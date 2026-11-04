<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Service\PageKeys;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The keys of programmes and pieces on the pages are an HMAC with the
 * secret of the instance (S N21): not a hash of a fixed text and the id,
 * which anyone could work out for every id.
 */
#[CoversClass(PageKeys::class)]
final class PageKeysTest extends TestCase {
	private function keys(string $secret): PageKeys {
		$crypto = $this->createStub(ICrypto::class);
		// Like Nextcloud: raw bytes of an HMAC with the secret of the instance
		$crypto->method('calculateHMAC')->willReturnCallback(fn (string $message): string => hash_hmac('sha512', $message, $secret, true));
		return new PageKeys($crypto);
	}

	private static function version(int $id): WorkVersion {
		$version = new WorkVersion();
		$version->setId($id);
		return $version;
	}

	public function testKeysAreStableAndDependOnTheSecretOfTheInstance(): void {
		$keys = $this->keys('secret of the instance');
		$practice = $keys->practice(self::version(7));
		self::assertMatchesRegularExpression('/^[0-9a-f]{' . PageKeys::LENGTH . '}$/', $practice);
		self::assertSame($practice, $this->keys('secret of the instance')->practice(self::version(7)), 'the same on every page');
		self::assertNotSame($practice, $keys->practice(self::version(8)));
		self::assertNotSame($practice, $this->keys('another instance')->practice(self::version(7)));
		self::assertNotSame($practice, $keys->programme(7), 'a programme and a piece with the same id have different keys');
	}

	public function testAKeyIsNoHashOfTheIdAlone(): void {
		$keys = $this->keys('secret of the instance');
		for ($id = 1; $id <= 50; $id++) {
			self::assertNotSame(substr(hash('sha256', 'cantoria-practice|' . $id), 0, 20), $keys->practice(self::version($id)));
			self::assertNotSame(substr(hash('sha256', 'cantoria-programme|' . $id), 0, 20), $keys->programme($id));
		}
	}
}
