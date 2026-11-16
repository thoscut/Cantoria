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
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Person;
use OCA\Cantoria\Db\PersonMapper;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\ContributorDerivationService;
use OCA\Cantoria\Service\ContributorPhrases;
use OCA\Cantoria\Service\LifeDates;
use OCA\Cantoria\Service\PersonImportService;
use OCA\Cantoria\Service\Roles;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Derivation of persons and roles from the composer and arranger texts of
 * the catalog, with mappers and database replaced by test doubles that
 * record what the service does. All names and works are invented or in the
 * public domain.
 */
#[CoversClass(ContributorDerivationService::class)]
#[CoversClass(ContributorPhrases::class)]
#[UsesClass(LifeDates::class)]
#[UsesClass(PersonImportService::class)]
final class ContributorDerivationServiceTest extends TestCase {
	private const NOW = 1761134400;
	private const UID = 'anna';

	private ContributorDerivationService $service;

	/** @var array<int, Work> */
	private array $works = [];
	/** @var array<int, WorkVersion> */
	private array $versions = [];
	/** @var array<string, Copy> */
	private array $copies = [];
	/** @var list<Person> */
	private array $persons = [];
	/** @var list<Contribution> */
	private array $contributions = [];

	/** @var list<Person|Contribution> */
	private array $inserted = [];
	/** @var list<Person|Contribution|WorkVersion> */
	private array $updated = [];
	/** @var list<string> */
	private array $transaction = [];
	/** @var list<array{objectType: string, objectId: ?int, action: string, field: ?string, old: mixed, new: mixed, reason: ?string}> */
	private array $audit = [];
	/** @var list<string> */
	private array $rolesOfUser = [Roles::LIBRARIAN];
	private int $nextId = 1000;

	#[\Override]
	protected function setUp(): void {
		$db = $this->createStub(IDBConnection::class);
		foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
			$db->method($method)->willReturnCallback(function () use ($method): void {
				$this->transaction[] = $method;
			});
		}

		$persons = $this->createStub(PersonMapper::class);
		$persons->method('findAll')->willReturnCallback(fn (): array => $this->persons);
		$persons->method('insert')->willReturnCallback($this->insert(...));
		$persons->method('update')->willReturnCallback($this->update(...));

		$contributions = $this->createStub(ContributionMapper::class);
		$contributions->method('findAllByVersion')->willReturnCallback(function (): array {
			$result = [];
			foreach ($this->contributions as $contribution) {
				$result[$contribution->getVersionId()][] = $contribution;
			}
			return $result;
		});
		$contributions->method('insert')->willReturnCallback($this->insert(...));
		$contributions->method('update')->willReturnCallback($this->update(...));

		$works = $this->createStub(WorkMapper::class);
		$works->method('findAllById')->willReturnCallback(fn (): array => $this->works);

		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('findAllById')->willReturnCallback(fn (): array => $this->versions);
		$versions->method('update')->willReturnCallback($this->update(...));

		$copies = $this->createStub(CopyMapper::class);
		$copies->method('findAllByShelfmark')->willReturnCallback(fn (): array => $this->copies);

		$audit = $this->createStub(AuditLog::class);
		$audit->method('newOperation')->willReturn('op-1');
		$audit->method('record')->willReturnCallback(
			function (string $operation, ?string $uid, string $objectType, ?int $objectId, string $action, ?string $field = null, mixed $old = null, mixed $new = null, ?string $reason = null): void {
				self::assertSame('op-1', $operation);
				self::assertSame(self::UID, $uid);
				$this->audit[] = ['objectType' => $objectType, 'objectId' => $objectId, 'action' => $action, 'field' => $field, 'old' => $old, 'new' => $new, 'reason' => $reason];
			});

		$roles = $this->createStub(Roles::class);
		$roles->method('requireRole')->willReturnCallback(function (string $uid, array $allowed): void {
			if (array_intersect($this->rolesOfUser, $allowed) === []) {
				throw new AccessDeniedException('This requires one of these roles: %s', [implode(', ', $allowed)]);
			}
		});

		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$this->service = new ContributorDerivationService($db, $persons, $contributions, $works, $versions, $copies, $audit, $roles, $time);
	}

	private function insert(Person|Contribution $entity): Person|Contribution {
		$entity->setId($this->nextId++);
		$this->inserted[] = $entity;
		if ($entity instanceof Contribution) {
			$this->contributions[] = $entity;
		}
		return $entity;
	}

	private function update(Person|Contribution|WorkVersion $entity): Person|Contribution|WorkVersion {
		$this->updated[] = $entity;
		return $entity;
	}

	/**
	 * Adds a catalog entry (work, version, copy) as if loaded from the database.
	 */
	private function givenEntry(string $shelfmark, ?string $composer, ?string $arrangerText = null, ?string $melodyOrigin = null): WorkVersion {
		$n = count($this->copies) + 1;
		$work = new Work();
		$work->setId($n);
		$work->setComposer($composer);
		$version = new WorkVersion();
		$version->setId(10 * $n);
		$version->setWorkId($n);
		$version->setArrangerText($arrangerText);
		$version->setMelodyOrigin($melodyOrigin);
		$copy = new Copy();
		$copy->setId(100 * $n);
		$copy->setVersionId(10 * $n);
		$copy->setShelfmark($shelfmark);
		$this->works[$n] = $work;
		$this->versions[10 * $n] = $version;
		$this->copies[$shelfmark] = $copy;
		return $version;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function givenPerson(string $name, array $values = []): Person {
		$person = new Person();
		$person->setId($this->nextId++);
		$person->setName($name);
		foreach ($values as $field => $value) {
			$person->setValue($field, $value);
		}
		$this->persons[] = $person;
		return $person;
	}

	private function givenContribution(WorkVersion $version, Person $person, string $role): Contribution {
		$contribution = new Contribution();
		$contribution->setId($this->nextId++);
		$contribution->setVersionId($version->getId());
		$contribution->setPersonId($person->getId());
		$contribution->setRole($role);
		$this->contributions[] = $contribution;
		return $contribution;
	}

	/**
	 * @return list<Person>
	 */
	private function insertedPersons(): array {
		return array_values(array_filter($this->inserted, fn (object $e): bool => $e instanceof Person));
	}

	/**
	 * @return list<Contribution>
	 */
	private function insertedContributions(): array {
		return array_values(array_filter($this->inserted, fn (object $e): bool => $e instanceof Contribution));
	}

	/**
	 * The contributions of a version as "name (role)", sorted.
	 *
	 * @return list<string>
	 */
	private function contributionsOf(WorkVersion $version): array {
		$names = [];
		foreach ([...$this->persons, ...$this->insertedPersons()] as $person) {
			$names[$person->getId()] = $person->getName();
		}
		$result = [];
		foreach ($this->contributions as $contribution) {
			if ($contribution->getVersionId() === $version->getId()) {
				$result[] = ($names[$contribution->getPersonId()] ?? '?') . ' (' . $contribution->getRole() . ')';
			}
		}
		sort($result);
		return $result;
	}

	/**
	 * @param array<string, mixed> $report
	 * @return list<string> warnings with their parameters filled in
	 */
	private static function warnings(array $report): array {
		/** @var list<array{0: string, 1: list<string|int>}> $warnings */
		$warnings = $report['warnings'];
		return array_map(fn (array $w): string => vsprintf($w[0], $w[1]), $warnings);
	}

	// ---- role phrases ----

	/**
	 * @return iterable<string, array{0: string, 1: ?list<string>}>
	 */
	public static function phrases(): iterable {
		yield 'Text' => ['Text', ['lyricist']];
		yield 'text, lower case' => ['text', ['lyricist']];
		yield 'dt. Text' => ['dt. Text', ['translator']];
		yield 'Deutscher Text' => ['Deutscher Text', ['translator']];
		yield 'Übersetzung' => ['Übersetzung', ['translator']];
		yield 'Deutsch' => ['Deutsch', ['translator']];
		yield 'Satz' => ['Satz', ['arranger']];
		yield 'Tonsatz' => ['Tonsatz', ['arranger']];
		yield 'Bearbeitung' => ['Bearbeitung', ['arranger']];
		yield 'Bearb.' => ['Bearb.', ['arranger']];
		yield 'Einrichtung' => ['Einrichtung', ['arranger']];
		yield 'Arr.' => ['Arr.', ['arranger']];
		yield 'Musik' => ['Musik', ['composer']];
		yield 'Melodie' => ['Melodie', ['composer']];
		yield 'Weise' => ['Weise', ['composer']];
		yield 'Deutscher Text und Satz' => ['Deutscher Text und Satz', ['translator', 'arranger']];
		yield 'Text und Satz' => ['Text und Satz', ['lyricist', 'arranger']];
		yield 'Text, Melodie und Satz' => ['Text, Melodie und Satz', ['lyricist', 'composer', 'arranger']];
		yield 'Satz u. deutsche Übersetzung' => ['Satz u. deutsche Übersetzung', ['arranger', 'translator']];
		yield 'Satz für Männerchor' => ['Satz für Männerchor', ['arranger']];
		yield 'Satz TTBB' => ['Satz TTBB', ['arranger']];
		yield 'enumerated' => ['a) Musik', ['composer']];
		yield 'unknown word' => ['Textgestaltung', null];
		yield 'unknown part makes the whole unknown' => ['Satz und Klavier', null];
		yield 'other language stays open' => ['Schwedischer Text', null];
		yield 'digits are no phrase' => ['1. Str.', null];
		yield 'GND is no phrase' => ['GND 1015478913', null];
		yield 'empty' => ['', null];
	}

	#[DataProvider('phrases')]
	public function testRolesOfPhrase(string $phrase, ?array $roles): void {
		self::assertSame($roles, ContributorPhrases::rolesOf($phrase));
	}

	public function testSegmentsAreSplitOutsideBracketsOnly(): void {
		self::assertSame(['Text: Anna Beispiel (1900–1980)', 'Satz: Bert Muster (* 1950)'],
			ContributorPhrases::segments('Text: Anna Beispiel (1900–1980); Satz: Bert Muster (* 1950)'));
		self::assertSame(['Anna Beispiel', 'Bert Muster'], ContributorPhrases::segments('Anna Beispiel / Bert Muster'));
		self::assertSame(['Carla Probe (1900–1980; Druck: „1901–1980“)'], ContributorPhrases::segments('Carla Probe (1900–1980; Druck: „1901–1980“)'),
			'a semicolon inside brackets does not split');
		self::assertSame(['Bearbeitung: Dora Test', 'vorher: ungenannt'], ContributorPhrases::segments('Bearbeitung: Dora Test | vorher: ungenannt'));
		self::assertSame(['Anna/Bert'], ContributorPhrases::segments('Anna/Bert'), 'a slash without blanks is no separator');
		self::assertSame([], ContributorPhrases::segments(null));
		self::assertSame([], ContributorPhrases::segments('  ; '));
	}

	public function testSplitFindsThePhraseBeforeTheColonOrBeforeVon(): void {
		self::assertSame(['Satz', 'Hans Lang (1897–1968)'], ContributorPhrases::split('Satz: Hans Lang (1897–1968)'));
		self::assertSame(['Tonsatz', 'Michael Prätorius (1571–1621)'], ContributorPhrases::split('Tonsatz von Michael Prätorius (1571–1621)'));
		self::assertSame(['Deutsch', 'Anna Beispiel'], ContributorPhrases::split('Deutsch von Anna Beispiel'));
		self::assertSame([null, 'Satz nach Friedrich Silcher (1789–1860)'], ContributorPhrases::split('Satz nach Friedrich Silcher (1789–1860)'),
			'"nach" names the model, not the arranger');
		self::assertSame([null, 'Lodovico Viadana (um 1560–1627; Druck: „1564–1645“)'],
			ContributorPhrases::split('Lodovico Viadana (um 1560–1627; Druck: „1564–1645“)'), 'a colon inside brackets is no phrase');
		self::assertSame([null, 'Rich. Turnwald'], ContributorPhrases::split('Rich. Turnwald'));
	}

	public function testNameAndDates(): void {
		self::assertSame(['Josef Rheinberger', '1839–1901'], ContributorPhrases::nameAndDates('Josef Rheinberger (1839–1901)'));
		self::assertSame(['Johann Strauss (Sohn)', '1825–1899'], ContributorPhrases::nameAndDates('Johann Strauss (Sohn) (1825–1899), op. 314'),
			'a bracket that is part of the name stays, the opus goes');
		self::assertSame(['Franz Schubert', '1797–1828'], ContributorPhrases::nameAndDates('Franz Schubert, op. 17 Nr. 4, vor 1823 (1797–1828)'));
		self::assertSame(['Gerhard Beispiel', '* 1944, GND 134490509'], ContributorPhrases::nameAndDates('Gerhard Beispiel (* 1944, GND 134490509)'));
		self::assertSame(['Carl Friedrich Zöllner', '1800–1860'], ContributorPhrases::nameAndDates('Carl Friedrich Zöllner (1800–1860), im Druck nicht genannt'));
		self::assertSame(['Bernhard Dietrich', '1840–1902, Chemnitz'],
			ContributorPhrases::nameAndDates('Bernhard Dietrich (1840–1902, Chemnitz) zugeschrieben (umstritten, auch Georg Kunoth genannt)'));
		self::assertSame(['Rudolf Mylo', 'Lebensdaten nicht ermittelt'], ContributorPhrases::nameAndDates('Rudolf Mylo (Lebensdaten nicht ermittelt)'));
		self::assertSame(['Ernst Welisch', null], ContributorPhrases::nameAndDates('Ernst Welisch (am Blatt ungenannt)'), 'a remark is dropped');
		self::assertSame(['Rich. Turnwald', null], ContributorPhrases::nameAndDates('Rich. Turnwald'));
	}

	public function testNamesAndNonNames(): void {
		self::assertTrue(ContributorPhrases::isName('Josef Rheinberger'));
		self::assertTrue(ContributorPhrases::isName('Rich. Turnwald'));
		self::assertFalse(ContributorPhrases::isName('unbekannt'));
		self::assertFalse(ContributorPhrases::isName('Stralsund 1665'), 'digits');
		self::assertFalse(ContributorPhrases::isName('ital. Berglied „Bènia calastoria“'), 'small letter and quotation marks');
		self::assertFalse(ContributorPhrases::isName(''));
		self::assertTrue(ContributorPhrases::isTraditional('Schottisches Volkslied („Auld Lang Syne“)'));
		self::assertTrue(ContributorPhrases::isTraditional('Spiritual'));
		self::assertFalse(ContributorPhrases::isTraditional('Rich. Turnwald (die Angabe „Volkslied“ ist unbelegt)'), 'only outside brackets');
		self::assertTrue(ContributorPhrases::isUncertain('Palestrina zugeschrieben'));
		self::assertTrue(ContributorPhrases::isUncertain('Siegfried Beispiel (* 1926?)'));
		self::assertFalse(ContributorPhrases::isUncertain('Josef Rheinberger (1839–1901)'));
		self::assertSame('134446216', ContributorPhrases::gnd('1902–1983, GND 134446216'));
		self::assertSame('1015478913', ContributorPhrases::gnd('GND 1015478913'));
		self::assertNull(ContributorPhrases::gnd('1902–1983'));
	}

	public function testCleanNameRemovesTheSharedPrefixesAndRemarks(): void {
		self::assertSame('Anna Beispiel', PersonImportService::cleanName('dt. Text: Anna Beispiel'));
		self::assertSame('Anna Beispiel', PersonImportService::cleanName('Deutscher Text und Satz: Anna Beispiel'));
		self::assertSame('Anna Beispiel', PersonImportService::cleanName('Einrichtung: Anna Beispiel (1900-1980)'));
		self::assertSame('Anna Beispiel', PersonImportService::cleanName('Anna Beispiel, im Druck nicht genannt'));
		self::assertSame('Friedrich Silcher', PersonImportService::cleanName('Friedrich Silcher, op. 26'));
		self::assertContains('deutscher text und satz:', ContributorPhrases::NAME_PREFIXES);
		self::assertContains('einrichtung:', ContributorPhrases::NAME_PREFIXES);
	}

	// ---- derivation ----

	public function testComposerWithoutPhraseAndRolesFromPhrases(): void {
		$version = $this->givenEntry('1-01', 'Josef Rheinberger (1839–1901)',
			'Text: Matthias Claudius (1740–1815); dt. Text: Anna Beispiel (* 1950); Satz: Bert Muster (1900–1980)');

		$report = $this->service->derive(self::UID, false);

		self::assertSame(['Anna Beispiel (translator)', 'Bert Muster (arranger)', 'Josef Rheinberger (composer)', 'Matthias Claudius (lyricist)'],
			$this->contributionsOf($version));
		self::assertSame(1, $report['versions']);
		self::assertSame(4, $report['segments']);
		self::assertSame(4, $report['personsCreated']);
		self::assertSame(4, $report['contributionsCreated']);
		self::assertSame(['arranger' => 1, 'composer' => 1, 'lyricist' => 1, 'translator' => 1], $report['roles']);
		self::assertSame([], $report['unassigned']);
		self::assertSame([], $report['warnings']);
		self::assertSame('op-1', $report['operation']);
		self::assertSame(['beginTransaction', 'commit'], $this->transaction);

		$rheinberger = $this->insertedPersons()[0];
		self::assertSame('Josef Rheinberger', $rheinberger->getName());
		self::assertSame(['1839', 'year', '1901', 'year'],
			[$rheinberger->getBorn(), $rheinberger->getBornPrecision(), $rheinberger->getDied(), $rheinberger->getDiedPrecision()]);
		self::assertSame('1839–1901', $rheinberger->getLifeDatesText());
		self::assertSame('probable', $rheinberger->getIdentification());
		self::assertSame('catalog 1-01', $rheinberger->getSource());
		self::assertSame(self::NOW, $rheinberger->getCreated());

		$audit = array_filter($this->audit, fn (array $e): bool => $e['action'] === 'created');
		self::assertCount(4, $audit);
		self::assertSame(['person', 'created', 'name', 'Josef Rheinberger', 'derived'],
			[reset($audit)['objectType'], reset($audit)['action'], reset($audit)['field'], reset($audit)['new'], reset($audit)['reason']]);
		$added = array_values(array_filter($this->audit, fn (array $e): bool => $e['action'] === 'contribution_added'));
		self::assertCount(4, $added);
		self::assertSame(['version', $version->getId(), 'Josef Rheinberger (composer)', 'derived'],
			[$added[0]['objectType'], $added[0]['objectId'], $added[0]['new'], $added[0]['reason']]);
	}

	public function testOnePhraseWithTwoRolesGivesTwoContributions(): void {
		$version = $this->givenEntry('1-02', 'Franz Schubert (1797–1828)', 'Deutscher Text und Satz: Anna Beispiel (1923–2001)');

		$report = $this->service->derive(self::UID, false);

		self::assertSame(['Anna Beispiel (arranger)', 'Anna Beispiel (translator)', 'Franz Schubert (composer)'], $this->contributionsOf($version));
		self::assertSame(2, $report['personsCreated'], 'one person, two contributions');
		self::assertSame(3, $report['contributionsCreated']);
	}

	public function testVonPhrasesNameTheRole(): void {
		$version = $this->givenEntry('1-03', 'Volkslied', 'Tonsatz von Michael Prätorius (1571–1621); Deutsch von Anna Beispiel');

		$this->service->derive(self::UID, false);

		self::assertSame(['Anna Beispiel (translator)', 'Michael Prätorius (arranger)'], $this->contributionsOf($version));
	}

	public function testTraditionalMelodySetsTheOriginOnlyWhenEmpty(): void {
		$folk = $this->givenEntry('2-01', 'Schottisches Volkslied („Auld Lang Syne“)', 'Satz: Bert Muster (1900–1980)');
		$spiritual = $this->givenEntry('2-02', 'Spiritual');
		$set = $this->givenEntry('2-03', 'Volksweise', null, 'traditional');

		$report = $this->service->derive(self::UID, false);

		self::assertSame('traditional', $folk->getMelodyOrigin());
		self::assertSame('traditional', $spiritual->getMelodyOrigin());
		self::assertSame(3, $report['traditionalMelodies']);
		self::assertSame(1, $report['personsCreated'], 'no person for the folk song');
		self::assertSame(['Bert Muster (arranger)'], $this->contributionsOf($folk));
		self::assertSame([$folk, $spiritual], array_values(array_filter($this->updated, fn (object $e): bool => $e instanceof WorkVersion)),
			'the version with the origin already set is not written');
		self::assertSame(self::NOW, $folk->getModified());
		$audit = array_values(array_filter($this->audit, fn (array $e): bool => $e['field'] === 'melody_origin'));
		self::assertCount(2, $audit);
		self::assertSame(['version', $folk->getId(), 'derived', null, 'traditional'],
			[$audit[0]['objectType'], $audit[0]['objectId'], $audit[0]['action'], $audit[0]['old'], $audit[0]['new']]);
		self::assertSame(0, $set->getModified(), 'untouched');
	}

	public function testLifeDatesAndGndComeFromTheBracket(): void {
		$this->givenEntry('3-01', 'Theo Beispiel (1926–2023, GND 134373847)', 'Text: Gerhard Muster (* 1944, GND 134490509); Satz: Carla Probe (um 1600)');

		$report = $this->service->derive(self::UID, false);

		[$theo, $gerhard, $carla] = $this->insertedPersons();
		self::assertSame(['1926', 'year', '2023', 'year', '134373847', 'probable'],
			[$theo->getBorn(), $theo->getBornPrecision(), $theo->getDied(), $theo->getDiedPrecision(), $theo->getGnd(), $theo->getIdentification()]);
		self::assertSame('1926–2023, GND 134373847', $theo->getLifeDatesText());
		// "* 1944" means living (the catalog writes a death as soon as it is known).
		self::assertSame(['1944', 'year', null, 'living', '134490509', 'probable'],
			[$gerhard->getBorn(), $gerhard->getBornPrecision(), $gerhard->getDied(), $gerhard->getDiedPrecision(), $gerhard->getGnd(), $gerhard->getIdentification()]);
		self::assertSame(['* 1944, GND 134490509'], [$gerhard->getLifeDatesText()]);
		// "um 1600" alone is ambiguous: no date, the text is kept, the identification uncertain.
		self::assertSame([null, 'unknown', null, 'unknown', null, 'uncertain', 'um 1600'],
			[$carla->getBorn(), $carla->getBornPrecision(), $carla->getDied(), $carla->getDiedPrecision(), $carla->getGnd(), $carla->getIdentification(),
				$carla->getLifeDatesText()]);
		self::assertSame(0, $report['uncertain'], '"um" is no doubt about the person');
	}

	public function testAttributionsAreUncertainAndKeepNoGnd(): void {
		$this->givenEntry('3-02', 'Bernhard Dietrich (1840–1902) zugeschrieben');
		$this->givenEntry('3-03', 'Siegfried Beispiel (* 1926? — Zuordnung GND 134468449 unbelegt)');

		$report = $this->service->derive(self::UID, false);

		[$dietrich, $beispiel] = $this->insertedPersons();
		self::assertSame(['Bernhard Dietrich', 'uncertain', '1840', '1902'],
			[$dietrich->getName(), $dietrich->getIdentification(), $dietrich->getBorn(), $dietrich->getDied()]);
		self::assertSame(['Siegfried Beispiel', 'uncertain', '1926', 'circa', 'living', null],
			[$beispiel->getName(), $beispiel->getIdentification(), $beispiel->getBorn(), $beispiel->getBornPrecision(), $beispiel->getDiedPrecision(),
				$beispiel->getGnd()]);
		self::assertSame(2, $report['uncertain']);
		self::assertSame(['composer' => 2], $report['roles']);
	}

	public function testAnUnspecifiedContributionGetsTheRole(): void {
		$version = $this->givenEntry('4-01', 'Josef Rheinberger (1839–1901)', 'Text: Anna Beispiel (1900–1980)');
		$rheinberger = $this->givenPerson('Josef Rheinberger', ['born' => '1839', 'born_precision' => 'year', 'died' => '1901', 'died_precision' => 'year']);
		$anna = $this->givenPerson('Anna Beispiel');
		$unspecified = $this->givenContribution($version, $rheinberger, 'unspecified');
		$this->givenContribution($version, $anna, 'unspecified');

		$report = $this->service->derive(self::UID, false);

		self::assertSame(['Anna Beispiel (lyricist)', 'Josef Rheinberger (composer)'], $this->contributionsOf($version));
		self::assertSame('composer', $unspecified->getRole());
		self::assertSame(2, $report['rolesSet']);
		self::assertSame(0, $report['contributionsCreated']);
		self::assertSame(0, $report['personsCreated']);
		self::assertSame(2, $report['personsExisting']);
		self::assertSame(['composer' => 1, 'lyricist' => 1], $report['roles']);
		self::assertContains($unspecified, $this->updated);
		$audit = array_values(array_filter($this->audit, fn (array $e): bool => $e['action'] === 'contribution_role_set'));
		self::assertCount(2, $audit);
		self::assertSame(['version', $version->getId(), 'contribution', 'Josef Rheinberger (unspecified)', 'Josef Rheinberger (composer)', 'derived'],
			[$audit[0]['objectType'], $audit[0]['objectId'], $audit[0]['field'], $audit[0]['old'], $audit[0]['new'], $audit[0]['reason']]);
	}

	public function testAContributionWithAnotherRoleIsKeptAndTheDerivedOneAdded(): void {
		$version = $this->givenEntry('4-02', 'Anna Beispiel (1900–1980)');
		$anna = $this->givenPerson('Anna Beispiel');
		$this->givenContribution($version, $anna, 'arranger');

		$report = $this->service->derive(self::UID, false);

		self::assertSame(['Anna Beispiel (arranger)', 'Anna Beispiel (composer)'], $this->contributionsOf($version));
		self::assertSame(1, $report['contributionsCreated']);
		self::assertSame(0, $report['rolesSet']);
	}

	public function testARepeatedRunAddsNothing(): void {
		$version = $this->givenEntry('4-03', 'Anna Beispiel (1900–1980)', 'Satz: Bert Muster (* 1950)');
		$anna = $this->givenPerson('Anna Beispiel', ['born' => '1900', 'born_precision' => 'year', 'died' => '1980', 'died_precision' => 'year',
			'life_dates_text' => '1900–1980', 'source' => 'catalog 4-03']);
		$bert = $this->givenPerson('Bert Muster', ['born' => '1950', 'born_precision' => 'year', 'died_precision' => 'living',
			'life_dates_text' => '* 1950', 'source' => 'catalog 4-03']);
		$this->givenContribution($version, $anna, 'composer');
		$this->givenContribution($version, $bert, 'arranger');

		$report = $this->service->derive(self::UID, false);

		self::assertSame([0, 0, 0, 0, 2], [$report['personsCreated'], $report['personsUpdated'], $report['contributionsCreated'], $report['rolesSet'],
			$report['personsExisting']]);
		self::assertSame([], $this->inserted);
		self::assertSame([], $this->updated);
		self::assertSame([], $report['differs']);
	}

	public function testExistingPersonIsFilledButACheckedOneNeverChanged(): void {
		$this->givenEntry('5-01', 'Anna Beispiel (1900–1980)', 'Text: Bert Muster (1910–1990, GND 134446216)');
		$anna = $this->givenPerson('Anna Beispiel', ['identification' => 'uncertain']);
		$bert = $this->givenPerson('Bert Muster', ['checked_by' => 'lena', 'identification' => 'uncertain']);

		$report = $this->service->derive(self::UID, false);

		self::assertSame(['1900', 'year', '1980', 'year', '1900–1980', 'probable', 'catalog 5-01'],
			[$anna->getBorn(), $anna->getBornPrecision(), $anna->getDied(), $anna->getDiedPrecision(), $anna->getLifeDatesText(), $anna->getIdentification(),
				$anna->getSource()]);
		self::assertSame(self::NOW, $anna->getModified());
		self::assertSame([null, null, null, null, 'uncertain'],
			[$bert->getBorn(), $bert->getDied(), $bert->getGnd(), $bert->getLifeDatesText(), $bert->getIdentification()], 'checked: untouched');
		self::assertSame(1, $report['personsUpdated']);
		self::assertSame(2, $report['personsExisting']);
		self::assertSame([$anna], array_values(array_filter($this->updated, fn (object $e): bool => $e instanceof Person)));
		$filled = array_values(array_filter($this->audit, fn (array $e): bool => $e['objectType'] === 'person' && $e['action'] === 'derived'));
		self::assertSame(['born', 'born_precision', 'died', 'died_precision', 'life_dates_text', 'source', 'identification'],
			array_column($filled, 'field'));
		self::assertSame([$anna->getId()], array_unique(array_column($filled, 'objectId')));
	}

	public function testDifferingLifeDatesAreReportedNotChanged(): void {
		$this->givenEntry('6-01', 'Giuseppe Verdi (1813–1901)');
		$this->givenEntry('6-02', 'Giuseppe Verdi (1813–1901)');
		$this->givenEntry('6-03', 'Anna Beispiel (* 1950)');
		$this->givenEntry('6-04', 'Bert Muster (1900–1980)');
		$verdi = $this->givenPerson('Giuseppe Verdi', ['born' => '1813', 'born_precision' => 'year', 'died' => '1907', 'died_precision' => 'year',
			'life_dates_text' => '1813–1907']);
		$anna = $this->givenPerson('Anna Beispiel', ['born' => '1950', 'born_precision' => 'year', 'died' => '2020', 'died_precision' => 'year']);
		$this->givenPerson('Bert Muster', ['born' => '1900', 'born_precision' => 'year', 'died' => '1980', 'died_precision' => 'year']);

		$report = $this->service->derive(self::UID, false);

		self::assertSame([
			['name' => 'Giuseppe Verdi', 'person' => '1813–1907', 'catalog' => '1813–1901', 'versions' => ['6-01', '6-02']],
			['name' => 'Anna Beispiel', 'person' => '1950–2020', 'catalog' => '* 1950', 'versions' => ['6-03']],
		], $report['differs']);
		self::assertSame('1907', $verdi->getDied(), 'the register is not changed');
		self::assertSame('2020', $anna->getDied());
		self::assertSame(3, $report['personsUpdated'], 'the empty texts (life dates text, source) are filled, no dates');
		self::assertSame(['source'], array_column(array_filter($this->audit,
			fn (array $e): bool => $e['objectType'] === 'person' && $e['objectId'] === $verdi->getId() && $e['action'] === 'derived'), 'field'));
	}

	public function testSegmentsWithoutAPhraseInTheArrangerFieldAreUnassigned(): void {
		$this->givenEntry('7-01', 'Anna Beispiel (1900–1980)', 'Rich. Turnwald; Nr. 9 Franz Abt (1819–1885), Worte Gustav Wagner; Textgestaltung: Bert Muster');
		$this->givenEntry('7-02', null, 'Satz nach Friedrich Silcher (1789–1860), 1827');

		$report = $this->service->derive(self::UID, false);

		self::assertSame([
			['shelfmark' => '7-01', 'text' => 'Rich. Turnwald'],
			['shelfmark' => '7-01', 'text' => 'Nr. 9 Franz Abt (1819–1885), Worte Gustav Wagner'],
			['shelfmark' => '7-01', 'text' => 'Textgestaltung: Bert Muster'],
			['shelfmark' => '7-02', 'text' => 'Satz nach Friedrich Silcher (1789–1860), 1827'],
		], $report['unassigned']);
		self::assertSame(1, $report['personsCreated'], 'only the composer');
		self::assertSame(2, $report['versions']);
		self::assertSame(5, $report['segments']);
	}

	public function testNonNamesAndAmbiguousNamesAreWarnings(): void {
		$this->givenEntry('8-01', 'Stralsund 1665 / Halle 1741');
		$this->givenEntry('8-02', 'unbekannt / Rich. Turnwald (die Angabe „Volkslied“ ist unbelegt)');
		$this->givenEntry('8-03', 'Anna Beispiel (1900–1980)');
		$this->givenPerson('Anna Beispiel');
		$this->givenPerson('anna beispiel');

		$report = $this->service->derive(self::UID, false);

		self::assertSame([
			'Shelf mark 8-01: "Stralsund 1665" is not a name and was skipped.',
			'Shelf mark 8-01: "Halle 1741" is not a name and was skipped.',
			'Shelf mark 8-02: "unbekannt" is not a name and was skipped.',
			'Shelf mark 8-03: there are several persons named "Anna Beispiel". The segment was skipped; please assign it by hand.',
		], self::warnings($report));
		self::assertSame(['Rich. Turnwald'], array_map(fn (Person $p): string => $p->getName(), $this->insertedPersons()));
		self::assertSame(1, $report['uncertain'], 'the remark "unbelegt" makes Turnwald uncertain');
		self::assertSame([], $report['unassigned']);
	}

	public function testVersionsWithoutTextsAreSkipped(): void {
		$this->givenEntry('9-01', null, null);
		$this->givenEntry('9-02', '', '  ');

		$report = $this->service->derive(self::UID, true);

		self::assertSame(0, $report['versions']);
		self::assertSame(0, $report['segments']);
		self::assertSame([], $this->inserted);
	}

	public function testDryRunDoesEverythingAndRollsBack(): void {
		$version = $this->givenEntry('1-01', 'Josef Rheinberger (1839–1901)', 'Text: Anna Beispiel (1900–1980)');
		$anna = $this->givenPerson('Anna Beispiel');
		$this->givenContribution($version, $anna, 'unspecified');

		$report = $this->service->derive(self::UID, true);

		self::assertTrue($report['dryRun']);
		self::assertNull($report['operation']);
		self::assertSame([1, 1, 1, 1], [$report['personsCreated'], $report['personsExisting'], $report['contributionsCreated'], $report['rolesSet']]);
		self::assertSame(['beginTransaction', 'rollBack'], $this->transaction);
		self::assertCount(1, $this->insertedPersons(), 'the work is done and taken back by the database');
		self::assertSame([], array_filter($this->audit, fn (array $e): bool => $e['action'] === 'contributor_derivation'), 'no closing entry');
	}

	public function testARealRunCommitsWithAClosingAuditEntry(): void {
		$this->givenEntry('1-01', 'Josef Rheinberger (1839–1901)');

		$report = $this->service->derive(self::UID, false);

		self::assertSame(['beginTransaction', 'commit'], $this->transaction);
		self::assertSame('op-1', $report['operation']);
		$closing = array_values(array_filter($this->audit, fn (array $e): bool => $e['action'] === 'contributor_derivation'));
		self::assertCount(1, $closing);
		self::assertSame(['import', null, '1 versions'], [$closing[0]['objectType'], $closing[0]['objectId'], $closing[0]['new']]);
	}

	public function testAFailureRollsBackAndRethrows(): void {
		$this->givenEntry('1-01', 'Josef Rheinberger (1839–1901)');
		$db = $this->createStub(IDBConnection::class);
		foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
			$db->method($method)->willReturnCallback(function () use ($method): void {
				$this->transaction[] = $method;
			});
		}
		$persons = $this->createStub(PersonMapper::class);
		$persons->method('findAll')->willReturn([]);
		$persons->method('insert')->willThrowException(new \RuntimeException('disk full'));
		$works = $this->createStub(WorkMapper::class);
		$works->method('findAllById')->willReturn($this->works);
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('findAllById')->willReturn($this->versions);
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('findAllByShelfmark')->willReturn($this->copies);
		$service = new ContributorDerivationService($db, $persons, $this->createStub(ContributionMapper::class), $works, $versions, $copies,
			$this->createStub(AuditLog::class), $this->createStub(Roles::class), $this->createStub(ITimeFactory::class));

		try {
			$service->derive(self::UID, false);
			self::fail('the exception is passed on');
		} catch (\RuntimeException $e) {
			self::assertSame('disk full', $e->getMessage());
		}
		self::assertSame(['beginTransaction', 'rollBack'], $this->transaction);
	}

	public function testOnlyTheLibrarianMayDerive(): void {
		$this->rolesOfUser = [Roles::CONDUCTOR];
		$this->givenEntry('1-01', 'Josef Rheinberger (1839–1901)');

		$this->expectException(AccessDeniedException::class);
		$this->service->derive(self::UID, true);
	}
}
