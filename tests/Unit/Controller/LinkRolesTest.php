<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Controller;

use OCA\Cantoria\Controller\EventController;
use OCA\Cantoria\Controller\TaskController;
use OCA\Cantoria\Db\ArchiveFile;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\ExcerptLink;
use OCA\Cantoria\Db\ExcerptLinkMapper;
use OCA\Cantoria\Db\License;
use OCA\Cantoria\Db\LicenseMapper;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\DueService;
use OCA\Cantoria\Service\EventService;
use OCA\Cantoria\Service\LinkService;
use OCA\Cantoria\Service\LinkTokens;
use OCA\Cantoria\Service\PerformanceImportService;
use OCA\Cantoria\Service\ProgrammeCopies;
use OCA\Cantoria\Service\ProgrammeShareService;
use OCA\Cantoria\Service\RepertoireService;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\ScanService;
use OCA\Cantoria\Service\ScoreService;
use OCA\Cantoria\Service\TaskService;
use OCA\Cantoria\Service\UserFiles;
use OCA\Cantoria\Tests\Unit\Support\FakeCrypto;
use OCA\Cantoria\Tests\Unit\Support\ModuleSwitches;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use OCP\Settings\IManager as ISettingsManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Sharing a programme and extending links through their routes, with the
 * real role checks, the real link service and the real rights policy
 * (T18): only the roles that make links get the options, share and
 * extend; every other role, choir members and administrators without a
 * role are refused. Extending checks the rights of today, also for public
 * links and for the scan a link offers. Only the mappers are test doubles.
 */
#[CoversClass(ProgrammeShareService::class)]
#[CoversClass(LinkService::class)]
#[CoversClass(EventController::class)]
#[CoversClass(TaskController::class)]
#[UsesClass(RightsPolicy::class)]
#[UsesClass(RightsService::class)]
#[UsesClass(Roles::class)]
#[UsesClass(ProgrammeCopies::class)]
#[UsesClass(LinkTokens::class)]
#[UsesClass(\OCA\Cantoria\Service\MusicXmlExcerpt::class)]
final class LinkRolesTest extends TestCase {
	use ModuleSwitches;

	private const TODAY = '2026-11-03';
	/** Copy 31: protected, licensed for the portal until 2027-03-31; copy 32: a preservation copy; copy 33: public link, rights withdrawn */
	private const LICENSED = 31;
	private const PRESERVATION = 32;
	private const WITHDRAWN = 33;

	private const SCORE = '<?xml version="1.0" encoding="UTF-8"?><score-partwise version="4.0"><part-list><score-part id="P1"><part-name>Alto</part-name></score-part></part-list>'
		. '<part id="P1"><measure number="1"><attributes><divisions>1</divisions></attributes><note><pitch><step>C</step><octave>5</octave></pitch><duration>4</duration></note></measure></part></score-partwise>';

	/** @var array<int, ExcerptLink> */
	private array $stored = [];

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function accounts(): array {
		$accounts = [];
		foreach (Roles::GROUP_ROLES as $role) {
			$accounts[$role] = [$role, in_array($role, LinkService::CREATORS, true)];
		}
		$accounts['administrator without a role'] = ['admin', false];
		return $accounts;
	}

	/**
	 * Each account holds the role it is named after; "admin" is an
	 * administrator without a role.
	 */
	private function roles(): Roles {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueArray')->willReturnCallback(fn (string $app, string $key): array => [substr($key, strlen('groups_'))]);
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => $uid === $group);
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $uid === 'admin');
		return new Roles($config, $groups, $this->createStub(IUserManager::class), $this->createStub(ISettingsManager::class), $this->createStub(LoggerInterface::class));
	}

	private function l10n(): IL10N {
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return $l;
	}

	/**
	 * @return array{EventController, TaskController}
	 */
	private function controllers(string $uid): array {
		$roles = $this->roles();
		$versions = [];
		$copies = [];
		foreach ([self::LICENSED => ['protected', null], self::PRESERVATION => ['public_domain', RightsPolicy::PRESERVATION_COPY_KIND],
			self::WITHDRAWN => ['open', null]] as $id => [$status, $kind]) {
			$version = new WorkVersion();
			$version->setId($id - 10);
			$version->setWorkId($id + 100);
			$version->setRightsStatus($status);
			$version->setPublicationStatus('free');
			$versions[$version->getId()] = $version;
			$copy = new Copy();
			$copy->setId($id);
			$copy->setVersionId($id - 10);
			$copy->setShelfmark('1-' . $id);
			$copy->setKind($kind);
			$copies[$id] = $copy;
		}
		$copyMapper = $this->createStub(CopyMapper::class);
		$copyMapper->method('find')->willReturnCallback(fn (int $id): Copy => $copies[$id] ?? throw new DoesNotExistException('none'));
		$versionMapper = $this->createStub(WorkVersionMapper::class);
		$versionMapper->method('find')->willReturnCallback(fn (int $id): WorkVersion => $versions[$id] ?? throw new DoesNotExistException('none'));
		$works = $this->createStub(WorkMapper::class);
		$works->method('find')->willReturnCallback(fn (int $id): Work => Work::fromParams(['id' => $id, 'title' => 'Werk ' . $id]));
		$portal = License::fromParams(['versionId' => self::LICENSED - 10, 'uses' => 'portal', 'validUntil' => '2027-03-31']);
		$licenses = $this->createStub(LicenseMapper::class);
		$licenses->method('findByVersion')->willReturnCallback(fn (int $id): array => $id === self::LICENSED - 10 ? [$portal] : []);
		$score = ArchiveFile::fromParams(['id' => 4, 'copyId' => self::LICENSED, 'role' => 'musicxml', 'name' => 'a.musicxml', 'etag' => 'e']);
		$scan = ArchiveFile::fromParams(['id' => 5, 'copyId' => self::LICENSED, 'role' => 'score_pdf', 'name' => 'a.pdf', 'found' => true]);
		$files = $this->createStub(ArchiveFileMapper::class);
		$files->method('findByCopy')->willReturn([$score, $scan]);
		$files->method('find')->willReturnCallback(fn (int $id): ArchiveFile => [4 => $score, 5 => $scan][$id] ?? throw new DoesNotExistException('none'));
		$scores = $this->createStub(ScoreService::class);
		$scores->method('describe')->willReturn(['fileId' => 4, 'fileName' => 'a.musicxml', 'canView' => true, 'canPlay' => false, 'reason' => null,
			'canExportAudio' => false, 'exportReason' => null]);
		$scores->method('content')->willReturn(['content' => self::SCORE, 'mime' => 'application/xml', 'name' => 'a.musicxml', 'type' => 'musicxml']);
		$scores->method('scoreText')->willReturn(['xml' => self::SCORE, 'entry' => null]);
		$links = $this->createStub(ExcerptLinkMapper::class);
		$links->method('find')->willReturnCallback(fn (int $id): ExcerptLink => $this->stored[$id] ?? throw new DoesNotExistException('none'));
		$links->method('findByCopy')->willReturnCallback(fn (?int $copyId): array => array_values(array_filter($this->stored,
			fn (ExcerptLink $link): bool => $link->getCopyId() === $copyId)));
		$links->method('update')->willReturnArgument(0);
		$links->method('insert')->willReturnArgument(0);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn (): \DateTime => new \DateTime(self::TODAY . ' 12:00:00'));
		$time->method('getTime')->willReturn((int)strtotime(self::TODAY . ' 12:00:00'));
		$zone = $this->createStub(IDateTimeZone::class);
		$zone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$audit = $this->createStub(AuditLog::class);
		$rights = new RightsService($this->createStub(IDBConnection::class), $versionMapper, $licenses, $audit, $roles,
			$this->createStub(IUserManager::class), $time, $zone);
		$policy = new RightsPolicy($roles, $rights, $licenses);
		$random = $this->createStub(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('n', 32));
		$linkService = new LinkService($links, $copyMapper, $versionMapper, $files, $scores, $policy, $rights, $audit, $roles,
			$this->createStub(IUserManager::class), new LinkTokens(new FakeCrypto(), $random), $time, $this->modulesWithout(), $this->createStub(ScanService::class));

		$event = Event::fromParams(['id' => 4, 'eventDate' => '2026-12-13']);
		$events = $this->createStub(EventMapper::class);
		$events->method('find')->willReturn($event);
		$items = $this->createStub(EventItemMapper::class);
		$items->method('findByEvent')->willReturn([EventItem::fromParams(['id' => 1, 'eventId' => 4, 'copyId' => self::LICENSED]),
			EventItem::fromParams(['id' => 2, 'eventId' => 4, 'copyId' => self::PRESERVATION, 'titleText' => 'Erhaltungskopie'])]);
		$share = new ProgrammeShareService($events, $items, new ProgrammeCopies($copyMapper, $files), $versionMapper, $works, $links, $linkService, $rights, $roles);

		$eventController = new EventController($this->createStub(IRequest::class), $uid, $roles, $this->createStub(EventService::class), $items,
			$this->createStub(PerformanceImportService::class), $share, $this->createStub(RepertoireService::class), $this->createStub(UserFiles::class),
			$this->l10n(), $this->createStub(LoggerInterface::class));
		$taskController = new TaskController($this->createStub(IRequest::class), $uid, $roles, $this->createStub(TaskService::class), $linkService,
			$this->createStub(DueService::class), $this->l10n(), $this->createStub(LoggerInterface::class));
		return [$eventController, $taskController];
	}

	private function store(int $id, int $copyId, string $circle, string $creator, string $expires, ?int $scan = null): void {
		$this->stored[$id] = ExcerptLink::fromParams(['id' => $id, 'copyId' => $copyId, 'circle' => $circle, 'createdBy' => $creator,
			'created' => (int)strtotime('2026-10-01 12:00:00'), 'expires' => $expires, 'scanFileId' => $scan, 'token' => 'kept']);
	}

	#[DataProvider('accounts')]
	public function testOnlyTheRolesThatMakeLinksShareAProgrammeAndExtendLinks(string $uid, bool $allowed): void {
		$this->store(9, self::LICENSED, ExcerptLink::CIRCLE_MEMBERS, $uid, '2026-11-20');
		[$events, $tasks] = $this->controllers($uid);
		$expected = $allowed ? Http::STATUS_OK : Http::STATUS_FORBIDDEN;
		self::assertSame($expected, $events->shareOptions(4)->getStatus(), 'share-options');
		$shared = $events->share(4, [1], '2026-12-20');
		self::assertSame($expected, $shared->getStatus(), 'share');
		if ($allowed) {
			self::assertSame([1, []], [$shared->getData()['extended'] ?? null, $shared->getData()['failed'] ?? null], 'its own link is extended');
		}
		self::assertSame($expected, $tasks->extendLink(9, '2026-12-27')->getStatus(), 'extend');
		self::assertSame($allowed ? '2026-12-27' : '2026-11-20', $this->stored[9]->getExpires());
	}

	public function testTheOptionsHideAPreservationCopyFromTheOperationsRole(): void {
		$items = $this->controllers(Roles::OPERATIONS)[0]->shareOptions(4)->getData()['items'] ?? [];
		self::assertIsArray($items[1] ?? null);
		self::assertSame([null, null, false], [$items[1]['shelfmark'], $items[1]['copyId'], $items[1]['allowed']], 'S N13');
		self::assertSame('1-32', $this->controllers(Roles::LIBRARIAN)[0]->shareOptions(4)->getData()['items'][1]['shelfmark'] ?? null);
	}

	public function testSharingAProgrammeKeepsTheLicenseEnd(): void {
		$data = $this->controllers(Roles::CONDUCTOR)[0]->share(4, [1], '2027-04-30')->getData();
		self::assertSame([1, [['id' => 1, 'title' => 'Werk 131', 'expires' => '2027-03-31']]], [$data['created'] ?? null, $data['limited'] ?? null],
			'the link lasts until the license for the portal ends');
	}

	public function testExtendingChecksTheRightsOfTodayForEveryCircleAndTheScan(): void {
		$this->store(9, self::WITHDRAWN, ExcerptLink::CIRCLE_PUBLIC, 'conductor', '2026-11-20');
		$response = $this->controllers('conductor')[1]->extendLink(9, '2026-12-27');
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), 'a public link whose public domain was withdrawn');
		self::assertSame('2026-11-20', $this->stored[9]->getExpires());

		$this->store(10, self::LICENSED, ExcerptLink::CIRCLE_MEMBERS, 'conductor', '2026-11-20', 5);
		$response = $this->controllers('conductor')[1]->extendLink(10, '2026-12-27');
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), 'a scan without a license for download and printing');
		self::assertStringContainsString('download and printing', (string)($response->getData()['error'] ?? ''));
	}
}
