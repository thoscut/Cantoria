<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Service\PersonService;
use OCA\Cantoria\Service\ProtectionTerm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Why the resubmission list shows a version (PersonService::reviewKind).
 * A version confirmed as public domain whose proposal is now "unknown"
 * because the arranger is not recorded comes back for a check, also
 * without any recorded person (R B24, 0.18).
 */
#[CoversClass(PersonService::class)]
final class ProtectionReviewKindTest extends TestCase {
	private static function version(string $status, bool $confirmed): WorkVersion {
		$version = new WorkVersion();
		$version->setRightsStatus($status);
		if ($confirmed) {
			$version->setConfirmedBy('lena');
			$version->setConfirmedAt('2026-01-05');
		}
		return $version;
	}

	/**
	 * @return iterable<string, array{string, bool, array{status: string, reason?: string, persons?: int}, ?string}>
	 */
	public static function cases(): iterable {
		yield 'protected, free by the life dates' => ['protected', true, ['status' => ProtectionTerm::FREE, 'persons' => 1], 'ended'];
		yield 'public domain, protected by the persons' => ['public_domain', true, ['status' => ProtectionTerm::PROTECTED, 'persons' => 2], 'conflict'];
		yield 'public domain, unknown by the persons' => ['public_domain', false, ['status' => ProtectionTerm::UNKNOWN, 'reason' => 'death_unknown', 'persons' => 1], 'conflict'];
		yield 'confirmed public domain, arranger missing, no persons' => ['public_domain', true,
			['status' => ProtectionTerm::UNKNOWN, 'reason' => 'arranger_missing', 'persons' => 0], 'unknown'];
		yield 'unconfirmed public domain, arranger missing' => ['public_domain', false,
			['status' => ProtectionTerm::UNKNOWN, 'reason' => 'arranger_missing', 'persons' => 0], null];
		yield 'confirmed public domain, nothing recorded' => ['public_domain', true, ['status' => ProtectionTerm::UNKNOWN, 'reason' => 'no_persons', 'persons' => 0], null];
		yield 'protected with a known end' => ['protected', false, ['status' => ProtectionTerm::PROTECTED, 'persons' => 1], 'upcoming'];
		yield 'public domain and free' => ['public_domain', true, ['status' => ProtectionTerm::FREE, 'persons' => 1], null];
	}

	/**
	 * @param array{status: string, reason?: string, persons?: int} $proposal
	 */
	#[DataProvider('cases')]
	public function testKind(string $status, bool $confirmed, array $proposal, ?string $expected): void {
		self::assertSame($expected, PersonService::reviewKind(self::version($status, $confirmed), $proposal));
	}
}
