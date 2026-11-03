<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\ExcerptLink;
use OCA\Cantoria\Db\ExcerptLinkMapper;
use OCA\Cantoria\Db\License;
use OCA\Cantoria\Db\LicenseMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\LinkService;
use OCA\Cantoria\Service\LinkTokens;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\ScanService;
use OCA\Cantoria\Service\ScoreService;
use OCA\Cantoria\Tests\Unit\Support\FakeCrypto;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The list of links (U40, U41): links that ended in the last 30 days stay
 * in it, so that they can still be extended, and each link the account may
 * extend says the latest last day the server allows today and why.
 */
#[CoversClass(LinkService::class)]
#[UsesClass(RightsPolicy::class)]
#[UsesClass(LinkTokens::class)]
#[UsesClass(ExcerptLink::class)]
#[UsesClass(License::class)]
final class LinkListTest extends TestCase {
	private const TODAY = '2026-11-03';

	private static function link(int $id, string $expires, string $made, string $circle = ExcerptLink::CIRCLE_INTERNAL, string $creator = 'lena'): ExcerptLink {
		$link = new ExcerptLink();
		$link->setId($id);
		$link->setCopyId(1);
		$link->setCircle($circle);
		$link->setCreatedBy($creator);
		$link->setExpires($expires);
		$link->setCreated((int)strtotime($made . ' 12:00:00'));
		return $link;
	}

	/**
	 * @param list<ExcerptLink> $links
	 * @param list<License> $licenses
	 */
	private function service(array $links, array $licenses = [], bool $librarian = false): LinkService {
		$mapper = $this->createStub(ExcerptLinkMapper::class);
		$mapper->method('findByCopy')->willReturn($links);
		$copy = new Copy();
		$copy->setId(1);
		$copy->setVersionId(11);
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('findAll')->willReturn([$copy]);
		$version = new WorkVersion();
		$version->setId(11);
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('findAllById')->willReturn([11 => $version]);
		$roles = $this->createStub(Roles::class);
		$roles->method('hasAnyRole')->willReturn($librarian);
		$rights = $this->createStub(RightsService::class);
		$rights->method('today')->willReturn(self::TODAY);
		$licenseMapper = $this->createStub(LicenseMapper::class);
		$licenseMapper->method('findByVersion')->willReturn($licenses);
		$random = $this->createStub(ISecureRandom::class);
		$random->method('generate')->willReturn('abcdefghijABCDEFGHIJ0123456789xy');
		return new LinkService($mapper, $copies, $versions, $this->createStub(ArchiveFileMapper::class), $this->createStub(ScoreService::class),
			new RightsPolicy($roles, $rights, $licenseMapper), $rights, $this->createStub(AuditLog::class), $roles,
			$this->createStub(IUserManager::class), new LinkTokens(new FakeCrypto(), $random), $this->createStub(ITimeFactory::class),
			$this->createStub(Modules::class), $this->createStub(ScanService::class));
	}

	public function testLinksThatEndedInTheLastThirtyDaysStayInTheList(): void {
		$revoked = self::link(4, '2026-12-01', '2026-10-01');
		$revoked->setRevoked(1);
		$rows = $this->service([
			self::link(1, '2026-12-01', '2026-10-01'),
			self::link(2, '2026-10-04', '2026-09-01'),
			self::link(3, '2026-10-03', '2026-09-01'),
			$revoked,
		])->active(null, 'lena');
		self::assertSame([1, 2], array_column($rows, 'id'), 'active, and ended 30 days ago; not 31 days ago, never revoked');
	}

	public function testEachLinkSaysTheLatestDayItCanBeExtendedToAndWhy(): void {
		$rows = $this->service([
			self::link(1, '2026-12-01', '2026-10-01'),
			self::link(2, '2026-12-01', '2026-01-10'),
			self::link(3, '2026-10-20', '2025-10-20'),
		])->active(null, 'lena');
		self::assertSame([
			['2027-05-05', 'six_months'],
			['2027-01-10', 'age'],
			[null, 'age'],
		], array_map(fn (array $row): array => [$row['extend_until'], $row['extend_limit']], $rows),
			'six months from today, a year after it was made; not at all after that year');
	}

	public function testTheLicenseLimitsALinkForChoirMembers(): void {
		$license = new License();
		$license->setUses('portal');
		$license->setValidUntil('2027-02-28');
		$rows = $this->service([self::link(1, '2026-12-01', '2026-10-01', ExcerptLink::CIRCLE_MEMBERS)], [$license])->active(null, 'lena');
		self::assertSame(['2027-02-28', 'license'], [$rows[0]['extend_until'], $rows[0]['extend_limit']]);
	}

	public function testOnlyTheLinksTheAccountMayExtendSayIt(): void {
		$links = [self::link(1, '2026-12-01', '2026-10-01', creator: 'anna')];
		self::assertSame([null, null], [$this->service($links)->active(null, 'lena')[0]['extend_until'], $this->service($links)->active(null, 'lena')[0]['extend_limit']]);
		self::assertSame('2027-05-05', $this->service($links, librarian: true)->active(null, 'lena')[0]['extend_until'], 'the catalog librarian extends every link');
	}
}
