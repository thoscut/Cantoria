<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Service\Modules;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * The service worker of the pages that keep pieces on a device (milestone
 * 0.15): "Shared with me", public links and the concert folder, all only
 * with the module "offline" (milestone 0.19: the folder keeps nothing
 * without it). Served from the app's own path, so that its scope covers
 * these pages. It keeps nothing by itself: it only answers from what a page
 * put on the device, when there is no network, and keeps a score, a scan or
 * the page of a public link only while the server's answer says so with the
 * header X-Cantoria-Keep (S N5). Registered by the browser, also on the page
 * of a public link, therefore without login and CSRF token; it is a script
 * of the app and contains no data, only the version of the app and the path
 * of its scripts, which it loads before it takes over after an update (A1).
 * With the module off, a worker that deletes everything kept on the device
 * and unregisters itself; therefore the route belongs to the core and is
 * always on.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class OfflineController extends Controller {
	/**
	 * The header of a score, a scan or the page of a public link that says
	 * whether it may stay on the device: '1' or '0'. The worker keeps an
	 * answer without '1' nowhere and removes what it kept of it.
	 */
	public const KEEP_HEADER = 'X-Cantoria-Keep';

	public function __construct(
		IRequest $request,
		private Modules $modules,
		private IAppManager $apps,
		private IL10N $l,
		private IURLGenerator $urls,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @psalm-suppress PossiblyUnusedMethod a route; the class is also used for its header KEEP_HEADER
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 600)]
	#[UserRateLimit(limit: 60, period: 600)]
	#[BruteForceProtection(action: 'cantoria_offline_worker')]
	#[FrontpageRoute(verb: 'GET', url: '/offline-worker')]
	public function worker(): Response {
		$script = self::REMOVING_WORKER;
		if ($this->modules->isEnabled(Modules::OFFLINE)) {
			$built = @file_get_contents(__DIR__ . '/../../js/' . Application::APP_ID . '-offline-worker.js');
			if ($built === false) {
				return new JSONResponse(['error' => $this->l->t('The app is not built.')], Http::STATUS_NOT_FOUND);
			}
			// A new version of the app gives a new worker, which drops the old cache of the pages;
			// the path of the app's files (/apps/cantoria/ or /custom_apps/cantoria/) for its scripts
			$path = $this->urls->linkTo(Application::APP_ID, '');
			$script = str_replace(
				['__CANTORIA_VERSION__', '__CANTORIA_APP_PATH__'],
				[$this->apps->getAppVersion(Application::APP_ID), preg_match('#^/[A-Za-z0-9/._~%-]*$#', $path) === 1 ? $path : ''],
				$built,
			);
		}
		$response = new DataDisplayResponse($script, Http::STATUS_OK, ['Content-Type' => 'text/javascript; charset=utf-8']);
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->cacheFor(0, false, false);
		// The policy of the worker itself: requests to this server only
		$response->setContentSecurityPolicy(new ContentSecurityPolicy());
		return $response;
	}

	/** The worker while the module is off: it removes what the app kept on the device. */
	public const REMOVING_WORKER = <<<'JS'
		self.addEventListener('install', () => self.skipWaiting())
		self.addEventListener('activate', (event) => event.waitUntil((async () => {
			for (const key of await caches.keys()) {
				if (key.startsWith('cantoria-')) {
					await caches.delete(key)
				}
			}
			await self.registration.unregister()
		})()))
		JS;
}
