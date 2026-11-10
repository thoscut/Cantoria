<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\MusicXmlExcerpt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Choir members and the public receive only the measures of a link: the
 * excerpt keeps the measures in every part, the attributes and the tempo
 * valid at its start, title, credits and rights, and nothing that leads out
 * of it. The demo scores come from tools/demo (node tools/demo/build.mjs).
 */
#[CoversClass(MusicXmlExcerpt::class)]
final class MusicXmlExcerptTest extends TestCase {
	private const DEMO = __DIR__ . '/../../fixtures/demo/';
	/** Pickup (measure 0), first and second ending at 6 and 7, two parts on two staves each */
	private const MOND = self::DEMO . '1-01_Der-Mond-ist-aufgegangen_Schulz.musicxml';
	/** Measures 1–16, a backward repeat at the end of measure 8 */
	private const GROSSER_GOTT = self::DEMO . '1-03_Grosser-Gott-wir-loben-dich.musicxml';
	/** Compressed MusicXML, tenor clef (G clef an octave lower) */
	private const AENNCHEN = self::DEMO . '2-01_Aennchen-von-Tharau_Silcher.mxl';

	private static function demo(string $path): string {
		$content = file_get_contents($path);
		self::assertIsString($content, $path);
		return $content;
	}

	private static function xpath(string $xml): \DOMXPath {
		$document = new \DOMDocument();
		self::assertTrue($document->loadXML($xml, LIBXML_NONET), 'the excerpt is well-formed XML');
		return new \DOMXPath($document);
	}

	/**
	 * @return list<string> printed numbers of the measures of a part
	 */
	private static function numbers(\DOMXPath $xpath, string $part): array {
		$numbers = [];
		foreach ($xpath->query('/score-partwise/part[@id="' . $part . '"]/measure') ?: [] as $measure) {
			self::assertInstanceOf(\DOMElement::class, $measure);
			$numbers[] = $measure->getAttribute('number');
		}
		return $numbers;
	}

	private static function found(\DOMXPath $xpath, string $query): int {
		$nodes = $xpath->query($query);
		self::assertNotFalse($nodes, $query);
		return $nodes->length;
	}

	private static function text(\DOMXPath $xpath, string $query): string {
		$nodes = $xpath->query($query);
		self::assertNotFalse($nodes, $query);
		self::assertSame(1, $nodes->length, $query);
		$node = $nodes->item(0);
		return trim($node instanceof \DOMNode ? (string)$node->textContent : '');
	}

	/**
	 * @param list<string> $measures inner XML of each measure, numbered 1, 2, …
	 */
	private static function score(array $measures, string $part = 'P1'): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?><score-partwise version="4.0">'
			. '<part-list><score-part id="' . $part . '"><part-name>Chor</part-name></score-part></part-list><part id="' . $part . '">';
		foreach ($measures as $index => $content) {
			$xml .= '<measure number="' . ($index + 1) . '">' . $content . '</measure>';
		}
		return $xml . '</part></score-partwise>';
	}

	private const NOTE = '<note><pitch><step>C</step><octave>4</octave></pitch><duration>2</duration><voice>1</voice><type>half</type></note>';
	private const START = '<attributes><divisions>1</divisions><key><fifths>0</fifths></key><time><beats>2</beats><beat-type>4</beat-type></time>'
		. '<clef><sign>G</sign><line>2</line></clef></attributes>';

	public function testTheExcerptKeepsTheMeasuresOfEveryPartAndWhatIsOutsideThem(): void {
		$xml = MusicXmlExcerpt::cut(self::demo(self::MOND), '3', '5');
		self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"', $xml);
		self::assertStringNotContainsString('<!DOCTYPE', $xml);
		$xpath = self::xpath($xml);
		self::assertSame(['3', '4', '5'], self::numbers($xpath, 'P1'));
		self::assertSame(['3', '4', '5'], self::numbers($xpath, 'P2'));
		self::assertSame('Der Mond ist aufgegangen', self::text($xpath, '/score-partwise/work/work-title'));
		self::assertSame('Melodie und Text gemeinfrei. Satz: CC0 1.0 (Cantoria-Demo-Bestand)', self::text($xpath, '/score-partwise/identification/rights'));
		self::assertSame(3, self::found($xpath, '/score-partwise/credit'), 'title, subtitle and composer credit');
		self::assertSame(2, self::found($xpath, '/score-partwise/part-list/score-part'));
		self::assertSame(1, self::found($xpath, '/score-partwise/part[@id="P1"]/measure[@number="3"]/note/lyric[@number="1"]/text[.="gold"]'), 'the notes are the same');
	}

	public function testTheFirstMeasureGetsTheValidAttributesAndTempo(): void {
		$xpath = self::xpath(MusicXmlExcerpt::cut(self::demo(self::MOND), '3', '5'));
		foreach (['P1' => ['G', '2'], 'P2' => ['F', '4']] as $part => [$sign, $line]) {
			$first = '/score-partwise/part[@id="' . $part . '"]/measure[1]';
			self::assertSame('attributes', $xpath->query($first . '/*[1]')?->item(0)?->nodeName, $part . ': attributes first');
			self::assertSame('4', self::text($xpath, $first . '/attributes/divisions'));
			self::assertSame('-1', self::text($xpath, $first . '/attributes/key/fifths'));
			self::assertSame('4', self::text($xpath, $first . '/attributes/time/beats'));
			self::assertSame($sign, self::text($xpath, $first . '/attributes/clef/sign'), $part);
			self::assertSame($line, self::text($xpath, $first . '/attributes/clef/line'), $part);
			$names = [];
			foreach ($xpath->query($first . '/attributes/*') ?: [] as $child) {
				$names[] = $child->nodeName;
			}
			self::assertSame(['divisions', 'key', 'time', 'clef'], $names, 'schema order');
		}
		$first = '/score-partwise/part[@id="P1"]/measure[1]';
		self::assertSame('direction', $xpath->query($first . '/*[2]')?->item(0)?->nodeName, 'the tempo follows the attributes');
		self::assertSame('72', self::text($xpath, $first . '/direction/direction-type/metronome/per-minute'));
		self::assertSame('Ruhig', self::text($xpath, $first . '/direction/direction-type/words'));
		self::assertSame(1, self::found($xpath, $first . '/direction/sound[@tempo="72"]'));
		self::assertSame(0, self::found($xpath, '//measure[@number="4"]/attributes'), 'only the first measure gets them');
	}

	public function testAnExcerptFromThePickupIsTheBeginningOfThePiece(): void {
		$xpath = self::xpath(MusicXmlExcerpt::cut(self::demo(self::MOND), '0', '2'));
		self::assertSame(['0', '1', '2'], self::numbers($xpath, 'P1'));
		self::assertSame(1, self::found($xpath, '/score-partwise/part[@id="P1"]/measure[@number="0" and @implicit="yes"]'));
		self::assertSame(1, self::found($xpath, '/score-partwise/part[@id="P1"]/measure[1]/attributes'), 'nothing added twice');
		self::assertSame(1, self::found($xpath, '/score-partwise/part[@id="P1"]/measure[1]/direction/sound[@tempo]'));
	}

	public function testTheWholePieceKeepsItsRepeatAndEndings(): void {
		$xml = MusicXmlExcerpt::cut(self::demo(self::MOND), '0', '7');
		$xpath = self::xpath($xml);
		self::assertSame(['0', '1', '2', '3', '4', '5', '6', '7'], self::numbers($xpath, 'P2'));
		self::assertSame(2, self::found($xpath, '//repeat[@direction="backward"]'), 'one per part; it goes back to the beginning');
		self::assertSame(8, self::found($xpath, '//ending'));
	}

	public function testARepeatBackToBeforeTheExcerptAndItsEndingsAreRemoved(): void {
		$xpath = self::xpath(MusicXmlExcerpt::cut(self::demo(self::MOND), '3', '7'));
		self::assertSame(['3', '4', '5', '6', '7'], self::numbers($xpath, 'P1'));
		self::assertSame(0, self::found($xpath, '//repeat'), 'the repeat goes back to the beginning, before the excerpt');
		self::assertSame(0, self::found($xpath, '//ending'), 'without the repeat the endings would skip measure 7');
		self::assertSame(0, self::found($xpath, '//measure[@number="6"]/barline'), 'empty barlines are gone');
		self::assertSame(2, self::found($xpath, '//measure[@number="7"]/barline[@location="right"]/bar-style[.="light-heavy"]'),
			'the final barline stays');
	}

	public function testARepeatInsideTheExcerptStays(): void {
		$xpath = self::xpath(MusicXmlExcerpt::cut(self::demo(self::GROSSER_GOTT), '1', '8'));
		self::assertSame(['1', '2', '3', '4', '5', '6', '7', '8'], self::numbers($xpath, 'P1'));
		self::assertSame(2, self::found($xpath, '//measure[@number="8"]/barline/repeat[@direction="backward"]'));
		self::assertSame(2, self::found($xpath, '//measure[@number="8"]/barline/bar-style[.="light-heavy"]'));

		$xpath = self::xpath(MusicXmlExcerpt::cut(self::demo(self::GROSSER_GOTT), '5', '12'));
		self::assertSame(['5', '6', '7', '8', '9', '10', '11', '12'], self::numbers($xpath, 'P2'));
		self::assertSame(0, self::found($xpath, '//repeat'));
		self::assertSame(0, self::found($xpath, '//measure[@number="8"]/barline'), 'repeat sign and its bar style are gone');
		self::assertSame('92', self::text($xpath, '/score-partwise/part[@id="P1"]/measure[1]/direction/sound/@tempo'));
		self::assertSame('3', self::text($xpath, '/score-partwise/part[@id="P2"]/measure[1]/attributes/time/beats'));
	}

	public function testMeasureNumbersAreComparedAsTheScoreViewDoes(): void {
		$xpath = self::xpath(MusicXmlExcerpt::cut(self::demo(self::GROSSER_GOTT), ' 05', '08 '));
		self::assertSame(['5', '6', '7', '8'], self::numbers($xpath, 'P1'));
	}

	public function testACompressedDemoScoreKeepsItsTenorClef(): void {
		$zip = new \ZipArchive();
		self::assertTrue($zip->open(self::AENNCHEN) === true);
		$text = $zip->getFromName('2-01_Aennchen-von-Tharau_Silcher.musicxml');
		$zip->close();
		self::assertIsString($text);

		$xpath = self::xpath(MusicXmlExcerpt::cut($text, '9', '16'));
		self::assertSame(['9', '10', '11', '12', '13', '14', '15', '16'], self::numbers($xpath, 'P1'));
		$first = '/score-partwise/part[@id="P1"]/measure[1]/attributes';
		self::assertSame('2', self::text($xpath, $first . '/key/fifths'));
		self::assertSame('-1', self::text($xpath, $first . '/clef/clef-octave-change'));
		self::assertSame('100', self::text($xpath, '/score-partwise/part[@id="P1"]/measure[1]/direction/sound/@tempo'));
		self::assertSame('Ännchen von Tharau', self::text($xpath, '/score-partwise/work/work-title'));
	}

	public function testAScoreInUtf16BecomesUtf8(): void {
		$utf8 = str_replace('encoding="UTF-8"', 'encoding="UTF-16"', self::demo(self::GROSSER_GOTT));
		$utf16 = "\xFF\xFE" . mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8');
		$xml = MusicXmlExcerpt::cut($utf16, '1', '2');
		self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"', $xml);
		self::assertSame('Großer Gott, wir loben dich', self::text(self::xpath($xml), '/score-partwise/work/work-title'));
	}

	public function testATempoIsCarriedWithoutOffsetAndJumps(): void {
		$xml = self::score([
			self::START . '<direction><direction-type><words>Andante</words></direction-type>'
				. '<direction-type><metronome><beat-unit>quarter</beat-unit><per-minute>60</per-minute></metronome></direction-type>'
				. '<sound tempo="60"/></direction>' . self::NOTE,
			self::NOTE . '<direction><direction-type><words>più mosso</words></direction-type><offset>1</offset>'
				. '<sound tempo="90" dynamics="80" fine="yes"/></direction>',
			self::NOTE,
			self::NOTE,
		]);
		$xpath = self::xpath(MusicXmlExcerpt::cut($xml, '3', '4'));
		$first = '/score-partwise/part/measure[1]';
		self::assertSame(['attributes', 'direction', 'note'], array_map(fn (\DOMNode $node): string => $node->nodeName,
			iterator_to_array($xpath->query($first . '/*') ?: [])));
		self::assertSame('più mosso', self::text($xpath, $first . '/direction/direction-type/words'));
		self::assertSame(0, self::found($xpath, $first . '/direction/offset'));
		self::assertSame(1, self::found($xpath, $first . '/direction/sound[@tempo="90" and not(@dynamics) and not(@fine)]'));

		// A measure with its own tempo before its first note gets none
		$xml = self::score([
			self::START . '<sound tempo="60"/>' . self::NOTE,
			'<direction><direction-type><metronome><beat-unit>half</beat-unit><per-minute>40</per-minute></metronome></direction-type></direction>'
				. self::NOTE,
		]);
		$xpath = self::xpath(MusicXmlExcerpt::cut($xml, '2', '2'));
		self::assertSame(0, self::found($xpath, '//sound'));
		self::assertSame(1, self::found($xpath, '//metronome'));

		// A tempo given only by a sound in the measure
		$xpath = self::xpath(MusicXmlExcerpt::cut(self::score([self::START . '<sound tempo="66" dacapo="yes"/>' . self::NOTE, self::NOTE]), '2', '2'));
		self::assertSame(1, self::found($xpath, '/score-partwise/part/measure[1]/sound[@tempo="66" and not(@dacapo)]'));
	}

	public function testAttributesOfSeveralStavesAreMerged(): void {
		$xml = self::score([
			'<attributes><divisions>2</divisions><key><fifths>1</fifths></key><time><beats>2</beats><beat-type>4</beat-type></time>'
				. '<staves>2</staves><clef number="1"><sign>G</sign><line>2</line></clef><clef number="2"><sign>F</sign><line>4</line></clef></attributes>'
				. self::NOTE,
			'<attributes><key><fifths>2</fifths></key></attributes>' . self::NOTE,
			'<attributes><clef number="2"><sign>G</sign><line>2</line><clef-octave-change>-1</clef-octave-change></clef></attributes>' . self::NOTE,
		]);
		$xpath = self::xpath(MusicXmlExcerpt::cut($xml, '3', '3'));
		$attributes = '/score-partwise/part/measure[1]/attributes';
		self::assertSame(1, self::found($xpath, $attributes), 'merged into the measure\'s own attributes');
		self::assertSame(['divisions', 'key', 'time', 'staves', 'clef', 'clef'], array_map(fn (\DOMNode $node): string => $node->nodeName,
			iterator_to_array($xpath->query($attributes . '/*') ?: [])));
		self::assertSame('2', self::text($xpath, $attributes . '/key/fifths'), 'the later key');
		self::assertSame('G', self::text($xpath, $attributes . '/clef[@number="1"]/sign'));
		self::assertSame('-1', self::text($xpath, $attributes . '/clef[@number="2"]/clef-octave-change'), 'the measure\'s own clef wins');
	}

	public function testRepeatsThatLeadOutOfTheExcerptAreRemoved(): void {
		$forward = '<barline location="left"><bar-style>heavy-light</bar-style><repeat direction="forward"/></barline>';
		$backward = '<barline location="right"><bar-style>light-heavy</bar-style><repeat direction="backward"/></barline>';
		$xml = self::score([self::START . self::NOTE, $forward . self::NOTE, self::NOTE, self::NOTE . $backward, self::NOTE, self::NOTE]);

		$xpath = self::xpath(MusicXmlExcerpt::cut($xml, '2', '4'));
		self::assertSame(1, self::found($xpath, '//repeat[@direction="forward"]'), 'both ends inside: kept');
		self::assertSame(1, self::found($xpath, '//repeat[@direction="backward"]'));

		$xpath = self::xpath(MusicXmlExcerpt::cut($xml, '3', '6'));
		self::assertSame(0, self::found($xpath, '//repeat'), 'the repeat goes back before the excerpt');
		self::assertSame(0, self::found($xpath, '//barline'), 'its bar style is gone with it');

		$xpath = self::xpath(MusicXmlExcerpt::cut($xml, '1', '3'));
		self::assertSame(0, self::found($xpath, '//repeat'), 'the repeat would come back from behind the excerpt');
		self::assertSame(0, self::found($xpath, '//barline'));
	}

	public function testAKeptRepeatGoesBackWhereItGoesInTheWholePiece(): void {
		// A :| B :| – the second repeat goes back to the measure after the first one
		$backward = '<barline location="right"><bar-style>light-heavy</bar-style><repeat direction="backward"/></barline>';
		$xml = self::score([self::START . self::NOTE, self::NOTE . $backward, self::NOTE, self::NOTE . $backward, self::NOTE]);

		$xpath = self::xpath(MusicXmlExcerpt::cut($xml, '2', '4'));
		self::assertSame(1, self::found($xpath, '//repeat[@direction="backward"]'), 'only the second repeat stays');
		self::assertSame(0, self::found($xpath, '//measure[@number="2"]/barline'), 'the first one is gone with its bar style');
		self::assertSame(1, self::found($xpath, '//measure[@number="3"]/barline[@location="left"][bar-style="heavy-light"]/repeat[@direction="forward"]'),
			'its target is marked, so that it does not go back to measure 2');

		$xpath = self::xpath(MusicXmlExcerpt::cut($xml, '1', '5'));
		self::assertSame(0, self::found($xpath, '//repeat[@direction="forward"]'), 'the whole piece needs no marks of its own');
		self::assertSame(2, self::found($xpath, '//repeat[@direction="backward"]'));
	}

	public function testEndingsAreKeptOnlyWithTheirRepeat(): void {
		$first = '<barline location="left"><ending number="1" type="start">1.</ending></barline>';
		$firstEnd = '<barline location="right"><bar-style>light-heavy</bar-style><ending number="1" type="stop"/><repeat direction="backward"/></barline>';
		$second = '<barline location="left"><ending number="2" type="start">2.</ending></barline>';
		$secondEnd = '<barline location="right"><ending number="2" type="discontinue"/></barline>';
		$forward = '<barline location="left"><bar-style>heavy-light</bar-style><repeat direction="forward"/></barline>';
		$xml = self::score([self::START . self::NOTE, $forward . self::NOTE, self::NOTE, $first . self::NOTE . $firstEnd, $second . self::NOTE . $secondEnd, self::NOTE]);

		$xpath = self::xpath(MusicXmlExcerpt::cut($xml, '2', '6'));
		self::assertSame(4, self::found($xpath, '//ending'), 'repeat and both endings inside');
		self::assertSame(2, self::found($xpath, '//repeat'));

		$xpath = self::xpath(MusicXmlExcerpt::cut($xml, '2', '4'));
		self::assertSame(2, self::found($xpath, '//ending'), 'the second ending lies behind the excerpt: the first one stays');
		self::assertSame(2, self::found($xpath, '//repeat'));

		$xpath = self::xpath(MusicXmlExcerpt::cut($xml, '3', '6'));
		self::assertSame(0, self::found($xpath, '//ending'), 'the repeat goes back before the excerpt');
		self::assertSame(0, self::found($xpath, '//repeat'));

		$xpath = self::xpath(MusicXmlExcerpt::cut($xml, '5', '6'));
		self::assertSame(0, self::found($xpath, '//ending'), 'the group starts before the excerpt');
		self::assertSame(0, self::found($xpath, '//barline'));
	}

	public function testJumpsOutOfTheExcerptAreRemoved(): void {
		$xml = self::score([
			self::START . self::NOTE,
			'<direction><direction-type><segno/></direction-type><sound segno="s1"/></direction>' . self::NOTE,
			self::NOTE . '<direction><direction-type><words>To Coda</words></direction-type><sound tocoda="c1"/></direction>',
			self::NOTE . '<direction><direction-type><words>D.S. al Coda</words></direction-type><sound dalsegno="s1"/></direction>',
			self::NOTE . '<sound dacapo="yes"/>',
			'<direction><direction-type><coda/></direction-type><sound coda="c1"/></direction>' . self::NOTE,
		]);

		$xpath = self::xpath(MusicXmlExcerpt::cut($xml, '1', '6'));
		self::assertSame(4, self::found($xpath, '//direction'), 'all targets inside');
		self::assertSame(1, self::found($xpath, '//sound[@dacapo="yes"]'));

		$xpath = self::xpath(MusicXmlExcerpt::cut($xml, '2', '5'));
		self::assertSame(1, self::found($xpath, '//sound[@dalsegno="s1"]'), 'the segno lies inside');
		self::assertSame(0, self::found($xpath, '//sound[@tocoda]'), 'the coda lies behind');
		self::assertSame(0, self::found($xpath, '//words[.="To Coda"]'), 'with its text');
		self::assertSame(0, self::found($xpath, '//sound[@dacapo]'), 'D.C. would go back before the excerpt');
		self::assertSame(0, self::found($xpath, '//measure[@number="5"]/sound'), 'nothing is left of that sound');
		self::assertSame(1, self::found($xpath, '//sound[@segno="s1"]'), 'the segno itself stays');

		$xpath = self::xpath(MusicXmlExcerpt::cut($xml, '3', '6'));
		self::assertSame(0, self::found($xpath, '//sound[@dalsegno]'), 'the segno lies before');
		self::assertSame(0, self::found($xpath, '//words[.="D.S. al Coda"]'));
		self::assertSame(1, self::found($xpath, '//sound[@tocoda="c1"]'), 'the coda lies inside');
		self::assertSame(1, self::found($xpath, '//sound[@coda="c1"]'));
	}

	public function testALinkWithoutMeasuresGetsTheWholePieceUnchanged(): void {
		$xpath = self::xpath(MusicXmlExcerpt::whole(self::demo(self::MOND)));
		self::assertSame(['0', '1', '2', '3', '4', '5', '6', '7'], self::numbers($xpath, 'P1'));
		self::assertSame(['0', '1', '2', '3', '4', '5', '6', '7'], self::numbers($xpath, 'P2'));
		self::assertSame(2, self::found($xpath, '//repeat[@direction="backward"]'), 'the full playing order');
		self::assertSame(8, self::found($xpath, '//ending'));

		$xpath = self::xpath(MusicXmlExcerpt::whole(self::demo(self::GROSSER_GOTT)));
		self::assertSame(range(1, 16), array_map(intval(...), self::numbers($xpath, 'P1')));

		// Jumps stay, also those an excerpt would remove
		$xml = self::score([
			self::START . self::NOTE,
			'<direction><direction-type><segno/></direction-type><sound segno="s1"/></direction>' . self::NOTE,
			self::NOTE . '<direction><direction-type><words>To Coda</words></direction-type><sound tocoda="c1"/></direction>',
			self::NOTE . '<direction><direction-type><words>D.S. al Coda</words></direction-type><sound dalsegno="s1"/></direction>',
			'<direction><direction-type><coda/></direction-type><sound coda="c1"/></direction>' . self::NOTE,
		]);
		$xpath = self::xpath(MusicXmlExcerpt::whole($xml));
		self::assertSame(['1', '2', '3', '4', '5'], self::numbers($xpath, 'P1'));
		self::assertSame(4, self::found($xpath, '//direction'));
		self::assertSame(1, self::found($xpath, '//sound[@dalsegno="s1"]'));
		self::assertSame(1, self::found($xpath, '//sound[@tocoda="c1"]'));

		$withDoctype = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE score-partwise PUBLIC "-//Recordare//DTD MusicXML 4.0 Partwise//EN" '
			. '"http://www.musicxml.org/dtds/partwise.dtd">' . substr(self::score([self::NOTE]), strlen('<?xml version="1.0" encoding="UTF-8"?>'));
		$whole = MusicXmlExcerpt::whole($withDoctype);
		self::assertStringNotContainsString('DOCTYPE', $whole, 'written like an excerpt');
		self::assertStringContainsString('encoding="UTF-8"', $whole);
	}

	public function testNamesAndNotesOutsideTheMusicDoNotLeaveTheServer(): void {
		$xml = '<?xml version="1.0" encoding="UTF-8"?><!-- set by Lena, still to check --><?musescore internal?>'
			. '<score-partwise version="4.0"><work><work-title>Abendlied</work-title></work>'
			. '<identification><creator type="composer">J. Rheinberger</creator><rights>CC0 1.0</rights>'
			. '<encoding><encoder>Lena Muster</encoder><software>MuseScore 4</software><encoding-date>2026-10-01</encoding-date></encoding>'
			. '<miscellaneous><miscellaneous-field name="note">ask the conductor</miscellaneous-field></miscellaneous></identification>'
			. '<credit page="1"><credit-words>Abendlied</credit-words></credit>'
			. '<part-list><score-part id="P1"><part-name>Chor</part-name></score-part></part-list>'
			. '<part id="P1"><measure number="1">' . self::START . '<!-- breath here? -->' . self::NOTE . '</measure>'
			. '<measure number="2"><?editor mark?>' . self::NOTE . '</measure></part></score-partwise>';
		foreach (['excerpt' => MusicXmlExcerpt::cut($xml, '1', '2'), 'whole piece' => MusicXmlExcerpt::whole($xml)] as $case => $result) {
			foreach (['Lena', 'still to check', 'musescore internal', 'ask the conductor', 'breath here', 'editor mark', '<!--', '<?editor'] as $text) {
				self::assertStringNotContainsString($text, $result, $case . ': ' . $text);
			}
			$xpath = self::xpath($result);
			self::assertSame('CC0 1.0', self::text($xpath, '/score-partwise/identification/rights'), $case);
			self::assertSame('J. Rheinberger', self::text($xpath, '/score-partwise/identification/creator'), $case);
			self::assertSame('MuseScore 4', self::text($xpath, '/score-partwise/identification/encoding/software'), $case);
			self::assertSame('Abendlied', self::text($xpath, '/score-partwise/credit/credit-words'), $case);
			self::assertSame(['1', '2'], self::numbers($xpath, 'P1'), $case);
		}
	}

	public function testTheWholePieceIsCheckedLikeAnExcerpt(): void {
		$subset = '<?xml version="1.0"?><!DOCTYPE score-partwise [<!ENTITY x "y">]>' . substr(self::score([self::NOTE]), strlen('<?xml version="1.0" encoding="UTF-8"?>'));
		foreach ([$subset => 'This file is not a MusicXML score.', '<html/>' => 'This file is not a MusicXML score.',
			'<score-timewise version="4.0"/>' => 'Links for choir members and public links need a score in the partwise MusicXML format.'] as $xml => $message) {
			try {
				MusicXmlExcerpt::whole((string)$xml);
				self::fail('refused: ' . substr((string)$xml, 0, 40));
			} catch (InvalidInputException $e) {
				self::assertSame($message, $e->getMessage());
			}
		}
	}

	public function testMissingMeasuresAreRefusedWithTheNumber(): void {
		$score = self::demo(self::GROSSER_GOTT);
		foreach ([['99', '100', 'There is no measure %s in this score.', ['99']],
			['1', '99', 'There is no measure %s in this score.', ['99']],
			['8', '5', 'Measure %s does not come after measure %s.', ['5', '8']]] as [$from, $to, $message, $parameters]) {
			try {
				MusicXmlExcerpt::cut($score, $from, $to);
				self::fail($from . '–' . $to . ' is refused');
			} catch (InvalidInputException $e) {
				self::assertSame($message, $e->getMessage());
				self::assertSame($parameters, $e->getParameters());
			}
		}
	}

	public function testOnlyPartwiseMusicXmlIsAccepted(): void {
		$timewise = '<?xml version="1.0"?><score-timewise version="4.0"><part-list><score-part id="P1"><part-name>A</part-name></score-part>'
			. '</part-list><measure number="1"><part id="P1">' . self::NOTE . '</part></measure></score-timewise>';
		$subset = '<?xml version="1.0"?><!DOCTYPE score-partwise [<!ENTITY x "y">]>' . substr(self::score([self::NOTE]), strlen('<?xml version="1.0" encoding="UTF-8"?>'));
		foreach ([
			$timewise => 'Links for choir members and public links need a score in the partwise MusicXML format.',
			$subset => 'This file is not a MusicXML score.',
			'<score-partwise xmlns="urn:x"/>' => 'This file is not a MusicXML score.',
			'<html/>' => 'This file is not a MusicXML score.',
			'not xml' => 'This file is not a MusicXML score.',
			'' => 'This file is not a MusicXML score.',
		] as $xml => $message) {
			try {
				MusicXmlExcerpt::cut((string)$xml, '1', '1');
				self::fail('refused: ' . substr((string)$xml, 0, 40));
			} catch (InvalidInputException $e) {
				self::assertSame($message, $e->getMessage());
			}
		}
	}
}
