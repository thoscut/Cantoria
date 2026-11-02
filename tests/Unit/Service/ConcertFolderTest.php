<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\ArchiveFile;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\NotFoundException;
use OCA\Cantoria\Service\ConcertFolderService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\MusicXmlExcerpt;
use OCA\Cantoria\Service\ProgrammeCopies;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\ScanService;
use OCA\Cantoria\Service\ScoreService;
use OCA\Cantoria\Service\SharedLinkService;
use OCA\Cantoria\Tests\Unit\Support\ModuleSwitches;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The concert folder of the conductor (milestone 0.15): the programmes
 * still to be sung in their order, the rights of the score view, copies on
 * a device only with the module "offline" and the rights for them, and no
 * internal preservation copy at all (milestone 0.19).
 */
#[CoversClass(ConcertFolderService::class)]
#[UsesClass(Modules::class)]
#[UsesClass(ProgrammeCopies::class)]
#[UsesClass(MusicXmlExcerpt::class)]
#[UsesClass(SharedLinkService::class)]
final class ConcertFolderTest extends TestCase {
	use ModuleSwitches;

	private const SCORE = '<?xml version="1.0" encoding="UTF-8"?><score-partwise version="4.0"><part-list><score-part id="P1"><part-name>S</part-name></score-part></part-list>'
		. '<part id="P1"><measure number="1"><note><rest/><duration>4</duration></note></measure></part></score-partwise>';

	/**
	 * @param array<int, string> $viewDenials version id => reason
	 * @param array<int, string> $deviceDenials version id => why it may not be kept on a device
	 * @param list<string> $off modules switched off
	 */
	private function folder(array $viewDenials = [], bool $conductor = true, array $deviceDenials = [], array $off = []): ConcertFolderService {
		$roles = $this->createStub(Roles::class);
		$roles->method('hasAnyRole')->willReturn($conductor);
		$events = [
			self::event(1, 'fixed', '2026-12-20', 'Weihnachtskonzert'),
			self::event(2, 'sung', '2026-06-01', 'Sommerkonzert'),
			self::event(3, 'draft', '2026-11-15', 'Herbstkonzert'),
			self::event(4, 'cancelled', '2026-11-01', 'Abgesagt'),
		];
		$eventMapper = $this->createStub(EventMapper::class);
		$eventMapper->method('findByKind')->willReturn($events);
		$eventMapper->method('find')->willReturnCallback(fn (int $id): Event => $events[array_search($id, array_map(fn (Event $e): int => $e->getId(), $events), true)]);
		$items = [
			self::item(10, 3, 1, 21),
			self::item(11, 3, 2, 22),
			self::item(12, 3, 3, 21, struck: true),
			self::item(13, 1, 1, 23),
			self::item(14, 3, 4, null),
		];
		$itemMapper = $this->createStub(EventItemMapper::class);
		$itemMapper->method('findByEvent')->willReturnCallback(fn (int $id): array => array_values(array_filter($items, fn (EventItem $i): bool => $i->getEventId() === $id)));
		$itemMapper->method('find')->willReturnCallback(function (int $id) use ($items): EventItem {
			foreach ($items as $item) {
				if ($item->getId() === $id) {
					return $item;
				}
			}
			throw new DoesNotExistException('none');
		});
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('find')->willReturnCallback(fn (int $id): Copy => self::copy($id, 30 + $id - 20, $id === 23 ? RightsPolicy::PRESERVATION_COPY_KIND : 'singing'));
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('find')->willReturnCallback(fn (int $id): WorkVersion => self::version($id));
		$works = $this->createStub(WorkMapper::class);
		$works->method('find')->willReturnCallback(function (int $id): Work {
			$work = new Work();
			$work->setId($id);
			$work->setTitle('Werk ' . $id);
			$work->setComposer('Komponist');
			return $work;
		});
		$files = $this->createStub(ArchiveFileMapper::class);
		$files->method('findByCopy')->willReturnCallback(fn (int $copyId): array => $copyId === 22
			? [self::file(2, $copyId, 'score_pdf', 'b.pdf')]
			: [self::file(1, $copyId, 'musicxml', 'a.musicxml'), self::file(3, $copyId, 'score_pdf', 'z.pdf'), self::file(4, $copyId, 'score_pdf', 'a.pdf')]);
		$policy = $this->createStub(RightsPolicy::class);
		$policy->method('viewDenial')->willReturnCallback(fn (WorkVersion $version): ?string => $viewDenials[$version->getId()] ?? null);
		$policy->method('deviceCopyDenial')->willReturnCallback(fn (WorkVersion $version): ?string => $deviceDenials[$version->getId()] ?? null);
		$policy->method('playDenial')->willReturnCallback(fn (WorkVersion $version): ?string => $viewDenials[$version->getId()] ?? ($version->getId() === 31 ? 'Rights not clarified.' : null));
		$scores = $this->createStub(ScoreService::class);
		$scores->method('content')->willReturn(['content' => self::SCORE, 'mime' => 'application/vnd.recordare.musicxml+xml', 'name' => 'a.musicxml', 'type' => 'musicxml']);
		$scores->method('scoreText')->willReturn(['xml' => self::SCORE, 'entry' => null]);
		return new ConcertFolderService($roles, $eventMapper, $itemMapper, $versions, $works, $files, $policy, $scores,
			$this->createStub(ScanService::class), $this->modulesWithout($off), new ProgrammeCopies($copies, $files));
	}

	private static function event(int $id, string $status, string $date, string $title): Event {
		$event = new Event();
		$event->setId($id);
		$event->setStatus($status);
		$event->setEventDate($date);
		$event->setTitle($title);
		return $event;
	}

	private static function item(int $id, int $eventId, int $position, ?int $copyId, bool $struck = false): EventItem {
		$item = new EventItem();
		$item->setId($id);
		$item->setEventId($eventId);
		$item->setPosition($position);
		$item->setCopyId($copyId);
		$item->setStruck($struck);
		if ($copyId === null) {
			$item->setTitleText('Zugabe ohne Noten');
		}
		return $item;
	}

	private static function copy(int $id, int $versionId, string $kind): Copy {
		$copy = new Copy();
		$copy->setId($id);
		$copy->setVersionId($versionId);
		$copy->setKind($kind);
		$copy->setShelfmark('1-' . $id);
		return $copy;
	}

	private static function version(int $id): WorkVersion {
		$version = new WorkVersion();
		$version->setId($id);
		$version->setWorkId(100 + $id);
		return $version;
	}

	private static function file(int $id, int $copyId, string $role, string $name): ArchiveFile {
		$file = new ArchiveFile();
		$file->setId($id);
		$file->setFileId(4700 + $id);
		$file->setCopyId($copyId);
		$file->setRole($role);
		$file->setType($role === 'score_pdf' ? 'pdf' : 'musicxml');
		$file->setName($name);
		$file->setFound(true);
		return $file;
	}

	public function testTheProgrammesStillToBeSungComeInTheirOrderWithoutStruckItems(): void {
		$concerts = $this->folder()->concerts('carla');
		self::assertSame(['Herbstkonzert', 'Weihnachtskonzert'], array_column($concerts, 'title'), 'draft and fixed, the next first');
		self::assertSame([10, 11, 14], array_column($concerts[0]['items'], 'id'), 'struck items are left out');
		[$score, $scanOnly, $none] = $concerts[0]['items'];
		self::assertSame(['Werk 131', true, true, true, null], [$score['title'], $score['score'], $score['scan'], $score['offline'], $score['offlineReason']]);
		self::assertSame(SharedLinkService::practiceKey(self::version(31)), $score['practiceKey'], 'the same key as the shared pages');
		self::assertFalse($score['canPlay'], 'playback as in the score view');
		self::assertSame('Rights not clarified.', $score['reason']);
		self::assertSame([false, true, true], [$scanOnly['score'], $scanOnly['scan'], $scanOnly['offline']], 'a scan alone can be kept');
		self::assertSame(['Zugabe ohne Noten', false, false, false], [$none['title'], $none['score'], $none['scan'], $none['offline']]);
	}

	public function testAPreservationCopyIsNotOpenedForTheRehearsalAndTheConcert(): void {
		$item = $this->folder()->concerts('carla')[1]['items'][0];
		self::assertSame([false, false, false, false], [$item['score'], $item['scan'], $item['canPlay'], $item['offline']]);
		self::assertSame(ConcertFolderService::PRESERVATION_COPY, $item['reason'], 'it says why and what to do');
		$this->expectException(AccessDeniedException::class);
		$this->folder()->score(13, 'carla');
	}

	public function testAPieceIsKeptOnADeviceOnlyWithTheRightsForACopy(): void {
		$reason = 'Not available without a network: unless public domain is confirmed, a copy on a device needs a license.';
		$item = $this->folder([], true, [31 => $reason])->concerts('carla')[0]['items'][0];
		self::assertSame([true, false, $reason], [$item['score'], $item['offline'], $item['offlineReason']], 'viewing as in the score view, but no copy');
	}

	public function testWithoutTheModuleOfflineNothingIsKeptOnADevice(): void {
		$folder = $this->folder([], true, [], [Modules::OFFLINE]);
		self::assertFalse($folder->offlineOn());
		$item = $folder->concerts('carla')[0]['items'][0];
		self::assertSame([true, false, null], [$item['score'], $item['offline'], $item['offlineReason']], 'no reason per piece: the page offers nothing');
		self::assertTrue($this->folder()->offlineOn());
	}

	public function testWithoutTheRightToViewNothingIsShownOrKept(): void {
		$item = $this->folder([31 => 'You have no access to the music archive.'])->concerts('carla')[0]['items'][0];
		self::assertSame([false, false, false, false], [$item['score'], $item['scan'], $item['canPlay'], $item['offline']]);
		self::assertSame('You have no access to the music archive.', $item['reason']);
	}

	public function testTheScoreIsTheWholePieceAfterTheSameChecks(): void {
		self::assertStringContainsString('<score-partwise', $this->folder()->score(10, 'carla'));
		$cases = [
			'struck' => fn () => $this->folder()->score(12, 'carla'),
			'unknown' => fn () => $this->folder()->score(99, 'carla'),
			'no score' => fn () => $this->folder()->score(11, 'carla'),
			'no copy' => fn () => $this->folder()->score(14, 'carla'),
		];
		foreach ($cases as $case => $call) {
			try {
				$call();
				self::fail($case);
			} catch (NotFoundException $e) {
				self::assertNotSame('', $e->getMessage(), $case);
			}
		}
		$this->expectException(AccessDeniedException::class);
		$this->folder([], false)->score(10, 'singer');
	}

	public function testOnlyTheConductorAndTheLibrarianOpenIt(): void {
		self::assertSame([Roles::CONDUCTOR, Roles::LIBRARIAN], ConcertFolderService::ROLES);
		$this->expectException(AccessDeniedException::class);
		$this->folder([], false)->concerts('singer');
	}
}
