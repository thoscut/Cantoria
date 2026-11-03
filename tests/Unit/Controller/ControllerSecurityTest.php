<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Controller;

use OCA\Cantoria\Controller\AnnualSnapshotController;
use OCA\Cantoria\Controller\ApiController;
use OCA\Cantoria\Controller\ApiV1StatusController;
use OCA\Cantoria\Controller\CalendarController;
use OCA\Cantoria\Controller\ChoirSystemController;
use OCA\Cantoria\Controller\ConcertFolderController;
use OCA\Cantoria\Controller\FilesIntegrationController;
use OCA\Cantoria\Controller\FolderBookController;
use OCA\Cantoria\Controller\HandoverController;
use OCA\Cantoria\Controller\MaintenanceController;
use OCA\Cantoria\Controller\MemberController;
use OCA\Cantoria\Controller\OfflineController;
use OCA\Cantoria\Controller\PageController;
use OCA\Cantoria\Controller\PracticeAudioController;
use OCA\Cantoria\Controller\PrintMirrorController;
use OCA\Cantoria\Controller\PublicLinkController;
use OCA\Cantoria\Controller\PublicRepertoireController;
use OCA\Cantoria\Controller\ReviewController;
use OCA\Cantoria\Controller\ScanResponse;
use OCA\Cantoria\Controller\SettingsController;
use OCA\Cantoria\Controller\StateController;
use OCA\Cantoria\Controller\TaskController;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\AuditEntryMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\ExcerptLinkMapper;
use OCA\Cantoria\Db\FolderLoanMapper;
use OCA\Cantoria\Db\FolderMapper;
use OCA\Cantoria\Db\LicenseMapper;
use OCA\Cantoria\Db\MirrorFileMapper;
use OCA\Cantoria\Db\ReviewPointMapper;
use OCA\Cantoria\Db\TranscriptionReportMapper;
use OCA\Cantoria\Db\VersionState;
use OCA\Cantoria\Db\VersionStateMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\ModuleDisabledException;
use OCA\Cantoria\Exception\NotFoundException;
use OCA\Cantoria\Exception\NotSharedException;
use OCA\Cantoria\Exception\UnknownLinkException;
use OCA\Cantoria\Middleware\ModuleMiddleware;
use OCA\Cantoria\Service\AnnualSnapshotService;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\ArchiveAccess;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\CatalogService;
use OCA\Cantoria\Service\ChoirSystemCompareService;
use OCA\Cantoria\Service\ConcertCalendarService;
use OCA\Cantoria\Service\ConcertFolderService;
use OCA\Cantoria\Service\CorrectionService;
use OCA\Cantoria\Service\DueService;
use OCA\Cantoria\Service\ExportService;
use OCA\Cantoria\Service\FilesIntegrationService;
use OCA\Cantoria\Service\FileSyncService;
use OCA\Cantoria\Service\FolderBookService;
use OCA\Cantoria\Service\FullBackupService;
use OCA\Cantoria\Service\HandoverService;
use OCA\Cantoria\Service\ImportService;
use OCA\Cantoria\Service\LinkService;
use OCA\Cantoria\Service\MetaService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\NotificationService;
use OCA\Cantoria\Service\PracticeAudioService;
use OCA\Cantoria\Service\PrintMirrorService;
use OCA\Cantoria\Service\PublicRepertoireService;
use OCA\Cantoria\Service\ReleaseListService;
use OCA\Cantoria\Service\RepertoireService;
use OCA\Cantoria\Service\ReviewService;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\RunLock;
use OCA\Cantoria\Service\ScanService;
use OCA\Cantoria\Service\ScoreService;
use OCA\Cantoria\Service\SharedLinkService;
use OCA\Cantoria\Service\StateService;
use OCA\Cantoria\Service\TaskService;
use OCA\Cantoria\Service\UserFiles;
use OCA\Cantoria\Settings\Admin;
use OCA\Cantoria\Tests\Unit\Support\ModuleSwitches;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\SubAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Services\IInitialState;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Group\ISubAdmin;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Settings\IManager as ISettingsManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Security-relevant properties of the controllers: route attributes (who may
 * call what, CSRF, rate limits, brute-force protection, modules), response
 * headers of user files and the content security policy of the app page.
 */
#[CoversClass(ApiController::class)]
#[CoversClass(CalendarController::class)]
#[CoversClass(FilesIntegrationController::class)]
#[CoversClass(PageController::class)]
#[CoversClass(SettingsController::class)]
#[CoversClass(TaskController::class)]
#[CoversClass(ApiV1StatusController::class)]
#[CoversClass(ModuleMiddleware::class)]
#[CoversClass(ReviewController::class)]
#[CoversClass(MemberController::class)]
#[CoversClass(PublicLinkController::class)]
#[CoversClass(ScanResponse::class)]
#[CoversClass(PublicRepertoireController::class)]
#[CoversClass(FolderBookController::class)]
#[CoversClass(PrintMirrorController::class)]
#[CoversClass(HandoverController::class)]
#[CoversClass(ChoirSystemController::class)]
#[CoversClass(AnnualSnapshotController::class)]
#[CoversClass(StateController::class)]
final class ControllerSecurityTest extends TestCase {
	use ModuleSwitches;

	/**
	 * Routes without #[NoAdminRequired]: the settings of the instance. Each
	 * carries #[AuthorizedAdminSetting(settings: Admin::class)]: admins, and
	 * the groups the administration has given the page "Administration →
	 * Music archive" (Administration privileges), nobody else.
	 */
	private const SETTINGS = [
		'SettingsController::index', 'SettingsController::update', 'SettingsController::groups', 'SettingsController::access',
		'EventController::setThresholds', 'ModuleController::settings', 'ModuleController::update',
		'FolderBookController::settings', 'FolderBookController::updateSettings',
		'PrintMirrorController::settings', 'PrintMirrorController::check', 'PrintMirrorController::updateSettings',
		'HandoverController::settings', 'HandoverController::check', 'HandoverController::updateSettings',
	];

	/**
	 * Routes of the core, always on (ROADMAP, guideline 12): catalog, start
	 * page, file sync and manual file links, roles, audit log, import,
	 * export, backup, settings and the personal view. Every other route
	 * names its module (attribute RequiresModule).
	 */
	private const CORE = [
		'PageController::index',
		'ApiController::meta', 'ApiController::entries', 'ApiController::entry', 'ApiController::update',
		'ApiController::import', 'ApiController::fileSync', 'ApiController::fileSyncStatus', 'ApiController::export',
		'ApiController::exportStore', 'ApiController::audit',
		'SettingsController::index', 'SettingsController::update', 'SettingsController::groups', 'SettingsController::access',
		'WorkflowController::backup', 'WorkflowController::fullBackup', 'WorkflowController::restore',
		'TaskController::due',
		'MaintenanceController::linkFile', 'MaintenanceController::unlinkFile',
		'ModuleController::settings', 'ModuleController::update', 'ModuleController::personalView',
		// the service worker (milestone 0.15): with its modules off it removes what was kept, so it is always there
		'OfflineController::worker',
	];

	/**
	 * Plain GET downloads (links, no CSRF token) that change nothing. The CSV
	 * files of the statistics and the annual report and the calendar files of
	 * concerts are such downloads: they only read, like the song order, need a
	 * logged-in account with access and are rate limited. Adding entries to a
	 * calendar (POST) keeps the CSRF check.
	 */
	private const WITHOUT_CSRF = [
		'PageController::index', 'ApiController::export', 'WorkflowController::fullBackup', 'EventController::songOrder',
		'StatisticsController::csv', 'StatisticsController::reportCsv',
		'CalendarController::concertFile', 'CalendarController::upcomingFile',
		// The scan beside the score is opened by the browser itself (frame or window).
		'ReviewController::scan',
		// The public pages (milestone 0.7) are opened by the browser, without login.
		'PublicLinkController::show', 'PublicRepertoireController::index',
		// The scan of a link for choir members or of a public link, opened by the browser in a tab of its own.
		'MemberController::scan', 'PublicLinkController::scan',
		// The service worker of the pages that keep pieces (milestone 0.15), fetched by the browser itself; no data.
		'OfflineController::worker',
		// The concert folder (milestone 0.15), a page opened by the browser, as the app page.
		'ConcertFolderController::page',
	];

	/** Expensive endpoints: limited per user (the statistics aggregate the whole archive) */
	private const RATE_LIMITED = [
		'ApiController::import', 'ApiController::fileSync', 'ApiController::export', 'ApiController::exportStore',
		'WorkflowController::backup', 'WorkflowController::fullBackup', 'WorkflowController::restore',
		'EventController::songOrder', 'EventController::importPerformances', 'PersonController::import',
		'PracticeAudioController::authorize',
		'StatisticsController::overview', 'StatisticsController::report', 'StatisticsController::csv',
		'StatisticsController::reportCsv',
		'CalendarController::concertFile', 'CalendarController::upcomingFile', 'CalendarController::addConcert',
		'CalendarController::addUpcoming', 'SettingsController::access',
		'ReviewController::ingest', 'ReviewController::correct', 'ReviewController::scan',
		// answers only for files the account sees; limited against trying file ids all the same
		'FilesIntegrationController::copyOfFile',
		// the routes of the choir members and the public pages (milestone 0.7)
		'MemberController::score', 'MemberController::practiceAudio', 'MemberController::scan',
		'MemberController::links', 'OfflineController::worker',
		'ConcertFolderController::concerts', 'ConcertFolderController::score', 'ConcertFolderController::scan',
		'PublicLinkController::show', 'PublicLinkController::scan', 'PublicRepertoireController::index',
		// the folder book writes, and the print station reads and writes files
		'FolderBookController::createFolder', 'FolderBookController::updateFolder', 'FolderBookController::issue',
		'FolderBookController::takeBack', 'PrintMirrorController::preview', 'PrintMirrorController::sync',
		'HandoverController::preview', 'HandoverController::sync',
		// reads an export of the choir system and may write proposals
		'ChoirSystemController::compare',
		// reads every archive file for the checksums, or two complete backups
		'AnnualSnapshotController::create', 'AnnualSnapshotController::compare',
		// setting the current revision of a version writes
		'StateController::setCurrent',
	];

	/**
	 * The only public routes (#[PublicPage], milestone 0.7) and their module:
	 * the page of a public link with the scan it offers, the public
	 * repertoire page, and the service worker that keeps a public link on a
	 * device (milestone 0.15; core, null: a script without data). Their
	 * controllers have no other route, so that the exception cannot grow by
	 * a method added later.
	 */
	private const PUBLIC_ROUTES = [
		'PublicLinkController::show' => 'public_links',
		'PublicLinkController::scan' => 'public_links',
		'PublicRepertoireController::index' => 'public_repertoire',
		'OfflineController::worker' => null,
	];

	/**
	 * The routes a choir member without an archive role reaches (milestone
	 * 0.7): the app page, which shows them only "Shared with me", and the
	 * routes of MemberController. Every other route checks for an archive
	 * role: Roles::hasAccess() and Roles::rolesOf() leave the choir members
	 * out (testChoirMembersHoldNoArchiveRole).
	 */
	private const MEMBER_ROUTES = [
		'PageController::index' => [],
		'MemberController::score' => ['member_links'],
		'MemberController::practiceAudio' => ['member_links', 'member_practice_audio'],
		'MemberController::scan' => ['member_links'],
		// practising without a network (milestone 0.15): the list for the check (the worker is public)
		'MemberController::links' => ['member_links', 'offline'],
	];

	/**
	 * Routes of the API for agents (OCS, api/v1) and the route of the UI
	 * that does the same, or null for a function the UI has without a
	 * server call (the text reference). Each API route needs the module
	 * `agent_api` plus exactly the modules of its counterpart.
	 *
	 * The review of transcriptions has no counterpart for deciding review
	 * points (ReviewController::decide) or saving corrections
	 * (ReviewController::correct): people do that in the app.
	 */
	private const API_V1 = [
		'ApiV1StatusController::modules' => 'ApiController::meta',
		'ApiV1CatalogController::search' => 'ApiController::entries',
		'ApiV1CatalogController::copy' => 'ApiController::entry',
		'ApiV1CatalogController::reference' => null,
		'ApiV1TaskController::due' => 'TaskController::due',
		'ApiV1TaskController::tasks' => 'TaskController::tasks',
		'ApiV1TaskController::createTask' => 'TaskController::createTask',
		'ApiV1TaskController::answer' => 'TaskController::answer',
		'ApiV1ProposalController::proposals' => 'WorkflowController::proposals',
		'ApiV1ProposalController::propose' => 'WorkflowController::propose',
		'ApiV1ConcertController::concerts' => 'EventController::list',
		'ApiV1ConcertController::concert' => 'EventController::detail',
		'ApiV1StatisticsController::statistics' => 'StatisticsController::overview',
		'ApiV1ReviewController::review' => 'ReviewController::review',
		'ApiV1ReviewController::orders' => 'ReviewController::orders',
		'ApiV1ReviewController::submitReport' => 'ReviewController::ingest',
		'ApiV1StateController::states' => 'StateController::list',
	];

	/**
	 * Routes of the UI that decide or change what exists: the API for agents
	 * must never offer them (guideline 1, people decide). Checked by their
	 * path, so that no API route reaches them under another name.
	 */
	private const NEVER_FOR_AGENTS = [
		'ReviewController::decide', 'ReviewController::correct', 'StateController::setCurrent',
	];

	/**
	 * @return array<string, array{verb: string, url: string, ocs: bool, attributes: list<class-string>, settings: list<string>}> "Class::method" => route
	 */
	private static function routes(): array {
		$routes = [];
		foreach (glob(__DIR__ . '/../../../lib/Controller/*Controller.php') ?: [] as $file) {
			$class = 'OCA\\Cantoria\\Controller\\' . basename($file, '.php');
			self::assertTrue(is_subclass_of($class, Controller::class), $class);
			foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
				$route = $method->getAttributes(FrontpageRoute::class)[0] ?? $method->getAttributes(ApiRoute::class)[0] ?? null;
				if ($route === null) {
					continue;
				}
				$routes[basename($file, '.php') . '::' . $method->getName()] = [
					'verb' => $route->newInstance()->getVerb(),
					'url' => $route->newInstance()->getUrl(),
					'ocs' => $route->getName() === ApiRoute::class,
					'attributes' => array_map(fn (\ReflectionAttribute $a): string => $a->getName(), $method->getAttributes()),
					// The settings whose holders may call the route besides admins
					'settings' => array_map(fn (\ReflectionAttribute $a): string => $a->newInstance()->getSettings(),
						$method->getAttributes(AuthorizedAdminSetting::class)),
				];
			}
		}
		self::assertGreaterThan(90, count($routes), 'all controllers were found');
		return $routes;
	}

	/**
	 * Every route of the API for agents: an OCS controller under /api/v1/,
	 * the module `agent_api` plus the modules of the same function in the
	 * UI, for every account with a role (#[NoAdminRequired]; the services
	 * check the roles), limited per account, never public.
	 *
	 * None skips the CSRF check (#[NoCSRFRequired]), and none needs to:
	 * Nextcloud's SecurityMiddleware lets a request to an OCS controller pass
	 * without a CSRF token only when it carries the header
	 * `OCS-APIRequest: true` (or a Bearer token). A foreign web page cannot
	 * send that header with the cookies of a logged-in browser without a
	 * CORS preflight, which these routes do not allow (no #[CORS]). With
	 * #[NoCSRFRequired], such a page could make a logged-in browser create
	 * tasks or proposals with a plain form.
	 */
	public function testEveryApiV1RouteNeedsTheInterfaceItsModuleARoleAndALimit(): void {
		$routes = self::routes();
		$ocs = array_keys(array_filter($routes, fn (array $route): bool => $route['ocs']));
		sort($ocs);
		$expected = array_keys(self::API_V1);
		sort($expected);
		self::assertSame($expected, $ocs, 'every OCS route is listed in API_V1');

		foreach (self::API_V1 as $name => $counterpart) {
			$route = $routes[$name];
			[$class, $method] = explode('::', $name);
			self::assertTrue(is_subclass_of('OCA\\Cantoria\\Controller\\' . $class, OCSController::class), $name . ' is an OCS controller');
			self::assertStringStartsWith('/api/v1/', $route['url'], $name);
			self::assertContains(NoAdminRequired::class, $route['attributes'], $name);
			self::assertContains(UserRateLimit::class, $route['attributes'], $name . ' is rate limited');
			self::assertNotContains(NoCSRFRequired::class, $route['attributes'], $name . ' keeps the CSRF check (OCS header)');
			self::assertNotContains(PublicPage::class, $route['attributes'], $name);
			self::assertNotContains(CORS::class, $route['attributes'], $name);

			$modules = ModuleMiddleware::required('OCA\\Cantoria\\Controller\\' . $class, $method);
			$expectedModules = [Modules::AGENT_API];
			if ($counterpart !== null) {
				self::assertArrayHasKey($counterpart, $routes, 'counterpart of ' . $name);
				[$counterClass, $counterMethod] = explode('::', $counterpart);
				$expectedModules = [...$expectedModules, ...ModuleMiddleware::required('OCA\\Cantoria\\Controller\\' . $counterClass, $counterMethod)];
			}
			sort($modules);
			sort($expectedModules);
			self::assertSame($expectedModules, $modules, $name . ': agent_api plus the module of the function');
		}
	}

	/**
	 * Agents read, ask, answer, propose and hand in reports; they never
	 * decide a review point, save a correction or confirm anything. The
	 * writing routes of api/v1 are exactly these four.
	 */
	#[CoversNothing]
	public function testNoApiV1RouteDecidesOrCorrects(): void {
		$routes = self::routes();
		foreach (self::NEVER_FOR_AGENTS as $name) {
			self::assertArrayHasKey($name, $routes, 'the route of the UI exists');
			self::assertNotContains($name, self::API_V1, $name . ' has no counterpart in api/v1');
		}
		$writing = array_filter($routes, fn (array $route): bool => $route['ocs'] && $route['verb'] !== 'GET');
		foreach ($writing as $name => $route) {
			self::assertDoesNotMatchRegularExpression('/decision|decide|corrections|confirm|valid|licen|close/', $route['url'], $name);
		}
		$names = array_keys($writing);
		sort($names);
		self::assertSame(['ApiV1ProposalController::propose', 'ApiV1ReviewController::submitReport', 'ApiV1TaskController::answer',
			'ApiV1TaskController::createTask'], $names, 'the only writing routes of api/v1');
	}

	public function testASwitchedOffApiV1RouteAnswers404WithOcsMeta(): void {
		$l = $this->l10n();
		$controller = new ApiV1StatusController($this->createStub(IRequest::class), 'agent', $this->createStub(Roles::class),
			$this->modulesWithout(), $this->createStub(RightsPolicy::class), $l, $this->createStub(LoggerInterface::class));
		(new ModuleMiddleware($this->modulesWithout(), $l))->beforeController($controller, 'modules');

		$middleware = new ModuleMiddleware($this->modulesWithout([Modules::AGENT_API]), $l);
		try {
			$middleware->beforeController($controller, 'modules');
			self::fail('the interface for agents is off');
		} catch (ModuleDisabledException $e) {
			try {
				$middleware->afterException($controller, 'modules', $e);
				self::fail('an OCS route gets an OCS exception, which Nextcloud turns into the OCS envelope');
			} catch (OCSNotFoundException $ocs) {
				self::assertSame(Http::STATUS_NOT_FOUND, $ocs->getCode());
				self::assertSame('This function is switched off.', $ocs->getMessage());
			}
		}
	}

	/**
	 * Every route needs a login, except the two public pages: each needs its
	 * module, is a plain GET, limited per address and per account, protected
	 * against guessing (brute force) and never an OCS or CORS route.
	 */
	#[CoversNothing]
	public function testOnlyTheSharingPagesArePublic(): void {
		$routes = self::routes();
		$public = array_keys(array_filter($routes, fn (array $route): bool => in_array(PublicPage::class, $route['attributes'], true)));
		sort($public);
		$expected = array_keys(self::PUBLIC_ROUTES);
		sort($expected);
		self::assertSame($expected, $public, 'the only public routes');
		foreach (self::PUBLIC_ROUTES as $name => $module) {
			$route = $routes[$name];
			[$class, $method] = explode('::', $name);
			self::assertSame('GET', $route['verb'], $name);
			self::assertFalse($route['ocs'], $name);
			self::assertSame($module === null ? [] : [$module], ModuleMiddleware::required('OCA\\Cantoria\\Controller\\' . $class, $method), $name . ' needs its module');
			self::assertContains(AnonRateLimit::class, $route['attributes'], $name . ' is limited per address');
			self::assertContains(UserRateLimit::class, $route['attributes'], $name . ' is limited per account');
			self::assertContains(BruteForceProtection::class, $route['attributes'], $name);
			self::assertNotContains(CORS::class, $route['attributes'], $name);
			$others = array_filter(array_keys($routes), fn (string $other): bool => str_starts_with($other, $class . '::')
				&& !array_key_exists($other, self::PUBLIC_ROUTES));
			self::assertSame([], array_values($others), $class . ' has no other route');
		}
	}

	/**
	 * Choir members are no archive role: an account that is only in a group
	 * of the choir members has no access to the archive, so every route that
	 * checks Roles::hasAccess(), requireAccess() or requireRole() refuses it.
	 */
	public function testChoirMembersHoldNoArchiveRole(): void {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueArray')->willReturnCallback(fn (string $app, string $key): array => $key === 'groups_member' ? ['choir'] : []);
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => $uid === 'singer' && $group === 'choir');
		$roles = new Roles($config, $groups, $this->createStub(IUserManager::class), $this->createStub(ISettingsManager::class), $this->createStub(LoggerInterface::class));
		self::assertTrue($roles->isMember('singer'));
		self::assertSame([], $roles->rolesOf('singer'));
		self::assertFalse($roles->hasAccess('singer'));
		self::assertNotContains(Roles::MEMBER, Roles::ALL);
		self::assertContains(Roles::MEMBER, Roles::GROUP_ROLES);
		$this->expectException(AccessDeniedException::class);
		$roles->requireAccess('singer');
	}

	/**
	 * The routes a choir member reaches are exactly MEMBER_ROUTES: the routes
	 * of MemberController (with their modules, rate limits, CSRF check, never
	 * public) and the app page; no other controller asks Roles::isMember().
	 */
	#[CoversNothing]
	public function testOnlyTheMemberRoutesLetChoirMembersIn(): void {
		$routes = self::routes();
		$memberController = array_values(array_filter(array_keys($routes), fn (string $name): bool => str_starts_with($name, 'MemberController::')));
		sort($memberController);
		$expected = array_values(array_filter(array_keys(self::MEMBER_ROUTES), fn (string $name): bool => str_starts_with($name, 'MemberController::')));
		sort($expected);
		self::assertSame($expected, $memberController);
		foreach (self::MEMBER_ROUTES as $name => $modules) {
			self::assertArrayHasKey($name, $routes);
			[$class, $method] = explode('::', $name);
			$required = ModuleMiddleware::required('OCA\\Cantoria\\Controller\\' . $class, $method);
			sort($required);
			self::assertSame($modules, $required, $name);
			self::assertNotContains(PublicPage::class, $routes[$name]['attributes'], $name);
			if ($class === 'MemberController') {
				self::assertContains(UserRateLimit::class, $routes[$name]['attributes'], $name);
				self::assertContains(BruteForceProtection::class, $routes[$name]['attributes'], $name);
				if (!in_array($name, self::WITHOUT_CSRF, true)) {
					// The scan is opened by the browser itself; it only reads (testCsrfIsOnlySkippedForPlainDownloads)
					self::assertNotContains(NoCSRFRequired::class, $routes[$name]['attributes'], $name . ' keeps the CSRF check');
				}
			}
		}
		foreach (glob(__DIR__ . '/../../../lib/Controller/*.php') ?: [] as $file) {
			$asksForMembers = str_contains((string)file_get_contents($file), 'isMember(')
				|| str_contains((string)file_get_contents($file), '->forMember(') || str_contains((string)file_get_contents($file), '->memberScore(');
			self::assertSame(in_array(basename($file, '.php'), ['PageController', 'MemberController'], true), $asksForMembers,
				basename($file) . ': only the app page and MemberController serve choir members');
		}
	}

	private function pageController(bool $access, bool $member, bool $moduleOn, ?SharedLinkService $shared = null): PageController {
		$roles = $this->createStub(Roles::class);
		$roles->method('hasAccess')->willReturn($access);
		$roles->method('isMember')->willReturn($member);
		$meta = $this->createStub(MetaService::class);
		$meta->method('forUser')->willReturn([]);
		return new PageController($this->createStub(IRequest::class), 'singer', $roles, $meta, $this->createStub(IInitialState::class),
			$this->l10n(), $moduleOn ? $this->modulesWithout() : $this->modulesWithout([Modules::MEMBER_LINKS]),
			$shared ?? $this->createStub(SharedLinkService::class), $this->createStub(IAppManager::class));
	}

	public function testChoirMembersGetOnlyTheirPage(): void {
		$shared = $this->createMock(SharedLinkService::class);
		$shared->expects(self::once())->method('forMember')->with('singer')->willReturn([]);
		$response = $this->pageController(false, true, true, $shared)->index();
		self::assertSame('member', $response->getTemplateName(), 'the page "Shared with me", not the archive');
		$policy = $response->getContentSecurityPolicy()->buildPolicy();
		self::assertStringContainsString("'wasm-unsafe-eval'", $policy);
		self::assertStringNotContainsString("'unsafe-eval'", $policy);
		self::assertStringContainsString('frame-src blob:;', $policy, 'no scan beside the score for choir members, only a kept scan from memory');
		self::assertStringContainsString("media-src 'self' blob:", $policy, 'a recording plays from the browser');
		self::assertStringContainsString("microphone 'self'", $response->getFeaturePolicy()->buildPolicy(), 'recording oneself');
		self::assertStringContainsString("camera 'none'", $response->getFeaturePolicy()->buildPolicy(), 'nothing else is opened');
		self::assertStringContainsString("worker-src 'self'", $policy, 'the service worker for practising without a network');
		self::assertSame('member', self::headers($response)['X-Cantoria-Page'] ?? null, 'the only page the worker keeps');

		$off = $this->pageController(false, true, false)->index();
		self::assertSame(Http::STATUS_FORBIDDEN, $off->getStatus(), 'module switched off: no page');
		$stranger = $this->pageController(false, false, true)->index();
		self::assertSame(Http::STATUS_FORBIDDEN, $stranger->getStatus());
		$both = $this->pageController(true, true, true)->index();
		self::assertSame('index', $both->getTemplateName(), 'an archive role gets the archive');
	}

	private function publicLinkController(?array $excerpt, ?File $scan = null): PublicLinkController {
		$shared = $this->createStub(SharedLinkService::class);
		$shared->method('publicExcerpt')->willReturn($excerpt);
		$shared->method('publicScan')->willReturn($scan);
		return new PublicLinkController($this->createStub(IRequest::class), $shared, $this->createStub(IInitialState::class), $this->l10n(),
			$this->createStub(LoggerInterface::class), $this->createStub(IAppManager::class));
	}

	/**
	 * Unknown, expired, revoked or withdrawn links, a malformed token and a
	 * switched-off module get the same 404 page, marked for the brute-force
	 * protection, without a reason.
	 */
	public function testPublicLinksAnswerTheSame404ForEveryFailure(): void {
		$excerpt = ['title' => 'Der Mond ist aufgegangen', 'composer' => 'J. A. P. Schulz', 'measureFrom' => null, 'measureTo' => null,
			'voice' => 'Alto', 'verse' => 1, 'tempo' => null, 'canPlay' => true, 'scan' => true, 'xml' => '<score-partwise/>'];
		// The page itself needs the running server (PublicTemplateResponse);
		// the integration test opens it. Its policy: as the app page, without frames.
		$policy = PublicLinkController::policy()->buildPolicy();
		self::assertStringContainsString("'wasm-unsafe-eval'", $policy);
		self::assertStringNotContainsString("'unsafe-eval'", $policy);
		self::assertStringNotContainsString('http', $policy, 'no external sources');
		self::assertStringContainsString('frame-src blob:;', $policy, 'frames only from the browser\'s memory: the scan');
		self::assertStringContainsString("worker-src 'self'", $policy);
		self::assertStringContainsString("media-src 'self' blob:", $policy);
		self::assertStringContainsString("microphone 'self';", PublicLinkController::recordingPolicy()->buildPolicy());
		self::assertStringContainsString("geolocation 'none';", PublicLinkController::recordingPolicy()->buildPolicy());

		$scan = $this->createStub(File::class);
		$failures = [
			'unknown or refused' => $this->publicLinkController(null)->show(str_repeat('a', 32)),
			'malformed' => $this->publicLinkController($excerpt)->show('../../etc'),
			'scan: unknown, refused or none' => $this->publicLinkController($excerpt)->scan(str_repeat('a', 32)),
			'scan: malformed' => $this->publicLinkController($excerpt, $scan)->scan('../../etc'),
		];
		$response = $this->publicLinkController($excerpt, $scan)->scan(str_repeat('a', 32));
		$headers = self::headers($response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('application/pdf', $headers['Content-Type'] ?? null);
		self::assertSame('inline; filename="scan.pdf"', $headers['Content-Disposition'] ?? null, 'shown by the PDF viewer of the browser');
		self::assertSame('nosniff', $headers['X-Content-Type-Options'] ?? null);
		self::assertSame('noindex, nofollow', $headers['X-Robots-Tag'] ?? null);
		$scanPolicy = $response->getContentSecurityPolicy()->buildPolicy();
		self::assertStringContainsString("default-src 'none'", $scanPolicy);
		self::assertStringContainsString("object-src 'self'", $scanPolicy);
		self::assertStringNotContainsString('http', $scanPolicy, 'no external sources');
		$controller = $this->publicLinkController($excerpt);
		$middleware = new ModuleMiddleware($this->modulesWithout([Modules::PUBLIC_LINKS]), $this->l10n());
		try {
			$middleware->beforeController($controller, 'show');
			self::fail('the module is off');
		} catch (ModuleDisabledException $e) {
			$failures['module off'] = $middleware->afterException($controller, 'show', $e);
		}
		foreach ($failures as $case => $response) {
			self::assertInstanceOf(NotFoundResponse::class, $response, $case);
			self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus(), $case);
			self::assertTrue($response->isThrottled(), $case . ' counts as a failed attempt');
			self::assertSame([], $response instanceof TemplateResponse ? $response->getParams() : null, $case . ': no reason given');
		}

		$repertoire = new PublicRepertoireController($this->createStub(IRequest::class), $this->createStub(PublicRepertoireService::class),
			$this->l10n(), $this->createStub(LoggerInterface::class));
		$middleware = new ModuleMiddleware($this->modulesWithout([Modules::PUBLIC_REPERTOIRE]), $this->l10n());
		try {
			$middleware->beforeController($repertoire, 'index');
			self::fail('the module is off');
		} catch (ModuleDisabledException $e) {
			self::assertInstanceOf(NotFoundResponse::class, $middleware->afterException($repertoire, 'index', $e));
		}
		$service = $this->createStub(PublicRepertoireService::class);
		$service->method('entries')->willThrowException(new \RuntimeException('database gone'));
		$broken = new PublicRepertoireController($this->createStub(IRequest::class), $service, $this->l10n(), $this->createStub(LoggerInterface::class));
		self::assertInstanceOf(NotFoundResponse::class, $broken->index(), 'an error tells nothing either');
	}

	public function testMemberRoutesRefuseOthersAndThrottleUnknownTokens(): void {
		$shared = $this->createStub(SharedLinkService::class);
		$shared->method('memberScore')->willReturnCallback(function (string $token, string $uid): string {
			if ($uid !== 'singer') {
				throw new AccessDeniedException('This page is only for choir members. Roles are linked to groups; please ask an administrator.');
			}
			if ($token === str_repeat('u', 32)) {
				throw new UnknownLinkException('This link does not exist.');
			}
			return '<score-partwise/>';
		});
		$controller = fn (?string $uid): MemberController => new MemberController($this->createStub(IRequest::class), $uid, $shared,
			$this->createStub(PracticeAudioService::class), $this->l10n(), $this->createStub(LoggerInterface::class));

		$response = $controller('singer')->score(str_repeat('a', 32));
		$headers = self::headers($response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('attachment; filename="excerpt.musicxml"', $headers['Content-Disposition'] ?? null);
		self::assertSame('nosniff', $headers['X-Content-Type-Options'] ?? null);
		self::assertStringContainsString("default-src 'none'", $response->getContentSecurityPolicy()->buildPolicy());

		self::assertSame(Http::STATUS_FORBIDDEN, $controller('librarian')->score(str_repeat('a', 32))->getStatus(), 'no choir member');
		$unknown = $controller('singer')->score(str_repeat('u', 32));
		self::assertSame(Http::STATUS_BAD_REQUEST, $unknown->getStatus());
		self::assertTrue($unknown->isThrottled());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller(null)->score(str_repeat('a', 32))->getStatus());
	}

	/**
	 * The service worker (milestone 0.15) is a script without data from the
	 * app's own path. With the modules offline and concert_folder off it is
	 * the worker that removes everything kept on the device; it never comes
	 * from elsewhere.
	 */
	public function testTheOfflineWorkerIsAScriptOfTheAppOrRemovesItself(): void {
		$apps = $this->createStub(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('0.15.0');
		$controller = fn (array $off): OfflineController => new OfflineController($this->createStub(IRequest::class),
			$this->modulesWithout($off), $apps, $this->l10n());

		$off = $controller([Modules::OFFLINE, Modules::CONCERT_FOLDER])->worker();
		self::assertSame(Http::STATUS_OK, $off->getStatus());
		self::assertSame('text/javascript; charset=utf-8', self::headers($off)['Content-Type'] ?? null);
		self::assertSame('nosniff', self::headers($off)['X-Content-Type-Options'] ?? null);
		self::assertSame(OfflineController::REMOVING_WORKER, $off->render());
		self::assertStringContainsString('unregister()', $off->render());
		self::assertStringContainsString("connect-src 'self'", $off->getContentSecurityPolicy()->buildPolicy());
		self::assertStringNotContainsString('http', $off->getContentSecurityPolicy()->buildPolicy(), 'no other server');

		$built = __DIR__ . '/../../../js/cantoria-offline-worker.js';
		foreach (['both on' => [], 'only the concert folder' => [Modules::OFFLINE], 'only the shared pages' => [Modules::CONCERT_FOLDER]] as $case => $without) {
			$on = $controller($without)->worker();
			if (is_file($built)) {
				self::assertSame(Http::STATUS_OK, $on->getStatus(), $case);
				self::assertStringContainsString('"0.15.0"', $on->render(), $case . ': the version names the cache of the scripts');
				self::assertStringNotContainsString('__CANTORIA_VERSION__', $on->render(), $case);
			} else {
				self::assertSame(Http::STATUS_NOT_FOUND, $on->getStatus(), $case . ': without a build there is no worker');
			}
		}
	}

	private function concertFolderController(?string $uid, ConcertFolderService $folder): ConcertFolderController {
		return new ConcertFolderController($this->createStub(IRequest::class), $uid, $folder, $this->createStub(IInitialState::class),
			$this->createStub(IAppManager::class), $this->l10n(), $this->createStub(LoggerInterface::class));
	}

	/**
	 * The concert folder (milestone 0.15) is a page of its own for the
	 * conductor and the catalog librarian, which the service worker keeps;
	 * everybody else gets the forbidden page. Its scores are never shown
	 * inline, its scans only by the PDF viewer.
	 */
	public function testTheConcertFolderIsForTheConductorAndTheLibrarianOnly(): void {
		$folder = $this->createStub(ConcertFolderService::class);
		$folder->method('mayOpen')->willReturnCallback(fn (string $uid): bool => $uid === 'conductor');
		$folder->method('concerts')->willReturn([]);
		$folder->method('score')->willReturnCallback(function (int $id, string $uid): string {
			if ($uid !== 'conductor') {
				throw new AccessDeniedException('The concert folder is for the conductor and the catalog librarian.');
			}
			return '<score-partwise/>';
		});
		$folder->method('scan')->willThrowException(new NotFoundException('This programme item has no scan.'));

		$page = $this->concertFolderController('conductor', $folder)->page();
		self::assertSame('concert-folder', $page->getTemplateName());
		self::assertSame('concert-folder', self::headers($page)['X-Cantoria-Page'] ?? null, 'the page the worker keeps');
		$policy = $page->getContentSecurityPolicy()->buildPolicy();
		self::assertStringContainsString("'wasm-unsafe-eval'", $policy);
		self::assertStringNotContainsString("'unsafe-eval'", $policy);
		self::assertStringContainsString("worker-src 'self'", $policy);
		self::assertStringContainsString('frame-src blob:;', $policy, 'the scan from the browser\'s memory only');
		$singer = $this->concertFolderController('singer', $folder)->page();
		self::assertSame(Http::STATUS_FORBIDDEN, $singer->getStatus());
		self::assertNull(self::headers($singer)['X-Cantoria-Page'] ?? null, 'the worker removes the kept folder for another account');

		$score = $this->concertFolderController('conductor', $folder)->score(7);
		self::assertSame(Http::STATUS_OK, $score->getStatus());
		self::assertSame('attachment; filename="score.musicxml"', self::headers($score)['Content-Disposition'] ?? null);
		self::assertSame('nosniff', self::headers($score)['X-Content-Type-Options'] ?? null);
		self::assertSame(Http::STATUS_FORBIDDEN, $this->concertFolderController('singer', $folder)->score(7)->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->concertFolderController(null, $folder)->score(7)->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->concertFolderController('conductor', $folder)->scan(7)->getStatus());
		self::assertSame([Modules::CONCERT_FOLDER], ModuleMiddleware::required(ConcertFolderController::class, 'page'));
	}

	public function testTheMemberLinksForTheDeviceAreThoseOfThePage(): void {
		$shared = $this->createStub(SharedLinkService::class);
		$shared->method('forMember')->willReturnCallback(function (string $uid): array {
			if ($uid !== 'singer') {
				throw new AccessDeniedException('This page is only for choir members. Roles are linked to groups; please ask an administrator.');
			}
			return [['token' => str_repeat('a', 32), 'reason' => 'Playback is switched off.', 'offline' => false]];
		});
		$controller = fn (?string $uid): MemberController => new MemberController($this->createStub(IRequest::class), $uid, $shared,
			$this->createStub(PracticeAudioService::class), $this->l10n(), $this->createStub(LoggerInterface::class));
		$response = $controller('singer')->links();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([['reason' => 'Playback is switched off.', 'token' => str_repeat('a', 32), 'offline' => false]], $response->getData()['links']);
		self::assertSame(Http::STATUS_FORBIDDEN, $controller('librarian')->links()->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller(null)->links()->getStatus());
	}

	public function testTheScanOfAMemberLinkIsShownInlineAfterTheSameChecks(): void {
		$shared = $this->createStub(SharedLinkService::class);
		$shared->method('memberScan')->willReturnCallback(function (string $token, string $uid): File {
			if ($uid !== 'singer') {
				throw new AccessDeniedException('This page is only for choir members. Roles are linked to groups; please ask an administrator.');
			}
			if ($token === str_repeat('u', 32)) {
				throw new UnknownLinkException('This link does not exist.');
			}
			if ($token === str_repeat('n', 32)) {
				throw new NotFoundException('The scan of this link is not available at the moment.');
			}
			return $this->createStub(File::class);
		});
		$controller = fn (?string $uid): MemberController => new MemberController($this->createStub(IRequest::class), $uid, $shared,
			$this->createStub(PracticeAudioService::class), $this->l10n(), $this->createStub(LoggerInterface::class));

		$response = $controller('singer')->scan(str_repeat('a', 32));
		$headers = self::headers($response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('application/pdf', $headers['Content-Type'] ?? null);
		self::assertSame('inline; filename="scan.pdf"', $headers['Content-Disposition'] ?? null);
		self::assertSame('nosniff', $headers['X-Content-Type-Options'] ?? null);
		self::assertStringContainsString("object-src 'self'", $response->getContentSecurityPolicy()->buildPolicy());

		self::assertSame(Http::STATUS_FORBIDDEN, $controller('librarian')->scan(str_repeat('a', 32))->getStatus(), 'no choir member');
		self::assertSame(Http::STATUS_NOT_FOUND, $controller('singer')->scan(str_repeat('n', 32))->getStatus(), 'no scan');
		$unknown = $controller('singer')->scan(str_repeat('u', 32));
		self::assertSame(Http::STATUS_BAD_REQUEST, $unknown->getStatus());
		self::assertTrue($unknown->isThrottled());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller(null)->scan(str_repeat('a', 32))->getStatus());
	}

	#[CoversNothing]
	public function testOnlyTheSettingsLackNoAdminRequired(): void {
		$routes = self::routes();
		$withoutNoAdmin = array_keys(array_filter($routes, fn (array $r): bool => !in_array(NoAdminRequired::class, $r['attributes'], true)));
		sort($withoutNoAdmin);
		$expected = self::SETTINGS;
		sort($expected);
		self::assertSame($expected, $withoutNoAdmin);
	}

	#[CoversNothing]
	public function testTheSettingsCanBeDelegatedAndNothingElse(): void {
		foreach (self::routes() as $name => $route) {
			if (in_array($name, self::SETTINGS, true)) {
				// Admins and the holders of the settings page, nobody else
				self::assertSame([Admin::class], $route['settings'], $name);
				self::assertNotContains(NoAdminRequired::class, $route['attributes'], $name);
				self::assertNotContains(SubAdminRequired::class, $route['attributes'], $name);
				continue;
			}
			self::assertSame([], $route['settings'], $name . ' is no setting');
		}
		// Restoring a backup stays with admins and the catalog role: giving the
		// settings page to a group does not include it.
		self::assertSame([], self::routes()['WorkflowController::restore']['settings']);
	}

	public function testTheSettingsRefuseWhomTheyWereNotGiven(): void {
		$roles = $this->createStub(Roles::class);
		$roles->method('requireSettingsAccess')->willThrowException(new AccessDeniedException('Only administrators may change this.'));
		$controller = new SettingsController($this->createStub(IRequest::class), 'carla', $roles, $this->createStub(AppSettings::class),
			$this->createStub(ArchiveAccess::class), $this->modulesWithout(), $this->createStub(RepertoireService::class),
			$this->createStub(FullBackupService::class), $this->createStub(AuditLog::class), $this->createStub(IGroupManager::class), $this->l10n(),
			$this->createStub(LoggerInterface::class), $this->createStub(ISubAdmin::class), $this->createStub(IUserManager::class));
		$responses = [$controller->index(), $controller->update(['librarian' => ['everyone']]), $controller->groups('a'), $controller->access()];
		foreach ($responses as $response) {
			self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			self::assertSame(['error' => 'Only administrators may change this.'], $response->getData());
		}
	}

	public function testTheSettingsTellWhetherARestoreIsPossibleOnlyToAdmins(): void {
		$roles = $this->createStub(Roles::class);
		$roles->method('groups')->willReturnCallback(fn (string $role): array => $role === Roles::READER ? ['board', 'deleted'] : []);
		$roles->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $uid === 'root');
		$backup = $this->createStub(FullBackupService::class);
		$backup->method('counts')->willReturn(['cantoria_copies' => 0, 'cantoria_files' => 0, 'cantoria_audit' => 0]);
		$board = $this->createStub(IGroup::class);
		$board->method('getGID')->willReturn('board');
		$board->method('getDisplayName')->willReturn('Board');
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('get')->willReturnCallback(fn (string $id): ?IGroup => $id === 'board' ? $board : null);
		$groups->method('search')->willReturn([$board]);
		$controller = fn (string $uid): SettingsController => new SettingsController($this->createStub(IRequest::class), $uid, $roles,
			$this->createStub(AppSettings::class), $this->createStub(ArchiveAccess::class), $this->modulesWithout(['repertoire']),
			$this->createStub(RepertoireService::class), $backup, $this->createStub(AuditLog::class), $groups, $this->l10n(),
			$this->createStub(LoggerInterface::class), $this->createStub(ISubAdmin::class), $this->createStub(IUserManager::class));

		$data = $controller('root')->index()->getData();
		self::assertIsArray($data);
		self::assertTrue($data['admin']);
		self::assertTrue($data['catalogEmpty']);
		self::assertNull($data['repertoire'], 'the thresholds belong to the module "repertoire"');
		self::assertSame(Roles::LABELS, $data['roleLabels']);
		self::assertSame(['board', 'deleted'], $data['roles'][Roles::READER] ?? null);
		self::assertEquals((object)['board' => 'Board', 'deleted' => 'deleted'], $data['groupNames']);
		// For the setup steps of the page
		self::assertSame(['deleted'], $data['groupsMissing']);
		self::assertSame(['copies' => 0, 'files' => 0], $data['catalog']);

		$delegated = $controller('anna')->index()->getData();
		self::assertIsArray($delegated);
		self::assertFalse($delegated['admin']);
		self::assertNull($delegated['catalogEmpty']);

		// Groups to choose, also for the groups given the settings page
		self::assertSame([['id' => 'board', 'displayName' => 'Board']], $controller('anna')->groups('bo')->getData());
	}

	public function testDelegatedSettingsCannotGrantRolesOrMoveTheArchiveFolder(): void {
		$stored = [Roles::LIBRARIAN => ['archive'], Roles::READER => []];
		$roles = $this->createStub(Roles::class);
		$roles->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $uid === 'root');
		$roles->method('groups')->willReturnCallback(function (string $role) use (&$stored): array {
			return $stored[$role] ?? [];
		});
		$roles->method('setGroups')->willReturnCallback(function (string $role, array $groups) use (&$stored): void {
			$stored[$role] = $groups;
		});
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => $uid === 'anna' && $group === 'settings-team');
		$groups->method('get')->willReturnCallback(function (string $id): IGroup {
			$group = $this->createStub(IGroup::class);
			$group->method('getGID')->willReturn($id);
			return $group;
		});
		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid): IUser {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($uid);
			return $user;
		});
		// Anna is group admin of "board-helpers": she could add herself to it.
		$subAdmin = $this->createStub(ISubAdmin::class);
		$subAdmin->method('isSubAdminOfGroup')->willReturnCallback(fn (IUser $user, IGroup $group): bool => $user->getUID() === 'anna'
			&& $group->getGID() === 'board-helpers');
		$settings = $this->createStub(AppSettings::class);
		$settings->method('archiveInfo')->willReturn(['id' => 7, 'path' => 'Archiv', 'user' => 'root']);
		$recorded = [];
		$audit = $this->createStub(AuditLog::class);
		$audit->method('record')->willReturnCallback(function (string $operation, ?string $uid, string $type, ?int $id, string $action,
			?string $field = null, mixed $old = null, mixed $new = null) use (&$recorded): void {
			$recorded[] = [$uid, (string)$field, $old, $new];
		});
		$controller = fn (string $uid): SettingsController => new SettingsController($this->createStub(IRequest::class), $uid, $roles,
			$settings, $this->createStub(ArchiveAccess::class), $this->modulesWithout(), $this->createStub(RepertoireService::class),
			$this->createStub(FullBackupService::class), $audit, $groups, $this->l10n(), $this->createStub(LoggerInterface::class),
			$subAdmin, $users);

		// A group given the settings page cannot give a role to itself ...
		$response = $controller('anna')->update([Roles::LIBRARIAN => ['archive', 'settings-team']]);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['archive'], $stored[Roles::LIBRARIAN]);
		// ... nor to a group whose members it manages as group admin ...
		self::assertSame(Http::STATUS_FORBIDDEN, $controller('anna')->update([Roles::LIBRARIAN => ['archive', 'board-helpers']])->getStatus());
		self::assertSame(['archive'], $stored[Roles::LIBRARIAN]);
		// ... nor move the archive folder, but it can save it unchanged
		self::assertSame(Http::STATUS_FORBIDDEN, $controller('anna')->update(null, 8)->getStatus());
		self::assertSame(Http::STATUS_OK, $controller('anna')->update(null, 7)->getStatus());
		self::assertSame([], $recorded);
		// It arranges the roles of other groups, and every change is logged
		self::assertSame(Http::STATUS_OK, $controller('anna')->update([Roles::READER => ['board']])->getStatus());
		self::assertSame(['board'], $stored[Roles::READER]);
		self::assertSame([['anna', 'groups_reader', '', 'board']], $recorded);
		// An administrator may give the role to any group
		self::assertSame(Http::STATUS_OK, $controller('root')->update([Roles::LIBRARIAN => ['archive', 'settings-team']])->getStatus());
		self::assertSame(['archive', 'settings-team'], $stored[Roles::LIBRARIAN]);
	}

	#[CoversNothing]
	public function testCsrfIsOnlySkippedForPlainDownloads(): void {
		$withoutCsrf = array_filter(self::routes(), fn (array $r): bool => in_array(NoCSRFRequired::class, $r['attributes'], true));
		foreach ($withoutCsrf as $name => $route) {
			self::assertSame('GET', $route['verb'], $name . ' changes state and must keep the CSRF check');
		}
		$names = array_keys($withoutCsrf);
		sort($names);
		$expected = self::WITHOUT_CSRF;
		sort($expected);
		self::assertSame($expected, $names);
	}

	#[CoversNothing]
	public function testExpensiveEndpointsAreRateLimited(): void {
		$routes = self::routes();
		foreach (self::RATE_LIMITED as $name) {
			self::assertArrayHasKey($name, $routes);
			self::assertContains(UserRateLimit::class, $routes[$name]['attributes'], $name);
		}
	}

	public function testEveryRouteIsCoreOrBelongsToAModule(): void {
		$routes = self::routes();
		foreach (self::CORE as $name) {
			self::assertArrayHasKey($name, $routes, 'core route exists');
		}
		foreach (array_keys($routes) as $name) {
			[$class, $method] = explode('::', $name);
			$modules = ModuleMiddleware::required('OCA\\Cantoria\\Controller\\' . $class, $method);
			if (in_array($name, self::CORE, true)) {
				self::assertSame([], $modules, $name . ' belongs to the core');
				continue;
			}
			self::assertNotSame([], $modules, $name . ' is neither core nor part of a module');
			foreach ($modules as $module) {
				self::assertTrue(Modules::exists($module), $name . ': unknown module ' . $module);
				self::assertFalse(Modules::isPlanned($module), $name . ': planned module ' . $module);
			}
		}
	}

	public function testRoutesOfASwitchedOffModuleAnswer404(): void {
		$l = $this->l10n();
		$controller = new PracticeAudioController($this->createStub(IRequest::class), 'carla', $this->createStub(PracticeAudioService::class),
			$l, $this->createStub(LoggerInterface::class));

		$middleware = new ModuleMiddleware($this->modulesWithout(), $l);
		$middleware->beforeController($controller, 'authorize');

		$middleware = new ModuleMiddleware($this->modulesWithout(['playback']), $l);
		try {
			$middleware->beforeController($controller, 'authorize');
			self::fail('practice audio needs playback');
		} catch (ModuleDisabledException $e) {
			$response = $middleware->afterException($controller, 'authorize', $e);
			self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			self::assertInstanceOf(JSONResponse::class, $response);
			self::assertSame(['error' => 'This function is switched off.'], $response->getData());
		}

		$other = new \RuntimeException('not mine');
		$this->expectExceptionObject($other);
		$middleware->afterException($controller, 'authorize', $other);
	}

	public function testCoreRoutesStayOnWithEveryModuleOff(): void {
		$middleware = new ModuleMiddleware($this->modules(array_fill_keys(Modules::switchable(), false)), $this->l10n());
		$controller = new TaskController($this->createStub(IRequest::class), 'carla', $this->createStub(Roles::class),
			$this->createStub(TaskService::class), $this->createStub(LinkService::class), $this->createStub(DueService::class), $this->l10n(),
			$this->createStub(LoggerInterface::class));
		$middleware->beforeController($controller, 'due');
		$this->expectException(ModuleDisabledException::class);
		$middleware->beforeController($controller, 'tasks');
	}

	public function testASwitchedOffPartOfAnActionAnswers404(): void {
		$practiceAudio = $this->createStub(PracticeAudioService::class);
		$practiceAudio->method('authorize')->willThrowException(new ModuleDisabledException('practice_audio'));
		$controller = new PracticeAudioController($this->createStub(IRequest::class), 'carla', $practiceAudio, $this->l10n(),
			$this->createStub(LoggerInterface::class));
		$response = $controller->authorize(3);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'This function is switched off.'], $response->getData());
	}

	/**
	 * Milestone 0.8: the annual snapshots are their own module, the report
	 * of gaps in the shelf marks is part of the inventory. Switched off, the
	 * routes answer 404 before the controller runs.
	 */
	public function testTheArchiveExtrasArePartOfTheirModules(): void {
		foreach (['overview', 'create', 'compare', 'settings'] as $method) {
			self::assertSame([Modules::ANNUAL_SNAPSHOT], ModuleMiddleware::required(AnnualSnapshotController::class, $method), $method);
		}
		self::assertSame(['inventory'], ModuleMiddleware::required(MaintenanceController::class, 'shelfmarkGaps'));
		$routes = self::routes();
		self::assertSame('POST', $routes['AnnualSnapshotController::create']['verb'] ?? null, 'writing keeps the CSRF check');
		self::assertSame('PUT', $routes['AnnualSnapshotController::settings']['verb'] ?? null);

		$controller = $this->snapshotController([]);
		(new ModuleMiddleware($this->modulesWithout(), $this->l10n()))->beforeController($controller, 'compare');
		$this->expectException(ModuleDisabledException::class);
		(new ModuleMiddleware($this->modulesWithout([Modules::ANNUAL_SNAPSHOT]), $this->l10n()))->beforeController($controller, 'compare');
	}

	/**
	 * Making a snapshot and its setting are for the catalog librarian;
	 * reading them needs access to the archive.
	 */
	public function testAnnualSnapshotsCheckTheRolesOnTheServer(): void {
		$reader = $this->snapshotController([Roles::READER]);
		self::assertSame(Http::STATUS_FORBIDDEN, $reader->create()->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $reader->settings(false)->getStatus());
		$nobody = $this->snapshotController([]);
		self::assertSame(Http::STATUS_FORBIDDEN, $nobody->overview()->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $nobody->compare('2025_20260101-000000', '2026_20261022-120000')->getStatus());
	}

	/**
	 * @param list<string> $roles roles of the account
	 */
	private function snapshotController(array $roles): AnnualSnapshotController {
		$roleService = $this->createStub(Roles::class);
		$roleService->method('requireAccess')->willReturnCallback(function () use ($roles): void {
			if ($roles === []) {
				throw new AccessDeniedException('You have no access to the music archive.');
			}
		});
		$roleService->method('requireRole')->willReturnCallback(function (string $uid, array $allowed) use ($roles): void {
			if (array_intersect($roles, $allowed) === []) {
				throw new AccessDeniedException('This requires one of these roles: %s', ['x']);
			}
		});
		$service = new AnnualSnapshotService($this->createStub(AppSettings::class), $this->createStub(ExportService::class),
			$this->createStub(FullBackupService::class), $this->createStub(ArchiveFileMapper::class), $this->createStub(RightsService::class),
			$roleService, $this->createStub(AuditLog::class), $this->createStub(IAppConfig::class), $this->createStub(ITimeFactory::class),
			$this->createStub(IDateTimeZone::class), $this->runLock());
		return new AnnualSnapshotController($this->createStub(IRequest::class), 'carla', $service, $this->l10n(),
			$this->createStub(LoggerInterface::class));
	}

	public function testTheFilesActionRouteIsPartOfItsModule(): void {
		$route = self::routes()['FilesIntegrationController::copyOfFile'] ?? null;
		self::assertNotNull($route);
		self::assertSame('GET', $route['verb']);
		self::assertContains(NoAdminRequired::class, $route['attributes'], 'any account with access, checked by the method');
		self::assertNotContains(NoCSRFRequired::class, $route['attributes'], 'called by a script with the request token');
		self::assertContains(UserRateLimit::class, $route['attributes']);
		self::assertSame([Modules::FILES_INTEGRATION], ModuleMiddleware::required(FilesIntegrationController::class, 'copyOfFile'));

		$controller = $this->filesController('carla', true, null);
		(new ModuleMiddleware($this->modulesWithout(), $this->l10n()))->beforeController($controller, 'copyOfFile');
		$middleware = new ModuleMiddleware($this->modulesWithout([Modules::FILES_INTEGRATION]), $this->l10n());
		$this->expectException(ModuleDisabledException::class);
		$middleware->beforeController($controller, 'copyOfFile');
	}

	/**
	 * @param array{copyId: int, shelfmark: string, role: string, score: bool}|null $copy answer of the service, null: not listed
	 */
	private function filesController(?string $uid, bool $access, ?array $copy): FilesIntegrationController {
		$roles = $this->createStub(Roles::class);
		$roles->method('requireAccess')->willReturnCallback(function () use ($access): void {
			if (!$access) {
				throw new AccessDeniedException('You have no access to the music archive.');
			}
		});
		$service = $this->createStub(FilesIntegrationService::class);
		if ($copy === null) {
			$service->method('copyOf')->willThrowException(new NotFoundException('This file is not listed in the music archive.'));
		} else {
			$service->method('copyOf')->willReturn($copy);
		}
		return new FilesIntegrationController($this->createStub(IRequest::class), $uid, $roles, $service, $this->l10n(),
			$this->createStub(LoggerInterface::class));
	}

	public function testTheFilesActionAnswersWithTheCopyOr404(): void {
		$copy = ['copyId' => 35, 'shelfmark' => '2-1', 'role' => 'musicxml', 'score' => true];
		$response = $this->filesController('carla', true, $copy)->copyOfFile(4711);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($copy, $response->getData());

		$response = $this->filesController('carla', true, null)->copyOfFile(4711);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'This file is not listed in the music archive.'], $response->getData());

		$response = $this->filesController('stranger', false, $copy)->copyOfFile(4711);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), 'no access: refused before any file is looked at');
		self::assertSame(['error' => 'You have no access to the music archive.'], $response->getData());

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->filesController(null, true, $copy)->copyOfFile(4711)->getStatus());
	}

	#[CoversNothing]
	public function testResolvingALinkHasBruteForceProtection(): void {
		self::assertContains(BruteForceProtection::class, self::routes()['TaskController::resolveLink']['attributes']);
	}

	private function taskController(LinkService $links): TaskController {
		return new TaskController($this->createStub(IRequest::class), 'conductor', $this->createStub(Roles::class),
			$this->createStub(TaskService::class), $links, $this->createStub(DueService::class), $this->l10n(),
			$this->createStub(LoggerInterface::class));
	}

	private function l10n(): IL10N {
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return $l;
	}

	public function testAnUnknownTokenIsThrottled(): void {
		$links = $this->createStub(LinkService::class);
		$links->method('resolve')->willThrowException(new UnknownLinkException('This link does not exist.'));
		$response = $this->taskController($links)->resolveLink(str_repeat('a', 32));
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'This link does not exist.'], $response->getData());
		self::assertTrue($response->isThrottled());
	}

	public function testAnExpiredLinkIsNotThrottled(): void {
		$links = $this->createStub(LinkService::class);
		$links->method('resolve')->willThrowException(new InvalidInputException('This link has expired.'));
		$response = $this->taskController($links)->resolveLink(str_repeat('a', 32));
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertFalse($response->isThrottled());
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function headers(Response $response): array {
		// Response::getHeaders() needs a running server; the headers set by the
		// controller are enough here.
		$headers = (new \ReflectionProperty(Response::class, 'headers'))->getValue($response);
		self::assertIsArray($headers);
		return $headers;
	}

	private function apiController(ScoreService $scores): ApiController {
		return new ApiController($this->createStub(IRequest::class), 'reader', $this->createStub(Roles::class), $this->createStub(MetaService::class),
			$this->createStub(CatalogService::class), $this->createStub(ImportService::class), $this->createStub(ExportService::class),
			$this->createStub(FileSyncService::class), $this->createStub(AuditEntryMapper::class),
			$this->createStub(UserFiles::class), $this->createStub(ITimeFactory::class), $this->createStub(IDateTimeZone::class), $scores,
			$this->l10n(), $this->createStub(LoggerInterface::class));
	}

	public function testAScoreInAFolderThatIsNotSharedSaysSo(): void {
		$scores = $this->createStub(ScoreService::class);
		$scores->method('content')->willThrowException(new NotSharedException('The file lies in a folder that is not shared with your account.'));
		$response = $this->apiController($scores)->fileContent(7);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertInstanceOf(JSONResponse::class, $response);
		// The UI adds the way to the access check for those who may change the settings
		self::assertSame(['error' => 'The file lies in a folder that is not shared with your account.', 'reason' => 'not_shared'], $response->getData());

		$other = $this->createStub(ScoreService::class);
		$other->method('content')->willThrowException(new AccessDeniedException('Internal preservation copy.'));
		$response = $this->apiController($other)->fileContent(7);
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(['error' => 'Internal preservation copy.'], $response->getData());
	}

	public function testScoreFilesAreNeverShownInline(): void {
		$scores = $this->createStub(ScoreService::class);
		$scores->method('content')->willReturn(['content' => '<score-partwise/>', 'mime' => 'application/vnd.recordare.musicxml+xml',
			'name' => '5-01_<script>.musicxml', 'type' => 'musicxml']);

		$response = $this->apiController($scores)->fileContent(7);
		$headers = self::headers($response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertIsString($headers['Content-Disposition'] ?? null);
		self::assertSame('attachment; filename="score.musicxml"', $headers['Content-Disposition']);
		self::assertSame('nosniff', $headers['X-Content-Type-Options'] ?? null);
		self::assertSame('application/vnd.recordare.musicxml+xml', $headers['Content-Type'] ?? null);
		self::assertStringContainsString("default-src 'none'", $response->getContentSecurityPolicy()->buildPolicy());
		self::assertSame('<score-partwise/>', $response->render());
	}

	private function reviewController(ScanService $scans): ReviewController {
		return new ReviewController($this->createStub(IRequest::class), 'reader', $this->createStub(ReviewService::class),
			$this->createStub(CorrectionService::class), $scans, $this->l10n(), $this->createStub(LoggerInterface::class));
	}

	public function testScansAreOnlyShownByThePdfViewer(): void {
		$scans = $this->createStub(ScanService::class);
		$scans->method('scan')->willReturn($this->createStub(File::class));
		$response = $this->reviewController($scans)->scan(9);
		$headers = self::headers($response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('application/pdf', $headers['Content-Type'] ?? null);
		self::assertSame('inline; filename="scan.pdf"', $headers['Content-Disposition'] ?? null);
		self::assertSame('nosniff', $headers['X-Content-Type-Options'] ?? null);
		$policy = $response->getContentSecurityPolicy()->buildPolicy();
		self::assertStringContainsString("default-src 'none'", $policy);
		self::assertStringContainsString("object-src 'self'", $policy, 'the browser\'s own PDF viewer');
		self::assertStringContainsString("frame-ancestors 'self'", $policy, 'framed by the app only');
		self::assertStringNotContainsString('http', $policy, 'no external sources');
	}

	public function testAScanIsRefusedWithoutTheRightToView(): void {
		$scans = $this->createStub(ScanService::class);
		$scans->method('scan')->willThrowException(new AccessDeniedException('Internal preservation copy: only the catalog librarian and the conductor may view it.'));
		$response = $this->reviewController($scans)->scan(9);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertInstanceOf(JSONResponse::class, $response);
	}

	public function testCalendarFilesAreRefusedWithoutAccess(): void {
		$calendar = $this->createStub(ConcertCalendarService::class);
		$calendar->method('upcomingFile')->willThrowException(new AccessDeniedException('You have no access to the music archive.'));
		$controller = new CalendarController($this->createStub(IRequest::class), 'stranger', $calendar, $this->l10n(),
			$this->createStub(LoggerInterface::class));
		$response = $controller->upcomingFile();
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(['error' => 'You have no access to the music archive.'], $response->getData());

		$loggedOut = new CalendarController($this->createStub(IRequest::class), null, $calendar, $this->l10n(),
			$this->createStub(LoggerInterface::class));
		self::assertSame(Http::STATUS_UNAUTHORIZED, $loggedOut->upcomingFile()->getStatus());
	}

	public function testTheAppPageAllowsWebAssemblyButNoScriptEval(): void {
		$response = $this->pageController(true, false, true)->index();
		self::assertInstanceOf(TemplateResponse::class, $response);
		$policy = $response->getContentSecurityPolicy()->buildPolicy();
		self::assertStringContainsString("'wasm-unsafe-eval'", $policy);
		self::assertStringNotContainsString("'unsafe-eval'", $policy);
		self::assertStringNotContainsString('http', $policy, 'no external sources');
		self::assertStringContainsString("frame-src 'self'", $policy, 'the scan beside the score, from the app itself');
	}

	/**
	 * Real role checks: each test account holds the role it is named after
	 * ("admin" is an administrator without a role, "member" a role this
	 * version does not know, e.g. choir members).
	 */
	private function rolesByName(): Roles {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueArray')->willReturnCallback(fn (string $app, string $key): array => [substr($key, strlen('groups_'))]);
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => $uid === $group);
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $uid === 'admin');
		return new Roles($config, $groups, $this->createStub(IUserManager::class), $this->createStub(ISettingsManager::class), $this->createStub(LoggerInterface::class));
	}

	private function folderBookController(string $uid): FolderBookController {
		$roles = $this->rolesByName();
		$service = new FolderBookService($this->createStub(IDBConnection::class), $this->createStub(FolderMapper::class),
			$this->createStub(FolderLoanMapper::class), $this->createStub(CopyMapper::class), $this->createStub(WorkVersionMapper::class),
			$this->createStub(WorkMapper::class), $this->createStub(AuditLog::class), $this->createStub(AuditEntryMapper::class), $roles,
			$this->createStub(RightsService::class), $this->createStub(IUserManager::class), $this->createStub(IAppConfig::class),
			$this->createStub(ITimeFactory::class));
		return new FolderBookController($this->createStub(IRequest::class), $uid, $roles, $service, $this->l10n(),
			$this->createStub(LoggerInterface::class));
	}

	public function testTheFolderBookIsReadByTheArchiveRolesAndMaintainedByTheLibrarians(): void {
		foreach (array_keys(self::routes()) as $name) {
			if (str_starts_with($name, 'FolderBookController::')) {
				self::assertSame([Modules::FOLDER_BOOK], ModuleMiddleware::required(FolderBookController::class, explode('::', $name)[1]), $name);
			}
		}
		foreach (['librarian', 'operations', 'conductor', 'reader'] as $uid) {
			self::assertSame(Http::STATUS_OK, $this->folderBookController($uid)->overview()->getStatus(), $uid . ' reads the folder book');
		}
		foreach (['contributor', 'member', 'admin'] as $uid) {
			self::assertSame(Http::STATUS_FORBIDDEN, $this->folderBookController($uid)->overview()->getStatus(), $uid . ' does not see the folder book');
		}
		foreach (['conductor', 'reader', 'contributor', 'member', 'admin'] as $uid) {
			$controller = $this->folderBookController($uid);
			self::assertSame(Http::STATUS_FORBIDDEN, $controller->createFolder(['label' => '12'])->getStatus(), $uid . ' creates no folder');
			self::assertSame(Http::STATUS_FORBIDDEN, $controller->issue(1, ['holderName' => 'A person'])->getStatus(), $uid . ' issues no folder');
			self::assertSame(Http::STATUS_FORBIDDEN, $controller->takeBack(1, ['wear' => 'good'])->getStatus(), $uid . ' takes nothing back');
			self::assertSame(Http::STATUS_FORBIDDEN, $controller->updateFolder(1, ['note' => 'x'])->getStatus(), $uid . ' changes no folder');
		}
		foreach (['librarian', 'operations'] as $uid) {
			$response = $this->folderBookController($uid)->createFolder([]);
			self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $uid . ' passes the role check');
			self::assertSame(['error' => 'Please give the folder a number or name.'], $response->getData());
		}
		$response = $this->folderBookController('librarian')->createFolder(['label' => '12', 'owner' => 'x']);
		self::assertSame(['error' => 'Unknown field: owner'], $response->getData(), 'unknown fields are refused');
	}

	/** A lock that is always free */
	private function runLock(): RunLock {
		$lock = $this->createStub(RunLock::class);
		$lock->method('run')->willReturnCallback(fn (string $name, ?string $uid, callable $run): mixed => $run());
		return $lock;
	}

	private function printMirrorController(string $uid): PrintMirrorController {
		$roles = $this->rolesByName();
		$service = new PrintMirrorService($this->createStub(IAppConfig::class), $this->createStub(IRootFolder::class),
			$this->createStub(AppSettings::class), $this->createStub(ReleaseListService::class), $this->createStub(ArchiveFileMapper::class),
			$this->createStub(CopyMapper::class), $this->createStub(MirrorFileMapper::class), $this->createStub(AuditLog::class), $roles,
			$this->modulesWithout(), $this->createStub(NotificationService::class), $this->createStub(IFilenameValidator::class),
			$this->createStub(IUserManager::class), $this->createStub(IFactory::class), $this->createStub(ITimeFactory::class),
			$this->createStub(IDateTimeZone::class), $this->createStub(LoggerInterface::class), $this->runLock());
		return new PrintMirrorController($this->createStub(IRequest::class), $uid, $roles, $service, $this->l10n(),
			$this->createStub(LoggerInterface::class));
	}

	public function testThePrintStationIsSyncedByTheCatalogLibrarianAndAdministrators(): void {
		foreach (array_keys(self::routes()) as $name) {
			if (str_starts_with($name, 'PrintMirrorController::')) {
				self::assertSame([Modules::PRINT_MIRROR], ModuleMiddleware::required(PrintMirrorController::class, explode('::', $name)[1]), $name);
			}
		}
		self::assertSame('POST', self::routes()['PrintMirrorController::sync']['verb']);
		self::assertNotContains(NoCSRFRequired::class, self::routes()['PrintMirrorController::sync']['attributes']);
		foreach (['operations', 'conductor', 'reader', 'contributor', 'member'] as $uid) {
			$controller = $this->printMirrorController($uid);
			self::assertSame(Http::STATUS_FORBIDDEN, $controller->sync()->getStatus(), $uid . ' does not sync');
			self::assertSame(Http::STATUS_FORBIDDEN, $controller->preview()->getStatus(), $uid . ' sees no preview');
			self::assertSame(Http::STATUS_FORBIDDEN, $controller->status()->getStatus(), $uid . ' sees no status');
			self::assertSame(Http::STATUS_FORBIDDEN, $controller->updateSettings(5)->getStatus(), $uid . ' chooses no folder');
		}
		foreach (['librarian', 'admin'] as $uid) {
			$response = $this->printMirrorController($uid)->sync();
			self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $uid . ' passes the role check');
			self::assertSame(['error' => 'No target folder has been chosen for the print station yet. An administrator chooses it in the settings of Cantoria.'],
				$response->getData());
		}
		self::assertSame(Http::STATUS_FORBIDDEN, $this->printMirrorController('librarian')->check(5)->getStatus(), 'only administrators choose the folder');
	}

	private function handoverController(string $uid): HandoverController {
		$roles = $this->rolesByName();
		$service = new HandoverService($this->createStub(IAppConfig::class), $this->createStub(IRootFolder::class),
			$this->createStub(AppSettings::class), $this->createStub(ReleaseListService::class), $this->createStub(ArchiveFileMapper::class),
			$this->createStub(CopyMapper::class), $this->createStub(MirrorFileMapper::class), $this->createStub(AuditLog::class), $roles,
			$this->modulesWithout(), $this->createStub(NotificationService::class), $this->createStub(IFilenameValidator::class),
			$this->createStub(IUserManager::class), $this->createStub(IFactory::class), $this->createStub(ITimeFactory::class),
			$this->createStub(IDateTimeZone::class), $this->createStub(LoggerInterface::class), $this->runLock(),
			$this->createStub(WorkVersionMapper::class), $this->createStub(RightsPolicy::class), $this->createStub(RightsService::class),
			$this->createStub(LicenseMapper::class), $this->createStub(ExcerptLinkMapper::class), $this->createStub(StateService::class),
			$this->createStub(\OCA\Cantoria\Service\ScoreService::class));
		return new HandoverController($this->createStub(IRequest::class), $uid, $roles, $service, $this->l10n(),
			$this->createStub(LoggerInterface::class));
	}

	public function testTheHandOverIsSyncedByTheCatalogLibrarianAndAdministrators(): void {
		foreach (array_keys(self::routes()) as $name) {
			if (str_starts_with($name, 'HandoverController::')) {
				self::assertSame([Modules::CHOIR_HANDOVER], ModuleMiddleware::required(HandoverController::class, explode('::', $name)[1]), $name);
			}
		}
		self::assertSame('POST', self::routes()['HandoverController::sync']['verb']);
		self::assertNotContains(NoCSRFRequired::class, self::routes()['HandoverController::sync']['attributes']);
		foreach (['operations', 'conductor', 'reader', 'contributor', 'member'] as $uid) {
			$controller = $this->handoverController($uid);
			self::assertSame(Http::STATUS_FORBIDDEN, $controller->sync()->getStatus(), $uid . ' does not sync');
			self::assertSame(Http::STATUS_FORBIDDEN, $controller->preview()->getStatus(), $uid . ' sees no preview');
			self::assertSame(Http::STATUS_FORBIDDEN, $controller->updateSettings(5)->getStatus(), $uid . ' chooses no folder');
		}
		foreach (['librarian', 'admin'] as $uid) {
			$response = $this->handoverController($uid)->sync();
			self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $uid . ' passes the role check');
			self::assertSame(['error' => 'No hand-over folder has been chosen yet. An administrator chooses it in the settings of Cantoria.'],
				$response->getData());
		}
		self::assertSame(Http::STATUS_FORBIDDEN, $this->handoverController('librarian')->check(5)->getStatus(), 'only administrators choose the folder');
	}

	public function testTheChoirSystemIsComparedByTheCatalogLibrarianOnly(): void {
		self::assertSame(['portal', 'proposals'], ModuleMiddleware::required(ChoirSystemController::class, 'compare'));
		self::assertSame('POST', self::routes()['ChoirSystemController::compare']['verb']);
		$userFiles = $this->createMock(UserFiles::class);
		$userFiles->expects(self::never())->method('read');
		foreach (['operations', 'conductor', 'reader', 'contributor', 'member', 'admin'] as $uid) {
			$controller = new ChoirSystemController($this->createStub(IRequest::class), $uid, $this->rolesByName(),
				$this->createStub(ChoirSystemCompareService::class), $userFiles, $this->l10n(), $this->createStub(LoggerInterface::class));
			self::assertSame(Http::STATUS_FORBIDDEN, $controller->compare(5, false)->getStatus(), $uid . ' compares nothing');
		}
	}

	public function testFolderBookEntriesOfTheAuditLogAreHiddenFromOtherRoles(): void {
		$excluded = [];
		$entries = $this->createStub(AuditEntryMapper::class);
		$entries->method('findLatest')->willReturnCallback(function (int $limit, array $exclude) use (&$excluded): array {
			$excluded[] = $exclude;
			return [];
		});
		foreach (['contributor', 'reader'] as $uid) {
			$controller = new ApiController($this->createStub(IRequest::class), $uid, $this->rolesByName(), $this->createStub(MetaService::class),
				$this->createStub(CatalogService::class), $this->createStub(ImportService::class), $this->createStub(ExportService::class),
				$this->createStub(FileSyncService::class), $entries,
				$this->createStub(UserFiles::class), $this->createStub(ITimeFactory::class), $this->createStub(IDateTimeZone::class),
				$this->createStub(ScoreService::class), $this->l10n(), $this->createStub(LoggerInterface::class));
			self::assertSame(Http::STATUS_OK, $controller->audit()->getStatus());
		}
		self::assertSame([FolderBookService::OBJECT_TYPES, []], $excluded, 'contributors do not see the names of the folder book');
	}
	private function stateController(string $uid): StateController {
		$roles = $this->rolesByName();
		$mapper = $this->createStub(VersionStateMapper::class);
		$state = new VersionState();
		$state->setId(7);
		$state->setVersionId(3);
		$mapper->method('find')->willReturn($state);
		$service = new StateService($this->createStub(IDBConnection::class), $mapper, $this->createStub(WorkVersionMapper::class),
			$this->createStub(CopyMapper::class), $this->createStub(ArchiveFileMapper::class), $this->createStub(ReviewPointMapper::class),
			$this->createStub(TranscriptionReportMapper::class), $this->createStub(AuditLog::class), $roles, $this->modulesWithout(),
			$this->createStub(AppSettings::class), $this->createStub(IUserManager::class), $this->createStub(ITimeFactory::class),
			$this->createStub(IDateTimeZone::class), $this->l10n(), $this->createStub(LoggerInterface::class));
		return new StateController($this->createStub(IRequest::class), $uid, $service, $this->l10n(), $this->createStub(LoggerInterface::class));
	}

	/**
	 * Milestone 0.8: the revisions of a version are their own module. Every
	 * archive role reads them; only the catalog librarian sets the current
	 * revision. Choir members and accounts without a role get nothing.
	 */
	public function testRevisionsAreReadByTheArchiveRolesAndSetByTheLibrarian(): void {
		foreach (['list', 'setCurrent'] as $method) {
			self::assertSame([Modules::VERSION_STATES], ModuleMiddleware::required(StateController::class, $method), $method);
		}
		self::assertSame('PUT', self::routes()['StateController::setCurrent']['verb'], 'writing keeps the CSRF check');
		self::assertNotContains(NoCSRFRequired::class, self::routes()['StateController::setCurrent']['attributes']);
		foreach (['member', 'nobody'] as $uid) {
			self::assertSame(Http::STATUS_FORBIDDEN, $this->stateController($uid)->list(3)->getStatus(), $uid . ' reads no revisions');
		}
		foreach (['operations', 'conductor', 'reader', 'contributor', 'member', 'admin'] as $uid) {
			self::assertSame(Http::STATUS_FORBIDDEN, $this->stateController($uid)->setCurrent(7)->getStatus(), $uid . ' sets no current revision');
		}
		$response = $this->stateController('librarian')->setCurrent(7, str_repeat('x', 10001));
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), 'the librarian passes the role check');
		self::assertSame(['error' => '"Reason" is too long.'], $response->getData());

		$controller = $this->stateController('librarian');
		(new ModuleMiddleware($this->modulesWithout(), $this->l10n()))->beforeController($controller, 'list');
		$this->expectException(ModuleDisabledException::class);
		(new ModuleMiddleware($this->modulesWithout(['score_viewer']), $this->l10n()))->beforeController($controller, 'list');
	}
}
