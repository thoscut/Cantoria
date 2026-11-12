<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Attribute\RequiresModule;
use OCA\Cantoria\Db\AuditEntryMapper;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\UserFacingException;
use OCA\Cantoria\Service\CatalogService;
use OCA\Cantoria\Service\ExportService;
use OCA\Cantoria\Service\FileSyncService;
use OCA\Cantoria\Service\FolderBookService;
use OCA\Cantoria\Service\ImportService;
use OCA\Cantoria\Service\MetaService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\ProtectionText;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\ScoreFactsService;
use OCA\Cantoria\Service\ScoreService;
use OCA\Cantoria\Service\UserFiles;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * JSON API for the UI (see docs/api.md).
 *
 * Every method checks the required role itself. Admins may read the catalog,
 * but may only write catalog data if they also hold a role. The settings are
 * in SettingsController.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class ApiController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private MetaService $meta,
		private CatalogService $catalog,
		private ImportService $import,
		private ExportService $export,
		private FileSyncService $fileSync,
		private AuditEntryMapper $auditEntries,
		private UserFiles $userFiles,
		private ITimeFactory $time,
		private IDateTimeZone $timeZone,
		private ScoreService $scores,
		private ScoreFactsService $scoreFacts,
		private Modules $modules,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/meta')]
	public function meta(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireAccess($uid);
			return $this->meta->forUser($uid);
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/entries')]
	public function entries(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireAccess($uid);
			return $this->catalog->entriesFor($uid);
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/entries/{id}', requirements: ['id' => '\d+'])]
	public function entry(int $id): JSONResponse {
		return $this->run(function (string $uid) use ($id) {
			$this->roles->requireAccess($uid);
			$entry = $this->catalog->entry($id, $uid);
			$score = $entry['score'];
			if ($score !== null) {
				if ($score['reason'] !== null) {
					$score['reason'] = $this->l->t($score['reason']);
				}
				if ($score['exportReason'] !== null) {
					$score['exportReason'] = $this->l->t($score['exportReason']);
				}
				$entry['score'] = $score;
			}
			$releases = $entry['releases'];
			if ($releases['portal'] !== null) {
				$releases['portal'] = $this->l->t($releases['portal']);
			}
			if ($releases['printStation'] !== null) {
				$releases['printStation'] = $this->l->t($releases['printStation']);
			}
			$entry['releases'] = $releases;
			$entry['licenseWarnings'] = array_map(fn (string $warning): string => $this->l->t($warning), $entry['licenseWarnings']);
			$entry['protection'] = $entry['protection'] === null ? null : ProtectionText::apply($this->l, $entry['protection']);
			return $entry;
		});
	}

	/**
	 * Raw MusicXML of a score file for the score viewer.
	 */
	#[NoAdminRequired]
	#[RequiresModule('score_viewer')]
	#[FrontpageRoute(verb: 'GET', url: '/api/files/{id}/content', requirements: ['id' => '\d+'])]
	public function fileContent(int $id): Response {
		if ($this->userId === null) {
			return new JSONResponse(['error' => $this->l->t('Not logged in.')], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$this->roles->requireAccess($this->userId);
			$score = $this->scores->content($id, $this->userId);
		} catch (UserFacingException $e) {
			return $this->errorResponse($e);
		}
		// The facts of a score opened are read at once, not only by the background job.
		if ($this->modules->isEnabled(Modules::SCORE_FACTS)) {
			$this->scoreFacts->remember($id, $score['content']);
		}
		// The viewer fetches the file with a script. Opened directly, the
		// browser must neither render the XML on the Nextcloud origin nor guess
		// another type: attachment, nosniff and the strict default policy of
		// data responses (default-src 'none'). The file name is fixed (ASCII).
		$response = new DataDisplayResponse($score['content'], Http::STATUS_OK, ['Content-Type' => $score['mime']]);
		$response->addHeader('Content-Disposition', 'attachment; filename="score.' . $score['type'] . '"');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->cacheFor(0, false, false);
		return $response;
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/{level}/{id}', requirements: ['level' => 'work|version|copy', 'id' => '\d+'])]
	public function update(string $level, int $id, array $fields = [], ?string $reason = null): JSONResponse {
		return $this->run(function (string $uid) use ($level, $id, $fields, $reason) {
			$this->roles->requireAccess($uid);
			$this->catalog->update($level, $id, $fields, $uid, $reason);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/import')]
	public function import(int $fileId, bool $dryRun = true): JSONResponse {
		return $this->run(function (string $uid) use ($fileId, $dryRun) {
			$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
			// CSV, .xlsx or .ods: the same limit for every format (U32)
			$file = $this->userFiles->read($uid, $fileId, UserFiles::MAX_IMPORT_BYTES,
				new InvalidInputException('The selected file is not accessible.'));
			$report = $this->import->import($file['content'], $file['name'], $uid, $dryRun);
			return ['warnings' => $this->translateWarnings($report['warnings'])] + $report;
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/file-sync')]
	public function fileSync(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
			return $this->fileSync->sync($uid);
		});
	}

	/**
	 * Whether a file sync is running, and the counts of the last one.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/file-sync')]
	public function fileSyncStatus(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
			return $this->fileSync->status();
		});
	}

	/**
	 * Plain download link, therefore without CSRF token. Read-only. Without
	 * accounts; internal preservation copies only for the roles that may
	 * view them (ExportService::csvFor).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/export')]
	public function export(int $extended = 0): Response {
		if ($this->userId === null || !$this->roles->hasAccess($this->userId)) {
			return new JSONResponse(['error' => $this->l->t('You have no access to the music archive.')], Http::STATUS_FORBIDDEN);
		}
		$today = $this->time->getDateTime('now', $this->timeZone->getTimeZone(false, $this->userId));
		$name = $today->format('Ymd') . '_catalog' . ($extended === 1 ? '_full' : '') . '.csv';
		$response = new DataDownloadResponse($this->export->csvFor($this->userId, $extended === 1), $name, 'text/csv; charset=utf-8');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		return $response;
	}

	/**
	 * The import template: the catalog columns with one example row, as CSV
	 * or ODS. Plain download link, therefore without CSRF token. Read-only;
	 * for the catalog role, which imports.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/import/template')]
	public function importTemplate(string $format = 'csv'): Response {
		if ($this->userId === null || !$this->roles->hasAnyRole($this->userId, [Roles::LIBRARIAN])) {
			return new JSONResponse(['error' => $this->l->t('This requires one of these roles: %s', [$this->l->t(Roles::LABELS[Roles::LIBRARIAN])])], Http::STATUS_FORBIDDEN);
		}
		$ods = $format === 'ods';
		$response = new DataDownloadResponse($this->export->importTemplate($ods ? 'ods' : 'csv'),
			'cantoria_import_template.' . ($ods ? 'ods' : 'csv'),
			$ods ? 'application/vnd.oasis.opendocument.spreadsheet' : 'text/csv; charset=utf-8');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		return $response;
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/export/store')]
	public function exportStore(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
			return ['path' => $this->export->store('export', $uid)];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/audit')]
	public function audit(int $limit = 200): JSONResponse {
		return $this->run(function (string $uid) use ($limit) {
			$this->roles->requireAccess($uid);
			// The entries of the folder book name choir members: only for the roles that read it.
			$hidden = $this->roles->hasAnyRole($uid, FolderBookService::READERS) ? [] : FolderBookService::OBJECT_TYPES;
			return $this->auditEntries->findLatest(max(1, min($limit, 1000)), $hidden);
		});
	}
}
