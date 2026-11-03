<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Notification;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\NotificationService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders the app's notifications in the recipient's language and links them
 * to the app (opening the copy when the notification refers to one).
 * Notifications whose switch has been turned off since they were sent are
 * withdrawn when they are shown (AlreadyProcessedException: Nextcloud then
 * deletes them).
 */
class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $url,
		private Modules $modules,
	) {
	}

	#[\Override]
	public function getID(): string {
		return Application::APP_ID;
	}

	#[\Override]
	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Cantoria');
	}

	#[\Override]
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		if (!$this->modules->notificationEnabled($notification->getSubject())) {
			throw new AlreadyProcessedException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$p = $notification->getSubjectParameters();
		$link = $this->url->linkToRouteAbsolute(Application::APP_ID . '.page.index');

		$subject = match ($notification->getSubject()) {
			NotificationService::PROPOSALS_WAITING => $l->n('%n proposal is waiting in the inbox', '%n proposals are waiting in the inbox', (int)($p['count'] ?? 0)),
			NotificationService::PORTAL_REMOVE => $l->t('%1$s %2$s is in the singers\' portal but is no longer released for it: please remove it there.', [(string)($p['shelfmark'] ?? ''), (string)($p['title'] ?? '')]),
			NotificationService::LICENSE_EXPIRING => $l->t('The license for %1$s %2$s ends on %3$s.', [(string)($p['shelfmark'] ?? ''), (string)($p['title'] ?? ''), (string)($p['date'] ?? '')]),
			NotificationService::LICENSE_EXPIRED => $l->t('The license for %1$s %2$s has ended (%3$s).', [(string)($p['shelfmark'] ?? ''), (string)($p['title'] ?? ''), (string)($p['date'] ?? '')]),
			NotificationService::EXPORT_FAILED => $l->t('The automatic export of the music archive failed: %s', [self::exportError($l, $p)]),
			NotificationService::TASK_NEW => $l->t('New question or task for your role: %1$s %2$s', [(string)($p['shelfmark'] ?? ''), (string)($p['title'] ?? '')]),
			NotificationService::TASK_ANSWERED => $l->t('A question about %1$s %2$s has been answered and can be applied.', [(string)($p['shelfmark'] ?? ''), (string)($p['title'] ?? '')]),
			NotificationService::CONCERT_DEADLINE => match ((int)($p['weeks'] ?? 0)) {
				10 => $l->t('%1$s on %2$s is in 10 weeks: please fix the programme and the valid versions.', [(string)($p['title'] ?? ''), (string)($p['date'] ?? '')]),
				8 => $l->t('%1$s on %2$s is in 8 weeks: please clarify the rights of all pieces.', [(string)($p['title'] ?? ''), (string)($p['date'] ?? '')]),
				default => $l->t('%1$s on %2$s is in 6 weeks: please prepare the concert binders.', [(string)($p['title'] ?? ''), (string)($p['date'] ?? '')]),
			},
			NotificationService::CONCERT_CONFIRM => $l->t('Please confirm what was sung at %1$s on %2$s.', [(string)($p['title'] ?? ''), (string)($p['date'] ?? '')]),
			NotificationService::PROTECTION_ENDED => $l->t('%1$s %2$s is marked as protected, but by the life dates of the persons involved it is public domain since %3$s. Please check the rights status.', [(string)($p['shelfmark'] ?? ''), (string)($p['title'] ?? ''), (string)($p['date'] ?? '')]),
			NotificationService::PROTECTION_CONFLICT => $l->t('%1$s %2$s is confirmed as public domain, but by the persons recorded now the protection proposal no longer says so. Please check the rights status; until you decide, public links without a license stay closed, the repertoire page stays online.', [(string)($p['shelfmark'] ?? ''), (string)($p['title'] ?? '')]),
			NotificationService::CHECKSUM_MISMATCH => $l->t('A %3$s file of %1$s %2$s has changed although it was not modified: please check it and restore it from a backup if necessary.', [(string)($p['shelfmark'] ?? ''), (string)($p['title'] ?? ''), (string)($p['type'] ?? '')]),
			NotificationService::REVIEW_POINTS => $l->n('%1$s %2$s: %n new review point of the transcription for your role', '%1$s %2$s: %n new review points of the transcription for your role', (int)($p['count'] ?? 0), [(string)($p['shelfmark'] ?? ''), (string)($p['title'] ?? '')]),
			NotificationService::CORRECTION_RECEIVED => ($p['kind'] ?? '') === 'report'
				? $l->t('%1$s %2$s: a new transcription has done review points.', [(string)($p['shelfmark'] ?? ''), (string)($p['title'] ?? '')])
				: $l->t('%1$s %2$s: a corrected MusicXML file was saved in the app.', [(string)($p['shelfmark'] ?? ''), (string)($p['title'] ?? '')]),
			NotificationService::PRINT_MIRROR_FAILED => $l->t('The sync of the mirror folder for the print station failed: %s', [self::exportError($l, $p)]),
			NotificationService::CHOIR_HANDOVER_FAILED => $l->t('The sync of the hand-over folder for the choir system failed: %s', [self::exportError($l, $p)]),
			default => throw new UnknownNotificationException(),
		};

		if (in_array($notification->getSubject(), [NotificationService::REVIEW_POINTS, NotificationService::CORRECTION_RECEIVED], true)
			&& isset($p['copyId'])) {
			// Opens the score view of the copy with the review
			$link .= '?copy=' . (int)$p['copyId'] . '&review=1';
		} elseif (isset($p['eventId'])) {
			$link .= '?event=' . (int)$p['eventId'];
		} elseif (isset($p['taskId'])) {
			$link .= '?copy=' . (int)($p['copyId'] ?? 0) . '&task=' . (int)$p['taskId'];
		} elseif (isset($p['copyId'])) {
			$link .= '?copy=' . (int)$p['copyId'];
		} elseif ($notification->getSubject() === NotificationService::PROPOSALS_WAITING) {
			$link .= '?view=proposals';
		} elseif ($notification->getSubject() === NotificationService::CHECKSUM_MISMATCH) {
			$link .= '?view=checksums';
		} elseif ($notification->getSubject() === NotificationService::PRINT_MIRROR_FAILED) {
			$link .= '?view=print-station';
		} elseif ($notification->getSubject() === NotificationService::CHOIR_HANDOVER_FAILED) {
			$link .= '?view=portal';
		}

		$notification->setParsedSubject($subject)
			->setLink($link)
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')));
		return $notification;
	}

	/**
	 * Why the export (or the sync of the print station) failed: a message
	 * meant for users (English source string with its parameters),
	 * translated; otherwise only a pointer to the log.
	 * Internal error texts are never shown (older notifications kept them in
	 * `message`, which is ignored).
	 *
	 * @param array<array-key, mixed> $p subject parameters
	 */
	private static function exportError(IL10N $l, array $p): string {
		$error = isset($p['error']) && is_string($p['error']) ? $p['error'] : '';
		$parameters = self::stringList(isset($p['errorParameters']) && is_string($p['errorParameters'])
			? json_decode($p['errorParameters'], true) : null);
		// A message whose placeholders do not match its parameters is never formatted.
		if ($error === '' || $parameters === null || substr_count(str_replace('%%', '', $error), '%') !== count($parameters)) {
			return $l->t('Internal error. Details are in the Nextcloud log.');
		}
		return $l->t($error, $parameters);
	}

	/**
	 * @return list<string>|null null unless the value is a list of strings
	 */
	private static function stringList(mixed $value): ?array {
		if (!is_array($value) || !array_is_list($value)) {
			return null;
		}
		$strings = array_filter($value, 'is_string');
		return count($strings) === count($value) ? array_values($strings) : null;
	}
}
