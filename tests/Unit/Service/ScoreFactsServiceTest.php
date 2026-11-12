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
use OCA\Cantoria\Db\ScoreFact;
use OCA\Cantoria\Db\ScoreFactMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\ScoreFacts;
use OCA\Cantoria\Service\ScoreFactsService;
use OCA\Cantoria\Service\ScoreService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The facts of the scores for the lists and the choir profile: what the
 * catalog gets (no lyrics, no preservation copies for other roles), when a
 * file is read again, and who saves which profile.
 */
#[CoversClass(ScoreFactsService::class)]
final class ScoreFactsServiceTest extends TestCase {
	private const SCORE = <<<'XML'
		<?xml version="1.0" encoding="UTF-8"?>
		<score-partwise version="4.0"><part-list><score-part id="P1"><part-name>Sopran</part-name></score-part></part-list>
		<part id="P1"><measure number="1"><attributes><divisions>1</divisions><key><fifths>0</fifths></key>
		<time><beats>4</beats><beat-type>4</beat-type></time><clef><sign>G</sign><line>2</line></clef></attributes>
		<note><pitch><step>C</step><octave>4</octave></pitch><duration>2</duration><type>half</type><lyric><syllabic>single</syllabic><text>Geheim</text></lyric></note>
		<note><pitch><step>G</step><octave>4</octave></pitch><duration>2</duration><type>half</type></note></measure></part></score-partwise>
		XML;

	/** @var list<array<int, mixed>> */
	private array $audited = [];
	/** @var array<string, string> */
	private array $config = [];
	/** @var array<int, ScoreFact> archive file id => stored facts */
	private array $stored = [];

	/**
	 * Copies 31 and 32 have a MusicXML file each (files 41, 42); copy 32 is of
	 * the kind "Preservation copy". Copy 33 has a file that was not found.
	 *
	 * @param list<string> $roles roles of the account
	 */
	private function service(array $roles = [Roles::CONDUCTOR]): ScoreFactsService {
		$facts = $this->createStub(ScoreFactMapper::class);
		$facts->method('findByFile')->willReturnCallback(fn (int $id): ?ScoreFact => $this->stored[$id] ?? null);
		$facts->method('findAllByFile')->willReturnCallback(fn (): array => $this->stored);
		$facts->method('findByFiles')->willReturnCallback(fn (array $ids): array => array_intersect_key($this->stored, array_flip($ids)));
		$facts->method('insert')->willReturnCallback(function (ScoreFact $fact): ScoreFact {
			$this->stored[$fact->getArchiveFileId()] = $fact;
			return $fact;
		});
		$facts->method('update')->willReturnCallback(fn (ScoreFact $fact): ScoreFact => $fact);
		$files = $this->createStub(ArchiveFileMapper::class);
		$files->method('findMusicxmlPerCopy')->willReturn([
			31 => [self::file(41)], 32 => [self::file(42)], 33 => [self::file(43, found: false)],
		]);
		$scores = $this->createStub(ScoreService::class);
		$scores->method('isScore')->willReturnCallback(fn (string $content): bool => str_contains($content, '<score-partwise'));
		$scores->method('scoreText')->willReturnCallback(fn (string $content): array => ['xml' => $content, 'entry' => null]);
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('findAll')->willReturnCallback(fn (): array => array_map(function (int $id): Copy {
			$copy = new Copy();
			$copy->setId($id);
			$copy->setVersionId($id - 10);
			$copy->setKind($id === 32 ? RightsPolicy::PRESERVATION_COPY_KIND : 'original');
			return $copy;
		}, [31, 32, 33]));
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('findAllById')->willReturnCallback(function (): array {
			$list = [];
			foreach ([21, 22, 23] as $id) {
				$list[$id] = new WorkVersion();
				$list[$id]->setId($id);
			}
			return $list;
		});
		$roleService = $this->createStub(Roles::class);
		$roleService->method('requireAccess')->willReturnCallback(function () use ($roles): void {
			if ($roles === []) {
				throw new AccessDeniedException('You have no access to the music archive.');
			}
		});
		$roleService->method('hasAnyRole')->willReturnCallback(fn (string $uid, array $allowed): bool => array_intersect($roles, $allowed) !== []);
		$roleService->method('requireRole')->willReturnCallback(function (string $uid, array $allowed) use ($roles): void {
			if (array_intersect($roles, $allowed) === []) {
				throw new AccessDeniedException('This requires one of these roles: %s');
			}
		});
		$policy = $this->createStub(RightsPolicy::class);
		$policy->method('mayViewPreservationCopies')->willReturnCallback(
			fn (): bool => array_intersect($roles, RightsPolicy::PRESERVATION_COPY_VIEWERS) !== []);
		$audit = $this->createStub(AuditLog::class);
		$audit->method('newOperation')->willReturn('op');
		$audit->method('record')->willReturnCallback(function (...$arguments): void {
			$this->audited[] = $arguments;
		});
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;
			return true;
		});
		$config->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->config[$key]);
		});
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_800_000_000);
		return new ScoreFactsService($facts, $files, $scores, $copies, $versions, $policy, $this->createStub(AppSettings::class),
			$roleService, $audit, $config, $time, $this->createStub(LoggerInterface::class));
	}

	private static function file(int $id, bool $found = true): ArchiveFile {
		$file = new ArchiveFile();
		$file->setId($id);
		$file->setFileId($id + 1000);
		$file->setRole('musicxml');
		$file->setType('musicxml');
		$file->setName('score' . $id . '.musicxml');
		$file->setFound($found);
		return $file;
	}

	public function testTheCatalogGetsRangesAndIncipitButNoLyrics(): void {
		$service = $this->service();
		$service->remember(41, self::SCORE);
		$catalog = $service->forCatalog('carla');
		self::assertSame([31], array_keys($catalog));
		self::assertSame(['voices', 'keyStart', 'incipit', 'measures'], array_keys($catalog[31]));
		self::assertSame(['name' => 'Sopran', 'group' => 'S', 'low' => 60, 'high' => 67, 'tessLow' => 60, 'tessHigh' => 67], $catalog[31]['voices'][0]);
		self::assertStringNotContainsString('Geheim', json_encode($catalog, JSON_THROW_ON_ERROR), 'lyrics never reach the catalog');
		self::assertSame('Geheim', $service->forCopies([31])[31]['lyrics']['verses'][0]['text'], 'the booklet reads them through the rights check');
	}

	public function testFactsOfAPreservationCopyAreOnlyForTheRolesThatMayViewIt(): void {
		$service = $this->service([Roles::CONDUCTOR]);
		$service->remember(41, self::SCORE);
		$service->remember(42, self::SCORE);
		self::assertSame([31, 32], array_keys($service->forCatalog('carla')));
		foreach ([Roles::READER, Roles::OPERATIONS, Roles::CONTRIBUTOR] as $role) {
			self::assertSame([31], array_keys($this->service([$role])->forCatalog('someone')), $role . ' sees no incipit of a preservation copy');
		}
		$this->expectException(AccessDeniedException::class);
		$this->service([])->forCatalog('nobody');
	}

	public function testAScoreIsReadOncePerContentAndAFileThatIsNoScoreIsRemembered(): void {
		$service = $this->service();
		self::assertSame(['files' => 2, 'read' => 0], $service->summary(), 'a file that was not found does not count');
		$service->remember(41, self::SCORE);
		$first = $this->stored[41];
		self::assertSame(ScoreFacts::FORMAT, $first->getFormat());
		self::assertSame(hash('sha256', self::SCORE), $first->getSha256());
		self::assertSame(1_800_000_000, $first->getAnalyzedAt());
		$first->setAnalyzedAt(1);
		$service->remember(41, self::SCORE);
		self::assertSame(1, $this->stored[41]->getAnalyzedAt(), 'the same content is not read again');
		$service->remember(41, str_replace('Geheim', 'Offen', self::SCORE));
		self::assertSame(1_800_000_000, $this->stored[41]->getAnalyzedAt(), 'a changed file is');

		$service->remember(42, 'not a score');
		self::assertNull($this->stored[42]->getFacts());
		self::assertSame(['files' => 2, 'read' => 2], $service->summary(), 'a file without facts is not tried on every run');
		self::assertSame([31], array_keys($service->forCopies()));
	}

	public function testTheDefaultProfileIsAMixedChoirUntilOneIsSaved(): void {
		$service = $this->service();
		self::assertSame(['groups' => ScoreFactsService::DEFAULT_PROFILE, 'custom' => false], $service->profile());
		$this->config[ScoreFactsService::PROFILE_KEY] = '{broken';
		self::assertFalse($service->profile()['custom'], 'a broken stored profile falls back to the default');
	}

	public function testTheConductorSavesTheProfileAndTheChangeIsRecorded(): void {
		$service = $this->service([Roles::CONDUCTOR]);
		$groups = [
			['group' => 'B', 'comfortLow' => 43, 'comfortHigh' => 60, 'limitLow' => 40, 'limitHigh' => 62, 'singer' => 'Max'],
			['group' => 'S', 'comfortLow' => 62, 'comfortHigh' => 76, 'limitLow' => 60, 'limitHigh' => 79],
		];
		$profile = $service->saveProfile($groups, 'carla');
		self::assertTrue($profile['custom']);
		self::assertSame(['S', 'B'], array_column($profile['groups'], 'group'), 'top to bottom');
		self::assertSame(['group', 'comfortLow', 'comfortHigh', 'limitLow', 'limitHigh'], array_keys($profile['groups'][1]),
			'only the ranges of the group are kept, nothing about single singers');
		self::assertSame(['op', 'carla', 'settings', null, 'changed', ScoreFactsService::PROFILE_KEY, null], array_slice($this->audited[0], 0, 7));
		self::assertSame($this->config[ScoreFactsService::PROFILE_KEY], $this->audited[0][7]);

		self::assertFalse($service->saveProfile([], 'carla')['custom'], 'an empty list goes back to the default');
		self::assertArrayNotHasKey(ScoreFactsService::PROFILE_KEY, $this->config);
		self::assertNull($this->audited[1][7]);
	}

	public function testOtherRolesDoNotSaveTheProfile(): void {
		self::assertSame([Roles::CONDUCTOR, Roles::LIBRARIAN], ScoreFactsService::PROFILE_EDITORS);
		foreach ([Roles::READER, Roles::OPERATIONS, Roles::CONTRIBUTOR] as $role) {
			try {
				$this->service([$role])->saveProfile([], 'someone');
				self::fail($role . ' saves no profile');
			} catch (AccessDeniedException) {
			}
		}
		self::assertSame([], $this->audited);
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function invalidProfiles(): array {
		$group = ['group' => 'S', 'comfortLow' => 62, 'comfortHigh' => 76, 'limitLow' => 60, 'limitHigh' => 79];
		return [
			'not a list' => ['S'],
			'a map' => [['S' => $group]],
			'unknown group' => [[['group' => 'X'] + $group]],
			'a group twice' => [[$group, $group]],
			'too many groups' => [[$group, $group, $group, $group, $group]],
			'a tone as text' => [[['comfortLow' => '62'] + $group]],
			'a tone outside the keyboard' => [[['limitHigh' => 120] + $group]],
			'comfortable range upside down' => [[['comfortLow' => 77] + $group]],
			'possible range inside the comfortable one' => [[['limitHigh' => 70] + $group]],
			'a missing value' => [[['group' => 'S', 'comfortLow' => 62]]],
		];
	}

	#[DataProvider('invalidProfiles')]
	public function testAnInvalidProfileIsRefused(mixed $groups): void {
		$this->expectException(InvalidInputException::class);
		ScoreFactsService::validGroups($groups);
	}
}
