<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\ModuleDisabledException;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\NotificationService;
use OCA\Cantoria\Tests\Unit\Support\ModuleSwitches;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The module register: consistent dependencies, the effective state, the
 * resolution of dependencies when switching, the presets and the switches
 * written on installation and update.
 */
#[CoversClass(Modules::class)]
final class ModulesTest extends TestCase {
	use ModuleSwitches;

	public function testTheRegisterIsConsistent(): void {
		foreach (Modules::MODULES as $key => $module) {
			self::assertMatchesRegularExpression('/^[a-z][a-z_]*$/', $key);
			self::assertArrayHasKey($module['group'], Modules::GROUPS, $key);
			self::assertNotSame('', $module['label'], $key);
			self::assertStringEndsWith('.', $module['description'], $key);
			foreach ($module['requires'] as $required) {
				self::assertTrue(Modules::exists($required), $key . ' requires ' . $required);
				self::assertFalse(Modules::isPlanned($required), $key . ' requires a planned module');
			}
			self::assertNotContains($key, Modules::requirements($key), $key . ' depends on itself');
		}
		self::assertSame(array_keys(Modules::PRESET_LABELS), Modules::PRESETS);
		self::assertSame([], array_values(array_filter(array_keys(Modules::MODULES), Modules::isPlanned(...))), 'nothing is planned at the moment');
		foreach (Modules::OUTSIDE_PRESETS as $key) {
			self::assertTrue(Modules::exists($key), $key);
			self::assertFalse(Modules::MODULES[$key]['standard'], $key . ' is off by default');
			self::assertFalse(Modules::inPresets($key), $key);
		}
	}

	public function testTheInterfaceForAgentsIsSwitchedOnlyByItself(): void {
		foreach ([Modules::AGENT_API, Modules::PRINT_MIRROR, Modules::MEMBER_LINKS, Modules::PUBLIC_LINKS, Modules::PUBLIC_REPERTOIRE] as $outside) {
			self::assertContains($outside, Modules::OUTSIDE_PRESETS);
		}
		self::assertContains(Modules::AGENT_API, Modules::switchable());
		self::assertSame('interfaces', Modules::MODULES[Modules::AGENT_API]['group']);
		self::assertSame([], Modules::requirements(Modules::AGENT_API));

		$modules = $this->modulesWithout([Modules::AGENT_API]);
		self::assertFalse($modules->isEnabled(Modules::AGENT_API));
		$modules->applyPreset(Modules::COMPLETE);
		self::assertFalse($modules->isEnabled(Modules::AGENT_API), '"complete" does not open the interface');
		$changes = $modules->change([Modules::AGENT_API => true]);
		self::assertSame([Modules::AGENT_API], $changes['enabled']);
		self::assertTrue($modules->isEnabled(Modules::AGENT_API));
		$modules->applyPreset(Modules::SIMPLE);
		self::assertTrue($modules->isEnabled(Modules::AGENT_API), 'a preset chosen later does not close it either');
		$modules->change([Modules::AGENT_API => false]);
		self::assertFalse($modules->isEnabled(Modules::AGENT_API));
		self::assertFalse($this->moduleConfig['module_agent_api']);
	}

	/**
	 * Everything that opens the archive beyond its roles (milestone 0.7) is
	 * off on new and updated installations and part of no preset, not even
	 * "complete": the choir decides, then the administration switches it on.
	 */
	public function testSharingBeyondTheRolesIsSwitchedOnlyByItself(): void {
		$sharing = [Modules::MEMBER_LINKS, Modules::MEMBER_PRACTICE_AUDIO, Modules::PUBLIC_LINKS, Modules::PUBLIC_REPERTOIRE, Modules::OFFLINE];
		self::assertSame([Modules::AGENT_API, ...$sharing, Modules::CONCERT_FOLDER, Modules::PRINT_MIRROR, Modules::CHOIR_HANDOVER], Modules::OUTSIDE_PRESETS);
		foreach ($sharing as $key) {
			self::assertSame('sharing', Modules::MODULES[$key]['group'], $key);
			self::assertFalse(Modules::MODULES[$key]['simple'], $key);
			self::assertFalse(Modules::MODULES[$key]['standard'], $key);
			foreach (Modules::PRESETS as $preset) {
				self::assertArrayNotHasKey($key, Modules::presetValues($preset), $key . ' is part of no preset');
			}
		}
		self::assertSame(['excerpt_links', 'playback', 'releases', 'score_viewer', 'rights'], Modules::requirements(Modules::MEMBER_LINKS));
		self::assertSame([Modules::MEMBER_LINKS, 'practice_audio', 'excerpt_links', 'playback', 'releases', 'score_viewer', 'rights'],
			Modules::requirements(Modules::MEMBER_PRACTICE_AUDIO));
		self::assertSame(['excerpt_links', 'playback', 'publication', 'score_viewer', 'releases', 'rights'], Modules::requirements(Modules::PUBLIC_LINKS));
		self::assertSame(['rights'], Modules::requirements(Modules::PUBLIC_REPERTOIRE));

		// A new installation: everything off; "complete" opens nothing of it
		$modules = $this->modules();
		$modules->writeBaseline();
		foreach ($sharing as $key) {
			self::assertFalse($modules->isEnabled($key), $key . ' is off on a new installation');
		}
		$modules->applyPreset(Modules::COMPLETE);
		foreach ($sharing as $key) {
			self::assertFalse($modules->isEnabled($key), $key . ' stays off with "complete"');
		}

		// Switching on practice audio for choir members also switches on what it needs
		$modules = $this->modulesWithout([...$sharing, 'practice_audio']);
		$changes = $modules->change([Modules::MEMBER_PRACTICE_AUDIO => true]);
		self::assertSame([Modules::MEMBER_PRACTICE_AUDIO], $changes['enabled']);
		self::assertSame(['practice_audio', Modules::MEMBER_LINKS], $changes['alsoEnabled']);
		self::assertTrue($modules->isEnabled(Modules::MEMBER_PRACTICE_AUDIO));
		self::assertFalse($modules->isEnabled(Modules::PUBLIC_LINKS), 'the public stays closed');
		$modules->applyPreset(Modules::SIMPLE);
		self::assertTrue($modules->isSwitchedOn(Modules::MEMBER_LINKS), 'a preset does not touch the switch');
		self::assertFalse($modules->isEnabled(Modules::MEMBER_LINKS), 'but "simple" switches off the excerpt links it needs');
	}

	public function testEveryNotificationHasASwitch(): void {
		$subjects = array_values((new \ReflectionClass(NotificationService::class))->getConstants());
		self::assertCount(16, $subjects);
		foreach ($subjects as $subject) {
			self::assertIsString($subject);
			$key = Modules::notificationKey($subject);
			self::assertNotNull($key, $subject);
			self::assertSame('notifications', Modules::MODULES[$key]['group']);
			self::assertContains('notifications', Modules::MODULES[$key]['requires'], $key);
		}
		self::assertNull(Modules::notificationKey('unknown'));
	}

	public function testRequirementsAreResolvedTransitively(): void {
		self::assertSame(['playback', 'score_viewer'], Modules::requirements('practice_audio'));
		self::assertSame(['notifications', 'portal', 'releases', 'rights'], Modules::requirements('notify_portal_remove'));
		self::assertSame([], Modules::requirements('concerts'));
	}

	public function testAModuleIsActiveOnlyWithEverythingItRequires(): void {
		$modules = $this->modulesWithout(['score_viewer']);
		self::assertFalse($modules->isEnabled('score_viewer'));
		self::assertTrue($modules->isSwitchedOn('playback'), 'the own switch stays');
		self::assertFalse($modules->isEnabled('playback'));
		self::assertFalse($modules->isEnabled('practice_audio'));
		self::assertFalse($modules->isEnabled('excerpt_links'));
		self::assertTrue($modules->isEnabled('concerts'));
		self::assertTrue($modules->isEnabled(Modules::AGENT_API), 'needs nothing else');
		self::assertCount(count(Modules::MODULES), $modules->states());
	}

	public function testUnknownModulesAreProgrammingErrors(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->modules()->isEnabled('nonsense');
	}

	public function testRequireEnabledThrows(): void {
		$modules = $this->modulesWithout(['statistics']);
		$modules->requireEnabled('concerts');
		$this->expectException(ModuleDisabledException::class);
		$modules->requireEnabled('statistics');
	}

	public function testNotificationsFollowTheirSwitchTheMasterAndTheirModule(): void {
		self::assertTrue($this->modulesWithout()->notificationEnabled(NotificationService::TASK_NEW));
		self::assertFalse($this->modulesWithout(['notify_task_new'])->notificationEnabled(NotificationService::TASK_NEW));
		$this->moduleConfig = [];
		self::assertFalse($this->modulesWithout(['tasks'])->notificationEnabled(NotificationService::TASK_NEW));
		$this->moduleConfig = [];
		$modules = $this->modulesWithout(['notifications']);
		self::assertFalse($modules->notificationEnabled(NotificationService::EXPORT_FAILED));
		self::assertFalse($modules->notificationEnabled('unknown subject'), 'follows the master switch');
	}

	public function testSwitchingOnAlsoSwitchesOnWhatItNeeds(): void {
		$modules = $this->modulesWithout(['score_viewer', 'playback', 'practice_audio']);
		$changes = $modules->change(['practice_audio' => true]);
		self::assertSame(['practice_audio'], $changes['enabled']);
		self::assertSame(['score_viewer', 'playback'], $changes['alsoEnabled']);
		self::assertSame([], $changes['disabled']);
		self::assertSame(['excerpt_links', 'transcription_review', 'corrections', Modules::VERSION_STATES, Modules::CONCERT_FOLDER, 'notify_review_points',
			'notify_correction_received', Modules::MEMBER_LINKS, Modules::MEMBER_PRACTICE_AUDIO, Modules::PUBLIC_LINKS], $changes['activated'],
			'their switches were on');
		self::assertTrue($modules->isEnabled('practice_audio'));
		self::assertTrue($this->moduleConfig['module_score_viewer']);
	}

	public function testSwitchingOffKeepsTheSwitchesOfWhatNeedsIt(): void {
		$modules = $this->modulesWithout(['statistics']);
		$changes = $modules->change(['concerts' => false]);
		self::assertSame(['concerts'], $changes['disabled']);
		self::assertSame([Modules::CONCERT_FOLDER, 'performance_import', 'repertoire', 'calendar', 'notify_concert_deadline', 'notify_concert_confirm'], $changes['inactive']);
		self::assertTrue($modules->isSwitchedOn('calendar'));
		self::assertFalse($modules->isEnabled('calendar'));

		$changes = $modules->change(['concerts' => true]);
		self::assertSame(['concerts'], $changes['enabled']);
		self::assertSame([Modules::CONCERT_FOLDER, 'performance_import', 'repertoire', 'calendar', 'notify_concert_deadline', 'notify_concert_confirm'], $changes['activated'],
			'switching on shows everything again');
	}

	public function testNothingChangesForASwitchThatIsAlreadyThere(): void {
		$modules = $this->modulesWithout();
		self::assertSame(['enabled' => [], 'alsoEnabled' => [], 'disabled' => [], 'inactive' => [], 'activated' => []],
			$modules->change(['concerts' => true]));
	}

	public function testARequestCannotNeedWhatItSwitchesOff(): void {
		$modules = $this->modulesWithout(['practice_audio']);
		try {
			$modules->change(['practice_audio' => true, 'score_viewer' => false]);
			self::fail('contradiction accepted');
		} catch (InvalidInputException $e) {
			self::assertSame('"%1$s" needs "%2$s".', $e->getMessage());
			self::assertSame(['Practice audio files', 'Score view'], $e->getParameters());
		}
		self::assertFalse($this->moduleConfig['module_practice_audio'], 'nothing was written');
		self::assertTrue($this->moduleConfig['module_score_viewer']);
	}

	public function testOnlyKnownSwitchableModulesCanBeChanged(): void {
		$modules = $this->modulesWithout();
		try {
			$modules->change(['nonsense' => true]);
			self::fail('nonsense accepted');
		} catch (InvalidInputException $e) {
			self::assertSame('Unknown module: %s', $e->getMessage());
		}
		self::assertSame([], $modules->change([Modules::AGENT_API => true])['enabled'], 'switchable, already on');
	}

	public function testPresets(): void {
		$simple = Modules::presetValues(Modules::SIMPLE);
		self::assertSame(['score_viewer', 'playback', 'notifications'], array_values(array_filter(
			array_keys(array_filter($simple)), fn (string $key): bool => !str_starts_with($key, Modules::NOTIFY_PREFIX))));
		self::assertNotContains(false, Modules::presetValues(Modules::COMPLETE));
		$standard = Modules::presetValues(Modules::STANDARD);
		self::assertSame(['print_station', 'practice_audio', 'proposals', 'folder_book', 'checksums', Modules::ANNUAL_SNAPSHOT, 'statistics'],
			array_keys(array_filter($standard, fn (bool $on): bool => !$on)));
		foreach (Modules::PRESETS as $preset) {
			self::assertArrayNotHasKey(Modules::AGENT_API, Modules::presetValues($preset), 'never part of a preset');
		}

		$modules = $this->modulesWithout();
		$changes = $modules->applyPreset(Modules::SIMPLE);
		self::assertContains('concerts', $changes['disabled']);
		self::assertNotContains('notify_task_new', $changes['disabled']);
		self::assertContains('notify_task_new', $changes['inactive'], 'switched on, but tasks are off');
		self::assertTrue($modules->isEnabled('playback'));
		self::assertTrue($modules->isEnabled('notify_export_failed'), 'a failed backup is still reported');
		self::assertFalse($modules->isEnabled('notify_task_new'));
		self::assertTrue($modules->isSwitchedOn('notify_task_new'));

		$changes = $modules->applyPreset(Modules::COMPLETE);
		self::assertSame([], $changes['disabled']);
		self::assertContains('concerts', $changes['enabled']);
		self::assertContains('notify_task_new', $changes['activated']);
		foreach (array_keys(Modules::presetValues(Modules::COMPLETE)) as $key) {
			self::assertTrue($modules->isEnabled($key), $key);
		}
		self::assertSame(array_values(array_diff(Modules::switchable(), Modules::OUTSIDE_PRESETS)),
			array_keys(Modules::presetValues(Modules::COMPLETE)), '"complete" is everything but the interface for agents and the sharing beyond the roles');
	}

	public function testOpeningFromTheFilesAppIsPartOfStandardAndComplete(): void {
		$module = Modules::MODULES[Modules::FILES_INTEGRATION];
		self::assertSame('scores', $module['group']);
		self::assertSame('Opening from the Files app', $module['label']);
		self::assertSame([], $module['requires'], 'without the score view it opens the copy');
		self::assertFalse(Modules::presetValues(Modules::SIMPLE)[Modules::FILES_INTEGRATION]);
		self::assertTrue(Modules::presetValues(Modules::STANDARD)[Modules::FILES_INTEGRATION]);
		self::assertTrue(Modules::presetValues(Modules::COMPLETE)[Modules::FILES_INTEGRATION]);
		self::assertContains(Modules::FILES_INTEGRATION, Modules::switchable());

		$modules = $this->modulesWithout(['score_viewer']);
		self::assertTrue($modules->isEnabled(Modules::FILES_INTEGRATION), 'independent of the score view');
		$modules->change([Modules::FILES_INTEGRATION => false]);
		self::assertFalse($modules->isEnabled(Modules::FILES_INTEGRATION));
	}

	public function testTheExtrasOfTheArchiveHaveTheirPresets(): void {
		$search = Modules::MODULES[Modules::UNIFIED_SEARCH];
		self::assertSame('overview', $search['group']);
		self::assertSame([], $search['requires']);
		self::assertFalse(Modules::presetValues(Modules::SIMPLE)[Modules::UNIFIED_SEARCH]);
		self::assertTrue(Modules::presetValues(Modules::STANDARD)[Modules::UNIFIED_SEARCH]);
		self::assertTrue(Modules::presetValues(Modules::COMPLETE)[Modules::UNIFIED_SEARCH]);

		$snapshot = Modules::MODULES[Modules::ANNUAL_SNAPSHOT];
		self::assertSame('maintenance', $snapshot['group']);
		self::assertSame([], $snapshot['requires']);
		self::assertFalse(Modules::presetValues(Modules::SIMPLE)[Modules::ANNUAL_SNAPSHOT]);
		self::assertFalse(Modules::presetValues(Modules::STANDARD)[Modules::ANNUAL_SNAPSHOT]);
		self::assertTrue(Modules::presetValues(Modules::COMPLETE)[Modules::ANNUAL_SNAPSHOT]);

		// An updated installation gets the default of the new modules.
		$modules = $this->modules(['statistics' => true]);
		$modules->writeBaseline();
		self::assertTrue($modules->isEnabled(Modules::UNIFIED_SEARCH));
		self::assertFalse($modules->isEnabled(Modules::ANNUAL_SNAPSHOT));
	}

	public function testUnknownPreset(): void {
		$this->expectException(InvalidInputException::class);
		Modules::presetValues('everything');
	}

	public function testANewInstallationGetsTheStandard(): void {
		$modules = $this->modules();
		$written = $modules->writeBaseline();
		self::assertSame(array_keys(Modules::MODULES), $written);
		foreach (Modules::presetValues(Modules::STANDARD) as $key => $on) {
			self::assertSame($on, $this->moduleConfig['module_' . $key], $key);
		}
		self::assertFalse($this->moduleConfig['module_agent_api']);
	}

	public function testTheFolderBookIsOnlyInCompleteAndTheMirrorFolderInNoPreset(): void {
		self::assertSame('maintenance', Modules::MODULES[Modules::FOLDER_BOOK]['group']);
		self::assertSame([], Modules::requirements(Modules::FOLDER_BOOK));
		self::assertFalse(Modules::presetValues(Modules::SIMPLE)[Modules::FOLDER_BOOK]);
		self::assertFalse(Modules::presetValues(Modules::STANDARD)[Modules::FOLDER_BOOK]);
		self::assertTrue(Modules::presetValues(Modules::COMPLETE)[Modules::FOLDER_BOOK]);

		self::assertSame('releases', Modules::MODULES[Modules::PRINT_MIRROR]['group']);
		self::assertSame(['print_station', 'releases', 'rights'], Modules::requirements(Modules::PRINT_MIRROR));
		self::assertSame(['notifications', 'print_mirror', 'print_station', 'releases', 'rights'], Modules::requirements('notify_print_mirror_failed'));
		foreach (Modules::PRESETS as $preset) {
			self::assertArrayNotHasKey(Modules::PRINT_MIRROR, Modules::presetValues($preset), 'writes files: never part of a preset');
		}

		$modules = $this->modulesWithout([Modules::PRINT_MIRROR]);
		$modules->applyPreset(Modules::COMPLETE);
		self::assertFalse($modules->isEnabled(Modules::PRINT_MIRROR), '"complete" does not switch it on');
		self::assertFalse($modules->isEnabled('notify_print_mirror_failed'));
		$changes = $modules->change([Modules::PRINT_MIRROR => true]);
		self::assertSame([Modules::PRINT_MIRROR], $changes['enabled']);
		self::assertSame(['notify_print_mirror_failed'], $changes['activated']);
		$modules->change(['print_station' => false]);
		self::assertFalse($modules->isEnabled(Modules::PRINT_MIRROR), 'needs the list of the print station');
		self::assertTrue($modules->isSwitchedOn(Modules::PRINT_MIRROR));
	}

	public function testStoredSwitchesAreNeverOverwritten(): void {
		$modules = $this->modules(['statistics' => true, 'concerts' => false]);
		$written = $modules->writeBaseline();
		self::assertNotContains('statistics', $written);
		self::assertNotContains('concerts', $written);
		self::assertTrue($this->moduleConfig['module_statistics']);
		self::assertFalse($this->moduleConfig['module_concerts']);
		self::assertSame([], $modules->writeBaseline(), 'a second run writes nothing');
	}

	public function testWithoutStoredSwitchesTheStandardApplies(): void {
		$modules = $this->modules();
		self::assertTrue($modules->isEnabled('concerts'));
		self::assertFalse($modules->isEnabled('statistics'));
		self::assertFalse($modules->isEnabled('practice_audio'));
	}
}
