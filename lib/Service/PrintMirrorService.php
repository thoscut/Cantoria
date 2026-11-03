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
use OCA\Cantoria\Db\MirrorFile;
use OCA\Cantoria\Db\MirrorFileMapper;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\RunInProgressException;
use OCA\Cantoria\Exception\UserFacingException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * The mirror folder of the print station (milestone 0.7, module
 * "print_mirror"), the one place besides small corrections where the app
 * writes files (exception to guideline 4):
 *
 * - Only into a target folder of its own that an administrator chooses; it
 *   must be neither the archive folder nor inside it (checked when it is
 *   chosen and before every sync). The app reads and writes as the account
 *   that chose it.
 * - It holds the PDF scans of the versions the list of the print station
 *   shows (ReleaseListService, the same rule, no second one), never internal
 *   preservation copies, named `<shelf mark> <title>.pdf`, and a
 *   `LIESMICH.txt` with the date and a note.
 * - Missing files are copied, changed scans replaced, files no longer
 *   released removed. The manifest (table cantoria_mirror_files: name, file
 *   id, SHA-256 of what was written) tells which files the app wrote: only
 *   those are replaced or removed, and only while their content is still
 *   the one written. Other files in the folder stay as they are.
 * - Every write and every removal is in the audit log. The daily checks
 *   sync once the first sync has been started by hand after the preview.
 *
 * Reasons for skipped files are keys, translated by the controller.
 *
 * @psalm-type MirrorEntry = array{name: string, shelfmark: ?string, reason: ?string}
 * @psalm-type MirrorReport = array{time: int, dryRun: bool, writable: bool, copied: list<MirrorEntry>, replaced: list<MirrorEntry>,
 *     removed: list<MirrorEntry>, skipped: list<MirrorEntry>, unchanged: int, files: int}
 */
class PrintMirrorService {
	public const MODULE = Modules::PRINT_MIRROR;
	public const README = 'LIESMICH.txt';
	public const OBJECT_TYPE = 'print_mirror';
	/** Name of this target folder in the manifest (cantoria_mirror_files.mirror) */
	public const MIRROR = 'print';

	/** Why a file was skipped (keys; the controller translates them) */
	public const FOREIGN = 'foreign';
	public const CHANGED = 'changed';
	public const NOT_ACCESSIBLE = 'not_accessible';
	public const NOT_PDF = 'not_pdf';
	public const INVALID_NAME = 'invalid_name';
	public const PRESERVATION = 'preservation';
	public const WRITE_FAILED = 'write_failed';

	/** Longest file name without ".pdf" and a number */
	private const MAX_BASE = 120;
	/** Entries per list kept in the stored report */
	private const MAX_REPORT_ENTRIES = 500;

	public function __construct(
		protected IAppConfig $appConfig,
		protected IRootFolder $rootFolder,
		protected AppSettings $settings,
		protected ReleaseListService $releases,
		protected ArchiveFileMapper $archiveFiles,
		protected CopyMapper $copies,
		protected MirrorFileMapper $mirrorFiles,
		protected AuditLog $audit,
		protected Roles $roles,
		protected Modules $modules,
		protected NotificationService $notifications,
		protected IFilenameValidator $filenameValidator,
		protected IUserManager $userManager,
		protected IFactory $l10nFactory,
		protected ITimeFactory $time,
		protected IDateTimeZone $timeZone,
		protected LoggerInterface $logger,
		protected RunLock $runLock,
	) {
	}

	/** Prefix of the app config keys (folder_id, folder_path, user, started, report) and of the log */
	protected function prefix(): string {
		return 'print_mirror';
	}

	/** Prefix of the other target folder, which must not be the same folder */
	protected function otherPrefix(): string {
		return 'choir_handover';
	}

	/** Name of this target folder in the manifest (cantoria_mirror_files.mirror) */
	protected function mirror(): string {
		return self::MIRROR;
	}

	/** Object type of the audit log */
	protected function objectType(): string {
		return self::OBJECT_TYPE;
	}

	/** The module of this target folder */
	protected function module(): string {
		return self::MODULE;
	}

	/**
	 * Messages about the target folder (English source strings); the
	 * hand-over folder (HandoverService) has its own.
	 */
	protected function message(string $key): string {
		return match ($key) {
			'archive_first' => 'Please choose the archive folder first: the print station copies the scans from it.',
			'inside_archive' => 'The target folder must be neither the archive folder nor inside it. Please choose a folder outside the archive folder, for example one shared with the print station.',
			'none' => 'No target folder has been chosen for the print station yet. An administrator chooses it in the settings of Cantoria.',
			'gone' => 'The target folder of the print station is not accessible (deleted, moved or no longer shared). An administrator chooses it again in the settings.',
			'moved_inside' => 'The target folder lies inside the archive folder, where the app never writes. An administrator chooses another target folder in the settings.',
			'not_writable' => 'The account %s may not write into the target folder of the print station. Give it write permission, or let an administrator choose another folder in the settings.',
			default => 'The account that chose the target folder of the print station no longer exists. An administrator chooses the target folder again in the settings.',
		};
	}

	/** The app config key of a setting of this target folder */
	private function key(string $name): string {
		return $this->prefix() . '_' . $name;
	}

	/**
	 * The run lock of this target folder
	 *
	 * @return RunLock::PRINT_MIRROR|RunLock::CHOIR_HANDOVER
	 */
	protected function lockName(): string {
		return RunLock::PRINT_MIRROR;
	}

	/** The notification when the daily sync failed */
	protected function failedSubject(): string {
		return NotificationService::PRINT_MIRROR_FAILED;
	}

	/**
	 * The target folder: Nextcloud file id, path as seen by the account that
	 * chose it, and that account (id 0: none chosen).
	 *
	 * @return array{id: int, path: string, user: string}
	 */
	public function info(): array {
		return [
			'id' => $this->appConfig->getValueInt(Application::APP_ID, $this->key('folder_id'), 0),
			'path' => $this->appConfig->getValueString(Application::APP_ID, $this->key('folder_path'), ''),
			'user' => $this->appConfig->getValueString(Application::APP_ID, $this->key('user'), ''),
		];
	}

	/**
	 * Checks a folder as target folder for the account without saving it.
	 *
	 * @return array{id: int, path: string, user: string}
	 * @throws InvalidInputException with what to do when it does not fit
	 */
	public function checkTargetFolder(string $uid, int $folderId): array {
		$this->roles->requireSettingsAccess($uid);
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$folder = $folderId > 0 ? $userFolder->getFirstNodeById($folderId) : null;
		if (!$folder instanceof Folder) {
			throw new InvalidInputException('The selected target folder is not a folder or not accessible. Please choose a folder.');
		}
		if ($this->settings->archiveInfo()['id'] === 0) {
			throw new InvalidInputException($this->message('archive_first'));
		}
		if ($this->insideArchive($uid, $folder)) {
			throw new InvalidInputException($this->message('inside_archive'));
		}
		try {
			$this->settings->archiveFolder($uid);
		} catch (UserFacingException) {
			throw new InvalidInputException('Your account cannot read the archive folder. Please choose the target folder with an account that has access to the archive folder.');
		}
		if ($folder->getId() === $this->appConfig->getValueInt(Application::APP_ID, $this->otherPrefix() . '_folder_id', 0)) {
			throw new InvalidInputException('This folder is already the target folder of the print station or of the hand-over to the choir system. Please choose a folder of its own.');
		}
		if (!$folder->isCreatable() || !$folder->isUpdateable()) {
			throw new InvalidInputException('Your account may not create files in this folder. Please choose a folder where it may create, change and delete files.');
		}
		return ['id' => $folder->getId(), 'path' => $userFolder->getRelativePath($folder->getPath()) ?? $folder->getPath(), 'user' => $uid];
	}

	/**
	 * Saves the target folder (administrators). Another folder than before
	 * starts afresh: the files in the old folder stay there and are no
	 * longer looked after, and the daily sync waits for a first sync by hand.
	 * Folder id 0 removes the target folder.
	 *
	 * @return array{id: int, path: string, user: string}
	 */
	public function setTargetFolder(string $uid, int $folderId): array {
		$this->roles->requireSettingsAccess($uid);
		$old = $this->info();
		$new = $folderId === 0 ? ['id' => 0, 'path' => '', 'user' => ''] : $this->checkTargetFolder($uid, $folderId);
		if ($new['id'] !== $old['id']) {
			$this->mirrorFiles->deleteAll($this->mirror());
			$this->appConfig->deleteKey(Application::APP_ID, $this->key('started'));
			$this->appConfig->deleteKey(Application::APP_ID, $this->key('report'));
			$this->notifications->withdraw($this->failedSubject(), $this->objectType(), 'sync');
		}
		if ($new['id'] === 0) {
			foreach ([$this->key('folder_id'), $this->key('folder_path'), $this->key('user')] as $key) {
				$this->appConfig->deleteKey(Application::APP_ID, $key);
			}
		} else {
			$this->appConfig->setValueInt(Application::APP_ID, $this->key('folder_id'), $new['id']);
			$this->appConfig->setValueString(Application::APP_ID, $this->key('folder_path'), $new['path']);
			$this->appConfig->setValueString(Application::APP_ID, $this->key('user'), $new['user']);
		}
		if ($new['path'] !== $old['path'] || $new['id'] !== $old['id']) {
			$this->audit->record($this->audit->newOperation(), $uid, 'settings', null, $this->prefix() . '_folder', 'target_folder',
				$old['id'] === 0 ? null : $old['path'], $new['id'] === 0 ? null : $new['path']);
		}
		return $new;
	}

	/**
	 * Target folder, whether the daily sync runs, and the report of the
	 * last sync (catalog librarian and administrators).
	 *
	 * @return array{folder: ?array{id: int, path: string, user: string}, started: bool, report: ?array<array-key, mixed>}
	 */
	public function status(string $uid): array {
		$this->requireOperator($uid);
		$info = $this->info();
		return [
			'folder' => $info['id'] === 0 ? null : $info,
			'started' => $this->started(),
			'report' => self::arrayOrNull(json_decode($this->appConfig->getValueString(Application::APP_ID, $this->key('report'), '', true), true, 8)),
		];
	}

	/**
	 * @return array<array-key, mixed>|null
	 */
	private static function arrayOrNull(mixed $value): ?array {
		return is_array($value) ? $value : null;
	}

	/**
	 * Compares the target folder with the list of the print station and,
	 * unless it is a dry run (the preview), copies, replaces and removes.
	 * The first sync by hand starts the daily sync.
	 *
	 * @param string|null $uid who started it; null for the daily checks
	 * @return MirrorReport
	 * @throws UserFacingException when the target folder cannot be used
	 */
	public function sync(?string $uid, bool $dryRun): array {
		if ($uid !== null) {
			$this->requireOperator($uid);
		}
		// The preview only reads; a real sync never runs twice at the same time.
		return $dryRun ? $this->syncNow($uid, true)
			: $this->runLock->run($this->lockName(), $uid, fn (): array => $this->syncNow($uid, false));
	}

	/**
	 * @return MirrorReport
	 */
	private function syncNow(?string $uid, bool $dryRun): array {
		$info = $this->info();
		if ($info['id'] === 0) {
			throw new InvalidInputException($this->message('none'));
		}
		$account = $info['user'];
		$userFolder = $this->userFolder($account);
		$target = $userFolder->getFirstNodeById($info['id']);
		if (!$target instanceof Folder) {
			throw new InvalidInputException($this->message('gone'));
		}
		if ($this->insideArchive($account, $target)) {
			throw new InvalidInputException($this->message('moved_inside'));
		}
		$writable = $target->isCreatable();
		if (!$dryRun && !$writable) {
			throw new InvalidInputException($this->message('not_writable'), [$account]);
		}

		$report = ['time' => $this->time->getTime(), 'dryRun' => $dryRun, 'writable' => $writable, 'copied' => [], 'replaced' => [], 'removed' => [],
			'skipped' => [], 'unchanged' => 0, 'files' => 0];
		$operation = $this->audit->newOperation();
		$manifest = $this->mirrorFiles->findAllByName($this->mirror());
		$keep = [];

		foreach ($this->wanted($account) as $entry) {
			$name = $entry['name'];
			$keep[$name] = true;
			$item = ['name' => $name, 'shelfmark' => $entry['shelfmark'], 'reason' => null];
			if ($entry['skip'] !== null || $entry['node'] === null) {
				$report['skipped'][] = ['reason' => $entry['skip'] ?? self::NOT_ACCESSIBLE] + $item;
				continue;
			}
			$row = $manifest[$name] ?? null;
			$existing = $target->nodeExists($name) ? $target->get($name) : null;
			if ($existing !== null && ($row === null || !$existing instanceof File || $existing->getId() !== $row->getFileId())) {
				// A file of this name that the app did not write (or no longer the one it wrote)
				$report['skipped'][] = ['reason' => self::FOREIGN] + $item;
				$this->forget($row, $dryRun);
				continue;
			}
			if ($existing === null) {
				$this->forget($row, $dryRun);
				$this->write($dryRun, $report, 'copied', $item, fn () => $this->copy($target, $name, $entry, $uid, $operation));
				continue;
			}
			/** @var MirrorFile $row */
			if ($row->getSourceId() === $entry['archiveFile']->getId() && $row->getSourceEtag() === $entry['node']->getEtag()) {
				$report['unchanged']++;
				continue;
			}
			if (self::sha256($existing) !== $row->getSha256()) {
				$report['skipped'][] = ['reason' => self::CHANGED] + $item;
				$this->forget($row, $dryRun);
				continue;
			}
			$this->write($dryRun, $report, 'replaced', $item, fn () => $this->replace($existing, $row, $entry, $uid, $operation));
		}

		// Text files of this folder (the read-me, for the hand-over also the list
		// and the notes); the number of files is final here, removals do not count
		$files = $report['unchanged'] + count($report['copied']) + count($report['replaced']);
		$texts = $this->textFiles($account, $files);
		foreach ($texts as $text) {
			$keep[$text['name']] = true;
		}

		foreach ($manifest as $name => $row) {
			if (isset($keep[$name])) {
				continue;
			}
			$existing = $target->nodeExists($name) ? $target->get($name) : null;
			$item = ['name' => $name, 'shelfmark' => null, 'reason' => null];
			if ($existing === null) {
				$this->forget($row, $dryRun);
			} elseif (!$existing instanceof File || $existing->getId() !== $row->getFileId()) {
				$report['skipped'][] = ['reason' => self::FOREIGN] + $item;
				$this->forget($row, $dryRun);
			} elseif (self::sha256($existing) !== $row->getSha256()) {
				$report['skipped'][] = ['reason' => self::CHANGED] + $item;
				$this->forget($row, $dryRun);
			} else {
				$this->write($dryRun, $report, 'removed', $item, function () use ($existing, $row, $name, $uid, $operation): void {
					$existing->delete();
					$this->mirrorFiles->delete($row);
					$this->audit->record($operation, $uid, $this->objectType(), $row->getCopyId(), 'mirror_removed', 'file', $name, null);
				});
			}
		}
		$report['files'] = $files;
		$changes = count($report['copied']) + count($report['replaced']) + count($report['removed']);
		foreach ($texts as $text) {
			$this->writeText($target, $manifest[$text['name']] ?? null, $text, $changes, $dryRun, $report, $uid, $operation);
		}

		if (!$dryRun) {
			$this->appConfig->setValueString(Application::APP_ID, $this->key('report'), json_encode(self::bounded($report), JSON_THROW_ON_ERROR), true);
			if ($uid !== null) {
				$this->appConfig->setValueBool(Application::APP_ID, $this->key('started'), true);
			}
			$this->notifications->withdraw($this->failedSubject(), $this->objectType(), 'sync');
		}
		return $report;
	}

	/**
	 * The daily sync (daily checks): only while the module is on, a target
	 * folder is chosen and the first sync was started by hand. A failure is
	 * notified to the catalog librarian and the administrators.
	 */
	public function runDaily(): void {
		if (!$this->modules->isEnabled($this->module()) || $this->info()['id'] === 0 || !$this->started()) {
			return;
		}
		try {
			$this->sync(null, false);
		} catch (RunInProgressException) {
			// A sync by hand is running at this moment.
			return;
		} catch (\Throwable $e) {
			$this->logger->error('Cantoria: sync of ' . $this->prefix() . ' failed: ' . $e->getMessage(), ['exception' => $e]);
			// Only messages meant for users go into the notification.
			$parameters = $e instanceof UserFacingException
				? ['error' => $e->getMessage(), 'errorParameters' => json_encode(array_map('strval', $e->getParameters()), JSON_THROW_ON_ERROR)]
				: [];
			$this->notifications->notifyRole(Roles::LIBRARIAN, $this->failedSubject(), $this->objectType(), 'sync', $parameters, true);
		}
	}

	/**
	 * File name without extension: `<shelf mark> <title>`, without the
	 * characters Windows and Nextcloud do not allow, shortened.
	 */
	public static function fileBase(string $shelfmark, ?string $title): string {
		$text = trim($shelfmark . ' ' . ($title ?? ''));
		$text = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $text) ?? '';
		$text = trim(preg_replace('/\s+/u', ' ', $text) ?? '', ' .');
		if (mb_strlen($text) > self::MAX_BASE) {
			$text = rtrim(mb_substr($text, 0, self::MAX_BASE), ' .');
		}
		return $text === '' ? 'score' : $text;
	}

	/**
	 * The files that belong into the target folder: the scans (PDF) of the
	 * active copies of every version on the list of the print station.
	 *
	 * @return list<array{name: string, shelfmark: string, copyId: int, archiveFile: ArchiveFile, node: ?File, skip: ?string}>
	 */
	protected function wanted(string $account): array {
		// Scans are read only inside the archive folder, never elsewhere in
		// the files of the account.
		$archive = $this->settings->archiveFolder($account);
		$scans = [];
		foreach ($this->archiveFiles->findFound() as $file) {
			$copyId = $file->getCopyId();
			if ($copyId !== null && $file->getRole() === 'score_pdf') {
				$scans[$copyId][] = $file;
			}
		}
		$kinds = [];
		foreach ($this->copies->findAll() as $copy) {
			$kinds[$copy->getId()] = $copy->getKind();
		}
		$entries = [];
		foreach ($this->releases->printStation($account) as $row) {
			foreach ($row['copies'] as $copy) {
				$files = $scans[$copy['id']] ?? [];
				usort($files, fn (ArchiveFile $a, ArchiveFile $b): int => $a->getId() <=> $b->getId());
				$base = self::fileBase($copy['shelfmark'], $row['title']);
				foreach ($files as $index => $file) {
					$name = $base . ($index > 0 ? ' (' . ($index + 1) . ')' : '') . '.pdf';
					$node = null;
					// The list already leaves out versions marked as preservation copies;
					// a copy of that kind is one as well (RightsPolicy, one rule).
					if (($kinds[$copy['id']] ?? null) === RightsPolicy::PRESERVATION_COPY_KIND) {
						$skip = self::PRESERVATION;
					} elseif (!$this->filenameValidator->isFilenameValid($name)) {
						$skip = self::INVALID_NAME;
					} else {
						$found = $archive->getFirstNodeById($file->getFileId());
						$node = $found instanceof File ? $found : null;
						$skip = $node === null ? self::NOT_ACCESSIBLE : (ScanService::isPdf($node) ? null : self::NOT_PDF);
					}
					$entries[] = ['name' => $name, 'shelfmark' => $copy['shelfmark'], 'copyId' => $copy['id'], 'archiveFile' => $file,
						'node' => $skip === null ? $node : null, 'skip' => $skip];
				}
			}
		}
		return $entries;
	}

	/**
	 * Runs a write action unless it is a dry run, and reports it; a failed
	 * action is reported as skipped and the others go on.
	 *
	 * @param MirrorReport $report
	 * @param 'copied'|'replaced'|'removed' $list
	 * @param MirrorEntry $item
	 * @param callable(): void $action
	 */
	private function write(bool $dryRun, array &$report, string $list, array $item, callable $action): void {
		if (!$dryRun) {
			try {
				$action();
			} catch (\Exception $e) {
				$this->logger->warning('Cantoria: ' . $this->prefix() . ', ' . $item['name'] . ': ' . $e->getMessage(), ['exception' => $e]);
				$report['skipped'][] = ['reason' => self::WRITE_FAILED] + $item;
				return;
			}
		}
		$report[$list][] = $item;
	}

	/**
	 * @param array{name: string, shelfmark: string, copyId: int, archiveFile: ArchiveFile, node: ?File, skip: ?string} $entry
	 */
	private function copy(Folder $target, string $name, array $entry, ?string $uid, string $operation): void {
		$source = $entry['node'];
		if ($source === null) {
			return;
		}
		$handle = $this->content($entry['archiveFile'], $source);
		try {
			$file = $target->newFile($name, $handle);
		} finally {
			// newFile() may close the stream itself
			if (is_resource($handle) && get_resource_type($handle) === 'stream') {
				fclose($handle);
			}
		}
		$row = new MirrorFile();
		$row->setMirror($this->mirror());
		$row->setName($name);
		$row->setFileId($file->getId());
		$row->setSha256(self::sha256($file));
		$row->setCopyId($entry['copyId']);
		$row->setSourceId($entry['archiveFile']->getId());
		$row->setSourceEtag($source->getEtag());
		$row->setWritten($this->time->getTime());
		$this->mirrorFiles->insert($row);
		$this->audit->record($operation, $uid, $this->objectType(), $entry['copyId'], 'mirror_copied', 'file', null, $name);
	}

	/**
	 * @param array{name: string, shelfmark: string, copyId: int, archiveFile: ArchiveFile, node: ?File, skip: ?string} $entry
	 */
	private function replace(File $existing, MirrorFile $row, array $entry, ?string $uid, string $operation): void {
		$source = $entry['node'];
		if ($source === null) {
			return;
		}
		$handle = $this->content($entry['archiveFile'], $source);
		try {
			$existing->putContent($handle);
		} finally {
			// putContent() may close the stream itself
			if (is_resource($handle) && get_resource_type($handle) === 'stream') {
				fclose($handle);
			}
		}
		$row->setSha256(self::sha256($existing));
		$row->setCopyId($entry['copyId']);
		$row->setSourceId($entry['archiveFile']->getId());
		$row->setSourceEtag($source->getEtag());
		$row->setWritten($this->time->getTime());
		$this->mirrorFiles->update($row);
		$this->audit->record($operation, $uid, $this->objectType(), $entry['copyId'], 'mirror_replaced', 'file', $row->getName(), $row->getName());
	}

	/**
	 * What is written for a file of the archive folder: here the file
	 * itself, as a stream; a subclass may write a cleaned copy instead.
	 *
	 * @return resource|string
	 */
	protected function content(ArchiveFile $file, File $source) {
		$handle = $source->fopen('r');
		if ($handle === false) {
			throw new \RuntimeException('cannot read archive file ' . $file->getId());
		}
		return $handle;
	}

	/**
	 * Writes a text file of the folder when it is missing, when files of the
	 * folder changed, or (with `refresh`) when its text is another; never
	 * over a file the app did not write or one changed since.
	 *
	 * @param array{name: string, text: string, refresh: bool, copyId: ?int} $text
	 * @param MirrorReport $report
	 */
	private function writeText(Folder $target, ?MirrorFile $row, array $text, int $changes, bool $dryRun, array &$report, ?string $uid,
		string $operation): void {
		$name = $text['name'];
		$existing = $target->nodeExists($name) ? $target->get($name) : null;
		$item = ['name' => $name, 'shelfmark' => null, 'reason' => null];
		$own = $existing instanceof File && $row !== null && $existing->getId() === $row->getFileId();
		if ($existing !== null && !$own) {
			$report['skipped'][] = ['reason' => self::FOREIGN] + $item;
			$this->forget($row, $dryRun);
			return;
		}
		$content = $text['text'];
		$sha = hash('sha256', $content);
		if ($existing !== null && $changes === 0 && (!$text['refresh'] || $row?->getSha256() === $sha)) {
			return;
		}
		/** @var ?File $existing */
		if ($existing !== null && $row !== null && self::sha256($existing) !== $row->getSha256()) {
			$report['skipped'][] = ['reason' => self::CHANGED] + $item;
			$this->forget($row, $dryRun);
			return;
		}
		$readme = $name === self::README;
		$this->write($dryRun, $report, $existing === null ? 'copied' : 'replaced', $item,
			function () use ($target, $existing, $row, $name, $content, $sha, $text, $readme, $uid, $operation): void {
				$file = $existing ?? $target->newFile($name, $content);
				if ($existing !== null) {
					$existing->putContent($content);
				}
				$entry = $row ?? new MirrorFile();
				$entry->setMirror($this->mirror());
				$entry->setName($name);
				$entry->setFileId($file->getId());
				$entry->setSha256($sha);
				$entry->setCopyId($text['copyId']);
				$entry->setWritten($this->time->getTime());
				if ($row === null) {
					$this->mirrorFiles->insert($entry);
				} else {
					$this->mirrorFiles->update($entry);
				}
				$action = $readme ? 'mirror_readme' : ($existing === null ? 'mirror_copied' : 'mirror_replaced');
				$this->audit->record($operation, $uid, $this->objectType(), $text['copyId'], $action, 'file', $readme || $existing === null ? null : $name, $name);
			});
	}

	/**
	 * The text files of the folder: the read-me (rewritten only when files
	 * changed, as it carries the time).
	 *
	 * @return list<array{name: string, text: string, refresh: bool, copyId: ?int}>
	 */
	protected function textFiles(string $account, int $files): array {
		return [['name' => self::README, 'text' => $this->readmeText($account, $files), 'refresh' => false, 'copyId' => null]];
	}

	/**
	 * The language of the account that chose the target folder.
	 */
	protected function l10nOf(string $account): IL10N {
		$user = $this->userManager->get($account);
		return $this->l10nFactory->get(Application::APP_ID, $this->l10nFactory->getUserLanguage($user));
	}

	/**
	 * The text of LIESMICH.txt in the language of the account.
	 */
	protected function readmeText(string $account, int $files): string {
		$l = $this->l10nOf($account);
		$now = $this->time->getDateTime('now', $this->timeZone->getTimeZone(false, $account));
		$lines = [
			$l->t('Print station: mirror folder of the music archive'),
			$l->t('As of %s', [$now->format('Y-m-d H:i')]),
			'',
			$l->n('Cantoria keeps this folder up to date. It holds %n score (PDF) released for the print station.',
				'Cantoria keeps this folder up to date. It holds %n scores (PDF) released for the print station.', $files),
			$l->t('Print these scores only for the choir and do not pass them on.'),
			$l->t('The app copies, replaces and removes only the files it wrote itself. Files changed or added by others stay as they are.'),
			$l->t('Questions: please ask the music librarians.'),
		];
		return implode("\r\n", $lines) . "\r\n";
	}

	/**
	 * Forgets a file of the manifest (it is not the app's any more).
	 */
	private function forget(?MirrorFile $row, bool $dryRun): void {
		if ($row !== null && !$dryRun) {
			$this->mirrorFiles->delete($row);
		}
	}

	private function started(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, $this->key('started'), false);
	}

	/**
	 * Whether a folder is the archive folder or lies inside it, as seen by
	 * the account and by the account that chose the archive folder (a
	 * folder may be reached through shares at different paths).
	 */
	private function insideArchive(string $uid, Folder $folder): bool {
		$archive = $this->settings->archiveInfo();
		if ($archive['id'] === 0) {
			return false;
		}
		if ($folder->getId() === $archive['id']) {
			return true;
		}
		foreach (array_unique(array_filter([$uid, $archive['user']])) as $user) {
			try {
				$view = $this->rootFolder->getUserFolder($user);
			} catch (\Throwable) {
				continue;
			}
			foreach ($view->getById($folder->getId()) as $node) {
				foreach ($view->getById($archive['id']) as $archiveNode) {
					if (str_starts_with($node->getPath() . '/', rtrim($archiveNode->getPath(), '/') . '/')) {
						return true;
					}
				}
			}
		}
		return false;
	}

	private function userFolder(string $account): Folder {
		try {
			return $this->rootFolder->getUserFolder($account);
		} catch (\Throwable) {
			throw new InvalidInputException($this->message('account_gone'));
		}
	}

	private function requireOperator(string $uid): void {
		if (!$this->roles->isAdmin($uid)) {
			$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		}
	}

	private static function sha256(File $file): string {
		$handle = $file->fopen('r');
		if ($handle === false) {
			throw new \RuntimeException('cannot read ' . $file->getPath());
		}
		try {
			$context = hash_init('sha256');
			hash_update_stream($context, $handle);
			return hash_final($context);
		} finally {
			fclose($handle);
		}
	}

	/**
	 * The report with at most MAX_REPORT_ENTRIES entries per list, to store.
	 *
	 * @param MirrorReport $report
	 * @return MirrorReport
	 */
	private static function bounded(array $report): array {
		foreach (['copied', 'replaced', 'removed', 'skipped'] as $list) {
			$report[$list] = array_slice($report[$list], 0, self::MAX_REPORT_ENTRIES);
		}
		return $report;
	}
}
