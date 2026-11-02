<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Integration test of sharing beyond the archive roles (milestone 0.7)
 * against a real Nextcloud instance with an imported catalog and a file
 * sync: the role "Choir members" and its routes, links for choir members
 * (allowed, refused without release, preservation copy, only the measures
 * of the excerpt, the whole piece, the scan, practice audio), public links
 * (page, whole piece, scan, unknown, expired, revoked, module off: the same
 * 404) and the public repertoire page.
 *
 * Usage (as the web server user):
 *   php tests/Integration/sharing_test.php <nextcloud-root> <uid> <shelfmark-public-domain> <shelfmark-preservation-copy>
 *
 * <uid> must hold the role "librarian" and be the account of the archive
 * folder (as after tools/ci/integration-demo.sh). The first shelf mark is a
 * public domain copy with a MusicXML file of sixteen measures (1 to 16, with
 * a repeat) and a PDF scan, whose rights are not confirmed yet (demo: 1-3),
 * the second an internal
 * preservation copy (demo: 3-1; on a catalog without one it is made one
 * inside the transaction). The test creates a group and an account
 * "member" (random password, never printed) and deletes both at the end;
 * the role groups of the choir members and the module switches are
 * restored exactly; database changes are rolled back.
 */

require __DIR__ . '/harness.php';
[$root, $uid, $publicMark, $preservationMark] = IntegrationTest::start($argv,
	['nextcloud-root', 'uid', 'shelfmark-public-domain', 'shelfmark-preservation-copy']);

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Controller\MemberController;
use OCA\Cantoria\Controller\PageController;
use OCA\Cantoria\Controller\PublicLinkController;
use OCA\Cantoria\Controller\PublicRepertoireController;
use OCA\Cantoria\Db\ArchiveFile;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\AuditEntry;
use OCA\Cantoria\Db\AuditEntryMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\ExcerptLink;
use OCA\Cantoria\Db\ExcerptLinkMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\ModuleDisabledException;
use OCA\Cantoria\Middleware\ModuleMiddleware;
use OCA\Cantoria\Service\CatalogService;
use OCA\Cantoria\Service\LicenseService;
use OCA\Cantoria\Service\LinkService;
use OCA\Cantoria\Service\MetaService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\PracticeAudioService;
use OCA\Cantoria\Service\PublicRepertoireService;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\ScoreService;
use OCA\Cantoria\Service\SharedLinkService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Server;
use Psr\Log\LoggerInterface;

$check = IntegrationTest::check(...);
$throws = IntegrationTest::throws(...);

const NO_ACCESS = 'You have no access to the music archive.';

$db = Server::get(IDBConnection::class);
$appConfig = Server::get(IAppConfig::class);
$modules = Server::get(Modules::class);
$roles = Server::get(Roles::class);
$links = Server::get(LinkService::class);
$shared = Server::get(SharedLinkService::class);
$linkMapper = Server::get(ExcerptLinkMapper::class);
$rights = Server::get(RightsService::class);
$catalog = Server::get(CatalogService::class);
$versions = Server::get(WorkVersionMapper::class);
$copies = Server::get(CopyMapper::class)->findAllByShelfmark();
$l = Server::get(IFactory::class)->get(Application::APP_ID, 'en');
$appContainer = (new Application())->getContainer();
$initialState = $appContainer->get(IInitialState::class);

$public = $copies[$publicMark];
$preservation = $copies[$preservationMark];
$publicVersion = $versions->find($public->getVersionId());
$check('the first copy is public domain and not confirmed yet', $publicVersion->getRightsStatus() === 'public_domain' && !$publicVersion->isConfirmed());
$check('the test account is a catalog librarian', $roles->hasAnyRole($uid, [Roles::LIBRARIAN]));

/** The module switches this test changes: key => stored value, or null when not stored. */
$switchKeys = [Modules::MEMBER_LINKS, Modules::MEMBER_PRACTICE_AUDIO, Modules::PUBLIC_LINKS, Modules::PUBLIC_REPERTOIRE,
	'excerpt_links', 'score_viewer', 'playback', 'practice_audio', 'releases', 'publication', 'rights', 'persons'];
$switches = function () use ($appConfig, $switchKeys): array {
	$values = [];
	foreach ($switchKeys as $key) {
		$configKey = Modules::CONFIG_PREFIX . $key;
		$values[$key] = $appConfig->hasKey(Application::APP_ID, $configKey, null)
			? $appConfig->getValueBool(Application::APP_ID, $configKey)
			: null;
	}
	return $values;
};
$original = $switches();
$memberGroups = $roles->groups(Roles::MEMBER);

// A group and an account of the choir members, outside the transaction, so
// that deleting them removes everything that belongs to them.
$suffix = bin2hex(random_bytes(4));
$group = Server::get(IGroupManager::class)->createGroup('cantoria-test-members-' . $suffix);
$member = Server::get(IUserManager::class)->createUser('member-' . $suffix, bin2hex(random_bytes(24)) . 'aA1!');
$check('test group and account "member" created', $group instanceof IGroup && $member instanceof IUser);

/**
 * The measures of the first part of an excerpt, by printed number.
 *
 * @return list<string>
 */
$measures = function (string $xml): array {
	$document = new DOMDocument();
	if (!$document->loadXML($xml, LIBXML_NONET)) {
		return [];
	}
	$numbers = [];
	foreach ((new DOMXPath($document))->query('/score-partwise/part[1]/measure') ?: [] as $measure) {
		if ($measure instanceof DOMElement) {
			$numbers[] = $measure->getAttribute('number');
		}
	}
	return $numbers;
};

/** Whether a response is the one 404 page of the public pages. */
$isNotFoundPage = fn (Response $response): bool => $response instanceof NotFoundResponse && $response->getStatus() === Http::STATUS_NOT_FOUND
	&& $response->isThrottled() && $response->getParams() === [];

$publicController = new PublicLinkController(Server::get(IRequest::class), $shared, $initialState, $l, Server::get(LoggerInterface::class),
	Server::get(IAppManager::class));
$middleware = new ModuleMiddleware($modules, $l);
/** The answer of the public route: the middleware first, then the method. */
$publicPage = function (string $token) use ($middleware, $publicController): Response {
	try {
		$middleware->beforeController($publicController, 'show');
	} catch (ModuleDisabledException $e) {
		return $middleware->afterException($publicController, 'show', $e);
	}
	return $publicController->show($token);
};
/** The answer of the public route of the scan, likewise. */
$publicScan = function (string $token) use ($middleware, $publicController): Response {
	try {
		$middleware->beforeController($publicController, 'scan');
	} catch (ModuleDisabledException $e) {
		return $middleware->afterException($publicController, 'scan', $e);
	}
	return $publicController->scan($token);
};
/** Whether a response is a PDF for the browser's own viewer. */
$isInlinePdf = function (Response $response): bool {
	$headers = $response->getHeaders();
	return $response->getStatus() === Http::STATUS_OK && ($headers['Content-Type'] ?? '') === 'application/pdf'
		&& ($headers['Content-Disposition'] ?? '') === 'inline; filename="scan.pdf"' && ($headers['X-Content-Type-Options'] ?? '') === 'nosniff'
		&& str_contains($response->getContentSecurityPolicy()->buildPolicy(), "default-src 'none'");
};
/** The PDF scans of a copy. */
$scansOf = fn (int $copyId): array => array_values(array_filter(Server::get(ArchiveFileMapper::class)->findByCopy($copyId),
	fn (ArchiveFile $file): bool => $file->getRole() === 'score_pdf' && $file->getFound() !== false));
// The measures of the whole piece, from the score file itself (any catalog)
$sourceFile = ScoreService::pick(Server::get(ArchiveFileMapper::class)->findByCopy($public->getId()));
$sourceXml = $sourceFile === null ? '' : Server::get(ScoreService::class)->scoreText(Server::get(ScoreService::class)->content($sourceFile->getId(), $uid)['content'])['xml'];
$wholePiece = $measures($sourceXml);
$sourceRepeats = str_contains($sourceXml, 'direction="backward"');

$db->beginTransaction();
try {
	if (!$group instanceof IGroup || !$member instanceof IUser) {
		throw new RuntimeException('test account missing');
	}
	$group->addUser($member);
	// Precondition, so that the test also runs on a catalog without a preservation copy
	if ($versions->find($preservation->getVersionId())->getPreservationCopy() !== true) {
		$catalog->update('version', $preservation->getVersionId(), ['preservation_copy' => true], $uid, 'integration test');
	}
	$check('the second copy is an internal preservation copy', $versions->find($preservation->getVersionId())->getPreservationCopy() === true);
	$memberUid = $member->getUID();
	$roles->setGroups(Roles::MEMBER, [...$memberGroups, $group->getGID()]);
	$modules->change([Modules::MEMBER_PRACTICE_AUDIO => true, Modules::PUBLIC_LINKS => true, Modules::PUBLIC_REPERTOIRE => true, 'persons' => true]);

	// ---- the role: no access to the archive ----
	$check('the account is a choir member', $roles->isMember($memberUid));
	$check('a choir member holds no archive role', $roles->rolesOf($memberUid) === [] && !$roles->hasAccess($memberUid));
	$check('archive services refuse a choir member', $throws(fn () => $roles->requireAccess($memberUid)) === NO_ACCESS);
	$scoreFile = ScoreService::pick(Server::get(ArchiveFileMapper::class)->findByCopy($public->getId()));
	$check('the public domain copy has a MusicXML file', $scoreFile !== null);
	if ($scoreFile !== null) {
		$check('a choir member gets no score file of the archive', $throws(fn () => Server::get(ScoreService::class)->content($scoreFile->getId(), $memberUid)) === NO_ACCESS);
	}
	$pageController = fn (string $account): PageController => new PageController(Server::get(IRequest::class), $account, $roles,
		Server::get(MetaService::class), $initialState, $l, $modules, $shared, Server::get(IAppManager::class));
	$check('a choir member gets the page "Shared with me"', $pageController($memberUid)->index()->getTemplateName() === 'member');
	$check('the archive roles get the archive', $pageController($uid)->index()->getTemplateName() === 'index');

	// ---- links for choir members ----
	$memberLink = ['circle' => 'members', 'measure_from' => '2', 'measure_to' => '4', 'voice' => 'Alto', 'note' => 'Please practise until Thursday.'];
	$reason = $throws(fn () => $links->create($public->getId(), $memberLink, $uid));
	$check('refused without release (public domain not confirmed)', $reason !== null && str_starts_with($reason, 'Public domain is not confirmed yet.'), (string)$reason);
	$scanId = ($scansOf($public->getId())[0] ?? null)?->getId();
	$check('the public domain copy has a PDF scan', $scanId !== null);
	$reason = $throws(fn () => $links->create($public->getId(), ['circle' => 'members', 'scan_file_id' => $scanId], $uid));
	$check('the whole piece with the scan: refused without release, too', $reason !== null && str_starts_with($reason, 'Public domain is not confirmed yet.'),
		(string)$reason);
	$rights->confirm($public->getVersionId(), $uid, 'public_domain', 'All persons involved died more than 70 years ago', 'integration test');
	$check('a measure that is not in the score is refused', $throws(fn () => $links->create($public->getId(), ['measure_to' => '999'] + $memberLink, $uid))
		=== 'There is no measure 999 in this score.');
	$link = $links->create($public->getId(), $memberLink, $uid);
	$check('a link for choir members is made', $link->getCircle() === ExcerptLink::CIRCLE_MEMBERS);

	// ---- the whole piece and the scan for choir members ----
	$wholeLink = $links->create($public->getId(), ['circle' => 'members', 'voice' => 'Alto', 'scan_file_id' => $scanId], $uid);
	$check('a link without measures shares the whole piece, with the scan', $wholeLink->getMeasureFrom() === null
		&& $wholeLink->getMeasureTo() === null && $wholeLink->getScanFileId() === $scanId);
	$wholeXml = $shared->memberScore($wholeLink->issuedToken(), $memberUid);
	$check('the server sends every measure of the whole piece', $wholePiece !== [] && $measures($wholeXml) === $wholePiece, implode(',', $measures($wholeXml)));
	$check('with the repeats of the score (the full playing order)', str_contains($wholeXml, 'direction="backward"') === $sourceRepeats);
	$wholeEntry = array_values(array_filter($shared->forMember($memberUid), fn (array $row): bool => $row['token'] === $wholeLink->issuedToken()))[0] ?? [];
	$check('listed as the whole piece with the scan', array_key_exists('measure_from', $wholeEntry) && $wholeEntry['measure_from'] === null
		&& ($wholeEntry['scan'] ?? false) === true, json_encode($wholeEntry) ?: '');
	$memberController = fn (string $account): MemberController => new MemberController(Server::get(IRequest::class), $account, $shared,
		Server::get(PracticeAudioService::class), $l, Server::get(LoggerInterface::class));
	$check('the choir member gets the scan inline for the PDF viewer', $isInlinePdf($memberController($memberUid)->scan($wholeLink->issuedToken())));
	$check('a link without a scan offers none', $throws(fn () => $shared->memberScan($link->issuedToken(), $memberUid))
		=== 'The scan of this link is not available at the moment.');
	$check('the scan route refuses accounts that are no choir members', $memberController($uid)->scan($wholeLink->issuedToken())->getStatus()
		=== Http::STATUS_FORBIDDEN);
	$check('an internal link takes no scan', $throws(fn () => $links->create($public->getId(), ['scan_file_id' => $scanId], $uid))
		=== 'Only links for choir members and public links offer the scan; the archive roles see it beside the score.');
	$otherScan = ($scansOf($preservation->getId())[0] ?? null)?->getId();
	if ($otherScan !== null) {
		$check('a scan of another copy is refused', $throws(fn () => $links->create($public->getId(), ['circle' => 'members', 'scan_file_id' => $otherScan], $uid))
			=== 'This scan does not belong to the copy. Please choose one of the PDF files of the copy (tab "Files").');
	}
	$check('one measure alone is refused', $throws(fn () => $links->create($public->getId(), ['circle' => 'members', 'measure_from' => '2'], $uid))
		=== 'Please give both the first and the last measure of the excerpt, or leave both empty to share the whole piece.');

	Server::get(LicenseService::class)->create($preservation->getVersionId(), ['holder' => 'Publisher', 'uses' => ['portal', 'practice_audio'],
		'valid_until' => '2099-12-31', 'evidence' => 'integration test'], $uid);
	$reason = $throws(fn () => $links->create($preservation->getId(), $memberLink, $uid));
	$check('an internal preservation copy is never shared, not even with a license',
		in_array($reason, ['Internal preservation copy: it is never shared with choir members.',
			'Internal preservation copy: only the catalog librarian and the conductor may view it.'], true), (string)$reason);

	$listed = $shared->forMember($memberUid);
	$check('the choir member sees the link', in_array($link->issuedToken(), array_column($listed, 'token'), true));
	$entry = array_values(array_filter($listed, fn (array $row): bool => $row['token'] === $link->issuedToken()))[0] ?? [];
	$check('without shelf mark or account name', !array_key_exists('shelfmark', $entry) && !array_key_exists('created_by', $entry), json_encode($entry) ?: '');
	$check('a non-member sees nothing', $throws(fn () => $shared->forMember($uid)) !== null);

	$hitsBefore = $linkMapper->findByToken($link->issuedToken())->getHits();
	$xml = $shared->memberScore($link->issuedToken(), $memberUid);
	$check('the server sends only the measures of the excerpt', $measures($xml) === ['2', '3', '4'], implode(',', $measures($xml)));
	$check('with its attributes', str_contains($xml, '<divisions>') && str_contains($xml, '<clef'));
	$check('the call is counted', $linkMapper->findByToken($link->issuedToken())->getHits() === $hitsBefore + 1);

	$practiceAudio = Server::get(PracticeAudioService::class);
	$practiceAudio->authorizeForMember($link->issuedToken(), ['voice' => 'Alto', 'mode' => 'solo', 'format' => 'wav', 'tempo' => 80], $memberUid);
	$exports = array_values(array_filter(Server::get(AuditEntryMapper::class)->findForObjects([['version', $public->getVersionId()]], 50),
		fn (AuditEntry $e): bool => $e->getAction() === PracticeAudioService::ACTION));
	$details = json_decode((string)(($exports[0] ?? null)?->getNewValue() ?? ''), true);
	$check('practice audio of a choir member is recorded with the measures of the link and the role, without account or name', is_array($details)
		&& $details['measures'] === '2-4' && ($exports[0] ?? null)?->getRoles() === Roles::MEMBER && ($exports[0] ?? null)?->getUserId() === null
		&& ($exports[0] ?? null)?->getUserName() === null, (string)(($exports[0] ?? null)?->getNewValue() ?? ''));
	$practiceAudio->authorizeForMember($wholeLink->issuedToken(), ['voice' => 'Alto', 'mode' => 'focus', 'format' => 'midi', 'measure_from' => '1',
		'measure_to' => '16'], $memberUid);
	$wholeExports = array_filter(Server::get(AuditEntryMapper::class)->findForObjects([['version', $public->getVersionId()]], 50),
		fn (AuditEntry $e): bool => $e->getAction() === PracticeAudioService::ACTION
			&& (json_decode((string)$e->getNewValue(), true)['measures'] ?? null) === '1-16');
	$check('practice audio of the whole piece is recorded with the measures of the file', count($wholeExports) === 1);
	$modules->change([Modules::MEMBER_PRACTICE_AUDIO => false]);
	$check('practice audio for choir members switched off: not offered', ($shared->forMember($memberUid)[0]['canExportAudio'] ?? true) === false);

	$links->revoke($link->getId(), $uid);
	$check('a revoked link is dead for choir members', $throws(fn () => $shared->memberScore($link->issuedToken(), $memberUid)) === 'This link has been revoked.');

	// ---- public links ----
	$publicLink = ['circle' => 'public', 'measure_from' => '9', 'measure_to' => '12', 'voice' => 'Alto'];
	$reason = $throws(fn () => $links->create($public->getId(), $publicLink, $uid));
	$check('refused without the release for publication', $reason !== null && str_starts_with($reason, 'Not released for publication.'), (string)$reason);
	$reason = $throws(fn () => $links->create($public->getId(), ['circle' => 'public', 'scan_file_id' => $scanId], $uid));
	$check('the whole piece with the scan: refused without the release for publication, too', $reason !== null
		&& str_starts_with($reason, 'Not released for publication.'), (string)$reason);
	$catalog->update('version', $public->getVersionId(), ['publication_status' => 'free'], $uid, 'integration test');
	$check('a public link has no note', $throws(fn () => $links->create($public->getId(), $publicLink + ['note' => 'internal'], $uid)) !== null);
	$link = $links->create($public->getId(), $publicLink, $uid);
	$check('a public link is made', $link->getCircle() === ExcerptLink::CIRCLE_PUBLIC);

	$excerpt = $shared->publicExcerpt($link->issuedToken());
	$check('the public excerpt has only the measures', $excerpt !== null && $measures($excerpt['xml']) === ['9', '10', '11', '12']);
	$check('and nothing internal', $excerpt !== null && array_keys($excerpt) === ['title', 'composer', 'measureFrom', 'measureTo', 'voice', 'verse',
		'tempo', 'canPlay', 'scan', 'scanSize', 'practiceKey', 'expires',
		'offline', 'served', 'xml', 'credits', 'notices']);

	$publicWhole = $links->create($public->getId(), ['circle' => 'public', 'voice' => 'Alto', 'scan_file_id' => $scanId], $uid);
	$wholeExcerpt = $shared->publicExcerpt($publicWhole->issuedToken());
	$check('a public link without measures shows the whole piece', $wholeExcerpt !== null && $wholeExcerpt['measureFrom'] === null
		&& $measures($wholeExcerpt['xml']) === $wholePiece);
	$check('and offers the scan', $wholeExcerpt !== null && $wholeExcerpt['scan'] === true);
	$check('the public page of the whole piece', $publicPage($publicWhole->issuedToken()) instanceof PublicTemplateResponse);
	$check('the public scan is shown inline for the PDF viewer', $isInlinePdf($publicScan($publicWhole->issuedToken())));
	$check('a public link without a scan: the 404 page for the scan', $isNotFoundPage($publicScan($link->issuedToken())));
	$check('an unknown token: the same 404 page for the scan', $isNotFoundPage($publicScan(str_repeat('x', 32))));
	$check('a link for choir members: the same 404 page for the scan', $isNotFoundPage($publicScan($wholeLink->issuedToken())));
	$page = $publicPage($link->issuedToken());
	$check('the public page answers with the public layout', $page instanceof PublicTemplateResponse && $page->getStatus() === Http::STATUS_OK
		&& !$page->isThrottled());
	$check('its policy allows WebAssembly only', str_contains($page->getContentSecurityPolicy()->buildPolicy(), "'wasm-unsafe-eval'")
		&& !str_contains($page->getContentSecurityPolicy()->buildPolicy(), "'unsafe-eval'"));
	$check('a public link does not open for choir members', $throws(fn () => $shared->memberScore($link->issuedToken(), $memberUid)) === 'This link does not exist.');

	$check('an unknown token: the 404 page', $isNotFoundPage($publicPage(str_repeat('x', 32))));
	$check('a malformed token: the same 404 page', $isNotFoundPage($publicPage('not-a-token')));
	$expired = $linkMapper->findByToken($link->issuedToken());
	$expired->setExpires((new DateTimeImmutable($rights->today(null)))->modify('-1 day')->format('Y-m-d'));
	$linkMapper->update($expired);
	$check('an expired link: the same 404 page', $isNotFoundPage($publicPage($link->issuedToken())));
	$expired->setExpires((new DateTimeImmutable($rights->today(null)))->modify('+1 day')->format('Y-m-d'));
	$linkMapper->update($expired);
	$check('valid again: the page', $publicPage($link->issuedToken()) instanceof PublicTemplateResponse);
	$modules->change([Modules::PUBLIC_LINKS => false]);
	$check('the module switched off: the same 404 page', $isNotFoundPage($publicPage($link->issuedToken())));
	$check('the module switched off: the same 404 page for the scan', $isNotFoundPage($publicScan($publicWhole->issuedToken())));
	$modules->change([Modules::PUBLIC_LINKS => true]);
	$links->revoke($link->getId(), $uid);
	$check('a revoked link: the same 404 page', $isNotFoundPage($publicPage($link->issuedToken())));

	// ---- the public repertoire page ----
	$repertoire = Server::get(PublicRepertoireService::class);
	$workId = $publicVersion->getWorkId();
	$catalog->update('work', $workId, ['public_repertoire' => true, 'cpdl_url' => 'https://www.cpdl.org/wiki/index.php/Main_Page'], $uid, 'integration test');
	$titles = array_column($repertoire->entries(), 'title');
	$check('a chosen public domain work is on the page', in_array(trim((string)Server::get(WorkMapper::class)->find($workId)->getTitle()), $titles, true),
		implode(', ', $titles));
	$check('a link that is not to CPDL is refused', $throws(fn () => $catalog->update('work', $workId, ['cpdl_url' => 'https://example.com/'], $uid, 'test')) !== null);
	$preservationWork = $versions->find($preservation->getVersionId())->getWorkId();
	$eligible = array_filter($versions->findByWork($preservationWork), PublicRepertoireService::eligible(...));
	if ($eligible === []) {
		$check('a work without a confirmed public domain version cannot be chosen',
			$throws(fn () => $catalog->update('work', $preservationWork, ['public_repertoire' => true], $uid, 'test')) !== null);
	}
	$repertoirePage = new PublicRepertoireController(Server::get(IRequest::class), $repertoire, $l, Server::get(LoggerInterface::class));
	$response = $repertoirePage->index();
	$check('the repertoire page answers with the public layout', $response instanceof TemplateResponse && $response->getStatus() === Http::STATUS_OK
		&& count($response->getParams()['entries'] ?? []) === count($titles));
	$rights->setOpen($public->getVersionId(), $uid, 'integration test');
	$check('when the rights change, the work leaves the page at once', count($repertoire->entries()) === count($titles) - 1);
	$check('and the scan is refused at once: for choir members', $throws(fn () => $shared->memberScan($wholeLink->issuedToken(), $memberUid))
		=== 'This piece is no longer shared with choir members.');
	$check('and for the public: the same 404 page', $isNotFoundPage($publicScan($publicWhole->issuedToken())));
	$modules->change([Modules::PUBLIC_REPERTOIRE => false]);
	try {
		$middleware->beforeController($repertoirePage, 'index');
		$check('the repertoire page is off with its module', false);
	} catch (ModuleDisabledException $e) {
		$check('the repertoire page is off with its module: the 404 page', $isNotFoundPage($middleware->afterException($repertoirePage, 'index', $e)));
	}
} catch (Throwable $e) {
	IntegrationTest::unexpected($e);
} finally {
	$db->rollBack();
	$roles->setGroups(Roles::MEMBER, $memberGroups);
	// Exactly the switches of before, also when the rollback did not cover
	// them (the app config caches its values).
	$appConfig->clearCache(true);
	foreach ($original as $key => $value) {
		$configKey = Modules::CONFIG_PREFIX . $key;
		if ($value === null) {
			$appConfig->deleteKey(Application::APP_ID, $configKey);
		} elseif (!$appConfig->hasKey(Application::APP_ID, $configKey, null) || $appConfig->getValueBool(Application::APP_ID, $configKey) !== $value) {
			$appConfig->setValueBool(Application::APP_ID, $configKey, $value);
		}
	}
	$appConfig->clearCache(true);
	$check('module configuration restored exactly', $switches() === $original);
	$check('role groups of the choir members restored', $roles->groups(Roles::MEMBER) === $memberGroups);
	if ($member instanceof IUser) {
		$check('test account "member" deleted', $member->delete());
	}
	if ($group instanceof IGroup) {
		$check('test group deleted', $group->delete());
	}
}

IntegrationTest::finish();
