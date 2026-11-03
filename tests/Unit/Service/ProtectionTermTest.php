<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\Person;
use OCA\Cantoria\Service\ProtectionTerm;
use OCA\Cantoria\Service\Sort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProtectionTerm::class)]
#[UsesClass(Sort::class)]
final class ProtectionTermTest extends TestCase {
	private static int $nextId = 1;

	private static function person(string $name, ?string $died, ?string $diedPrecision, ?string $identification = 'certain'): Person {
		$person = Person::fromParams(['name' => $name, 'died' => $died, 'diedPrecision' => $diedPrecision, 'identification' => $identification]);
		$person->setId(self::$nextId++);
		return $person;
	}

	public function testWithoutPersonsTheVersionCountsAsProtected(): void {
		$proposal = ProtectionTerm::propose([], 2026);
		self::assertSame(ProtectionTerm::UNKNOWN, $proposal['status']);
		self::assertSame('no_persons', $proposal['reason']);
		self::assertNull($proposal['until']);
		self::assertSame([], $proposal['blocking']);
	}

	public function testProtectionEndsSeventyYearsAfterTheEndOfTheYearOfDeath(): void {
		$mozart = self::person('Wolfgang Amadeus Mozart', '1791-12-05', 'exact');
		$proposal = ProtectionTerm::propose([$mozart], 2026);
		self::assertSame([
			'status' => ProtectionTerm::FREE,
			'until' => '1861-12-31',
			'freeSince' => '1862-01-01',
			'reason' => 'ended',
			'parameters' => ['Wolfgang Amadeus Mozart', 1791, 1861],
			'decisivePersonId' => $mozart->getId(),
			'blocking' => [],
		], $proposal);
	}

	/**
	 * @return iterable<string, array{0: int, 1: string}>
	 */
	public static function boundary(): iterable {
		// died 1955 => protected until 2025-12-31
		yield 'last year of protection' => [2025, ProtectionTerm::PROTECTED];
		yield 'first free year' => [2026, ProtectionTerm::FREE];
		yield 'long before' => [1990, ProtectionTerm::PROTECTED];
	}

	#[DataProvider('boundary')]
	public function testBoundaryYear(int $currentYear, string $status): void {
		$proposal = ProtectionTerm::propose([self::person('Hugo Distler', '1942', 'year'), self::person('Arranger', '1955', 'year')], $currentYear);
		self::assertSame($status, $proposal['status']);
		self::assertSame('2025-12-31', $proposal['until']);
		self::assertSame($status === ProtectionTerm::FREE ? 'ended' : 'running', $proposal['reason']);
	}

	public function testTheLastPersonToDieDecides(): void {
		$composer = self::person('Composer', '1900', 'year');
		$arranger = self::person('Arranger', '1980', 'year');
		$translator = self::person('Translator', '1950', 'year');
		$proposal = ProtectionTerm::propose([$composer, $arranger, $translator], 2026);
		self::assertSame(ProtectionTerm::PROTECTED, $proposal['status']);
		self::assertSame($arranger->getId(), $proposal['decisivePersonId']);
		self::assertSame('2050-12-31', $proposal['until']);
	}

	/**
	 * @return iterable<string, array{0: ?string, 1: ?string, 2: ?string, 3: string}>
	 */
	public static function blockingPersons(): iterable {
		// died, precision, identification, reason
		yield 'living' => [null, 'living', 'certain', 'living'];
		yield 'year of death unknown' => [null, 'unknown', 'certain', 'death_unknown'];
		yield 'no precision' => ['1900', null, 'certain', 'death_unknown'];
		yield 'approximate year of death' => ['1900', 'circa', 'certain', 'death_circa'];
		yield 'identification uncertain' => ['1900', 'year', 'uncertain', 'identification_uncertain'];
		yield 'identification not recorded' => ['1900', 'year', null, 'identification_uncertain'];
	}

	#[DataProvider('blockingPersons')]
	public function testUnknownMeansProtected(?string $died, ?string $precision, ?string $identification, string $reason): void {
		$blocking = self::person('Somebody', $died, $precision, $identification);
		self::assertSame($reason, ProtectionTerm::blockingReason($blocking));

		// Even next to a person whose protection has long ended, there is no "free" proposal.
		$proposal = ProtectionTerm::propose([self::person('Old master', '1700', 'year'), $blocking], 2026);
		self::assertSame(ProtectionTerm::UNKNOWN, $proposal['status']);
		self::assertSame($reason, $proposal['reason']);
		self::assertSame(['Somebody'], $proposal['parameters']);
		self::assertSame([['personId' => $blocking->getId(), 'name' => 'Somebody', 'reason' => $reason]], $proposal['blocking']);
		self::assertNull($proposal['until']);
	}

	public function testSecuredPersonsDoNotBlock(): void {
		self::assertNull(ProtectionTerm::blockingReason(self::person('A', '1900', 'year', 'certain')));
		self::assertNull(ProtectionTerm::blockingReason(self::person('B', '1900-05-01', 'exact', 'probable')));
	}

	public function testTheMostSeriousReasonComesFirst(): void {
		$uncertain = self::person('Uncertain', '1900', 'year', 'uncertain');
		$circa = self::person('Circa', '1900', 'circa');
		$living = self::person('Living', null, 'living');
		$proposal = ProtectionTerm::propose([$uncertain, $circa, $living], 2026);
		self::assertSame('living', $proposal['reason']);
		self::assertSame(['living', 'death_circa', 'identification_uncertain'], array_column($proposal['blocking'], 'reason'));
	}

	public function testWithoutSpecialCasesTheProposalHasNoSpecialPart(): void {
		$proposal = ProtectionTerm::propose([self::person('Bach', '1750', 'year')], 2026, [], 2010);
		self::assertArrayNotHasKey('special', $proposal);
		self::assertSame('ended', $proposal['reason']);
	}

	public function testAScientificEditionIsProtectedForTwentyFiveYearsAfterItAppeared(): void {
		$bach = self::person('Johann Sebastian Bach', '1750', 'year');
		$editor = self::person('Editor', null, 'living');
		$proposal = ProtectionTerm::propose([$bach, $editor], 2026, [ProtectionTerm::SCIENTIFIC_EDITION], 2010, [$editor->getId()]);
		self::assertSame(ProtectionTerm::PROTECTED, $proposal['status']);
		self::assertSame('2035-12-31', $proposal['until']);
		self::assertSame('2036-01-01', $proposal['freeSince']);
		self::assertSame('scientific_edition_running', $proposal['reason']);
		self::assertSame([2010, 2035], $proposal['parameters']);
		self::assertNull($proposal['decisivePersonId']);
		self::assertSame([['case' => ProtectionTerm::SCIENTIFIC_EDITION, 'year' => 2010, 'until' => '2035-12-31']], $proposal['special']);

		$proposal = ProtectionTerm::propose([$bach, $editor], 2036, [ProtectionTerm::SCIENTIFIC_EDITION], 2010, [$editor->getId()]);
		self::assertSame(ProtectionTerm::FREE, $proposal['status']);
		self::assertSame('scientific_edition_ended', $proposal['reason']);
	}

	public function testEditorsCountWithTheirLifeDatesWithoutASpecialCase(): void {
		$bach = self::person('Johann Sebastian Bach', '1750', 'year');
		$editor = self::person('Editor', null, 'living');
		$proposal = ProtectionTerm::propose([$bach, $editor], 2026, [], 2010, [$editor->getId()]);
		self::assertSame(ProtectionTerm::UNKNOWN, $proposal['status']);
		self::assertSame('living', $proposal['reason']);
	}

	public function testThePersonsDecideWhenTheirProtectionEndsLater(): void {
		$composer = self::person('Composer', '1990', 'year');
		$proposal = ProtectionTerm::propose([$composer], 2026, [ProtectionTerm::POSTHUMOUS_WORK], 2000);
		self::assertSame(ProtectionTerm::PROTECTED, $proposal['status']);
		self::assertSame('running', $proposal['reason']);
		self::assertSame('2060-12-31', $proposal['until']);
		self::assertSame($composer->getId(), $proposal['decisivePersonId']);
		self::assertSame('2025-12-31', $proposal['special'][0]['until'] ?? null);
	}

	public function testAPosthumousWorkIsProtectedAfterItsFirstAppearance(): void {
		$proposal = ProtectionTerm::propose([self::person('Composer', '1850', 'year')], 2026, [ProtectionTerm::POSTHUMOUS_WORK], 2020);
		self::assertSame(ProtectionTerm::PROTECTED, $proposal['status']);
		self::assertSame('posthumous_work_running', $proposal['reason']);
		self::assertSame('2045-12-31', $proposal['until']);
	}

	public function testWithoutTheYearASpecialCaseCountsAsProtected(): void {
		$proposal = ProtectionTerm::propose([self::person('Composer', '1850', 'year')], 2026, [ProtectionTerm::SCIENTIFIC_EDITION]);
		self::assertSame(ProtectionTerm::UNKNOWN, $proposal['status']);
		self::assertSame('year_missing', $proposal['reason']);
		self::assertSame([['personId' => null, 'name' => '', 'reason' => 'year_missing']], $proposal['blocking']);
		self::assertSame([['case' => ProtectionTerm::SCIENTIFIC_EDITION, 'year' => null, 'until' => null]], $proposal['special']);

		// A blocking person stays the main reason; the missing year is listed after it.
		$living = self::person('Living', null, 'living');
		$proposal = ProtectionTerm::propose([$living], 2026, [ProtectionTerm::POSTHUMOUS_WORK]);
		self::assertSame('living', $proposal['reason']);
		self::assertSame(['living', 'year_missing'], array_column($proposal['blocking'], 'reason'));

		// Without persons, "no persons" stays the main reason.
		$proposal = ProtectionTerm::propose([], 2026, [ProtectionTerm::POSTHUMOUS_WORK]);
		self::assertSame('no_persons', $proposal['reason']);
		self::assertSame(['year_missing'], array_column($proposal['blocking'], 'reason'));
	}

	public function testOnlyEditorsAreNotEnough(): void {
		$editor = self::person('Editor', '1900', 'year');
		$proposal = ProtectionTerm::propose([$editor], 2026, [ProtectionTerm::SCIENTIFIC_EDITION], 1950, [$editor->getId()]);
		self::assertSame(ProtectionTerm::UNKNOWN, $proposal['status']);
		self::assertSame('only_editors', $proposal['reason']);
	}

	public function testUnknownCasesAreIgnored(): void {
		$proposal = ProtectionTerm::propose([self::person('Bach', '1750', 'year')], 2026, ['anonymous'], 2010);
		self::assertArrayNotHasKey('special', $proposal);
	}

	public function testYearsBeforeChrist(): void {
		$horace = self::person('Horaz', '-8', 'year');
		$proposal = ProtectionTerm::propose([$horace], 2026);
		self::assertSame(ProtectionTerm::FREE, $proposal['status']);
		self::assertSame('0062-12-31', $proposal['until']);
		self::assertSame([$horace->getName(), -8, 62], $proposal['parameters']);
	}

	public function testPublicDomainNeedsAComposerAndALyricistUnlessTheyAreTraditional(): void {
		$arranger = self::person('Friedrich Silcher', '1860-08-26', 'exact');
		// Only the arranger (died 1860) is recorded; the composer of the melody is missing.
		$proposal = ProtectionTerm::propose([$arranger], 2026, roles: ['arranger']);
		self::assertSame(ProtectionTerm::UNKNOWN, $proposal['status']);
		self::assertSame('composer_missing', $proposal['reason']);

		$proposal = ProtectionTerm::propose([$arranger], 2026, roles: ['arranger'], melodyOrigin: ProtectionTerm::TRADITIONAL);
		self::assertSame('lyricist_missing', $proposal['reason'], 'a version with text needs its lyricist too');

		$proposal = ProtectionTerm::propose([$arranger], 2026, roles: ['arranger'], melodyOrigin: ProtectionTerm::TRADITIONAL,
			textOrigin: ProtectionTerm::TRADITIONAL);
		self::assertSame(ProtectionTerm::FREE, $proposal['status'], 'a folk song set by Silcher');
		self::assertSame('1931-01-01', $proposal['freeSince']);

		$proposal = ProtectionTerm::propose([$arranger], 2026, roles: ['composer'], textOrigin: ProtectionTerm::NO_TEXT);
		self::assertSame(ProtectionTerm::FREE, $proposal['status'], 'without text no lyricist is needed');

		$proposal = ProtectionTerm::propose([$arranger], 2026, roles: ['composer', 'lyricist']);
		self::assertSame(ProtectionTerm::FREE, $proposal['status']);

		$proposal = ProtectionTerm::propose([$arranger], 2026, roles: ['unspecified']);
		self::assertSame('composer_missing', $proposal['reason'], 'a person without a role is no composer');

		// Protected stays protected: the check only stops a "public domain" proposal.
		$living = self::person('Living Arranger', null, 'living');
		$proposal = ProtectionTerm::propose([$living], 2026, roles: ['arranger']);
		self::assertSame('living', $proposal['reason']);

		// Without roles (callers that do not know them) nothing changes.
		self::assertSame(ProtectionTerm::FREE, ProtectionTerm::propose([$arranger], 2026)['status']);
	}

	public function testOnlyTraditionalPartsAndNoPersonsArePublicDomain(): void {
		$proposal = ProtectionTerm::propose([], 2026, roles: [], melodyOrigin: ProtectionTerm::TRADITIONAL, textOrigin: ProtectionTerm::NO_TEXT,
			versionKind: ProtectionTerm::ORIGINAL_KIND);
		self::assertSame(ProtectionTerm::FREE, $proposal['status']);
		self::assertSame('traditional', $proposal['reason']);
		self::assertSame('no_persons', ProtectionTerm::propose([], 2026, roles: [], melodyOrigin: ProtectionTerm::TRADITIONAL)['reason'],
			'the text is not traditional: unknown means protected');
		$proposal = ProtectionTerm::propose([], 2026, [ProtectionTerm::SCIENTIFIC_EDITION], null, [], [], ProtectionTerm::TRADITIONAL, ProtectionTerm::TRADITIONAL);
		self::assertSame(ProtectionTerm::UNKNOWN, $proposal['status'], 'a special case without its year still counts as protected');
	}

	/**
	 * Most choral settings of folk songs are arrangements with a protection
	 * of their own (§ 3 UrhG): "traditional" is only public domain for an
	 * unarranged original or with the arranger recorded (R B4).
	 */
	public function testATraditionalMelodyIsNoPublicDomainWithoutTheArranger(): void {
		foreach (['arrangement', 'adaptation', null] as $kind) {
			$proposal = ProtectionTerm::propose([], 2026, roles: [], melodyOrigin: ProtectionTerm::TRADITIONAL,
				textOrigin: ProtectionTerm::TRADITIONAL, versionKind: $kind);
			self::assertSame(ProtectionTerm::UNKNOWN, $proposal['status'], 'kind ' . ($kind ?? 'unknown'));
			self::assertSame('arranger_missing', $proposal['reason']);
		}
		$lyricist = self::person('Simon Dach', '1659-04-15', 'exact');
		$proposal = ProtectionTerm::propose([$lyricist], 2026, roles: ['lyricist'], melodyOrigin: ProtectionTerm::TRADITIONAL,
			versionKind: 'arrangement');
		self::assertSame('arranger_missing', $proposal['reason'], 'a known lyricist does not make the setting free');

		$arranger = self::person('Friedrich Silcher', '1860-08-26', 'exact');
		$proposal = ProtectionTerm::propose([$arranger], 2026, roles: ['arranger'], melodyOrigin: ProtectionTerm::TRADITIONAL,
			textOrigin: ProtectionTerm::TRADITIONAL, versionKind: 'arrangement');
		self::assertSame(ProtectionTerm::FREE, $proposal['status'], 'the arranger is recorded and died long ago');
		$proposal = ProtectionTerm::propose([], 2026, roles: [], melodyOrigin: ProtectionTerm::TRADITIONAL,
			textOrigin: ProtectionTerm::TRADITIONAL, versionKind: ProtectionTerm::ORIGINAL_KIND);
		self::assertSame('traditional', $proposal['reason'], 'an unarranged original');
	}

	/**
	 * The rule holds for every arrangement, not only of traditional melodies
	 * (R B24): a version of the kind "Arrangement" or "Adaptation" of a
	 * composition in the public domain is no public domain without the
	 * arranger; the composition itself and the other kinds stay as they are.
	 */
	public function testEveryArrangementNeedsItsArranger(): void {
		$bach = self::person('Johann Sebastian Bach', '1750-07-28', 'exact');
		foreach (ProtectionTerm::ARRANGED_KINDS as $kind) {
			$proposal = ProtectionTerm::propose([$bach], 2026, roles: ['composer'], textOrigin: ProtectionTerm::NO_TEXT, versionKind: $kind);
			self::assertSame(ProtectionTerm::UNKNOWN, $proposal['status'], 'kind ' . $kind);
			self::assertSame('arranger_missing', $proposal['reason'], 'kind ' . $kind);
		}
		foreach ([ProtectionTerm::ORIGINAL_KIND, 'new_engraving', 'manuscript_copy', 'transposition', 'excerpt', null] as $kind) {
			$proposal = ProtectionTerm::propose([$bach], 2026, roles: ['composer'], textOrigin: ProtectionTerm::NO_TEXT, versionKind: $kind);
			self::assertSame(ProtectionTerm::FREE, $proposal['status'], 'kind ' . ($kind ?? 'unknown'));
		}
		$arranger = self::person('Max Reger', '1916-05-11', 'exact');
		$proposal = ProtectionTerm::propose([$bach, $arranger], 2026, roles: ['composer', 'arranger'], textOrigin: ProtectionTerm::NO_TEXT,
			versionKind: 'arrangement');
		self::assertSame(ProtectionTerm::FREE, $proposal['status'], 'the arranger is recorded and died long ago');
		$living = self::person('Living Arranger', null, 'living');
		$proposal = ProtectionTerm::propose([$bach, $living], 2026, roles: ['composer', 'arranger'], textOrigin: ProtectionTerm::NO_TEXT,
			versionKind: 'arrangement');
		self::assertSame('living', $proposal['reason'], 'a living arranger: protected');
		// Callers that do not know the roles keep the proposal by the persons
		self::assertSame(ProtectionTerm::FREE, ProtectionTerm::propose([$bach], 2026, versionKind: 'arrangement')['status']);
	}
}
