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
 * A migration counts as released as soon as it is on `main`, where test
 * instances run it: its file and checksum are added below in the same pull
 * request (sha256sum lib/Migration/<file>); testEveryMigrationIsListed
 * fails in that pull request otherwise, and `tools/release/package.sh`
 * stops for a release when a migration of the archive is missing here.
 *
 * A failing hash means a released migration was changed: undo that change
 * and add a new migration. Never update the hash of a listed file.
 */
class ReleasedMigrationsTest extends TestCase {
	/** File name => SHA-256 of the released migrations */
	private const RELEASED = [
		'Version001700Date20261030000000.php' => 'd08479cff65cc171a58dce790bee0f2e0681554224103da7d2a290698c58c429',
		'Version001700Date20261103140000.php' => 'e3fcf49739ec5189ad91ef4d7fe6028c21ca97f8ba5068d5862799c59213c4c9',
		'Version001700Date20261110120000.php' => '0df2374ff234f02719a8b2d701bc5cd07379163d07121f3403911e04b2c95e28',
		'Version001800Date20261112120000.php' => '5b06419e68f70b4ded6a2713482982bd510d40198fb6141c02699ad677b4bec3',
	];

	public function testReleasedMigrationsAreUnchanged(): void {
		foreach (self::RELEASED as $file => $hash) {
			$path = __DIR__ . '/../../../lib/Migration/' . $file;
			self::assertFileExists($path, $file . ' was released and must stay');
			self::assertSame($hash, hash_file('sha256', $path), $file . ' was released and must not change; add a new migration instead');
		}
	}

	/**
	 * Every migration in lib/Migration is listed (T27): a migration counts as
	 * released once it is on `main`, so a pull request that adds one lists it
	 * here, not only the build of a tag. (ModuleDefaults is a helper of the
	 * migrations, not a migration.)
	 */
	public function testEveryMigrationIsListed(): void {
		$files = array_map('basename', glob(__DIR__ . '/../../../lib/Migration/Version*.php') ?: []);
		self::assertNotSame([], $files, 'lib/Migration holds the migrations');
		foreach ($files as $file) {
			self::assertArrayHasKey($file, self::RELEASED, $file . ' is not listed in RELEASED: add it with its checksum ('
				. hash_file('sha256', __DIR__ . '/../../../lib/Migration/' . $file) . ') in the pull request that adds it');
		}
		foreach (glob(__DIR__ . '/../../../lib/Migration/*.php') ?: [] as $path) {
			$class = 'OCA\\Cantoria\\Migration\\' . basename($path, '.php');
			if (!str_starts_with(basename($path), 'Version')) {
				self::assertFalse(is_subclass_of($class, \OCP\Migration\IMigrationStep::class), basename($path) . ' is a migration, but Nextcloud only runs files named Version*.php');
			}
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
