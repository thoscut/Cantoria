<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Attribute\RequiresModule;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\NotFoundException;
use OCA\Cantoria\Exception\UnknownLinkException;
use OCA\Cantoria\Exception\UserFacingException;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\PracticeAudioService;
use OCA\Cantoria\Service\SharedLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The routes of the choir members (milestone 0.7, module member_links): the
 * measures of a link shared with them (or the whole piece), the scan the
 * link offers and, with the module member_practice_audio, practice audio
 * files of one voice; with the module offline (milestone 0.15) the list of
 * their links as JSON, with which their page checks the pieces kept on their
 * device (the service worker: OfflineController). Together with
 * their page (PageController::index) these are the only routes a choir
 * member without an archive role reaches; every other route checks for an
 * archive role (Roles::hasAccess) and refuses them. The service checks that
 * the account belongs to the choir members.
 *
 * @psalm-suppress UnusedClass
 */
#[RequiresModule(Modules::MEMBER_LINKS)]
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class MemberController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private SharedLinkService $shared,
		private PracticeAudioService $practiceAudio,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Only the measures of the excerpt, or the whole piece for a link
	 * without measures, as MusicXML; never the score file.
	 * An unknown token counts for Nextcloud's brute-force protection.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 600)]
	#[BruteForceProtection(action: 'cantoria_link')]
	#[FrontpageRoute(verb: 'GET', url: '/api/member/links/{token}/score', requirements: ['token' => '[A-Za-z0-9]{32}'])]
	public function score(string $token): Response {
		if ($this->userId === null) {
			return new JSONResponse(['error' => $this->l->t('Not logged in.')], Http::STATUS_UNAUTHORIZED);
		}
		try {
			['xml' => $xml, 'keep' => $keep] = $this->shared->memberScore($token, $this->userId);
		} catch (UserFacingException $e) {
			return $this->failure($e);
		}
		// As the score files of the archive: never rendered on the Nextcloud
		// origin, no type guessing, the strict policy of data responses.
		$response = new DataDisplayResponse($xml, Http::STATUS_OK, ['Content-Type' => 'application/vnd.recordare.musicxml+xml']);
		$response->addHeader('Content-Disposition', 'attachment; filename="excerpt.musicxml"');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->cacheFor(0, false, false);
		// the service worker keeps it on the device only while the server says so (S N5)
		$response->addHeader(OfflineController::KEEP_HEADER, $keep ? '1' : '0');
		return $response;
	}

	/**
	 * The scan a link offers, for the browser's own PDF viewer (inline).
	 * Opened by the browser itself in a tab of its own, therefore without
	 * CSRF token; it only reads, with the same checks as the score. Whoever
	 * sees the scan can save it; the link says so when it is made.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 60, period: 600)]
	#[BruteForceProtection(action: 'cantoria_link')]
	#[FrontpageRoute(verb: 'GET', url: '/api/member/links/{token}/scan', requirements: ['token' => '[A-Za-z0-9]{32}'])]
	public function scan(string $token): Response {
		if ($this->userId === null) {
			return new JSONResponse(['error' => $this->l->t('Not logged in.')], Http::STATUS_UNAUTHORIZED);
		}
		try {
			['file' => $scan, 'keep' => $keep] = $this->shared->memberScan($token, $this->userId);
			$response = ScanResponse::inline($scan);
		} catch (UserFacingException $e) {
			return $this->failure($e);
		}
		$response->addHeader(OfflineController::KEEP_HEADER, $keep ? '1' : '0');
		return $response;
	}

	/**
	 * `{ error }` with 403 for a refused permission, 404 for something not
	 * there, 400 otherwise; an unknown token counts for the brute-force
	 * protection.
	 */
	private function failure(UserFacingException $e): JSONResponse {
		$status = match (true) {
			$e instanceof AccessDeniedException => Http::STATUS_FORBIDDEN,
			$e instanceof NotFoundException => Http::STATUS_NOT_FOUND,
			default => Http::STATUS_BAD_REQUEST,
		};
		$response = new JSONResponse(['error' => $this->translate($e->getMessage(), $e->getParameters())], $status);
		if ($e instanceof UnknownLinkException) {
			$response->throttle(['reason' => 'unknown_token']);
		}
		return $response;
	}

	/**
	 * Allows one practice audio file of one voice of a shared excerpt and
	 * records it in the audit log; 403 with the reason when the rights do
	 * not allow it.
	 *
	 * @param array<string, mixed> $export voice, mode, format, tempo, repeats (see docs/api.md)
	 */
	#[NoAdminRequired]
	#[RequiresModule(Modules::MEMBER_PRACTICE_AUDIO)]
	#[UserRateLimit(limit: 30, period: 600)]
	#[BruteForceProtection(action: 'cantoria_link')]
	#[FrontpageRoute(verb: 'POST', url: '/api/member/links/{token}/practice-audio', requirements: ['token' => '[A-Za-z0-9]{32}'])]
	public function practiceAudio(string $token, array $export = []): JSONResponse {
		return $this->run(fn (string $uid) => $this->practiceAudio->authorizeForMember($token, $export, $uid));
	}

	/**
	 * The links shared with the account, as on the page "Shared with me"
	 * (milestone 0.15): the page compares the pieces kept on the device with
	 * it at every start with a network and removes expired and revoked ones.
	 */
	#[NoAdminRequired]
	#[RequiresModule(Modules::OFFLINE)]
	#[UserRateLimit(limit: 120, period: 600)]
	#[BruteForceProtection(action: 'cantoria_link')]
	#[FrontpageRoute(verb: 'GET', url: '/api/member/links')]
	public function links(): JSONResponse {
		return $this->run(fn (string $uid): array => ['links' => $this->translatedLinks($uid)]);
	}

	/**
	 * The links of a choir member with the reasons translated, as the page
	 * gets them (PageController::memberPage).
	 *
	 * @return list<array<string, mixed>>
	 * @throws UserFacingException
	 */
	private function translatedLinks(string $uid): array {
		return array_map(fn (array $link): array => ['reason' => $link['reason'] === null ? null : $this->l->t($link['reason'])] + $link,
			$this->shared->forMember($uid));
	}
}
