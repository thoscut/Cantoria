<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Attribute\RequiresModule;
use OCA\Cantoria\Db\Proposal;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\ExportService;
use OCA\Cantoria\Service\FolderBookService;
use OCA\Cantoria\Service\FullBackupService;
use OCA\Cantoria\Service\LicenseService;
use OCA\Cantoria\Service\ProposalService;
use OCA\Cantoria\Service\ReleaseListService;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\UserFiles;
use OCA\Cantoria\Service\ValidVersionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Actions on rights, releases, valid versions, licenses and proposals
 * (milestone 0.2, see docs/api.md). Each service checks the required role.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class WorkflowController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private RightsService $rights,
		private ValidVersionService $validVersions,
		private LicenseService $licenses,
		private ProposalService $proposals,
		private ReleaseListService $releaseLists,
		private ExportService $export,
		private FullBackupService $fullBackupService,
		private UserFiles $userFiles,
		private IL10N $l,
		private LoggerInterface $logger,
		private IUserManager $userManager,
		private AuditLog $audit,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[RequiresModule('rights')]
	#[FrontpageRoute(verb: 'POST', url: '/api/versions/{id}/confirm', requirements: ['id' => '\d+'])]
	public function confirm(int $id, string $status = '', string $reason = '', string $evidence = ''): JSONResponse {
		return $this->run(function (string $uid) use ($id, $status, $reason, $evidence) {
			$this->rights->confirm($id, $uid, $status, $reason, $evidence);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	#[RequiresModule('rights')]
	#[FrontpageRoute(verb: 'POST', url: '/api/versions/{id}/set-open', requirements: ['id' => '\d+'])]
	public function setOpen(int $id, string $reason = ''): JSONResponse {
		return $this->run(function (string $uid) use ($id, $reason) {
			$this->rights->setOpen($id, $uid, $reason);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	#[RequiresModule('portal')]
	#[FrontpageRoute(verb: 'POST', url: '/api/versions/{id}/portal', requirements: ['id' => '\d+'])]
	public function addToPortal(int $id, string $since = ''): JSONResponse {
		return $this->run(function (string $uid) use ($id, $since) {
			$this->rights->addToPortal($id, $uid, $since);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	#[RequiresModule('portal')]
	#[FrontpageRoute(verb: 'POST', url: '/api/versions/{id}/portal-remove', requirements: ['id' => '\d+'])]
	public function removeFromPortal(int $id, string $reason = ''): JSONResponse {
		return $this->run(function (string $uid) use ($id, $reason) {
			$this->rights->removeFromPortal($id, $uid, $reason);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	#[RequiresModule('valid_version')]
	#[FrontpageRoute(verb: 'POST', url: '/api/versions/{id}/valid', requirements: ['id' => '\d+'])]
	public function setValid(int $id, ?string $reason = null): JSONResponse {
		return $this->run(function (string $uid) use ($id, $reason) {
			$this->validVersions->set($id, $uid, $reason);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	#[RequiresModule('valid_version')]
	#[FrontpageRoute(verb: 'POST', url: '/api/versions/{id}/valid-clear', requirements: ['id' => '\d+'])]
	public function clearValid(int $id, ?string $reason = null): JSONResponse {
		return $this->run(function (string $uid) use ($id, $reason) {
			$this->validVersions->clear($id, $uid, $reason);
			return ['ok' => true];
		});
	}

	/**
	 * @param array<string, mixed> $license
	 */
	#[NoAdminRequired]
	#[RequiresModule('rights')]
	#[FrontpageRoute(verb: 'POST', url: '/api/versions/{id}/licenses', requirements: ['id' => '\d+'])]
	public function createLicense(int $id, array $license = []): JSONResponse {
		return $this->run(fn (string $uid) => $this->licenses->create($id, $license, $uid));
	}

	/**
	 * @param array<string, mixed> $license
	 */
	#[NoAdminRequired]
	#[RequiresModule('rights')]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/licenses/{id}', requirements: ['id' => '\d+'])]
	public function updateLicense(int $id, array $license = []): JSONResponse {
		return $this->run(fn (string $uid) => $this->licenses->update($id, $license, $uid));
	}

	#[NoAdminRequired]
	#[RequiresModule('rights')]
	#[FrontpageRoute(verb: 'GET', url: '/api/licenses/ending')]
	public function licensesEnding(int $days = 30): JSONResponse {
		return $this->run(function (string $uid) use ($days) {
			$this->roles->requireAccess($uid);
			return $this->releaseLists->licensesEnding($uid, max(1, min(366, $days)));
		});
	}

	#[NoAdminRequired]
	#[RequiresModule('proposals')]
	#[FrontpageRoute(verb: 'GET', url: '/api/proposals')]
	public function proposals(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireRole($uid, [Roles::LIBRARIAN, Roles::CONTRIBUTOR, Roles::READER]);
			// The inbox names who proposed with the display name, as the audit log does
			return array_map(function (Proposal $proposal): array {
				$creator = $proposal->getCreatedBy();
				return $proposal->jsonSerialize() + ['created_by_name' => $creator === null ? null : $this->userManager->getDisplayName($creator)];
			}, $this->proposals->open());
		});
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	#[NoAdminRequired]
	#[RequiresModule('proposals')]
	#[FrontpageRoute(verb: 'POST', url: '/api/proposals')]
	public function propose(string $level = '', int $id = 0, array $fields = [], ?string $reason = null, ?string $source = null): JSONResponse {
		return $this->run(fn (string $uid) => $this->proposals->propose($level, $id, $fields, $uid, $reason, $source));
	}

	#[NoAdminRequired]
	#[RequiresModule('proposals')]
	#[FrontpageRoute(verb: 'POST', url: '/api/proposals/{id}/accept', requirements: ['id' => '\d+'])]
	public function acceptProposal(int $id, ?string $reason = null): JSONResponse {
		return $this->run(function (string $uid) use ($id, $reason) {
			$this->proposals->accept($id, $uid, $reason);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	#[RequiresModule('proposals')]
	#[FrontpageRoute(verb: 'POST', url: '/api/proposals/{id}/reject', requirements: ['id' => '\d+'])]
	public function rejectProposal(int $id, ?string $reason = null): JSONResponse {
		return $this->run(function (string $uid) use ($id, $reason) {
			$this->proposals->reject($id, $uid, $reason);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	#[RequiresModule('portal')]
	#[FrontpageRoute(verb: 'GET', url: '/api/lists/portal')]
	public function portalList(int $all = 0): JSONResponse {
		return $this->releaseList('portal', $all);
	}

	#[NoAdminRequired]
	#[RequiresModule('print_station')]
	#[FrontpageRoute(verb: 'GET', url: '/api/lists/print-station')]
	public function printStationList(int $all = 0): JSONResponse {
		return $this->releaseList('print-station', $all);
	}

	/**
	 * @param 'portal'|'print-station' $target
	 */
	private function releaseList(string $target, int $all): JSONResponse {
		return $this->run(function (string $uid) use ($target, $all) {
			$this->roles->requireAccess($uid);
			$rows = $target === 'portal'
				? $this->releaseLists->portal($uid, $all === 1)
				: $this->releaseLists->printStation($uid, $all === 1);
			foreach ($rows as $index => $row) {
				if ($row['reason'] !== null) {
					$rows[$index]['reason'] = $this->l->t($row['reason']);
				}
			}
			return $rows;
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/backup')]
	public function backup(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
			return $this->export->storeBackup('manual', $uid);
		});
	}

	/**
	 * Complete backup (JSON) as download, for the roles that read all of
	 * the catalog including the folder book (which names choir members) and
	 * for administrators; not for contributors (tools). Every download is
	 * recorded in the audit log with account and roles (who took the names
	 * of the choir members along, Art. 5 (2) GDPR), without its content.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 10, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/backup/full')]
	public function fullBackup(): Response {
		if ($this->userId === null || !$this->roles->hasAccess($this->userId)) {
			return new JSONResponse(['error' => $this->l->t('You have no access to the music archive.')], Http::STATUS_FORBIDDEN);
		}
		if (!$this->roles->isAdmin($this->userId) && !$this->roles->hasAnyRole($this->userId, FolderBookService::READERS)) {
			return new JSONResponse(['error' => $this->l->t('The complete backup contains the names of choir members. It is open to the music librarians, the conductor, readers and administrators.')],
				Http::STATUS_FORBIDDEN);
		}
		$name = 'cantoria-backup-' . gmdate('Ymd-His') . '.json';
		$content = $this->fullBackupService->export();
		$this->audit->record($this->audit->newOperation(), $this->userId, 'export', null, 'download_backup', null, null, $name);
		$response = new DataDownloadResponse($content, $name, 'application/json');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		return $response;
	}

	/**
	 * Restores a complete backup into an empty catalog (catalog librarian or
	 * admin, e.g. after reinstalling).
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 5, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/backup/restore')]
	public function restore(int $fileId): JSONResponse {
		return $this->run(function (string $uid) use ($fileId) {
			if (!$this->roles->isAdmin($uid)) {
				$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
			}
			$file = $this->userFiles->read($uid, $fileId, FullBackupService::MAX_BYTES,
				new InvalidInputException('The selected file is not accessible.'));
			return ['restored' => $this->fullBackupService->restore($file['content'], $uid)];
		});
	}
}
