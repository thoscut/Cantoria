<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Exception\InvalidInputException;

/**
 * Reads a complete backup (FullBackupService) from a stream, row by row,
 * without holding the file in memory (milestone 0.20). It knows only the
 * shape of a backup, not JSON in general:
 *
 *     { "<key>": <value>, …, "tables": { "<name>": [ { "<column>": <plain value>, … }, … ], … } }
 *
 * The keys may come in any order. A row is an object of plain values
 * (text, number, yes/no, null) and must not be empty; anything else, a
 * second "tables", a table named twice, text after the end or a value
 * nested too deep makes the file "not a backup". Values beside "tables"
 * that are plain are returned as the head; nested ones are skipped. A
 * caller that names the tables it knows has the others skipped, whatever
 * their shape (a later version may write more than rows).
 *
 * What is kept in memory is bounded by the reader, not only by the size
 * of the file: the columns and the text of one row, the names of the
 * tables, the values of the head.
 *
 * A caller that must know the head before it reads the rows (the format
 * version names the tables) reads the stream twice: once without a
 * callback, which also checks the whole file before anything is written,
 * then again from the start.
 *
 * @psalm-type BackupRow = array<string, string|int|float|bool|null>
 */
final class BackupReader {
	/** Bytes read from the stream at once */
	private const BLOCK = 65536;
	/** Longest text of one value or name (bytes, as written in the file) */
	private const MAX_TEXT = 16 * 1024 * 1024;
	/** Nesting accepted in a value beside "tables" (the whole file: eight levels) */
	private const MAX_DEPTH = 7;
	/** Columns of one row; the widest table of the app has fewer than 40 */
	private const MAX_COLUMNS = 512;
	/** Text of one row altogether (bytes, decoded) */
	private const MAX_ROW = 32 * 1024 * 1024;
	/** Tables named in a backup; the app has fewer than 60 */
	private const MAX_TABLES = 256;
	/** Plain values beside "tables", and the longest text kept of one (bytes) */
	private const MAX_HEAD = 64;
	private const MAX_HEAD_TEXT = 1024;

	private string $buffer = '';
	private int $position = 0;

	/**
	 * @param resource $stream
	 */
	private function __construct(
		private $stream,
	) {
	}

	/**
	 * @param resource $stream at the start of the backup
	 * @param (callable(string, BackupRow, int): void)|null $onRow called for every row with the name of its table as
	 *                                                             written in the file, the row and its place in the table (from 0)
	 * @param list<string>|null $known the names of the tables to read; every other table is skipped and not counted
	 *                                 (null: every table is read)
	 * @return array{head: array<string, string|int|float|bool|null>, tables: array<string, int>} the plain values beside
	 *                                                                                            "tables" (a text longer than 1024 bytes is left out), and the rows per table as named in the file
	 * @throws InvalidInputException when the stream is not a backup
	 */
	public static function read($stream, ?callable $onRow = null, ?array $known = null): array {
		$reader = new self($stream);
		$head = [];
		$tables = null;
		$reader->expect('{');
		if ($reader->peek() === '}') {
			$reader->position++;
		} else {
			do {
				$key = $reader->text();
				$reader->expect(':');
				if ($key === 'tables') {
					if ($tables !== null) {
						throw self::invalid();
					}
					$tables = $reader->tables($onRow, $known);
				} elseif (in_array($reader->peek(), ['{', '['], true)) {
					$reader->skip(1);
				} else {
					$value = $reader->plain();
					if (!is_string($value) || strlen($value) <= self::MAX_HEAD_TEXT) {
						$head[$key] = $value;
						if (count($head) > self::MAX_HEAD) {
							throw self::invalid();
						}
					}
				}
			} while ($reader->more('}'));
		}
		if ($reader->peek() !== '' || $tables === null) {
			throw self::invalid();
		}
		return ['head' => $head, 'tables' => $tables];
	}

	/**
	 * @param (callable(string, BackupRow, int): void)|null $onRow
	 * @param list<string>|null $known
	 * @return array<string, int> rows per table
	 */
	private function tables(?callable $onRow, ?array $known): array {
		$counts = [];
		$this->expect('{');
		if ($this->peek() === '}') {
			$this->position++;
			return $counts;
		}
		$named = 0;
		do {
			$name = $this->text();
			if (isset($counts[$name]) || ++$named > self::MAX_TABLES) {
				throw self::invalid();
			}
			$this->expect(':');
			if ($known !== null && !in_array($name, $known, true)) {
				// a table this version does not know: dropped, whatever its shape
				$this->skip(1);
				continue;
			}
			$this->expect('[');
			$count = 0;
			if ($this->peek() === ']') {
				$this->position++;
			} else {
				do {
					$row = $this->row();
					if ($onRow !== null) {
						$onRow($name, $row, $count);
					}
					$count++;
				} while ($this->more(']'));
			}
			$counts[$name] = $count;
		} while ($this->more('}'));
		return $counts;
	}

	/**
	 * @return BackupRow
	 */
	private function row(): array {
		$this->expect('{');
		$row = [];
		$bytes = 0;
		do {
			$column = $this->text();
			$this->expect(':');
			$value = $this->plain();
			$bytes += strlen($column) + (is_string($value) ? strlen($value) : 0);
			$row[$column] = $value;
			if (count($row) > self::MAX_COLUMNS || $bytes > self::MAX_ROW) {
				throw self::invalid();
			}
		} while ($this->more('}'));
		return $row;
	}

	/**
	 * After a value: a comma (another one follows, true) or the closing
	 * character (false).
	 */
	private function more(string $close): bool {
		$next = $this->peek();
		$this->position++;
		if ($next === ',') {
			return true;
		}
		if ($next === $close) {
			return false;
		}
		throw self::invalid();
	}

	private function expect(string $character): void {
		if ($this->peek() !== $character) {
			throw self::invalid();
		}
		$this->position++;
	}

	/**
	 * The next character that is no white space, without taking it; '' at
	 * the end of the stream.
	 */
	private function peek(): string {
		while (true) {
			$this->position += strspn($this->buffer, " \t\r\n", $this->position);
			if ($this->position < strlen($this->buffer)) {
				return $this->buffer[$this->position];
			}
			if (!$this->fill()) {
				return '';
			}
		}
	}

	/**
	 * The next block of the stream; false at its end.
	 */
	private function fill(): bool {
		$block = feof($this->stream) ? '' : fread($this->stream, self::BLOCK);
		if ($block === false || $block === '') {
			$this->buffer = '';
			$this->position = 0;
			return false;
		}
		$this->buffer = $block;
		$this->position = 0;
		return true;
	}

	/**
	 * A text in quotes, decoded.
	 */
	private function text(): string {
		$this->expect('"');
		$raw = '';
		while (true) {
			$length = strcspn($this->buffer, '"\\', $this->position);
			$raw .= substr($this->buffer, $this->position, $length);
			$this->position += $length;
			if (strlen($raw) > self::MAX_TEXT) {
				throw self::invalid();
			}
			if ($this->position >= strlen($this->buffer)) {
				if (!$this->fill()) {
					throw self::invalid();
				}
				continue;
			}
			if ($this->buffer[$this->position] === '"') {
				$this->position++;
				break;
			}
			// a backslash with the character it escapes, which may be in the next block
			$this->position++;
			if ($this->position >= strlen($this->buffer) && !$this->fill()) {
				throw self::invalid();
			}
			$raw .= '\\' . $this->buffer[$this->position];
			$this->position++;
		}
		try {
			$text = json_decode('"' . $raw . '"', false, 1, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw self::invalid();
		}
		if (!is_string($text)) {
			throw self::invalid();
		}
		return $text;
	}

	/**
	 * A plain value: text, number, true, false or null.
	 */
	private function plain(): string|int|float|bool|null {
		$next = $this->peek();
		if ($next === '"') {
			return $this->text();
		}
		$raw = '';
		while (true) {
			$length = strspn($this->buffer, '+-.0123456789Eaeflnrstu', $this->position);
			$raw .= substr($this->buffer, $this->position, $length);
			$this->position += $length;
			if (strlen($raw) > 64) {
				throw self::invalid();
			}
			if ($this->position < strlen($this->buffer) || !$this->fill()) {
				break;
			}
		}
		try {
			/** @var mixed $value */
			$value = json_decode($raw, false, 1, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw self::invalid();
		}
		if ($value !== null && !is_scalar($value)) {
			throw self::invalid();
		}
		return $value;
	}

	/**
	 * Skips a nested value beside "tables" (e.g. of a later version).
	 */
	private function skip(int $depth): void {
		if ($depth > self::MAX_DEPTH) {
			throw self::invalid();
		}
		$open = $this->peek();
		if ($open !== '{' && $open !== '[') {
			$this->plain();
			return;
		}
		$close = $open === '{' ? '}' : ']';
		$this->position++;
		if ($this->peek() === $close) {
			$this->position++;
			return;
		}
		do {
			if ($open === '{') {
				$this->text();
				$this->expect(':');
			}
			$this->skip($depth + 1);
		} while ($this->more($close));
	}

	private static function invalid(): InvalidInputException {
		return new InvalidInputException('The file is not a backup of the music archive.');
	}
}
