<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\ArchiveFile;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\ExcerptLinkMapper;
use OCA\Cantoria\Db\LicenseMapper;
use OCA\Cantoria\Db\MirrorFileMapper;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\HandoverService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\NotificationService;
use OCA\Cantoria\Service\ReleaseListService;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\RunLock;
use OCA\Cantoria\Service\ScoreService;
use OCA\Cantoria\Service\StateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDateTimeZone;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The hand-over folder reaches choir members and a choir system: a MusicXML
 * file goes in cleaned like the score of a link (no encoder, no notes, no
 * comments), and a file that is no score not at all (S N4). Scans and
 * recordings go in as they are.
 */
#[CoversClass(HandoverService::class)]
final class HandoverContentTest extends TestCase {
	private const SCORE = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
		. '<!-- check measure 12, license ends in May -->'
		. '<score-partwise version="4.0"><identification><encoding><encoder>Kim Example</encoder><software>MuseScore</software></encoding>'
		. '<miscellaneous><miscellaneous-field name="notes">internal</miscellaneous-field></miscellaneous></identification>'
		. '<part-list><score-part id="P1"><part-name>Alto</part-name></score-part></part-list>'
		. '<part id="P1"><measure number="1"><?app hint?><note><rest/><duration>4</duration></note></measure></part></score-partwise>';

	private function service(bool $isScore): HandoverService {
		$scores = $this->createStub(ScoreService::class);
		$scores->method('isScore')->willReturn($isScore);
		$scores->method('scoreText')->willReturnCallback(fn (string $content): array => ['xml' => $content, 'entry' => null]);
		return new HandoverService($this->createStub(IAppConfig::class), $this->createStub(IRootFolder::class),
			$this->createStub(AppSettings::class), $this->createStub(ReleaseListService::class), $this->createStub(ArchiveFileMapper::class),
			$this->createStub(CopyMapper::class), $this->createStub(MirrorFileMapper::class), $this->createStub(AuditLog::class),
			$this->createStub(Roles::class), $this->createStub(Modules::class), $this->createStub(NotificationService::class),
			$this->createStub(IFilenameValidator::class), $this->createStub(IUserManager::class), $this->createStub(IFactory::class),
			$this->createStub(ITimeFactory::class), $this->createStub(IDateTimeZone::class), $this->createStub(LoggerInterface::class),
			$this->createStub(RunLock::class), $this->createStub(WorkVersionMapper::class), $this->createStub(RightsPolicy::class),
			$this->createStub(RightsService::class), $this->createStub(LicenseMapper::class), $this->createStub(ExcerptLinkMapper::class),
			$this->createStub(StateService::class), $scores);
	}

	private function source(string $content): File {
		$file = $this->createStub(File::class);
		$file->method('getSize')->willReturn(strlen($content));
		$file->method('fopen')->willReturnCallback(function () use ($content) {
			$handle = fopen('php://memory', 'r+');
			self::assertIsResource($handle);
			fwrite($handle, $content);
			rewind($handle);
			return $handle;
		});
		return $file;
	}

	/**
	 * @return resource|string
	 */
	private function written(HandoverService $service, string $role, string $content) {
		$file = new ArchiveFile();
		$file->setId(4);
		$file->setRole($role);
		$method = new \ReflectionMethod(HandoverService::class, 'content');
		/** @var resource|string */
		return $method->invoke($service, $file, $this->source($content));
	}

	public function testMusicXmlGoesInCleaned(): void {
		$written = $this->written($this->service(true), 'musicxml', self::SCORE);
		self::assertIsString($written);
		self::assertStringContainsString('<part-name>Alto</part-name>', $written);
		self::assertStringContainsString('<software>MuseScore</software>', $written);
		foreach (['Kim Example', '<encoder', 'internal', '<miscellaneous', '<!--', 'license ends', '<?app'] as $gone) {
			self::assertStringNotContainsString($gone, $written);
		}
	}

	public function testAFileThatIsNoScoreStaysOut(): void {
		$this->expectException(InvalidInputException::class);
		$this->written($this->service(false), 'musicxml', '<html></html>');
	}

	public function testScansAndRecordingsGoInAsTheyAre(): void {
		$written = $this->written($this->service(false), 'score_pdf', '%PDF-1.7 <!-- kept -->');
		self::assertIsResource($written);
		self::assertSame('%PDF-1.7 <!-- kept -->', stream_get_contents($written));
	}
}
