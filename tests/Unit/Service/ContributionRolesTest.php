<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\Contribution;
use OCA\Cantoria\Db\ContributionMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Person;
use OCA\Cantoria\Db\PersonMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\PersonService;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A person has each role at most once in a version, also when a role is
 * changed: composer and arranger may not both become composer.
 */
#[CoversClass(PersonService::class)]
final class ContributionRolesTest extends TestCase {
	/** @var list<Contribution> */
	private array $updated = [];

	private function service(): PersonService {
		$composer = self::contribution(1, 'composer');
		$arranger = self::contribution(2, 'arranger');
		$contributions = $this->createStub(ContributionMapper::class);
		$contributions->method('findByVersion')->willReturn([$composer, $arranger]);
		$contributions->method('find')->willReturnCallback(fn (int $id): Contribution => $id === 1 ? $composer : $arranger);
		$contributions->method('update')->willReturnCallback(function (Contribution $c): Contribution {
			$this->updated[] = $c;
			return $c;
		});
		$person = new Person();
		$person->setId(5);
		$person->setName('Josef Rheinberger');
		$persons = $this->createStub(PersonMapper::class);
		$persons->method('find')->willReturn($person);
		return new PersonService($persons, $contributions, $this->createStub(WorkVersionMapper::class), $this->createStub(WorkMapper::class),
			$this->createStub(CopyMapper::class), $this->createStub(AuditLog::class), $this->createStub(Roles::class),
			$this->createStub(RightsService::class), $this->createStub(IUserManager::class), $this->createStub(ITimeFactory::class));
	}

	private static function contribution(int $id, string $role): Contribution {
		$contribution = new Contribution();
		$contribution->setId($id);
		$contribution->setVersionId(3);
		$contribution->setPersonId(5);
		$contribution->setRole($role);
		$contribution->setTextForComposition(false);
		return $contribution;
	}

	public function testChangingARoleToOneThePersonHasIsRefused(): void {
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('This person already has this role in this version.');
		$this->service()->updateContribution(2, ['role' => 'composer'], 'lena');
	}

	public function testChangingARoleToANewOneIsSaved(): void {
		$this->service()->updateContribution(2, ['role' => 'lyricist'], 'lena');
		self::assertSame('lyricist', $this->updated[0]->getRole());
	}

	public function testKeepingTheRoleOfTheContributionIsNoDuplicate(): void {
		$this->service()->updateContribution(1, ['role' => 'composer', 'text_for_composition' => true], 'lena');
		self::assertTrue($this->updated[0]->getTextForComposition());
	}
}
