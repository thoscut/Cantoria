<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Command;

use OCA\Cantoria\Command\ModulesCommand;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\ModuleSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ cantoria:modules` (T32): the list as table and JSON, what each
 * action hands to the module settings, the report of the changes and the
 * errors for a wrong call. The settings themselves are tested in
 * ModuleSettingsTest.
 */
#[CoversClass(ModulesCommand::class)]
#[UsesClass(Modules::class)]
final class ModulesCommandTest extends TestCase {
	/** @var list<array{0: string, 1: mixed}> calls of the module settings */
	private array $calls = [];

	private const NONE = ['enabled' => [], 'alsoEnabled' => [], 'disabled' => [], 'inactive' => [], 'activated' => []];

	/**
	 * @param array{enabled: list<string>, alsoEnabled: list<string>, disabled: list<string>, inactive: list<string>, activated: list<string>} $changes
	 */
	private function tester(array $changes = self::NONE, ?string $fails = null): CommandTester {
		$modules = $this->createStub(Modules::class);
		$modules->method('states')->willReturn(['excerpt_links' => true] + array_fill_keys(array_keys(Modules::MODULES), false));
		$modules->method('isSwitchedOn')->willReturnCallback(fn (string $key): bool => $key === 'excerpt_links');
		$settings = $this->createStub(ModuleSettings::class);
		foreach (['change', 'applyPreset', 'applyScenario'] as $method) {
			$settings->method($method)->willReturnCallback(function (mixed $value) use ($method, $changes, $fails): array {
				if ($fails !== null) {
					throw new InvalidInputException('Unknown module: %s', [$fails]);
				}
				$this->calls[] = [$method, $value];
				return $changes;
			});
		}
		return new CommandTester(new ModulesCommand($modules, $settings));
	}

	public function testListsEveryModuleAsTableAndAsJson(): void {
		$tester = $this->tester();
		self::assertSame(0, $tester->execute(['action' => 'list']));
		$table = $tester->getDisplay();
		foreach (array_keys(Modules::MODULES) as $key) {
			self::assertStringContainsString($key, $table);
		}
		self::assertMatchesRegularExpression('/excerpt_links\s*\|\s*on\s*\|\s*yes/', $table);

		self::assertSame(0, $tester->execute(['action' => 'list', '--json' => true]));
		$rows = json_decode($tester->getDisplay(), true, 8, JSON_THROW_ON_ERROR);
		self::assertIsArray($rows);
		self::assertSame(array_keys(Modules::MODULES), array_keys($rows));
		self::assertSame(['enabled' => true, 'active' => true], array_intersect_key($rows['excerpt_links'], ['enabled' => 0, 'active' => 0]));
	}

	public function testEnableAndDisableHandTheModulesToTheSettingsAndReportTheChanges(): void {
		$tester = $this->tester(['enabled' => ['member_links'], 'alsoEnabled' => ['excerpt_links'], 'disabled' => [], 'inactive' => [], 'activated' => []]);
		self::assertSame(0, $tester->execute(['action' => 'enable', 'names' => ['member_links']]));
		self::assertSame([['change', ['member_links' => true]]], $this->calls);
		self::assertStringContainsString('Switched on: member_links', $tester->getDisplay());
		self::assertStringContainsString('Also switched on (needed): excerpt_links', $tester->getDisplay());

		$this->calls = [];
		$tester = $this->tester();
		self::assertSame(0, $tester->execute(['action' => 'disable', 'names' => ['public_links', 'offline']]));
		self::assertSame([['change', ['public_links' => false, 'offline' => false]]], $this->calls);
		self::assertStringContainsString('Nothing changed.', $tester->getDisplay());
	}

	public function testPresetAndScenarioTakeExactlyOneName(): void {
		$tester = $this->tester();
		self::assertSame(0, $tester->execute(['action' => 'preset', 'names' => ['simple']]));
		self::assertSame(0, $tester->execute(['action' => 'scenario', 'names' => ['singers']]));
		self::assertSame([['applyPreset', 'simple'], ['applyScenario', 'singers']], $this->calls);

		self::assertSame(1, $tester->execute(['action' => 'preset', 'names' => []]));
		self::assertStringContainsString('Please name one preset: ' . implode(', ', Modules::PRESETS), $tester->getDisplay());
		self::assertSame(1, $tester->execute(['action' => 'scenario', 'names' => ['singers', 'public']]));
		self::assertCount(2, $this->calls, 'nothing applied');
	}

	public function testAWrongCallChangesNothingAndSaysWhy(): void {
		$tester = $this->tester();
		self::assertSame(1, $tester->execute(['action' => 'switch']));
		self::assertStringContainsString('Unknown action: switch (list, enable, disable, preset, scenario)', $tester->getDisplay());
		self::assertSame(1, $tester->execute(['action' => 'enable']));
		self::assertStringContainsString('Please name at least one module.', $tester->getDisplay());
		self::assertSame([], $this->calls);

		$failing = $this->tester(fails: 'karaoke');
		self::assertSame(1, $failing->execute(['action' => 'enable', 'names' => ['karaoke']]));
		self::assertStringContainsString('Unknown module: karaoke', $failing->getDisplay());
	}
}
