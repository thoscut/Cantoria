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
use OCA\Cantoria\Db\ExcerptLink;
use OCA\Cantoria\Db\ExcerptLinkMapper;
use OCA\Cantoria\Db\LicenseMapper;
use OCA\Cantoria\Db\MirrorFileMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\UserFacingException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * The hand-over folder for a choir system (milestone 0.12, module
 * "choir_handover"), on the pattern of the mirror folder of the print
 * station (PrintMirrorService: target folder of its own outside the archive
 * folder, manifest, preview, daily sync, only own files replaced or removed).
 *
 * It holds, for every version on the list of the singers' portal, the
 * scans (PDF) of its active copies where choir members may save and print
 * them, its MusicXML files where they may be passed on as digital score
 * files, and its recordings (audio files of the archive folder) where
 * practice audio and passing on recordings are allowed (RightsPolicy, one
 * rule). MusicXML goes in as a checked and cleaned copy, like the score of
 * a link: without encoder, notes in `miscellaneous`, comments and
 * processing instructions (MusicXmlExcerpt::whole), always uncompressed as
 * `.musicxml`; a file that is no MusicXML score stays out.
 * Beside them a list `Liste.csv` (file, shelf mark, title, version line,
 * rights and license notice per file), the notes of the conductor per
 * passage of the links for choir members as `<shelf mark> <title> Hinweise.txt`,
 * and `LIESMICH.txt`. Someone of the choir uploads the files to the choir
 * system; the app uploads nothing to a provider.
 */
class HandoverService extends PrintMirrorService {
	public const HANDOVER_OBJECT_TYPE = 'choir_handover';
	public const HANDOVER_MIRROR = 'handover';
	public const LIST = 'Liste.csv';
	/** Suffix of the notes of a piece */
	public const NOTES = ' Hinweise.txt';

	/** Why a file was skipped, besides those of the print station (keys; the controller translates them) */
	public const NO_DOWNLOAD = 'no_download';
	public const NO_PRACTICE_AUDIO = 'no_practice_audio';
	/** MusicXML without confirmed public domain or a license for digital score files */
	public const NO_DIGITAL_SCORES = 'no_digital_scores';
	/** A file of the role "musicxml" that is no MusicXML score */
	public const NOT_MUSICXML = 'not_musicxml';

	/** Roles of archive files that go into the folder */
	private const ROLES = ['score_pdf', 'musicxml', 'audio'];

	/**
	 * @var array<string, array{line: array{shelfmark: string, title: string, composer: string, version: string, revision: string, since: string, rights: string, notice: string}}>
	 *                                                                                                                                                                              what Liste.csv says per file, by file name; filled by wanted()
	 */
	private array $listed = [];
	/** @var array<string, string> notes per piece: file name => text; filled by wanted() */
	private array $notes = [];

	public function __construct(
		IAppConfig $appConfig,
		IRootFolder $rootFolder,
		AppSettings $settings,
		ReleaseListService $releases,
		ArchiveFileMapper $archiveFiles,
		CopyMapper $copies,
		MirrorFileMapper $mirrorFiles,
		AuditLog $audit,
		Roles $roles,
		Modules $modules,
		NotificationService $notifications,
		IFilenameValidator $filenameValidator,
		IUserManager $userManager,
		IFactory $l10nFactory,
		ITimeFactory $time,
		IDateTimeZone $timeZone,
		LoggerInterface $logger,
		RunLock $runLock,
		private WorkVersionMapper $versions,
		private RightsPolicy $policy,
		private RightsService $rights,
		private LicenseMapper $licenses,
		private ExcerptLinkMapper $links,
		private StateService $states,
		private ScoreService $scores,
	) {
		parent::__construct($appConfig, $rootFolder, $settings, $releases, $archiveFiles, $copies, $mirrorFiles, $audit, $roles, $modules,
			$notifications, $filenameValidator, $userManager, $l10nFactory, $time, $timeZone, $logger, $runLock);
	}

	#[\Override]
	protected function prefix(): string {
		return 'choir_handover';
	}

	#[\Override]
	protected function otherPrefix(): string {
		return 'print_mirror';
	}

	#[\Override]
	protected function mirror(): string {
		return self::HANDOVER_MIRROR;
	}

	#[\Override]
	protected function objectType(): string {
		return self::HANDOVER_OBJECT_TYPE;
	}

	#[\Override]
	protected function module(): string {
		return Modules::CHOIR_HANDOVER;
	}

	#[\Override]
	protected function lockName(): string {
		return RunLock::CHOIR_HANDOVER;
	}

	#[\Override]
	protected function failedSubject(): string {
		return NotificationService::CHOIR_HANDOVER_FAILED;
	}

	#[\Override]
	protected function message(string $key): string {
		return match ($key) {
			'archive_first' => 'Please choose the archive folder first: the hand-over copies the files from it.',
			'inside_archive' => 'The hand-over folder must be neither the archive folder nor inside it. Please choose a folder outside the archive folder.',
			'none' => 'No hand-over folder has been chosen yet. An administrator chooses it in the settings of Cantoria.',
			'gone' => 'The hand-over folder is not accessible (deleted, moved or no longer shared). An administrator chooses it again in the settings.',
			'moved_inside' => 'The hand-over folder lies inside the archive folder, where the app never writes. An administrator chooses another folder in the settings.',
			'not_writable' => 'The account %s may not write into the hand-over folder. Give it write permission, or let an administrator choose another folder in the settings.',
			default => 'The account that chose the hand-over folder no longer exists. An administrator chooses the folder again in the settings.',
		};
	}

	/**
	 * The files of the versions on the list of the singers' portal; on the
	 * way, the lines of Liste.csv and the notes of each piece.
	 */
	#[\Override]
	protected function wanted(string $account): array {
		$archive = $this->settings->archiveFolder($account);
		$day = $this->rights->today($account);
		$l = $this->l10nOf($account);
		$byCopy = [];
		foreach ($this->archiveFiles->findFound() as $file) {
			$copyId = $file->getCopyId();
			if ($copyId !== null && in_array($file->getRole(), self::ROLES, true)) {
				$byCopy[$copyId][] = $file;
			}
		}
		$notesByCopy = $this->notesByCopy($day, $l);
		$this->listed = [];
		$this->notes = [];
		$entries = [];
		foreach ($this->releases->portal($account) as $row) {
			// "remove": still recorded in the portal, but no longer released
			if ($row['status'] === 'remove') {
				continue;
			}
			try {
				$version = $this->versions->find($row['versionId']);
			} catch (DoesNotExistException) {
				continue;
			}
			$line = $this->listLine($version, $row, $day, $l);
			foreach ($row['copies'] as $listed) {
				try {
					$copy = $this->copies->find($listed['id']);
				} catch (DoesNotExistException) {
					continue;
				}
				$base = self::fileBase($listed['shelfmark'], $row['title']);
				if (isset($notesByCopy[$copy->getId()])) {
					$this->notes[$base . self::NOTES] = implode("\r\n", [trim($listed['shelfmark'] . ' ' . ($row['title'] ?? '')), '',
						...$notesByCopy[$copy->getId()]]) . "\r\n";
				}
				$files = $byCopy[$copy->getId()] ?? [];
				usort($files, fn (ArchiveFile $a, ArchiveFile $b): int => [self::roleOrder($a), $a->getId()] <=> [self::roleOrder($b), $b->getId()]);
				$counts = [];
				foreach ($files as $file) {
					$name = $this->entryName($base, $file, $counts);
					$skip = $this->denial($version, $copy, $file, $day);
					$node = null;
					if ($skip === null && !$this->filenameValidator->isFilenameValid($name)) {
						$skip = self::INVALID_NAME;
					}
					if ($skip === null) {
						$found = $archive->getFirstNodeById($file->getFileId());
						$node = $found instanceof File ? $found : null;
						$skip = $node === null ? self::NOT_ACCESSIBLE
							: ($file->getRole() === 'score_pdf' && !ScanService::isPdf($node) ? self::NOT_PDF
								: ($file->getRole() === 'musicxml' && !$this->isScore($node) ? self::NOT_MUSICXML : null));
					}
					$entries[] = ['name' => $name, 'shelfmark' => $listed['shelfmark'], 'copyId' => $copy->getId(), 'archiveFile' => $file,
						'node' => $skip === null ? $node : null, 'skip' => $skip];
					if ($skip === null) {
						$this->listed[$name] = ['line' => ['shelfmark' => $listed['shelfmark']] + $line];
					}
				}
			}
		}
		return $entries;
	}

	/**
	 * The read-me, the list and the notes of each piece.
	 */
	#[\Override]
	protected function textFiles(string $account, int $files): array {
		$texts = parent::textFiles($account, $files);
		$texts[] = ['name' => self::LIST, 'text' => $this->listText($account), 'refresh' => true, 'copyId' => null];
		foreach ($this->notes as $name => $text) {
			$texts[] = ['name' => $name, 'text' => $text, 'refresh' => true, 'copyId' => null];
		}
		return $texts;
	}

	#[\Override]
	protected function readmeText(string $account, int $files): string {
		$l = $this->l10nOf($account);
		$now = $this->time->getDateTime('now', $this->timeZone->getTimeZone(false, $account));
		$lines = [
			$l->t('Hand-over to the choir system: folder of the music archive'),
			$l->t('As of %s', [$now->format('Y-m-d H:i')]),
			'',
			$l->n('Cantoria keeps this folder up to date. It holds %n file released for choir members: scans (PDF), MusicXML and practice recordings.',
				'Cantoria keeps this folder up to date. It holds %n files released for choir members: scans (PDF), MusicXML and practice recordings.', $files),
			$l->t('Liste.csv names the version, the revision and the license notice of every file; show the notice with the file in the choir system.'),
			$l->t('Upload the files to the choir system yourself and remove there what is no longer in this folder. Cantoria uploads nothing.'),
			$l->t('The app copies, replaces and removes only the files it wrote itself. Files changed or added by others stay as they are.'),
			$l->t('Questions: please ask the music librarians.'),
		];
		return implode("\r\n", $lines) . "\r\n";
	}

	/**
	 * Liste.csv: one row per file, in the language of the account.
	 */
	private function listText(string $account): string {
		$l = $this->l10nOf($account);
		$header = [$l->t('File'), $l->t('Shelf mark'), $l->t('Title'), $l->t('Composer'), $l->t('Version'), $l->t('Revision'),
			$l->t('Revision valid since'), $l->t('Rights status'), $l->t('License notice')];
		$rows = [];
		foreach ($this->listed as $name => ['line' => $line]) {
			$rows[] = [$name, $line['shelfmark'], $line['title'], $line['composer'], $line['version'], $line['revision'], $line['since'],
				$line['rights'], $line['notice']];
		}
		return CatalogCsv::writeForPeople($header, $rows);
	}

	/**
	 * What Liste.csv says about a version: title, composer, version line,
	 * rights status and the license notice (confirmed public domain, or the
	 * notices of the valid licenses for the singers' portal).
	 *
	 * @param array{title: ?string, composer: ?string, ...} $row
	 * @return array{title: string, composer: string, version: string, revision: string, since: string, rights: string, notice: string}
	 */
	private function listLine(WorkVersion $version, array $row, string $day, IL10N $l): array {
		$kind = (string)$version->getKind();
		$versionText = implode(', ', array_filter([isset(Fields::VERSION_KIND[$kind]) ? $l->t(Fields::VERSION_KIND[$kind]) : '',
			trim((string)$version->getArrangerText())], fn (string $part): bool => $part !== ''));
		$current = $this->states->isActive() ? $this->states->summary($version->getId(), null)['current'] : null;
		$status = (string)$version->getRightsStatus();
		$notices = [];
		$holders = [];
		foreach ($this->licenses->findByVersion($version->getId()) as $license) {
			if (($license->getValidFrom() !== null && $license->getValidFrom() > $day)
				|| ($license->getValidUntil() !== null && $license->getValidUntil() < $day)) {
				continue;
			}
			$notice = trim((string)$license->getShareNotice());
			if ($notice !== '') {
				$notices[] = $notice;
			} elseif (trim((string)$license->getHolder()) !== '') {
				$holders[] = $l->t('License: %s', [trim((string)$license->getHolder())]);
			}
		}
		$confirmedFree = $status === 'public_domain' && $version->isConfirmed();
		$notice = $confirmedFree ? $l->t('Public domain (confirmed)') : implode(' / ', array_values(array_unique($notices !== [] ? $notices : $holders)));
		return [
			'title' => $row['title'] ?? '',
			'composer' => $row['composer'] ?? '',
			'version' => $versionText,
			'revision' => $current === null ? '' : (string)$current['number'],
			'since' => $current === null ? '' : $current['valid_since'],
			'rights' => isset(Fields::RIGHTS_STATUS[$status]) ? $l->t(Fields::RIGHTS_STATUS[$status]) : $status,
			'notice' => $notice,
		];
	}

	/**
	 * The notes of the conductor per passage: the note of every link for
	 * choir members that is valid today, with its measures and voice.
	 *
	 * @return array<int, list<string>> lines by copy id
	 */
	private function notesByCopy(string $day, IL10N $l): array {
		$notes = [];
		foreach (array_reverse($this->links->findUnrevokedByCircle(ExcerptLink::CIRCLE_MEMBERS)) as $link) {
			$note = trim((string)$link->getNote());
			$expires = $link->getExpires();
			if ($note === '' || ($expires !== null && $expires < $day)) {
				continue;
			}
			$from = $link->getMeasureFrom();
			$to = $link->getMeasureTo();
			$place = $from === null || $to === null ? $l->t('Whole piece')
				: ($from === $to ? $l->t('m. %s', [$from]) : $l->t('m. %1$s–%2$s', [$from, $to]));
			$voice = trim((string)$link->getVoice());
			$notes[$link->getCopyId()][] = ($voice === '' ? $place : $place . ', ' . $voice) . ': ' . (preg_replace('/\s*\R\s*/u', ' ', $note) ?? $note);
		}
		return $notes;
	}

	/**
	 * Why a file of a released version stays out of the folder, or null.
	 */
	private function denial(WorkVersion $version, Copy $copy, ArchiveFile $file, string $day): ?string {
		if (RightsPolicy::isPreservationCopy($version, $copy)) {
			return self::PRESERVATION;
		}
		return match ($file->getRole()) {
			'audio' => $this->policy->handoverAudioDenial($version, $day, $copy) === null ? null : self::NO_PRACTICE_AUDIO,
			'musicxml' => $this->policy->handoverScoreDenial($version, $day, $copy) === null ? null : self::NO_DIGITAL_SCORES,
			default => $this->policy->memberScanDenial($version, $day, $copy) === null ? null : self::NO_DOWNLOAD,
		};
	}

	/**
	 * The rights reasons of the hand-over besides the preservation copy: no
	 * license for download, for practice audio and recordings, or for
	 * digital score files. A file the app wrote before such a license ended
	 * is removed from the folder (A22).
	 */
	#[\Override]
	protected function isRightsReason(string $reason): bool {
		return parent::isRightsReason($reason) || in_array($reason, [self::NO_DOWNLOAD, self::NO_PRACTICE_AUDIO, self::NO_DIGITAL_SCORES], true);
	}

	/**
	 * Whether a file of the role "musicxml" is a MusicXML score that the
	 * viewer would read (ScoreService::isScore).
	 */
	private function isScore(File $node): bool {
		try {
			return $this->scores->isScore(UserFiles::content($node, ScoreService::MAX_BYTES, new InvalidInputException('The file is not accessible.')));
		} catch (UserFacingException) {
			return false;
		}
	}

	/**
	 * MusicXML goes in cleaned, as the score of a link: checked, without
	 * encoder, notes, comments and processing instructions, uncompressed.
	 */
	#[\Override]
	protected function content(ArchiveFile $file, File $source) {
		if ($file->getRole() !== 'musicxml') {
			return parent::content($file, $source);
		}
		$content = UserFiles::content($source, ScoreService::MAX_BYTES, new InvalidInputException('The file is not accessible.'));
		if (!$this->scores->isScore($content)) {
			throw new InvalidInputException('This file is not a MusicXML score.');
		}
		return MusicXmlExcerpt::whole($this->scores->scoreText($content)['xml']);
	}

	/**
	 * `<shelf mark> <title>.pdf` (a second scan with " (2)"), the same with
	 * the extension of the MusicXML file, and for a recording its own name
	 * after the title, e.g. `2-1 Ännchen von Tharau - Tenor.mp3`.
	 *
	 * @param array<string, int> $counts names used so far
	 */
	private function entryName(string $base, ArchiveFile $file, array &$counts): string {
		// MusicXML is written uncompressed (content), also from an .mxl file
		$extension = $file->getRole() === 'musicxml' ? 'musicxml' : strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
		$stem = $file->getRole() === 'audio'
			? self::fileBase($base . ' - ' . pathinfo($file->getName(), PATHINFO_FILENAME), null)
			: $base;
		$key = $stem . '.' . $extension;
		$counts[$key] = ($counts[$key] ?? 0) + 1;
		return $stem . ($counts[$key] > 1 ? ' (' . $counts[$key] . ')' : '') . ($extension === '' ? '' : '.' . $extension);
	}

	private static function roleOrder(ArchiveFile $file): int {
		return (int)array_search($file->getRole(), self::ROLES, true);
	}
}
