<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Large-print parts (November 2026): one voice of a MusicXML score set
 * large for a singer with a visual impairment. The part is set in the
 * browser from the score the maker already sees (src/score/largePrint.ts);
 * the server says whether it may be printed (RightsPolicy::largePrintDenial)
 * and records every part made.
 *
 * Without confirmed public domain or a license, the catalog librarian can
 * confirm that the part is an accessible copy for a person with a visual
 * impairment (§ 45b UrhG). The app does not decide that; it records who
 * confirmed it. No name of the singer is asked for or stored: that someone
 * sees badly is data about health.
 */
class LargePrintService {
	/** The longest name of a voice that is recorded */
	private const MAX_VOICE = 100;

	public function __construct(
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private RightsPolicy $policy,
		private RightsService $rights,
		private AuditLog $audit,
		private Roles $roles,
	) {
	}

	/**
	 * Whether a large-print part of a copy may be made, and on what basis.
	 *
	 * @return array{allowed: bool, basis: ?string, reason: ?string, canConfirm: bool}
	 */
	public function check(int $copyId, string $uid): array {
		$this->roles->requireRole($uid, RightsPolicy::LARGE_PRINT_MAKERS);
		[$copy, $version] = $this->find($copyId, $uid);
		$day = $this->rights->today($uid);
		$denial = $this->policy->largePrintDenial($version, $day, $copy);
		return [
			'allowed' => $denial === null,
			'basis' => $denial === null ? $this->policy->largePrintBasis($version, $day) : null,
			'reason' => $denial,
			// Only the catalog librarian decides about an accessible copy, and never for a preservation copy
			'canConfirm' => $denial !== null && !RightsPolicy::isPreservationCopy($version, $copy)
				&& $this->roles->hasAnyRole($uid, [Roles::LIBRARIAN]),
		];
	}

	/**
	 * Records a large-print part that is being printed.
	 *
	 * @param string $voice the voice of the part, as shown
	 * @param bool $accessible the catalog librarian confirms an accessible copy (§ 45b UrhG)
	 * @return array{recorded: true, basis: string}
	 * @throws AccessDeniedException when the rights do not allow the part
	 */
	public function record(int $copyId, string $voice, bool $accessible, string $uid): array {
		$this->roles->requireRole($uid, RightsPolicy::LARGE_PRINT_MAKERS);
		[$copy, $version] = $this->find($copyId, $uid);
		$voice = trim($voice);
		if ($voice === '' || mb_strlen($voice) > self::MAX_VOICE) {
			throw new InvalidInputException('Please choose the voice of the part.');
		}
		$day = $this->rights->today($uid);
		$confirmed = $accessible && $this->roles->hasAnyRole($uid, [Roles::LIBRARIAN]);
		$denial = $this->policy->largePrintDenial($version, $day, $copy, $confirmed);
		if ($denial !== null) {
			throw new AccessDeniedException($denial);
		}
		$basis = $this->policy->largePrintBasis($version, $day) ?? 'accessible';
		$this->audit->record($this->audit->newOperation(), $uid, 'copy', $copyId, 'large_print_made', 'voice', null, $voice,
			$basis === 'accessible' ? 'Accessible copy for a person with a visual impairment (§ 45b UrhG), confirmed by the catalog librarian' : null);
		return ['recorded' => true, 'basis' => $basis];
	}

	/**
	 * The copy and its version, as far as the account may view them.
	 *
	 * @return array{0: Copy, 1: WorkVersion}
	 */
	private function find(int $copyId, string $uid): array {
		try {
			$copy = $this->copies->find($copyId);
			$version = $this->versions->find($copy->getVersionId());
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		$denial = $this->policy->viewDenial($version, $uid, $copy);
		if ($denial !== null) {
			throw new AccessDeniedException($denial);
		}
		return [$copy, $version];
	}
}
