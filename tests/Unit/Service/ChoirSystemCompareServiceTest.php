<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\ChoirSystemCompareService;
use OCA\Cantoria\Service\ProposalService;
use OCA\Cantoria\Service\ReleaseListService;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\XlsxReader;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The export of the choir system against the list of the singers' portal:
 * the differences become proposals, nothing is changed directly.
 */
#[CoversClass(ChoirSystemCompareService::class)]
final class ChoirSystemCompareServiceTest extends TestCase {
	private ProposalService&MockObject $proposals;
	private ChoirSystemCompareService $service;
	/** @var list<array{0: int, 1: ?string}> proposals made: version, since */
	private array $proposed = [];

	#[\Override]
	protected function setUp(): void {
		$row = fn (int $id, string $shelfmark, string $status, ?string $reason = null): array => ['versionId' => $id,
			'copies' => [['id' => $id * 10, 'shelfmark' => $shelfmark]], 'title' => 'Title ' . $shelfmark, 'composer' => null,
			'status' => $status, 'reason' => $reason, 'portalSince' => null, 'validSince' => null, 'rightsStatus' => null, 'confirmed' => true];
		$releases = $this->createStub(ReleaseListService::class);
		$releases->method('portal')->willReturn([
			$row(1, '2-3', 'ok'),
			$row(2, '2-35', 'add'),
			$row(3, '5-1', 'ok'),
			$row(4, '1-1', 'blocked', 'Not released.'),
			$row(5, '1-2', 'not_decided'),
			$row(6, '7-7', 'remove', 'Not released.'),
		]);
		$this->proposals = $this->createMock(ProposalService::class);
		$this->proposals->method('proposePortal')->willReturnCallback(function (int $id, ?string $since): void {
			$this->proposed[] = [$id, $since];
		});
		$rights = $this->createStub(RightsService::class);
		$rights->method('today')->willReturn('2026-10-27');
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$this->service = new ChoirSystemCompareService($releases, $this->proposals, $rights, $this->createStub(XlsxReader::class),
			$this->createStub(Roles::class), $l);
	}

	private const EXPORT = "Titel,Stimme,Datei\n"
		. "2-3 Am Brunnen vor dem Tore,Alt,2-3 Am Brunnen vor dem Tore.pdf\n"
		. "\"2-35 Ave verum corpus\",Sopran,\n"
		. "1-1 Locus iste,,\n"
		. "Warm-up,,\n"
		. "7-7,,\n";

	public function testTheDifferencesBecomeProposals(): void {
		$report = $this->service->compare(self::EXPORT, 'Singste.csv', 'librarian', false);
		self::assertSame([[2, '2026-10-27'], [3, null]], $this->proposed, '2-35 is there and released, 5-1 is no longer there');
		self::assertSame(['2-35'], array_column($report['added'], 'shelfmark'));
		self::assertSame(['5-1'], array_column($report['removed'], 'shelfmark'));
		self::assertSame(['1-1', '7-7'], array_column($report['removeThere'], 'shelfmark'), 'not released: to remove there, no proposal');
		self::assertSame(['Warm-up'], $report['unknown'], 'the header row is no unknown row');
		self::assertSame(5, $report['rows']);
		self::assertSame(4, $report['matched']);
	}

	public function testTheDryRunProposesNothing(): void {
		$this->proposals->expects(self::never())->method('proposePortal');
		$report = $this->service->compare(self::EXPORT, 'Singste.csv', 'librarian', true);
		self::assertTrue($report['dryRun']);
		self::assertSame(['2-35'], array_column($report['added'], 'shelfmark'));
	}

	public function testAnOpenProposalIsNotMadeAgain(): void {
		$this->proposals->method('hasOpenPortalProposal')->willReturn(true);
		$report = $this->service->compare(self::EXPORT, 'Singste.csv', 'librarian', false);
		self::assertSame([], $this->proposed);
		self::assertSame(2, $report['alreadyProposed']);
	}

	public function testAFileWithoutShelfMarksIsRefused(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->compare("Name;Datum\nWarm-up;2026-10-01\n", 'other.csv', 'librarian', false);
	}

	public function testTheShelfMarkAtTheStartOfAHashtagCellIsFound(): void {
		// The table export of a choir system: quoted, comma, BOM, the shelf mark in a column of its own (here: hashtags)
		$export = "\xEF\xBB\xBF\"Titel\",\"Komponiert von\",\"Hashtags\"\n"
			. "\"Ave verum corpus\",\"W. A. Mozart (1756-1791)\",\"2-35 #advent\"\n"
			. "\"Am Brunnen vor dem Tore\",\"Franz Schubert\",\"2-3\"\n";
		$report = $this->service->compare($export, 'Repertoire.csv', 'librarian', true);
		self::assertSame(['2-35'], array_column($report['added'], 'shelfmark'));
		self::assertSame(2, $report['matched']);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function otherFormats(): array {
		return [
			'old Excel file' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1rest", 'export.xls'],
			'web page named .xls' => ["\xEF\xBB\xBF<html><table><tr><td>2-3</td></tr></table></html>", 'export.xls'],
			'web page named .csv' => ['  <table><tr><td>2-3</td></tr></table>', 'export.csv'],
			'XML' => ['<?xml version="1.0"?><rows><row>2-3</row></rows>', 'export.xml'],
			'OpenDocument' => ["PK\x03\x04rest", 'export.ods'],
		];
	}

	#[DataProvider('otherFormats')]
	public function testOtherFormatsAreRefusedWithAWayOut(string $content, string $fileName): void {
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('Export the list from the choir system as CSV');
		$this->service->compare($content, $fileName, 'librarian', true);
	}

	public function testTheSeparatorIsTheOneOfTheFirstLine(): void {
		self::assertSame([['a', 'b,c'], ['1', '2']], ChoirSystemCompareService::parseCsv("\xEF\xBB\xBFa;b,c\n1;2\n"));
		self::assertSame([['a', 'b'], ['1', '2']], ChoirSystemCompareService::parseCsv("a\tb\n1\t2"));
	}
}
