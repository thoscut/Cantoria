<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Db\ArchiveFile;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\ScoreFact;
use OCA\Cantoria\Db\ScoreFactMapper;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\InvalidInputException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Keeps the facts of the scores (ScoreFacts) with the MusicXML files of the
 * archive and hands them to the lists: ranges of the voices for "fits our
 * choir", the incipit for the catalog and the programmes, first tones and
 * keys for the pitch sheet, lyrics for the programme booklet.
 *
 * The facts are read in the background with the account of the archive
 * folder, like the checksums (ScoreFactsJob), and whenever a score is opened
 * in the viewer. They are derived data: a changed file or a new format of
 * the analysis is simply read again, and the table is not in the backup.
 *
 * Also keeps the choir profile: which voice groups the choir has and which
 * range is comfortable and which still possible for each. It describes the
 * groups, never single singers.
 *
 * @psalm-import-type Facts from ScoreFacts
 * @psalm-type ProfileGroup = array{group: string, comfortLow: int, comfortHigh: int, limitLow: int, limitHigh: int}
 * @psalm-type ChoirProfile = array{groups: list<ProfileGroup>, custom: bool}
 */
class ScoreFactsService {
	public const PROFILE_KEY = 'choir_profile';
	public const CURSOR_KEY = 'score_facts_cursor';
	/** Files read per background run */
	public const MAX_FILES = 50;
	/** Who edits the choir profile */
	public const PROFILE_EDITORS = [Roles::CONDUCTOR, Roles::LIBRARIAN];

	/**
	 * A mixed choir of amateurs: comfortable and possible ranges per group
	 * (MIDI, middle C = 60). The conductor adjusts them to the choir.
	 */
	public const DEFAULT_PROFILE = [
		['group' => 'S', 'comfortLow' => 62, 'comfortHigh' => 77, 'limitLow' => 60, 'limitHigh' => 81],
		['group' => 'A', 'comfortLow' => 55, 'comfortHigh' => 72, 'limitLow' => 53, 'limitHigh' => 74],
		['group' => 'T', 'comfortLow' => 50, 'comfortHigh' => 65, 'limitLow' => 48, 'limitHigh' => 69],
		['group' => 'B', 'comfortLow' => 43, 'comfortHigh' => 60, 'limitLow' => 40, 'limitHigh' => 64],
	];

	public function __construct(
		private ScoreFactMapper $facts,
		private ArchiveFileMapper $files,
		private ScoreService $scores,
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private RightsPolicy $policy,
		private AppSettings $settings,
		private Roles $roles,
		private AuditLog $audit,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reads the facts of a score whose content was just read (e.g. for the
	 * viewer), unless they are stored for this content already. Never fails.
	 */
	public function remember(int $archiveFileId, string $content): void {
		try {
			$this->store($archiveFileId, $content);
		} catch (\Throwable $e) {
			$this->logger->info('Cantoria: facts of file ' . $archiveFileId . ' not read: ' . $e->getMessage(), ['exception' => $e]);
		}
	}

	/**
	 * One background run: the next MusicXML files after the stored position
	 * whose facts are missing or older than the file. Returns how many were
	 * read.
	 */
	public function run(int $maxFiles = self::MAX_FILES): int {
		$uid = $this->settings->archiveInfo()['user'];
		if ($uid === '') {
			return 0;
		}
		try {
			$archive = $this->settings->archiveFolder($uid);
		} catch (InvalidInputException $e) {
			$this->logger->info('Cantoria: score facts skipped: ' . $e->getMessage());
			return 0;
		}
		$stored = $this->facts->findAllByFile();
		$cursor = $this->appConfig->getValueInt(Application::APP_ID, self::CURSOR_KEY, 0, true);
		$candidates = [];
		foreach ($this->files->findMusicxmlPerCopy() as $files) {
			foreach ($files as $file) {
				if ($file->getFound() !== false && $this->isStale($file, $stored[$file->getId()] ?? null)) {
					$candidates[] = $file;
				}
			}
		}
		usort($candidates, fn (ArchiveFile $a, ArchiveFile $b): int => $a->getId() <=> $b->getId());
		$after = array_values(array_filter($candidates, fn (ArchiveFile $f): bool => $f->getId() > $cursor));
		$batch = array_slice($after === [] ? $candidates : $after, 0, $maxFiles);
		$read = 0;
		foreach ($batch as $file) {
			$cursor = $file->getId();
			try {
				$node = $archive->getFirstNodeById($file->getFileId());
				if (!$node instanceof File) {
					continue;
				}
				$content = UserFiles::content($node, ScoreService::MAX_BYTES, new InvalidInputException('The file is too large.'));
				$this->store($file->getId(), $content);
				$read++;
			} catch (\Throwable $e) {
				$this->logger->info('Cantoria: facts of file ' . $file->getId() . ' not read: ' . $e->getMessage());
			}
		}
		$this->appConfig->setValueInt(Application::APP_ID, self::CURSOR_KEY, $cursor, true);
		return $read;
	}

	/**
	 * The facts of the score of each copy (the file the viewer would open),
	 * for the copies that have stored facts.
	 *
	 * @param list<int>|null $copyIds null: all copies
	 * @return array<int, Facts> copy id => facts
	 */
	public function forCopies(?array $copyIds = null): array {
		$perCopy = $this->files->findMusicxmlPerCopy();
		if ($copyIds !== null) {
			$perCopy = array_intersect_key($perCopy, array_flip($copyIds));
		}
		$picked = [];
		foreach ($perCopy as $copyId => $files) {
			$file = ScoreService::pick($files);
			if ($file !== null) {
				$picked[$copyId] = $file->getId();
			}
		}
		if ($picked === []) {
			return [];
		}
		$stored = $this->facts->findByFiles(array_values($picked));
		$result = [];
		foreach ($picked as $copyId => $fileId) {
			$facts = self::decode($stored[$fileId] ?? null);
			if ($facts !== null) {
				$result[$copyId] = $facts;
			}
		}
		return $result;
	}

	/**
	 * The facts the catalog shows, per copy the account may see: the range
	 * of each voice, the key and the incipit; no lyrics. Internal
	 * preservation copies only for the roles that may view them.
	 *
	 * @return array<int, array{voices: list<array{name: ?string, group: ?string, low: int, high: int, tessLow: int, tessHigh: int}>, keyStart: mixed, incipit: mixed, measures: int}>
	 */
	public function forCatalog(string $uid): array {
		$this->roles->requireAccess($uid);
		$mayViewPreservation = $this->policy->mayViewPreservationCopies($uid);
		$versions = $mayViewPreservation ? [] : $this->versions->findAllById();
		$hidden = [];
		if (!$mayViewPreservation) {
			foreach ($this->copies->findAll() as $copy) {
				$version = $versions[$copy->getVersionId()] ?? null;
				if ($version !== null && RightsPolicy::isPreservationCopy($version, $copy)) {
					$hidden[$copy->getId()] = true;
				}
			}
		}
		$result = [];
		foreach ($this->forCopies() as $copyId => $facts) {
			if (isset($hidden[$copyId])) {
				continue;
			}
			$result[$copyId] = [
				'voices' => array_map(fn (array $v): array => ['name' => $v['name'], 'group' => $v['group'], 'low' => $v['low'],
					'high' => $v['high'], 'tessLow' => $v['tessLow'], 'tessHigh' => $v['tessHigh']], $facts['voices']),
				'keyStart' => $facts['keyStart'],
				'incipit' => $facts['incipit'],
				'measures' => $facts['measures'],
			];
		}
		return $result;
	}

	/**
	 * How many MusicXML files have facts, of how many there are.
	 *
	 * @return array{files: int, read: int}
	 */
	public function summary(): array {
		$stored = $this->facts->findAllByFile();
		$files = 0;
		$read = 0;
		foreach ($this->files->findMusicxmlPerCopy() as $list) {
			foreach ($list as $file) {
				if ($file->getFound() === false) {
					continue;
				}
				$files++;
				if (!$this->isStale($file, $stored[$file->getId()] ?? null)) {
					$read++;
				}
			}
		}
		return ['files' => $files, 'read' => $read];
	}

	/**
	 * The choir profile; the default one until the conductor saves one.
	 *
	 * @return ChoirProfile
	 */
	public function profile(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::PROFILE_KEY, '');
		if ($raw !== '') {
			try {
				$groups = self::validGroups(json_decode($raw, true, 8, JSON_THROW_ON_ERROR));
				return ['groups' => $groups, 'custom' => true];
			} catch (\JsonException|InvalidInputException) {
			}
		}
		return ['groups' => self::DEFAULT_PROFILE, 'custom' => false];
	}

	/**
	 * Saves the choir profile (conductor or catalog librarian); an empty list
	 * of groups goes back to the default.
	 *
	 * @param mixed $groups request data
	 * @return ChoirProfile
	 */
	public function saveProfile(mixed $groups, string $uid): array {
		$this->roles->requireRole($uid, self::PROFILE_EDITORS);
		$old = $this->appConfig->getValueString(Application::APP_ID, self::PROFILE_KEY, '');
		if ($groups === null || $groups === []) {
			$this->appConfig->deleteKey(Application::APP_ID, self::PROFILE_KEY);
			$this->audit->record($this->audit->newOperation(), $uid, 'settings', null, 'changed', self::PROFILE_KEY, $old === '' ? null : $old, null);
			return $this->profile();
		}
		$valid = self::validGroups($groups);
		$new = json_encode($valid, JSON_THROW_ON_ERROR);
		$this->appConfig->setValueString(Application::APP_ID, self::PROFILE_KEY, $new);
		$this->audit->record($this->audit->newOperation(), $uid, 'settings', null, 'changed', self::PROFILE_KEY, $old === '' ? null : $old, $new);
		return $this->profile();
	}

	/**
	 * @return list<ProfileGroup>
	 * @throws InvalidInputException
	 */
	public static function validGroups(mixed $groups): array {
		if (!is_array($groups) || !array_is_list($groups) || count($groups) > count(ScoreFacts::GROUPS)) {
			throw new InvalidInputException('Please give the voice groups of the choir.');
		}
		$seen = [];
		$valid = [];
		foreach ($groups as $group) {
			if (!is_array($group) || !in_array($group['group'] ?? null, ScoreFacts::GROUPS, true) || isset($seen[$group['group']])) {
				throw new InvalidInputException('Please give the voice groups of the choir.');
			}
			$seen[$group['group']] = true;
			$values = [];
			foreach (['comfortLow', 'comfortHigh', 'limitLow', 'limitHigh'] as $key) {
				$value = $group[$key] ?? null;
				if (!is_int($value) || $value < 24 || $value > 96) {
					throw new InvalidInputException('Please give each range from a lower to a higher tone.');
				}
				$values[$key] = $value;
			}
			if ($values['comfortLow'] > $values['comfortHigh'] || $values['limitLow'] > $values['comfortLow'] || $values['limitHigh'] < $values['comfortHigh']) {
				throw new InvalidInputException('Please give each range from a lower to a higher tone; the possible range includes the comfortable one.');
			}
			$valid[] = ['group' => $group['group'], ...$values];
		}
		usort($valid, fn (array $a, array $b): int => array_search($a['group'], ScoreFacts::GROUPS, true) <=> array_search($b['group'], ScoreFacts::GROUPS, true));
		return $valid;
	}

	private function store(int $archiveFileId, string $content): void {
		$hash = hash('sha256', $content);
		$fact = $this->facts->findByFile($archiveFileId);
		if ($fact !== null && $fact->getSha256() === $hash && $fact->getFormat() === ScoreFacts::FORMAT) {
			return;
		}
		$facts = null;
		if ($this->scores->isScore($content)) {
			$facts = ScoreFacts::analyze($this->scores->scoreText($content)['xml']);
		}
		$isNew = $fact === null;
		$fact ??= new ScoreFact();
		$fact->setArchiveFileId($archiveFileId);
		$fact->setSha256($hash);
		$fact->setFormat(ScoreFacts::FORMAT);
		$fact->setAnalyzedAt($this->time->getTime());
		$fact->setFacts($facts === null ? null : json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
		if ($isNew) {
			$this->facts->insert($fact);
		} else {
			$this->facts->update($fact);
		}
	}

	/**
	 * Whether the facts of a file must be read (again): none yet, an older
	 * format, or a checksum of the file that differs from the one read.
	 */
	private function isStale(ArchiveFile $file, ?ScoreFact $fact): bool {
		if ($fact === null || $fact->getFormat() !== ScoreFacts::FORMAT) {
			return true;
		}
		$sha = $file->getSha256();
		// A compressed file is read as it is: the checksum of the file is the checksum read.
		return $sha !== null && $sha !== '' && !hash_equals($sha, $fact->getSha256());
	}

	/**
	 * @return Facts|null
	 */
	private static function decode(?ScoreFact $fact): ?array {
		$raw = $fact?->getFacts();
		if ($raw === null || $raw === '') {
			return null;
		}
		try {
			/** @var Facts $decoded */
			$decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
			return $decoded;
		} catch (\JsonException) {
			return null;
		}
	}
}
