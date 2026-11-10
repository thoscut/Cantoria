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
use OCA\Cantoria\Service\SongbookService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Songbooks from a selection (SongbookService): which pieces may go in,
 * their scans, and the record of a songbook made. The PDF itself is put
 * together in the browser.
 *
 * @psalm-import-type SongbookPiece from SongbookService
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
#[RequiresModule('songbook')]
class SongbookController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private SongbookService $songbooks,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * The pieces of a programme or song list for a songbook.
	 */
	#[NoAdminRequired]
	#[RequiresModule('concerts')]
	#[FrontpageRoute(verb: 'GET', url: '/api/events/{id}/songbook', requirements: ['id' => '\d+'])]
	public function forEvent(int $id): JSONResponse {
		return $this->run(fn (string $uid): array => $this->translated($this->songbooks->forEvent($id, $uid)));
	}

	/**
	 * The pieces of a list of copies (the catalog as filtered).
	 *
	 * @param list<mixed> $copyIds
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/songbook/pieces')]
	public function forCopies(array $copyIds = []): JSONResponse {
		return $this->run(fn (string $uid): array => $this->translated($this->songbooks->forCopies($copyIds, $uid)));
	}

	/**
	 * The scan of a piece, for the browser to put into the songbook.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 600, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/songbook/files/{id}', requirements: ['id' => '\d+'])]
	public function scan(int $id): Response {
		if ($this->userId === null) {
			return new JSONResponse(['error' => $this->l->t('Not logged in.')], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$response = ScanResponse::inline($this->songbooks->scan($id, $this->userId));
		} catch (UserFacingException $e) {
			return $this->errorResponse($e);
		}
		$response->addHeader('Content-Disposition', 'attachment; filename="scan.pdf"');
		return $response;
	}

	/**
	 * Records the songbook just made (audit log).
	 *
	 * @param list<mixed> $copyIds
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/songbook/record')]
	public function record(array $copyIds = [], ?int $eventId = null, string $title = ''): JSONResponse {
		return $this->run(function (string $uid) use ($copyIds, $eventId, $title): array {
			$this->songbooks->record($copyIds, $eventId, $title, $uid);
			return ['recorded' => true];
		});
	}

	/**
	 * The reasons of the pieces translated.
	 *
	 * @param array{title: string, date: ?string, pieces: list<SongbookPiece>} $plan
	 * @return array{title: string, date: ?string, pieces: list<SongbookPiece>}
	 */
	private function translated(array $plan): array {
		$plan['pieces'] = array_map(function (array $piece): array {
			$piece['reason'] = $piece['reason'] === null ? null : $this->l->t($piece['reason']);
			$piece['note'] = $piece['note'] === null ? null : $this->l->t($piece['note']);
			return $piece;
		}, $plan['pieces']);
		return $plan;
	}
}
