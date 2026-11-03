<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\ArchiveFile;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\ProposalMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\UserFacingException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * The rights notice of a MusicXML file (`<identification><rights>`,
 * milestone 0.8), read by the file sync and shown with the file as
 * unchecked information.
 *
 * Guideline 1: a notice never sets a rights status. When it names the
 * public domain or a license and the proposals inbox is on, it becomes at
 * most a proposal for the catalog librarian: the rights status "public
 * domain", or the license text for an empty field "License". An open
 * proposal with the same value is not made twice.
 *
 * The file is only read (guideline 4). A file that cannot be read is marked
 * as read without a notice, so that the next sync does not try again until
 * it changes.
 */
class RightsNoticeService {
	/** MusicXML files read per file sync; the rest follows with the next one */
	public const MAX_FILES = 300;
	/** Longest notice stored (the column holds 1000 characters) */
	public const MAX_LENGTH = 1000;

	public const PUBLIC_DOMAIN = 'public_domain';
	public const LICENSE = 'license';

	public function __construct(
		private ScoreService $scores,
		private ArchiveFileMapper $files,
		private ProposalService $proposals,
		private ProposalMapper $proposalMapper,
		private Modules $modules,
		private ITimeFactory $time,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reads the notices of MusicXML files that were not read yet (new or
	 * changed files), at most MAX_FILES.
	 *
	 * @param list<array{file: ArchiveFile, node: File, version: WorkVersion}> $candidates
	 * @return array{read: int, found: int, proposals: int, failed: int, pending: int, switchedOff: bool}
	 */
	public function readAll(array $candidates, string $uid): array {
		$counts = ['read' => 0, 'found' => 0, 'proposals' => 0, 'failed' => 0, 'pending' => max(0, count($candidates) - self::MAX_FILES),
			'switchedOff' => false];
		$proposing = $this->modules->isEnabled('proposals');
		foreach (array_slice($candidates, 0, self::MAX_FILES) as ['file' => $file, 'node' => $node, 'version' => $version]) {
			try {
				$notice = $this->read($node);
			} catch (\Throwable $e) {
				// The id of the file, not its path, which may name the account
				$this->logger->info('Cantoria: rights notice of archive file ' . $file->getId() . ' not read: ' . $e->getMessage());
				$notice = null;
				$counts['failed']++;
			}
			$file->setRightsNotice($notice);
			$file->setRightsReadAt($this->time->getTime());
			$this->files->update($file);
			$counts['read']++;
			if ($notice !== null) {
				$counts['found']++;
				if ($proposing) {
					$counts['proposals'] += $this->propose($version, $file, $notice, $uid);
				}
			}
		}
		return $counts;
	}

	/**
	 * The notice of a MusicXML file (plain or compressed), or null.
	 *
	 * @throws UserFacingException when the file cannot be read or is no score
	 */
	public function read(File $node): ?string {
		$content = UserFiles::content($node, ScoreService::MAX_BYTES, new InvalidInputException('The file cannot be read.'));
		return self::extract($this->scores->scoreText($content)['xml']);
	}

	/**
	 * The text of the `<rights>` elements in `<identification>` of a
	 * MusicXML document, white space collapsed, several joined with " · ";
	 * null when there is none. Documents with an internal DTD subset anywhere
	 * in the text are not read, however long the prolog before it (no
	 * entities are ever expanded, nothing is loaded).
	 */
	public static function extract(string $xml): ?string {
		if (preg_match('/<!DOCTYPE[^>\[]*\[/i', $xml) === 1) {
			return null;
		}
		$reader = new \XMLReader();
		$previous = libxml_use_internal_errors(true);
		$texts = [];
		try {
			if (!$reader->XML($xml, null, LIBXML_NONET)) {
				return null;
			}
			$section = '';
			while ($reader->read()) {
				if ($reader->nodeType !== \XMLReader::ELEMENT) {
					continue;
				}
				if ($reader->depth === 1) {
					$section = $reader->localName;
					// The header ends where the music begins.
					if (in_array($section, ['part-list', 'part', 'measure'], true)) {
						break;
					}
				} elseif ($reader->depth === 2 && $section === 'identification' && $reader->localName === 'rights') {
					$text = trim((string)preg_replace('/\s+/u', ' ', $reader->readString()));
					if ($text !== '') {
						$texts[] = $text;
					}
				}
			}
		} finally {
			$reader->close();
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		$notice = implode(' · ', array_unique($texts));
		return $notice === '' ? null : mb_substr($notice, 0, self::MAX_LENGTH);
	}

	/**
	 * What a notice names: the public domain (also CC0), a license, or
	 * neither (e.g. only a copyright line).
	 *
	 * @return self::PUBLIC_DOMAIN|self::LICENSE|null
	 */
	public static function classify(string $notice): ?string {
		$text = mb_strtolower($notice);
		if (preg_match('/public[\s-]*domain|gemeinfrei|\bcc[\s-]*0\b|\bcc[\s-]*zero\b|domaine public|pubblico dominio|dominio p[uú]blico/u', $text) === 1) {
			return self::PUBLIC_DOMAIN;
		}
		if (preg_match('/creative[\s-]*commons|\bcc[\s-]*by\b|licen[cs]e|lizenz|\bgpl\b/u', $text) === 1) {
			return self::LICENSE;
		}
		return null;
	}

	/**
	 * At most one proposal for the version of the file.
	 *
	 * @return int 1 when a proposal was made
	 */
	private function propose(WorkVersion $version, ArchiveFile $file, string $notice, string $uid): int {
		[$field, $value] = match (self::classify($notice)) {
			self::PUBLIC_DOMAIN => $version->getRightsStatus() === 'public_domain' ? [null, null] : ['rights_status', 'public_domain'],
			self::LICENSE => trim((string)$version->getLicense()) === '' ? ['license', $notice] : [null, null],
			default => [null, null],
		};
		if ($field === null) {
			return 0;
		}
		foreach ($this->proposalMapper->findOpenForObject(Fields::VERSION, $version->getId()) as $open) {
			if ($open->getField() === $field && $open->value() === $value) {
				return 0;
			}
		}
		$reason = $this->l->t('Rights notice in the MusicXML file "%1$s", not checked: %2$s', [$file->getName(), $notice]);
		try {
			$this->proposals->propose(Fields::VERSION, $version->getId(), [$field => $value], $uid, $reason, $this->l->t('MusicXML rights notice'));
			return 1;
		} catch (UserFacingException $e) {
			// e.g. an account without a role that may propose (file sync by occ)
			$this->logger->info('Cantoria: no proposal from the rights notice of archive file ' . $file->getId() . ': ' . $e->getMessage());
			return 0;
		}
	}
}
