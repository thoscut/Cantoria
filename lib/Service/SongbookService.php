<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\ArchiveFile;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\IRootFolder;

/**
 * A songbook from a selection: the scans (PDF) of several pieces put
 * together in the browser, with a cover, the contents and page numbers,
 * printed for the choir. The selection is a programme or song list (in its
 * order, without struck pieces) or a list of copies (e.g. the catalog as
 * filtered). The server says per piece whether it may go in
 * (RightsPolicy::songbookDenial) and hands out only the scans of those; the
 * browser builds the PDF (src/songbook). Making a songbook is recorded in
 * the audit log with the pieces in it.
 *
 * @psalm-type SongbookPiece = array{copyId: ?int, shelfmark: ?string, title: string, composer: ?string, arranger: ?string,
 *     note: ?string, scanFileId: ?int, allowed: bool, reason: ?string}
 */
class SongbookService {
	/** Who makes songbooks: the catalog librarian and the conductor */
	public const MAKERS = [Roles::LIBRARIAN, Roles::CONDUCTOR];
	/** At most this many pieces in one songbook */
	public const MAX_PIECES = 200;

	public function __construct(
		private EventMapper $events,
		private EventItemMapper $items,
		private ProgrammeCopies $programmeCopies,
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private WorkMapper $works,
		private ArchiveFileMapper $files,
		private RightsPolicy $policy,
		private RightsService $rights,
		private AuditLog $audit,
		private Roles $roles,
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * The pieces of a programme or song list, in its order.
	 *
	 * @return array{title: string, date: ?string, pieces: list<SongbookPiece>}
	 */
	public function forEvent(int $eventId, string $uid): array {
		$this->roles->requireRole($uid, self::MAKERS);
		try {
			$event = $this->events->find($eventId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		$today = $this->rights->today($uid);
		$pieces = [];
		foreach ($this->items->findByEvent($eventId) as $item) {
			if ($item->getStruck() === true) {
				continue;
			}
			$copy = $this->programmeCopies->copyOf($item);
			$piece = $this->describe($copy, $today, $uid);
			if ($copy === null) {
				$piece['title'] = (string)$item->getTitleText();
			}
			$piece['note'] = $item->getEncore() === true ? 'Encore' : null;
			$pieces[] = $piece;
			if (count($pieces) >= self::MAX_PIECES) {
				break;
			}
		}
		return ['title' => (string)$event->getTitle(), 'date' => $event->getEventDate(), 'pieces' => $pieces];
	}

	/**
	 * The pieces of a list of copies, in the order given; a copy named twice
	 * comes once.
	 *
	 * @param array<array-key, mixed> $copyIds (request data)
	 * @return array{title: string, date: ?string, pieces: list<SongbookPiece>}
	 */
	public function forCopies(array $copyIds, string $uid): array {
		$this->roles->requireRole($uid, self::MAKERS);
		$ids = [];
		foreach (array_map(self::copyId(...), $copyIds) as $id) {
			if ($id === null) {
				throw new InvalidInputException('Please choose the copies for the songbook.');
			}
			$ids[$id] = true;
		}
		if ($ids === []) {
			throw new InvalidInputException('Please choose the copies for the songbook.');
		}
		if (count($ids) > self::MAX_PIECES) {
			throw new InvalidInputException('A songbook holds at most %s pieces. Please narrow the list.', [(string)self::MAX_PIECES]);
		}
		$today = $this->rights->today($uid);
		$pieces = [];
		foreach (array_keys($ids) as $id) {
			try {
				$copy = $this->copies->find($id);
			} catch (DoesNotExistException) {
				continue;
			}
			$pieces[] = $this->describe($copy, $today, $uid);
		}
		return ['title' => '', 'date' => null, 'pieces' => $pieces];
	}

	/**
	 * The scan of a piece for the songbook, after the same checks as the
	 * list: role, rights for a songbook, the account's own view of the file.
	 *
	 * @throws AccessDeniedException|InvalidInputException|NotFoundException
	 */
	public function scan(int $archiveFileId, string $uid): File {
		$this->roles->requireRole($uid, self::MAKERS);
		try {
			$file = $this->files->find($archiveFileId);
			$copy = $this->copies->find($file->getCopyId() ?? 0);
			$version = $this->versions->find($copy->getVersionId());
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		if ($file->getRole() !== 'score_pdf') {
			throw new InvalidInputException('This file is not a PDF scan.');
		}
		$denial = $this->viewOrSongbookDenial($version, $copy, $uid);
		if ($denial !== null) {
			throw new AccessDeniedException($denial);
		}
		$node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($file->getFileId());
		if (!$node instanceof File) {
			throw new AccessDeniedException('The file is not accessible for this account.');
		}
		if (!ScanService::isPdf($node)) {
			throw new InvalidInputException('This file is not a PDF scan.');
		}
		return $node;
	}

	/**
	 * Records that a songbook was made, with the shelf marks in it.
	 *
	 * @param array<array-key, mixed> $copyIds the copies in the songbook (request data)
	 */
	public function record(array $copyIds, ?int $eventId, string $title, string $uid): void {
		$this->roles->requireRole($uid, self::MAKERS);
		$marks = [];
		foreach (array_map(self::copyId(...), array_slice($copyIds, 0, self::MAX_PIECES)) as $id) {
			if ($id === null) {
				continue;
			}
			try {
				$marks[] = $this->copies->find($id)->getShelfmark();
			} catch (DoesNotExistException) {
			}
		}
		if ($marks === []) {
			throw new InvalidInputException('Please choose the copies for the songbook.');
		}
		$this->audit->record($this->audit->newOperation(), $uid, $eventId === null ? 'songbook' : 'event', $eventId, 'songbook_made',
			null, null, implode(', ', $marks), mb_substr(trim($title), 0, 200) ?: null);
	}

	/**
	 * @return SongbookPiece
	 */
	private function describe(?Copy $copy, string $today, string $uid): array {
		$piece = ['copyId' => $copy?->getId(), 'shelfmark' => $copy?->getShelfmark(), 'title' => '', 'composer' => null, 'arranger' => null,
			'note' => null, 'scanFileId' => null, 'allowed' => false, 'reason' => null];
		if ($copy === null) {
			$piece['reason'] = 'No copy of this piece is in the catalog.';
			return $piece;
		}
		try {
			$version = $this->versions->find($copy->getVersionId());
			$work = $this->works->find($version->getWorkId());
		} catch (DoesNotExistException) {
			$piece['reason'] = 'No copy of this piece is in the catalog.';
			return $piece;
		}
		if (RightsPolicy::isPreservationCopy($version, $copy) && !$this->roles->hasAnyRole($uid, RightsPolicy::PRESERVATION_COPY_VIEWERS)) {
			// neither shelf mark nor title of a copy the account may not view
			return ['copyId' => null, 'shelfmark' => null, 'reason' => ProgrammeShareService::NOT_AVAILABLE] + $piece;
		}
		$piece['title'] = (string)$work->getTitle();
		$piece['composer'] = $work->getComposer();
		$piece['arranger'] = $version->getArrangerText();
		$scan = self::firstScan($this->files->findByCopy($copy->getId()));
		$piece['scanFileId'] = $scan?->getId();
		$denial = $this->viewOrSongbookDenial($version, $copy, $uid);
		if ($denial === null && $scan === null) {
			$denial = 'No scan (PDF) is linked to this copy: a songbook is made of scans.';
		}
		$piece['allowed'] = $denial === null;
		$piece['reason'] = $denial;
		return $piece;
	}

	private function viewOrSongbookDenial(WorkVersion $version, Copy $copy, string $uid): ?string {
		return $this->policy->viewDenial($version, $uid, $copy)
			?? $this->policy->songbookDenial($version, $this->rights->today($uid), $copy);
	}

	/** A copy id from the request, or null */
	private static function copyId(mixed $value): ?int {
		$id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		return $id === false ? null : $id;
	}

	/**
	 * The first scan of a copy that was found in the archive folder.
	 *
	 * @param list<ArchiveFile> $files
	 */
	public static function firstScan(array $files): ?ArchiveFile {
		foreach ($files as $file) {
			if ($file->getRole() === 'score_pdf' && $file->getFound() !== false) {
				return $file;
			}
		}
		return null;
	}
}
