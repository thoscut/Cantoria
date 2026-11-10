<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Db\AuditEntryMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Folder;
use OCA\Cantoria\Db\FolderLoan;
use OCA\Cantoria\Db\FolderLoanMapper;
use OCA\Cantoria\Db\FolderMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * The folder book (milestone 0.7, module "folder_book"): which choir folder
 * is out with whom since when, its contents and what is missing.
 *
 * - The catalog librarian and operations maintain it; conductor and readers
 *   read it. Contributors (tools) and choir members never see it.
 * - Folders are never deleted; a folder taken out of service is `retired`.
 * - A folder is out at most once at a time (also enforced by a unique index).
 * - The person is a Nextcloud account or a name. After the retention period
 *   (months after the return, set by the administration) the daily checks
 *   remove the name, the account and the notes of the loan, also from the
 *   loan's entries in the audit log; the loan stays as "former loan". This
 *   runs even when the module is switched off: switching off never makes
 *   anything less safe.
 * - A folder holding a copy that was retired, or a version that is no longer
 *   the valid one, is marked with a hint only; nothing changes by itself.
 *
 * @psalm-type FolderItem = array{copyId: int, count: int, shelfmark: string, title: ?string, outdated: ?string}
 * @psalm-type LoanRow = array{id: int, holderName: ?string, holderAccount: bool, anonymized: bool, issuedOn: string, issueNote: ?string,
 *     returnedOn: ?string, returnWear: ?string, returnMissing: ?string, returnNote: ?string}
 * @psalm-type FolderRow = array{id: int, label: string, voice: ?string, wear: string, missing: ?string, note: ?string, status: string,
 *     statusReason: ?string, statusSince: ?string, contents: list<FolderItem>, outdated: bool, loan: ?LoanRow, history: list<LoanRow>}
 */
class FolderBookService {
	public const MODULE = Modules::FOLDER_BOOK;
	/** Roles that read the folder book (not contributors, not choir members) */
	public const READERS = [Roles::LIBRARIAN, Roles::OPERATIONS, Roles::CONDUCTOR, Roles::READER];
	/** Roles that maintain it */
	public const MAINTAINERS = [Roles::LIBRARIAN, Roles::OPERATIONS];

	/** Object types of its audit entries */
	public const OBJECT_FOLDER = 'folder';
	public const OBJECT_LOAN = 'folder_loan';
	public const OBJECT_TYPES = [self::OBJECT_FOLDER, self::OBJECT_LOAN];

	/** Condition of a folder (English labels) */
	public const WEAR = ['good' => 'Good', 'worn' => 'Worn', 'damaged' => 'Damaged'];

	public const RETENTION_KEY = 'folder_book_retention_months';
	public const RETENTION_DEFAULT = 12;
	public const RETENTION_MIN = 1;
	public const RETENTION_MAX = 120;
	/**
	 * Months after the issue after which the personal data of a loan that
	 * never came back are removed (milestone 0.11): a claim for the return
	 * expires after three years (§ 195 BGB). The folder stays recorded as
	 * issued.
	 */
	public const OPEN_LOAN_MONTHS = 36;
	/** Fields of a loan that are personal data and removed after the retention period */
	public const PERSONAL_FIELDS = ['holder_uid', 'holder_name', 'issue_note', 'return_note'];
	/** What the audit log shows instead of a removed value */
	public const ANONYMIZED = '(anonymised)';

	/** Different copies in one folder */
	public const MAX_ITEMS = 200;
	public const MAX_COUNT = 999;
	private const MAX_TEXT = 4000;
	private const FOLDER_FIELDS = ['label', 'voice', 'contents', 'wear', 'missing', 'note', 'status', 'statusReason'];

	public function __construct(
		private IDBConnection $db,
		private FolderMapper $folders,
		private FolderLoanMapper $loans,
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private WorkMapper $works,
		private AuditLog $audit,
		private AuditEntryMapper $auditEntries,
		private Roles $roles,
		private RightsService $rights,
		private IUserManager $userManager,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
	) {
	}

	/**
	 * All folders with their contents, the current loan and the history,
	 * active folders first in natural order of their labels.
	 *
	 * @return array{folders: list<FolderRow>, retentionMonths: int, openLoanMonths: int, canEdit: bool}
	 */
	public function overview(string $uid): array {
		$this->roles->requireRole($uid, self::READERS);
		$copies = [];
		foreach ($this->copies->findAll() as $copy) {
			$copies[$copy->getId()] = $copy;
		}
		$versions = $this->versions->findAllById();
		$works = $this->works->findAllById();
		$validPerWork = [];
		foreach ($versions as $version) {
			if ($version->getValidSince() !== null) {
				$validPerWork[$version->getWorkId()] = $version->getId();
			}
		}
		$loansPerFolder = [];
		foreach ($this->loans->findAll() as $loan) {
			$loansPerFolder[$loan->getFolderId()][] = $loan;
		}

		$rows = [];
		$keys = [];
		foreach ($this->folders->findAll() as $folder) {
			$items = [];
			foreach (self::decodeContents($folder->getContents()) as $item) {
				$copy = $copies[$item['copyId']] ?? null;
				$version = $copy === null ? null : ($versions[$copy->getVersionId()] ?? null);
				$work = $version === null ? null : ($works[$version->getWorkId()] ?? null);
				$items[] = [
					'copyId' => $item['copyId'],
					'count' => $item['count'],
					'shelfmark' => $copy?->getShelfmark() ?? '?',
					'title' => $work?->getTitle(),
					'outdated' => self::outdated($copy, $version, $validPerWork),
				];
			}
			$history = array_map(self::loanRow(...), $loansPerFolder[$folder->getId()] ?? []);
			$open = null;
			foreach ($loansPerFolder[$folder->getId()] ?? [] as $loan) {
				if ($loan->getOpenFolderId() !== null) {
					$open = self::loanRow($loan);
				}
			}
			$rows[] = [
				'id' => $folder->getId(),
				'label' => $folder->getLabel(),
				'voice' => $folder->getVoice(),
				'wear' => $folder->getWear(),
				'missing' => $folder->getMissing(),
				'note' => $folder->getNote(),
				'status' => $folder->getStatus(),
				'statusReason' => $folder->getStatusReason(),
				'statusSince' => $folder->getStatusSince(),
				'contents' => $items,
				'outdated' => array_filter($items, fn (array $item): bool => $item['outdated'] !== null) !== [],
				'loan' => $open,
				'history' => $history,
			];
			$keys[] = [$folder->getStatus() === Folder::RETIRED ? 1 : 0, mb_strtolower($folder->getLabel())];
		}
		return [
			'folders' => Sort::byKeys($rows, $keys),
			'retentionMonths' => $this->retentionMonths(),
			'openLoanMonths' => self::OPEN_LOAN_MONTHS,
			'canEdit' => $this->roles->hasAnyRole($uid, self::MAINTAINERS),
		];
	}

	/**
	 * Why a copy in a folder no longer applies, or null: the copy was retired
	 * in the catalog, or another version of its work is now the valid one.
	 * Only a hint; the folder is not changed.
	 *
	 * @param array<int, int> $validPerWork work id => id of its valid version
	 */
	public static function outdated(?Copy $copy, ?WorkVersion $version, array $validPerWork): ?string {
		if ($copy === null || $copy->getStatus() === 'retired') {
			return 'retired';
		}
		$valid = $version === null ? null : ($validPerWork[$version->getWorkId()] ?? null);
		if ($valid !== null && $valid !== $version?->getId()) {
			return 'other_version_valid';
		}
		return null;
	}

	/**
	 * @param array<array-key, mixed> $data label, voice, contents, wear, missing, note (request data)
	 * @return array{id: int}
	 */
	public function createFolder(array $data, string $uid): array {
		$this->roles->requireRole($uid, self::MAINTAINERS);
		$values = $this->folderValues($data, null);
		if (!array_key_exists('label', $values)) {
			throw new InvalidInputException('Please give the folder a number or name.');
		}
		if (($values['status'] ?? Folder::ACTIVE) !== Folder::ACTIVE) {
			throw new InvalidInputException('A new folder starts in service.');
		}
		$folder = new Folder();
		$now = $this->time->getTime();
		$operation = $this->audit->newOperation();
		$this->db->beginTransaction();
		try {
			foreach ($values as $column => $value) {
				$folder->setValue($column, $value);
			}
			$folder->setStatus(Folder::ACTIVE);
			$folder->setCreated($now);
			$folder->setModified($now);
			$folder = $this->folders->insert($folder);
			foreach ($values as $column => $value) {
				if ($value !== null) {
					$this->audit->record($operation, $uid, self::OBJECT_FOLDER, $folder->getId(), 'folder_created', $column, null, $value);
				}
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return ['id' => $folder->getId()];
	}

	/**
	 * Changes a folder; also takes it out of service (`status: retired` with
	 * a reason) or puts it back (`status: active`).
	 *
	 * @param array<array-key, mixed> $data the fields to change (request data)
	 */
	public function updateFolder(int $id, array $data, string $uid): void {
		$this->roles->requireRole($uid, self::MAINTAINERS);
		$folder = $this->folder($id);
		$values = $this->folderValues($data, $folder);
		$status = $values['status'] ?? $folder->getStatus();
		if ($status === Folder::RETIRED && $folder->getStatus() !== Folder::RETIRED) {
			if (($values['status_reason'] ?? null) === null) {
				throw new InvalidInputException('Please give a reason for taking the folder out of service.');
			}
			if ($this->loans->findOpen($folder->getId()) !== null) {
				throw new InvalidInputException('This folder is out with someone. Take it back first, then take it out of service.');
			}
			$values['status_since'] = $this->rights->today($uid);
		} elseif ($status === Folder::ACTIVE && $folder->getStatus() === Folder::RETIRED) {
			// meanwhile another folder in service may carry its label
			$this->requireFreeLabel($values['label'] ?? $folder->getLabel(), $folder->getId());
			$values['status_reason'] = null;
			$values['status_since'] = $this->rights->today($uid);
		} elseif (!array_key_exists('status', $values)) {
			// The reason belongs to taking it out of service.
			unset($values['status_reason']);
		}
		$operation = $this->audit->newOperation();
		$this->db->beginTransaction();
		try {
			$this->apply($folder, $values, $uid, $operation, $status !== $folder->getStatus()
				? ($status === Folder::RETIRED ? 'folder_retired' : 'folder_reactivated')
				: 'folder_changed');
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Issues a folder to a person: a Nextcloud account (`holderUid`) or a
	 * name (`holderName`), on a day (`issuedOn`, default today) with an
	 * optional note.
	 *
	 * @param array<array-key, mixed> $data (request data)
	 * @return array{id: int}
	 */
	public function issue(int $folderId, array $data, string $uid): array {
		$this->roles->requireRole($uid, self::MAINTAINERS);
		self::refuseUnknown($data, ['holderUid', 'holderName', 'issuedOn', 'note']);
		$folder = $this->folder($folderId);
		if ($folder->getStatus() !== Folder::ACTIVE) {
			throw new InvalidInputException('This folder is out of service. Put it back into service before you issue it.');
		}
		if ($this->loans->findOpen($folder->getId()) !== null) {
			throw new InvalidInputException('This folder is already out with someone. Take it back first.');
		}
		$holderUid = Input::text($data['holderUid'] ?? null, 'Nextcloud account');
		$holderName = Input::text($data['holderName'] ?? null, 'Name');
		if (($holderUid === null) === ($holderName === null)) {
			throw new InvalidInputException('Please choose a Nextcloud account or enter the name of the person (one of both).');
		}
		if ($holderUid !== null) {
			$user = $this->userManager->get($holderUid);
			if ($user === null) {
				throw new InvalidInputException('This Nextcloud account does not exist. Please choose it from the list or enter the name instead.');
			}
			$holderUid = $user->getUID();
			$holderName = $user->getDisplayName();
		}
		$holderName = trim((string)$holderName);
		if (mb_strlen($holderName) > 255) {
			throw new InvalidInputException('The name may have at most 255 characters.');
		}
		$issuedOn = $this->day(Input::text($data['issuedOn'] ?? null, 'Handed out on'), 'Handed out on', $uid);
		$note = $this->longText(Input::text($data['note'] ?? null, 'Note'), 'Note');

		$loan = new FolderLoan();
		$now = $this->time->getTime();
		$loan->setFolderId($folder->getId());
		$loan->setOpenFolderId($folder->getId());
		$loan->setHolderUid($holderUid);
		$loan->setHolderName($holderName);
		$loan->setIssuedOn($issuedOn);
		$loan->setIssueNote($note);
		$loan->setCreated($now);
		$loan->setModified($now);
		$operation = $this->audit->newOperation();
		$this->db->beginTransaction();
		try {
			$loan = $this->loans->insert($loan);
			foreach (['holder_uid' => $holderUid, 'holder_name' => $holderName, 'issued_on' => $issuedOn, 'issue_note' => $note] as $field => $value) {
				if ($value !== null) {
					$this->audit->record($operation, $uid, self::OBJECT_LOAN, $loan->getId(), 'folder_issued', $field, null, $value);
				}
			}
			$this->audit->record($operation, $uid, self::OBJECT_FOLDER, $folder->getId(), 'folder_issued', 'loan', null, (string)$loan->getId());
			$this->db->commit();
		} catch (DbException $e) {
			$this->db->rollBack();
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				// Someone else issued it at the same moment.
				throw new InvalidInputException('This folder is already out with someone. Take it back first.');
			}
			throw $e;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return ['id' => $loan->getId()];
	}

	/**
	 * Takes a folder back: day (`returnedOn`, default today), condition
	 * (`wear`), missing sheets (`missing`) and a note. Condition and missing
	 * sheets become those of the folder.
	 *
	 * @param array<array-key, mixed> $data (request data)
	 */
	public function takeBack(int $loanId, array $data, string $uid): void {
		$this->roles->requireRole($uid, self::MAINTAINERS);
		self::refuseUnknown($data, ['returnedOn', 'wear', 'missing', 'note']);
		try {
			$loan = $this->loans->find($loanId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('This loan does not exist. Please reload the folder book.');
		}
		if ($loan->getReturnedOn() !== null) {
			throw new InvalidInputException('This folder has already been taken back. Please reload the folder book.');
		}
		$folder = $this->folder($loan->getFolderId());
		$returnedOn = $this->day(Input::text($data['returnedOn'] ?? null, 'Taken back on'), 'Taken back on', $uid);
		if ($returnedOn < $loan->getIssuedOn()) {
			throw new InvalidInputException('The folder cannot come back before it was handed out (%s). Please check the date.', [$loan->getIssuedOn()]);
		}
		$wear = self::wear($data['wear'] ?? null);
		$missing = $this->longText(Input::text($data['missing'] ?? null, 'Missing sheets'), 'Missing sheets');
		$note = $this->longText(Input::text($data['note'] ?? null, 'Note'), 'Note');

		$operation = $this->audit->newOperation();
		$this->db->beginTransaction();
		try {
			$values = ['returned_on' => $returnedOn, 'return_wear' => $wear, 'return_missing' => $missing, 'return_note' => $note];
			foreach ($values as $field => $value) {
				$loan->setValue($field, $value);
				if ($value !== null) {
					$this->audit->record($operation, $uid, self::OBJECT_LOAN, $loan->getId(), 'folder_taken_back', $field, null, $value);
				}
			}
			$loan->setOpenFolderId(null);
			$loan->setModified($this->time->getTime());
			$this->loans->update($loan);
			$this->apply($folder, ['wear' => $wear, 'missing' => $missing], $uid, $operation, 'folder_taken_back');
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Months after the return after which the personal data of a loan are
	 * removed.
	 */
	public function retentionMonths(): int {
		try {
			$months = $this->appConfig->getValueInt(Application::APP_ID, self::RETENTION_KEY, self::RETENTION_DEFAULT);
		} catch (\Throwable) {
			return self::RETENTION_DEFAULT;
		}
		return min(self::RETENTION_MAX, max(self::RETENTION_MIN, $months));
	}

	/**
	 * Sets the retention period (administrators, checked by the route).
	 */
	public function setRetentionMonths(int $months, string $uid): void {
		if ($months < self::RETENTION_MIN || $months > self::RETENTION_MAX) {
			throw new InvalidInputException('Please enter the retention period as a whole number of months between %1$s and %2$s.',
				[self::RETENTION_MIN, self::RETENTION_MAX]);
		}
		$old = $this->retentionMonths();
		if ($old !== $months) {
			$this->appConfig->setValueInt(Application::APP_ID, self::RETENTION_KEY, $months);
			$this->audit->record($this->audit->newOperation(), $uid, 'settings', null, 'folder_book_retention', 'retention_months', $old, $months);
		}
	}

	/**
	 * Removes the personal data of every loan returned at least the
	 * retention period before `$today` (daily checks): name, account and
	 * notes, in the loan and in its entries of the audit log. The loan stays;
	 * the audit log notes the anonymisation without the name.
	 *
	 * @param string $today the current day (YYYY-MM-DD)
	 */
	public function anonymizeExpired(string $today): void {
		$months = $this->retentionMonths();
		foreach ($this->loans->findToAnonymize(self::monthsBefore($today, $months)) as $loan) {
			$this->anonymizeLoan($loan, 'retention period of ' . $months . ' months after the return ended');
		}
		foreach ($this->loans->findOpenToAnonymize(self::monthsBefore($today, self::OPEN_LOAN_MONTHS)) as $loan) {
			$this->anonymizeLoan($loan, 'not returned ' . self::OPEN_LOAN_MONTHS . ' months after the issue');
		}
	}

	/**
	 * Removes the personal data of a loan, also from its audit entries, and
	 * records why (without a name). An open loan stays open.
	 */
	public function anonymizeLoan(FolderLoan $loan, string $reason): void {
		$operation = $this->audit->newOperation();
		$this->db->beginTransaction();
		try {
			foreach (self::PERSONAL_FIELDS as $field) {
				$loan->setValue($field, null);
			}
			$loan->setAnonymized($this->time->getTime());
			$loan->setModified($this->time->getTime());
			$this->loans->update($loan);
			$this->auditEntries->anonymizeFields(self::OBJECT_LOAN, $loan->getId(), self::PERSONAL_FIELDS, self::ANONYMIZED);
			$this->audit->record($operation, null, self::OBJECT_LOAN, $loan->getId(), 'anonymized', null, null, null, $reason);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * The day the given number of months before a day; the end of a shorter
	 * month stands for later days (31 March minus one month is 28 or 29
	 * February).
	 */
	public static function monthsBefore(string $day, int $months): string {
		$date = new \DateTimeImmutable($day);
		$first = $date->modify('first day of this month')->modify('-' . $months . ' months');
		$lastDay = (int)$first->format('t');
		return $first->format('Y-m-') . str_pad((string)min((int)$date->format('j'), $lastDay), 2, '0', STR_PAD_LEFT);
	}

	/**
	 * Copies and counts of a folder from the stored JSON.
	 *
	 * @return list<array{copyId: int, count: int}>
	 */
	public static function decodeContents(?string $json): array {
		if ($json === null || $json === '') {
			return [];
		}
		try {
			return self::contentItems(json_decode($json, true, 4, JSON_THROW_ON_ERROR));
		} catch (\JsonException) {
			return [];
		}
	}

	/**
	 * @return list<array{copyId: int, count: int}>
	 */
	private static function contentItems(mixed $data): array {
		if (!is_array($data)) {
			return [];
		}
		$items = [];
		foreach (array_keys($data) as $key) {
			$item = self::contentItem($data[$key]);
			if ($item !== null) {
				$items[] = $item;
			}
		}
		return $items;
	}

	/**
	 * @return array{copyId: int, count: int}|null
	 */
	private static function contentItem(mixed $item): ?array {
		if (is_array($item) && isset($item['copyId'], $item['count']) && is_int($item['copyId']) && is_int($item['count'])) {
			return ['copyId' => $item['copyId'], 'count' => $item['count']];
		}
		return null;
	}

	/**
	 * @return LoanRow
	 */
	private static function loanRow(FolderLoan $loan): array {
		return [
			'id' => $loan->getId(),
			'holderName' => $loan->getHolderName(),
			'holderAccount' => $loan->getHolderUid() !== null,
			'anonymized' => $loan->getAnonymized() !== null,
			'issuedOn' => $loan->getIssuedOn(),
			'issueNote' => $loan->getIssueNote(),
			'returnedOn' => $loan->getReturnedOn(),
			'returnWear' => $loan->getReturnWear(),
			'returnMissing' => $loan->getReturnMissing(),
			'returnNote' => $loan->getReturnNote(),
		];
	}

	/**
	 * No two folders in service share a number or name (upper and lower case
	 * count as the same); `$except` is the folder being changed.
	 *
	 * @throws InvalidInputException
	 */
	private function requireFreeLabel(string $label, ?int $except): void {
		foreach ($this->folders->findAll() as $other) {
			if ($other->getId() !== $except && $other->getStatus() === Folder::ACTIVE
				&& mb_strtolower($other->getLabel()) === mb_strtolower($label)) {
				throw new InvalidInputException('A folder in service is already called "%s". Please choose another number or name, or take the other folder out of service first.', [$label]);
			}
		}
	}

	/**
	 * The checked values of a folder from request data (column => value).
	 *
	 * @param array<array-key, mixed> $data
	 * @return array<string, string|null>
	 */
	private function folderValues(array $data, ?Folder $current): array {
		self::refuseUnknown($data, self::FOLDER_FIELDS);
		$values = [];
		if (array_key_exists('label', $data)) {
			$label = trim((string)Input::text($data['label'], 'Number or name'));
			if ($label === '') {
				throw new InvalidInputException('Please give the folder a number or name.');
			}
			if (mb_strlen($label) > 64) {
				throw new InvalidInputException('The number or name of the folder may have at most 64 characters.');
			}
			$this->requireFreeLabel($label, $current?->getId());
			$values['label'] = $label;
		}
		if (array_key_exists('voice', $data)) {
			$voice = Input::text($data['voice'], 'Voice');
			if ($voice !== null && mb_strlen(trim($voice)) > 64) {
				throw new InvalidInputException('The voice may have at most 64 characters.');
			}
			$values['voice'] = $voice === null ? null : trim($voice);
		}
		if (array_key_exists('contents', $data)) {
			$values['contents'] = $this->contents($data['contents']);
		}
		if (array_key_exists('wear', $data)) {
			$values['wear'] = self::wear($data['wear']);
		}
		if (array_key_exists('missing', $data)) {
			$values['missing'] = $this->longText(Input::text($data['missing'], 'Missing sheets'), 'Missing sheets');
		}
		if (array_key_exists('note', $data)) {
			$values['note'] = $this->longText(Input::text($data['note'], 'Note'), 'Note');
		}
		if (array_key_exists('statusReason', $data)) {
			$values['status_reason'] = $this->longText(Input::text($data['statusReason'], 'Reason'), 'Reason');
		}
		if (array_key_exists('status', $data)) {
			$status = Input::text($data['status'], 'Status');
			if (!in_array($status, [Folder::ACTIVE, Folder::RETIRED], true)) {
				throw new InvalidInputException('Unknown status: %s', [(string)$status]);
			}
			$values['status'] = $status;
		}
		return $values;
	}

	/**
	 * The contents as JSON: copies of the catalog with their number, each
	 * copy once (numbers of the same copy are added).
	 */
	private function contents(mixed $value): ?string {
		if ($value === null || $value === []) {
			return null;
		}
		if (!is_array($value) || !array_is_list($value)) {
			throw new InvalidInputException('The contents must be a list of copies with their number.');
		}
		$counts = [];
		foreach ($value as $item) {
			$copyId = is_array($item) ? filter_var($item['copyId'] ?? null, FILTER_VALIDATE_INT) : false;
			$count = is_array($item) ? filter_var($item['count'] ?? 1, FILTER_VALIDATE_INT) : false;
			if (!is_array($item) || $copyId === false || $count === false || array_diff(array_keys($item), ['copyId', 'count']) !== []) {
				throw new InvalidInputException('The contents must be a list of copies with their number.');
			}
			try {
				$copy = $this->copies->find($copyId);
			} catch (DoesNotExistException) {
				throw new InvalidInputException('Copy %s does not exist. Please choose the copies from the catalog.', [$copyId]);
			}
			$total = ($counts[$copy->getId()] ?? 0) + $count;
			if ($count < 1 || $total > self::MAX_COUNT) {
				throw new InvalidInputException('The number of %1$s must be between 1 and %2$s.', [$copy->getShelfmark(), self::MAX_COUNT]);
			}
			$counts[$copy->getId()] = $total;
		}
		if (count($counts) > self::MAX_ITEMS) {
			throw new InvalidInputException('A folder can hold at most %s different copies.', [self::MAX_ITEMS]);
		}
		$items = [];
		foreach ($counts as $copyId => $count) {
			$items[] = ['copyId' => $copyId, 'count' => $count];
		}
		return json_encode($items, JSON_THROW_ON_ERROR);
	}

	/**
	 * Writes changed values of a folder with one audit entry each.
	 *
	 * @param array<string, string|null> $values column => new value
	 */
	private function apply(Folder $folder, array $values, string $uid, string $operation, string $action): void {
		$changed = false;
		foreach ($values as $column => $value) {
			$old = $folder->getValue($column);
			if ($old === $value) {
				continue;
			}
			$folder->setValue($column, $value);
			$this->audit->record($operation, $uid, self::OBJECT_FOLDER, $folder->getId(), $action, $column, $old, $value);
			$changed = true;
		}
		if ($changed) {
			$folder->setModified($this->time->getTime());
			$this->folders->update($folder);
		}
	}

	private function folder(int $id): Folder {
		try {
			return $this->folders->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('This folder does not exist. Please reload the folder book.');
		}
	}

	private static function wear(mixed $value): string {
		$wear = is_string($value) ? $value : '';
		if (!isset(self::WEAR[$wear])) {
			throw new InvalidInputException('Please choose the condition of the folder: good, worn or damaged.');
		}
		return $wear;
	}

	/**
	 * A day of a loan: default today, a valid date and not in the future.
	 */
	private function day(?string $value, string $label, string $uid): string {
		$today = $this->rights->today($uid);
		$day = $value === null ? $today : trim($value);
		if (!FieldValue::isDate($day)) {
			throw new InvalidInputException('"%s" needs a valid date (YYYY-MM-DD).', [$label]);
		}
		if ($day > $today) {
			throw new InvalidInputException('The date lies in the future. Please enter the day the folder changed hands.');
		}
		return $day;
	}

	private function longText(?string $text, string $label): ?string {
		if ($text !== null && mb_strlen($text) > self::MAX_TEXT) {
			throw new InvalidInputException('"%1$s" may have at most %2$s characters.', [$label, self::MAX_TEXT]);
		}
		return $text === null ? null : trim($text);
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @param list<string> $allowed
	 */
	private static function refuseUnknown(array $data, array $allowed): void {
		foreach (array_keys($data) as $field) {
			if (!in_array((string)$field, $allowed, true)) {
				throw new InvalidInputException('Unknown field: %s', [(string)$field]);
			}
		}
	}
}
