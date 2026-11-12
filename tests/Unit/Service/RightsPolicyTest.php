<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\License;
use OCA\Cantoria\Db\LicenseMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\PersonService;
use OCA\Cantoria\Service\ProtectionTerm;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Viewing and playback of scores. Uses the real RightsService for the license
 * logic; only the Nextcloud services and the database are replaced.
 */
#[CoversClass(RightsPolicy::class)]
#[UsesClass(RightsService::class)]
final class RightsPolicyTest extends TestCase {
	private const TODAY = '2026-10-22';
	private const UID = 'anna';
	private const VERSION_ID = 10;

	private const NO_ACCESS = 'You have no access to the music archive.';
	private const PRESERVATION_VIEW = 'Internal preservation copy: only the catalog librarian and the conductor may view it.';
	private const PRESERVATION_PLAY = 'Internal preservation copy: no playback.';
	private const PROTECTED_PLAY = 'Protected version: playback needs a license for practice audio.';
	private const UNCLEAR_PLAY = 'Rights not clarified: no playback until the rights status is known.';

	/**
	 * @param list<License> $licenses licenses of the version
	 */
	private function policy(bool $access, bool $preservationViewer, array $licenses = [], bool $expectLicenseLookup = true): RightsPolicy {
		$roles = $this->createStub(Roles::class);
		$roles->method('hasAccess')->willReturnCallback(fn (string $uid): bool => $uid === self::UID && $access);
		$roles->method('hasAnyRole')->willReturnCallback(
			fn (string $uid, array $allowed): bool => $uid === self::UID && $allowed === RightsPolicy::PRESERVATION_COPY_VIEWERS && $preservationViewer);

		$mapper = $this->createMock(LicenseMapper::class);
		$mapper->expects($expectLicenseLookup ? self::any() : self::never())
			->method('findByVersion')->with(self::VERSION_ID)->willReturn($licenses);

		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(
			fn (string $when = 'now', ?\DateTimeZone $zone = null): \DateTime => new \DateTime(self::TODAY . ' 12:00:00', $zone));
		$timeZone = $this->createStub(IDateTimeZone::class);
		$timeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$rights = new RightsService($this->createStub(IDBConnection::class), $this->createStub(WorkVersionMapper::class), $mapper,
			$this->createStub(AuditLog::class), $roles, $this->createStub(IUserManager::class), $time, $timeZone);

		return new RightsPolicy($roles, $rights, $mapper);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private static function version(array $values): WorkVersion {
		$version = new WorkVersion();
		$version->setId(self::VERSION_ID);
		foreach ($values as $column => $value) {
			$version->setValue($column, $value);
		}
		return $version;
	}

	private static function license(string $uses, ?string $from = null, ?string $until = null): License {
		return License::fromParams(['versionId' => self::VERSION_ID, 'uses' => $uses, 'validFrom' => $from, 'validUntil' => $until]);
	}

	// ---- viewing ----

	/**
	 * @return iterable<string, array{0: bool, 1: bool, 2: bool, 3: ?string}>
	 */
	public static function viewCases(): iterable {
		// access to the app, preservation copy viewer role, version is a preservation copy, expected denial
		yield 'no access' => [false, false, false, self::NO_ACCESS];
		yield 'no access beats every role' => [false, true, true, self::NO_ACCESS];
		yield 'ordinary version' => [true, false, false, null];
		yield 'preservation copy, other role' => [true, false, true, self::PRESERVATION_VIEW];
		yield 'preservation copy, librarian or conductor' => [true, true, true, null];
	}

	#[DataProvider('viewCases')]
	public function testViewDenial(bool $access, bool $viewer, bool $preservationCopy, ?string $denial): void {
		$version = self::version(['rights_status' => 'protected', 'preservation_copy' => $preservationCopy]);
		self::assertSame($denial, $this->policy($access, $viewer, [], false)->viewDenial($version, self::UID));
	}

	// ---- playback ----

	public function testPlaybackFollowsTheViewDenial(): void {
		$version = self::version(['rights_status' => 'public_domain']);
		self::assertSame(self::NO_ACCESS, $this->policy(false, false, [], false)->playDenial($version, self::UID));

		$preservationCopy = self::version(['rights_status' => 'public_domain', 'preservation_copy' => true]);
		self::assertSame(self::PRESERVATION_VIEW, $this->policy(true, false, [], false)->playDenial($preservationCopy, self::UID));
	}

	public function testPreservationCopiesAreNeverPlayed(): void {
		$version = self::version(['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01', 'preservation_copy' => true]);
		$policy = $this->policy(true, true, [self::license('practice_audio')], false);
		self::assertNull($policy->viewDenial($version, self::UID));
		self::assertSame(self::PRESERVATION_PLAY, $policy->playDenial($version, self::UID));
	}

	/**
	 * @return iterable<string, array{0: ?string, 1: list<array{0: string, 1?: ?string, 2?: ?string}>, 2: ?string}>
	 */
	public static function playbackCases(): iterable {
		// rights status, licenses [uses, from, until], expected denial
		yield 'protected without license' => ['protected', [], self::PROTECTED_PLAY];
		yield 'protected with practice audio license' => ['protected', [['practice_audio']], null];
		yield 'protected, practice audio among other uses' => ['protected', [['binder,practice_audio,recording']], null];
		yield 'protected, license ended yesterday' => ['protected', [['practice_audio', null, '2026-10-21']], self::PROTECTED_PLAY];
		yield 'protected, license ends today' => ['protected', [['practice_audio', null, self::TODAY]], null];
		yield 'protected, license starts tomorrow' => ['protected', [['practice_audio', '2026-10-23']], self::PROTECTED_PLAY];
		yield 'protected, license for the portal only' => ['protected', [['portal,print_station']], self::PROTECTED_PLAY];
		yield 'open without license' => ['open', [], self::UNCLEAR_PLAY];
		yield 'open with practice audio license' => ['open', [['practice_audio']], null];
		yield 'no rights status' => [null, [], self::UNCLEAR_PLAY];
	}

	/**
	 * @param list<array{0: string, 1?: ?string, 2?: ?string}> $licenseSpecs
	 */
	#[DataProvider('playbackCases')]
	public function testPlayDenial(?string $rightsStatus, array $licenseSpecs, ?string $denial): void {
		$licenses = array_map(fn (array $spec): License => self::license($spec[0], $spec[1] ?? null, $spec[2] ?? null), $licenseSpecs);
		$version = self::version(['rights_status' => $rightsStatus]);
		self::assertSame($denial, $this->policy(true, false, $licenses)->playDenial($version, self::UID));
	}

	public function testPublicDomainIsPlayedWithoutLookingUpLicenses(): void {
		$version = self::version(['rights_status' => 'public_domain']);
		self::assertNull($this->policy(true, false, [], false)->playDenial($version, self::UID));
	}

	// ---- practice audio files ----

	private const MAKERS_ONLY = 'Practice audio files are made by the catalog librarian or the conductor.';
	private const UNCONFIRMED_EXPORT = 'Public domain is not confirmed yet: practice audio files need a confirmed rights status or a license for practice audio.';

	/**
	 * A policy for a user with the given roles (access to the app with any role).
	 *
	 * @param list<string> $userRoles
	 * @param list<License> $licenses licenses of the version
	 */
	private function practicePolicy(array $userRoles, array $licenses = []): RightsPolicy {
		$roles = $this->createStub(Roles::class);
		$roles->method('hasAccess')->willReturnCallback(fn (string $uid): bool => $uid === self::UID && $userRoles !== []);
		$roles->method('rolesOf')->willReturn($userRoles);
		$roles->method('hasAnyRole')->willReturnCallback(
			fn (string $uid, array $allowed): bool => $uid === self::UID && array_intersect($userRoles, $allowed) !== []);

		$mapper = $this->createStub(LicenseMapper::class);
		$mapper->method('findByVersion')->willReturn($licenses);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(
			fn (string $when = 'now', ?\DateTimeZone $zone = null): \DateTime => new \DateTime(self::TODAY . ' 12:00:00', $zone));
		$timeZone = $this->createStub(IDateTimeZone::class);
		$timeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$rights = new RightsService($this->createStub(IDBConnection::class), $this->createStub(WorkVersionMapper::class), $mapper,
			$this->createStub(AuditLog::class), $roles, $this->createStub(IUserManager::class), $time, $timeZone);
		return new RightsPolicy($roles, $rights, $mapper);
	}

	/**
	 * @return iterable<string, array{0: list<string>, 1: array<string, mixed>, 2: list<array{0: string, 1?: ?string, 2?: ?string}>, 3: ?string}>
	 */
	public static function practiceAudioCases(): iterable {
		$confirmed = ['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01'];
		// roles of the user, version values, licenses [uses, from, until], expected denial
		yield 'confirmed public domain, librarian' => [[Roles::LIBRARIAN], $confirmed, [], null];
		yield 'confirmed public domain, conductor' => [[Roles::CONDUCTOR], $confirmed, [], null];
		yield 'confirmed public domain, operations' => [[Roles::OPERATIONS], $confirmed, [], self::MAKERS_ONLY];
		yield 'confirmed public domain, read only' => [[Roles::READER], $confirmed, [], self::MAKERS_ONLY];
		yield 'confirmed public domain, contributor' => [[Roles::CONTRIBUTOR], $confirmed, [], self::MAKERS_ONLY];
		yield 'no role at all' => [[], $confirmed, [], self::NO_ACCESS];
		yield 'unconfirmed public domain' => [[Roles::LIBRARIAN], ['rights_status' => 'public_domain'], [], self::UNCONFIRMED_EXPORT];
		yield 'unconfirmed public domain with practice audio license' => [[Roles::CONDUCTOR], ['rights_status' => 'public_domain'], [['practice_audio']], null];
		yield 'unconfirmed public domain, license for the portal only' => [[Roles::LIBRARIAN], ['rights_status' => 'public_domain'], [['portal']], self::UNCONFIRMED_EXPORT];
		yield 'protected without license' => [[Roles::LIBRARIAN], ['rights_status' => 'protected'], [], self::PROTECTED_PLAY];
		yield 'protected with practice audio license' => [[Roles::LIBRARIAN], ['rights_status' => 'protected'], [['binder,practice_audio']], null];
		yield 'protected, license ended yesterday' => [[Roles::LIBRARIAN], ['rights_status' => 'protected'], [['practice_audio', null, '2026-10-21']], self::PROTECTED_PLAY];
		yield 'protected with license, read only' => [[Roles::READER], ['rights_status' => 'protected'], [['practice_audio']], self::MAKERS_ONLY];
		yield 'open with practice audio license' => [[Roles::CONDUCTOR], ['rights_status' => 'open'], [['practice_audio']], null];
		yield 'open without license' => [[Roles::CONDUCTOR], ['rights_status' => 'open'], [], self::UNCLEAR_PLAY];
		yield 'preservation copy, confirmed and licensed' => [[Roles::LIBRARIAN], $confirmed + ['preservation_copy' => true], [['practice_audio']], self::PRESERVATION_PLAY];
		yield 'preservation copy, read only' => [[Roles::READER], ['rights_status' => 'protected', 'preservation_copy' => true], [['practice_audio']], self::PRESERVATION_VIEW];
	}

	/**
	 * @param list<string> $userRoles
	 * @param array<string, mixed> $values
	 * @param list<array{0: string, 1?: ?string, 2?: ?string}> $licenseSpecs
	 */
	#[DataProvider('practiceAudioCases')]
	public function testPracticeAudioDenial(array $userRoles, array $values, array $licenseSpecs, ?string $denial): void {
		$licenses = array_map(fn (array $spec): License => self::license($spec[0], $spec[1] ?? null, $spec[2] ?? null), $licenseSpecs);
		self::assertSame($denial, $this->practicePolicy($userRoles, $licenses)->practiceAudioDenial(self::version($values), self::UID));
	}

	public function testPracticeAudioNeedsMoreThanPlayback(): void {
		// Playback in the app accepts an unconfirmed public domain status, a file that leaves the app does not
		$version = self::version(['rights_status' => 'public_domain']);
		$policy = $this->practicePolicy([Roles::LIBRARIAN]);
		self::assertNull($policy->playDenial($version, self::UID));
		self::assertSame(self::UNCONFIRMED_EXPORT, $policy->practiceAudioDenial($version, self::UID));
	}

	// ---- links for choir members and public links (milestone 0.7) ----

	private const MEMBER_PRESERVATION = 'Internal preservation copy: it is never shared with choir members.';
	private const MEMBER_UNCONFIRMED = 'Public domain is not confirmed yet. The catalog librarian confirms it on the tab "Rights"; then the version can be shared with choir members.';
	private const MEMBER_NOT_RELEASED = 'Not released for choir members: the version needs confirmed public domain or a valid license for the singers\' portal. The catalog librarian records it on the tab "Rights".';
	private const PUBLIC_PRESERVATION = 'Internal preservation copy: it is never published.';
	private const PUBLIC_NOT_RELEASED = 'Not released for publication. The catalog librarian sets the publication status of the version to "Released for publication".';
	private const PUBLIC_UNCONFIRMED = 'Public domain is not confirmed yet. The catalog librarian confirms it on the tab "Rights"; then a public link can be made.';
	private const PUBLIC_NO_LICENSE = 'A public link needs confirmed public domain or a valid license for online publication. The catalog librarian records it on the tab "Rights".';
	private const PUBLIC_NO_AUDIO = 'Playback on a public link needs public domain or a license for online playback (audio). The catalog librarian records it on the tab "Rights".';
	private const MEMBER_UNCONFIRMED_PLAY = 'Public domain is not confirmed yet: playback for choir members needs a confirmed rights status or a license for practice audio.';
	private const PUBLIC_UNCONFIRMED_PLAY = 'Public domain is not confirmed yet: a public link plays only after the confirmation or with a license for online playback (audio), both on the tab "Rights".';
	private const MEMBER_NO_DOWNLOAD = 'Choir members can save and print the scan: unless public domain is confirmed, it needs a license for download and printing by choir members. The catalog librarian records it on the tab "Rights".';

	/**
	 * @return iterable<string, array{0: array<string, mixed>, 1: list<array{0: string, 1?: ?string, 2?: ?string}>, 2: ?string, 3: ?string, 4: ?string}>
	 */
	public static function memberCases(): iterable {
		$confirmed = ['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01'];
		// version values, licenses [uses, from, until], expected denial of viewing, playback, practice audio
		yield 'confirmed public domain' => [$confirmed, [], null, null, null];
		yield 'unconfirmed public domain' => [['rights_status' => 'public_domain'], [], self::MEMBER_UNCONFIRMED, self::MEMBER_UNCONFIRMED, self::MEMBER_UNCONFIRMED];
		// outside the archive only confirmed public domain plays (R B15): the portal license releases the notes, not their sound
		yield 'unconfirmed public domain, portal license' => [['rights_status' => 'public_domain'], [['portal']], null, self::MEMBER_UNCONFIRMED_PLAY, self::MEMBER_UNCONFIRMED_PLAY];
		yield 'unconfirmed public domain, portal and practice audio' => [['rights_status' => 'public_domain'], [['portal,practice_audio']], null, null, null];
		yield 'unconfirmed public domain, portal and an ended license for practice audio' => [['rights_status' => 'public_domain'],
			[['portal'], ['practice_audio', null, '2026-10-21']], null, self::MEMBER_UNCONFIRMED_PLAY, self::MEMBER_UNCONFIRMED_PLAY];
		yield 'protected, portal license' => [['rights_status' => 'protected'], [['portal']], null, self::PROTECTED_PLAY, self::PROTECTED_PLAY];
		yield 'protected, portal and practice audio' => [['rights_status' => 'protected'], [['portal'], ['practice_audio']], null, null, null];
		yield 'protected, practice audio only' => [['rights_status' => 'protected'], [['practice_audio']], self::MEMBER_NOT_RELEASED, self::MEMBER_NOT_RELEASED, self::MEMBER_NOT_RELEASED];
		yield 'protected, portal license ended' => [['rights_status' => 'protected'], [['portal', null, '2026-10-21']], self::MEMBER_NOT_RELEASED, self::MEMBER_NOT_RELEASED, self::MEMBER_NOT_RELEASED];
		yield 'open without license' => [['rights_status' => 'open'], [], self::MEMBER_NOT_RELEASED, self::MEMBER_NOT_RELEASED, self::MEMBER_NOT_RELEASED];
		yield 'preservation copy' => [$confirmed + ['preservation_copy' => true], [['portal,practice_audio']], self::MEMBER_PRESERVATION, self::MEMBER_PRESERVATION, self::MEMBER_PRESERVATION];
	}

	/**
	 * Links for choir members need the release for the singers' portal;
	 * playback and practice audio follow the rules of the archive. The
	 * account plays no part: the link is for the circle.
	 *
	 * @param array<string, mixed> $values
	 * @param list<array{0: string, 1?: ?string, 2?: ?string}> $licenseSpecs
	 */
	#[DataProvider('memberCases')]
	public function testLinksForChoirMembers(array $values, array $licenseSpecs, ?string $view, ?string $play, ?string $practiceAudio): void {
		$licenses = array_map(fn (array $spec): License => self::license($spec[0], $spec[1] ?? null, $spec[2] ?? null), $licenseSpecs);
		$policy = $this->practicePolicy([], $licenses);
		$version = self::version($values);
		self::assertSame($view, $policy->memberViewDenial($version, self::TODAY));
		self::assertSame($play, $policy->memberPlayDenial($version, self::TODAY));
		self::assertSame($practiceAudio, $policy->memberPracticeAudioDenial($version, self::TODAY));
	}

	/**
	 * @return iterable<string, array{0: array<string, mixed>, 1: list<array{0: string, 1?: ?string, 2?: ?string}>, 2: ?string, 3: ?string}>
	 */
	public static function publicCases(): iterable {
		$free = ['publication_status' => 'free'];
		$confirmed = ['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01'];
		// version values, licenses, expected denial of viewing and playback
		yield 'confirmed public domain, released' => [$confirmed + $free, [], null, null];
		yield 'confirmed public domain, not released' => [$confirmed, [], self::PUBLIC_NOT_RELEASED, self::PUBLIC_NOT_RELEASED];
		yield 'confirmed public domain, blocked' => [$confirmed + ['publication_status' => 'blocked'], [], self::PUBLIC_NOT_RELEASED, self::PUBLIC_NOT_RELEASED];
		yield 'unconfirmed public domain, released' => [['rights_status' => 'public_domain'] + $free, [], self::PUBLIC_UNCONFIRMED, self::PUBLIC_UNCONFIRMED];
		// an unconfirmed "public domain" shown under a license for online publication does not play (R B15)
		yield 'unconfirmed public domain, online license' => [['rights_status' => 'public_domain'] + $free, [['online_publication']], null, self::PUBLIC_UNCONFIRMED_PLAY];
		yield 'unconfirmed public domain, online publication and online playback' => [['rights_status' => 'public_domain'] + $free,
			[['online_publication,online_audio']], null, null];
		yield 'protected, online license' => [['rights_status' => 'protected'] + $free, [['online_publication']], null, self::PUBLIC_NO_AUDIO];
		yield 'protected, online and practice audio: practice audio is no online playback' => [['rights_status' => 'protected'] + $free,
			[['online_publication,practice_audio']], null, self::PUBLIC_NO_AUDIO];
		yield 'protected, online publication and online playback' => [['rights_status' => 'protected'] + $free, [['online_publication,online_audio']], null, null];
		yield 'protected, online playback ended' => [['rights_status' => 'protected'] + $free, [['online_publication'], ['online_audio', null, '2026-10-21']],
			null, self::PUBLIC_NO_AUDIO];
		yield 'protected, license for publication only' => [['rights_status' => 'protected'] + $free, [['publication']], self::PUBLIC_NO_LICENSE, self::PUBLIC_NO_LICENSE];
		yield 'protected, online license ended' => [['rights_status' => 'protected'] + $free, [['online_publication', null, '2026-10-21']], self::PUBLIC_NO_LICENSE, self::PUBLIC_NO_LICENSE];
		yield 'preservation copy' => [$confirmed + $free + ['preservation_copy' => true], [], self::PUBLIC_PRESERVATION, self::PUBLIC_PRESERVATION];
	}

	/**
	 * Public links need the release for publication and confirmed public
	 * domain or a license for online publication (a license for
	 * "publication" alone is not enough).
	 *
	 * @param array<string, mixed> $values
	 * @param list<array{0: string, 1?: ?string, 2?: ?string}> $licenseSpecs
	 */
	#[DataProvider('publicCases')]
	public function testPublicLinks(array $values, array $licenseSpecs, ?string $view, ?string $play): void {
		$licenses = array_map(fn (array $spec): License => self::license($spec[0], $spec[1] ?? null, $spec[2] ?? null), $licenseSpecs);
		$policy = $this->practicePolicy([], $licenses);
		$version = self::version($values);
		self::assertSame($view, $policy->publicViewDenial($version, self::TODAY));
		self::assertSame($play, $policy->publicPlayDenial($version, self::TODAY));
	}

	/**
	 * @return iterable<string, array{0: array<string, mixed>, 1: list<array{0: string, 1?: ?string, 2?: ?string}>, 2: ?string}>
	 */
	public static function memberScanCases(): iterable {
		$confirmed = ['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01'];
		// version values, licenses, expected denial of the scan in a link for choir members
		yield 'confirmed public domain' => [$confirmed, [], null];
		yield 'protected, portal license only: viewing, no copies' => [['rights_status' => 'protected'], [['portal']], self::MEMBER_NO_DOWNLOAD];
		yield 'protected, portal and download by members' => [['rights_status' => 'protected'], [['portal,member_download']], null];
		yield 'protected, portal and self-printing' => [['rights_status' => 'protected'], [['portal'], ['self_print']], null];
		yield 'protected, download license ended' => [['rights_status' => 'protected'], [['portal'], ['member_download', null, '2026-10-21']], self::MEMBER_NO_DOWNLOAD];
		yield 'unconfirmed public domain, portal license' => [['rights_status' => 'public_domain'], [['portal']], self::MEMBER_NO_DOWNLOAD];
		yield 'not released' => [['rights_status' => 'protected'], [['member_download']], self::MEMBER_NOT_RELEASED];
		yield 'preservation copy' => [$confirmed + ['preservation_copy' => true], [['portal,member_download']], self::MEMBER_PRESERVATION];
	}

	/**
	 * The scan in a link for choir members can be saved and printed: beyond
	 * the release of the notes it needs confirmed public domain or a license
	 * for download and printing by members (or self-printing).
	 *
	 * @param array<string, mixed> $values
	 * @param list<array{0: string, 1?: ?string, 2?: ?string}> $licenseSpecs
	 */
	#[DataProvider('memberScanCases')]
	public function testTheScanForChoirMembersNeedsALicenseForCopies(array $values, array $licenseSpecs, ?string $denial): void {
		$licenses = array_map(fn (array $spec): License => self::license($spec[0], $spec[1] ?? null, $spec[2] ?? null), $licenseSpecs);
		self::assertSame($denial, $this->practicePolicy([], $licenses)->memberScanDenial(self::version($values), self::TODAY));
	}

	/**
	 * @return iterable<string, array{0: array<string, mixed>, 1: list<array{0: string, 1?: ?string, 2?: ?string}>, 2: ?string, 3: ?string}>
	 */
	public static function handoverCases(): iterable {
		$confirmed = ['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01'];
		$noScores = 'New prints and parts can be made from a MusicXML file: unless public domain is confirmed, passing it on to choir members needs a license for digital score files (tab "Rights").';
		$noRecording = 'A recording has rights of its own (performers, producer): passing it on to choir members needs a license for passing on recordings (tab "Rights").';
		// version values, licenses, expected denial for MusicXML, for a recording
		yield 'confirmed public domain: MusicXML yes, a recording needs its own license' => [$confirmed, [], null, $noRecording];
		yield 'confirmed public domain with a license for recordings' => [$confirmed, [['recording_sharing']], null, null];
		yield 'download and printing cover no MusicXML' => [['rights_status' => 'protected'], [['portal,member_download,self_print,practice_audio']], $noScores, $noRecording];
		yield 'digital score files and recordings' => [['rights_status' => 'protected'], [['portal,digital_scores,practice_audio,recording_sharing']], null, null];
		yield 'recordings without practice audio' => [['rights_status' => 'protected'], [['portal,recording_sharing']], $noScores, self::PROTECTED_PLAY];
		yield 'unconfirmed public domain: a recording plays only as for choir members' => [['rights_status' => 'public_domain'], [['portal,recording_sharing']],
			$noScores, self::MEMBER_UNCONFIRMED_PLAY];
		yield 'preservation copy' => [$confirmed + ['preservation_copy' => true], [['portal,digital_scores,recording_sharing']], self::MEMBER_PRESERVATION, self::MEMBER_PRESERVATION];
	}

	/**
	 * The hand-over folder: MusicXML is a digital edition of its own, and a
	 * recording has rights of performers and producer (R B6, B7).
	 *
	 * @param array<string, mixed> $values
	 * @param list<array{0: string, 1?: ?string, 2?: ?string}> $licenseSpecs
	 */
	#[DataProvider('handoverCases')]
	public function testTheHandOverNeedsUsesForDigitalScoresAndRecordings(array $values, array $licenseSpecs, ?string $score, ?string $audio): void {
		$licenses = array_map(fn (array $spec): License => self::license($spec[0], $spec[1] ?? null, $spec[2] ?? null), $licenseSpecs);
		$policy = $this->practicePolicy([], $licenses);
		self::assertSame($score, $policy->handoverScoreDenial(self::version($values), self::TODAY), 'MusicXML');
		self::assertSame($audio, $policy->handoverAudioDenial(self::version($values), self::TODAY), 'recording');
	}

	/**
	 * @return iterable<string, array{0: array<string, mixed>, 1: list<array{0: string, 1?: ?string, 2?: ?string}>, 2: ?string, 3: ?string}>
	 */
	public static function deviceCopyCases(): iterable {
		$confirmed = ['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01'];
		$noCopy = 'Not available without a network: unless public domain is confirmed, a copy on a device needs a license for download or self-printing. The catalog librarian records it on the tab "Rights".';
		$noScores = 'Not available without a network: unless public domain is confirmed, the notes (MusicXML) also need a license for digital score files. The catalog librarian records it on the tab "Rights".';
		// version values, licenses, expected denial of a copy on the device of the conductor with the notes, with the scan alone
		yield 'confirmed public domain' => [$confirmed, [], null, null];
		yield 'unconfirmed public domain' => [['rights_status' => 'public_domain'], [], $noCopy, $noCopy];
		yield 'rights open: unknown counts as protected' => [['rights_status' => 'unknown'], [], $noCopy, $noCopy];
		yield 'protected, only copies for the folders' => [['rights_status' => 'protected'], [['photocopy']], $noCopy, $noCopy];
		yield 'protected, download license: the scan, not the MusicXML (R B26)' => [['rights_status' => 'protected'], [['member_download']], $noScores, null];
		yield 'protected, self-printing and digital score files' => [['rights_status' => 'protected'], [['self_print'], ['digital_scores']], null, null];
		yield 'protected, digital score files alone' => [['rights_status' => 'protected'], [['digital_scores']], $noCopy, $noCopy];
		yield 'protected, license ended' => [['rights_status' => 'protected'], [['self_print,digital_scores', null, '2026-10-21']], $noCopy, $noCopy];
		yield 'preservation copy' => [$confirmed + ['preservation_copy' => true], [], 'Internal preservation copy: it is not kept on a device.',
			'Internal preservation copy: it is not kept on a device.'];
	}

	/**
	 * The concert folder keeps the score and the scan for weeks on the device
	 * of the conductor: a copy, which needs confirmed public domain or a
	 * license for download or self-printing (milestone 0.19), and for the
	 * MusicXML a license for digital score files (R B26).
	 *
	 * @param array<string, mixed> $values
	 * @param list<array{0: string, 1?: ?string, 2?: ?string}> $licenseSpecs
	 */
	#[DataProvider('deviceCopyCases')]
	public function testACopyOnTheDeviceOfTheConductorNeedsTheRightsForIt(array $values, array $licenseSpecs, ?string $withNotes, ?string $scanOnly): void {
		$licenses = array_map(fn (array $spec): License => self::license($spec[0], $spec[1] ?? null, $spec[2] ?? null), $licenseSpecs);
		$policy = $this->practicePolicy([Roles::CONDUCTOR], $licenses);
		self::assertSame($withNotes, $policy->deviceDenial(RightsPolicy::CIRCLE_FOLDER, self::version($values), self::TODAY));
		self::assertSame($scanOnly, $policy->deviceDenial(RightsPolicy::CIRCLE_FOLDER, self::version($values), self::TODAY, null, false));
	}

	// ---- one rule for printing and keeping on a device, for every circle (A23, R B26, B30) ----

	/**
	 * A policy with a person register whose protection proposal is given.
	 *
	 * @param list<License> $licenses
	 */
	private function matrixPolicy(string $proposal, array $licenses, ?LicenseMapper $mapper = null): RightsPolicy {
		$roles = $this->createStub(Roles::class);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime(self::TODAY . ' 12:00:00'));
		if ($mapper === null) {
			$mapper = $this->createStub(LicenseMapper::class);
			$mapper->method('findByVersion')->willReturn($licenses);
		}
		$rights = new RightsService($this->createStub(IDBConnection::class), $this->createStub(WorkVersionMapper::class), $mapper,
			$this->createStub(AuditLog::class), $roles, $this->createStub(IUserManager::class), $time, $this->createStub(IDateTimeZone::class));
		$persons = $this->createStub(PersonService::class);
		$persons->method('protection')->willReturn(['status' => $proposal]);
		return new RightsPolicy($roles, $rights, $mapper, $persons);
	}

	/**
	 * Rights status x licenses x way: printing and keeping on a device with
	 * the notes (MusicXML) or with the scan alone, for choir members, the
	 * concert folder and the visitors of a public link. The expectation is
	 * written out here as the documentation says it (admin guide, sharing):
	 * - choir members: released for them (confirmed public domain or the
	 *   portal), then confirmed public domain or a license for download or
	 *   self-printing; on a device the MusicXML also needs digital score files;
	 * - concert folder: the same without the release for members;
	 * - public: only confirmed public domain without a rights conflict, whatever
	 *   the licenses (R B30).
	 */
	public function testPrintingAndKeepingFollowOneRuleForEveryCircle(): void {
		$states = [
			'confirmed public domain' => [['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01'], ProtectionTerm::FREE],
			'confirmed, but a rights conflict' => [['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01'], ProtectionTerm::PROTECTED],
			'unconfirmed public domain' => [['rights_status' => 'public_domain'], ProtectionTerm::FREE],
			'protected' => [['rights_status' => 'protected'], ProtectionTerm::PROTECTED],
			'open' => [['rights_status' => 'unknown'], ProtectionTerm::PROTECTED],
		];
		$licenseSets = ['', 'portal', 'portal,member_download', 'portal,self_print', 'portal,member_download,digital_scores', 'member_download,digital_scores',
			'online_publication', 'online_publication,online_download', 'online_publication,online_download,digital_scores,member_download'];
		$checked = 0;
		foreach ($states as $stateName => [$values, $proposal]) {
			$version = self::version($values + ['publication_status' => 'free']);
			$confirmed = ($values['confirmed_at'] ?? null) !== null;
			$free = $confirmed && $proposal !== ProtectionTerm::PROTECTED;
			foreach ($licenseSets as $set) {
				$uses = $set === '' ? [] : explode(',', $set);
				$policy = $this->matrixPolicy($proposal, $uses === [] ? [] : [self::license($set)]);
				$has = fn (string ...$any): bool => array_intersect($any, $uses) !== [];
				$copy = $confirmed || $has('member_download', 'self_print');
				$notes = $confirmed || $has('digital_scores');
				$expected = [
					'members' => ['print' => ($confirmed || $has('portal')) && $copy],
					'folder' => ['print' => $copy],
					'public' => ['print' => $free],
				];
				foreach ($expected as $circle => $ways) {
					$expected[$circle] += ['scan on a device' => $ways['print'], 'notes on a device' => $ways['print'] && ($circle === 'public' || $notes)];
				}
				foreach ($expected as $circle => $ways) {
					$circleKey = $circle === 'folder' ? RightsPolicy::CIRCLE_FOLDER : $circle;
					$actual = [
						'print' => $policy->printDenial($circleKey, $version, self::TODAY),
						'scan on a device' => $policy->deviceDenial($circleKey, $version, self::TODAY, null, false),
						'notes on a device' => $policy->deviceDenial($circleKey, $version, self::TODAY),
					];
					foreach ($ways as $way => $allowed) {
						$case = "$stateName, licenses [$set], $circle, $way";
						if ($allowed) {
							self::assertNull($actual[$way], $case);
						} else {
							self::assertIsString($actual[$way], $case);
							self::assertNotSame('', $actual[$way], $case . ': says why');
						}
						$checked++;
					}
				}
			}
		}
		self::assertSame(5 * 9 * 3 * 3, $checked);
	}

	public function testAVisitorNeverKeepsWhatAConflictSuspends(): void {
		$confirmed = self::version(['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01', 'publication_status' => 'free']);
		$licensed = $this->matrixPolicy(ProtectionTerm::PROTECTED, [self::license('online_publication,online_download,digital_scores')]);
		self::assertNull($licensed->publicViewDenial($confirmed, self::TODAY), 'the license shows it');
		self::assertSame('Visitors of a public link hold no license: printing the piece or keeping it on a device needs confirmed public domain without a rights conflict.',
			$licensed->printDenial('public', $confirmed, self::TODAY), 'R B30: no print, the conflict counts as for scan and playback');
		self::assertNotNull($licensed->deviceDenial('public', $confirmed, self::TODAY));
		self::assertNull($this->matrixPolicy(ProtectionTerm::FREE, [])->deviceDenial('public', $confirmed, self::TODAY));
		self::assertSame('Not released for publication. The catalog librarian sets the publication status of the version to "Released for publication".',
			$this->matrixPolicy(ProtectionTerm::FREE, [])->printDenial('public', self::version(['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01']), self::TODAY));
	}

	public function testTheDeviceLearnsWhenTheLicensesForTheCopyEnd(): void {
		$protected = self::version(['rights_status' => 'protected']);
		$policy = $this->practicePolicy([], [self::license('portal', null, '2027-06-30'), self::license('member_download', null, '2027-03-31'),
			self::license('digital_scores', null, '2027-01-31')]);
		self::assertSame('2027-01-31', $policy->deviceEnd('members', $protected, self::TODAY), 'the earliest: here the digital score files');
		self::assertSame('2027-03-31', $policy->deviceEnd('members', $protected, self::TODAY, false), 'the scan alone');
		self::assertSame('2027-01-31', $policy->deviceEnd(RightsPolicy::CIRCLE_FOLDER, $protected, self::TODAY), 'R B29');
		self::assertSame('2027-03-31', $policy->deviceEnd(RightsPolicy::CIRCLE_FOLDER, self::version(['rights_status' => 'protected']), self::TODAY, false),
			'the folder needs no release for choir members');
		self::assertNull($policy->deviceEnd('members', self::version(['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01']), self::TODAY));
		self::assertNull($this->practicePolicy([], [self::license('portal,member_download,digital_scores')])->deviceEnd('members', $protected, self::TODAY),
			'no end');
	}

	public function testWhileRememberingTheLicensesOfAVersionAreReadOnce(): void {
		$mapper = $this->createMock(LicenseMapper::class);
		$mapper->expects(self::exactly(2))->method('findByVersion')->with(self::VERSION_ID)->willReturn([self::license('portal,member_download')]);
		$policy = $this->matrixPolicy(ProtectionTerm::FREE, [], $mapper);
		$protected = self::version(['rights_status' => 'protected']);
		$answers = $policy->remembering(fn (): array => [$policy->memberViewDenial($protected, self::TODAY), $policy->memberScanDenial($protected, self::TODAY),
			$policy->printDenial('members', $protected, self::TODAY), $policy->deviceDenial('members', $protected, self::TODAY, null, false)]);
		self::assertSame([null, null, null, null], $answers);
		// outside it every question reads them anew: a license saved in the same request counts
		self::assertNull($policy->memberViewDenial($protected, self::TODAY));
	}

	public function testAnUnknownCircleIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->practicePolicy([])->printDenial('internal', self::version(['rights_status' => 'protected']), self::TODAY);
	}

	// ---- milestone 0.18: the scan of public links, the end and the number of licenses, rights conflicts ----

	private const PUBLIC_NO_DOWNLOAD = 'Everyone can save the scan of a public link: it needs confirmed public domain or a license for the download of the scan on public links. The catalog librarian records it on the tab "Rights".';

	/**
	 * @return iterable<string, array{0: array<string, mixed>, 1: list<array{0: string, 1?: ?string, 2?: ?string}>, 2: ?string}>
	 */
	public static function publicScanCases(): iterable {
		$free = ['publication_status' => 'free'];
		$confirmed = ['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01'];
		// version values, licenses, expected denial of the scan of a public link
		yield 'confirmed public domain' => [$confirmed + $free, [], null];
		yield 'protected, online publication only: showing, no download (R B2)' => [['rights_status' => 'protected'] + $free, [['online_publication']], self::PUBLIC_NO_DOWNLOAD];
		yield 'protected, online publication and download of the scan' => [['rights_status' => 'protected'] + $free, [['online_publication,' . RightsPolicy::PUBLIC_SCAN_USE]], null];
		yield 'protected, download by choir members is no download for everyone' => [['rights_status' => 'protected'] + $free,
			[['online_publication,member_download']], self::PUBLIC_NO_DOWNLOAD];
		yield 'protected, download license ended' => [['rights_status' => 'protected'] + $free,
			[['online_publication'], [RightsPolicy::PUBLIC_SCAN_USE, null, '2026-10-21']], self::PUBLIC_NO_DOWNLOAD];
		yield 'not released for publication' => [['rights_status' => 'protected'], [['online_publication,' . RightsPolicy::PUBLIC_SCAN_USE]], self::PUBLIC_NOT_RELEASED];
	}

	/**
	 * @param array<string, mixed> $values
	 * @param list<array{0: string, 1?: ?string, 2?: ?string}> $licenseSpecs
	 */
	#[DataProvider('publicScanCases')]
	public function testTheScanOfAPublicLinkNeedsItsOwnLicense(array $values, array $licenseSpecs, ?string $denial): void {
		$licenses = array_map(fn (array $spec): License => self::license($spec[0], $spec[1] ?? null, $spec[2] ?? null), $licenseSpecs);
		self::assertSame($denial, $this->practicePolicy([], $licenses)->publicScanDenial(self::version($values), self::TODAY));
	}

	public function testALinkLastsAtMostAsLongAsItsLicense(): void {
		$protected = self::version(['rights_status' => 'protected', 'publication_status' => 'free']);
		$policy = $this->practicePolicy([], [self::license('portal', null, '2027-03-31'), self::license('portal', null, '2027-01-31'),
			self::license('member_download', null, '2026-12-15'), self::license('online_publication', null, '2027-06-30'), self::license('portal', null, '2026-10-01')]);
		self::assertSame('2027-03-31', $policy->releaseEnd($protected, 'members', false, self::TODAY), 'the license for the portal that lasts longest');
		self::assertSame('2026-12-15', $policy->releaseEnd($protected, 'members', true, self::TODAY), 'with the scan: the earlier end of the license for download');
		self::assertSame('2027-06-30', $policy->releaseEnd($protected, 'public', true, self::TODAY), 'no license for the download: the scan is refused anyway');
		self::assertNull($policy->releaseEnd($protected, 'internal', false, self::TODAY), 'the archive roles need no license');
		self::assertNull($this->practicePolicy([], [self::license('portal', null, '2027-03-31'), self::license('portal')])
			->releaseEnd($protected, 'members', false, self::TODAY), 'a license without end');
		self::assertNull($policy->releaseEnd(self::version(['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01']), 'members', true, self::TODAY),
			'confirmed public domain does not end');
	}

	public function testTheNumberOfLicensesIsKnownOnlyWhenEveryLicenseHasOne(): void {
		$protected = self::version(['rights_status' => 'protected']);
		$license = function (string $uses, ?int $quantity, ?string $until = null): License {
			$license = self::license($uses, null, $until);
			$license->setQuantity($quantity);
			return $license;
		};
		self::assertSame(30, $this->practicePolicy([], [$license('portal', 20), $license('portal', 10), $license('portal', 50, '2026-10-01')])
			->licensedQuantity($protected, 'members', self::TODAY), 'the licenses valid today, added up');
		self::assertSame(12, $this->practicePolicy([], [$license('portal', 40), $license('member_download', 12)])
			->licensedQuantity($protected, 'members', self::TODAY), 'the smaller number: notes or scan');
		self::assertNull($this->practicePolicy([], [$license('portal', 20), $license('portal', null)])->licensedQuantity($protected, 'members', self::TODAY));
		self::assertNull($this->practicePolicy([], [$license('portal', 20)])
			->licensedQuantity(self::version(['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01']), 'members', self::TODAY));
	}

	public function testARightsConflictClosesPublicLinksWithoutALicense(): void {
		$confirmed = self::version(['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01', 'publication_status' => 'free']);
		$roles = $this->createStub(Roles::class);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime(self::TODAY . ' 12:00:00'));
		$build = function (string $proposal, array $licenses) use ($roles, $time): RightsPolicy {
			$mapper = $this->createStub(LicenseMapper::class);
			$mapper->method('findByVersion')->willReturn($licenses);
			$rights = new RightsService($this->createStub(IDBConnection::class), $this->createStub(WorkVersionMapper::class), $mapper,
				$this->createStub(AuditLog::class), $roles, $this->createStub(IUserManager::class), $time, $this->createStub(IDateTimeZone::class));
			$persons = $this->createStub(PersonService::class);
			$persons->method('protection')->willReturn(['status' => $proposal]);
			return new RightsPolicy($roles, $rights, $mapper, $persons);
		};
		$conflict = 'Rights conflict: confirmed as public domain, but protected by the persons recorded now. Public links stay closed until the catalog librarian checks the tab "Rights".';
		self::assertSame($conflict, $build(ProtectionTerm::PROTECTED, [])->publicViewDenial($confirmed, self::TODAY), 'R M4');
		self::assertSame($conflict, $build(ProtectionTerm::PROTECTED, [])->publicScanDenial($confirmed, self::TODAY));
		self::assertNull($build(ProtectionTerm::FREE, [])->publicViewDenial($confirmed, self::TODAY));
		self::assertNull($build(ProtectionTerm::PROTECTED, [self::license('online_publication')])->publicViewDenial($confirmed, self::TODAY), 'a license carries it');
		self::assertSame(self::PUBLIC_NO_DOWNLOAD, $build(ProtectionTerm::PROTECTED, [self::license('online_publication')])->publicScanDenial($confirmed, self::TODAY),
			'the scan then needs its own license');
		self::assertNull($build(ProtectionTerm::PROTECTED, [])->memberViewDenial($confirmed, self::TODAY), 'choir members are not concerned');
		// playback follows the publishing: under a conflict only a license for online playback carries it (R B15)
		self::assertNull($build(ProtectionTerm::FREE, [])->publicPlayDenial($confirmed, self::TODAY));
		self::assertSame(self::PUBLIC_NO_AUDIO, $build(ProtectionTerm::PROTECTED, [self::license('online_publication')])->publicPlayDenial($confirmed, self::TODAY));
		self::assertNull($build(ProtectionTerm::PROTECTED, [self::license('online_publication,online_audio')])->publicPlayDenial($confirmed, self::TODAY));
	}

	// ---- songbooks ----

	public function testASongbookNeedsConfirmedPublicDomainOrALicenseForPrintedCopies(): void {
		$denied = 'A songbook needs confirmed public domain or a license for copies for the binders or for the print station. The catalog librarian records it on the tab "Rights".';
		$confirmed = self::version(['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01']);
		$unconfirmed = self::version(['rights_status' => 'public_domain']);
		$protected = self::version(['rights_status' => 'protected']);
		self::assertNull($this->policy(true, false)->songbookDenial($confirmed, self::TODAY));
		self::assertSame($denied, $this->policy(true, false)->songbookDenial($unconfirmed, self::TODAY), 'public domain counts only when confirmed');
		self::assertSame($denied, $this->policy(true, false)->songbookDenial($protected, self::TODAY));
		self::assertNull($this->policy(true, false, [self::license('binder')])->songbookDenial($protected, self::TODAY));
		self::assertNull($this->policy(true, false, [self::license('print_station')])->songbookDenial($protected, self::TODAY));
		self::assertSame($denied, $this->policy(true, false, [self::license('portal,member_download')])->songbookDenial($protected, self::TODAY),
			'downloads by single members are not copies printed for the choir');
		self::assertSame($denied, $this->policy(true, false, [self::license('binder', null, '2026-01-31')])->songbookDenial($protected, self::TODAY),
			'a license that has ended');
		self::assertSame($denied, $this->policy(true, false, [self::license('binder', '2099-01-01')])->songbookDenial($protected, self::TODAY),
			'a license not yet valid');
	}

	public function testAPreservationCopyNeverGoesIntoASongbook(): void {
		$version = self::version(['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01', 'preservation_copy' => true]);
		self::assertSame('Internal preservation copy: it does not go into a songbook.',
			$this->policy(true, true, [self::license('binder')], false)->songbookDenial($version, self::TODAY));
	}

	// ---- the programme booklet: lyrics printed for the audience (November 2026) ----

	public function testLyricsInABookletNeedConfirmedPublicDomainOrALicenseForPublication(): void {
		$denied = 'Printing lyrics in a booklet needs confirmed public domain or a license for publication. The catalog librarian records it on the tab "Rights".';
		$confirmed = self::version(['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01']);
		$unconfirmed = self::version(['rights_status' => 'public_domain']);
		$protected = self::version(['rights_status' => 'protected']);
		self::assertNull($this->policy(true, false)->bookletLyricsDenial($confirmed, self::TODAY));
		self::assertSame($denied, $this->policy(true, false)->bookletLyricsDenial($unconfirmed, self::TODAY), 'public domain counts only when confirmed');
		self::assertSame($denied, $this->policy(true, false)->bookletLyricsDenial($protected, self::TODAY));
		self::assertNull($this->policy(true, false, [self::license('publication')])->bookletLyricsDenial($protected, self::TODAY));
		self::assertSame($denied, $this->policy(true, false, [self::license('binder,portal,practice_audio')])->bookletLyricsDenial($protected, self::TODAY),
			'copies for the choir are no publication for the audience');
		self::assertSame($denied, $this->policy(true, false, [self::license('publication', null, '2026-01-31')])->bookletLyricsDenial($protected, self::TODAY),
			'a license that has ended');
	}

	public function testTheLyricsOfAPreservationCopyAreNeverPrinted(): void {
		$version = self::version(['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01', 'preservation_copy' => true]);
		self::assertSame('Internal preservation copy: its lyrics are not printed.',
			$this->policy(true, true, [self::license('publication')], false)->bookletLyricsDenial($version, self::TODAY));
	}

	// ---- large-print parts for singers with a visual impairment (November 2026) ----

	public function testALargePrintPartNeedsConfirmedPublicDomainALicenseOrTheConfirmationOfTheLibrarian(): void {
		$denied = 'A large-print part is a printed copy: unless public domain is confirmed, it needs a license for an accessible format or for printed copies for singers. Without one, the catalog librarian can confirm that the part is made for a person with a visual impairment (§ 45b UrhG).';
		$confirmed = self::version(['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01']);
		$unconfirmed = self::version(['rights_status' => 'public_domain']);
		$protected = self::version(['rights_status' => 'protected']);
		self::assertNull($this->policy(true, false)->largePrintDenial($confirmed, self::TODAY));
		self::assertSame('free', $this->policy(true, false)->largePrintBasis($confirmed, self::TODAY));
		self::assertSame($denied, $this->policy(true, false)->largePrintDenial($unconfirmed, self::TODAY), 'public domain counts only when confirmed');
		self::assertSame($denied, $this->policy(true, false)->largePrintDenial($protected, self::TODAY));
		foreach (RightsPolicy::LARGE_PRINT_USES as $use) {
			self::assertNull($this->policy(true, false, [self::license($use)])->largePrintDenial($protected, self::TODAY), $use);
			self::assertSame('license', $this->policy(true, false, [self::license($use)])->largePrintBasis($protected, self::TODAY), $use);
		}
		self::assertSame($denied, $this->policy(true, false, [self::license('portal,practice_audio,publication')])->largePrintDenial($protected, self::TODAY),
			'viewing, sound and publication are no printed copy for a singer');
		self::assertSame($denied, $this->policy(true, false, [self::license('accessible_format', null, '2026-01-31')])->largePrintDenial($protected, self::TODAY),
			'a license that has ended');
		self::assertNull($this->policy(true, false)->largePrintDenial($protected, self::TODAY, null, true),
			'the confirmation of an accessible copy (§ 45b UrhG)');
		self::assertNull($this->policy(true, false)->largePrintBasis($protected, self::TODAY), 'a confirmation is no license');
	}

	public function testNoLargePrintPartIsMadeFromAPreservationCopyEvenWhenConfirmed(): void {
		$version = self::version(['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01', 'preservation_copy' => true]);
		self::assertSame('Internal preservation copy: no part is printed from it.',
			$this->policy(true, true, [self::license('accessible_format')], false)->largePrintDenial($version, self::TODAY, null, true));
	}
}
