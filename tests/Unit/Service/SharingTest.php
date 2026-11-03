<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\ArchiveFile;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\AuditEntry;
use OCA\Cantoria\Db\AuditEntryMapper;
use OCA\Cantoria\Db\Contribution;
use OCA\Cantoria\Db\ContributionMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\ExcerptLink;
use OCA\Cantoria\Db\ExcerptLinkMapper;
use OCA\Cantoria\Db\License;
use OCA\Cantoria\Db\LicenseMapper;
use OCA\Cantoria\Db\Person;
use OCA\Cantoria\Db\PersonMapper;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\ModuleDisabledException;
use OCA\Cantoria\Exception\NotFoundException;
use OCA\Cantoria\Exception\UnknownLinkException;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\FieldValue;
use OCA\Cantoria\Service\LinkService;
use OCA\Cantoria\Service\LinkTokens;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\MusicXmlExcerpt;
use OCA\Cantoria\Service\PageKeys;
use OCA\Cantoria\Service\PracticeAudioService;
use OCA\Cantoria\Service\PublicRepertoireService;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\ScanService;
use OCA\Cantoria\Service\ScoreService;
use OCA\Cantoria\Service\SharedLinkService;
use OCA\Cantoria\Tests\Unit\Support\FakeCrypto;
use OCA\Cantoria\Tests\Unit\Support\ModuleSwitches;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Sharing beyond the archive roles (milestone 0.7): making links for choir
 * members and the public, opening them, practice audio of choir members,
 * the public repertoire page and the links to CPDL and IMSLP.
 */
#[CoversClass(LinkService::class)]
#[CoversClass(SharedLinkService::class)]
#[UsesClass(PageKeys::class)]
#[CoversClass(PublicRepertoireService::class)]
#[CoversClass(FieldValue::class)]
#[CoversClass(PracticeAudioService::class)]
#[CoversClass(AuditLog::class)]
#[UsesClass(MusicXmlExcerpt::class)]
#[UsesClass(Modules::class)]
#[UsesClass(ScanService::class)]
final class SharingTest extends TestCase {
	use ModuleSwitches;

	private const TODAY = '2026-10-22';
	private const TOKEN = 'abcdefghijABCDEFGHIJ0123456789xy';

	/** Three measures in one part */
	private const SCORE = '<?xml version="1.0" encoding="UTF-8"?>'
		. '<score-partwise version="4.0"><work><work-title>Abendlied</work-title></work>'
		. '<part-list><score-part id="P1"><part-name>Alto</part-name></score-part></part-list><part id="P1">'
		. '<measure number="1"><attributes><divisions>1</divisions><time><beats>4</beats><beat-type>4</beat-type></time><clef><sign>G</sign><line>2</line></clef></attributes>'
		. '<note><pitch><step>C</step><octave>5</octave></pitch><duration>4</duration><type>whole</type></note></measure>'
		. '<measure number="2"><note><pitch><step>D</step><octave>5</octave></pitch><duration>4</duration><type>whole</type></note></measure>'
		. '<measure number="3"><note><pitch><step>E</step><octave>5</octave></pitch><duration>4</duration><type>whole</type></note></measure>'
		. '</part></score-partwise>';

	/** The start of a PDF document, enough for ScanService::isPdf() */
	private const PDF = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";

	/** @var list<array{string, ?string, mixed}> audit entries: action, field, new value */
	private array $audit = [];
	/** @var list<int> ids of links whose call was counted */
	private array $hits = [];
	/** the link the mapper finds by its id */
	private ?ExcerptLink $stored = null;

	private function version(array $values = []): WorkVersion {
		$version = new WorkVersion();
		$version->setId(3);
		$version->setWorkId(7);
		foreach ($values as $column => $value) {
			$version->setValue($column, $value);
		}
		return $version;
	}

	private function copy(): Copy {
		$copy = new Copy();
		$copy->setId(35);
		$copy->setVersionId(3);
		$copy->setShelfmark('1-3');
		return $copy;
	}

	private function auditLog(): AuditLog {
		$audit = $this->createStub(AuditLog::class);
		$audit->method('newOperation')->willReturn('op');
		$audit->method('record')->willReturnCallback(function (string $operation, ?string $uid, string $type, ?int $id, string $action,
			?string $field = null, mixed $old = null, mixed $new = null): void {
			$this->audit[] = [$action, $field, $new];
		});
		return $audit;
	}

	private function rights(): RightsService {
		$rights = $this->createStub(RightsService::class);
		$rights->method('today')->willReturn(self::TODAY);
		return $rights;
	}

	/**
	 * @param array<string, string|int|null> $denials RightsPolicy method => reason (releaseEnd: the day, licensedQuantity: the number)
	 */
	private function policy(array $denials = []): RightsPolicy {
		$policy = $this->createStub(RightsPolicy::class);
		foreach (['viewDenial', 'memberViewDenial', 'memberPlayDenial', 'memberPracticeAudioDenial', 'memberScanDenial', 'publicViewDenial', 'publicPlayDenial',
			'publicScanDenial', 'releaseEnd', 'licensedQuantity'] as $method) {
			$policy->method($method)->willReturn($denials[$method] ?? null);
		}
		return $policy;
	}

	/**
	 * @param array<string, string|int|null> $denials
	 * @param list<string> $off modules switched off
	 * @param int $members accounts in the groups of the choir members
	 */
	private function links(array $denials = [], array $off = [], bool $withScore = true, int $members = 0): LinkService {
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('find')->willReturn($this->copy());
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('find')->willReturn($this->version());
		$files = $this->createStub(ArchiveFileMapper::class);
		$file = new ArchiveFile();
		$file->setId(4);
		$file->setCopyId(35);
		$file->setRole('musicxml');
		$file->setName('1-03.musicxml');
		$file->setEtag('e1');
		$archiveFiles = [4 => $file, 5 => self::archiveFile(5, 35, 'score_pdf', '1-03.pdf'), 6 => self::archiveFile(6, 36, 'score_pdf', '1-04.pdf'),
			7 => self::archiveFile(7, 35, 'score_pdf', '1-03 old.pdf', false)];
		$files->method('find')->willReturnCallback(fn (int $id): ArchiveFile => $archiveFiles[$id] ?? throw new DoesNotExistException('none'));
		$files->method('findByCopy')->willReturn([$file, $archiveFiles[5], $archiveFiles[7]]);
		$scores = $this->createStub(ScoreService::class);
		$scores->method('describe')->willReturn($withScore
			? ['fileId' => 4, 'fileName' => '1-03.musicxml', 'canView' => true, 'canPlay' => true, 'reason' => null, 'canExportAudio' => false, 'exportReason' => null]
			: null);
		$scores->method('content')->willReturn(['content' => self::SCORE, 'mime' => 'application/xml', 'name' => '1-03.musicxml', 'type' => 'musicxml']);
		$scores->method('scoreText')->willReturn(['xml' => self::SCORE, 'entry' => null]);
		$mapper = $this->createStub(ExcerptLinkMapper::class);
		$mapper->method('insert')->willReturnCallback(function (ExcerptLink $link): ExcerptLink {
			$link->setId(9);
			return $link;
		});
		$mapper->method('find')->willReturnCallback(fn (int $id): ExcerptLink => $this->stored !== null && $this->stored->getId() === $id
			? $this->stored : throw new DoesNotExistException('none'));
		$mapper->method('update')->willReturnArgument(0);
		$random = $this->createStub(ISecureRandom::class);
		$random->method('generate')->willReturn(self::TOKEN);
		$scans = $this->createStub(ScanService::class);
		$scans->method('scan')->willReturn($this->createStub(File::class));
		$roles = $this->createStub(Roles::class);
		$roles->method('memberCount')->willReturn($members);
		return new LinkService($mapper, $copies, $versions, $files, $scores, $this->policy($denials), $this->rights(), $this->auditLog(),
			$roles, $this->createStub(IUserManager::class), new LinkTokens(new FakeCrypto(), $random), $this->createStub(ITimeFactory::class),
			$this->modulesWithout($off), $scans);
	}

	private static function archiveFile(int $id, int $copyId, string $role, string $name, bool $found = true): ArchiveFile {
		$file = new ArchiveFile();
		$file->setId($id);
		$file->setFileId(4700 + $id);
		$file->setCopyId($copyId);
		$file->setRole($role);
		$file->setType($role === 'score_pdf' ? 'pdf' : 'musicxml');
		$file->setName($name);
		$file->setFound($found);
		return $file;
	}

	// ---- extending links (milestone 0.19) -------------------------------------------

	private function storedLink(string $expires, string $creator = 'conductor', ?int $revoked = null, string $circle = ExcerptLink::CIRCLE_MEMBERS,
		string $created = '2026-09-01'): ExcerptLink {
		$link = new ExcerptLink();
		$link->setId(9);
		$link->setCopyId(35);
		$link->setCircle($circle);
		$link->setCreatedBy($creator);
		$link->setCreated((int)strtotime($created . ' 12:00:00'));
		$link->setExpires($expires);
		$link->setRevoked($revoked);
		$link->setToken('kept');
		return $this->stored = $link;
	}

	public function testALinkIsExtendedAndKeepsItsToken(): void {
		$this->storedLink('2026-11-01');
		$link = $this->links()->extend(9, '2027-01-15', 'conductor');
		self::assertSame(['2027-01-15', 'kept'], [$link->getExpires(), $link->getToken()], 'what choir members kept on their devices stays');
		self::assertContains(['extended', 'expires', '2027-01-15'], $this->audit);

		$this->storedLink('2026-10-01');
		self::assertSame(LinkService::latestExpiry(self::TODAY), $this->links()->extend(9, '', 'conductor')->getExpires(),
			'an expired link can be extended, by default as far as allowed');
	}

	public function testALinkIsExtendedOnlyByItsCreatorOrTheLibrarianAndWithTheRightsOfToday(): void {
		$cases = [
			'revoked' => [fn () => $this->storedLink('2026-11-01', 'conductor', 1700000000), [], InvalidInputException::class,
				'A revoked link cannot be extended. Please make a new one.'],
			'someone else' => [fn () => $this->storedLink('2026-11-01', 'other'), [], AccessDeniedException::class,
				'Only the creator or the catalog librarian can extend this link.'],
			'rights withdrawn' => [fn () => $this->storedLink('2026-11-01'), ['memberViewDenial' => 'Not released for choir members.'],
				AccessDeniedException::class, 'Not released for choir members.'],
			'too far' => [fn () => $this->storedLink('2026-11-01'), [], InvalidInputException::class,
				'The expiry date must lie between today and six months from now.', '2027-12-31'],
			'shorter' => [fn () => $this->storedLink('2027-01-31'), [], InvalidInputException::class,
				'The link is valid until %s already. Extending never shortens a link; to end it earlier, revoke it.', '2026-12-31'],
			'beyond the license' => [fn () => $this->storedLink('2026-11-01'), ['releaseEnd' => '2026-12-15'], InvalidInputException::class,
				'The expiry date must lie between today and %s: the license that releases the piece, or the age of the link, allows no later day.'],
			'beyond a year after it was made' => [fn () => $this->storedLink('2026-11-01', created: '2026-01-10'), [], InvalidInputException::class,
				'The expiry date must lie between today and %s: the license that releases the piece, or the age of the link, allows no later day.', '2027-01-15'],
			'made more than a year ago' => [fn () => $this->storedLink('2026-11-01', created: '2025-10-01'), [], InvalidInputException::class,
				'This link was made more than a year ago and cannot be extended any more. Please make a new one.'],
			'public, release withdrawn' => [fn () => $this->storedLink('2026-11-01', circle: ExcerptLink::CIRCLE_PUBLIC),
				['publicViewDenial' => 'Public domain is not confirmed yet.'], AccessDeniedException::class, 'Public domain is not confirmed yet.'],
		];
		foreach ($cases as $case => [$store, $denials, $class, $message]) {
			$store();
			try {
				$this->links($denials)->extend(9, $cases[$case][4] ?? '2026-12-31', 'conductor');
				self::fail($case);
			} catch (InvalidInputException|AccessDeniedException $e) {
				self::assertInstanceOf($class, $e, $case);
				self::assertSame($message, $e->getMessage(), $case);
			}
		}
	}

	public function testAnExpiredPublicLinkGetsANewTokenWhenExtended(): void {
		$this->storedLink('2026-11-01', circle: ExcerptLink::CIRCLE_PUBLIC);
		self::assertSame('kept', $this->links()->extend(9, '2026-12-31', 'conductor')->getToken(), 'a public link that is still valid keeps its address');
		self::assertNotContains(['extended', 'token', 'new'], $this->audit);

		$this->storedLink('2026-10-01', circle: ExcerptLink::CIRCLE_PUBLIC);
		$link = $this->links()->extend(9, '2026-12-31', 'conductor');
		self::assertSame(ExcerptLink::hashToken(self::TOKEN), $link->getToken(), 'the old address from a message or a log stays closed');
		self::assertSame(self::TOKEN, $link->issuedToken(), 'the creator gets the new address');
		self::assertContains(['extended', 'token', 'new'], $this->audit);

		$this->storedLink('2026-10-01');
		self::assertSame('kept', $this->links()->extend(9, '2026-12-31', 'conductor')->getToken(), 'a link for choir members keeps it: they are signed in');
	}

	public function testALinkLastsAtMostUntilTheLicenseEnds(): void {
		$this->storedLink('2026-11-01');
		self::assertSame('2026-12-15', $this->links(['releaseEnd' => '2026-12-15'])->extend(9, '', 'conductor')->getExpires(), 'by default as far as the license');
		self::assertContains(['extended', 'license_until', '2026-12-15'], $this->audit, 'the end of the license is recorded as evidence');

		$this->storedLink('2026-11-01', created: '2026-01-10');
		self::assertSame('2027-01-10', $this->links()->extend(9, '', 'conductor')->getExpires(), 'a year after the link was made at the latest');

		$link = $this->links(['releaseEnd' => '2026-12-15'])->create(35, ['circle' => 'members'], 'conductor');
		self::assertSame('2026-12-15', $link->getExpires(), 'a new link, too');
		try {
			$this->links(['releaseEnd' => '2026-12-15'])->create(35, ['circle' => 'members', 'expires' => '2027-01-31'], 'conductor');
			self::fail('a link beyond the end of the license');
		} catch (InvalidInputException $e) {
			self::assertSame(['2026-12-15'], $e->getParameters());
		}
		self::assertSame(LinkService::latestExpiry(self::TODAY), $this->links(['releaseEnd' => '2026-12-15'])->create(35, ['voice' => 'Alto'], 'conductor')->getExpires(),
			'internal links need no license');
	}

	public function testExtendingTakesTheNoteAndTheScanWithTheSameChecks(): void {
		$this->storedLink('2026-11-01');
		$link = $this->links()->extend(9, '2026-12-31', 'conductor', ['note' => 'Bis Donnerstag', 'scan_file_id' => 5]);
		self::assertSame(['2026-12-31', 'Bis Donnerstag', 5], [$link->getExpires(), $link->getNote(), $link->getScanFileId()]);
		self::assertContains(['extended', 'scan_file_id', 5], $this->audit);
		self::assertContains(['extended', 'note', 'Bis Donnerstag'], $this->audit);

		$cases = [
			'no license for the scan' => [ExcerptLink::CIRCLE_MEMBERS, ['memberScanDenial' => 'Choir members can save and print the scan.'], ['scan_file_id' => 5],
				AccessDeniedException::class, 'Choir members can save and print the scan.'],
			'public, no license for the download of the scan' => [ExcerptLink::CIRCLE_PUBLIC, ['publicScanDenial' => 'Everyone who opens a public link can save and print its scan.'],
				['scan_file_id' => 5], AccessDeniedException::class, 'Everyone who opens a public link can save and print its scan.'],
			'a scan of another copy' => [ExcerptLink::CIRCLE_MEMBERS, [], ['scan_file_id' => 6], InvalidInputException::class,
				'This scan does not belong to the copy. Please choose one of the PDF files of the copy (tab "Files").'],
			'a note for everyone' => [ExcerptLink::CIRCLE_PUBLIC, [], ['note' => 'internal remark'], InvalidInputException::class,
				'A public link has no note, because everyone could read it. Please leave the note empty.'],
		];
		foreach ($cases as $case => [$circle, $denials, $changes, $class, $message]) {
			$this->storedLink('2026-11-01', circle: $circle);
			try {
				$this->links($denials)->extend(9, '2026-12-31', 'conductor', $changes);
				self::fail($case);
			} catch (InvalidInputException|AccessDeniedException $e) {
				self::assertInstanceOf($class, $e, $case);
				self::assertSame($message, $e->getMessage(), $case);
			}
		}
	}

	public function testALinkOfAProgrammeKnowsTheProgramme(): void {
		self::assertSame(4, $this->links()->create(35, ['circle' => 'members'], 'conductor', 4)->getEventId(), 'for "Shared with me" (U24)');
		self::assertNull($this->links()->create(35, ['circle' => 'members'], 'conductor')->getEventId(), 'a link made on its own');
		self::assertNull($this->links()->create(35, ['circle' => 'public'], 'conductor', 4)->getEventId(), 'only links for choir members');
		try {
			$this->links()->create(35, ['circle' => 'members', 'event_id' => 4], 'conductor');
			self::fail('no request sets the programme');
		} catch (InvalidInputException $e) {
			self::assertSame(['event_id'], $e->getParameters());
		}

		$this->storedLink('2026-11-01');
		$link = $this->links()->extend(9, '2026-12-31', 'conductor', ['programme' => 5]);
		self::assertSame(5, $link->getEventId(), 'shared with another programme: listed under that one');
		self::assertSame(5, $this->links()->extend(9, '2026-12-31', 'conductor')->getEventId(), 'extended in the list of links: it stays');
		$this->storedLink('2026-11-01', circle: ExcerptLink::CIRCLE_PUBLIC);
		self::assertNull($this->links()->extend(9, '2026-12-31', 'conductor', ['programme' => 5])->getEventId());
	}

	public function testThePublicScanNeedsItsOwnLicense(): void {
		$reason = 'Everyone who opens a public link can save and print its scan.';
		try {
			$this->links(['publicScanDenial' => $reason])->create(35, ['circle' => 'public', 'scan_file_id' => 5], 'conductor');
			self::fail('a public scan with a license for online publication only');
		} catch (AccessDeniedException $e) {
			self::assertSame($reason, $e->getMessage());
		}
		$options = $this->links(['publicScanDenial' => $reason])->circleOptions(35, 'conductor');
		self::assertSame([true, false, $reason], [$options['public']['allowed'], $options['public']['scan'], $options['public']['scanReason']]);
		self::assertTrue($options['members']['scan'], 'choir members follow their own license');
	}

	public function testTheDialogHintsAtTooFewLicensesAndTheirEnd(): void {
		$options = $this->links(['licensedQuantity' => 20, 'releaseEnd' => '2027-03-31'], members: 35)->circleOptions(35, 'conductor');
		self::assertSame(['licensed' => 20, 'members' => 35], $options['members']['quantity'], 'more choir members than licenses (W B17)');
		self::assertSame('2027-03-31', $options['members']['until']);
		self::assertNull($options['public']['quantity'], 'the public is no group to count');
		self::assertNull($this->links(['licensedQuantity' => 40], members: 35)->circleOptions(35, 'conductor')['members']['quantity'], 'enough licenses');
		self::assertNull($this->links(members: 35)->circleOptions(35, 'conductor')['members']['quantity'], 'no quantity known');

		$listed = $this->shared([$this->link('members')], ['releaseEnd' => '2027-03-31'])->forMember('singer');
		self::assertSame('2027-03-31', $listed[0]['keepUntil'], 'the device drops its copy when the license ends (R B8)');
	}

	// ---- making links -------------------------------------------------------------

	public function testALinkForChoirMembersKeepsItsCircleAndIsRecorded(): void {
		$link = $this->links()->create(35, ['circle' => 'members', 'measure_from' => '2', 'measure_to' => '3', 'voice' => 'Alto',
			'note' => 'Please practise until Thursday.', 'task_id' => '4'], 'conductor');
		self::assertSame(ExcerptLink::CIRCLE_MEMBERS, $link->getCircle());
		self::assertSame('Please practise until Thursday.', $link->getNote(), 'the choir members read the note');
		self::assertNull($link->getTaskId(), 'tasks stay internal');
		self::assertContains(['created', 'circle', 'members'], $this->audit);
	}

	public function testLinksBeyondTheRolesNeedTheirModule(): void {
		foreach (['members' => Modules::MEMBER_LINKS, 'public' => Modules::PUBLIC_LINKS] as $circle => $module) {
			try {
				$this->links([], [$module])->create(35, ['circle' => $circle, 'measure_from' => '1', 'measure_to' => '2'], 'conductor');
				self::fail($circle . ': the module is off');
			} catch (ModuleDisabledException) {
				self::assertTrue(true);
			}
		}
		$internal = $this->links([], [Modules::MEMBER_LINKS, Modules::PUBLIC_LINKS])->create(35, ['voice' => 'Alto'], 'conductor');
		self::assertSame(ExcerptLink::CIRCLE_INTERNAL, $internal->getCircle(), 'internal links stay as they were');
	}

	public function testLinksBeyondTheRolesAreRefusedWithTheNextStep(): void {
		$reason = 'Not released for choir members: the version needs confirmed public domain or a valid license for the singers\' portal. The catalog librarian records it on the tab "Rights".';
		try {
			$this->links(['memberViewDenial' => $reason])->create(35, ['circle' => 'members', 'measure_from' => '1', 'measure_to' => '2'], 'conductor');
			self::fail('not released');
		} catch (AccessDeniedException $e) {
			self::assertSame($reason, $e->getMessage());
		}
		$cases = [
			'one measure only' => [['circle' => 'members', 'measure_from' => '1'],
				'Please give both the first and the last measure of the excerpt, or leave both empty to share the whole piece.'],
			'a note for everyone' => [['circle' => 'public', 'measure_from' => '1', 'measure_to' => '2', 'note' => 'internal remark'],
				'A public link has no note, because everyone could read it. Please leave the note empty.'],
			'a measure that is not there' => [['circle' => 'public', 'measure_from' => '2', 'measure_to' => '9'], 'There is no measure %s in this score.'],
			'an unknown circle' => [['circle' => 'friends'], '"%s": value not allowed.'],
		];
		foreach ($cases as $case => [$data, $message]) {
			try {
				$this->links()->create(35, $data, 'conductor');
				self::fail($case);
			} catch (InvalidInputException $e) {
				self::assertSame($message, $e->getMessage(), $case);
			}
		}
		try {
			$this->links([], [], false)->create(35, ['circle' => 'members', 'measure_from' => '1', 'measure_to' => '2'], 'conductor');
			self::fail('no MusicXML');
		} catch (InvalidInputException $e) {
			self::assertSame(LinkService::NO_SCORE, $e->getMessage());
		}
	}

	public function testALinkWithoutMeasuresSharesTheWholePiece(): void {
		foreach (['members', 'public'] as $circle) {
			$link = $this->links()->create(35, ['circle' => $circle, 'measure_from' => '', 'measure_to' => null, 'voice' => 'Alto'], 'conductor');
			self::assertSame($circle, $link->getCircle());
			self::assertNull($link->getMeasureFrom(), $circle . ': the whole piece');
			self::assertNull($link->getMeasureTo());
			self::assertNull($link->getScanFileId(), 'no scan unless chosen');
		}
		$reason = 'Internal preservation copy: it is never published.';
		try {
			$this->links(['publicViewDenial' => $reason])->create(35, ['circle' => 'public'], 'conductor');
			self::fail('the whole piece needs the same release as an excerpt');
		} catch (AccessDeniedException $e) {
			self::assertSame($reason, $e->getMessage());
		}
		try {
			$this->links([], [], false)->create(35, ['circle' => 'members'], 'conductor');
			self::fail('no MusicXML');
		} catch (InvalidInputException $e) {
			self::assertSame(LinkService::NO_SCORE, $e->getMessage());
		}
	}

	public function testALinkOffersTheScanOnlyUnderTheSameRelease(): void {
		$link = $this->links()->create(35, ['circle' => 'members', 'scan_file_id' => '5'], 'conductor');
		self::assertSame(5, $link->getScanFileId());
		self::assertContains(['created', 'scan_file_id', 5], $this->audit, 'recorded');
		self::assertSame(5, $this->links()->create(35, ['circle' => 'public', 'measure_from' => '1', 'measure_to' => '2', 'scan_file_id' => 5],
			'conductor')->getScanFileId(), 'also with an excerpt');

		foreach (['memberViewDenial' => 'members', 'publicViewDenial' => 'public'] as $method => $circle) {
			try {
				$this->links([$method => 'Not released.'])->create(35, ['circle' => $circle, 'scan_file_id' => 5], 'conductor');
				self::fail($circle . ': not released');
			} catch (AccessDeniedException $e) {
				self::assertSame('Not released.', $e->getMessage());
			}
		}
		$refused = [
			'internal' => [['scan_file_id' => 5], 'Only links for choir members and public links offer the scan; the archive roles see it beside the score.'],
			'another copy' => [['circle' => 'members', 'scan_file_id' => 6], 'This scan does not belong to the copy. Please choose one of the PDF files of the copy (tab "Files").'],
			'no PDF' => [['circle' => 'members', 'scan_file_id' => 4], 'This scan does not belong to the copy. Please choose one of the PDF files of the copy (tab "Files").'],
			'no longer found' => [['circle' => 'members', 'scan_file_id' => 7], 'This scan does not belong to the copy. Please choose one of the PDF files of the copy (tab "Files").'],
			'unknown' => [['circle' => 'members', 'scan_file_id' => 99], 'This scan does not belong to the copy. Please choose one of the PDF files of the copy (tab "Files").'],
			'not a number' => [['circle' => 'members', 'scan_file_id' => 'x'], '"%s": value not allowed.'],
		];
		foreach ($refused as $case => [$data, $message]) {
			try {
				$this->links()->create(35, $data, 'conductor');
				self::fail($case);
			} catch (InvalidInputException $e) {
				self::assertSame($message, $e->getMessage(), $case);
			}
		}
	}

	public function testTheDialogLearnsWhatTheRecipientsGet(): void {
		$options = $this->links(['publicPlayDenial' => 'Protected version: playback needs a license for practice audio.'], [Modules::MEMBER_PRACTICE_AUDIO])
			->circleOptions(35, 'conductor');
		self::assertSame([['id' => 5, 'name' => '1-03.pdf']], $options['scans'], 'the PDF scans of the copy that are still found');
		self::assertSame(['available' => true, 'allowed' => true, 'reason' => null, 'scan' => true, 'scanReason' => null, 'playback' => true,
			'practiceAudio' => false, 'offline' => true, 'until' => null, 'quantity' => null], $options['members'], 'practice audio for choir members is switched off');
		self::assertSame(['available' => true, 'allowed' => true, 'reason' => 'Protected version: playback needs a license for practice audio.',
			'scan' => true, 'scanReason' => null, 'playback' => false, 'practiceAudio' => false, 'offline' => false, 'until' => null, 'quantity' => null], $options['public'],
			'a public link is kept on a device only for confirmed public domain');

		$options = $this->links([], [Modules::PUBLIC_LINKS])->circleOptions(35, 'conductor');
		self::assertTrue($options['members']['practiceAudio']);
		self::assertSame(['available' => false, 'allowed' => false, 'reason' => LinkService::SWITCHED_OFF, 'scan' => false, 'scanReason' => null,
			'playback' => false, 'practiceAudio' => false, 'offline' => null, 'until' => null, 'quantity' => null], $options['public']);
		self::assertNull($this->links([], [Modules::OFFLINE])->circleOptions(35, 'conductor')['members']['offline'], 'nothing to say while the module is off');

		$noDownload = 'Choir members can save and print the scan: unless public domain is confirmed, it needs a license for download and printing by choir members. The catalog librarian records it on the tab "Rights".';
		$options = $this->links(['memberScanDenial' => $noDownload])->circleOptions(35, 'conductor');
		self::assertTrue($options['members']['allowed'], 'the notes may be shared');
		self::assertFalse($options['members']['scan'], 'the scan not');
		self::assertSame($noDownload, $options['members']['scanReason']);
		self::assertFalse($options['members']['offline'], 'no copy on the device either');
		self::assertTrue($options['public']['scan'], 'public links follow their own license for the download of the scan');
		try {
			$this->links(['memberScanDenial' => $noDownload])->create(35, ['circle' => 'members', 'scan_file_id' => 5], 'conductor');
			self::fail('a scan without a license for copies was offered');
		} catch (AccessDeniedException $e) {
			self::assertSame($noDownload, $e->getMessage());
		}
		try {
			$this->shared([$this->link('members', ['scan_file_id' => 5])], ['memberScanDenial' => $noDownload])->memberScan(self::TOKEN, 'singer');
			self::fail('the scan was handed out without a license for copies');
		} catch (AccessDeniedException $e) {
			self::assertSame($noDownload, $e->getMessage());
		}
		$listed = $this->shared([$this->link('members', ['scan_file_id' => 5])], ['memberScanDenial' => $noDownload])->forMember('singer');
		self::assertFalse($listed[0]['scan'], 'the page of the choir member offers no scan');
	}

	// ---- opening links ----------------------------------------------------------------

	private function link(string $circle, array $values = []): ExcerptLink {
		$link = new ExcerptLink();
		$link->setId(9);
		$link->setToken(self::TOKEN);
		$link->setCopyId(35);
		$link->setCircle($circle);
		$link->setMeasureFrom('2');
		$link->setMeasureTo('3');
		$link->setVoice('Alto');
		$link->setNote('Please practise until Thursday.');
		$link->setExpires('2026-12-31');
		foreach ($values as $column => $value) {
			$link->setValue($column, $value);
		}
		// stored like LinkTokens::issue: the hash, and the token encrypted
		$token = $link->getToken();
		$link->setToken(ExcerptLink::hashToken($token));
		$link->setTokenSecret((new FakeCrypto())->encrypt($token));
		return $link;
	}

	private function node(string $content): File {
		$node = $this->createStub(File::class);
		$node->method('getSize')->willReturn(strlen($content));
		$node->method('fopen')->willReturnCallback(function () use ($content) {
			$stream = fopen('php://memory', 'r+');
			self::assertIsResource($stream);
			fwrite($stream, $content);
			rewind($stream);
			return $stream;
		});
		return $node;
	}

	/**
	 * @param list<ExcerptLink> $links
	 * @param array<string, ?string> $denials
	 * @param array<int, array{0: Event, 1: list<EventItem>}> $programmes event id => event and its items
	 * @param list<string> $off modules switched off
	 */
	private function shared(array $links, array $denials = [], bool $member = true, array $versionValues = [], array $programmes = [], array $off = []): SharedLinkService {
		$mapper = $this->createStub(ExcerptLinkMapper::class);
		$mapper->method('findByToken')->willReturnCallback(function (string $token) use ($links): ExcerptLink {
			foreach ($links as $link) {
				if ($link->getToken() === ExcerptLink::hashToken($token)) {
					return $link;
				}
			}
			throw new DoesNotExistException('none');
		});
		$mapper->method('findUnrevokedByCircle')->willReturnCallback(fn (string $circle): array => array_values(array_filter($links,
			fn (ExcerptLink $link): bool => $link->getCircle() === $circle && $link->getRevoked() === null)));
		$mapper->method('countHit')->willReturnCallback(function (int $id): void {
			$this->hits[] = $id;
		});
		$copies = $this->createStub(CopyMapper::class);
		// copy 35 of version 3; every other copy of the version with its own id
		$copies->method('find')->willReturnCallback(function (int $id): Copy {
			$copy = $this->copy();
			if ($id !== 35) {
				$copy->setId($id);
				$copy->setVersionId($id);
			}
			return $copy;
		});
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('find')->willReturnCallback(function (int $id) use ($versionValues): WorkVersion {
			$version = $this->version(['arranger_text' => 'K. Satz'] + $versionValues);
			$version->setId($id);
			return $version;
		});
		$events = $this->createStub(EventMapper::class);
		$events->method('find')->willReturnCallback(fn (int $id): Event => $programmes[$id][0] ?? throw new DoesNotExistException('none'));
		$items = $this->createStub(EventItemMapper::class);
		$items->method('findByEvent')->willReturnCallback(fn (int $id): array => $programmes[$id][1] ?? []);
		$work = new Work();
		$work->setId(7);
		$work->setTitle('Abendlied');
		$work->setComposer('J. Rheinberger');
		$works = $this->createStub(WorkMapper::class);
		$works->method('find')->willReturn($work);
		$file = new ArchiveFile();
		$file->setId(4);
		$file->setFileId(4711);
		$file->setRole('musicxml');
		$file->setType('musicxml');
		$file->setName('1-03.musicxml');
		$scan = self::archiveFile(5, 35, 'score_pdf', '1-03.pdf');
		$scan->setSize(2_400_000);
		$files = $this->createStub(ArchiveFileMapper::class);
		$files->method('findByCopy')->willReturn([$file, $scan]);
		$files->method('find')->willReturnCallback(fn (int $id): ArchiveFile => match ($id) {
			4 => $file,
			5 => $scan,
			6 => self::archiveFile(6, 36, 'score_pdf', '1-04.pdf'),
			default => throw new DoesNotExistException('none'),
		});
		$scores = $this->createStub(ScoreService::class);
		$scores->method('isScore')->willReturn(true);
		$scores->method('scoreText')->willReturn(['xml' => self::SCORE, 'entry' => null]);
		$nodes = [4711 => $this->node(self::SCORE), 4705 => $this->node(self::PDF), 4706 => $this->node(self::PDF)];
		$folder = $this->createStub(Folder::class);
		$folder->method('getFirstNodeById')->willReturnCallback(fn (int $id): ?File => $nodes[$id] ?? null);
		$settings = $this->createStub(AppSettings::class);
		$settings->method('archiveInfo')->willReturn(['id' => 1, 'path' => 'Notenarchiv', 'user' => 'archive']);
		$settings->method('archiveFolder')->willReturn($folder);
		$roles = $this->createStub(Roles::class);
		$roles->method('isMember')->willReturn($member);
		$contribution = new Contribution();
		$contribution->setPersonId(1);
		$contribution->setRole('lyricist');
		$composer = new Contribution();
		$composer->setPersonId(2);
		$composer->setRole('composer');
		$contributions = $this->createStub(ContributionMapper::class);
		$contributions->method('findByVersion')->willReturn([$composer, $contribution]);
		$persons = $this->createStub(PersonMapper::class);
		$persons->method('find')->willReturnCallback(function (int $id): Person {
			$person = new Person();
			$person->setName($id === 1 ? 'H. Claudius' : 'J. Rheinberger');
			$person->setBorn('1740-08-15');
			return $person;
		});
		$licenses = [];
		foreach ([['By kind permission of Carus', null], ['Ended', '2026-01-01'], ['', null]] as [$notice, $until]) {
			$license = new License();
			$license->setShareNotice($notice);
			$license->setValidUntil($until);
			$licenses[] = $license;
		}
		$licenseMapper = $this->createStub(LicenseMapper::class);
		$licenseMapper->method('findByVersion')->willReturn($licenses);
		return new SharedLinkService($mapper, $copies, $versions, $works, $files, $scores, $this->policy($denials), $this->rights(), $roles,
			$this->modulesWithout($off), $settings, new LinkTokens(new FakeCrypto(), $this->createStub(ISecureRandom::class)),
			$contributions, $persons, $licenseMapper, $events, $items, new PageKeys(new FakeCrypto()));
	}

	private static function programme(int $id, ?string $date, string $title): Event {
		$event = new Event();
		$event->setId($id);
		$event->setTitle($title);
		$event->setEventDate($date);
		return $event;
	}

	/**
	 * Items of a programme in their order: a copy id, or ['version' => id]
	 * for an item without a copy, or ['struck' => copy id].
	 *
	 * @param list<int|array<string, int>> $pieces
	 * @return list<EventItem>
	 */
	private static function items(int $eventId, array $pieces): array {
		$items = [];
		foreach ($pieces as $position => $piece) {
			$item = new EventItem();
			$item->setId(100 * $eventId + $position);
			$item->setEventId($eventId);
			$item->setPosition($position + 1);
			$item->setCopyId(is_int($piece) ? $piece : ($piece['struck'] ?? null));
			$item->setVersionId(is_array($piece) ? ($piece['version'] ?? null) : null);
			$item->setStruck(is_array($piece) && isset($piece['struck']));
			$items[] = $item;
		}
		return $items;
	}

	private static function programmeKey(int $eventId): string {
		return (new PageKeys(new FakeCrypto()))->programme($eventId);
	}

	/**
	 * "Shared with me" by programme (U24): a link that came with the
	 * programme stands under it at the place of its piece; a piece of two
	 * programmes in both, unless a programme has a link of its own for it;
	 * struck items, deleted programmes and links made on their own stand
	 * under none. The next concert first, past ones after the coming ones,
	 * undated ones last.
	 */
	public function testThePageListsThePiecesByProgrammeInTheirOrder(): void {
		$programmes = [
			4 => [self::programme(4, '2026-12-13', 'Adventskonzert'), self::items(4, [41, ['struck' => 43], 42, 35])],
			5 => [self::programme(5, '2026-12-24', 'Weihnachtskonzert'), self::items(5, [35, 44])],
			6 => [self::programme(6, '2026-07-01', 'Sommerkonzert'), self::items(6, [45, ['version' => 47]])],
			8 => [self::programme(8, null, 'Neues Programm'), self::items(8, [48])],
		];
		$links = [];
		foreach ([['a', 44, 5], ['b', 35, 4], ['c', 42, 4], ['d', 41, 4], ['e', 43, 4], ['f', 45, 6], ['g', 46, 7], ['h', 41, null],
			['i', 35, 5], ['j', 47, 6], ['k', 48, 8]] as [$letter, $copyId, $eventId]) {
			$links[] = $this->link('members', ['token' => str_repeat($letter, 32), 'copy_id' => $copyId, 'event_id' => $eventId,
				'measure_from' => null, 'measure_to' => null]);
		}
		$list = $this->shared($links, programmes: $programmes)->forMember('singer');

		$places = [];
		foreach ($list as $row) {
			$places[$row['token'][0]] = array_map(fn (array $p): array => [$p['key'], $p['title'], $p['date'], $p['order'], $p['position']], $row['programmes']);
		}
		$advent = [self::programmeKey(4), 'Adventskonzert', '2026-12-13', 0];
		$christmas = [self::programmeKey(5), 'Weihnachtskonzert', '2026-12-24', 1];
		$summer = [self::programmeKey(6), 'Sommerkonzert', '2026-07-01', 2];
		self::assertSame([
			'a' => [[...$christmas, 1]],
			'b' => [[...$advent, 2]],
			'c' => [[...$advent, 1]],
			'd' => [[...$advent, 0]],
			'e' => [],
			'f' => [[...$summer, 0]],
			'g' => [],
			'h' => [],
			'i' => [[...$christmas, 0]],
			'j' => [[...$summer, 1]],
			'k' => [[self::programmeKey(8), 'Neues Programm', null, 3, 0]],
		], $places, 'struck items do not count; each programme keeps its own link of a piece of both; an item without a copy by its version');
		self::assertMatchesRegularExpression('/^[0-9a-f]{20}$/', $list[0]['programmes'][0]['key'], 'a key, not the id of the programme');

		$shared = $this->shared([$this->link('members', ['copy_id' => 35, 'event_id' => 4])], programmes: $programmes);
		self::assertSame([[...$advent, 2]], array_map(fn (array $p): array => [$p['key'], $p['title'], $p['date'], $p['order'], $p['position']],
			$shared->forMember('singer')[0]['programmes']), 'a piece of two programmes, the other one not shared with the choir: only under its own');
	}

	public function testChoirMembersSeeTheirLinksAndOnlyTheMeasures(): void {
		$links = [$this->link('members'), $this->link('internal', ['token' => str_repeat('i', 32)]),
			$this->link('members', ['token' => str_repeat('e', 32), 'expires' => '2026-10-21']), $this->link('public', ['token' => str_repeat('p', 32)])];
		$shared = $this->shared($links);
		$list = $shared->forMember('singer');
		self::assertCount(1, $list, 'only active links for choir members');
		self::assertSame(['token' => self::TOKEN, 'title' => 'Abendlied', 'composer' => 'J. Rheinberger', 'measure_from' => '2', 'measure_to' => '3',
			'voice' => 'Alto', 'verse' => null, 'tempo' => null, 'note' => 'Please practise until Thursday.', 'expires' => '2026-12-31',
			'canPlay' => true, 'canExportAudio' => true, 'reason' => null, 'scan' => false, 'scanSize' => null, 'offline' => true, 'keepUntil' => null, 'print' => true,
			'practiceKey' => (new PageKeys(new FakeCrypto()))->practice($this->version()),
			'credits' => [['role' => 'lyricist', 'name' => 'H. Claudius'], ['role' => 'arranger', 'name' => 'K. Satz']],
			'notices' => ['By kind permission of Carus'], 'programmes' => []], $list[0], 'the authors by name only, the notices of licenses valid today');

		$xml = $shared->memberScore(self::TOKEN, 'singer');
		self::assertStringContainsString('<measure number="2"', $xml);
		self::assertStringContainsString('<measure number="3"', $xml);
		self::assertStringNotContainsString('<measure number="1"', $xml, 'never the whole score');
		self::assertSame([9], $this->hits, 'the call is counted');
		self::assertTrue($shared->memberMayKeep(self::TOKEN, 'singer'), 'the worker may keep it (X-Cantoria-Keep)');
		self::assertFalse($shared->memberMayKeep(str_repeat('i', 32), 'singer'), 'not a link for choir members');
		self::assertSame([9], $this->hits, 'asking whether it may stay is no call');

		$this->expectException(UnknownLinkException::class);
		$shared->memberScore(str_repeat('i', 32), 'singer');
	}

	/**
	 * Printing the notes and keeping them on the device are copies (B21,
	 * S N5): without the rights for a copy the page offers no print view,
	 * and the worker keeps nothing of the answers.
	 */
	public function testWithoutTheRightsForACopyNothingIsPrintedOrKept(): void {
		$shared = $this->shared([$this->link('members')], ['memberScanDenial' => 'Choir members can save and print the scan: it needs a license.']);
		self::assertSame([false, false], [$shared->forMember('singer')[0]['print'], $shared->forMember('singer')[0]['offline']]);
		self::assertFalse($shared->memberMayKeep(self::TOKEN, 'singer'));
		self::assertFalse($this->shared([$this->link('members')], [], false)->memberMayKeep(self::TOKEN, 'reader'), 'no choir member');

		self::assertFalse($this->shared([$this->link('public')])->publicMayKeep(self::TOKEN), 'a public link without confirmed public domain');
		$free = $this->shared([$this->link('public')], [], true, ['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01']);
		$excerpt = $free->publicExcerpt(self::TOKEN);
		self::assertSame([true, true], [$excerpt['offline'] ?? null, $excerpt['print'] ?? null], 'confirmed public domain');
		self::assertTrue($free->publicMayKeep(self::TOKEN), 'it may stay on the device');
		self::assertFalse($free->publicMayKeep(str_repeat('z', 32)), 'unknown');
	}

	/**
	 * A piece that may not be kept is not a module switched off (A24): the
	 * page of a public link removes only itself from the device then, not
	 * every public piece kept there for a concert.
	 */
	public function testAPublicLinkTellsAPieceThatMayNotBeKeptFromTheModuleSwitchedOff(): void {
		$protected = $this->shared([$this->link('public')])->publicExcerpt(self::TOKEN);
		self::assertSame([false, true], [$protected['offline'] ?? null, $protected['offlineModule'] ?? null], 'this piece may not be kept, the module is on');
		$off = $this->shared([$this->link('public')], [], true, ['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01'], [], [Modules::OFFLINE])
			->publicExcerpt(self::TOKEN);
		self::assertSame([false, false], [$off['offline'] ?? null, $off['offlineModule'] ?? null], 'the module is off: nothing may stay');
	}

	public function testOthersAndWithdrawnLinksGetNothing(): void {
		try {
			$this->shared([$this->link('members')], [], false)->forMember('reader');
			self::fail('no choir member');
		} catch (AccessDeniedException) {
			self::assertTrue(true);
		}
		$shared = $this->shared([$this->link('members')], ['memberViewDenial' => 'Public domain is not confirmed yet.']);
		self::assertSame([], $shared->forMember('singer'), 'no longer released: not listed');
		$this->expectException(AccessDeniedException::class);
		$this->expectExceptionMessage('This piece is no longer shared with choir members.');
		$shared->memberScore(self::TOKEN, 'singer');
	}

	public function testChoirMembersGetTheWholePieceAndTheScanOfTheLink(): void {
		$whole = $this->link('members', ['measure_from' => null, 'measure_to' => null, 'scan_file_id' => 5]);
		$excerpt = $this->link('members', ['token' => str_repeat('x', 32)]);
		$shared = $this->shared([$whole, $excerpt]);
		$list = $shared->forMember('singer');
		self::assertSame([null, null, true], [$list[0]['measure_from'], $list[0]['measure_to'], $list[0]['scan']]);
		self::assertSame(2_400_000, $list[0]['scanSize'], 'the size of the scan for the switch on the device');
		self::assertFalse($list[1]['scan']);
		self::assertNull($list[1]['scanSize']);

		$xml = $shared->memberScore(self::TOKEN, 'singer');
		foreach (['1', '2', '3'] as $number) {
			self::assertStringContainsString('<measure number="' . $number . '"', $xml, 'the whole piece');
		}
		self::assertStringContainsString('<work-title>Abendlied</work-title>', $xml);

		self::assertSame(strlen(self::PDF), $shared->memberScan(self::TOKEN, 'singer')->getSize(), 'the scan of the link');
		try {
			$shared->memberScan(str_repeat('x', 32), 'singer');
			self::fail('a link without a scan');
		} catch (NotFoundException $e) {
			self::assertSame('The scan of this link is not available at the moment.', $e->getMessage());
		}
		$foreign = $this->shared([$this->link('members', ['scan_file_id' => 6])]);
		try {
			$foreign->memberScan(self::TOKEN, 'singer');
			self::fail('a scan of another copy');
		} catch (NotFoundException) {
			self::assertTrue(true);
		}
		$withdrawn = $this->shared([$whole], ['memberViewDenial' => 'Public domain is not confirmed yet.']);
		try {
			$withdrawn->memberScan(self::TOKEN, 'singer');
			self::fail('no longer released: no scan either');
		} catch (AccessDeniedException $e) {
			self::assertSame('This piece is no longer shared with choir members.', $e->getMessage());
		}
		$this->expectException(AccessDeniedException::class);
		$this->shared([$whole], [], false)->memberScan(self::TOKEN, 'reader');
	}

	public function testAPublicLinkShowsTheWholePieceAndItsScanUnderTheSameRules(): void {
		$whole = $this->link('public', ['measure_from' => null, 'measure_to' => null, 'scan_file_id' => 5]);
		$excerpt = $this->shared([$whole])->publicExcerpt(self::TOKEN);
		self::assertNotNull($excerpt);
		self::assertSame([null, null, true], [$excerpt['measureFrom'], $excerpt['measureTo'], $excerpt['scan']]);
		self::assertStringContainsString('<measure number="1"', $excerpt['xml'], 'the whole piece');
		self::assertInstanceOf(File::class, $this->shared([$whole])->publicScan(self::TOKEN));

		$refused = [
			'no scan' => [[$this->link('public')], []],
			'a scan of another copy' => [[$this->link('public', ['scan_file_id' => 6])], []],
			'for choir members' => [[$this->link('members', ['scan_file_id' => 5])], []],
			'revoked' => [[$this->link('public', ['scan_file_id' => 5, 'revoked' => 1700000000])], []],
			'expired' => [[$this->link('public', ['scan_file_id' => 5, 'expires' => '2026-10-21'])], []],
			'no longer released' => [[$whole], ['publicViewDenial' => 'Not released for publication.']],
			'no license for the download of the scan' => [[$whole], ['publicScanDenial' => 'Everyone who opens a public link can save and print its scan.']],
			'unknown' => [[], []],
		];
		foreach ($refused as $case => [$links, $denials]) {
			self::assertNull($this->shared($links, $denials)->publicScan(self::TOKEN), $case);
		}
		$withoutScan = $this->shared([$whole], ['publicScanDenial' => 'Everyone who opens a public link can save and print its scan.'])->publicExcerpt(self::TOKEN);
		self::assertNotNull($withoutScan, 'the page shows the notes');
		self::assertSame([false, null], [$withoutScan['scan'], $withoutScan['scanSize']], 'but offers no scan (R B2)');
	}

	public function testAPublicLinkShowsOnlyTheExcerptAndNothingInternal(): void {
		$excerpt = $this->shared([$this->link('public')])->publicExcerpt(self::TOKEN);
		self::assertNotNull($excerpt);
		self::assertSame(['title', 'composer', 'measureFrom', 'measureTo', 'voice', 'verse', 'tempo', 'canPlay', 'scan', 'scanSize', 'practiceKey', 'expires', 'offline',
			'offlineModule', 'print', 'served', 'xml', 'credits', 'notices'], array_keys($excerpt), 'no note, no shelf mark, no account');
		self::assertSame([false, false], [$excerpt['offline'], $excerpt['print']], 'no copy, also on paper, without confirmed public domain (B21)');
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $excerpt['served'], 'when the page was made, for the check on the device');
		self::assertSame(['By kind permission of Carus'], $excerpt['notices']);
		self::assertSame(['role', 'name'], array_keys($excerpt['credits'][0]), 'no life dates');
		self::assertStringNotContainsString('<measure number="1"', $excerpt['xml']);
		self::assertSame([9], $this->hits);

		$refused = [
			'unknown' => [[], self::TOKEN],
			'for choir members' => [[$this->link('members')], self::TOKEN],
			'revoked' => [[$this->link('public', ['revoked' => 1700000000])], self::TOKEN],
			'expired' => [[$this->link('public', ['expires' => '2026-10-21'])], self::TOKEN],
		];
		foreach ($refused as $case => [$links, $token]) {
			self::assertNull($this->shared($links)->publicExcerpt($token), $case);
		}
		self::assertNull($this->shared([$this->link('public')], ['publicViewDenial' => 'Not released for publication.'])->publicExcerpt(self::TOKEN),
			'no longer released');
		self::assertSame([9], $this->hits, 'refused calls are not counted');
	}

	public function testChoirMembersMakePracticeAudioOfTheirExcerptOnly(): void {
		$service = new PracticeAudioService($this->createStub(WorkVersionMapper::class), $this->createStub(CopyMapper::class), $this->policy(),
			$this->auditLog(), $this->createStub(Roles::class), $this->shared([$this->link('members')]), $this->rights());
		$service->authorizeForMember(self::TOKEN, ['voice' => 'Alto', 'mode' => 'solo', 'format' => 'wav', 'tempo' => 80, 'measure_from' => '1',
			'measure_to' => '3'], 'singer');
		$entry = $this->audit[0] ?? null;
		self::assertSame(PracticeAudioService::ACTION, $entry[0] ?? null);
		self::assertSame(['copy' => '1-3', 'measures' => '2-3', 'voice' => 'Alto', 'mode' => 'solo', 'format' => 'wav', 'tempo' => 80, 'repeats' => true],
			$entry[2] ?? null, 'the measures of the link, whatever was asked');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('Unknown field: %s');
		$service->authorizeForMember(self::TOKEN, ['format' => 'wav', 'copy_id' => '36'], 'singer');
	}

	public function testPracticeAudioOfTheWholePieceIsCheckedAndRecordedTheSame(): void {
		$whole = $this->link('members', ['measure_from' => null, 'measure_to' => null]);
		$service = fn (array $denials = []): PracticeAudioService => new PracticeAudioService($this->createStub(WorkVersionMapper::class),
			$this->createStub(CopyMapper::class), $this->policy($denials), $this->auditLog(), $this->createStub(Roles::class),
			$this->shared([$whole], $denials), $this->rights());
		$service()->authorizeForMember(self::TOKEN, ['voice' => 'Alto', 'mode' => 'focus', 'format' => 'midi', 'measure_from' => '1',
			'measure_to' => '3'], 'singer');
		self::assertSame(['copy' => '1-3', 'measures' => '1-3', 'voice' => 'Alto', 'mode' => 'focus', 'format' => 'midi', 'tempo' => 100, 'repeats' => true],
			$this->audit[0][2] ?? null, 'the measures of the file');
		try {
			$service()->authorizeForMember(self::TOKEN, ['format' => 'wav'], 'singer');
			self::fail('the measures of the file are named');
		} catch (InvalidInputException $e) {
			self::assertSame('Please give the first and the last measure.', $e->getMessage());
		}
		$reason = 'Public domain is not confirmed yet: practice audio files need a confirmed rights status or a license for practice audio.';
		$this->expectException(AccessDeniedException::class);
		$this->expectExceptionMessage($reason);
		$service(['memberPracticeAudioDenial' => $reason])->authorizeForMember(self::TOKEN, ['format' => 'wav', 'measure_from' => '1', 'measure_to' => '3'], 'singer');
	}

	// ---- the public repertoire page ----------------------------------------------------

	public function testTheRepertoirePageShowsChosenPublicDomainWorksOnly(): void {
		$works = [];
		foreach ([7 => ['Abendlied', true], 8 => ['Tischlied', true], 9 => ['Ave verum corpus', false]] as $id => [$title, $listed]) {
			$work = new Work();
			$work->setId($id);
			$work->setTitle($title);
			$work->setComposer('Composer ' . $id);
			$work->setPublicRepertoire($listed);
			$work->setCpdlUrl('https://www.cpdl.org/wiki/index.php/' . $id);
			$work->setImslpUrl('javascript:alert(1)');
			$works[$id] = $work;
		}
		$versions = [
			3 => $this->version(['work_id' => 7, 'rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01', 'scoring' => 'SATB']),
			4 => $this->version(['work_id' => 8, 'rights_status' => 'public_domain']),
			5 => $this->version(['work_id' => 9, 'rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01']),
		];
		foreach ($versions as $id => $version) {
			$version->setId($id);
		}
		$workMapper = $this->createStub(WorkMapper::class);
		$workMapper->method('findAllById')->willReturn($works);
		$versionMapper = $this->createStub(WorkVersionMapper::class);
		$versionMapper->method('findAllById')->willReturn($versions);
		$contribution = new Contribution();
		$contribution->setPersonId(1);
		$contribution->setRole('composer');
		$contributions = $this->createStub(ContributionMapper::class);
		$contributions->method('findAllByVersion')->willReturn([3 => [$contribution]]);
		$person = new Person();
		$person->setId(1);
		$person->setName('Josef Rheinberger');
		$person->setBorn('1839-03-17');
		$person->setBornPrecision('exact');
		$person->setDied('1901');
		$person->setDiedPrecision('year');
		$persons = $this->createStub(PersonMapper::class);
		$persons->method('findAllById')->willReturn([1 => $person]);

		$entries = (new PublicRepertoireService($workMapper, $versionMapper, $contributions, $persons, $this->modulesWithout()))->entries();
		self::assertSame([[
			'title' => 'Abendlied',
			'composer' => 'Composer 7',
			'persons' => [['role' => 'composer', 'name' => 'Josef Rheinberger', 'lifeDates' => '1839–1901']],
			'scoring' => ['SATB'],
			'cpdl' => 'https://www.cpdl.org/wiki/index.php/7',
			'imslp' => null,
		]], $entries, 'chosen and confirmed public domain; unsafe links are left out');

		$withoutRegister = (new PublicRepertoireService($workMapper, $versionMapper, $contributions, $persons, $this->modulesWithout(['persons'])))->entries();
		self::assertSame([], $withoutRegister[0]['persons'] ?? null, 'without the register of persons: the composer as written');
	}

	public function testLinksToCpdlAndImslpMustBeHttpsToTheirSite(): void {
		$hosts = ['imslp.org', 'www.imslp.org'];
		self::assertTrue(FieldValue::isLinkTo('https://imslp.org/wiki/Ave_verum_corpus,_K.618_(Mozart,_Wolfgang_Amadeus)', $hosts));
		self::assertTrue(FieldValue::isLinkTo('https://IMSLP.org', $hosts));
		foreach (['http://imslp.org/wiki/X', 'https://imslp.org.example.com/wiki/X', 'https://user@imslp.org/wiki/X', 'https://imslp.org:8443/wiki/X',
			'javascript:alert(1)', 'https://imslp.org/wiki/X Y', "https://imslp.org/wiki/X\n", '//imslp.org/wiki/X'] as $value) {
			self::assertFalse(FieldValue::isLinkTo($value, $hosts), $value);
		}
		$info = ['type' => 'text', 'label' => 'Link to IMSLP', 'write' => ['librarian'], 'max' => 500, 'hosts' => $hosts];
		self::assertNull(FieldValue::check($info, 'https://imslp.org/wiki/X'));
		self::assertSame('"%s" must be an https link to the page of the work on cpdl.org or imslp.org.', FieldValue::check($info, 'https://example.com/'));
	}

	public function testTheAuditLogNamesOnlyTheRoleOfAChoirMember(): void {
		$entries = [];
		$mapper = $this->createStub(AuditEntryMapper::class);
		$mapper->method('insert')->willReturnCallback(function (AuditEntry $entry) use (&$entries): AuditEntry {
			$entries[] = $entry;
			return $entry;
		});
		$roles = $this->createStub(Roles::class);
		$roles->method('rolesOf')->willReturn([]);
		$roles->method('isMember')->willReturnCallback(fn (string $uid): bool => $uid === 'singer');
		$users = $this->createStub(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(fn (string $uid): string => ucfirst($uid));
		$time = $this->createStub(ITimeFactory::class);
		// 2026-10-22 21:14:07 UTC
		$time->method('getTime')->willReturn(1792703647);
		$log = new AuditLog($mapper, $users, $roles, $time);
		$log->record('op', 'singer', 'version', 3, PracticeAudioService::ACTION);
		$log->record('op', 'stranger', 'version', 3, PracticeAudioService::ACTION);
		self::assertSame(['member', ''], array_map(fn (AuditEntry $entry): ?string => $entry->getRoles(), $entries));
		self::assertNull($entries[0]->getUserId(), 'no account of a choir member');
		self::assertNull($entries[0]->getUserName(), 'and no name');
		self::assertSame('2026-10-22 00:00:00', gmdate('Y-m-d H:i:s', (int)$entries[0]->getTime()), 'and only the day: "Bass, 21:14:07" could tell who it was');
		self::assertSame('stranger', $entries[1]->getUserId());
		self::assertSame(1792703647, $entries[1]->getTime());
	}
}
