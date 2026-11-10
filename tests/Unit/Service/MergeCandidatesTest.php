<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\MergeMapper;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\CatalogSearch;
use OCA\Cantoria\Service\MergeService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\Sort;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Proposals for merging works: the same title and composer surname, or no
 * composer; titles compare like the search does.
 */
#[CoversClass(MergeService::class)]
#[UsesClass(CatalogSearch::class)]
#[UsesClass(Sort::class)]
final class MergeCandidatesTest extends TestCase {
	public function testTitlesCompareWithoutAccentsAndInEveryUnicodeForm(): void {
		$decomposed = "A\u{0308}nnchen von Tharau";
		self::assertSame(MergeService::normalizeTitle('Ännchen von Tharau'), MergeService::normalizeTitle($decomposed));
		self::assertSame('ave maria', MergeService::normalizeTitle('Ave María!'));
		self::assertSame('dvorak', MergeService::surname('Antonín Dvořák (1841-1904)'));
		self::assertSame('silcher', MergeService::surname('Friedrich Silcher, op. 26'));
	}

	/**
	 * @param list<array{0: int, 1: string, 2: ?string}> $works id, title, composer
	 */
	private function service(array $works): MergeService {
		$entities = [];
		$versions = [];
		$copies = [];
		foreach ($works as [$id, $title, $composer]) {
			$work = new Work();
			$work->setId($id);
			$work->setTitle($title);
			$work->setComposer($composer);
			$entities[$id] = $work;
			$version = new WorkVersion();
			$version->setId($id);
			$version->setWorkId($id);
			$versions[$id] = $version;
			$copy = new Copy();
			$copy->setId($id);
			$copy->setVersionId($id);
			$copy->setShelfmark('1-' . $id);
			$copy->setStatus('available');
			$copies[] = $copy;
		}
		$workMapper = $this->createStub(WorkMapper::class);
		$workMapper->method('findAllById')->willReturn($entities);
		$versionMapper = $this->createStub(WorkVersionMapper::class);
		$versionMapper->method('findAllById')->willReturn($versions);
		$copyMapper = $this->createStub(CopyMapper::class);
		$copyMapper->method('findAll')->willReturn($copies);
		return new MergeService($this->createStub(IDBConnection::class), $this->createStub(MergeMapper::class), $workMapper, $versionMapper,
			$copyMapper, $this->createStub(EventItemMapper::class), $this->createStub(AuditLog::class), $this->createStub(Roles::class),
			$this->createStub(ITimeFactory::class), $this->createStub(IUserManager::class));
	}

	public function testWorksWithoutComposerFormAGroupNextToSeveralComposers(): void {
		$groups = $this->service([[1, 'Abendlied', 'Josef Rheinberger'], [2, 'Abendlied', 'Felix Mendelssohn Bartholdy'],
			[3, 'Abendlied', null], [4, 'Abendlied', ''], [5, 'Abendlied', 'J. Rheinberger']])->candidates();
		$ids = array_map(fn (array $group): array => array_column($group['works'], 'workId'), $groups);
		self::assertContains([1, 5], $ids, 'the same surname');
		self::assertContains([3, 4], $ids, 'the two without a composer');
		self::assertCount(2, $groups, 'Mendelssohn alone is no group');
	}

	public function testWithOneComposerTheWorksWithoutOneJoinIt(): void {
		$groups = $this->service([[1, 'Ave Maria', 'Franz Biebl'], [2, 'Ave María', null]])->candidates();
		self::assertSame([[1, 2]], array_map(fn (array $group): array => array_column($group['works'], 'workId'), $groups));
	}
}
