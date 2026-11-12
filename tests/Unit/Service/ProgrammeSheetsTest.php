<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\Contribution;
use OCA\Cantoria\Db\ContributionMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\Person;
use OCA\Cantoria\Db\PersonMapper;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\NotFoundException;
use OCA\Cantoria\Service\ProgrammeCopies;
use OCA\Cantoria\Service\ProgrammeSheets;
use OCA\Cantoria\Service\ProtectionTerm;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\ScoreFactsService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The pitch sheet and the programme booklet: struck pieces are left out,
 * facts of a preservation copy stay with the roles that may view it, and
 * lyrics and translation are only handed out where RightsPolicy allows
 * printing them.
 */
#[CoversClass(ProgrammeSheets::class)]
final class ProgrammeSheetsTest extends TestCase {
	private const DENIED = 'Printing lyrics in a booklet needs a license.';
	private const HIDDEN = 'Internal preservation copy.';

	/**
	 * Event 5 has five items: 1 free (version 21, copy 31), 2 protected
	 * (22, 32), 3 struck, 4 a preservation copy (24, 34), and 5 a piece that
	 * is not in the catalog.
	 *
	 * @param list<string> $roles roles of the account
	 */
	private function sheets(array $roles = [Roles::CONDUCTOR]): ProgrammeSheets {
		$events = $this->createStub(EventMapper::class);
		$events->method('find')->willReturnCallback(function (int $id): Event {
			if ($id !== 5) {
				throw new DoesNotExistException('');
			}
			$event = new Event();
			$event->setId(5);
			$event->setKind('concert');
			$event->setTitle('Advent');
			$event->setEventDate('2026-12-06');
			return $event;
		});
		$items = $this->createStub(EventItemMapper::class);
		$items->method('findByEvent')->willReturn([
			self::item(1, 31), self::item(2, 32), self::item(3, 31, struck: true), self::item(4, 34), self::item(5, null),
		]);
		$copies = $this->createStub(ProgrammeCopies::class);
		$copies->method('copyOf')->willReturnCallback(function (EventItem $item): ?Copy {
			if ($item->getCopyId() === null) {
				return null;
			}
			$copy = new Copy();
			$copy->setId($item->getCopyId());
			$copy->setVersionId($item->getCopyId() - 10);
			$copy->setShelfmark('1-' . $item->getCopyId());
			return $copy;
		});
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('find')->willReturnCallback(function (int $id): WorkVersion {
			$version = new WorkVersion();
			$version->setId($id);
			$version->setWorkId($id + 20);
			$version->setLyricsTranslation('Translation ' . $id);
			$version->setPreservationCopy($id === 24);
			$version->setDuration(200);
			return $version;
		});
		$works = $this->createStub(WorkMapper::class);
		$works->method('find')->willReturnCallback(function (int $id): Work {
			$work = new Work();
			$work->setId($id);
			$work->setTitle('Work ' . $id);
			$work->setComposer('Composer ' . $id);
			return $work;
		});
		$contributions = $this->createStub(ContributionMapper::class);
		$contributions->method('findByVersion')->willReturnCallback(function (int $versionId): array {
			$list = [];
			foreach (['lyricist' => 2, 'editor' => 3, 'composer' => 1] as $role => $personId) {
				$contribution = new Contribution();
				$contribution->setVersionId($versionId);
				$contribution->setRole($role);
				$contribution->setPersonId($personId);
				$list[] = $contribution;
			}
			return $list;
		});
		$persons = $this->createStub(PersonMapper::class);
		$persons->method('find')->willReturnCallback(function (int $id): Person {
			$person = new Person();
			$person->setId($id);
			$person->setName('Person ' . $id);
			$person->setBorn($id === 1 ? '1685-03-21' : null);
			$person->setDied($id === 1 ? '1750' : null);
			return $person;
		});
		$facts = $this->createStub(ScoreFactsService::class);
		$facts->method('forCopies')->willReturnCallback(fn (array $copyIds): array => array_fill_keys($copyIds, self::facts()));
		$policy = $this->createStub(RightsPolicy::class);
		$policy->method('viewDenial')->willReturnCallback(
			fn (WorkVersion $version): ?string => $version->getId() === 24 && !in_array(Roles::CONDUCTOR, $roles, true) ? self::HIDDEN : null);
		$policy->method('bookletLyricsDenial')->willReturnCallback(fn (WorkVersion $version): ?string => match ($version->getId()) {
			21 => null,
			24 => 'Internal preservation copy: its lyrics are not printed.',
			default => self::DENIED,
		});
		$rights = $this->createStub(RightsService::class);
		$rights->method('today')->willReturn('2026-11-12');
		$roleService = $this->createStub(Roles::class);
		$roleService->method('requireAccess')->willReturnCallback(function () use ($roles): void {
			if ($roles === []) {
				throw new AccessDeniedException('You have no access to the music archive.');
			}
		});
		$roleService->method('requireRole')->willReturnCallback(function (string $uid, array $allowed) use ($roles): void {
			if (array_intersect($roles, $allowed) === []) {
				throw new AccessDeniedException('This requires one of these roles: %s');
			}
		});
		return new ProgrammeSheets($events, $items, $copies, $versions, $works, $contributions, $persons, $facts, $policy, $rights, $roleService);
	}

	private static function item(int $id, ?int $copyId, bool $struck = false): EventItem {
		$item = new EventItem();
		$item->setId($id);
		$item->setEventId(5);
		$item->setPosition($id);
		$item->setCopyId($copyId);
		$item->setStruck($struck);
		if ($copyId === null) {
			$item->setTitleText('Happy Birthday');
			$item->setComposerText('Trad.');
		}
		return $item;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function facts(): array {
		return [
			'format' => 1,
			'voices' => [['name' => 'Sopran', 'group' => 'S', 'part' => 'P1', 'low' => 60, 'high' => 79, 'tessLow' => 64, 'tessHigh' => 74,
				'first' => 67, 'last' => 72, 'notes' => 40]],
			'keyStart' => ['fifths' => 1, 'mode' => 'major'],
			'keyEnd' => ['fifths' => 1, 'mode' => 'major'],
			'time' => '4/4',
			'tempo' => 72,
			'measures' => 24,
			'incipit' => ['clef' => 'G-2', 'keysig' => 'xF', 'timesig' => '4/4', 'data' => "4''G"],
			'lyrics' => ['voice' => 'Sopran', 'verses' => [['number' => '1', 'text' => 'Macht hoch die Tür']]],
		];
	}

	public function testThePitchSheetLeavesOutStruckPiecesAndGivesTheFirstTones(): void {
		$sheet = $this->sheets()->pitchSheet(5, 'carla');
		self::assertSame('Advent', $sheet['event']['title']);
		self::assertSame([1, 2, 4, 5], array_column($sheet['pieces'], 'itemId'), 'the struck piece is left out');
		$first = $sheet['pieces'][0];
		self::assertSame('Work 41', $first['title']);
		self::assertSame('1-31', $first['shelfmark']);
		self::assertSame(200, $first['duration'], 'the duration of the version when the programme gives none');
		self::assertSame([['name' => 'Sopran', 'group' => 'S', 'first' => 67, 'last' => 72, 'low' => 60, 'high' => 79]], $first['facts']['voices']);
		self::assertArrayNotHasKey('lyrics', $first['facts'], 'the pitch sheet carries no lyrics');
		$free = $sheet['pieces'][3];
		self::assertSame(['Happy Birthday', 'Trad.', null, null], [$free['title'], $free['composer'], $free['copyId'], $free['facts']]);
	}

	public function testThePitchSheetHidesTheFactsOfAPreservationCopyFromOtherRoles(): void {
		self::assertNotNull($this->sheets([Roles::CONDUCTOR])->pitchSheet(5, 'carla')['pieces'][2]['facts']);
		$sheet = $this->sheets([Roles::READER])->pitchSheet(5, 'rita');
		self::assertNull($sheet['pieces'][2]['facts'], 'no incipit and no tones of a preservation copy');
		self::assertNotNull($sheet['pieces'][0]['facts']);
	}

	public function testThePitchSheetNeedsAccessAndAnExistingEvent(): void {
		try {
			$this->sheets([])->pitchSheet(5, 'nobody');
			self::fail('no access without a role');
		} catch (AccessDeniedException) {
		}
		$this->expectException(NotFoundException::class);
		$this->sheets()->pitchSheet(6, 'carla');
	}

	public function testTheBookletPrintsLyricsOnlyWhereTheRightsAllow(): void {
		$booklet = $this->sheets()->booklet(5, 'carla');
		[$free, $protected, $preservation, $unknown] = $booklet['pieces'];

		self::assertSame([['number' => '1', 'text' => 'Macht hoch die Tür']], $free['lyrics']);
		self::assertSame('Translation 21', $free['translation']);
		self::assertNull($free['lyricsReason']);

		self::assertNull($protected['lyrics'], 'protected lyrics without a license for publication stay in the archive');
		self::assertNull($protected['translation'], 'and so does their translation');
		self::assertSame(self::DENIED, $protected['lyricsReason']);
		self::assertSame('Work 42', $protected['title'], 'the piece itself is listed');

		self::assertNull($preservation['lyrics']);
		self::assertNull($preservation['translation']);
		self::assertSame('Internal preservation copy: its lyrics are not printed.', $preservation['lyricsReason']);

		self::assertNull($unknown['lyrics']);
		self::assertSame('The piece is not in the catalog.', $unknown['lyricsReason']);
	}

	public function testTheBookletListsThePeopleWithTheirYearsInTheOrderOfTheRoles(): void {
		$persons = $this->sheets()->booklet(5, 'carla')['pieces'][0]['persons'];
		self::assertSame([
			['role' => 'composer', 'name' => 'Person 1', 'born' => '1685', 'died' => '1750'],
			['role' => 'lyricist', 'name' => 'Person 2', 'born' => null, 'died' => null],
		], $persons, 'editors are not named in a booklet');
	}

	public function testAPieceWithoutTextGivesNoReason(): void {
		$sheets = $this->sheets();
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('find')->willReturnCallback(function (int $id): WorkVersion {
			$version = new WorkVersion();
			$version->setId($id);
			$version->setTextOrigin(ProtectionTerm::NO_TEXT);
			return $version;
		});
		(new \ReflectionProperty(ProgrammeSheets::class, 'versions'))->setValue($sheets, $versions);
		$piece = $sheets->booklet(5, 'carla')['pieces'][1];
		self::assertNull($piece['lyrics']);
		self::assertNull($piece['lyricsReason'], 'an instrumental piece needs no explanation');
	}

	public function testTheBookletIsMadeByTheConductorAndTheCatalogLibrarianOnly(): void {
		self::assertSame([Roles::LIBRARIAN, Roles::CONDUCTOR], ProgrammeSheets::BOOKLET_MAKERS);
		$this->sheets([Roles::LIBRARIAN])->booklet(5, 'lea');
		foreach ([Roles::READER, Roles::OPERATIONS, Roles::CONTRIBUTOR] as $role) {
			try {
				$this->sheets([$role])->booklet(5, 'someone');
				self::fail($role . ' makes no booklet');
			} catch (AccessDeniedException) {
			}
		}
		$this->expectException(NotFoundException::class);
		$this->sheets()->booklet(6, 'carla');
	}
}
