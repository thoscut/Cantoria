<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Db\AccountRecordFinder;
use OCA\Cantoria\Db\AuditEntryMapper;
use OCA\Cantoria\Db\FolderLoan;
use OCA\Cantoria\Db\FolderLoanMapper;
use OCA\Cantoria\Db\FolderMapper;
use OCA\Cantoria\Db\PersonMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * Access and erasure for one person (milestone 0.11, Art. 15 and 17 GDPR),
 * by Nextcloud account or by name: the loans of the folder book, the audit
 * entries made by them, persons of the same name in the register of
 * persons, and every row that records them as who created, decided,
 * answered, checked or set something (ACCOUNT_FIELDS: tasks, links,
 * proposals, review points, …). For self-hosted instances via `occ cantoria:privacy-report`
 * and `cantoria:privacy-erase`; a deleted Nextcloud account is handled
 * automatically (forgetAccount).
 *
 * Erasure removes the personal data of the loans (also from their audit
 * entries; an open loan stays open, without the holder) and the account and
 * name from the audit entries made by the person and from the values of the
 * audit log that name them (AUDIT_ACCOUNT_VALUES, AUDIT_NAME_VALUES: who
 * confirmed, checked or set something, R B27); what was changed stays.
 * Persons of the register (composers, lyricists, …) are only reported: their
 * names are needed for the rights; life dates can be removed in the app.
 *
 * @psalm-type PrivacyReport = array{query: string, loans: list<array<string, mixed>>, auditEntries: list<array<string, mixed>>, persons: list<array<string, mixed>>, records: list<array{table: string, id: int, fields: array<string, string>}>}
 */
class PrivacyService {
	/** The name in the audit log after an erasure */
	public const ERASED = '(anonymised)';
	/**
	 * App config: years after which audit entries lose account and name
	 * (0: never). Ten years is the longest period of limitation for claims
	 * of rights holders (§ 102 UrhG with § 199 (3) and (4) BGB); keeping the
	 * record that long is the exception of Art. 17 (3) (e) GDPR. After it,
	 * the role is enough to show which function made a change.
	 */
	public const RETENTION_KEY = 'audit_retention_years';
	public const RETENTION_YEARS = 10;

	/**
	 * Columns that hold an account or the name of one, per table, besides
	 * the folder book and the audit log (PrivacyTest checks that every such
	 * field of an entity is listed here).
	 */
	public const ACCOUNT_FIELDS = [
		'cantoria_versions' => ['valid_set_by', 'confirmed_by', 'confirmed_by_name'],
		'cantoria_states' => ['created_by', 'created_by_name', 'valid_set_by'],
		'cantoria_proposals' => ['created_by', 'decided_by'],
		'cantoria_tasks' => ['created_by', 'answered_by', 'answered_by_name', 'closed_by'],
		'cantoria_links' => ['created_by'],
		'cantoria_events' => ['created_by'],
		'cantoria_persons' => ['checked_by'],
		'cantoria_merges' => ['created_by', 'undone_by'],
		'cantoria_review_reports' => ['created_by'],
		'cantoria_review_points' => ['created_by', 'decided_by', 'decided_by_name'],
	];

	/**
	 * Fields of the audit log whose old and new values hold an account (the
	 * account columns of ACCOUNT_FIELDS; a confirmation records who
	 * confirmed as its new value, RightsService::confirm).
	 */
	public const AUDIT_ACCOUNT_VALUES = ['confirmed_by', 'created_by', 'decided_by', 'answered_by', 'closed_by', 'checked_by', 'undone_by'];
	/**
	 * Fields of the audit log whose values hold a display name (the name
	 * columns of ACCOUNT_FIELDS; `valid_set_by` stores the display name,
	 * ValidVersionService). PrivacyTest checks that both lists together are
	 * the columns of ACCOUNT_FIELDS.
	 */
	public const AUDIT_NAME_VALUES = ['confirmed_by_name', 'created_by_name', 'answered_by_name', 'decided_by_name', 'valid_set_by'];
	/** Fields of the folder book in the audit log, only searched for the report (the folder book anonymises them itself) */
	private const AUDIT_LOAN_VALUES = ['holder_uid', 'holder_name'];

	public function __construct(
		private FolderLoanMapper $loans,
		private FolderMapper $folders,
		private FolderBookService $folderBook,
		private AuditEntryMapper $auditEntries,
		private PersonMapper $persons,
		private AuditLog $audit,
		private AccountRecordFinder $records,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Everything the app stores about an account or a name.
	 *
	 * @return PrivacyReport
	 */
	public function report(string $who): array {
		$labels = [];
		foreach ($this->folders->findAll() as $folder) {
			$labels[$folder->getId()] = $folder->getLabel();
		}
		$loans = [];
		foreach ($this->loansOf($who) as $loan) {
			$loans[] = ['folder' => $labels[$loan->getFolderId()] ?? null] + $loan->jsonSerialize();
		}
		// Entries made by the account or name, and entries whose values name it
		$found = [];
		foreach ($this->auditEntries->findByActor($who, $who) as $entry) {
			$found[$entry->getId()] = $entry;
		}
		$fields = [...self::AUDIT_ACCOUNT_VALUES, ...self::AUDIT_NAME_VALUES, ...self::AUDIT_LOAN_VALUES];
		foreach ($this->auditEntries->findWithValues($fields) as $entry) {
			if (self::same($entry->getOldValue(), $who) || self::same($entry->getNewValue(), $who)) {
				$found[$entry->getId()] = $entry;
			}
		}
		ksort($found);
		$entries = array_values(array_map(fn ($entry): array => $entry->jsonSerialize(), $found));
		$persons = array_values(array_map(fn ($person): array => $person->jsonSerialize(),
			array_filter($this->persons->findAll(), fn ($person): bool => self::same($person->getName(), $who))));
		return ['query' => $who, 'loans' => $loans, 'auditEntries' => $entries, 'persons' => $persons,
			'records' => $this->records->find(self::ACCOUNT_FIELDS, $who)];
	}

	/**
	 * Erases the personal data of an account or a name; records the erasure
	 * without the name.
	 *
	 * @return array{loans: int, auditEntries: int, persons: int}
	 */
	public function erase(string $who): array {
		$loans = $this->loansOf($who);
		foreach ($loans as $loan) {
			$this->folderBook->anonymizeLoan($loan, 'erased on request');
		}
		// The display names under which the account made entries also leave the values
		$names = [$who];
		foreach ($this->auditEntries->findByActor($who, $who) as $entry) {
			if ($entry->getUserId() === $who && $entry->getUserName() !== null) {
				$names[] = $entry->getUserName();
			}
		}
		$values = $this->eraseFromValues([...self::AUDIT_ACCOUNT_VALUES, ...self::AUDIT_NAME_VALUES], array_values(array_unique($names)));
		$entries = $this->auditEntries->removeAccount($who, self::ERASED) + $this->auditEntries->replaceName($who, self::ERASED) + $values;
		$persons = count(array_filter($this->persons->findAll(), fn ($person): bool => self::same($person->getName(), $who)));
		$this->audit->record($this->audit->newOperation(), null, 'privacy', null, 'erased', null, null,
			['loans' => count($loans), 'auditEntries' => $entries]);
		return ['loans' => count($loans), 'auditEntries' => $entries, 'persons' => $persons];
	}

	/**
	 * A Nextcloud account was deleted: returned loans of the account lose
	 * their personal data; an open loan keeps the name of the holder (the
	 * folder is still out; the display name if no name was written) but no
	 * longer the account, until its own term ends. Audit entries lose the
	 * account, also in their values (who confirmed or checked something),
	 * and keep the display name of who made a change (the record of the
	 * function holders).
	 */
	public function forgetAccount(string $uid, string $displayName): void {
		foreach ($this->loans->findAll() as $loan) {
			if ($loan->getHolderUid() !== $uid || $loan->getAnonymized() !== null) {
				continue;
			}
			if ($loan->getReturnedOn() !== null) {
				$this->folderBook->anonymizeLoan($loan, 'account deleted');
				continue;
			}
			$loan->setHolderUid(null);
			if (trim((string)$loan->getHolderName()) === '') {
				$loan->setHolderName($displayName);
			}
			$this->loans->update($loan);
			$this->auditEntries->anonymizeFields(FolderBookService::OBJECT_LOAN, $loan->getId(), ['holder_uid'], FolderBookService::ANONYMIZED);
			$this->audit->record($this->audit->newOperation(), null, FolderBookService::OBJECT_LOAN, $loan->getId(), 'changed', 'holder_uid',
				FolderBookService::ANONYMIZED, null, 'account deleted');
		}
		$this->auditEntries->removeAccount($uid, null);
		$this->eraseFromValues(self::AUDIT_ACCOUNT_VALUES, [$uid]);
	}

	/**
	 * The retention period of the audit log (daily): entries older than
	 * RETENTION_KEY years keep their roles, not account and name, neither
	 * of who made them nor in their values (who confirmed, checked or set
	 * something, R B27). Recorded without a name when something changed.
	 *
	 * @return int the number of entries changed
	 * @psalm-suppress PossiblyUnusedReturnValue the daily job needs no count: the change itself is recorded in the audit log
	 */
	public function applyRetention(): int {
		$years = $this->appConfig->getValueInt(Application::APP_ID, self::RETENTION_KEY, self::RETENTION_YEARS);
		if ($years <= 0) {
			return 0;
		}
		$before = $this->time->getDateTime('@' . $this->time->getTime())->modify('-' . $years . ' years')->getTimestamp();
		$changed = $this->auditEntries->pseudonymizeBefore($before, [...self::AUDIT_ACCOUNT_VALUES, ...self::AUDIT_NAME_VALUES], self::ERASED);
		if ($changed > 0) {
			$this->audit->record($this->audit->newOperation(), null, 'privacy', null, 'pseudonymised', null, null,
				['auditEntries' => $changed, 'years' => $years]);
		}
		return $changed;
	}

	/**
	 * Replaces the old and new values of the given fields that name one of
	 * $whos (whatever the case) with ERASED.
	 *
	 * @param list<string> $fields
	 * @param list<string> $whos accounts or names
	 * @return int the number of entries changed
	 */
	private function eraseFromValues(array $fields, array $whos): int {
		$changed = 0;
		$matches = function (?string $value) use ($whos): bool {
			foreach ($whos as $who) {
				if (self::same($value, $who)) {
					return true;
				}
			}
			return false;
		};
		foreach ($this->auditEntries->findWithValues($fields) as $entry) {
			$old = $entry->getOldValue();
			$new = $entry->getNewValue();
			if (!$matches($old) && !$matches($new)) {
				continue;
			}
			$this->auditEntries->replaceValues($entry->getId(), $matches($old) ? self::ERASED : $old, $matches($new) ? self::ERASED : $new);
			$changed++;
		}
		return $changed;
	}

	/**
	 * Loans with personal data of an account or a name.
	 *
	 * @return list<FolderLoan>
	 */
	private function loansOf(string $who): array {
		return array_values(array_filter($this->loans->findAll(), fn (FolderLoan $loan): bool => $loan->getAnonymized() === null
			&& ($loan->getHolderUid() === $who || self::same($loan->getHolderName(), $who))));
	}

	private static function same(?string $name, string $who): bool {
		return $name !== null && mb_strtolower(trim($name)) === mb_strtolower(trim($who));
	}
}
