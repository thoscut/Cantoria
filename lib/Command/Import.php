<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Command;

use OCA\Cantoria\Service\ImportService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Import extends Command {
	public function __construct(
		private ImportService $import,
		private IRootFolder $rootFolder,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('cantoria:import')
			->setDescription('Import the catalog from CSV, .xlsx or .ods; a dry run unless --execute is given')
			->addArgument('user', InputArgument::REQUIRED, 'account that owns the file and is recorded in the audit log')
			->addArgument('file', InputArgument::REQUIRED, 'path of the file (CSV, .xlsx or .ods), relative to the account\'s files')
			->addOption('execute', null, InputOption::VALUE_NONE, 'really import instead of a dry run');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = (string)$input->getArgument('user');
		$file = $this->rootFolder->getUserFolder($uid)->get((string)$input->getArgument('file'));
		if (!$file instanceof File) {
			$output->writeln('<error>Not a file</error>');
			return 1;
		}
		$report = $this->import->import($file->getContent(), $file->getName(), $uid, !$input->getOption('execute'));
		$report['warnings'] = array_map(fn (array $w): string => vsprintf($w[0], $w[1]), $report['warnings']);
		$output->writeln((string)json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		return 0;
	}
}
