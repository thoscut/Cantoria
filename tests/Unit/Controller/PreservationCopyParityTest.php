<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Controller;

use OCA\Cantoria\Controller\ApiController;
use OCA\Cantoria\Controller\ApiV1CatalogController;
use OCA\Cantoria\Controller\ApiV1ConcertController;
use OCA\Cantoria\Controller\EventController;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\ExcerptLinkMapper;
use OCA\Cantoria\Db\LicenseMapper;
use OCA\Cantoria\Db\Task;
use OCA\Cantoria\Db\TaskMapper;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\CatalogCsv;
use OCA\Cantoria\Service\CatalogService;
use OCA\Cantoria\Service\EventService;
use OCA\Cantoria\Service\ExportService;
use OCA\Cantoria\Service\FileSyncService;
use OCA\Cantoria\Service\FullBackupService;
use OCA\Cantoria\Service\ImportService;
use OCA\Cantoria\Service\LinkService;
use OCA\Cantoria\Service\MetaService;
use OCA\Cantoria\Service\OdsWriter;
use OCA\Cantoria\Service\PersonService;
use OCA\Cantoria\Service\ProgrammeCopies;
use OCA\Cantoria\Service\ProgrammeShareService;
use OCA\Cantoria\Service\RepertoireService;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\ScoreService;
use OCA\Cantoria\Service\TaskService;
use OCA\Cantoria\Service\UserFiles;
use OCA\Cantoria\Tests\Unit\Support\ModuleSwitches;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IAppConfig;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Settings\IManager as ISettingsManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The internal API (the app's own pages) and the API for agents (v1) show
 * and refuse the same internal preservation copies for every role: a
 * version marked as one, and a copy of the kind "Preservation copy". Both
 * ask RightsPolicy; the catalog and the policy are real, the mappers are
 * test doubles. The same holds for the CSV export, the files the app
 * stores in the archive folder and the task lists, for every role group,
 * administrators without a role and choir members.
 */
#[CoversClass(CatalogService::class)]
#[CoversClass(RightsPolicy::class)]
#[CoversClass(EventService::class)]
#[CoversClass(ProgrammeShareService::class)]
#[CoversClass(ExportService::class)]
#[CoversClass(TaskService::class)]
final class PreservationCopyParityTest extends TestCase {
	use ModuleSwitches;

	private WorkMapper $workMapper;
	private WorkVersionMapper $versionMapper;
	private CopyMapper $copyMapper;

	/** Copy 1: ordinary, copy 2: version marked, copy 3: copy of the kind preservation copy */
	private const ORDINARY = 1;
	private const MARKED_VERSION = 2;
	private const PRESERVATION_KIND = 3;

	/**
	 * Every role group, an administrator without a role and a choir member
	 * (no archive role): roles, admin, may view preservation copies.
	 *
	 * @return array<string, array{list<string>, bool, bool}>
	 */
	public static function roles(): array {
		return [
			'catalog librarian' => [[Roles::LIBRARIAN], false, true],
			'conductor' => [[Roles::CONDUCTOR], false, true],
			'operations' => [[Roles::OPERATIONS], false, false],
			'contributor' => [[Roles::CONTRIBUTOR], false, false],
			'reader' => [[Roles::READER], false, false],
			'choir member' => [[Roles::MEMBER], false, false],
			'administrator without a role' => [[], true, false],
			'administrator and conductor' => [[Roles::CONDUCTOR], true, true],
		];
	}

	/**
	 * The real role check, with one group per role and the account in the
	 * groups of $roles.
	 *
	 * @param list<string> $roles
	 */
	private function roleStub(array $roles, bool $admin = false): Roles {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueArray')->willReturnCallback(fn (string $app, string $key): array => ['g-' . substr($key, strlen('groups_'))]);
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => in_array(substr($group, 2), $roles, true));
		$groups->method('isAdmin')->willReturn($admin);
		return new Roles($config, $groups, $this->createStub(IUserManager::class), $this->createStub(ISettingsManager::class),
			$this->createStub(LoggerInterface::class));
	}

	/**
	 * Whether the account may use the archive at all (choir members may not).
	 *
	 * @param list<string> $roles
	 */
	private static function hasAccess(array $roles, bool $admin): bool {
		return $admin || array_diff($roles, [Roles::MEMBER]) !== [];
	}

	/**
	 * @param list<string> $roles
	 * @return array{CatalogService, RightsPolicy, Roles}
	 */
	private function services(array $roles, bool $admin = false): array {
		$works = [];
		$versions = [];
		$copies = [];
		foreach ([self::ORDINARY, self::MARKED_VERSION, self::PRESERVATION_KIND] as $id) {
			$work = new Work();
			$work->setId(100 + $id);
			$work->setTitle('Werk ' . $id);
			$works[$work->getId()] = $work;
			$version = new WorkVersion();
			$version->setId(200 + $id);
			$version->setWorkId(100 + $id);
			$version->setPreservationCopy($id === self::MARKED_VERSION);
			$version->setRightsStatus('public_domain');
			$versions[$version->getId()] = $version;
			$copy = new Copy();
			$copy->setId($id);
			$copy->setVersionId(200 + $id);
			$copy->setShelfmark('1-' . $id);
			$copy->setBinder(1);
			$copy->setNumber($id);
			$copy->setStatus('active');
			$copy->setKind($id === self::PRESERVATION_KIND ? RightsPolicy::PRESERVATION_COPY_KIND : null);
			$copies[$id] = $copy;
		}
		$workMapper = $this->createStub(WorkMapper::class);
		$workMapper->method('findAllById')->willReturn($works);
		$workMapper->method('find')->willReturnCallback(fn (int $id): Work => $works[$id]);
		$versionMapper = $this->createStub(WorkVersionMapper::class);
		$versionMapper->method('findAllById')->willReturn($versions);
		$versionMapper->method('find')->willReturnCallback(fn (int $id): WorkVersion => $versions[$id]);
		$versionMapper->method('findByWork')->willReturnCallback(fn (int $id): array => [$versions[$id + 100]]);
		$copyMapper = $this->createStub(CopyMapper::class);
		$copyMapper->method('findAll')->willReturn(array_values($copies));
		$copyMapper->method('find')->willReturnCallback(fn (int $id): Copy => $copies[$id]);
		$copyMapper->method('findByVersion')->willReturnCallback(fn (int $id): array => [$copies[$id - 200]]);
		$fileMapper = $this->createStub(ArchiveFileMapper::class);
		$fileMapper->method('countPerCopy')->willReturn([]);
		$fileMapper->method('findByCopy')->willReturn([]);
		$repertoire = $this->createStub(RepertoireService::class);
		$repertoire->method('perWork')->willReturn([]);
		$rights = $this->createStub(RightsService::class);
		$rights->method('today')->willReturn('2026-10-27');

		$this->workMapper = $workMapper;
		$this->versionMapper = $versionMapper;
		$this->copyMapper = $copyMapper;
		$roles = $this->roleStub($roles, $admin);
		$this->programme = [$workMapper, $versionMapper, $copyMapper, $fileMapper, $rights, $roles];
		$modules = $this->modules(array_fill_keys(\OCA\Cantoria\Service\Modules::switchable(), false));
		$catalog = new CatalogService($this->createStub(IDBConnection::class), $workMapper, $versionMapper, $copyMapper, $fileMapper,
			$this->createStub(\OCA\Cantoria\Db\AuditEntryMapper::class), $this->createStub(\OCA\Cantoria\Service\AuditLog::class), $roles,
			$this->createStub(ITimeFactory::class), $this->createStub(IDateTimeZone::class), $this->createStub(ScoreService::class), $rights,
			$this->createStub(LicenseMapper::class), $this->createStub(\OCA\Cantoria\Db\ProposalMapper::class),
			$this->createStub(IUserManager::class), $this->createStub(TaskService::class), $this->createStub(\OCA\Cantoria\Service\LinkService::class),
			$repertoire, $this->createStub(\OCA\Cantoria\Service\PersonService::class), $this->createStub(\OCA\Cantoria\Service\MergeService::class),
			$modules, $this->createStub(\OCA\Cantoria\Db\ReviewPointMapper::class), $this->createStub(\OCA\Cantoria\Service\StateService::class));
		return [$catalog, new RightsPolicy($roles, $rights, $this->createStub(LicenseMapper::class)), $roles];
	}

	/** @var array{WorkMapper, WorkVersionMapper, CopyMapper, ArchiveFileMapper, RightsService, Roles}|null the mappers of services() for the programme */
	private ?array $programme = null;

	/**
	 * The internal API of programmes, the API for agents and the options for
	 * sharing a programme, over a concert with one line per copy (S N13).
	 *
	 * @return array{EventController, ApiV1ConcertController}
	 */
	private function concerts(string $role): array {
		$this->services([$role]);
		self::assertNotNull($this->programme);
		[$works, $versions, $copies, $files, $rights, $roles] = $this->programme;
		$events = $this->createStub(EventMapper::class);
		$events->method('find')->willReturn(Event::fromParams(['id' => 4, 'kind' => Event::CONCERT, 'title' => 'Konzert']));
		$items = $this->createStub(EventItemMapper::class);
		$lines = [];
		foreach ([self::ORDINARY, self::MARKED_VERSION, self::PRESERVATION_KIND] as $id) {
			$lines[] = EventItem::fromParams(['id' => $id, 'eventId' => 4, 'position' => $id, 'copyId' => $id, 'versionId' => 200 + $id,
				'workId' => 100 + $id, 'assignment' => EventItem::CERTAIN]);
		}
		$items->method('findByEvent')->willReturn($lines);
		$service = new EventService($this->createStub(IDBConnection::class), $events, $items, $copies, $versions, $works,
			$this->createStub(LicenseMapper::class), $rights, $this->createStub(\OCA\Cantoria\Service\StateService::class),
			$this->createStub(\OCA\Cantoria\Service\AuditLog::class), $roles, $this->createStub(ITimeFactory::class), $this->createStub(IDateTimeZone::class),
			$this->createStub(\OCA\Cantoria\Db\ContributionMapper::class), $this->createStub(\OCA\Cantoria\Db\PersonMapper::class));
		$links = $this->createStub(LinkService::class);
		$links->method('circleOptions')->willReturn(['members' => ['allowed' => false, 'reason' => 'x', 'scan' => false, 'offline' => null], 'public' => [], 'scans' => []]);
		$share = new ProgrammeShareService($events, $items, new ProgrammeCopies($copies, $files), $versions, $works, $this->createStub(ExcerptLinkMapper::class),
			$links, $rights, $roles);
		$internal = new EventController($this->createStub(IRequest::class), 'account', $roles, $service, $items,
			$this->createStub(\OCA\Cantoria\Service\PerformanceImportService::class), $share, $this->createStub(RepertoireService::class),
			$this->createStub(UserFiles::class), $this->l10n(), $this->createStub(LoggerInterface::class));
		$agents = new ApiV1ConcertController($this->createStub(IRequest::class), 'account', $roles, $service, $this->l10n(), $this->createStub(LoggerInterface::class));
		return [$internal, $agents];
	}

	/**
	 * The archive roles that see programmes: role, may view preservation copies.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function programmeRoles(): array {
		return [
			'catalog librarian' => [Roles::LIBRARIAN, true],
			'conductor' => [Roles::CONDUCTOR, true],
			'operations' => [Roles::OPERATIONS, false],
			'contributor' => [Roles::CONTRIBUTOR, false],
		];
	}

	#[DataProvider('programmeRoles')]
	public function testProgrammesShowPreservationCopiesOnlyToTheRolesThatMayViewThem(string $role, bool $mayView): void {
		[$internal, $agents] = $this->concerts($role);
		$detail = $internal->detail(4)->getData();
		$v1 = $agents->concert(4)->getData();
		$options = $internal->shareOptions(4)->getData();
		foreach ([self::ORDINARY, self::MARKED_VERSION, self::PRESERVATION_KIND] as $index => $id) {
			$hidden = !$mayView && $id !== self::ORDINARY;
			$shelfmark = $hidden ? null : '1-' . $id;
			self::assertSame($shelfmark, $detail['items'][$index]['shelfmark'] ?? null, 'internal API, copy ' . $id);
			self::assertSame($hidden ? null : $id, $detail['items'][$index]['copy_id'] ?? null, 'internal API, copy ' . $id);
			self::assertSame($shelfmark, $v1['items'][$index]['shelfmark'] ?? null, 'API for agents, copy ' . $id);
			self::assertSame($hidden ? [null, null] : [$id, 200 + $id], [$v1['items'][$index]['copy_id'] ?? null, $v1['items'][$index]['version_id'] ?? null],
				'API for agents, copy ' . $id);
			$warnings = $v1['items'][$index]['warnings'] ?? [];
			self::assertSame($hidden ? [ProgrammeShareService::NOT_AVAILABLE] : $warnings, $warnings, 'nothing that marks it as a preservation copy');
			if ($id !== self::ORDINARY && $mayView) {
				self::assertContains('Internal preservation copy: not a singing copy.', $warnings, 'the roles that may view it are warned');
			}
			if (in_array($role, LinkService::CREATORS, true)) {
				self::assertSame($shelfmark, $options['items'][$index]['shelfmark'] ?? null, 'share-options, copy ' . $id);
				self::assertSame($hidden ? null : $id, $options['items'][$index]['copyId'] ?? null, 'share-options, copy ' . $id);
			}
		}
	}

	private function l10n(): IL10N {
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return $l;
	}

	private function internal(CatalogService $catalog, Roles $roles, ?ExportService $export = null): ApiController {
		return new ApiController($this->createStub(IRequest::class), 'account', $roles, $this->createStub(MetaService::class), $catalog,
			$this->createStub(ImportService::class), $export ?? $this->createStub(ExportService::class), $this->createStub(FileSyncService::class),
			$this->createStub(\OCA\Cantoria\Db\AuditEntryMapper::class), $this->createStub(UserFiles::class), $this->time(),
			$this->createStub(IDateTimeZone::class), $this->createStub(ScoreService::class), $this->l10n(), $this->createStub(LoggerInterface::class));
	}

	private function time(): ITimeFactory {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-03 12:00:00'));
		return $time;
	}

	/**
	 * The real export with the mappers of services(); the export folder
	 * (inside the archive folder) and the backup folder record what is
	 * written into them.
	 *
	 * @param array<string, string> $written file name => content
	 * @param array<string, string> $backups file name => content
	 */
	private function export(Roles $roles, array &$written, array &$backups = []): ExportService {
		$settings = $this->createStub(AppSettings::class);
		$settings->method('exportFolder')->willReturn($this->recordingFolder($written));
		$settings->method('backupFolder')->willReturn($this->recordingFolder($backups));
		return new ExportService($this->workMapper, $this->versionMapper, $this->copyMapper, $settings, $this->time(),
			$this->createStub(IDateTimeZone::class), $this->createStub(\OCA\Cantoria\Db\AuditEntryMapper::class), $this->createStub(OdsWriter::class),
			$this->createStub(PersonService::class), $this->createStub(FullBackupService::class), $this->createStub(IAppConfig::class), $roles,
			$this->createStub(LoggerInterface::class));
	}

	/**
	 * @param array<string, string> $written file name => content
	 */
	private function recordingFolder(array &$written): Folder {
		$folder = $this->createStub(Folder::class);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturnCallback(function (string $name, mixed $content = null) use (&$written): File {
			$written[$name] = is_string($content) ? $content : '';
			return $this->createStub(File::class);
		});
		return $folder;
	}

	/**
	 * Shelf marks of a catalog CSV, and whether a column holds accounts.
	 *
	 * @return array{list<string>, bool}
	 */
	private static function shelfmarks(string $csv): array {
		$parsed = CatalogCsv::parse($csv);
		$column = array_search('Signatur', $parsed['header'], true);
		self::assertIsInt($column);
		$accounts = false;
		foreach ($parsed['header'] as $header) {
			$field = substr((string)strrchr('.' . $header, '.'), 1);
			$accounts = $accounts || in_array($field, FullBackupService::ACCOUNT_COLUMNS, true);
		}
		return [array_map(fn (array $row): string => $row[$column], $parsed['rows']), $accounts];
	}

	private function agents(CatalogService $catalog, RightsPolicy $policy, Roles $roles): ApiV1CatalogController {
		return new ApiV1CatalogController($this->createStub(IRequest::class), 'account', $roles, $catalog, $policy, $this->l10n(),
			$this->createStub(LoggerInterface::class));
	}

	/**
	 * @param list<string> $role
	 */
	#[DataProvider('roles')]
	public function testBothInterfacesListTheSameCopies(array $role, bool $admin, bool $mayView): void {
		[$catalog, $policy, $roles] = $this->services($role, $admin);
		if (!self::hasAccess($role, $admin)) {
			self::assertSame(Http::STATUS_FORBIDDEN, $this->internal($catalog, $roles)->entries()->getStatus(), 'internal API');
			$this->expectException(OCSForbiddenException::class);
			$this->agents($catalog, $policy, $roles)->search();
		}

		$internal = $this->internal($catalog, $roles)->entries()->getData();
		self::assertIsArray($internal);
		$internalIds = array_map(fn (mixed $row): mixed => is_array($row) ? $row['id'] : null, $internal);
		$v1 = $this->agents($catalog, $policy, $roles)->search()->getData();
		self::assertIsArray($v1);
		self::assertIsArray($v1['items']);
		$v1Ids = array_map(fn (mixed $row): mixed => is_array($row) ? $row['id'] : null, $v1['items']);

		$expected = $mayView ? [self::ORDINARY, self::MARKED_VERSION, self::PRESERVATION_KIND] : [self::ORDINARY];
		self::assertSame($expected, $internalIds, 'internal API');
		self::assertSame($expected, $v1Ids, 'API for agents');
	}

	/**
	 * @param list<string> $role
	 */
	#[DataProvider('roles')]
	public function testBothInterfacesRefuseTheSameCopies(array $role, bool $admin, bool $mayView): void {
		[$catalog, $policy, $roles] = $this->services($role, $admin);
		foreach ([self::ORDINARY, self::MARKED_VERSION, self::PRESERVATION_KIND] as $id) {
			$internal = $this->internal($catalog, $roles)->entry($id);
			try {
				$this->agents($catalog, $policy, $roles)->copy($id);
				$v1Refused = false;
			} catch (OCSForbiddenException) {
				$v1Refused = true;
			}
			$refused = !self::hasAccess($role, $admin) || (!$mayView && $id !== self::ORDINARY);
			self::assertSame($refused ? Http::STATUS_FORBIDDEN : Http::STATUS_OK, $internal->getStatus(), 'internal API, copy ' . $id);
			self::assertSame($refused, $v1Refused, 'API for agents, copy ' . $id);
			self::assertSame($refused, $policy->viewDenial($catalog->copyWithContext($id)['version'], 'account', $catalog->copyWithContext($id)['copy']) !== null);
		}
	}

	/**
	 * GET /api/export in both formats (ExportService::csvFor; the download
	 * response itself needs a Nextcloud server) lists the copies of the
	 * catalog list and never the accounts of who confirmed or set something.
	 * Choir members get no export.
	 *
	 * @param list<string> $role
	 */
	#[DataProvider('roles')]
	public function testTheExportHoldsTheCopiesOfTheListAndNoAccounts(array $role, bool $admin, bool $mayView): void {
		[$catalog, , $roles] = $this->services($role, $admin);
		$written = [];
		$export = $this->export($roles, $written);
		if (!self::hasAccess($role, $admin)) {
			self::assertSame(Http::STATUS_FORBIDDEN, $this->internal($catalog, $roles, $export)->export(1)->getStatus());
			return;
		}
		foreach ([false, true] as $extended) {
			[$marks, $accounts] = self::shelfmarks($export->csvFor('account', $extended));
			self::assertSame($mayView ? ['1-1', '1-2', '1-3'] : ['1-1'], $marks, 'extended ' . ($extended ? 'yes' : 'no'));
			self::assertFalse($accounts, 'extended ' . ($extended ? 'yes' : 'no'));
		}
	}

	/**
	 * Every role group reads the archive folder: what the app stores in its
	 * export folder (exports, the state before an import) holds no internal
	 * preservation copies and no accounts, whoever stores it. The complete
	 * state before an import goes into the backup folder.
	 */
	public function testTheExportFolderHoldsNoPreservationCopiesAndNoAccounts(): void {
		$this->services([Roles::LIBRARIAN]);
		$written = [];
		$backups = [];
		$export = $this->export($this->roleStub([Roles::LIBRARIAN]), $written, $backups);
		$export->store('export', 'account');
		$export->snapshot('before-import', 'account');
		self::assertCount(2, $written);
		foreach ($written as $name => $csv) {
			self::assertSame([['1-1'], false], self::shelfmarks($csv), $name);
		}
		self::assertCount(1, $backups);
		self::assertSame([['1-1', '1-2', '1-3'], true], self::shelfmarks((string)reset($backups)));
	}

	/**
	 * Tasks about internal preservation copies reach only the roles that may
	 * view them, in every list (the app, the API for agents, the dashboard).
	 *
	 * @param list<string> $role
	 */
	#[DataProvider('roles')]
	public function testTaskListsHoldTheSameCopies(array $role, bool $admin, bool $mayView): void {
		[, , $roles] = $this->services($role, $admin);
		$tasks = [];
		foreach ([self::ORDINARY, self::MARKED_VERSION, self::PRESERVATION_KIND] as $id) {
			$task = new Task();
			$task->setId(300 + $id);
			$task->setCopyId($id);
			$task->setStatus(Task::OPEN);
			$task->setAddressee($role[0] ?? Roles::LIBRARIAN);
			$tasks[] = $task;
		}
		$mapper = $this->createStub(TaskMapper::class);
		$mapper->method('findByStatus')->willReturn($tasks);
		$service = new TaskService($mapper, $this->copyMapper, $this->versionMapper, $this->workMapper,
			$this->createStub(\OCA\Cantoria\Service\AuditLog::class), $roles, $this->createStub(\OCA\Cantoria\Service\NotificationService::class),
			$this->createStub(IUserManager::class), $this->createStub(ITimeFactory::class));
		$expected = $mayView ? [self::ORDINARY, self::MARKED_VERSION, self::PRESERVATION_KIND] : [self::ORDINARY];
		$copyIds = fn (array $rows): array => array_map(fn (array $row): mixed => $row['copy_id'], $rows);
		self::assertSame($expected, $copyIds($service->all(true, 'account')), 'all tasks');
		self::assertSame($expected, $copyIds($service->all(false, 'account')), 'open tasks');
		// The mapper double returns the tasks of every addressee.
		self::assertSame($expected, $copyIds($service->forUser('account')), 'tasks of the account');
	}

	/**
	 * A task about an internal preservation copy is addressed to a role
	 * that may view it, and the other roles cannot open or answer it.
	 */
	public function testATaskAboutAPreservationCopyStaysWithTheRolesThatMayViewIt(): void {
		[, , $librarian] = $this->services([Roles::LIBRARIAN]);
		$mapper = $this->createStub(TaskMapper::class);
		$mapper->method('insert')->willReturnArgument(0);
		$service = fn (Roles $roles): TaskService => new TaskService($mapper, $this->copyMapper, $this->versionMapper, $this->workMapper,
			$this->createStub(\OCA\Cantoria\Service\AuditLog::class), $roles, $this->createStub(\OCA\Cantoria\Service\NotificationService::class),
			$this->createStub(IUserManager::class), $this->createStub(ITimeFactory::class));
		try {
			$service($librarian)->create(self::PRESERVATION_KIND, ['addressee' => Roles::READER, 'question' => 'Page 3?'], 'account');
			self::fail('a reader was addressed');
		} catch (\OCA\Cantoria\Exception\InvalidInputException) {
		}
		$task = $service($librarian)->create(self::PRESERVATION_KIND, ['addressee' => Roles::CONDUCTOR, 'question' => 'Page 3?'], 'account');
		self::assertSame(Roles::CONDUCTOR, $task->getAddressee());

		$task->setId(9);
		$mapper->method('find')->willReturn($task);
		self::assertSame($task, $service($librarian)->find(9, 'account'));
		$this->expectException(\OCA\Cantoria\Exception\AccessDeniedException::class);
		$service($this->roleStub([Roles::READER]))->find(9, 'account');
	}
}
