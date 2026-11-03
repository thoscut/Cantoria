<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Attribute\RequiresModule;
use OCA\Cantoria\Service\HandoverService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Settings\Admin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The hand-over folder for a choir system (milestone 0.12, module
 * "choir_handover", see docs/api.md): status, preview and sync for the
 * catalog librarian and administrators (checked by the service); the
 * folder is an instance setting for administrators. The same requests as
 * for the mirror folder of the print station.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
#[RequiresModule(Modules::CHOIR_HANDOVER)]
class HandoverController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private HandoverService $mirror,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/choir-handover')]
	public function status(): JSONResponse {
		return $this->run(fn (string $uid) => $this->statusData($uid));
	}

	/**
	 * What a sync would do now, without writing anything.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/choir-handover/preview')]
	public function preview(): JSONResponse {
		return $this->run(fn (string $uid) => $this->translated($this->mirror->sync($uid, true)));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/choir-handover/sync')]
	public function sync(): JSONResponse {
		return $this->run(fn (string $uid) => $this->translated($this->mirror->sync($uid, false)));
	}

	/**
	 * The same as the status, for the settings (administrators).
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'GET', url: '/api/settings/choir-handover')]
	public function settings(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireSettingsAccess($uid);
			return $this->statusData($uid);
		});
	}

	/**
	 * Checks a folder as target folder without saving it.
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'GET', url: '/api/settings/choir-handover/check')]
	public function check(int $folderId = 0): JSONResponse {
		return $this->run(fn (string $uid) => ['folder' => $this->mirror->checkTargetFolder($uid, $folderId)]);
	}

	/**
	 * Saves the target folder; 0 removes it.
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/settings/choir-handover')]
	public function updateSettings(int $folderId = 0): JSONResponse {
		return $this->run(fn (string $uid) => ['folder' => $this->folderOrNull($this->mirror->setTargetFolder($uid, $folderId))]);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function statusData(string $uid): array {
		$status = $this->mirror->status($uid);
		$status['report'] = $status['report'] === null ? null : $this->translated($status['report']);
		return $status;
	}

	/**
	 * @param array{id: int, path: string, user: string} $folder
	 * @return array{id: int, path: string, user: string}|null
	 */
	private function folderOrNull(array $folder): ?array {
		return $folder['id'] === 0 ? null : $folder;
	}

	/**
	 * The report (of this sync, or the stored one of the last) with the
	 * reasons of skipped files translated.
	 *
	 * @param array<array-key, mixed> $report
	 * @return array<array-key, mixed>
	 */
	private function translated(array $report): array {
		$report['skipped'] = isset($report['skipped']) && is_array($report['skipped'])
			? array_values(array_filter(array_map(fn (mixed $entry): ?array => $this->translatedEntry($entry), $report['skipped'])))
			: [];
		return $report;
	}

	/**
	 * @return array<array-key, mixed>|null
	 */
	private function translatedEntry(mixed $entry): ?array {
		if (!is_array($entry)) {
			return null;
		}
		$reason = isset($entry['reason']) && is_string($entry['reason']) ? $entry['reason'] : '';
		return ['reasonText' => $this->reason($reason)] + $entry;
	}

	private function reason(string $key): string {
		return match ($key) {
			HandoverService::FOREIGN => $this->l->t('A file of this name is in the hand-over folder, but the app did not write it (or it was replaced). It stays as it is; rename or remove it there if the app should write it.'),
			HandoverService::CHANGED => $this->l->t('The file was changed in the hand-over folder after the app wrote it. It stays as it is; remove it there if the app should look after it again.'),
			HandoverService::NOT_ACCESSIBLE => $this->l->t('The account of the hand-over folder cannot read this file. Give it access to the archive folder.'),
			HandoverService::NOT_PDF => $this->l->t('The file is not a PDF document. Check the scan in the archive folder.'),
			HandoverService::INVALID_NAME => $this->l->t('Nextcloud does not allow this file name. Change the title in the catalog.'),
			HandoverService::PRESERVATION => $this->l->t('Internal preservation copy: never handed over.'),
			HandoverService::NO_DOWNLOAD => $this->l->t('Choir members could save and print this file in the choir system: it needs confirmed public domain or a license for download and printing by choir members.'),
			HandoverService::NO_PRACTICE_AUDIO => $this->l->t('Recording: it needs confirmed public domain or a license for practice audio, and in every case a license for passing on recordings (performers and producer agree).'),
			HandoverService::NO_DIGITAL_SCORES => $this->l->t('MusicXML file: it needs confirmed public domain or a license for passing on digital score files.'),
			HandoverService::NOT_MUSICXML => $this->l->t('The file is not a MusicXML score. Check the file in the archive folder.'),
			HandoverService::WRITE_FAILED => $this->l->t('The file could not be written or removed (no permission, or it is locked). The next sync tries again.'),
			default => $key,
		};
	}
}
