<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\BackgroundJob;

use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\ScoreFactsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Hourly: reads the facts of the next MusicXML files whose facts are
 * missing or older than the file (ScoreFactsService::run). Does nothing
 * while the module "score_facts" is off.
 */
class ScoreFactsJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ScoreFactsService $facts,
		private Modules $modules,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		if (!$this->modules->isEnabled(Modules::SCORE_FACTS)) {
			return;
		}
		$this->facts->run();
	}
}
