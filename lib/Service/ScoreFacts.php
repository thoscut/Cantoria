<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

/**
 * What a MusicXML score tells about itself, read once and kept with the
 * file (ScoreFactsService): the range of every voice, the first and last
 * tones, the key at the start and at the end, the time signature, the
 * tempo, an incipit of the first bars and the lyrics of the top voice.
 *
 * Only reads; the file is never changed. Pitches are MIDI numbers of the
 * sounding pitch (middle C = 60). The voices are named from the part names
 * the same way as in the score viewer (`src/score/voices.ts`): "Tenor I/II"
 * on one staff gives Tenor I and Tenor II.
 *
 * The incipit is written in the Plaine & Easie Code (the format of RISM),
 * which Verovio renders: a few bytes per piece instead of a cut MusicXML.
 *
 * @psalm-type FactVoice = array{name: ?string, group: ?string, part: string, low: int, high: int, tessLow: int, tessHigh: int,
 *     first: int, last: int, notes: int}
 * @psalm-type FactKey = array{fifths: int, mode: ?string}
 * @psalm-type FactIncipit = array{clef: string, keysig: string, timesig: string, data: string}
 * @psalm-type FactLyrics = array{voice: ?string, verses: list<array{number: string, text: string}>}
 * @psalm-type Facts = array{format: int, voices: list<FactVoice>, keyStart: ?FactKey, keyEnd: ?FactKey, time: ?string,
 *     tempo: ?int, measures: int, incipit: ?FactIncipit, lyrics: ?FactLyrics}
 */
class ScoreFacts {
	/** Raised when what the analysis gives changes, so that stored facts are read again */
	public const FORMAT = 1;

	/** Voice groups of a choir, top to bottom */
	public const GROUPS = ['S', 'A', 'T', 'B'];

	/** Lyrics are cut after this many characters per verse */
	private const MAX_LYRICS = 4000;
	/** Notes per voice and in the whole score that are read at most */
	private const MAX_NOTES = 200000;
	/** Full bars in the incipit (a pickup comes on top) */
	private const INCIPIT_BARS = 2;

	private const STEPS = ['C' => 0, 'D' => 2, 'E' => 4, 'F' => 5, 'G' => 7, 'A' => 9, 'B' => 11];

	private const SHARPS = 'FCGDAEB';
	private const FLATS = 'BEADGCF';

	private const PAE_DURATIONS = ['maxima' => '9', 'long' => '0', 'breve' => '0', 'whole' => '1', 'half' => '2', 'quarter' => '4',
		'eighth' => '8', '16th' => '6', '32nd' => '3', '64th' => '5', '128th' => '7'];

	/** Part names of instruments: their staves are no voices of the choir */
	private const INSTRUMENT = '/\b(piano(forte)?|klavier|flügel|orgel|organ|keyboard|cembalo|harpsichord|gitarre|guitar|harfe|harp|'
		. 'violin[ea]?|geige|viola|cello|violoncello|kontrabass|double bass|contrabass|flöte|flute|oboe|klarinette|clarinet|fagott|bassoon|'
		. 'trompete|trumpet|horn|posaune|trombone|tuba|pauken?|timpani|percussion|schlagzeug|continuo|b\.\s*c\.|akkordeon|accordion|'
		. 'begleitung|accompaniment|instrument)\b/iu';

	private const NUMERAL = '(?:IV|I{1,3}|[1-4])';

	private const ABBREVIATIONS = ['T' => 'Tenor', 'B' => 'Bass', 'S' => 'Sopran', 'A' => 'Alt', 'Bar' => 'Bariton'];

	/**
	 * The facts of a MusicXML document (score-partwise); null when it is
	 * not one or cannot be read.
	 *
	 * @return Facts|null
	 */
	public static function analyze(string $xml): ?array {
		$document = self::load($xml);
		$root = $document?->documentElement;
		if ($document === null || $root === null || $root->nodeName !== 'score-partwise') {
			return null;
		}
		$xpath = new \DOMXPath($document);

		/** @var array<string, array{name: string, vocal: ?bool}> $partInfo */
		$partInfo = [];
		foreach (self::elements($xpath->query('/score-partwise/part-list/score-part')) as $scorePart) {
			$name = trim(preg_replace('/\s+/u', ' ', self::text($xpath, 'part-name', $scorePart)) ?? '');
			$sound = self::text($xpath, 'score-instrument/instrument-sound', $scorePart);
			$vocal = $sound === '' ? null : str_starts_with($sound, 'voice.');
			$partInfo[$scorePart->getAttribute('id')] = ['name' => $name, 'vocal' => $vocal];
		}

		/** @var array<string, array{part: string, partIndex: int, staff: int, voice: string, pitches: array<int, int>, first: ?int, last: ?int, notes: int}> $members */
		$members = [];
		$keyStart = null;
		$keyEnd = null;
		$time = null;
		$tempo = null;
		$measures = 0;
		$total = 0;
		$partIndex = 0;
		/** @var array<string, array<string, true>> $stavesOfPart */
		$stavesOfPart = [];
		foreach (self::elements($xpath->query('/score-partwise/part')) as $part) {
			$id = $part->getAttribute('id');
			$info = $partInfo[$id] ?? ['name' => '', 'vocal' => null];
			$isInstrument = $info['vocal'] === false || ($info['vocal'] === null && preg_match(self::INSTRUMENT, $info['name']) === 1);
			$transpose = 0;
			$partMeasures = 0;
			foreach (self::children($part, 'measure') as $measure) {
				$partMeasures++;
				foreach (self::children($measure) as $element) {
					if ($element->nodeName === 'attributes') {
						foreach (self::children($element, 'key') as $key) {
							$fifths = self::childText($key, 'fifths');
							if ($fifths !== '' && is_numeric($fifths)) {
								$mode = self::childText($key, 'mode');
								$found = ['fifths' => max(-7, min(7, (int)$fifths)), 'mode' => $mode === '' ? null : $mode];
								if ($partIndex === 0) {
									$keyStart ??= $found;
									$keyEnd = $found;
								}
							}
						}
						foreach (self::children($element, 'time') as $timeElement) {
							$beats = self::childText($timeElement, 'beats');
							$type = self::childText($timeElement, 'beat-type');
							if ($time === null && $beats !== '' && $type !== '') {
								$time = $beats . '/' . $type;
							}
						}
						foreach (self::children($element, 'transpose') as $t) {
							$transpose = (int)self::childText($t, 'chromatic') + 12 * (int)self::childText($t, 'octave-change');
						}
					} elseif ($element->nodeName === 'direction' || $element->nodeName === 'sound') {
						$tempo ??= self::tempo($xpath, $element);
					} elseif ($element->nodeName === 'note' && !$isInstrument) {
						if (++$total > self::MAX_NOTES) {
							break 3;
						}
						$staff = max(1, (int)(self::childText($element, 'staff') ?: '1'));
						$voice = self::childText($element, 'voice') ?: '1';
						$stavesOfPart[$id][(string)$staff] = true;
						$pitch = self::pitch($element);
						if ($pitch === null || self::childElement($element, 'grace') !== null || self::childElement($element, 'cue') !== null) {
							continue;
						}
						$key = $id . '|' . $staff . '|' . $voice;
						if (!isset($members[$key])) {
							$members[$key] = ['part' => $id, 'partIndex' => $partIndex, 'staff' => $staff, 'voice' => $voice,
								'pitches' => [], 'first' => null, 'last' => null, 'notes' => 0];
						}
						$sounding = $pitch + $transpose;
						$duration = max(1, (int)self::childText($element, 'duration'));
						// Of a chord only the top note counts as the voice's tone for first and last.
						$inChord = self::childElement($element, 'chord') !== null;
						$members[$key]['pitches'][$sounding] = ($members[$key]['pitches'][$sounding] ?? 0) + $duration;
						$members[$key]['notes']++;
						if ($members[$key]['first'] === null) {
							$members[$key]['first'] = $sounding;
						}
						if (!$inChord || $members[$key]['last'] === null || $sounding > $members[$key]['last']) {
							$members[$key]['last'] = $sounding;
						}
					}
				}
			}
			$measures = max($measures, $partMeasures);
			$partIndex++;
		}

		$voices = self::voices($members, $partInfo, $stavesOfPart);
		return [
			'format' => self::FORMAT,
			'voices' => $voices,
			'keyStart' => $keyStart,
			'keyEnd' => $keyEnd,
			'time' => $time,
			'tempo' => $tempo,
			'measures' => $measures,
			'incipit' => self::incipit($xpath, $members, $voices),
			'lyrics' => self::lyrics($xpath, $members, $voices),
		];
	}

	/**
	 * The voice group (S, A, T, B) a voice name belongs to; null when the
	 * name does not tell ("Chor", "Stimme 1", an empty name).
	 */
	public static function group(?string $name): ?string {
		if ($name === null) {
			return null;
		}
		$n = mb_strtolower(trim($name));
		$n = preg_replace('/[\s.]+(' . self::NUMERAL . ')$/iu', '', $n) ?? $n;
		$n = trim(preg_replace('/\s+/u', ' ', $n) ?? $n);
		return match (true) {
			preg_match('/^(sopran[oei]?|soprane|s|diskant|discant|treble|superius|cantus)$/u', $n) === 1 => 'S',
			preg_match('/^(alt|alto|alti|a|mezzo(-?sopran[o]?)?|contralto|altus)$/u', $n) === 1 => 'A',
			preg_match('/^(tenor|tenore|tenori|t|tenöre)$/u', $n) === 1 => 'T',
			preg_match('/^(bass|basso|bassi|bässe|b|bariton|baritone|baritono|bar|bassus)$/u', $n) === 1 => 'B',
			default => null,
		};
	}

	/**
	 * Names a part name announces, like `splitPartName` in voices.ts.
	 *
	 * @return list<string>
	 */
	public static function splitPartName(string $raw): array {
		$name = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
		if ($name === '') {
			return [];
		}
		$list = '/^(.*?)[\s,]*(' . self::NUMERAL . '(?:(?:[\s.\/,&+-]|und|u\.)+' . self::NUMERAL . ')+)\.?$/u';
		if (preg_match($list, $name, $m) === 1 && trim($m[1]) !== '') {
			$base = rtrim($m[1], " ,.");
			preg_match_all('/' . self::NUMERAL . '/u', $m[2], $numerals);
			return array_map(fn (string $numeral): string => $base . ' ' . $numeral, $numerals[0]);
		}
		$tokens = array_values(array_filter(preg_split('/\s*[\/&+]\s*/u', $name) ?: [], fn (string $t): bool => $t !== ''));
		if (count($tokens) >= 2) {
			return array_map(function (string $token): string {
				if (preg_match('/^([A-Za-zÄÖÜäöüß]+?)\.?\s*(' . self::NUMERAL . ')\.?$/u', $token, $m) !== 1) {
					return $token;
				}
				return (self::ABBREVIATIONS[$m[1]] ?? $m[1]) . ' ' . $m[2];
			}, $tokens);
		}
		return [$name];
	}

	/**
	 * @param array<string, array{part: string, partIndex: int, staff: int, voice: string, pitches: array<int, int>, first: ?int, last: ?int, notes: int}> $members
	 * @param array<string, array{name: string, vocal: ?bool}> $partInfo
	 * @param array<string, array<string, true>> $stavesOfPart
	 * @return list<FactVoice>
	 */
	private static function voices(array $members, array $partInfo, array $stavesOfPart): array {
		uasort($members, fn (array $a, array $b): int => [$a['partIndex'], $a['staff'], (int)$a['voice']] <=> [$b['partIndex'], $b['staff'], (int)$b['voice']]);
		/** @var array<string, list<string>> $byPart */
		$byPart = [];
		foreach ($members as $key => $member) {
			$byPart[$member['part']][] = $key;
		}
		$named = [];
		foreach ($byPart as $partId => $keys) {
			$partName = $partInfo[$partId]['name'] ?? '';
			$names = self::splitPartName($partName);
			$oneStaff = count($stavesOfPart[$partId] ?? []) <= 1;
			foreach ($keys as $index => $key) {
				$name = null;
				if (count($keys) === 1) {
					$name = $partName === '' ? null : $partName;
				} elseif ($oneStaff && count($names) >= 2) {
					$name = $names[$index] ?? null;
				}
				$named[] = ['key' => $key, 'name' => $name, 'part' => $partName];
			}
		}
		// Four unnamed voices of one choir part on two staves: S, A, T, B in score order.
		if (count($byPart) === 1 && count($named) === 4 && array_filter($named, fn (array $v): bool => $v['name'] !== null) === []) {
			foreach (['Sopran', 'Alt', 'Tenor', 'Bass'] as $index => $name) {
				$named[$index]['name'] = $name;
			}
		}

		/** @var array<string, array{name: ?string, part: string, pitches: array<int, int>, first: ?int, last: ?int, notes: int}> $merged */
		$merged = [];
		foreach ($named as $index => $entry) {
			$member = $members[$entry['key']];
			$id = $entry['name'] === null ? '#' . $index : mb_strtolower(str_replace(['ß', '.'], ['ss', ' '], preg_replace('/\s+/u', ' ', $entry['name']) ?? ''));
			$id = trim(preg_replace('/\s+/u', ' ', $id) ?? $id);
			if (!isset($merged[$id])) {
				$merged[$id] = ['name' => $entry['name'], 'part' => $entry['part'], 'pitches' => [], 'first' => $member['first'],
					'last' => $member['last'], 'notes' => 0];
			}
			foreach ($member['pitches'] as $pitch => $weight) {
				$merged[$id]['pitches'][$pitch] = ($merged[$id]['pitches'][$pitch] ?? 0) + $weight;
			}
			$merged[$id]['notes'] += $member['notes'];
			$merged[$id]['last'] = $member['last'] ?? $merged[$id]['last'];
		}

		$voices = [];
		foreach ($merged as $voice) {
			if ($voice['pitches'] === [] || $voice['first'] === null || $voice['last'] === null) {
				continue;
			}
			ksort($voice['pitches']);
			[$tessLow, $tessHigh] = self::tessitura($voice['pitches']);
			$voices[] = [
				'name' => $voice['name'],
				'group' => self::group($voice['name']),
				'part' => $voice['part'],
				'low' => (int)array_key_first($voice['pitches']),
				'high' => (int)array_key_last($voice['pitches']),
				'tessLow' => $tessLow,
				'tessHigh' => $tessHigh,
				'first' => $voice['first'],
				'last' => $voice['last'],
				'notes' => $voice['notes'],
			];
		}
		return $voices;
	}

	/**
	 * Where a voice mostly sings: the pitches between the 10th and the 90th
	 * percentile of the sung time.
	 *
	 * @param array<int, int> $pitches pitch => time, sorted by pitch
	 * @return array{int, int}
	 */
	public static function tessitura(array $pitches): array {
		$total = array_sum($pitches);
		$low = (int)array_key_first($pitches);
		$high = (int)array_key_last($pitches);
		$sum = 0;
		$foundLow = null;
		foreach ($pitches as $pitch => $weight) {
			$sum += $weight;
			if ($foundLow === null && $sum >= 0.1 * $total) {
				$foundLow = $pitch;
			}
			if ($sum >= 0.9 * $total) {
				$high = $pitch;
				break;
			}
		}
		return [$foundLow ?? $low, $high];
	}

	/**
	 * The first bars of the top voice in the Plaine & Easie Code.
	 *
	 * @param array<string, array{part: string, partIndex: int, staff: int, voice: string, pitches: array<int, int>, first: ?int, last: ?int, notes: int}> $members
	 * @param list<FactVoice> $voices
	 * @return FactIncipit|null
	 */
	private static function incipit(\DOMXPath $xpath, array $members, array $voices): ?array {
		$top = self::topMember($members);
		if ($top === null) {
			return null;
		}
		$part = self::part($xpath, $top['part']);
		if ($part === null) {
			return null;
		}
		$clef = 'G-2';
		$keysig = '';
		$timesig = '';
		$data = '';
		$octave = null;
		$duration = null;
		$bars = 0;
		$clefSet = false;
		foreach (self::children($part, 'measure') as $index => $measure) {
			$pickup = $index === 0 && $measure->getAttribute('implicit') === 'yes';
			$tokens = '';
			foreach (self::children($measure) as $element) {
				if ($element->nodeName === 'attributes' && $index === 0) {
					foreach (self::children($element, 'clef') as $clefElement) {
						$number = (int)($clefElement->getAttribute('number') ?: '1');
						if ($number === $top['staff'] && !$clefSet) {
							$clef = self::paeClef($clefElement);
							$clefSet = true;
						}
					}
					foreach (self::children($element, 'key') as $key) {
						$fifths = (int)self::childText($key, 'fifths');
						$keysig = $fifths > 0 ? 'x' . substr(self::SHARPS, 0, min(7, $fifths)) : ($fifths < 0 ? 'b' . substr(self::FLATS, 0, min(7, -$fifths)) : '');
					}
					foreach (self::children($element, 'time') as $time) {
						$symbol = $time->getAttribute('symbol');
						$beats = self::childText($time, 'beats');
						$type = self::childText($time, 'beat-type');
						$timesig = $symbol === 'common' ? 'c' : ($symbol === 'cut' ? 'c/' : ($beats !== '' && $type !== '' ? $beats . '/' . $type : ''));
					}
				}
				if ($element->nodeName !== 'note') {
					continue;
				}
				$staff = max(1, (int)(self::childText($element, 'staff') ?: '1'));
				$voice = self::childText($element, 'voice') ?: '1';
				if ($staff !== $top['staff'] || $voice !== $top['voice'] || self::childElement($element, 'chord') !== null
					|| self::childElement($element, 'grace') !== null) {
					continue;
				}
				$type = self::childText($element, 'type');
				$code = self::PAE_DURATIONS[$type] ?? null;
				if ($code === null) {
					// A whole-bar rest has no type.
					if (self::childElement($element, 'rest') !== null) {
						$tokens .= '=';
					}
					continue;
				}
				$code .= str_repeat('.', min(2, count(self::children($element, 'dot'))));
				if ($code !== $duration) {
					$tokens .= $code;
					$duration = $code;
				}
				$pitchElement = self::childElement($element, 'pitch');
				if ($pitchElement === null) {
					$tokens .= '-';
					continue;
				}
				$step = strtoupper(self::childText($pitchElement, 'step'));
				$noteOctave = (int)self::childText($pitchElement, 'octave');
				if (!isset(self::STEPS[$step])) {
					continue;
				}
				if ($noteOctave !== $octave) {
					$tokens .= $noteOctave >= 4 ? str_repeat("'", $noteOctave - 3) : str_repeat(',', max(1, 4 - $noteOctave));
					$octave = $noteOctave;
				}
				$accidental = self::childText($element, 'accidental');
				$tokens .= match ($accidental) {
					'sharp' => 'x',
					'flat' => 'b',
					'natural' => 'n',
					'double-sharp', 'sharp-sharp' => 'xx',
					'flat-flat' => 'bb',
					default => '',
				};
				$tokens .= $step;
				foreach (self::children($element, 'tie') as $tie) {
					if ($tie->getAttribute('type') === 'start') {
						$tokens .= '+';
						break;
					}
				}
			}
			$data .= $tokens . '/';
			if (!$pickup && ++$bars >= self::INCIPIT_BARS) {
				break;
			}
		}
		$data = rtrim($data, '/');
		if (trim(str_replace(['/', '=', '-'], '', $data)) === '') {
			return null;
		}
		return ['clef' => $clef, 'keysig' => $keysig, 'timesig' => $timesig, 'data' => $data];
	}

	/**
	 * The lyrics of the top voice that has any, verse by verse, words joined
	 * from their syllables, with a line break after punctuation.
	 *
	 * @param array<string, array{part: string, partIndex: int, staff: int, voice: string, pitches: array<int, int>, first: ?int, last: ?int, notes: int}> $members
	 * @param list<FactVoice> $voices
	 * @return FactLyrics|null
	 */
	private static function lyrics(\DOMXPath $xpath, array $members, array $voices): ?array {
		uasort($members, fn (array $a, array $b): int => [$a['partIndex'], $a['staff'], (int)$a['voice']] <=> [$b['partIndex'], $b['staff'], (int)$b['voice']]);
		foreach ($members as $member) {
			$part = self::part($xpath, $member['part']);
			if ($part === null) {
				continue;
			}
			/** @var array<string, string> $verses */
			$verses = [];
			foreach (self::children($part, 'measure') as $measure) {
				foreach (self::children($measure, 'note') as $note) {
					$staff = max(1, (int)(self::childText($note, 'staff') ?: '1'));
					$voice = self::childText($note, 'voice') ?: '1';
					if ($staff !== $member['staff'] || $voice !== $member['voice']) {
						continue;
					}
					foreach (self::children($note, 'lyric') as $lyric) {
						$number = $lyric->getAttribute('number') ?: '1';
						$texts = self::children($lyric, 'text');
						if ($texts === []) {
							continue;
						}
						$text = implode('', array_map(fn (\DOMElement $t): string => $t->textContent, $texts));
						$syllabic = self::childText($lyric, 'syllabic') ?: 'single';
						$current = $verses[$number] ?? '';
						if (mb_strlen($current) > self::MAX_LYRICS) {
							continue;
						}
						$current .= trim($text);
						$current .= in_array($syllabic, ['begin', 'middle'], true) ? '' : ' ';
						$verses[$number] = $current;
					}
				}
			}
			if ($verses === []) {
				continue;
			}
			uksort($verses, fn (string $a, string $b): int => [(int)$a, $a] <=> [(int)$b, $b]);
			$list = [];
			foreach ($verses as $number => $text) {
				$text = trim(preg_replace('/ {2,}/', ' ', $text) ?? $text);
				$text = preg_replace('/([,.;:!?])\s+/u', "$1\n", $text) ?? $text;
				$list[] = ['number' => (string)$number, 'text' => mb_substr($text, 0, self::MAX_LYRICS)];
			}
			$name = null;
			foreach ($voices as $voice) {
				if ($voice['part'] === (self::partName($xpath, $member['part']))) {
					$name = $voice['name'];
					break;
				}
			}
			return ['voice' => $name, 'verses' => $list];
		}
		return null;
	}

	/**
	 * The member that sings highest at the start: the first voice of the
	 * first vocal part.
	 *
	 * @param array<string, array{part: string, partIndex: int, staff: int, voice: string, pitches: array<int, int>, first: ?int, last: ?int, notes: int}> $members
	 * @return array{part: string, partIndex: int, staff: int, voice: string, pitches: array<int, int>, first: ?int, last: ?int, notes: int}|null
	 */
	private static function topMember(array $members): ?array {
		$top = null;
		foreach ($members as $member) {
			if ($top === null || [$member['partIndex'], $member['staff'], (int)$member['voice']] < [$top['partIndex'], $top['staff'], (int)$top['voice']]) {
				$top = $member;
			}
		}
		return $top;
	}

	private static function paeClef(\DOMElement $clef): string {
		$sign = strtoupper(self::childText($clef, 'sign'));
		$line = self::childText($clef, 'line');
		$octave = (int)self::childText($clef, 'clef-octave-change');
		return match ($sign) {
			'F' => 'F-' . ($line === '' ? '4' : $line),
			'C' => 'C-' . ($line === '' ? '3' : $line),
			default => ($octave === -1 ? 'g' : 'G') . '-' . ($line === '' ? '2' : $line),
		};
	}

	/** The pitch of a note as written (MIDI), or null for a rest or an unpitched note. */
	private static function pitch(\DOMElement $note): ?int {
		$pitch = self::childElement($note, 'pitch');
		if ($pitch === null) {
			return null;
		}
		$step = strtoupper(self::childText($pitch, 'step'));
		$octave = self::childText($pitch, 'octave');
		if (!isset(self::STEPS[$step]) || $octave === '' || !is_numeric($octave)) {
			return null;
		}
		$alter = self::childText($pitch, 'alter');
		return ((int)$octave + 1) * 12 + self::STEPS[$step] + (int)round(is_numeric($alter) ? (float)$alter : 0.0);
	}

	private static function tempo(\DOMXPath $xpath, \DOMElement $element): ?int {
		$sound = $element->nodeName === 'sound' ? $element : self::childElement($element, 'sound');
		$value = $sound?->getAttribute('tempo') ?? '';
		if ($value === '') {
			$value = self::text($xpath, 'direction-type/metronome/per-minute', $element);
		}
		if (!is_numeric($value)) {
			return null;
		}
		$tempo = (int)round((float)$value);
		return $tempo >= 20 && $tempo <= 400 ? $tempo : null;
	}

	private static function part(\DOMXPath $xpath, string $id): ?\DOMElement {
		foreach (self::elements($xpath->query('/score-partwise/part')) as $part) {
			if ($part->getAttribute('id') === $id) {
				return $part;
			}
		}
		return null;
	}

	private static function partName(\DOMXPath $xpath, string $id): string {
		foreach (self::elements($xpath->query('/score-partwise/part-list/score-part')) as $scorePart) {
			if ($scorePart->getAttribute('id') === $id) {
				return trim(preg_replace('/\s+/u', ' ', self::text($xpath, 'part-name', $scorePart)) ?? '');
			}
		}
		return '';
	}

	private static function load(string $xml): ?\DOMDocument {
		if ($xml === '') {
			return null;
		}
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		try {
			// No LIBXML_NOENT and no DTD loading: entities are not expanded and nothing external is fetched.
			$loaded = $document->loadXML($xml, LIBXML_NONET);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		if (!$loaded) {
			return null;
		}
		$doctype = $document->doctype;
		if ($doctype !== null && trim((string)$doctype->internalSubset) !== '') {
			return null;
		}
		return $document;
	}

	private static function text(\DOMXPath $xpath, string $path, \DOMNode $context): string {
		$nodes = $xpath->query($path, $context);
		$node = $nodes === false ? null : $nodes->item(0);
		return $node === null ? '' : trim($node->textContent);
	}

	private static function childElement(\DOMElement $parent, string $name): ?\DOMElement {
		foreach ($parent->childNodes as $child) {
			if ($child instanceof \DOMElement && $child->nodeName === $name) {
				return $child;
			}
		}
		return null;
	}

	private static function childText(\DOMElement $parent, string $name): string {
		return trim(self::childElement($parent, $name)?->textContent ?? '');
	}

	/**
	 * @return list<\DOMElement>
	 */
	private static function children(\DOMElement $parent, ?string $name = null): array {
		$list = [];
		foreach ($parent->childNodes as $child) {
			if ($child instanceof \DOMElement && ($name === null || $child->nodeName === $name)) {
				$list[] = $child;
			}
		}
		return $list;
	}

	/**
	 * @param \DOMNodeList<\DOMNode>|false $list
	 * @return list<\DOMElement>
	 */
	private static function elements(\DOMNodeList|false $list): array {
		$elements = [];
		if ($list !== false) {
			foreach ($list as $node) {
				if ($node instanceof \DOMElement) {
					$elements[] = $node;
				}
			}
		}
		return $elements;
	}
}
