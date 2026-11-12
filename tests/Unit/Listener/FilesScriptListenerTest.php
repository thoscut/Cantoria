<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Listener;

use OCA\Cantoria\Listener\FilesScriptListener;
use OCA\Cantoria\Service\FilesIntegrationService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Tests\Unit\Support\ModuleSwitches;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Settings\IManager as ISettingsManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who gets the script of the action "Open in Cantoria" in the Files
 * app: accounts with a role and admins (who may read), while the module is
 * on and the account sees the archive folder. Nobody else.
 */
#[CoversClass(FilesScriptListener::class)]
final class FilesScriptListenerTest extends TestCase {
	use ModuleSwitches;

	private const STATE = ['archivePath' => '/Notenarchiv', 'extensions' => ['pdf', 'mxl'], 'scoreExtensions' => ['musicxml', 'mxl'], 'scoreView' => true];

	/** @var array<string, list<string>> groups of each account */
	private array $groups = [
		'librarian' => ['archive'],
		'board' => ['board'],
		'admin' => ['admin'],
		'singer' => ['choir'],
	];
	private ?string $sessionUser = 'librarian';
	/** @var list<string> modules switched off */
	private array $off = [];
	/** @var array{archivePath: string, extensions: list<string>, scoreExtensions: list<string>, scoreView: bool}|null */
	private ?array $clientState = self::STATE;
	private ?\Throwable $clientStateFails = null;
	/** @var array<string, mixed> initial state provided */
	private array $provided = [];
	private int $warnings = 0;

	private function listener(): FilesScriptListener {
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturnCallback(function (): ?IUser {
			if ($this->sessionUser === null) {
				return null;
			}
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($this->sessionUser);
			return $user;
		});
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueArray')->willReturnCallback(fn (string $app, string $key): array => match ($key) {
			'groups_librarian' => ['archive'],
			'groups_reader' => ['board'],
			default => [],
		});
		$groupManager = $this->createStub(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => in_array($group, $this->groups[$uid] ?? [], true));
		$groupManager->method('isAdmin')->willReturnCallback(fn (string $uid): bool => in_array('admin', $this->groups[$uid] ?? [], true));
		$roles = new Roles($config, $groupManager, $this->createStub(IUserManager::class), $this->createStub(ISettingsManager::class), $this->createStub(LoggerInterface::class));

		$filesIntegration = $this->createStub(FilesIntegrationService::class);
		$filesIntegration->method('clientState')->willReturnCallback(function (): ?array {
			if ($this->clientStateFails !== null) {
				throw $this->clientStateFails;
			}
			return $this->clientState;
		});
		$initialState = $this->createStub(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(function (string $key, mixed $data): void {
			$this->provided[$key] = $data;
		});
		$logger = $this->createStub(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (): void {
			$this->warnings++;
		});

		return new class($session, $roles, $this->modulesWithout($this->off), $filesIntegration, $initialState, $logger) extends FilesScriptListener {
			public int $scripts = 0;

			#[\Override]
			protected function addScript(): void {
				$this->scripts++;
			}
		};
	}

	/**
	 * Runs the listener for the Files app and tells how many scripts it added.
	 */
	private function scriptsFor(?string $uid, ?Event $event = null): int {
		$this->sessionUser = $uid;
		$this->provided = [];
		$listener = $this->listener();
		$listener->handle($event ?? new LoadAdditionalScriptsEvent());
		self::assertObjectHasProperty('scripts', $listener);
		/** @psalm-suppress UndefinedPropertyFetch the property of the anonymous test class in listener() */
		$scripts = $listener->scripts;
		self::assertIsInt($scripts);
		self::assertSame($scripts === 1, $this->provided !== [], 'the initial state comes with the script and only with it');
		return $scripts;
	}

	public function testAccountsWithARoleGetTheScriptAndTheArchiveFolder(): void {
		self::assertSame(1, $this->scriptsFor('librarian'));
		self::assertSame(1, $this->scriptsFor('board'), 'read-only role');
		self::assertSame([FilesScriptListener::STATE => self::STATE], $this->provided);
		self::assertSame('cantoria-files', FilesScriptListener::SCRIPT);
	}

	public function testAdminsWithoutARoleGetItBecauseTheyMayRead(): void {
		self::assertSame(1, $this->scriptsFor('admin'));
	}

	public function testAccountsWithoutAccessGetNothing(): void {
		self::assertSame(0, $this->scriptsFor('singer'));
		self::assertSame(0, $this->scriptsFor('unknown'));
		self::assertSame(0, $this->scriptsFor(null), 'not logged in');
	}

	public function testNothingWhileTheModuleIsOff(): void {
		$this->off = [Modules::FILES_INTEGRATION];
		self::assertSame(0, $this->scriptsFor('librarian'));
		self::assertSame(0, $this->scriptsFor('admin'));
	}

	public function testNothingWhenTheAccountCannotSeeTheArchiveFolder(): void {
		$this->clientState = null;
		self::assertSame(0, $this->scriptsFor('librarian'));
	}

	public function testAFailureNeverBreaksTheFilesApp(): void {
		$this->clientStateFails = new \RuntimeException('storage not available');
		self::assertSame(0, $this->scriptsFor('librarian'));
		self::assertSame(1, $this->warnings);
	}

	public function testOtherEventsAreIgnored(): void {
		self::assertSame(0, $this->scriptsFor('librarian', new Event()));
	}
}
