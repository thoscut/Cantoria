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
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\ReviewPointMapper;
use OCA\Cantoria\Db\TranscriptionReportMapper;
use OCA\Cantoria\Db\VersionState;
use OCA\Cantoria\Db\VersionStateMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\StateService;
use OCA\Cantoria\Tests\Unit\Support\ModuleSwitches;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Revisions of a version: the list of changed measures, the current
 * revision, the revision of a concert, and what a correction records.
 */
#[CoversClass(StateService::class)]
#[UsesClass(Modules::class)]
#[UsesClass(VersionState::class)]
final class StateServiceTest extends TestCase {
	use ModuleSwitches;

	/** @var list<VersionState> revisions of version 3 in the mapper */
	private array $stored = [];

	private static function state(int $id, int $number, ?string $validSince = null, int $fileId = 10, ?string $sha256 = 'aa'): VersionState {
		$state = new VersionState();
		$state->setId($id);
		$state->setVersionId(3);
		$state->setNumber($number);
		$state->setFileId($fileId);
		$state->setSha256($sha256);
		$state->setValidSince($validSince);
		$state->resetUpdatedFields();
		return $state;
	}

	private function service(?Modules $modules = null, ?AuditLog $audit = null, bool $viewer = true, ?CopyMapper $copies = null): StateService {
		$mapper = $this->createStub(VersionStateMapper::class);
		$mapper->method('findByVersion')->willReturnCallback(fn (): array => $this->stored);
		$mapper->method('find')->willReturnCallback(function (int $id): VersionState {
			foreach ($this->stored as $state) {
				if ($state->getId() === $id) {
					return $state;
				}
			}
			throw new \OCP\AppFramework\Db\DoesNotExistException('none');
		});
		$mapper->method('findByIds')->willReturnCallback(fn (array $ids): array => array_filter(array_column(
			array_map(fn (VersionState $s): array => ['id' => $s->getId(), 's' => $s], $this->stored), 's', 'id'),
			fn (int $id): bool => in_array($id, $ids, true), ARRAY_FILTER_USE_KEY));
		$mapper->method('update')->willReturnArgument(0);
		$mapper->method('insert')->willReturnCallback(function (VersionState $state): VersionState {
			$state->setId(100 + count($this->stored));
			$this->stored[] = $state;
			return $state;
		});
		$roles = $this->createStub(Roles::class);
		$roles->method('hasAnyRole')->willReturnCallback(fn (string $uid, array $allowed): bool => $viewer
			|| array_diff($allowed, \OCA\Cantoria\Service\RightsPolicy::PRESERVATION_COPY_VIEWERS) !== []);
		$version = new \OCA\Cantoria\Db\WorkVersion();
		$version->setId(3);
		$version->setPreservationCopy(false);
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('find')->willReturn($version);
		$users = $this->createStub(IUserManager::class);
		$users->method('getDisplayName')->willReturn('Catalog Librarian');
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_790_000_000);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-27 12:00:00'));
		$timeZone = $this->createStub(IDateTimeZone::class);
		$timeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return new StateService($this->createStub(IDBConnection::class), $mapper, $versions,
			$copies ?? $this->createStub(CopyMapper::class), $this->createStub(ArchiveFileMapper::class), $this->createStub(ReviewPointMapper::class),
			$this->createStub(TranscriptionReportMapper::class), $audit ?? $this->createStub(AuditLog::class), $roles, $modules ?? $this->modulesWithout(),
			$this->createStub(AppSettings::class), $users, $time, $timeZone, $l, $this->createStub(LoggerInterface::class));
	}

	public function testMeasuresAreListedShortly(): void {
		self::assertNull(StateService::measureList([]));
		self::assertSame('12', StateService::measureList(['12', '12']));
		self::assertSame('3, 12–14, 20a, 21', StateService::measureList(['3', '12', '13', '14', '13', '20a', '21']));
		self::assertSame('5, 4', StateService::measureList(['5', '4']), 'in the order of the changes');
	}

	public function testTheCurrentRevisionIsTheOneWithADay(): void {
		self::assertNull(StateService::current([self::state(1, 1), self::state(2, 2)]));
		self::assertSame(2, StateService::current([self::state(1, 1), self::state(2, 2, '2026-10-01')])?->getId());
	}

	public function testSettingTheCurrentRevisionClearsTheOldOne(): void {
		$this->stored = [self::state(1, 1, '2026-10-01'), self::state(2, 2)];
		$audit = $this->createMock(AuditLog::class);
		$audit->method('newOperation')->willReturn('op');
		$audit->expects(self::once())->method('record')->with('op', 'carla', 'version', 3, 'state_current', null, 1, 2, 'checked');
		$set = $this->service(null, $audit)->setCurrent(2, 'carla', ' checked ');
		self::assertSame('2026-10-27', $set->getValidSince());
		self::assertSame('Catalog Librarian', $set->getValidSetBy());
		self::assertNull($this->stored[0]->getValidSince());
		self::assertNull($this->stored[0]->getValidSetBy());
	}

	public function testTheCurrentRevisionIsNotSetTwice(): void {
		$this->stored = [self::state(1, 1, '2026-10-01')];
		$this->expectException(InvalidInputException::class);
		$this->service()->setCurrent(1, 'carla', null);
	}

	public function testTheSummaryNamesTheRevisionOfTheScoreShown(): void {
		$this->stored = [self::state(1, 1, '2026-10-01', 10), self::state(2, 2, null, 11)];
		self::assertSame(['count' => 2, 'latest' => 2, 'current' => ['id' => 1, 'number' => 1, 'valid_since' => '2026-10-01'], 'shown' => 2],
			$this->service()->summary(3, 11));
		self::assertNull($this->service()->summary(3, 99)['shown']);
	}

	public function testAConcertSingsTheCurrentRevisionElseTheNewest(): void {
		$this->stored = [self::state(1, 1, '2026-10-01'), self::state(2, 2)];
		self::assertSame(1, $this->service()->sungState(3)?->getId());
		$this->stored = [self::state(1, 1), self::state(2, 2)];
		self::assertSame(2, $this->service()->sungState(3)?->getId());
		self::assertNull($this->service($this->modulesWithout([Modules::VERSION_STATES]))->sungState(3), 'switched off: none');
	}

	public function testProgrammeItemsNameTheRevisionSungOrTheCurrentOne(): void {
		$this->stored = [self::state(1, 1), self::state(2, 2, '2026-10-01')];
		$sung = new EventItem();
		$sung->setId(7);
		$sung->setVersionId(3);
		$sung->setStateId(1);
		$planned = new EventItem();
		$planned->setId(8);
		$planned->setVersionId(3);
		$free = new EventItem();
		$free->setId(9);
		self::assertSame([
			7 => ['number' => 1, 'valid_since' => null, 'sung' => true],
			8 => ['number' => 2, 'valid_since' => '2026-10-01', 'sung' => false],
		], $this->service()->forItems([$sung, $planned, $free]));
	}

	public function testACorrectionRecordsTheCorrectedFileFirstWhenItHasNoRevision(): void {
		$this->stored = [];
		$copy = new Copy();
		$copy->setId(5);
		$version = new WorkVersion();
		$version->setId(3);
		$old = new ArchiveFile();
		$old->setId(10);
		$old->setName('1-2_Title.mxl');
		$new = new ArchiveFile();
		$new->setId(11);
		$new->setName('1-2_Title (corrected 2026-10-27).mxl');
		$changes = [
			['measure' => '12', 'part' => 'Tenor I/II', 'staff' => '1', 'voice' => '2', 'kind' => 'pitch', 'verse' => null, 'old' => 'D4', 'new' => 'E4'],
			['measure' => '13', 'part' => 'Bass', 'staff' => '1', 'voice' => '1', 'kind' => 'lyric', 'verse' => '1', 'old' => 'Lie', 'new' => 'Lieb'],
		];
		$state = $this->service()->recordCorrection($copy, $version, $old, 'aa', $new, 'bb', $changes, [], 'wrong note', 'carla', 'op');
		self::assertCount(2, $this->stored);
		self::assertSame([VersionState::FIRST, 1, 10, 'aa'], [$this->stored[0]->getSource(), $this->stored[0]->getNumber(), $this->stored[0]->getFileId(),
			$this->stored[0]->getSha256()]);
		self::assertSame([VersionState::CORRECTION, 2, 11, 'bb', '12–13', 'Tenor I/II, Bass', 'wrong note'], [$state?->getSource(), $state?->getNumber(),
			$state?->getFileId(), $state?->getSha256(), $state?->getMeasures(), $state?->getVoices(), $state?->getNote()]);
		self::assertStringContainsString('"kind":"lyric"', (string)$state?->getChanges());

		// The corrected file is known now: the next correction adds one revision
		$this->service()->recordCorrection($copy, $version, $new, 'bb', $old, 'cc', $changes, [], null, 'carla', 'op');
		self::assertCount(3, $this->stored);
		self::assertNull($this->service($this->modulesWithout([Modules::VERSION_STATES]))
			->recordCorrection($copy, $version, $new, 'cc', $old, 'dd', $changes, [], null, 'carla', 'op'), 'switched off: nothing recorded');
		self::assertCount(3, $this->stored);
	}

	/**
	 * One rule (RightsPolicy): the revisions of a version with a copy of
	 * the kind "Preservation copy" are only for the roles that may view it.
	 */
	public function testTheRevisionsOfAVersionWithACopyOfThePreservationKindAreOnlyForItsViewers(): void {
		$copy = new \OCA\Cantoria\Db\Copy();
		$copy->setVersionId(3);
		$copy->setKind(\OCA\Cantoria\Service\RightsPolicy::PRESERVATION_COPY_KIND);
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('findByVersion')->willReturn([$copy]);
		$this->stored = [self::state(1, 1)];
		self::assertSame(3, $this->service(copies: $copies)->forVersion(3, 'anna')['version_id']);
		self::assertSame(3, $this->service(viewer: false)->forVersion(3, 'anna')['version_id'], 'an ordinary version');
		$this->expectException(\OCA\Cantoria\Exception\AccessDeniedException::class);
		$this->service(viewer: false, copies: $copies)->forVersion(3, 'anna');
	}
}
