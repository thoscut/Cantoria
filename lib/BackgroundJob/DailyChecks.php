<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\BackgroundJob;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\LicenseMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\ConcertDeadlines;
use OCA\Cantoria\Service\ExportService;
use OCA\Cantoria\Service\FieldValue;
use OCA\Cantoria\Service\FolderBookService;
use OCA\Cantoria\Service\HandoverService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\NotificationService;
use OCA\Cantoria\Service\PersonService;
use OCA\Cantoria\Service\PrintMirrorService;
use OCA\Cantoria\Service\PrivacyService;
use OCA\Cantoria\Service\ProtectionTerm;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;

/**
 * Daily checks that turn into notifications for the catalog librarian:
 * - licenses ending within 30 days, and ended licenses
 * - versions still in the singers' portal that are no longer released
 * - concert deadlines and concerts to confirm as sung (to the conductor)
 * - protected versions whose protection has ended by the life dates of all
 *   persons involved (resubmission; the rights status is not changed)
 *
 * It also removes the personal data of the folder book after the retention
 * period (FolderBookService), syncs the mirror folder of the print station
 * and the hand-over folder for the choir system (PrintMirrorService,
 * HandoverService, which notify their own failures) and notifies when
 * the last complete backup is older than eight days.
 *
 * Each case is notified once (state kept in lazy app config) and withdrawn
 * when it is resolved.
 *
 * A part whose notification is switched off (module `notify_<subject>`) is
 * not computed; its notifications are withdrawn and its state is cleared,
 * so that the cases are notified again once it is switched on.
 */
class DailyChecks extends TimedJob {
	private const WARN_DAYS = 30;
	/** Days after which a missing backup is notified */
	public const BACKUP_STALE_DAYS = 8;

	public function __construct(
		ITimeFactory $time,
		private AppSettings $settings,
		private LicenseMapper $licenses,
		private WorkVersionMapper $versions,
		private WorkMapper $works,
		private CopyMapper $copies,
		private RightsService $rights,
		private NotificationService $notifications,
		private IAppConfig $appConfig,
		private EventMapper $events,
		private PersonService $persons,
		private Modules $modules,
		private FolderBookService $folderBook,
		private PrintMirrorService $printMirror,
		private ExportService $export,
		private HandoverService $handover,
		private PrivacyService $privacy,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		$uid = $this->settings->archiveInfo()['user'];
		$today = $uid === '' ? gmdate('Y-m-d') : $this->rights->today($uid);
		$works = $this->works->findAllById();
		$versions = $this->versions->findAllById();
		$firstCopy = [];
		foreach ($this->copies->findAll() as $copy) {
			$firstCopy[$copy->getVersionId()] ??= $copy;
		}
		$describe = function (int $versionId) use ($versions, $works, $firstCopy): array {
			$version = $versions[$versionId] ?? null;
			$copy = $firstCopy[$versionId] ?? null;
			return [
				'shelfmark' => $copy?->getShelfmark() ?? '',
				'title' => $version === null ? '' : ($works[$version->getWorkId()]?->getTitle() ?? ''),
				'copyId' => $copy?->getId(),
			];
		};

		$licensesByVersion = $this->licenses->findAllByVersion();
		$warnUntil = (new \DateTimeImmutable($today))->modify('+' . self::WARN_DAYS . ' days')->format('Y-m-d');
		foreach ($licensesByVersion as $versionId => $licenses) {
			foreach ($licenses as $license) {
				$end = $license->getValidUntil();
				$key = 'notified_license_' . $license->getId();
				$state = $end === null ? '' : ($end < $today ? 'expired' : ($end <= $warnUntil ? 'expiring' : ''));
				$this->transition($key, $state, 'license', (string)$license->getId(), [
					'expiring' => NotificationService::LICENSE_EXPIRING,
					'expired' => NotificationService::LICENSE_EXPIRED,
				], $describe($versionId) + ['date' => (string)$end]);
			}
		}

		foreach ($this->events->findByKind(Event::CONCERT) as $event) {
			$date = $event->getEventDate();
			if ($event->getImported() === true || $date === null || !FieldValue::isDate($date)) {
				continue;
			}
			// A sung or cancelled concert has no stage: its notification is withdrawn.
			$state = ConcertDeadlines::stage($event, $today);
			$this->transition('notified_event_' . $event->getId(), $state, 'event', (string)$event->getId(), [
				'10' => NotificationService::CONCERT_DEADLINE,
				'8' => NotificationService::CONCERT_DEADLINE,
				'6' => NotificationService::CONCERT_DEADLINE,
				'confirm' => NotificationService::CONCERT_CONFIRM,
			], ['eventId' => $event->getId(), 'title' => (string)$event->getTitle(), 'date' => $date, 'weeks' => is_numeric($state) ? (int)$state : null],
				$state === 'confirm' ? [Roles::CONDUCTOR] : [Roles::CONDUCTOR, Roles::LIBRARIAN]);
		}

		$portalOn = $this->modules->notificationEnabled(NotificationService::PORTAL_REMOVE);
		foreach ($versions as $version) {
			$onPortal = $portalOn && $this->rights->isOnPortal($version);
			$denied = $onPortal && $this->rights->portalDenial($version, $licensesByVersion[$version->getId()] ?? [], $today) !== null;
			$this->transition('notified_portal_' . $version->getId(), $denied ? 'remove' : '', 'version', (string)$version->getId(),
				['remove' => NotificationService::PORTAL_REMOVE], $describe($version->getId()));
		}

		// Resubmission: marked protected, but by the life dates of all persons
		// involved the protection has ended; or confirmed public domain, but a
		// later change (a person added, life dates changed) makes the proposal
		// say protected (milestone 0.11; a proposal that is only unsure does
		// not notify). A task to check; nothing changes
		// automatically.
		$ended = $this->modules->notificationEnabled(NotificationService::PROTECTION_ENDED);
		$conflict = $this->modules->notificationEnabled(NotificationService::PROTECTION_CONFLICT);
		$checked = array_keys(array_filter($versions, fn ($v): bool => ($ended && $v->getRightsStatus() === 'protected')
			|| ($conflict && $v->getRightsStatus() === 'public_domain' && $v->isConfirmed())));
		$proposals = $checked === [] ? [] : $this->persons->protectionForVersions($checked, (int)substr($today, 0, 4));
		foreach ($versions as $version) {
			$proposal = $proposals[$version->getId()] ?? null;
			$state = match (true) {
				$proposal === null => '',
				$version->getRightsStatus() === 'protected' && $proposal['status'] === ProtectionTerm::FREE => 'ended',
				$version->getRightsStatus() === 'public_domain' && $proposal['status'] === ProtectionTerm::PROTECTED => 'conflict',
				default => '',
			};
			$this->transition('notified_protection_' . $version->getId(), $state, 'version', (string)$version->getId(),
				['ended' => NotificationService::PROTECTION_ENDED, 'conflict' => NotificationService::PROTECTION_CONFLICT],
				$describe($version->getId()) + ['date' => $state === 'ended' ? ($proposal['freeSince'] ?? '') : '']);
		}

		// Folder book: the personal data of returned folders are removed after
		// the retention period, also while the module is off (switching off
		// never makes anything less safe).
		$this->folderBook->anonymizeExpired($today);
		$this->privacy->applyRetention();
		// The mirror folder of the print station and the hand-over folder, while their modules are on.
		$this->printMirror->runDaily();
		$this->handover->runDaily();

		// A backup older than eight days: the weekly backup did not run.
		if ($uid !== '') {
			$stale = $this->export->lastBackupAge() > self::BACKUP_STALE_DAYS * 24 * 3600;
			$message = new InvalidInputException('The last complete backup is older than %s days. Check that the background jobs of Nextcloud run, or store a backup by hand.',
				[self::BACKUP_STALE_DAYS]);
			$this->transition('notified_backup_stale', $stale ? 'stale' : '', 'export', 'stale', ['stale' => NotificationService::EXPORT_FAILED], [
				'error' => $message->getMessage(),
				'errorParameters' => json_encode(array_map('strval', $message->getParameters()), JSON_THROW_ON_ERROR),
			]);
		}
	}

	/**
	 * Notifies when a case enters a new state and withdraws the notification
	 * of the previous state. A state whose notification is switched off
	 * counts as no state.
	 *
	 * @param array<array-key, string> $subjects state => notification subject
	 * @param array<string, scalar|null> $parameters
	 * @param list<string> $roles recipients
	 */
	private function transition(string $key, string $state, string $objectType, string $objectId, array $subjects, array $parameters,
		array $roles = [Roles::LIBRARIAN]): void {
		if (isset($subjects[$state]) && !$this->modules->notificationEnabled($subjects[$state])) {
			$state = '';
		}
		$previous = $this->appConfig->getValueString(Application::APP_ID, $key, '', true);
		if ($previous === $state) {
			return;
		}
		if (isset($subjects[$previous])) {
			$this->notifications->withdraw($subjects[$previous], $objectType, $objectId);
		}
		if (isset($subjects[$state])) {
			$this->notifications->notifyRoles($roles, $subjects[$state], $objectType, $objectId, $parameters);
		}
		if ($state === '') {
			$this->appConfig->deleteKey(Application::APP_ID, $key);
		} else {
			$this->appConfig->setValueString(Application::APP_ID, $key, $state, true);
		}
	}
}
