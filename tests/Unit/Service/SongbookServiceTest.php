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
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\ProgrammeCopies;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\ScanService;
use OCA\Cantoria\Service\SongbookService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Songbooks from a selection: the pieces of a programme in its order
 * without struck ones, the reasons for pieces that cannot go in, the scans
 * only after the same checks, and the record in the audit log.
 */
#[CoversClass(SongbookService::class)]
#[UsesClass(ProgrammeCopies::class)]
#[UsesClass(ScanService::class)]
final class SongbookServiceTest extends TestCase {
	private const NO_LICENSE = 'A songbook needs a license.';
	private const NO_SCAN = 'No scan (PDF) is linked to this copy: a songbook is made of scans.';

	/** @var list<array<int, mixed>> */
	private array $audited = [];

	/**
	 * Copies 31 (allowed, with a scan), 32 (no license), 33 (allowed, no scan),
	 * 34 (preservation copy), versions = copy - 10, works = version + 100.
	 */
	private function service(bool $maker = true, bool $preservationViewer = false, string $fileContent = "%PDF-1.7\n"): SongbookService {
		$event = new Event();
		$event->setId(4);
		$event->setTitle('Adventskonzert');
		$event->setEventDate('2026-12-13');
		$events = $this->createStub(EventMapper::class);
		$events->method('find')->willReturn($event);
		$items = $this->createStub(EventItemMapper::class);
		$items->method('findByEvent')->willReturn([self::item(1, 32), self::item(2, 31, encore: false), self::item(3, null, 'Ohne Noten'),
			self::item(4, 33, struck: true), self::item(5, 34), self::item(6, 31, encore: true)]);
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('find')->willReturnCallback(function (int $id): Copy {
			if ($id < 31 || $id > 34) {
				throw new DoesNotExistException('');
			}
			$copy = new Copy();
			$copy->setId($id);
			$copy->setVersionId($id - 10);
			$copy->setShelfmark('1-' . $id);
			return $copy;
		});
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('find')->willReturnCallback(function (int $id): WorkVersion {
			$version = new WorkVersion();
			$version->setId($id);
			$version->setWorkId($id + 100);
			$version->setPreservationCopy($id === 24);
			$version->setArrangerText($id === 21 ? 'Bearb. M. Muster' : null);
			return $version;
		});
		$works = $this->createStub(WorkMapper::class);
		$works->method('find')->willReturnCallback(function (int $id): Work {
			$work = new Work();
			$work->setId($id);
			$work->setTitle('Werk ' . $id);
			$work->setComposer('Komponist ' . $id);
			return $work;
		});
		$files = $this->createStub(ArchiveFileMapper::class);
		$files->method('findByCopy')->willReturnCallback(fn (int $copyId): array => match ($copyId) {
			31 => [self::file(70, 31, 'musicxml'), self::file(71, 31, 'score_pdf', found: false), self::file(72, 31, 'score_pdf')],
			32, 34 => [self::file(73, $copyId, 'score_pdf')],
			default => [],
		});
		$files->method('find')->willReturnCallback(fn (int $id): ArchiveFile => match ($id) {
			70 => self::file(70, 31, 'musicxml'),
			72 => self::file(72, 31, 'score_pdf'),
			73 => self::file(73, 32, 'score_pdf'),
			default => throw new DoesNotExistException(''),
		});
		$policy = $this->createStub(RightsPolicy::class);
		$policy->method('viewDenial')->willReturn(null);
		$policy->method('songbookDenial')->willReturnCallback(fn (WorkVersion $version, string $day, ?Copy $copy = null): ?string => match (true) {
			$version->getPreservationCopy() === true => 'Internal preservation copy: it does not go into a songbook.',
			$version->getId() === 22 => self::NO_LICENSE,
			default => null,
		});
		$rights = $this->createStub(RightsService::class);
		$rights->method('today')->willReturn('2026-11-10');
		$audit = $this->createStub(AuditLog::class);
		$audit->method('newOperation')->willReturn('op');
		$audit->method('record')->willReturnCallback(function (mixed ...$args): void {
			$this->audited[] = $args;
		});
		$roles = $this->createStub(Roles::class);
		$roles->method('hasAnyRole')->willReturnCallback(fn (string $uid, array $allowed): bool => $allowed === RightsPolicy::PRESERVATION_COPY_VIEWERS
			? $preservationViewer : $maker);
		$roles->method('requireRole')->willReturnCallback(function () use ($maker): void {
			if (!$maker) {
				throw new AccessDeniedException('This requires one of these roles: %s');
			}
		});
		$node = $this->createStub(File::class);
		$node->method('fopen')->willReturnCallback(function () use ($fileContent) {
			$handle = fopen('php://memory', 'w+');
			self::assertNotFalse($handle);
			fwrite($handle, $fileContent);
			rewind($handle);
			return $handle;
		});
		$folder = $this->createStub(Folder::class);
		$folder->method('getFirstNodeById')->willReturnCallback(fn (int $id): ?File => $id === 9072 ? $node : null);
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);
		return new SongbookService($events, $items, new ProgrammeCopies($copies, $files), $copies, $versions, $works, $files, $policy, $rights,
			$audit, $roles, $root);
	}

	private static function item(int $id, ?int $copyId, string $title = '', bool $struck = false, bool $encore = false): EventItem {
		$item = new EventItem();
		$item->setId($id);
		$item->setEventId(4);
		$item->setCopyId($copyId);
		$item->setTitleText($title);
		$item->setStruck($struck);
		$item->setEncore($encore);
		return $item;
	}

	private static function file(int $id, int $copyId, string $role, bool $found = true): ArchiveFile {
		$file = new ArchiveFile();
		$file->setId($id);
		$file->setCopyId($copyId);
		$file->setFileId(9000 + $id);
		$file->setRole($role);
		$file->setFound($found);
		return $file;
	}

	public function testAProgrammeGivesItsPiecesInOrderWithAReasonForEachLeftOut(): void {
		$plan = $this->service()->forEvent(4, 'lib');
		self::assertSame('Adventskonzert', $plan['title']);
		self::assertSame('2026-12-13', $plan['date']);
		$pieces = $plan['pieces'];
		self::assertSame([32, 31, null, null, 31], array_column($pieces, 'copyId'), 'the struck piece is left out; the encore keeps its place');
		self::assertSame([false, true, false, false, true], array_column($pieces, 'allowed'));
		self::assertSame(self::NO_LICENSE, $pieces[0]['reason']);
		self::assertSame('Werk 121', $pieces[1]['title']);
		self::assertSame('Komponist 121', $pieces[1]['composer']);
		self::assertSame('Bearb. M. Muster', $pieces[1]['arranger']);
		self::assertSame(72, $pieces[1]['scanFileId'], 'the first scan found in the archive folder, not the MusicXML');
		self::assertSame('Ohne Noten', $pieces[2]['title']);
		self::assertSame('No copy of this piece is in the catalog.', $pieces[2]['reason']);
		self::assertNull($pieces[3]['shelfmark'], 'neither shelf mark nor title of a preservation copy the account may not view');
		self::assertSame('', $pieces[3]['title']);
		self::assertSame('Encore', $pieces[4]['note']);
		self::assertNull($pieces[1]['note']);
	}

	public function testThePreservationCopyIsNamedToThoseWhoMayViewIt(): void {
		$pieces = $this->service(preservationViewer: true)->forEvent(4, 'lib')['pieces'];
		self::assertSame('1-34', $pieces[3]['shelfmark']);
		self::assertFalse($pieces[3]['allowed']);
		self::assertSame('Internal preservation copy: it does not go into a songbook.', $pieces[3]['reason']);
	}

	public function testACopyWithoutAScanCannotGoIn(): void {
		$pieces = $this->service()->forCopies([33, '31', 33, 999], 'lib')['pieces'];
		self::assertSame([33, 31], array_column($pieces, 'copyId'), 'in the order given, once each, unknown copies left out');
		self::assertFalse($pieces[0]['allowed']);
		self::assertSame(self::NO_SCAN, $pieces[0]['reason']);
		self::assertTrue($pieces[1]['allowed']);
	}

	public function testTheListOfCopiesIsChecked(): void {
		foreach ([[], ['x'], [0], [31, -2]] as $ids) {
			try {
				$this->service()->forCopies($ids, 'lib');
				self::fail('accepted ' . json_encode($ids));
			} catch (InvalidInputException $e) {
				self::assertSame('Please choose the copies for the songbook.', $e->getMessage());
			}
		}
		$this->expectException(InvalidInputException::class);
		$this->service()->forCopies(range(1, SongbookService::MAX_PIECES + 1), 'lib');
	}

	public function testOnlyTheLibrarianAndTheConductorMakeSongbooks(): void {
		$service = $this->service(maker: false);
		foreach ([fn () => $service->forEvent(4, 'singer'), fn () => $service->forCopies([31], 'singer'), fn () => $service->scan(72, 'singer'),
			fn () => $service->record([31], null, '', 'singer')] as $call) {
			try {
				$call();
				self::fail('no role check');
			} catch (AccessDeniedException) {
				self::addToAssertionCount(1);
			}
		}
		self::assertSame([], $this->audited);
	}

	public function testTheScanIsHandedOutOnlyAfterTheSameChecks(): void {
		self::assertInstanceOf(File::class, $this->service()->scan(72, 'lib'));
		try {
			$this->service()->scan(73, 'lib');
			self::fail('scan of a piece without a license');
		} catch (AccessDeniedException $e) {
			self::assertSame(self::NO_LICENSE, $e->getMessage());
		}
		try {
			$this->service()->scan(70, 'lib');
			self::fail('MusicXML handed out as a scan');
		} catch (InvalidInputException $e) {
			self::assertSame('This file is not a PDF scan.', $e->getMessage());
		}
		try {
			$this->service(fileContent: 'not a pdf')->scan(72, 'lib');
			self::fail('a file that is not a PDF');
		} catch (InvalidInputException $e) {
			self::assertSame('This file is not a PDF scan.', $e->getMessage());
		}
	}

	public function testMakingASongbookIsRecorded(): void {
		$this->service()->record([31, '32', 'x', 999], 4, '  Adventskonzert  ', 'lib');
		self::assertCount(1, $this->audited);
		self::assertSame(['op', 'lib', 'event', 4, 'songbook_made', null, null, '1-31, 1-32', 'Adventskonzert'], $this->audited[0]);

		$this->service()->record([31], null, '', 'lib');
		self::assertSame(['songbook', null, null], [$this->audited[1][2], $this->audited[1][3], $this->audited[1][8]]);

		$this->expectException(InvalidInputException::class);
		$this->service()->record([999], null, '', 'lib');
	}
}
