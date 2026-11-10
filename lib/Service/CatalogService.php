<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\ArchiveFile;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\AuditEntry;
use OCA\Cantoria\Db\AuditEntryMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\License;
use OCA\Cantoria\Db\LicenseMapper;
use OCA\Cantoria\Db\Proposal;
use OCA\Cantoria\Db\ProposalMapper;
use OCA\Cantoria\Db\ReviewPointMapper;
use OCA\Cantoria\Db\Task;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Reading and maintaining the catalog. Every change is checked against the
 * write roles of each field and recorded in the audit log.
 *
 * @psalm-import-type FieldInfo from Fields
 * @psalm-import-type LinkView from LinkService
 * @psalm-import-type Proposal from ProtectionTerm as ProtectionProposal
 * @psalm-type Entry = array{work: Work, version: WorkVersion, copy: Copy, files: list<ArchiveFile>,
 *     score: ?array{fileId: int, fileName: string, canView: bool, canPlay: bool, reason: ?string, canExportAudio: bool, exportReason: ?string},
 *     licenses: list<License>, licenseWarnings: list<string>,
 *     releases: array{portal: ?string, onPortal: bool, printStation: ?string}, tasks: list<Task>, links: list<LinkView>,
 *     proposals: list<Proposal>, siblings: list<array{id: int, shelfmark: string, version_id: int, status: string}>,
 *     contributions: list<array<string, string|int|bool|null|array<string, string|int|bool|null>>>, protection: ?ProtectionProposal,
 *     merges: list<array<string, mixed>>, audit: list<AuditEntry>,
 *     revision: ?array{count: int, latest: ?int, current: ?array{id: int, number: int, valid_since: string}, shown: ?int}}
 */
class CatalogService {
	public function __construct(
		private IDBConnection $db,
		private WorkMapper $works,
		private WorkVersionMapper $versions,
		private CopyMapper $copies,
		private ArchiveFileMapper $files,
		private AuditEntryMapper $auditEntries,
		private AuditLog $audit,
		private Roles $roles,
		private ITimeFactory $time,
		private IDateTimeZone $timeZone,
		private ScoreService $scores,
		private RightsService $rights,
		private LicenseMapper $licenses,
		private ProposalMapper $proposals,
		private IUserManager $userManager,
		private TaskService $taskService,
		private LinkService $linkService,
		private RepertoireService $repertoire,
		private PersonService $persons,
		private MergeService $merges,
		private Modules $modules,
		private ReviewPointMapper $reviewPoints,
		private StateService $states,
	) {
	}

	/**
	 * The work a version belongs to after merges: follows `merged_into` to
	 * the work that is listed (a merged work has no versions of its own, so
	 * this only matters for data that changed outside the app).
	 *
	 * @param array<int, Work> $works keyed by id
	 */
	public static function listedWork(?Work $work, array $works): ?Work {
		for ($hops = 0; $work !== null && $hops < 20; $hops++) {
			$mergedInto = $work->getMergedInto();
			if ($mergedInto === null) {
				break;
			}
			$work = $works[$mergedInto] ?? $work;
		}
		return $work;
	}

	/** Imported repertoire values of the legacy catalog => class */
	private const LEGACY_REPERTOIRE = ['aktuell' => 'current', 'ruhend' => 'dormant', 'früher' => 'earlier', 'nicht belegt' => 'none'];

	/**
	 * Repertoire class, performance count and last date: derived from
	 * documented performances when there are any, otherwise the imported
	 * values mapped to the same classes.
	 *
	 * @param array<int, array{performances: int, first: ?string, last: ?string, class: string, songLists: list<string>}> $derived
	 * @return array{repertoire_class: ?string, repertoire_source: string, performances: ?int, last_performed_on: ?string, song_lists: list<string>}
	 */
	public static function repertoireFigures(?Work $work, array $derived): array {
		$figures = $work === null ? null : ($derived[$work->getId()] ?? null);
		if ($figures !== null && $figures['performances'] > 0) {
			return ['repertoire_class' => $figures['class'], 'repertoire_source' => 'performances',
				'performances' => $figures['performances'], 'last_performed_on' => $figures['last'], 'song_lists' => $figures['songLists']];
		}
		$imported = $work?->getRepertoireImport();
		$count = $work?->getPerformancesImport();
		return ['repertoire_class' => $imported === null ? null : (self::LEGACY_REPERTOIRE[$imported] ?? null), 'repertoire_source' => 'import',
			'performances' => is_numeric($count) ? (int)$count : null, 'last_performed_on' => $work?->getLastPerformedImport(),
			'song_lists' => $figures['songLists'] ?? []];
	}

	/**
	 * Whether a copy has MusicXML: a linked .mxl or .musicxml file, or a
	 * valid MusicXML noted in the catalog. Same rule in the list and in the
	 * statistics.
	 *
	 * @param array<string, int> $fileCounts file type => number of found files of the copy
	 */
	public static function hasMusicxml(array $fileCounts, ?WorkVersion $version): bool {
		return ($fileCounts['mxl'] ?? 0) + ($fileCounts['musicxml'] ?? 0) > 0 || $version?->getMusicxmlValid() !== null;
	}

	/**
	 * Throws when the entity does not exist.
	 */
	public function entityExists(string $level, int $id): void {
		$this->load($level, $id);
	}

	/**
	 * A copy with its version and the work it is listed under (after
	 * merges), without anything else of the entry.
	 *
	 * @return array{copy: Copy, version: WorkVersion, work: Work}
	 * @throws NotFoundException
	 */
	public function copyWithContext(int $copyId): array {
		$copy = $this->load(Fields::COPY, $copyId);
		assert($copy instanceof Copy);
		$version = $this->versions->find($copy->getVersionId());
		$work = $this->works->find($version->getWorkId());
		if ($work->getMergedInto() !== null) {
			$work = self::listedWork($work, $this->works->findAllById()) ?? $work;
		}
		return ['copy' => $copy, 'version' => $version, 'work' => $work];
	}

	/**
	 * One row per copy with the data needed for list and filters.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function entries(): array {
		$works = $this->works->findAllById();
		$versions = $this->versions->findAllById();
		$fileCounts = $this->files->countPerCopy();
		$derived = $this->repertoire->perWork();
		// Review state of the transcription (milestone 0.6); null while the review is switched off
		$review = $this->modules->isEnabled(ReviewService::MODULE) ? $this->reviewPoints->countsPerCopy() : null;

		$copies = $this->copies->findAll();
		$copiesPerVersion = [];
		foreach ($copies as $copy) {
			$copiesPerVersion[$copy->getVersionId()][] = $copy;
		}
		// Photocopies without a license (milestone 0.11): only a warning, one value per version
		$licenses = $this->licenses->findAllByVersion();
		$day = $this->rights->today(null);
		$unlicensed = [];
		$rows = [];
		foreach ($copies as $copy) {
			$version = $versions[$copy->getVersionId()] ?? null;
			if ($version !== null && !isset($unlicensed[$version->getId()])) {
				$unlicensed[$version->getId()] = LicenseCoverage::copies($version, $copiesPerVersion[$version->getId()] ?? [],
					$licenses[$version->getId()] ?? [], $day) !== [];
			}
			$work = self::listedWork($version === null ? null : ($works[$version->getWorkId()] ?? null), $works);
			$counts = $fileCounts[$copy->getId()] ?? [];
			$rows[] = [
				'id' => $copy->getId(),
				'work_id' => $work?->getId(),
				'version_id' => $version?->getId(),
				'shelfmark' => $copy->getShelfmark(),
				'binder' => $copy->getBinder(),
				'number' => $copy->getNumber(),
				'status' => $copy->getStatus(),
				'title' => $work?->getTitle(),
				'composer' => $work?->getComposer(),
				'work_no' => $work?->getWorkNo(),
				'arranger_text' => $version?->getValue('arranger_text'),
				'rights_status' => $version?->getRightsStatus(),
				'rights_confirmed' => $version?->getValue('rights_confirmed'),
				'confirmed_at' => $version?->getValue('confirmed_at'),
				'preservation_copy' => $version !== null && RightsPolicy::isPreservationCopy($version, $copy),
				'copies_unlicensed' => $version !== null && $unlicensed[$version->getId()],
				'publication' => $version?->getPublication(),
				'valid_since' => $version?->getValue('valid_since'),
				// for finding repertoire: scoring, language and duration (seconds) of the version
				'scoring' => $version?->getValue('scoring'),
				'language' => $version?->getValue('language'),
				'duration' => $version?->getValue('duration'),
				'repertoire' => $work?->getValue('repertoire_import'),
				'last_performed' => $work?->getValue('last_performed_import'),
				...self::repertoireFigures($work, $derived),
				'has_musicxml' => self::hasMusicxml($counts, $version),
				'files' => array_sum($counts),
				'pdfs' => $counts['pdf'] ?? 0,
				'review_open' => $review === null ? null : ($review[$copy->getId()]['open'] ?? 0),
				'review_orders' => $review === null ? null : ($review[$copy->getId()]['orders'] ?? 0),
			];
		}
		return $rows;
	}

	/**
	 * Whether the copies of a version and the files of this copy are covered
	 * by its licenses (LicenseCoverage); English source strings.
	 *
	 * @param list<ArchiveFile> $files
	 * @param list<License> $licenses
	 * @return list<string>
	 */
	private function licenseWarnings(WorkVersion $version, array $files, array $licenses, string $day): array {
		$counts = [];
		foreach ($files as $file) {
			if ($file->getFound() !== false) {
				$type = (string)$file->getType();
				$counts[$type] = ($counts[$type] ?? 0) + 1;
			}
		}
		$warnings = LicenseCoverage::copies($version, $this->copies->findByVersion($version->getId()), $licenses, $day);
		$digitised = LicenseCoverage::digitisation($version, $counts, $licenses, $day);
		return $digitised === null ? $warnings : [...$warnings, $digitised];
	}

	/**
	 * The rows of entries() an account may see: internal preservation copies
	 * only for the roles of RightsPolicy::PRESERVATION_COPY_VIEWERS, as in
	 * the API for agents.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function entriesFor(string $uid): array {
		$rows = $this->entries();
		if ($this->roles->hasAnyRole($uid, RightsPolicy::PRESERVATION_COPY_VIEWERS)) {
			return $rows;
		}
		return array_values(array_filter($rows, fn (array $row): bool => $row['preservation_copy'] !== true));
	}

	/**
	 * Everything about one copy: work, version, files, other copies of the same
	 * work, persons involved with the protection proposal (display only),
	 * merges that can be undone, audit log. Parts of switched-off modules are
	 * empty (lists) or null, without being read.
	 *
	 * @return Entry
	 */
	public function entry(int $copyId, string $uid): array {
		['copy' => $copy, 'version' => $version, 'work' => $work] = $this->copyWithContext($copyId);
		if (RightsPolicy::isPreservationCopy($version, $copy) && !$this->roles->hasAnyRole($uid, RightsPolicy::PRESERVATION_COPY_VIEWERS)) {
			throw new AccessDeniedException('Internal preservation copy: only the catalog librarian and the conductor may view it.');
		}

		$siblings = [];
		foreach ($this->versions->findByWork($work->getId()) as $otherVersion) {
			foreach ($this->copies->findByVersion($otherVersion->getId()) as $other) {
				if ($other->getId() !== $copy->getId()) {
					$siblings[] = ['id' => $other->getId(), 'shelfmark' => $other->getShelfmark(),
						'version_id' => $otherVersion->getId(), 'status' => $other->getStatus()];
				}
			}
		}

		$files = $this->files->findByCopy($copy->getId());
		$licenses = $this->licenses->findByVersion($version->getId());
		$day = $this->rights->today($uid);
		$active = $this->modules->states();
		$score = $active['score_viewer'] ? $this->scores->describe($version, $files, $uid, $copy) : null;
		return [
			'work' => $work,
			'version' => $version,
			'copy' => $copy,
			'files' => $files,
			'score' => $score,
			// Licenses still decide about releases and playback when their maintenance is switched off.
			'licenses' => $active['rights'] ? $licenses : [],
			'licenseWarnings' => $active['rights'] ? $this->licenseWarnings($version, $files, $licenses, $day) : [],
			'releases' => [
				'portal' => $this->rights->portalDenial($version, $licenses, $day),
				'onPortal' => $this->rights->isOnPortal($version),
				'printStation' => $this->rights->printStationDenial($version, $licenses, $day),
			],
			'tasks' => $active['tasks'] ? $this->taskService->forCopy($copy->getId()) : [],
			'links' => $active['excerpt_links'] ? $this->linkService->active($copy->getId(), $uid) : [],
			'proposals' => $active['proposals'] ? array_merge(
				$this->proposals->findOpenForObject(Fields::WORK, $work->getId()),
				$this->proposals->findOpenForObject(Fields::VERSION, $version->getId()),
				$this->proposals->findOpenForObject(Fields::COPY, $copy->getId()),
			) : [],
			'siblings' => $siblings,
			'contributions' => $active['persons'] ? $this->persons->forVersion($version->getId()) : [],
			'protection' => $active['protection_terms'] ? $this->persons->protection($version->getId(), (int)substr($day, 0, 4)) : null,
			'merges' => $active['merge'] ? $this->merges->openFor($work->getId(), $copy->getId()) : [],
			'audit' => $this->auditEntries->findForObjects([
				[Fields::WORK, $work->getId()],
				[Fields::VERSION, $version->getId()],
				[Fields::COPY, $copy->getId()],
				...$this->reviewObjects($copy->getId(), $active[ReviewService::MODULE]),
			]),
			// Revisions of the version (milestone 0.8): the newest, the current one, the one of the score shown
			'revision' => $active[StateService::MODULE] ? $this->states->summary($version->getId(), $score['fileId'] ?? null) : null,
		];
	}

	/**
	 * Changes fields of an entity. Each field is checked against the field
	 * registry and the roles of the user; each change is recorded.
	 *
	 * @param array<array-key, mixed> $fields field => value (request data)
	 */
	public function update(string $level, int $id, array $fields, string $uid, ?string $reason): void {
		$registry = Fields::all()[$level] ?? null;
		if ($registry === null) {
			throw new InvalidInputException('Unknown level: %s', [$level]);
		}
		if ($fields === []) {
			return;
		}
		$entity = $this->load($level, $id);

		$new = [];
		foreach (Input::values($fields) as $field => $value) {
			$info = $registry[$field] ?? null;
			if ($info === null) {
				throw new InvalidInputException('Unknown field: %s', [$field]);
			}
			if ($info['write'] === []) {
				throw new InvalidInputException('"%s" is read-only.', [$info['label']]);
			}
			$this->roles->requireRole($uid, $info['write']);
			$new[$field] = self::validate($info, $value);
		}

		if ($entity instanceof WorkVersion && array_key_exists('rights_status', $new) && $new['rights_status'] !== $entity->getRightsStatus()) {
			// A confirmation refers to one status; changing it removes the confirmation.
			$new += RightsService::noConfirmation();
		}
		$year = $new['first_published_year'] ?? null;
		if (is_int($year) && ($year < 1000 || $year > (int)substr($this->rights->today($uid), 0, 4))) {
			throw new InvalidInputException('"%s" must be a year between 1000 and this year, e.g. 1998.', ['Year first published']);
		}
		if ($entity instanceof WorkVersion && array_key_exists('rule1_status', $new) && $new['rule1_status'] !== $entity->getValue('rule1_status')) {
			$new['rule1_checked'] = $this->rights->today($uid) . ' ' . ($this->userManager->getDisplayName($uid) ?? $uid);
		}

		if ($entity instanceof Work && ($new['public_repertoire'] ?? null) === true && $entity->getPublicRepertoire() !== true) {
			// The public repertoire page (milestone 0.7) shows public domain works only; it checks again when it is shown.
			$this->modules->requireEnabled(Modules::PUBLIC_REPERTOIRE);
			if (array_filter($this->versions->findByWork($entity->getId()), PublicRepertoireService::eligible(...)) === []) {
				throw new InvalidInputException('Only works with a version confirmed as public domain can be shown on the public repertoire page. The catalog librarian confirms the rights of a version on the tab "Rights" first.');
			}
		}

		if ($entity instanceof Copy && array_key_exists('status', $new) && $new['status'] !== $entity->getStatus()) {
			if ($new['status'] === null) {
				throw new InvalidInputException('The status must not be empty.');
			}
			$statusReason = $new['status_reason'] ?? null;
			if ($new['status'] !== 'active' && ($statusReason === null || trim((string)$statusReason) === '')) {
				throw new InvalidInputException('A reason is required for this status.');
			}
			$new['status_since'] = $this->time->getDateTime('now', $this->timeZone->getTimeZone(false, $uid))->format('Y-m-d');
		}

		$operation = $this->audit->newOperation();
		$this->db->beginTransaction();
		try {
			$changed = false;
			foreach ($new as $field => $value) {
				$old = $entity->getValue($field);
				if ($old === $value) {
					continue;
				}
				$entity->setValue($field, $value);
				$this->audit->record($operation, $uid, $level, $id, 'changed', $field, $old, $value, $reason);
				$changed = true;
			}
			if ($changed) {
				$entity->setModified($this->time->getTime());
				match (true) {
					$entity instanceof Work => $this->works->update($entity),
					$entity instanceof WorkVersion => $this->versions->update($entity),
					$entity instanceof Copy => $this->copies->update($entity),
				};
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * The review points of a copy as objects of the audit log, so that their
	 * decisions show with the copy (milestone 0.6).
	 *
	 * @return list<array{0: string, 1: int}>
	 */
	private function reviewObjects(int $copyId, bool $active): array {
		if (!$active) {
			return [];
		}
		$objects = [];
		foreach ($this->reviewPoints->findByCopy($copyId) as $point) {
			$objects[] = ['review_point', $point->getId()];
		}
		return $objects;
	}

	private function load(string $level, int $id): Work|WorkVersion|Copy {
		try {
			return match ($level) {
				Fields::WORK => $this->works->find($id),
				Fields::VERSION => $this->versions->find($id),
				Fields::COPY => $this->copies->find($id),
				default => throw new InvalidInputException('Unknown level: %s', [$level]),
			};
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
	}

	/**
	 * Normalizes a value from the UI and checks it against the field.
	 *
	 * @param FieldInfo $info
	 */
	private static function validate(array $info, string|int|float|bool|null $value): string|int|bool|null {
		if ($value === null || (is_string($value) && trim($value) === '')) {
			return null;
		}
		if ($info['type'] === Fields::TYPE_NUMBER) {
			$value = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? $value;
		} elseif ($info['type'] === Fields::TYPE_BOOLEAN && !is_bool($value)) {
			$value = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $value;
		}
		$error = FieldValue::check($info, $value);
		if ($error !== null || is_float($value)) {
			throw new InvalidInputException($error ?? '"%s" must be a whole number.', [$info['label']]);
		}
		return $value;
	}
}
