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
use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\ExcerptLink;
use OCA\Cantoria\Db\ExcerptLinkMapper;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\LinkService;
use OCA\Cantoria\Service\ProgrammeCopies;
use OCA\Cantoria\Service\ProgrammeShareService;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Sharing a whole programme with the choir members (milestone 0.19): one
 * link per piece through the same checks as a single link, an existing link
 * to the whole piece extended instead of a second one, and a reason for
 * every piece that cannot be shared.
 */
#[CoversClass(ProgrammeShareService::class)]
#[UsesClass(ProgrammeCopies::class)]
#[UsesClass(LinkService::class)]
final class ProgrammeShareTest extends TestCase {
	private const TODAY = '2026-10-30';
	private const NOT_RELEASED = 'Not released for choir members: the version needs confirmed public domain or a valid license for the singers\' portal.';

	/** @var list<array{0: string, 1: int, 2: array<string, mixed>|string|null, 3?: array<string, mixed>}> calls of the link service */
	private array $calls = [];
	/** @var list<int> copies whose options were asked for */
	private array $described = [];

	/**
	 * @param list<ExcerptLink> $links existing links
	 */
	private function service(?string $date = '2026-12-13', array $links = [], bool $librarian = false, ?string $createFails = null,
		?string $licenseEnd = null): ProgrammeShareService {
		$event = new Event();
		$event->setId(4);
		$event->setEventDate($date);
		$events = $this->createStub(EventMapper::class);
		$events->method('find')->willReturn($event);
		$items = [self::item(1, 31), self::item(2, 32), self::item(3, 33), self::item(4, null, 'Zugabe ohne Noten'), self::item(5, 31, struck: true),
			self::item(6, 34, 'Erhaltungskopie')];
		$itemMapper = $this->createStub(EventItemMapper::class);
		$itemMapper->method('findByEvent')->willReturn($items);
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('find')->willReturnCallback(function (int $id): Copy {
			$copy = new Copy();
			$copy->setId($id);
			$copy->setVersionId($id - 10);
			$copy->setShelfmark('1-' . $id);
			return $copy;
		});
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('find')->willReturnCallback(function (int $id): WorkVersion {
			$version = new WorkVersion();
			$version->setId($id);
			$version->setWorkId($id + 100);
			$version->setPreservationCopy($id === 24);
			return $version;
		});
		$works = $this->createStub(WorkMapper::class);
		$works->method('find')->willReturnCallback(function (int $id): Work {
			$work = new Work();
			$work->setId($id);
			$work->setTitle('Werk ' . $id);
			return $work;
		});
		$linkMapper = $this->createStub(ExcerptLinkMapper::class);
		$linkMapper->method('findByCopy')->willReturnCallback(fn (?int $copyId): array => array_values(array_filter($links,
			fn (ExcerptLink $link): bool => $link->getCopyId() === $copyId)));
		$linkService = $this->createStub(LinkService::class);
		$option = fn (bool $allowed, ?string $reason, bool $scan, ?string $until = null): array => ['available' => true, 'allowed' => $allowed, 'reason' => $reason,
			'scan' => $scan, 'scanReason' => null, 'playback' => $allowed, 'practiceAudio' => false, 'offline' => $allowed && $scan, 'until' => $until,
			'quantity' => $scan ? ['licensed' => 20, 'members' => 35] : null];
		$linkService->method('circleOptions')->willReturnCallback(function (int $copyId) use ($option, $licenseEnd): array {
			$this->described[] = $copyId;
			return match ($copyId) {
				31 => ['members' => $option(true, null, true), 'public' => $option(false, null, false), 'scans' => [['id' => 7, 'name' => 'a.pdf']]],
				32 => ['members' => $option(true, null, false, $licenseEnd), 'public' => $option(false, null, false), 'scans' => [['id' => 8, 'name' => 'b.pdf']]],
				default => throw new AccessDeniedException(self::NOT_RELEASED),
			};
		});
		$linkService->method('create')->willReturnCallback(function (int $copyId, array $data, string $uid, ?int $programme = null) use ($createFails): ExcerptLink {
			if ($createFails !== null) {
				throw new InvalidInputException($createFails);
			}
			$this->calls[] = ['create', $copyId, $data, ['programme' => $programme]];
			return new ExcerptLink();
		});
		$linkService->method('extend')->willReturnCallback(function (int $linkId, mixed $expires, string $uid, array $changes = []): ExcerptLink {
			$this->calls[] = ['extend', $linkId, is_string($expires) ? $expires : null, $changes];
			$link = new ExcerptLink();
			$link->setExpires(is_string($expires) ? $expires : null);
			return $link;
		});
		$rights = $this->createStub(RightsService::class);
		$rights->method('today')->willReturn(self::TODAY);
		$roles = $this->createStub(Roles::class);
		$roles->method('hasAnyRole')->willReturn($librarian);
		return new ProgrammeShareService($events, $itemMapper, new ProgrammeCopies($copies, $this->createStub(ArchiveFileMapper::class)),
			$versions, $works, $linkMapper, $linkService, $rights, $roles);
	}

	private static function item(int $id, ?int $copyId, string $title = '', bool $struck = false): EventItem {
		$item = new EventItem();
		$item->setId($id);
		$item->setEventId(4);
		$item->setCopyId($copyId);
		$item->setTitleText($title);
		$item->setStruck($struck);
		return $item;
	}

	private static function link(int $id, int $copyId, string $creator, ?string $measureFrom = null, string $expires = '2026-11-20'): ExcerptLink {
		$link = new ExcerptLink();
		$link->setId($id);
		$link->setCopyId($copyId);
		$link->setCircle(ExcerptLink::CIRCLE_MEMBERS);
		$link->setCreatedBy($creator);
		$link->setMeasureFrom($measureFrom);
		$link->setExpires($expires);
		$link->setCreated((int)strtotime('2026-10-01 12:00:00'));
		return $link;
	}

	public function testTheOptionsSayPerPieceWhatWouldHappen(): void {
		$options = $this->service(links: [self::link(50, 31, 'carla'), self::link(51, 32, 'carla', '9')])->options(4, 'carla');
		self::assertSame('2026-12-27', $options['expires'], 'two weeks after the concert');
		self::assertSame([1, 2, 3, 4, 6], array_column($options['items'], 'id'), 'struck items are left out');
		[$first, $second, $third, $fourth] = $options['items'];
		self::assertSame(['Werk 121', '1-31', true, null, true, ['id' => 50, 'expires' => '2026-11-20', 'scan' => false, 'limit' => '2027-10-01']],
			[$first['title'], $first['shelfmark'], $first['allowed'], $first['reason'], $first['scan'], $first['existing']], 'its own link is extended');
		self::assertSame(['licensed' => 20, 'members' => 35], $first['quantity'], 'a hint when there are more choir members than licenses');
		self::assertSame([true, false, null], [$second['allowed'], $second['scan'], $second['existing']], 'a link to measures is no link to the whole piece');
		self::assertSame([false, ['message' => self::NOT_RELEASED, 'parameters' => []]], [$third['allowed'], $third['reason']]);
		self::assertSame(['Zugabe ohne Noten', false, 'No copy of this piece is in the catalog.'], [$fourth['title'], $fourth['allowed'], $fourth['reason']['message'] ?? null]);
	}

	public function testTheLastDayStaysWithinSixMonths(): void {
		self::assertSame(LinkService::latestExpiry(self::TODAY), $this->service('2027-12-24')->options(4, 'carla')['expires']);
		self::assertSame(LinkService::latestExpiry(self::TODAY), $this->service(null)->options(4, 'carla')['expires'], 'without a date');
		self::assertSame(LinkService::latestExpiry(self::TODAY), $this->service('2026-06-01')->options(4, 'carla')['expires'], 'a past concert');
	}

	public function testALinkOfSomeoneElseIsExtendedOnlyByTheLibrarian(): void {
		$links = [self::link(50, 31, 'other')];
		self::assertNull($this->service(links: $links)->options(4, 'carla')['items'][0]['existing']);
		self::assertSame(50, $this->service(links: $links, librarian: true)->options(4, 'carla')['items'][0]['existing']['id'] ?? null);
	}

	public function testSharingMakesOrExtendsALinkPerPieceAndSaysWhyNot(): void {
		$result = $this->service(links: [self::link(50, 31, 'carla')])->share(4, ['items' => [1, 2, 3], 'expires' => '2026-12-27', 'note' => 'Bis Donnerstag', 'scan' => true], 'carla');
		self::assertSame(['created' => 1, 'extended' => 1, 'kept' => 0], ['created' => $result['created'], 'extended' => $result['extended'], 'kept' => $result['kept']]);
		self::assertSame([
			['extend', 50, '2026-12-27', ['note' => 'Bis Donnerstag', 'scan_file_id' => 7, 'programme' => 4]],
			['create', 32, ['circle' => ExcerptLink::CIRCLE_MEMBERS, 'expires' => '2026-12-27', 'note' => 'Bis Donnerstag', 'scan_file_id' => null], ['programme' => 4]],
		], $this->calls, 'the note and the scan apply to the extended link, too; the scan only where it may be offered; both know the programme (U24)');
		self::assertSame([['id' => 3, 'title' => 'Werk 123', 'reason' => ['message' => self::NOT_RELEASED, 'parameters' => []]]], $result['failed']);

		$this->calls = [];
		$this->service()->share(4, ['items' => [1], 'expires' => '', 'scan' => true], 'carla');
		self::assertSame(7, $this->calls[0][2]['scan_file_id'] ?? null, 'the first scan of the copy');
		self::assertSame(LinkService::latestExpiry(self::TODAY), $this->calls[0][2]['expires'] ?? null);
	}

	public function testALinkIsNeverShortened(): void {
		$result = $this->service(links: [self::link(50, 31, 'carla', expires: '2027-03-01')])->share(4, ['items' => [1], 'expires' => '2026-12-27'], 'carla');
		self::assertSame([0, 0, 1], [$result['created'], $result['extended'], $result['kept']], 'it stays as it is (U23)');
		self::assertSame([['extend', 50, '2027-03-01', ['note' => null, 'scan_file_id' => null, 'programme' => 4]]], $this->calls, 'the later last day stays');
		self::assertSame([], $result['limited']);
	}

	public function testOnlyTheChosenPiecesAreChecked(): void {
		$this->service()->share(4, ['items' => [2]], 'carla');
		self::assertSame([32], $this->described, 'no checks for the pieces not chosen (A18)');
	}

	public function testAPieceLastsAtMostUntilItsLicenseEnds(): void {
		$result = $this->service(licenseEnd: '2026-12-20')->share(4, ['items' => [1, 2], 'expires' => '2026-12-27'], 'carla');
		self::assertSame('2026-12-20', $this->calls[1][2]['expires'] ?? null);
		self::assertSame([['id' => 2, 'title' => 'Werk 122', 'expires' => '2026-12-20', 'reason' => 'license']], $result['limited'], 'the dialog says so');
	}

	/**
	 * U41: a link that exists already lasts at most a year after it was
	 * made; the result names that reason, not the license.
	 */
	public function testThePiecesThatLastShorterSayWhy(): void {
		$old = self::link(50, 31, 'carla');
		$old->setCreated((int)strtotime('2025-12-01 12:00:00'));
		$result = $this->service(links: [$old])->share(4, ['items' => [1], 'expires' => '2027-01-15'], 'carla');
		self::assertSame('2026-12-01', $this->calls[0][2] ?? null, 'a year after it was made');
		self::assertSame([['id' => 1, 'title' => 'Werk 121', 'expires' => '2026-12-01', 'reason' => 'age']], $result['limited']);
	}

	/**
	 * U45: a link with a voice, verse or tempo is not the link of the whole
	 * choir; sharing the programme makes a new one beside it.
	 */
	public function testALinkWithAVoiceVerseOrTempoIsNotTakenOver(): void {
		foreach (['setVoice' => 'Alt', 'setVerse' => 2, 'setTempo' => 80] as $setter => $value) {
			$link = self::link(50, 31, 'carla');
			$link->$setter($value);
			$options = $this->service(links: [$link])->options(4, 'carla');
			self::assertNull($options['items'][0]['existing'], $setter);
			$this->calls = [];
			$result = $this->service(links: [$link])->share(4, ['items' => [1], 'expires' => '2026-12-27'], 'carla');
			self::assertSame([1, 0], [$result['created'], $result['extended']], $setter);
			self::assertSame('create', $this->calls[0][0] ?? null, $setter);
		}
	}

	/**
	 * U40: a link that has ended is extended, too, so that it keeps its
	 * address; a revoked one is not.
	 */
	public function testALinkThatHasEndedIsExtended(): void {
		$ended = self::link(50, 31, 'carla', expires: '2026-10-20');
		$result = $this->service(links: [$ended])->share(4, ['items' => [1], 'expires' => '2026-12-27'], 'carla');
		self::assertSame([0, 1], [$result['created'], $result['extended']]);
		self::assertSame(['extend', 50, '2026-12-27'], array_slice($this->calls[0], 0, 3));

		$revoked = self::link(50, 31, 'carla', expires: '2026-10-20');
		$revoked->setRevoked(1);
		self::assertNull($this->service(links: [$revoked])->options(4, 'carla')['items'][0]['existing']);
	}

	public function testTheOptionsSayWhetherAPieceCanBePlayed(): void {
		$items = $this->service()->options(4, 'carla')['items'];
		self::assertSame([true, true, false], [$items[0]['playback'], $items[1]['playback'], $items[2]['playback']], 'for the message to the choir (U42)');
	}

	public function testAPreservationCopyShowsNeitherShelfMarkNorCopyToOtherRoles(): void {
		$item = $this->service()->options(4, 'otto')['items'][4];
		self::assertSame([6, 'Erhaltungskopie', null, null, false, ProgrammeShareService::NOT_AVAILABLE],
			[$item['id'], $item['title'], $item['shelfmark'], $item['copyId'], $item['allowed'], $item['reason']['message'] ?? null], 'S N13');
		self::assertNotContains(34, $this->described);
		self::assertSame('1-34', $this->service(librarian: true)->options(4, 'carla')['items'][4]['shelfmark'], 'the catalog librarian sees it');
	}

	public function testAFailingPieceDoesNotStopTheOthers(): void {
		$result = $this->service(createFails: 'The measures do not exist.')->share(4, ['items' => [1, 2]], 'carla');
		self::assertSame([0, ['The measures do not exist.', 'The measures do not exist.']], [$result['created'], array_column(array_column($result['failed'], 'reason'), 'message')]);
	}

	public function testNothingChosenOrAWrongDayIsRefused(): void {
		foreach ([[[], null, 'Please choose at least one piece.'], [[1], '2028-01-01', 'The expiry date must lie between today and six months from now.']] as [$items, $expires, $message]) {
			try {
				$this->service()->share(4, ['items' => $items, 'expires' => $expires], 'carla');
				self::fail($message);
			} catch (InvalidInputException $e) {
				self::assertSame($message, $e->getMessage());
			}
		}
	}
}
