<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Command;

use OCA\Cantoria\Service\FullBackupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Restores a complete JSON backup into an empty catalog. The file is read
 * from the local file system of the server, so it also works when the archive
 * folder itself is lost.
 */
class Restore extends Command {
	public function __construct(
		private FullBackupService $backup,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('cantoria:restore')
			->setDescription('Restore a complete backup (JSON) into an empty catalog')
			->addArgument('file', InputArgument::REQUIRED, 'path of the backup file on the server')
			->addArgument('user', InputArgument::OPTIONAL, 'account recorded in the audit log');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$path = (string)$input->getArgument('file');
		// as a stream: also a backup larger than the memory of PHP
		$stream = self::open($path);
		if ($stream === null) {
			$output->writeln('<error>Cannot read ' . $path . '</error>');
			return 1;
		}
		try {
			$restored = $this->backup->restoreStream($stream, (string)($input->getArgument('user') ?? 'occ'));
		} finally {
			fclose($stream);
		}
		foreach ($restored as $table => $rows) {
			$output->writeln(sprintf('%-28s %6d', $table, $rows));
		}
		return 0;
	}

	/**
	 * The backup as a stream that can be rewound (it is read twice). A
	 * regular file is opened as it is; a pipe (`/dev/stdin`, a process
	 * substitution of the shell) is copied into a temporary stream first,
	 * which moves to disk when it grows.
	 *
	 * @return resource|null null when the path cannot be read
	 */
	public static function open(string $path) {
		if (is_dir($path) || !is_readable($path)) {
			return null;
		}
		$source = @fopen($path, 'rb');
		if ($source === false) {
			return null;
		}
		if (is_file($path)) {
			return $source;
		}
		$copy = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
		$copied = $copy === false ? false : stream_copy_to_stream($source, $copy);
		fclose($source);
		if ($copy === false || $copied === false) {
			return null;
		}
		rewind($copy);
		return $copy;
	}
}
