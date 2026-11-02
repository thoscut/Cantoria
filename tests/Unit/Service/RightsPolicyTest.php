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
		$licenses = array_map(fn (array $spec): License => self::license(...$spec), $licenseSpecs);
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
		$licenses = array_map(fn (array $spec): License => self::license(...$spec), $licenseSpecs);
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
	private const MEMBER_NO_DOWNLOAD = 'Choir members can save and print the scan: unless public domain is confirmed, it needs a license for download and printing by choir members. The catalog librarian records it on the tab "Rights".';

	/**
	 * @return iterable<string, array{0: array<string, mixed>, 1: list<array{0: string, 1?: ?string, 2?: ?string}>, 2: ?string, 3: ?string, 4: ?string}>
	 */
	public static function memberCases(): iterable {
		$confirmed = ['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01'];
		// version values, licenses [uses, from, until], expected denial of viewing, playback, practice audio
		yield 'confirmed public domain' => [$confirmed, [], null, null, null];
		yield 'unconfirmed public domain' => [['rights_status' => 'public_domain'], [], self::MEMBER_UNCONFIRMED, self::MEMBER_UNCONFIRMED, self::MEMBER_UNCONFIRMED];
		yield 'unconfirmed public domain, portal license' => [['rights_status' => 'public_domain'], [['portal']], null, null, self::UNCONFIRMED_EXPORT];
		yield 'unconfirmed public domain, portal and practice audio' => [['rights_status' => 'public_domain'], [['portal,practice_audio']], null, null, null];
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
		$licenses = array_map(fn (array $spec): License => self::license(...$spec), $licenseSpecs);
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
		yield 'unconfirmed public domain, online license' => [['rights_status' => 'public_domain'] + $free, [['online_publication']], null, null];
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
		$licenses = array_map(fn (array $spec): License => self::license(...$spec), $licenseSpecs);
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
		$licenses = array_map(fn (array $spec): License => self::license(...$spec), $licenseSpecs);
		self::assertSame($denial, $this->practicePolicy([], $licenses)->memberScanDenial(self::version($values), self::TODAY));
	}

	/**
	 * @return iterable<string, array{0: array<string, mixed>, 1: list<array{0: string, 1?: ?string, 2?: ?string}>, 2: ?string}>
	 */
	public static function deviceCopyCases(): iterable {
		$confirmed = ['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01'];
		$noCopy = 'Not available without a network: unless public domain is confirmed, a copy on a device needs a license for download or self-printing. The catalog librarian records it on the tab "Rights".';
		// version values, licenses, expected denial of a copy on the device of the conductor
		yield 'confirmed public domain' => [$confirmed, [], null];
		yield 'unconfirmed public domain' => [['rights_status' => 'public_domain'], [], $noCopy];
		yield 'rights open: unknown counts as protected' => [['rights_status' => 'unknown'], [], $noCopy];
		yield 'protected, only copies for the folders' => [['rights_status' => 'protected'], [['photocopy']], $noCopy];
		yield 'protected, download license' => [['rights_status' => 'protected'], [['member_download']], null];
		yield 'protected, self-printing' => [['rights_status' => 'protected'], [['self_print']], null];
		yield 'protected, license ended' => [['rights_status' => 'protected'], [['self_print', null, '2026-10-21']], $noCopy];
		yield 'preservation copy' => [$confirmed + ['preservation_copy' => true], [], 'Internal preservation copy: it is not kept on a device.'];
	}

	/**
	 * The concert folder keeps the score and the scan for weeks on the device
	 * of the conductor: a copy, which needs confirmed public domain or a
	 * license for download or self-printing (milestone 0.19).
	 *
	 * @param array<string, mixed> $values
	 * @param list<array{0: string, 1?: ?string, 2?: ?string}> $licenseSpecs
	 */
	#[DataProvider('deviceCopyCases')]
	public function testACopyOnTheDeviceOfTheConductorNeedsTheRightsForIt(array $values, array $licenseSpecs, ?string $denial): void {
		$licenses = array_map(fn (array $spec): License => self::license(...$spec), $licenseSpecs);
		self::assertSame($denial, $this->practicePolicy([Roles::CONDUCTOR], $licenses)->deviceCopyDenial(self::version($values), self::UID));
	}
}
