<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\BackgroundJob;

use OCA\Cantoria\Exception\UserFacingException;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\ExportService;
use OCA\Cantoria\Service\NotificationService;
use OCA\Cantoria\Service\Roles;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Weekly backup of the catalog into the archive folder (extended CSV and ODS
 * with the audit log), so that the regular file backup of the archive also
 * holds the catalog. Failures are reported to the catalog librarians and
 * admins.
 */
class WeeklyBackup extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private AppSettings $settings,
		private ExportService $export,
		private NotificationService $notifications,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(7 * 24 * 3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		$uid = $this->settings->archiveInfo()['user'];
		if ($uid === '') {
			return;
		}
		try {
			$paths = $this->export->storeBackup('weekly', $uid);
			$this->notifications->withdraw(NotificationService::EXPORT_FAILED, 'export', 'weekly');
			// Only the number of files: their paths lie in the files of an account.
			$this->logger->info('Cantoria: weekly backup stored (' . count($paths['paths']) . ' files)');
		} catch (\Throwable $e) {
			$this->logger->error('Cantoria: weekly backup failed: ' . $e->getMessage(), ['exception' => $e]);
			// Only messages meant for users go into the notification (translated
			// when shown); internal details stay in the log.
			$parameters = $e instanceof UserFacingException
				? ['error' => $e->getMessage(), 'errorParameters' => json_encode(array_map('strval', $e->getParameters()), JSON_THROW_ON_ERROR)]
				: [];
			$this->notifications->notifyRole(Roles::LIBRARIAN, NotificationService::EXPORT_FAILED, 'export', 'weekly', $parameters, true);
		}
	}
}
