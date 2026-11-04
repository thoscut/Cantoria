<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\ContributionMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Person;
use OCA\Cantoria\Db\PersonMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\PersonService;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The register of persons as a table: with the account that checked a
 * person for the backups, without it for the export folder inside the
 * archive folder, which every role group reads.
 */
#[CoversClass(PersonService::class)]
final class PersonTableTest extends TestCase {
	public function testTheTableForTheExportFolderHoldsNoAccount(): void {
		$person = new Person();
		$person->setId(5);
		$person->setName('Johann Example');
		$person->setCheckedBy('lena');
		$persons = $this->createStub(PersonMapper::class);
		$persons->method('findAll')->willReturn([$person]);
		$contributions = $this->createStub(ContributionMapper::class);
		$contributions->method('findAllByVersion')->willReturn([]);
		$service = new PersonService($persons, $contributions, $this->createStub(WorkVersionMapper::class), $this->createStub(WorkMapper::class),
			$this->createStub(CopyMapper::class), $this->createStub(AuditLog::class), $this->createStub(Roles::class),
			$this->createStub(RightsService::class), $this->createStub(IUserManager::class), $this->createStub(ITimeFactory::class));

		[$header, $rows] = $service->table();
		self::assertContains('checked_by', $header);
		self::assertContains('lena', $rows[0]);

		[$header, $rows] = $service->table(false);
		self::assertNotContains('checked_by', $header);
		self::assertNotContains('lena', $rows[0]);
		self::assertCount(count($header), $rows[0]);
	}
}
