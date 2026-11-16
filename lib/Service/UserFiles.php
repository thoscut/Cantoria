<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\UserFacingException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\Lock\LockedException;

/**
 * Reads files chosen by a user through the user's own view of Nextcloud and
 * refuses files above a size limit, without ever loading more than the limit
 * into memory.
 */
class UserFiles {
	/** Largest file accepted for an import (catalog, persons, performances) */
	public const MAX_IMPORT_BYTES = 20 * 1024 * 1024;

	public function __construct(
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * A file the user chose for an import or a restore.
	 *
	 * @param UserFacingException $notAccessible thrown when the file is missing, no file or cannot be read
	 * @return array{content: string, name: string}
	 * @throws UserFacingException
	 */
	public function read(string $uid, int $fileId, int $maxBytes, UserFacingException $notAccessible): array {
		$file = $fileId > 0 ? $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId) : null;
		if (!$file instanceof File) {
			throw $notAccessible;
		}
		return ['content' => self::content($file, $maxBytes, $notAccessible), 'name' => $file->getName()];
	}

	/**
	 * A file the user chose, as a stream that can be rewound: copied block
	 * by block into a temporary stream that moves to disk when it grows, so
	 * that a large file (a complete backup) is never held in memory and a
	 * storage that cannot seek does not matter. The caller closes it.
	 *
	 * @param UserFacingException $notAccessible thrown when the file is missing, no file or cannot be read
	 * @return resource at its start
	 * @throws UserFacingException
	 */
	public function stream(string $uid, int $fileId, int $maxBytes, UserFacingException $notAccessible) {
		$file = $fileId > 0 ? $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId) : null;
		if (!$file instanceof File) {
			throw $notAccessible;
		}
		if ($file->getSize() > $maxBytes) {
			throw self::tooLarge($maxBytes);
		}
		try {
			$handle = $file->fopen('r');
		} catch (NotPermittedException|LockedException) {
			$handle = false;
		}
		$copy = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
		if ($handle === false || $copy === false) {
			throw $notAccessible;
		}
		try {
			// One byte more than allowed: the size in the file cache may lag behind the content.
			$copied = stream_copy_to_stream($handle, $copy, $maxBytes + 1);
		} finally {
			fclose($handle);
		}
		if ($copied === false || $copied > $maxBytes) {
			fclose($copy);
			throw $copied === false ? $notAccessible : self::tooLarge($maxBytes);
		}
		rewind($copy);
		return $copy;
	}

	/**
	 * Content of a file, at most $maxBytes long.
	 *
	 * @param UserFacingException $notAccessible thrown when the file cannot be read
	 * @throws UserFacingException
	 */
	public static function content(File $file, int $maxBytes, UserFacingException $notAccessible): string {
		if ($file->getSize() > $maxBytes) {
			throw self::tooLarge($maxBytes);
		}
		try {
			$handle = $file->fopen('r');
		} catch (NotPermittedException|LockedException) {
			$handle = false;
		}
		if ($handle === false) {
			throw $notAccessible;
		}
		try {
			// In blocks instead of reserving $maxBytes at once. One byte more
			// than allowed: the size in the file cache may lag behind the
			// content.
			$content = '';
			while (strlen($content) <= $maxBytes && !feof($handle)) {
				$block = fread($handle, min(1024 * 1024, $maxBytes + 1 - strlen($content)));
				if ($block === false) {
					throw $notAccessible;
				}
				if ($block === '') {
					break;
				}
				$content .= $block;
			}
		} finally {
			fclose($handle);
		}
		if (strlen($content) > $maxBytes) {
			throw self::tooLarge($maxBytes);
		}
		return $content;
	}

	public static function tooLarge(int $maxBytes): InvalidInputException {
		return new InvalidInputException('The file is too large (at most %s MB).', [intdiv($maxBytes, 1024 * 1024)]);
	}
}
