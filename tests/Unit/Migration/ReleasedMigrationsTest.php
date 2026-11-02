<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;

/**
 * A migration that was released is never changed again: an instance that ran
 * it does not run it a second time, so a change would reach new
 * installations only. A change of the schema is a new migration.
 *
 * When a version with a new migration is released, its file and checksum
 * are added below (sha256sum lib/Migration/<file>).
 */
class ReleasedMigrationsTest extends TestCase {
	/** File name => SHA-256 of the released migrations */
	private const RELEASED = [
		'Version001700Date20261030000000.php' => 'd08479cff65cc171a58dce790bee0f2e0681554224103da7d2a290698c58c429',
	];

	public function testReleasedMigrationsAreUnchanged(): void {
		foreach (self::RELEASED as $file => $hash) {
			$path = __DIR__ . '/../../../lib/Migration/' . $file;
			self::assertFileExists($path, $file . ' was released and must stay');
			self::assertSame($hash, hash_file('sha256', $path), $file . ' was released and must not change; add a new migration instead');
		}
	}

	public function testEveryMigrationIsNewerThanTheReleasedOnes(): void {
		$files = array_map('basename', glob(__DIR__ . '/../../../lib/Migration/Version*.php') ?: []);
		$released = array_keys(self::RELEASED);
		$last = max($released);
		foreach ($files as $file) {
			self::assertTrue(in_array($file, $released, true) || strcmp($file, $last) > 0,
				$file . ' sorts before the last released migration, so an updated instance would never run it');
		}
	}
}
