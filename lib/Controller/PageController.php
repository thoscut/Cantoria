<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Service\MetaService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\SharedLinkService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\FeaturePolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IRequest;

/**
 * The app page. Accounts with an archive role (or admin rights) get the
 * music archive. Choir members without an archive role get only the page
 * "Shared with me" (milestone 0.7), while the module member_links is on;
 * everyone else is refused.
 *
 * @psalm-suppress UnusedClass
 */
class PageController extends Controller {
	/** The guides (as in appinfo/info.xml and src/docs.ts) */
	private const DOCS = 'https://github.com/thoscut/choircoresmanager/blob/main/docs/';

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private MetaService $meta,
		private IInitialState $initialState,
		private IL10N $l,
		private Modules $modules,
		private SharedLinkService $shared,
		private IAppManager $apps,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @psalm-suppress PossiblyUnusedMethod a route; the class is also used for its policies
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		if ($this->userId !== null && !$this->roles->hasAccess($this->userId)
			&& $this->roles->isMember($this->userId) && $this->modules->isEnabled(Modules::MEMBER_LINKS)) {
			return $this->memberPage($this->userId);
		}
		if ($this->userId === null || !$this->roles->hasAccess($this->userId)) {
			// a choir member whose page is switched off asks the conductor, not the administration
			$memberPageOff = $this->userId !== null && $this->roles->isMember($this->userId);
			$response = new TemplateResponse(Application::APP_ID, 'forbidden', [
				'heading' => $this->l->t('No access yet'),
				'message' => $memberPageOff
					? $this->l->t('The page for choir members is switched off at the moment. Please ask the conductor.')
					: $this->l->t('You have no access to the music archive: your account has no role in it. Please ask the administration to add your account to the group of your role.'),
				'guideUrl' => self::noAccessGuideUrl($this->l->getLanguageCode()),
				'guideLabel' => $this->l->t('No access to the music archive (guide, opens in a new tab)'),
			], TemplateResponse::RENDER_AS_ERROR);
			$response->setStatus(Http::STATUS_FORBIDDEN);
			return $response;
		}
		$this->initialState->provideInitialState('meta', $this->meta->forUser($this->userId));
		return self::withScorePolicy(new TemplateResponse(Application::APP_ID, 'index'), true);
	}

	/**
	 * The section of the user guide about a missing role: the German guide
	 * for German, otherwise the English one (the anchors are those of
	 * src/docs.ts, USER_GUIDE.noAccess; a unit test there checks them).
	 */
	public static function noAccessGuideUrl(string $language): string {
		return str_starts_with(strtolower($language), 'de')
			? self::DOCS . 'de/benutzerhandbuch.md#kein-zugang-zum-notenarchiv'
			: self::DOCS . 'user-guide.md#no-access-to-the-music-archive';
	}

	/**
	 * The page of the choir members: the links shared with them, nothing of
	 * the catalog. Reasons are translated here.
	 */
	private function memberPage(string $uid): TemplateResponse {
		$links = array_map(fn (array $link): array => ['reason' => $link['reason'] === null ? null : $this->l->t($link['reason'])] + $link,
			$this->shared->forMember($uid));
		$this->initialState->provideInitialState('member', [
			'links' => $links,
			// Practising without a network (milestone 0.15): the version names the cache of the page
			'offline' => [
				'enabled' => $this->modules->isEnabled(Modules::OFFLINE),
				'version' => $this->apps->getAppVersion(Application::APP_ID),
			],
		]);
		$response = self::withScorePolicy(new TemplateResponse(Application::APP_ID, 'member'), false, true);
		// The service worker keeps only this page on the device, never the archive or another page
		$response->addHeader('X-Cantoria-Page', 'member');
		return $response;
	}

	/**
	 * The score viewer compiles the bundled notation engine (WebAssembly, no
	 * external source). This needs 'wasm-unsafe-eval' only; JavaScript eval
	 * stays forbidden and no other source is allowed. The archive also shows
	 * the scan beside the score: a PDF from the app's own route, shown by the
	 * browser's PDF viewer in a frame. Nothing from other origins.
	 * Recording oneself while practising (milestone 0.13) needs the
	 * microphone of this page and plays the recording from the browser's
	 * memory (blob:); it never leaves the browser.
	 */
	public static function withScorePolicy(TemplateResponse $response, bool $scanFrames, bool $offlineWorker = false): TemplateResponse {
		$policy = new ContentSecurityPolicy();
		$policy->allowEvalWasm(true);
		$policy->addAllowedMediaDomain('blob:');
		if ($offlineWorker) {
			// The service worker for practising without a network comes from the app's own route;
			// a kept scan is shown in the page from the browser's memory
			$policy->addAllowedWorkerSrcDomain('\'self\'');
			$policy->addAllowedFrameDomain('blob:');
		}
		if ($scanFrames) {
			$policy->addAllowedFrameDomain('\'self\'');
		}
		$response->setContentSecurityPolicy($policy);
		$response->setFeaturePolicy(self::recordingPolicy());
		return $response;
	}

	/**
	 * The microphone for this page only, for recording oneself while
	 * practising; the browser still asks the person first.
	 */
	public static function recordingPolicy(): FeaturePolicy {
		$policy = new FeaturePolicy();
		$policy->addAllowedMicrophoneDomain('\'self\'');
		return $policy;
	}
}
