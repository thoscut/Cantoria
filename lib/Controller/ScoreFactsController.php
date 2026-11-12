<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Attribute\RequiresModule;
use OCA\Cantoria\Service\ProgrammeSheets;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\ScoreFactsService;
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
 * Facts from the scores (ScoreFactsService): per copy for the catalog, the
 * choir profile, reading them on request, and the pitch sheet of a
 * programme (ProgrammeSheets).
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
#[RequiresModule('score_facts')]
class ScoreFactsController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private ScoreFactsService $facts,
		private ProgrammeSheets $sheets,
		private Roles $roles,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * The facts per copy, the choir profile and how many scores were read.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/facts')]
	public function index(): JSONResponse {
		return $this->run(fn (string $uid): array => [
			'copies' => (object)$this->facts->forCatalog($uid),
			'profile' => $this->facts->profile(),
			'summary' => $this->facts->summary(),
			'canEditProfile' => $this->roles->hasAnyRole($uid, ScoreFactsService::PROFILE_EDITORS),
		]);
	}

	/**
	 * Saves the choir profile; an empty list goes back to the default.
	 *
	 * @param mixed $groups
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/facts/profile')]
	public function saveProfile(mixed $groups = null): JSONResponse {
		return $this->run(fn (string $uid): array => $this->facts->saveProfile($groups, $uid));
	}

	/**
	 * Reads the next scores now (catalog librarian), instead of waiting for
	 * the background job.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/facts/run')]
	public function runNow(): JSONResponse {
		return $this->run(function (string $uid): array {
			$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
			$read = $this->facts->run(ScoreFactsService::MAX_FILES * 4);
			return ['read' => $read, 'summary' => $this->facts->summary()];
		});
	}

	/**
	 * The pitch sheet of a programme or song list.
	 */
	#[NoAdminRequired]
	#[RequiresModule('concerts')]
	#[FrontpageRoute(verb: 'GET', url: '/api/events/{id}/pitches', requirements: ['id' => '\d+'])]
	public function pitches(int $id): JSONResponse {
		return $this->run(fn (string $uid): array => $this->sheets->pitchSheet($id, $uid));
	}
}
