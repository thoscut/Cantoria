<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Attribute\RequiresModule;
use OCA\Cantoria\Middleware\ModuleMiddleware;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\SharedLinkService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\FeaturePolicy;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The page of a public link (milestone 0.7, module public_links): an
 * excerpt or a whole piece to view and play without login, in Nextcloud's
 * public layout.
 *
 * The page carries everything it shows (title, composer, measures, voice,
 * verse, tempo and the measures of the excerpt or the whole piece as
 * MusicXML) in its initial state; no download of the score file and no
 * practice audio. The only further route is the scan, when the link offers
 * one (shown inline by the browser's PDF viewer). An unknown, expired or
 * revoked token, a version no longer released for publication and a
 * switched-off module all get the same 404 page
 * (ModuleMiddleware::notFoundPage), which counts for Nextcloud's
 * brute-force protection; calls are limited per address.
 *
 * @psalm-suppress UnusedClass
 */
#[RequiresModule(Modules::PUBLIC_LINKS)]
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class PublicLinkController extends Controller {
	public function __construct(
		IRequest $request,
		private SharedLinkService $shared,
		private IInitialState $initialState,
		private IL10N $l,
		private LoggerInterface $logger,
		private IAppManager $apps,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 600)]
	#[UserRateLimit(limit: 120, period: 600)]
	#[BruteForceProtection(action: 'cantoria_public_link')]
	#[FrontpageRoute(verb: 'GET', url: '/p/{token}')]
	public function show(string $token): Response {
		$excerpt = null;
		if (preg_match('/^[A-Za-z0-9]{32}$/', $token) === 1) {
			try {
				$excerpt = $this->shared->publicExcerpt($token);
			} catch (\Throwable $e) {
				$this->logger->error('Cantoria: public link: ' . $e->getMessage(), ['exception' => $e]);
			}
		}
		if ($excerpt === null) {
			return ModuleMiddleware::notFoundPage();
		}
		$this->initialState->provideInitialState('excerpt', $excerpt);
		// Practising without a network (milestone 0.15): the version names the cache of the page
		$this->initialState->provideInitialState('version', $this->apps->getAppVersion(Application::APP_ID));
		$response = new PublicTemplateResponse(Application::APP_ID, 'public', []);
		$response->setHeaderTitle($excerpt['title'] !== '' ? $excerpt['title'] : $this->l->t('Score'));
		$response->setHeaderDetails($excerpt['measureFrom'] === null || $excerpt['measureTo'] === null
			? $this->l->t('Whole piece')
			: $this->l->t('Measures %1$s to %2$s', [$excerpt['measureFrom'], $excerpt['measureTo']]));
		$response->setContentSecurityPolicy(self::policy());
		$response->setFeaturePolicy(self::recordingPolicy());
		// A link that expires: not for search engines.
		$response->addHeader('X-Robots-Tag', 'noindex, nofollow');
		// The service worker keeps this page on the device only when the visitor asks for it (milestone 0.15)
		$response->addHeader('X-Cantoria-Page', 'public');
		// the page is the piece: it stays on the device only while the server says so (S N5)
		$response->addHeader(OfflineController::KEEP_HEADER, $excerpt['offline'] ? '1' : '0');
		$response->cacheFor(0, false, false);
		return $response;
	}

	/**
	 * The scan a public link offers, for the browser's own PDF viewer
	 * (inline): read through the account of the archive folder, with the
	 * same checks as the page. Every failure, also a link without a scan,
	 * gets the same 404 page as the page itself.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 600)]
	#[UserRateLimit(limit: 60, period: 600)]
	#[BruteForceProtection(action: 'cantoria_public_link')]
	#[FrontpageRoute(verb: 'GET', url: '/p/{token}/scan')]
	public function scan(string $token): Response {
		$scan = null;
		if (preg_match('/^[A-Za-z0-9]{32}$/', $token) === 1) {
			try {
				$scan = $this->shared->publicScan($token);
			} catch (\Throwable $e) {
				$this->logger->error('Cantoria: public link: ' . $e->getMessage(), ['exception' => $e]);
			}
		}
		if ($scan === null) {
			return ModuleMiddleware::notFoundPage();
		}
		$response = ScanResponse::inline($scan['file']);
		$response->addHeader('X-Robots-Tag', 'noindex, nofollow');
		$response->addHeader(OfflineController::KEEP_HEADER, $scan['keep'] ? '1' : '0');
		return $response;
	}

	/**
	 * As the app page: the bundled notation engine needs WebAssembly
	 * ('wasm-unsafe-eval'); no JavaScript eval, no other source, frames only
	 * from the browser's memory.
	 */
	public static function policy(): ContentSecurityPolicy {
		$policy = new ContentSecurityPolicy();
		$policy->allowEvalWasm(true);
		// a recording made while practising is played from the browser's memory
		$policy->addAllowedMediaDomain('blob:');
		// practising without a network (milestone 0.15): the service worker of the app's own
		// route, and a kept scan shown in the page from the browser's memory
		$policy->addAllowedWorkerSrcDomain('\'self\'');
		$policy->addAllowedFrameDomain('blob:');
		return $policy;
	}

	/**
	 * The microphone for this page only, for recording oneself while
	 * practising (as PageController); the browser still asks first.
	 */
	public static function recordingPolicy(): FeaturePolicy {
		$policy = new FeaturePolicy();
		$policy->addAllowedMicrophoneDomain('\'self\'');
		return $policy;
	}
}
