<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Attribute\RequiresModule;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\ProgrammeSheets;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The programme booklet of a concert (ProgrammeSheets::booklet). The PDF is
 * put together in the browser; making it is recorded in the audit log.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
#[RequiresModule('programme_booklet')]
class BookletController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private ProgrammeSheets $sheets,
		private AuditLog $audit,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * The content of the booklet; the reasons why lyrics are left out are
	 * translated.
	 */
	#[NoAdminRequired]
	#[RequiresModule('concerts')]
	#[FrontpageRoute(verb: 'GET', url: '/api/events/{id}/booklet', requirements: ['id' => '\d+'])]
	public function show(int $id): JSONResponse {
		return $this->run(function (string $uid) use ($id): array {
			$booklet = $this->sheets->booklet($id, $uid);
			$booklet['pieces'] = array_map(function (array $piece): array {
				$piece['lyricsReason'] = is_string($piece['lyricsReason']) ? $this->l->t($piece['lyricsReason']) : null;
				return $piece;
			}, $booklet['pieces']);
			return $booklet;
		});
	}

	/**
	 * Records the booklet just made, with the pieces whose lyrics it prints.
	 *
	 * @param list<mixed> $withLyrics item ids
	 */
	#[NoAdminRequired]
	#[RequiresModule('concerts')]
	#[FrontpageRoute(verb: 'POST', url: '/api/events/{id}/booklet/record', requirements: ['id' => '\d+'])]
	public function record(int $id, array $withLyrics = []): JSONResponse {
		return $this->run(function (string $uid) use ($id, $withLyrics): array {
			// Checks the role and that the event exists; only pieces whose
			// lyrics the rights allow are recorded as printed.
			$allowed = [];
			foreach ($this->sheets->booklet($id, $uid)['pieces'] as $piece) {
				if ($piece['lyrics'] !== null || $piece['translation'] !== null) {
					$allowed[] = $piece['itemId'];
				}
			}
			$items = array_values(array_intersect($allowed, $withLyrics));
			$this->audit->record($this->audit->newOperation(), $uid, 'event', $id, 'booklet_made', 'lyrics', null,
				$items === [] ? '' : implode(',', $items));
			return ['recorded' => true];
		});
	}
}
