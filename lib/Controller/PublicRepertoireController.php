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
use OCA\Cantoria\Service\PublicRepertoireService;
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
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The public repertoire page (milestone 0.7, module public_repertoire): the
 * works chosen by the catalog librarian among those with a version
 * confirmed as public domain, rendered on the server without scripts. Only
 * links to CPDL and IMSLP; nothing is loaded from there. Calls are limited
 * per address; a switched-off module answers with the 404 page of the
 * public pages.
 *
 * @psalm-suppress UnusedClass
 */
#[RequiresModule(Modules::PUBLIC_REPERTOIRE)]
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class PublicRepertoireController extends Controller {
	public function __construct(
		IRequest $request,
		private PublicRepertoireService $repertoire,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 600)]
	#[UserRateLimit(limit: 120, period: 600)]
	#[BruteForceProtection(action: 'cantoria_repertoire')]
	#[FrontpageRoute(verb: 'GET', url: '/repertoire')]
	public function index(): Response {
		try {
			$entries = $this->repertoire->entries();
		} catch (\Throwable $e) {
			$this->logger->error('Cantoria: public repertoire page: ' . $e->getMessage(), ['exception' => $e]);
			return ModuleMiddleware::notFoundPage();
		}
		$response = new PublicTemplateResponse(Application::APP_ID, 'repertoire', [
			'entries' => $entries,
			// The template only prints; its texts are translated here (the
			// string extraction reads lib/)
			'texts' => [
				'heading' => $this->l->t('Repertoire in the public domain'),
				'intro' => $this->l->t('Works from our repertoire that are in the public domain. The links lead to the score libraries CPDL and IMSLP on the internet. Not every edition offered there is free in every country.'),
				'empty' => $this->l->t('No works are listed yet.'),
				'composer' => $this->l->t('Composer'),
				'lyricist' => $this->l->t('Lyricist'),
				'scoring' => $this->l->t('Scoring'),
				'cpdl' => $this->l->t('Scores on CPDL (external site)'),
				'imslp' => $this->l->t('Scores on IMSLP (external site)'),
			],
		]);
		$response->setHeaderTitle($this->l->t('Repertoire'));
		$response->setHeaderDetails($this->l->t('Works in the public domain'));
		// No scripts of its own: Nextcloud's strict default policy, no frames.
		$response->setContentSecurityPolicy(new ContentSecurityPolicy());
		return $response;
	}
}
