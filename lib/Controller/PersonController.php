<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Attribute\RequiresModule;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\ContributorDerivationService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\PersonImportService;
use OCA\Cantoria\Service\PersonService;
use OCA\Cantoria\Service\ProtectionText;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\UserFiles;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Register of persons, contributions per version, the protection proposal
 * and its resubmission list, and the import of a research list (milestone
 * 0.5, see docs/api.md). Writing needs the role librarian; the services check
 * it.
 *
 * @psalm-suppress UnusedClass
 */
#[RequiresModule('persons')]
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class PersonController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private PersonService $persons,
		private PersonImportService $personImport,
		private ContributorDerivationService $derivation,
		private RightsService $rights,
		private UserFiles $userFiles,
		private Modules $modules,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/persons')]
	public function list(?string $q = null): JSONResponse {
		return $this->run(function (string $uid) use ($q) {
			$this->roles->requireAccess($uid);
			return $this->persons->list($q);
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/persons/{id}', requirements: ['id' => '\d+'])]
	public function detail(int $id): JSONResponse {
		return $this->run(function (string $uid) use ($id) {
			$this->roles->requireAccess($uid);
			return $this->persons->detail($id);
		});
	}

	/**
	 * @param array<string, mixed> $person
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/persons')]
	public function create(array $person = []): JSONResponse {
		return $this->run(fn (string $uid) => $this->persons->serialize($this->persons->create($person, $uid)));
	}

	/**
	 * @param array<string, mixed> $person
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/persons/{id}', requirements: ['id' => '\d+'])]
	public function update(int $id, array $person = [], ?string $reason = null): JSONResponse {
		return $this->run(fn (string $uid) => $this->persons->serialize($this->persons->update($id, $person, $uid, $reason)));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/persons/{id}/checked', requirements: ['id' => '\d+'])]
	public function checked(int $id): JSONResponse {
		return $this->run(fn (string $uid) => $this->persons->serialize($this->persons->markChecked($id, $uid)));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/persons/{id}', requirements: ['id' => '\d+'])]
	public function delete(int $id): JSONResponse {
		return $this->run(function (string $uid) use ($id) {
			$this->persons->delete($id, $uid);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/versions/{id}/contributions', requirements: ['id' => '\d+'])]
	public function contributions(int $id): JSONResponse {
		return $this->run(function (string $uid) use ($id) {
			$this->roles->requireAccess($uid);
			$year = (int)substr($this->rights->today($uid), 0, 4);
			return [
				'contributions' => $this->persons->forVersion($id),
				'protection' => $this->modules->isEnabled('protection_terms')
					? ProtectionText::apply($this->l, $this->persons->protection($id, $year))
					: null,
			];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/versions/{id}/contributions', requirements: ['id' => '\d+'])]
	public function addContribution(int $id, int $personId = 0, string $role = 'unspecified', bool $textForComposition = false): JSONResponse {
		return $this->run(fn (string $uid) => $this->persons->addContribution($id, $personId, $role, $textForComposition, $uid));
	}

	/**
	 * @param array<string, mixed> $contribution
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/contributions/{id}', requirements: ['id' => '\d+'])]
	public function updateContribution(int $id, array $contribution = []): JSONResponse {
		return $this->run(fn (string $uid) => $this->persons->updateContribution($id, $contribution, $uid));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/contributions/{id}', requirements: ['id' => '\d+'])]
	public function removeContribution(int $id): JSONResponse {
		return $this->run(function (string $uid) use ($id) {
			$this->persons->removeContribution($id, $uid);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	#[RequiresModule('protection_terms')]
	#[FrontpageRoute(verb: 'GET', url: '/api/protection/review')]
	public function review(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireAccess($uid);
			$rows = [];
			foreach ($this->persons->review($uid) as $row) {
				$rows[] = ['protection' => ProtectionText::apply($this->l, $row['protection'])] + $row;
			}
			return $rows;
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/persons/import')]
	public function import(int $fileId = 0, bool $dryRun = true): JSONResponse {
		return $this->run(function (string $uid) use ($fileId, $dryRun) {
			$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
			$file = $this->userFiles->read($uid, $fileId, UserFiles::MAX_IMPORT_BYTES,
				new InvalidInputException('The selected CSV file is not accessible.'));
			$report = $this->personImport->import($file['content'], $file['name'], $uid, $dryRun);
			return ['warnings' => $this->translateWarnings($report['warnings'])] + $report;
		});
	}

	/**
	 * Derives persons and roles from the composer and arranger texts of the
	 * catalog; a dry run first (the service checks the role).
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/persons/derive')]
	public function derive(bool $dryRun = true): JSONResponse {
		return $this->run(function (string $uid) use ($dryRun) {
			$report = $this->derivation->derive($uid, $dryRun);
			return ['warnings' => $this->translateWarnings($report['warnings'])] + $report;
		});
	}
}
