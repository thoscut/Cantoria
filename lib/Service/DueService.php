<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\LicenseMapper;
use OCA\Cantoria\Db\ProposalMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;

/**
 * The start page "due": what each role should do next, so that a successor
 * without instruction sees the open work (hand-over test).
 *
 * Priorities follow the expert reports: the current repertoire first.
 *
 * The same data feed the Nextcloud dashboard widget (Dashboard\DueWidget).
 */
class DueService {
	public function __construct(
		private Roles $roles,
		private TaskService $tasks,
		private ProposalMapper $proposals,
		private ReleaseListService $releaseLists,
		private LicenseMapper $licenses,
		private WorkVersionMapper $versions,
		private WorkMapper $works,
		private RightsService $rights,
		private RepertoireService $repertoire,
		private EventItemMapper $eventItems,
		private EventMapper $events,
		private Modules $modules,
		private CopyMapper $copies,
	) {
	}

	/**
	 * Cards with a number, the tasks for the user's roles and the concerts
	 * with a deadline now (for conductor and catalog librarian, like the
	 * notifications; confirming what was sung only for the conductor).
	 * Labels are English source strings. Only cards, tasks and concerts of
	 * active modules are listed (see docs/api.md).
	 *
	 * @return array{cards: list<array{key: string, count: int, label: string}>, tasks: list<array<string, mixed>>, concerts: list<array{id: int, title: string, date: string, stage: string, label: string}>}
	 */
	public function forUser(string $uid): array {
		$roles = $this->roles->rolesOf($uid);
		$isLibrarian = in_array(Roles::LIBRARIAN, $roles, true);
		$isConductor = in_array(Roles::CONDUCTOR, $roles, true);
		$active = $this->modules->states();
		$cards = [];
		$tasks = $active['tasks'] ? $this->tasks->forUser($uid) : [];
		$open = count(array_filter($tasks, fn (array $t): bool => $t['status'] === 'open'));
		$answered = count($tasks) - $open;
		if ($open > 0) {
			$cards[] = ['key' => 'tasks_open', 'count' => $open, 'label' => 'Open tasks for your role'];
		}
		if ($isLibrarian && $answered > 0) {
			$cards[] = ['key' => 'tasks_answered', 'count' => $answered, 'label' => 'Answered tasks to apply'];
		}

		$current = ($isLibrarian && $active['rights']) || ($isConductor && $active['valid_version'])
			? $this->currentRepertoireVersions() : [];
		if (($isLibrarian || $isConductor) && $active['performance_import']) {
			$ambiguous = count($this->eventItems->findByAssignment(EventItem::AMBIGUOUS));
			if ($ambiguous > 0) {
				$cards[] = ['key' => 'ambiguous_items', 'count' => $ambiguous, 'label' => 'Programme lines with an unclear shelf mark'];
			}
		}
		$proposals = $isLibrarian && $active['proposals'] ? $this->proposals->countOpen() : 0;
		if ($proposals > 0) {
			$cards[] = ['key' => 'proposals', 'count' => $proposals, 'label' => 'Proposals in the inbox'];
		}
		if ($isLibrarian && $active['portal']) {
			$portal = array_count_values(array_column($this->releaseLists->portal($uid), 'status'));
			if (($portal['remove'] ?? 0) > 0) {
				$cards[] = ['key' => 'portal_remove', 'count' => $portal['remove'], 'label' => 'Remove from the singers\' portal'];
			}
			if (($portal['add'] ?? 0) > 0) {
				$cards[] = ['key' => 'portal_add', 'count' => $portal['add'], 'label' => 'Released, not yet in the singers\' portal'];
			}
		}
		if ($isLibrarian && $active['rights']) {
			$unconfirmed = 0;
			$rule1 = 0;
			foreach ($current as $version) {
				if ($version->getRightsStatus() === 'public_domain' && !$version->isConfirmed()) {
					$unconfirmed++;
				}
				if ($version->getPreservationCopy() === true && in_array($version->getValue('rule1_status'), [null, 'unchecked', 'board_assumption'], true)) {
					$rule1++;
				}
			}
			if ($unconfirmed > 0) {
				$cards[] = ['key' => 'confirm_current', 'count' => $unconfirmed, 'label' => 'Current repertoire: public domain to confirm'];
			}
			if ($rule1 > 0) {
				$cards[] = ['key' => 'rule1_current', 'count' => $rule1, 'label' => 'Current repertoire: check originals of preservation copies'];
			}
			$today = $this->rights->today($uid);
			$uncovered = $current === [] ? 0 : $this->uncoveredCopies($current, $today);
			if ($uncovered > 0) {
				$cards[] = ['key' => 'copies_unlicensed', 'count' => $uncovered, 'label' => 'Current repertoire: photocopies without a license'];
			}
			$soon = (new \DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d');
			$expiring = 0;
			foreach ($this->licenses->findAllByVersion() as $list) {
				foreach ($list as $license) {
					$end = $license->getValidUntil();
					if ($end !== null && $end <= $soon) {
						$expiring++;
					}
				}
			}
			if ($expiring > 0) {
				$cards[] = ['key' => 'licenses_expiring', 'count' => $expiring, 'label' => 'Licenses ending or ended'];
			}
		}
		if ($isLibrarian) {
			// hired material (round 3, November 2026): to return within 30 days or overdue, core
			$soon = (new \DateTimeImmutable($this->rights->today($uid)))->modify('+30 days')->format('Y-m-d');
			$hired = $this->copies->countReturnDue($soon);
			if ($hired > 0) {
				$cards[] = ['key' => 'hired_return', 'count' => $hired, 'label' => 'Hired material to return'];
			}
		}
		if ($isConductor && $active['valid_version']) {
			$withoutValid = [];
			$valid = [];
			foreach ($this->versions->findAllById() as $version) {
				if ($version->getValidSince() !== null) {
					$valid[$version->getWorkId()] = true;
				}
			}
			foreach ($current as $version) {
				if (!isset($valid[$version->getWorkId()])) {
					$withoutValid[$version->getWorkId()] = true;
				}
			}
			if ($withoutValid !== []) {
				$cards[] = ['key' => 'valid_missing', 'count' => count($withoutValid), 'label' => 'Current repertoire: no valid version chosen'];
			}
		}
		$concerts = [];
		if (($isLibrarian || $isConductor) && $active['concerts']) {
			$today = $this->rights->today($uid);
			foreach ($this->events->findByKind(Event::CONCERT) as $event) {
				$stage = ConcertDeadlines::stage($event, $today);
				if ($stage === '' || ($stage === 'confirm' && !$isConductor)) {
					continue;
				}
				$concerts[] = ['id' => $event->getId(), 'title' => (string)$event->getTitle(), 'date' => (string)$event->getEventDate(),
					'stage' => $stage, 'label' => ConcertDeadlines::label($stage)];
			}
			usort($concerts, fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
		}
		return ['cards' => $cards, 'tasks' => $tasks, 'concerts' => $concerts];
	}

	/**
	 * Versions of works marked as current repertoire (imported value until
	 * performances are recorded in the app).
	 *
	 * @return list<\OCA\Cantoria\Db\WorkVersion>
	 */
	private function currentRepertoireVersions(): array {
		$works = $this->works->findAllById();
		$derived = $this->repertoire->perWork();
		return array_values(array_filter($this->versions->findAllById(),
			fn ($v): bool => CatalogService::repertoireFigures($works[$v->getWorkId()] ?? null, $derived)['repertoire_class'] === 'current'));
	}

	/**
	 * Versions whose photocopies are not covered by a license for the
	 * binders (LicenseCoverage::copies).
	 *
	 * @param iterable<WorkVersion> $versions
	 */
	private function uncoveredCopies(iterable $versions, string $day): int {
		$copies = [];
		foreach ($this->copies->findAll() as $copy) {
			$copies[$copy->getVersionId()][] = $copy;
		}
		$licenses = $this->licenses->findAllByVersion();
		$count = 0;
		foreach ($versions as $version) {
			if (LicenseCoverage::copies($version, $copies[$version->getId()] ?? [], $licenses[$version->getId()] ?? [], $day) !== []) {
				$count++;
			}
		}
		return $count;
	}
}
