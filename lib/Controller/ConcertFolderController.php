<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Attribute\RequiresModule;
use OCA\Cantoria\Exception\UserFacingException;
use OCA\Cantoria\Service\ConcertFolderService;
use OCA\Cantoria\Service\Modules;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The concert folder of the conductor (milestone 0.15, module
 * concert_folder): a page of its own with the programmes still to be sung,
 * their list as JSON for the check of what is kept on the device, and the
 * score and scan of each item. Only reads. The service checks the role and
 * the rights on every call.
 *
 * @psalm-import-type FolderConcert from ConcertFolderService
 * @psalm-suppress UnusedClass
 */
#[RequiresModule(Modules::CONCERT_FOLDER)]
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class ConcertFolderController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private ConcertFolderService $folder,
		private IInitialState $initialState,
		private IAppManager $apps,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/concert-folder')]
	public function page(): TemplateResponse {
		if ($this->userId === null || !$this->folder->mayOpen($this->userId)) {
			$response = new TemplateResponse(Application::APP_ID, 'forbidden', [
				'heading' => $this->l->t('No access yet'),
				'message' => $this->l->t('The concert folder is for the conductor and the catalog librarian.'),
				'guideUrl' => PageController::noAccessGuideUrl($this->l->getLanguageCode()),
				'guideLabel' => $this->l->t('No access to the music archive (guide, opens in a new tab)'),
			], TemplateResponse::RENDER_AS_ERROR);
			$response->setStatus(Http::STATUS_FORBIDDEN);
			return $response;
		}
		$this->initialState->provideInitialState('folder', [
			'concerts' => $this->translated($this->folder->concerts($this->userId)),
			'version' => $this->apps->getAppVersion(Application::APP_ID),
			// pieces are kept on the device only with the module "offline" (milestone 0.19)
			'offline' => $this->folder->offlineOn(),
		]);
		$response = PageController::withScorePolicy(new TemplateResponse(Application::APP_ID, 'concert-folder'), false, true);
		// The service worker keeps only this page of the archive on the device
		$response->addHeader('X-Cantoria-Page', 'concert-folder');
		return $response;
	}

	/**
	 * The programmes as on the page: the page compares what is kept on the
	 * device with it at every start with a network.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/concert-folder')]
	public function concerts(): JSONResponse {
		return $this->run(fn (string $uid): array => ['concerts' => $this->translated($this->folder->concerts($uid))]);
	}

	/**
	 * The MusicXML of a programme item (unpacked), as the score files of the
	 * archive: never rendered on the Nextcloud origin, no type guessing.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 240, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/concert-folder/items/{id}/score', requirements: ['id' => '\d+'])]
	public function score(int $id): Response {
		if ($this->userId === null) {
			return new JSONResponse(['error' => $this->l->t('Not logged in.')], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$xml = $this->folder->score($id, $this->userId);
		} catch (UserFacingException $e) {
			return $this->errorResponse($e);
		}
		$response = new DataDisplayResponse($xml, Http::STATUS_OK, ['Content-Type' => 'application/vnd.recordare.musicxml+xml']);
		$response->addHeader('Content-Disposition', 'attachment; filename="score.musicxml"');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->cacheFor(0, false, false);
		return $response;
	}

	/**
	 * The scan of a programme item (PDF). The page shows it inside itself.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/concert-folder/items/{id}/scan', requirements: ['id' => '\d+'])]
	public function scan(int $id): Response {
		if ($this->userId === null) {
			return new JSONResponse(['error' => $this->l->t('Not logged in.')], Http::STATUS_UNAUTHORIZED);
		}
		try {
			return ScanResponse::inline($this->folder->scan($id, $this->userId));
		} catch (UserFacingException $e) {
			return $this->errorResponse($e);
		}
	}

	/**
	 * The reasons of the items translated.
	 *
	 * @param list<FolderConcert> $concerts
	 * @return list<array<string, mixed>>
	 */
	private function translated(array $concerts): array {
		$t = fn (?string $reason): ?string => $reason === null ? null : $this->l->t($reason);
		return array_map(fn (array $concert): array => ['items' => array_map(
			fn (array $item): array => ['reason' => $t($item['reason']), 'offlineReason' => $t($item['offlineReason'])] + $item,
			$concert['items'])] + $concert, $concerts);
	}
}
