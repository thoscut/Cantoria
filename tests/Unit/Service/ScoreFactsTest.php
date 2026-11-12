<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Service\ScoreFacts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What is read from a MusicXML score: the voices with their ranges and
 * first tones, the keys, the first bars and the lyrics.
 */
#[CoversClass(ScoreFacts::class)]
final class ScoreFactsTest extends TestCase {
	private const DEMO = __DIR__ . '/../../fixtures/demo/';

	private static function note(string $step, int $octave, int $duration = 4, string $extra = ''): string {
		return "<note><pitch><step>$step</step><octave>$octave</octave></pitch><duration>$duration</duration><voice>1</voice><type>quarter</type>$extra</note>";
	}

	/**
	 * @param array<string, string> $parts part name => notes of one measure
	 */
	private static function score(array $parts, string $attributes = '<key><fifths>-1</fifths><mode>major</mode></key><time><beats>4</beats><beat-type>4</beat-type></time>'): string {
		$list = '';
		$body = '';
		$n = 0;
		foreach ($parts as $name => $notes) {
			$n++;
			$list .= "<score-part id=\"P$n\"><part-name>$name</part-name></score-part>";
			$body .= "<part id=\"P$n\"><measure number=\"1\"><attributes><divisions>4</divisions>$attributes<clef><sign>G</sign><line>2</line></clef></attributes>$notes</measure></part>";
		}
		return "<?xml version=\"1.0\" encoding=\"UTF-8\"?><score-partwise version=\"4.0\"><part-list>$list</part-list>$body</score-partwise>";
	}

	public function testReadsTheVoicesOfAChoirScore(): void {
		$facts = ScoreFacts::analyze((string)file_get_contents(self::DEMO . '1-01_Der-Mond-ist-aufgegangen_Schulz.musicxml'));
		self::assertNotNull($facts);
		self::assertSame(ScoreFacts::FORMAT, $facts['format']);
		self::assertSame(['S', 'A', 'T', 'B'], array_column($facts['voices'], 'group'));
		$voices = [];
		foreach ($facts['voices'] as $voice) {
			$voices[(string)$voice['group']] = $voice;
		}
		self::assertSame([65, 74], [$voices['S']['low'], $voices['S']['high']]);
		self::assertSame([41, 53], [$voices['B']['low'], $voices['B']['high']]);
		foreach ($facts['voices'] as $voice) {
			self::assertGreaterThanOrEqual($voice['low'], $voice['tessLow']);
			self::assertLessThanOrEqual($voice['high'], $voice['tessHigh']);
			self::assertGreaterThanOrEqual($voice['low'], $voice['first']);
			self::assertGreaterThan(0, $voice['notes']);
		}
		self::assertSame(['fifths' => -1, 'mode' => 'major'], $facts['keyStart']);
		self::assertSame('4/4', $facts['time']);
		self::assertSame(72, $facts['tempo']);
		self::assertSame('G-2', $facts['incipit']['clef']);
		self::assertSame('bB', $facts['incipit']['keysig']);
		self::assertMatchesRegularExpression("/^[0-9A-G',.\\/=+\\-xbn ]+$/", $facts['incipit']['data']);
		self::assertCount(2, $facts['lyrics']['verses']);
		self::assertStringStartsWith('Der Mond ist aufgegangen', str_replace("\n", ' ', $facts['lyrics']['verses'][0]['text']));
	}

	public function testLeavesOutInstruments(): void {
		$facts = ScoreFacts::analyze(self::score([
			'Sopran' => self::note('C', 5) . self::note('E', 5),
			'Klavier' => self::note('C', 2) . self::note('C', 6),
			'Violine' => self::note('G', 6),
		]));
		self::assertNotNull($facts);
		self::assertSame(['Sopran'], array_column($facts['voices'], 'name'));
		self::assertSame([72, 76], [$facts['voices'][0]['low'], $facts['voices'][0]['high']]);
		self::assertSame(72, $facts['voices'][0]['first']);
		self::assertSame(76, $facts['voices'][0]['last']);
	}

	public function testATransposingPartSoundsWhereItIsSung(): void {
		$transposed = '<key><fifths>0</fifths></key><transpose><diatonic>0</diatonic><chromatic>0</chromatic><octave-change>-1</octave-change></transpose>';
		$facts = ScoreFacts::analyze(self::score(['Tenor' => self::note('C', 5)], $transposed));
		self::assertNotNull($facts);
		self::assertSame(60, $facts['voices'][0]['low'], 'the tenor reads the treble clef an octave higher than he sings');
	}

	/**
	 * @return iterable<string, array{0: ?string, 1: ?string}>
	 */
	public static function groupNames(): iterable {
		yield 'German' => ['Sopran', 'S'];
		yield 'numbered' => ['Sopran II', 'S'];
		yield 'Italian' => ['Contralto', 'A'];
		yield 'mezzo is an alto group' => ['Mezzosopran', 'A'];
		yield 'letter' => ['T', 'T'];
		yield 'baritone sings with the basses' => ['Bariton', 'B'];
		yield 'bass with a number' => ['Bass 1', 'B'];
		yield 'instrument' => ['Klavier', null];
		yield 'no word that only starts like a voice' => ['Altblockflöte', null];
		yield 'nothing' => [null, null];
	}

	#[DataProvider('groupNames')]
	public function testNamesTheGroupOfAVoice(?string $name, ?string $group): void {
		self::assertSame($group, ScoreFacts::group($name));
	}

	public function testTheTessituraLeavesOutRareExtremes(): void {
		// tones weighted by their duration: one short top tone among long middle ones
		$pitches = [60 => 2, 62 => 40, 64 => 40, 65 => 40, 67 => 40, 79 => 1];
		[$low, $high] = ScoreFacts::tessitura($pitches);
		self::assertSame(62, $low);
		self::assertSame(67, $high);
	}

	public function testRefusesWhatIsNoScore(): void {
		self::assertNull(ScoreFacts::analyze(''));
		self::assertNull(ScoreFacts::analyze('<html><body/></html>'));
		self::assertNull(ScoreFacts::analyze('not xml at all'));
	}

	public function testAScoreWithoutSingersHasNoVoicesButCountsAsRead(): void {
		$facts = ScoreFacts::analyze(self::score(['Klavier' => self::note('C', 4)]));
		self::assertNotNull($facts);
		self::assertSame([], $facts['voices']);
		self::assertNull($facts['incipit']);
		self::assertNull($facts['lyrics']);
		self::assertSame(1, $facts['measures']);
	}

	public function testNeverExpandsEntitiesOfTheFile(): void {
		$xml = '<?xml version="1.0"?><!DOCTYPE score-partwise [<!ENTITY secret SYSTEM "file:///etc/passwd">]>'
			. '<score-partwise><part-list><score-part id="P1"><part-name>&secret;</part-name></score-part></part-list>'
			. '<part id="P1"><measure number="1">' . self::note('C', 5) . '</measure></part></score-partwise>';
		self::assertNull(ScoreFacts::analyze($xml));
	}
}
