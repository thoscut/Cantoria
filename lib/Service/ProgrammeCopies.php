<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\EventItem;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Which copy stands for an item of a programme: for the concert folder and
 * for sharing a programme with the choir (milestone 0.19).
 */
class ProgrammeCopies {
	public function __construct(
		private CopyMapper $copies,
		private ArchiveFileMapper $files,
	) {
	}

	/**
	 * The copy of an item: the one of the programme, otherwise the first copy
	 * of its version that has a score.
	 */
	public function copyOf(EventItem $item): ?Copy {
		$copyId = $item->getCopyId();
		try {
			if ($copyId !== null) {
				return $this->copies->find($copyId);
			}
		} catch (DoesNotExistException) {
			return null;
		}
		$versionId = $item->getVersionId();
		if ($versionId === null) {
			return null;
		}
		$copies = $this->copies->findByVersion($versionId);
		foreach ($copies as $copy) {
			if (ScoreService::pick($this->files->findByCopy($copy->getId())) !== null) {
				return $copy;
			}
		}
		return $copies[0] ?? null;
	}
}
