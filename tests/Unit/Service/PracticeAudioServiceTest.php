<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\Input;
use OCA\Cantoria\Service\PracticeAudioService;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\SharedLinkService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The gatekeeper for practice audio files: the rights policy decides, an
 * allowed export is recorded in the audit log with measures, voice, mode and
 * format, a refused or invalid one is not.
 */
#[CoversClass(PracticeAudioService::class)]
#[UsesClass(Input::class)]
final class PracticeAudioServiceTest extends TestCase {
	private const UID = 'conductor';
	private const VERSION_ID = 10;
	private const COPY_ID = 35;

	private ?string $denial = null;
	private AuditLog&MockObject $audit;

	#[\Override]
	protected function setUp(): void {
		$this->audit = $this->createMock(AuditLog::class);
		$this->audit->method('newOperation')->willReturn('op-1');
	}

	private function service(): PracticeAudioService {
		$version = new WorkVersion();
		$version->setId(self::VERSION_ID);
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('find')->willReturnCallback(fn (int $id): WorkVersion => $id === self::VERSION_ID || $id === 11
			? $version : throw new DoesNotExistException('none'));
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('find')->willReturnCallback(function (int $id): Copy {
			if ($id !== self::COPY_ID && $id !== 36) {
				throw new DoesNotExistException('none');
			}
			$copy = new Copy();
			$copy->setId($id);
			$copy->setVersionId($id === self::COPY_ID ? self::VERSION_ID : 11);
			$copy->setShelfmark($id === self::COPY_ID ? '2-2' : '2-3');
			return $copy;
		});
		$policy = $this->createStub(RightsPolicy::class);
		$policy->method('practiceAudioDenial')->willReturnCallback(fn (): ?string => $this->denial);
		return new PracticeAudioService($versions, $copies, $policy, $this->audit, $this->createStub(Roles::class),
			$this->createStub(SharedLinkService::class), $this->createStub(RightsService::class));
	}

	/**
	 * @param array<string, mixed> $changes
	 * @return array<string, mixed>
	 */
	private static function request(array $changes = []): array {
		return array_merge([
			'copy_id' => self::COPY_ID,
			'measure_from' => '0',
			'measure_to' => '11',
			'voice' => 'Tenor 1',
			'mode' => 'focus',
			'format' => 'wav',
			'all_voices' => false,
			'tempo' => 80,
			'repeats' => true,
		], $changes);
	}

	public function testAnAllowedExportIsRecorded(): void {
		$this->audit->expects(self::once())->method('record')->with('op-1', self::UID, 'version', self::VERSION_ID, 'practice_audio', null, null, [
			'copy' => '2-2',
			'measures' => '0-11',
			'voice' => 'Tenor 1',
			'mode' => 'focus',
			'format' => 'wav',
			'tempo' => 80,
			'repeats' => true,
		]);
		self::assertSame(['ok' => true], $this->service()->authorize(self::VERSION_ID, self::request(), self::UID));
	}

	public function testAFileForEveryVoiceIsRecordedAsSuch(): void {
		$this->audit->expects(self::once())->method('record')->with('op-1', self::UID, 'version', self::VERSION_ID, 'practice_audio', null, null, [
			'copy' => '2-2',
			'measures' => '1-4',
			'voice' => '*',
			'mode' => 'solo',
			'format' => 'midi',
			'tempo' => 100,
			'repeats' => false,
		]);
		$request = ['copy_id' => (string)self::COPY_ID, 'measure_from' => 1, 'measure_to' => '4', 'mode' => 'solo', 'format' => 'midi',
			'all_voices' => true, 'repeats' => false];
		$this->service()->authorize(self::VERSION_ID, $request, self::UID);
	}

	public function testATuttiNeedsNoVoice(): void {
		$this->audit->expects(self::once())->method('record');
		$this->service()->authorize(self::VERSION_ID, self::request(['voice' => null, 'mode' => 'all']), self::UID);
	}

	public function testATuttiIsRecordedWithoutAVoice(): void {
		$this->audit->expects(self::once())->method('record')->with('op-1', self::UID, 'version', self::VERSION_ID, 'practice_audio', null, null,
			self::callback(fn (array $details): bool => $details['voice'] === null && $details['mode'] === 'all'));
		$this->service()->authorize(self::VERSION_ID, self::request(['mode' => 'all']), self::UID);
	}

	public function testARefusedExportIsNotRecorded(): void {
		$this->denial = 'Protected version: playback needs a license for practice audio.';
		$this->audit->expects(self::never())->method('record');
		$this->expectException(AccessDeniedException::class);
		$this->expectExceptionMessage('Protected version: playback needs a license for practice audio.');
		$this->service()->authorize(self::VERSION_ID, self::request(), self::UID);
	}

	public function testTheRightsComeBeforeTheRequestData(): void {
		// a refused user learns the reason, not what is wrong with the request
		$this->denial = 'Internal preservation copy: no playback.';
		$this->expectException(AccessDeniedException::class);
		$this->service()->authorize(self::VERSION_ID, ['format' => 'mp3'], self::UID);
	}

	public function testAnUnknownVersionIsRefused(): void {
		$this->audit->expects(self::never())->method('record');
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('Not found.');
		$this->service()->authorize(99, self::request(), self::UID);
	}

	public function testTheCopyMustBelongToTheVersion(): void {
		$this->audit->expects(self::never())->method('record');
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The copy does not belong to this version.');
		$this->service()->authorize(self::VERSION_ID, self::request(['copy_id' => 36]), self::UID);
	}

	/**
	 * @return iterable<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function invalidRequests(): iterable {
		yield 'unknown field' => [['file_name' => 'x.wav'], 'Unknown field: %s'];
		yield 'no copy' => [['copy_id' => null], '"%s": value not allowed.'];
		yield 'copy id is no id' => [['copy_id' => 'abc'], '"%s": value not allowed.'];
		yield 'no first measure' => [['measure_from' => null], 'Please give the first and the last measure.'];
		yield 'measure with other characters' => [['measure_to' => '4<script>'], 'Measure numbers may only contain digits, letters and dots.'];
		yield 'nested value' => [['voice' => ['Tenor']], '"%s": value not allowed.'];
		yield 'voice with a line break' => [['voice' => "Tenor\n1"], '"%s": value not allowed.'];
		yield 'voice too long' => [['voice' => str_repeat('a', 101)], '"%s": value not allowed.'];
		yield 'unknown mode' => [['mode' => 'karaoke'], '"%s": value not allowed.'];
		yield 'unknown format' => [['format' => 'mp3'], '"%s": value not allowed.'];
		yield 'no format' => [['format' => null], '"%s": value not allowed.'];
		yield 'emphasis without a voice' => [['voice' => ' '], 'Please choose the voice to emphasise.'];
		yield 'every voice, all equal' => [['all_voices' => true, 'mode' => 'all'], 'A file for every voice needs the mode "focus voice louder" or "focus voice only".'];
		yield 'tempo too slow' => [['tempo' => 24], '"%s": value not allowed.'];
		yield 'tempo too fast' => [['tempo' => 201], '"%s": value not allowed.'];
		yield 'tempo with decimals' => [['tempo' => 80.5], '"%s": value not allowed.'];
		yield 'repeats not yes or no' => [['repeats' => 'maybe'], '"%s" must be yes or no.'];
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	#[DataProvider('invalidRequests')]
	public function testInvalidRequestsAreRefused(array $changes, string $message): void {
		$this->audit->expects(self::never())->method('record');
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage($message);
		$this->service()->authorize(self::VERSION_ID, self::request($changes), self::UID);
	}
}
