<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Migration\ModuleDefaults;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\ModuleSettings;
use OCA\Cantoria\Tests\Unit\Support\ModuleSwitches;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Changing modules from the settings and the command line (with the audit
 * log), the description for the settings, and the switches written by the
 * repair step on installation and update.
 */
#[CoversClass(ModuleSettings::class)]
#[CoversClass(ModuleDefaults::class)]
final class ModuleSettingsTest extends TestCase {
	use ModuleSwitches;

	/** @var list<array{?string, string, string, mixed, mixed, ?string}> uid, object type, field, old, new, reason */
	private array $audit = [];

	/**
	 * @param array<string, string> $theming keys of the app theming
	 */
	private function settings(Modules $modules, array $theming = []): ModuleSettings {
		$audit = $this->createStub(AuditLog::class);
		$audit->method('newOperation')->willReturn('op');
		$audit->method('record')->willReturnCallback(function (string $operation, ?string $uid, string $objectType, ?int $objectId, string $action,
			?string $field = null, mixed $old = null, mixed $new = null, ?string $reason = null): void {
			$this->audit[] = [$uid, $objectType, (string)$field, $old, $new, $reason];
		});
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key): string => $app === 'theming' ? ($theming[$key] ?? '') : '');
		return new ModuleSettings($modules, $audit, $config);
	}

	private function l10n(): IL10N {
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text): string => '[' . $text . ']');
		return $l;
	}

	public function testEveryChangedSwitchIsRecorded(): void {
		$settings = $this->settings($this->modulesWithout(['score_viewer', 'playback']));
		$changes = $settings->change(['playback' => 'true'], 'admin');
		self::assertSame(['playback'], $changes['enabled']);
		self::assertSame(['score_viewer'], $changes['alsoEnabled']);
		self::assertSame([
			['admin', 'settings', 'module_playback', 'off', 'on', null],
			['admin', 'settings', 'module_score_viewer', 'off', 'on', null],
		], $this->audit);

		$this->audit = [];
		$settings->applyPreset(Modules::SIMPLE, null);
		self::assertContains([null, 'settings', 'module_concerts', 'on', 'off', 'preset simple'], $this->audit);
		self::assertNotContains('module_playback', array_column($this->audit, 2), 'unchanged switches are not recorded');
	}

	public function testOnlyAdministratorsSwitchOnWhatOpensTheArchive(): void {
		$settings = $this->settings($this->modulesWithout([Modules::AGENT_API, Modules::PRINT_MIRROR, 'notify_print_mirror_failed', 'concerts']));
		foreach ([[Modules::AGENT_API => true], ['notify_print_mirror_failed' => true]] as $wanted) {
			try {
				$settings->change($wanted, 'anna', false);
				self::fail('a group given the settings page switched on ' . json_encode($wanted));
			} catch (AccessDeniedException) {
				self::assertSame([], $this->audit);
			}
		}
		// Other modules, and switching off, stay with the groups given the page
		self::assertSame(['concerts'], $settings->change(['concerts' => true, Modules::PUBLIC_LINKS => false], 'anna', false)['enabled']);
		self::assertSame([Modules::AGENT_API], $settings->change([Modules::AGENT_API => true], 'admin')['enabled']);
	}

	public function testScenariosSwitchWhatTheChoirNeeds(): void {
		$singers = Modules::scenarioValues('singers');
		self::assertTrue($singers[Modules::MEMBER_LINKS]);
		self::assertTrue($singers['practice_audio'], 'the requirements of the scenario are on, also where the preset has them off');
		self::assertFalse($singers[Modules::PUBLIC_LINKS]);
		self::assertArrayNotHasKey(Modules::AGENT_API, $singers, 'a scenario leaves the interface for agents alone');
		self::assertFalse(Modules::scenarioValues('archive')['concerts']);
		// The modules of a scenario work (a preset may leave a notification switched on whose module is off)
		foreach (Modules::SCENARIOS as $key => $scenario) {
			foreach ($scenario['modules'] as $module => $on) {
				if ($on) {
					foreach (Modules::requirements($module) as $required) {
						self::assertTrue(Modules::scenarioValues($key)[$required] ?? false, "$key: $module needs $required");
					}
				}
			}
		}

		$settings = $this->settings($this->modulesWithout([Modules::MEMBER_LINKS, Modules::MEMBER_PRACTICE_AUDIO, Modules::OFFLINE]));
		try {
			$settings->applyScenario('singers', 'anna', false);
			self::fail('a group given the settings page opened the archive to the choir members');
		} catch (AccessDeniedException) {
			self::assertSame([], $this->audit);
		}
		$changes = $settings->applyScenario('singers', 'admin');
		self::assertContains(Modules::MEMBER_LINKS, $changes['enabled']);
		self::assertContains(['admin', 'settings', 'module_member_links', 'off', 'on', 'scenario singers'], $this->audit);

		$this->expectException(InvalidInputException::class);
		$settings->applyScenario('everything', 'admin');
	}

	public function testRequestValuesAreChecked(): void {
		$settings = $this->settings($this->modulesWithout());
		foreach ([[], ['concerts' => 'maybe'], ['concerts' => ['x']]] as $wanted) {
			try {
				$settings->change($wanted, 'admin');
				self::fail('accepted: ' . json_encode($wanted));
			} catch (InvalidInputException) {
				self::assertSame([], $this->audit);
			}
		}
	}

	public function testDescribesTheModulesForTheSettings(): void {
		$described = $this->settings($this->modulesWithout(['score_viewer']))->describe($this->l10n());
		$byKey = array_column($described['modules'], null, 'key');
		self::assertArrayHasKey(Modules::AGENT_API, $byKey, 'the interface for agents can be switched');
		self::assertSame('interfaces', $byKey[Modules::AGENT_API]['group']);
		self::assertSame(Modules::switchable(), array_keys($byKey));
		self::assertSame(['key' => 'playback', 'group' => 'scores', 'label' => '[Playback]',
			'description' => '[' . Modules::MODULES['playback']['description'] . ']', 'requires' => ['score_viewer'],
			'enabled' => true, 'active' => false], $byKey['playback']);
		self::assertSame(['rights', 'releases', 'scores', 'collaboration', 'concerts', 'maintenance', 'overview', 'notifications', 'sharing', 'interfaces'],
			array_column($described['groups'], 'key'));
		self::assertSame(Modules::PRESETS, array_column($described['presets'], 'key'));
		foreach ($described['presets'] as $preset) {
			self::assertArrayNotHasKey(Modules::AGENT_API, $preset['modules'], 'no preset switches the interface for agents');
		}
		self::assertSame('[Simple]', $described['presets'][0]['label']);
		self::assertSame(array_keys(Modules::SCENARIOS), array_column($described['scenarios'], 'key'));
		self::assertNull($described['scenarios'][0]['rights']);
		self::assertSame(Modules::scenarioValues('public'), $described['scenarios'][3]['modules']);
		self::assertCount(count(Modules::CORE), $described['core']);
		self::assertSame([Modules::PUBLIC_LINKS, Modules::PUBLIC_REPERTOIRE], $described['publicModules']);
		self::assertSame(['imprint' => false, 'privacy' => false], $described['legalPages'], 'the settings warn before public pages go online');
		$themed = $this->settings($this->modulesWithout(), ['imprintUrl' => 'https://choir.example/imprint', 'privacyUrl' => ' '])->describe($this->l10n());
		self::assertSame(['imprint' => true, 'privacy' => false], $themed['legalPages']);
	}

	private function runRepairStep(): void {
		(new ModuleDefaults($this->modules()))->run($this->createStub(IOutput::class));
	}

	public function testANewInstallationGetsTheStandard(): void {
		$this->runRepairStep();
		self::assertFalse($this->moduleConfig['module_statistics']);
		self::assertTrue($this->moduleConfig['module_concerts']);
		self::assertFalse($this->moduleConfig['module_agent_api']);
	}

	public function testAnUpdateKeepsTheSwitchesAndAddsNewOnes(): void {
		$this->moduleConfig = ['module_concerts' => false];
		$this->runRepairStep();
		self::assertFalse($this->moduleConfig['module_concerts'], 'a stored switch is never changed');
		self::assertFalse($this->moduleConfig['module_statistics'], 'a module new to the installation gets its default');
	}
}
