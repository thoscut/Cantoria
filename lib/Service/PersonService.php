<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\Contribution;
use OCA\Cantoria\Db\ContributionMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Person;
use OCA\Cantoria\Db\PersonMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;

/**
 * Register of persons and their contributions to versions, and the proposal
 * for the end of protection derived from them (milestone 0.5).
 *
 * Only the catalog librarian writes; every role reads. A person with
 * contributions cannot be deleted. Changing name, identifiers, life dates or
 * identification removes the "checked" stamp, because the check referred to
 * the old data. Every change is recorded in the audit log; contributions are
 * logged on the version, so they appear in its history.
 *
 * The protection proposal is display only: it never changes a rights status.
 *
 * @psalm-import-type Proposal from ProtectionTerm
 * @psalm-type VersionContext = array{title: ?string, composer: ?string, workId: int, copies: list<array{id: int, shelfmark: string, status: string}>}
 * @psalm-type ReviewRow = array{versionId: int, kind: string, rightsStatus: ?string, confirmed: bool, protection: Proposal, title: ?string, composer: ?string, workId: int, copies: list<array{id: int, shelfmark: string, status: string}>}
 */
class PersonService {
	private const FIELDS = ['name', 'gnd', 'wikidata', 'born', 'born_precision', 'died', 'died_precision', 'life_dates_text',
		'source', 'identification', 'notes'];
	/** Changing one of these removes the check stamp */
	private const CHECKED_FIELDS = ['name', 'gnd', 'wikidata', 'born', 'born_precision', 'died', 'died_precision', 'identification'];

	public function __construct(
		private PersonMapper $persons,
		private ContributionMapper $contributions,
		private WorkVersionMapper $versions,
		private WorkMapper $works,
		private CopyMapper $copies,
		private AuditLog $audit,
		private Roles $roles,
		private RightsService $rights,
		private IUserManager $userManager,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return list<array<string, string|int|bool|null>> persons with the number of contributions
	 */
	public function list(?string $query = null): array {
		$counts = $this->contributions->countPerPerson();
		$needle = mb_strtolower(trim((string)$query));
		$rows = [];
		foreach ($this->persons->findAll() as $person) {
			if ($needle !== '' && !str_contains(mb_strtolower($person->getName()), $needle)) {
				continue;
			}
			$rows[] = $person->jsonSerialize() + [
				'contributions' => $counts[$person->getId()] ?? 0,
				'blocking' => ProtectionTerm::blockingReason($person),
			];
		}
		return $rows;
	}

	/**
	 * @return array{person: array<string, mixed>, contributions: list<array<string, mixed>>}
	 */
	public function detail(int $personId): array {
		$person = $this->load($personId);
		$context = $this->versionContext();
		$rows = [];
		$keys = [];
		foreach ($this->contributions->findByPerson($personId) as $contribution) {
			$info = $context[$contribution->getVersionId()] ?? ['title' => null, 'composer' => null, 'copies' => []];
			$rows[] = $contribution->jsonSerialize() + $info;
			$keys[] = [$info['copies'][0]['shelfmark'] ?? ''];
		}
		return ['person' => $this->serialize($person), 'contributions' => Sort::byKeys($rows, $keys)];
	}

	/**
	 * @param array<array-key, mixed> $data field => value (request data)
	 */
	public function create(array $data, string $uid): Person {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		$person = new Person();
		$person->setValue('born_precision', 'unknown');
		$person->setValue('died_precision', 'unknown');
		$person->setValue('identification', 'uncertain');
		$this->assign($person, $data, $uid);
		// assign() only checks a name that is sent; a new person needs one.
		if (trim($person->getName()) === '') {
			throw new InvalidInputException('Please enter a name.');
		}
		$person->setCreated($this->time->getTime());
		$person->setModified($this->time->getTime());
		$person = $this->persons->insert($person);
		$this->audit->record($this->audit->newOperation(), $uid, 'person', $person->getId(), 'created', 'name', null, $person->getName());
		return $person;
	}

	/**
	 * @param array<array-key, mixed> $data field => value (request data)
	 */
	public function update(int $personId, array $data, string $uid, ?string $reason = null): Person {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		$person = $this->load($personId);
		$before = $person->jsonSerialize();
		$this->assign($person, $data, $uid);
		$changed = array_values(array_filter(self::FIELDS, fn (string $f): bool => $before[$f] !== $person->getValue($f)));
		if ($changed === []) {
			return $person;
		}
		if (array_intersect($changed, self::CHECKED_FIELDS) !== []) {
			$person->setValue('checked_by', null);
			$person->setValue('checked_at', null);
		}
		$operation = $this->audit->newOperation();
		foreach (array_merge(self::FIELDS, ['checked_by', 'checked_at']) as $field) {
			if ($before[$field] !== $person->getValue($field)) {
				$this->audit->record($operation, $uid, 'person', $personId, 'changed', $field, $before[$field], $person->getValue($field), $reason);
			}
		}
		$person->setModified($this->time->getTime());
		return $this->persons->update($person);
	}

	/**
	 * Records that the librarian has checked name, identification and life
	 * dates of the person.
	 */
	public function markChecked(int $personId, string $uid): Person {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		$person = $this->load($personId);
		$operation = $this->audit->newOperation();
		$this->audit->record($operation, $uid, 'person', $personId, 'checked', 'checked_by', $person->getValue('checked_by'), $uid);
		$person->setValue('checked_by', $uid);
		$person->setValue('checked_at', $this->rights->today($uid));
		$person->setModified($this->time->getTime());
		return $this->persons->update($person);
	}

	/**
	 * Deletes a person without contributions (e.g. a duplicate or a wrongly
	 * imported name). A person involved in versions is never deleted.
	 */
	public function delete(int $personId, string $uid): void {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		$person = $this->load($personId);
		$count = count($this->contributions->findByPerson($personId));
		if ($count > 0) {
			throw new InvalidInputException('This person is involved in %s versions and cannot be deleted. Remove the contributions first.', [$count]);
		}
		$this->persons->delete($person);
		$this->audit->record($this->audit->newOperation(), $uid, 'person', $personId, 'deleted', 'name', $person->getName(), null);
	}

	/**
	 * @return list<array<string, string|int|bool|null|array<string, string|int|bool|null>>> contributions of a version, each with the person
	 */
	public function forVersion(int $versionId): array {
		$order = array_flip(Contribution::ROLES);
		$rows = [];
		$keys = [];
		foreach ($this->contributions->findByVersion($versionId) as $contribution) {
			try {
				$person = $this->persons->find($contribution->getPersonId());
			} catch (DoesNotExistException) {
				continue;
			}
			$rows[] = $contribution->jsonSerialize() + ['person' => $person->jsonSerialize()];
			$keys[] = [$order[$contribution->getRole()] ?? 99, $contribution->getId()];
		}
		return Sort::byKeys($rows, $keys);
	}

	public function addContribution(int $versionId, int $personId, string $role, bool $textForComposition, string $uid): Contribution {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		$this->requireVersion($versionId);
		$person = $this->load($personId);
		self::checkRole($role);
		foreach ($this->contributions->findByVersion($versionId) as $existing) {
			if ($existing->getPersonId() === $personId && $existing->getRole() === $role) {
				throw new InvalidInputException('This person already has this role in this version.');
			}
		}
		$contribution = new Contribution();
		$contribution->setVersionId($versionId);
		$contribution->setPersonId($personId);
		$contribution->setRole($role);
		$contribution->setTextForComposition($textForComposition);
		$contribution->setCreated($this->time->getTime());
		$contribution = $this->contributions->insert($contribution);
		$this->audit->record($this->audit->newOperation(), $uid, Fields::VERSION, $versionId, 'contribution_added', 'contribution', null,
			self::describe($person, $role));
		return $contribution;
	}

	/**
	 * @param array<array-key, mixed> $data `role` and/or `text_for_composition` (request data)
	 */
	public function updateContribution(int $contributionId, array $data, string $uid): Contribution {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		$contribution = $this->loadContribution($contributionId);
		$person = $this->load($contribution->getPersonId());
		$before = self::describe($person, $contribution->getRole()) . ($contribution->getTextForComposition() === true ? ' §65' : '');
		foreach (Input::values($data) as $field => $value) {
			if ($field === 'role') {
				$contribution->setRole(self::checkRole(is_string($value) ? $value : ''));
			} elseif ($field === 'text_for_composition') {
				$contribution->setTextForComposition(Input::bool($value, 'Text written for this composition'));
			} else {
				throw new InvalidInputException('Unknown field: %s', [$field]);
			}
		}
		$after = self::describe($person, $contribution->getRole()) . ($contribution->getTextForComposition() === true ? ' §65' : '');
		if ($before !== $after) {
			$this->audit->record($this->audit->newOperation(), $uid, Fields::VERSION, $contribution->getVersionId(), 'contribution_changed',
				'contribution', $before, $after);
			$contribution = $this->contributions->update($contribution);
		}
		return $contribution;
	}

	public function removeContribution(int $contributionId, string $uid): void {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		$contribution = $this->loadContribution($contributionId);
		$name = '#' . $contribution->getPersonId();
		try {
			$name = self::describe($this->persons->find($contribution->getPersonId()), $contribution->getRole());
		} catch (DoesNotExistException) {
		}
		$this->contributions->delete($contribution);
		$this->audit->record($this->audit->newOperation(), $uid, Fields::VERSION, $contribution->getVersionId(), 'contribution_removed',
			'contribution', $name, null);
	}

	/**
	 * The protection proposal of one version.
	 *
	 * @return Proposal
	 */
	public function protection(int $versionId, int $currentYear): array {
		$persons = [];
		$roles = [];
		foreach ($this->contributions->findByVersion($versionId) as $contribution) {
			try {
				$persons[$contribution->getPersonId()] ??= $this->persons->find($contribution->getPersonId());
				$roles[$contribution->getPersonId()][] = $contribution->getRole();
			} catch (DoesNotExistException) {
			}
		}
		try {
			$version = $this->versions->find($versionId);
		} catch (DoesNotExistException) {
			$version = null;
		}
		return self::propose($version, $persons, $roles, $currentYear);
	}

	/**
	 * The proposal of a version with its special cases (§§ 70, 71 UrhG):
	 * persons involved only as editors count with the special term instead.
	 *
	 * @param array<int, Person> $persons person id => person
	 * @param array<int, list<string>> $roles person id => roles in the version
	 * @return Proposal
	 */
	private static function propose(?WorkVersion $version, array $persons, array $roles, int $currentYear): array {
		$cases = array_values(array_filter([
			$version?->getScientificEdition() === true ? ProtectionTerm::SCIENTIFIC_EDITION : null,
			$version?->getPosthumousWork() === true ? ProtectionTerm::POSTHUMOUS_WORK : null,
		]));
		$editors = array_keys(array_filter($roles, fn (array $list): bool => array_diff($list, [ProtectionTerm::EDITOR_ROLE]) === []));
		return ProtectionTerm::propose(array_values($persons), $currentYear, $cases, $version?->getFirstPublishedYear(), $editors,
			array_values(array_unique(array_merge([], ...array_values($roles)))), $version?->getMelodyOrigin(), $version?->getTextOrigin());
	}

	/**
	 * Protection proposals of all versions, keyed by version id. Versions
	 * without persons get the "unknown" proposal.
	 *
	 * @param list<int> $versionIds
	 * @return array<int, Proposal> with `persons`: number of persons recorded
	 */
	public function protectionForVersions(array $versionIds, int $currentYear): array {
		$persons = $this->persons->findAllById();
		$byVersion = $this->contributions->findAllByVersion();
		$versions = $this->versions->findAllById();
		$result = [];
		foreach ($versionIds as $versionId) {
			$involved = [];
			$roles = [];
			foreach ($byVersion[$versionId] ?? [] as $contribution) {
				if (isset($persons[$contribution->getPersonId()])) {
					$involved[$contribution->getPersonId()] = $persons[$contribution->getPersonId()];
					$roles[$contribution->getPersonId()][] = $contribution->getRole();
				}
			}
			$result[$versionId] = self::propose($versions[$versionId] ?? null, $involved, $roles, $currentYear) + ['persons' => count($involved)];
		}
		return $result;
	}

	/**
	 * Resubmission list for the catalog librarian:
	 * - `ended`: marked protected, but by the proposal the protection has ended
	 * - `conflict`: marked public domain, but the recorded persons do not allow
	 *   a "public domain" proposal
	 * - `upcoming`: marked protected, with a known end of protection
	 *
	 * @return list<ReviewRow>
	 */
	public function review(string $uid): array {
		$year = (int)substr($this->rights->today($uid), 0, 4);
		$versions = $this->versions->findAllById();
		$proposals = $this->protectionForVersions(array_keys($versions), $year);
		$context = $this->versionContext();
		$rows = [];
		$keys = [];
		$order = ['ended' => 0, 'conflict' => 1, 'upcoming' => 2];
		foreach ($versions as $versionId => $version) {
			$proposal = $proposals[$versionId];
			$kind = match (true) {
				$version->getRightsStatus() === 'protected' && $proposal['status'] === ProtectionTerm::FREE => 'ended',
				$version->getRightsStatus() === 'public_domain' && ($proposal['persons'] ?? 0) > 0 && $proposal['status'] !== ProtectionTerm::FREE => 'conflict',
				$version->getRightsStatus() === 'protected' && $proposal['status'] === ProtectionTerm::PROTECTED => 'upcoming',
				default => null,
			};
			$info = $context[$versionId] ?? null;
			if ($kind === null || $info === null || $info['copies'] === []) {
				continue;
			}
			$rows[] = ['versionId' => $versionId, 'kind' => $kind, 'rightsStatus' => $version->getRightsStatus(),
				'confirmed' => $version->isConfirmed(), 'protection' => $proposal] + $info;
			$keys[] = [$order[$kind], $proposal['until'] ?? '', $info['copies'][0]['shelfmark'] ?? ''];
		}
		return Sort::byKeys($rows, $keys);
	}

	/**
	 * All persons as a table with their contributions ("shelf mark: role"),
	 * for backups and snapshots that are readable without the app.
	 *
	 * @param bool $withAccounts with the account that checked a person (not
	 *                           for the export folder, which every role group reads)
	 * @return array{0: list<string>, 1: list<list<string>>}
	 */
	public function table(bool $withAccounts = true): array {
		$header = ['id', 'name', 'gnd', 'wikidata', 'born', 'born_precision', 'died', 'died_precision', 'life_dates_text', 'source',
			'identification', ...($withAccounts ? ['checked_by'] : []), 'checked_at', 'notes', 'contributions'];
		$context = $this->versionContext();
		$byPerson = [];
		foreach ($this->contributions->findAllByVersion() as $versionId => $list) {
			foreach ($list as $contribution) {
				$marks = array_column($context[$versionId]['copies'] ?? [], 'shelfmark');
				$byPerson[$contribution->getPersonId()][] = ($marks === [] ? 'version ' . $versionId : implode(', ', $marks)) . ': ' . $contribution->getRole()
					. ($contribution->getTextForComposition() === true ? ' (§ 65)' : '');
			}
		}
		$rows = [];
		foreach ($this->persons->findAll() as $person) {
			$data = $person->jsonSerialize();
			$row = [];
			foreach (array_slice($header, 0, -1) as $column) {
				$row[] = (string)($data[$column] ?? '');
			}
			$row[] = implode(' | ', $byPerson[$person->getId()] ?? []);
			$rows[] = $row;
		}
		return [$header, $rows];
	}

	/**
	 * @return array<string, string|int|bool|null>
	 */
	public function serialize(Person $person): array {
		$checkedBy = $person->getCheckedBy();
		return $person->jsonSerialize() + [
			'checked_by_name' => $checkedBy === null ? null : ($this->userManager->getDisplayName($checkedBy) ?? $checkedBy),
		];
	}

	public function load(int $personId): Person {
		try {
			return $this->persons->find($personId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
	}

	/**
	 * Title, composer and copies of every version, for lists.
	 *
	 * @return array<int, VersionContext>
	 */
	public function versionContext(): array {
		$works = $this->works->findAllById();
		$copies = [];
		foreach ($this->copies->findAll() as $copy) {
			$copies[$copy->getVersionId()][] = ['id' => $copy->getId(), 'shelfmark' => $copy->getShelfmark(), 'status' => $copy->getStatus()];
		}
		$result = [];
		foreach ($this->versions->findAllById() as $versionId => $version) {
			$work = $works[$version->getWorkId()] ?? null;
			$list = $copies[$versionId] ?? [];
			$list = Sort::byKeys($list, array_map(fn (array $copy): array => [$copy['shelfmark']], $list));
			$result[$versionId] = ['title' => $work?->getTitle(), 'composer' => $work?->getComposer(), 'workId' => $version->getWorkId(), 'copies' => $list];
		}
		return $result;
	}

	/**
	 * Takes over and checks the given fields.
	 *
	 * @param array<array-key, mixed> $data
	 */
	private function assign(Person $person, array $data, string $uid): void {
		$info = Fields::person();
		foreach (Input::values($data) as $field => $value) {
			if (!in_array($field, self::FIELDS, true)) {
				throw new InvalidInputException('Unknown field: %s', [$field]);
			}
			if (is_int($value) && in_array($field, ['born', 'died'], true)) {
				$value = (string)$value;
			}
			if ($value !== null && !is_string($value)) {
				throw new InvalidInputException('"%s" must be text.', [$info[$field]['label']]);
			}
			$value = $value === null ? null : trim($value);
			if ($field === 'name') {
				if ($value === null || $value === '') {
					throw new InvalidInputException('Please enter a name.');
				}
				$person->setName($value);
				continue;
			}
			$person->setValue($field, $value === '' ? null : $value);
		}

		foreach (['born', 'died'] as $field) {
			$precisionField = $field . '_precision';
			$value = $field === 'born' ? $person->getBorn() : $person->getDied();
			$precision = ($field === 'born' ? $person->getBornPrecision() : $person->getDiedPrecision()) ?? 'unknown';
			if ($value !== null && $precision === 'unknown') {
				// A value without precision: full date or year.
				$precision = FieldValue::isDate($value) ? 'exact' : 'year';
			}
			if (in_array($precision, ['living', 'unknown'], true)) {
				$value = null;
			}
			$label = $info[$field]['label'];
			if ($precision === 'living' && $field === 'born') {
				throw new InvalidInputException('"%s": value not allowed.', [$info[$precisionField]['label']]);
			}
			if (!isset(Fields::DATE_PRECISION[$precision])) {
				throw new InvalidInputException('"%s": value not allowed.', [$info[$precisionField]['label']]);
			}
			if ($precision === 'exact' && ($value === null || !FieldValue::isDate($value))) {
				throw new InvalidInputException('"%s" needs a valid date (YYYY-MM-DD).', [$label]);
			}
			if ($precision === 'year' && ($value === null || !preg_match('/^-?\d{1,4}$/', $value))) {
				throw new InvalidInputException('"%s" needs a year (e.g. 1850).', [$label]);
			}
			if ($precision === 'circa' && $value !== null && !preg_match('/^-?\d{1,4}$/', $value)) {
				throw new InvalidInputException('"%s" needs a year (e.g. 1850).', [$label]);
			}
			if ($value !== null && preg_match('/^-?\d{1,4}$/', $value) === 1) {
				$value = (string)(int)$value;
			}
			$person->setValue($field, $value);
			$person->setValue($precisionField, $precision);
		}
		$person->keepBirthYearOnly();

		$currentYear = (int)substr($this->rights->today($uid), 0, 4);
		$bornYear = LifeDates::year($person->getBorn());
		$diedYear = LifeDates::year($person->getDied());
		if (($bornYear !== null && $bornYear > $currentYear) || ($diedYear !== null && $diedYear > $currentYear)) {
			throw new InvalidInputException('Life dates must not lie in the future.');
		}
		if ($bornYear !== null && $diedYear !== null && $diedYear < $bornYear) {
			throw new InvalidInputException('The date of death lies before the date of birth.');
		}
		$identification = $person->getIdentification() ?? 'uncertain';
		if (!isset(Fields::IDENTIFICATION[$identification])) {
			throw new InvalidInputException('"%s": value not allowed.', [$info['identification']['label']]);
		}
		$person->setValue('identification', $identification);
		$gnd = $person->getGnd();
		if ($gnd !== null && preg_match('/^\d[\dX-]{0,19}$/i', $gnd) !== 1) {
			throw new InvalidInputException('"%s" is not a valid GND number.', [$info['gnd']['label']]);
		}
		$wikidata = $person->getWikidata();
		if ($wikidata !== null && preg_match('/^Q[1-9]\d{0,11}$/', $wikidata) !== 1) {
			throw new InvalidInputException('"%s" is not a valid Wikidata ID (e.g. Q254).', [$info['wikidata']['label']]);
		}
		foreach (['name', 'gnd', 'wikidata', 'life_dates_text', 'source'] as $field) {
			$value = $person->getValue($field);
			if (is_string($value) && mb_strlen($value) > ($info[$field]['max'] ?? PHP_INT_MAX)) {
				throw new InvalidInputException('"%s" is too long.', [$info[$field]['label']]);
			}
		}
	}

	private function requireVersion(int $versionId): void {
		try {
			$this->versions->find($versionId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
	}

	private function loadContribution(int $contributionId): Contribution {
		try {
			return $this->contributions->find($contributionId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
	}

	private static function checkRole(string $role): string {
		if (!in_array($role, Contribution::ROLES, true)) {
			throw new InvalidInputException('"%s": value not allowed.', ['Role']);
		}
		return $role;
	}

	private static function describe(Person $person, string $role): string {
		return $person->getName() . ' (' . $role . ')';
	}
}
