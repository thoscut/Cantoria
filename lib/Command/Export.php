<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Command;

use OCA\Cantoria\Service\ExportService;
use OCA\Cantoria\Service\FullBackupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Export extends Command {
	public function __construct(
		private ExportService $export,
		private FullBackupService $fullBackup,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('cantoria:export')
			->setDescription('Write the catalog as CSV (or the complete backup as JSON) to standard output')
			->addOption('extended', null, InputOption::VALUE_NONE, 'all fields instead of only the legacy catalog columns')
			->addOption('full', null, InputOption::VALUE_NONE, 'complete backup of all tables as JSON (for cantoria:restore)');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('full')) {
			// block by block: the backup is never held in memory as a whole
			$stream = $this->fullBackup->exportStream();
			try {
				while (!feof($stream) && ($block = fread($stream, 1024 * 1024)) !== false && $block !== '') {
					$output->write($block, false, OutputInterface::OUTPUT_RAW);
				}
			} finally {
				fclose($stream);
			}
			return 0;
		}
		$output->write($this->export->csv((bool)$input->getOption('extended')), false, OutputInterface::OUTPUT_RAW);
		return 0;
	}
}
