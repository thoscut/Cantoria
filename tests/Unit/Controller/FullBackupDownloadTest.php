<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Controller;

use OCA\Cantoria\Controller\WorkflowController;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\ExportService;
use OCA\Cantoria\Service\FullBackupService;
use OCA\Cantoria\Service\LicenseService;
use OCA\Cantoria\Service\ProposalService;
use OCA\Cantoria\Service\ReleaseListService;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\UserFiles;
use OCA\Cantoria\Service\ValidVersionService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The complete backup names the choir members of the folder book: every
 * download is in the audit log (who, when), a refused one is not.
 */
#[CoversClass(WorkflowController::class)]
final class FullBackupDownloadTest extends TestCase {
	/** @var list<array{?string, string, string}> uid, object type, action */
	private array $recorded = [];

	private function controller(bool $access, bool $admin, bool $reader): WorkflowController {
		$roles = $this->createStub(Roles::class);
		$roles->method('hasAccess')->willReturn($access);
		$roles->method('isAdmin')->willReturn($admin);
		$roles->method('hasAnyRole')->willReturn($reader);
		$audit = $this->createStub(AuditLog::class);
		$audit->method('record')->willReturnCallback(function (string $operation, ?string $uid, string $type, ?int $id, string $action): void {
			$this->recorded[] = [$uid, $type, $action];
		});
		$backup = $this->createStub(FullBackupService::class);
		$backup->method('export')->willReturn('{"format":"cantoria-backup"}');
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		return new WorkflowController($this->createStub(IRequest::class), 'kim', $roles, $this->createStub(RightsService::class),
			$this->createStub(ValidVersionService::class), $this->createStub(LicenseService::class), $this->createStub(ProposalService::class),
			$this->createStub(ReleaseListService::class), $this->createStub(ExportService::class), $backup, $this->createStub(UserFiles::class),
			$l, $this->createStub(LoggerInterface::class), $this->createStub(IUserManager::class), $audit);
	}

	public function testEveryDownloadIsRecorded(): void {
		try {
			$this->controller(true, false, true)->fullBackup();
		} catch (\Error $e) {
			// Without a Nextcloud server the download response lacks a Symfony
			// class; the audit entry comes before the response.
			self::assertStringContainsString('HeaderUtils', $e->getMessage());
		}
		self::assertSame([['kim', 'export', 'download_backup']], $this->recorded);
	}

	public function testARefusedDownloadIsNotRecorded(): void {
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(true, false, false)->fullBackup()->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(false, false, false)->fullBackup()->getStatus());
		self::assertSame([], $this->recorded);
	}
}
