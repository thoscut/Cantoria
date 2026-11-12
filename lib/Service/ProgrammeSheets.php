<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\ContributionMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\PersonMapper;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Two sheets of a programme built from the facts of the scores
 * (November 2026):
 *
 * - the **pitch sheet** for the conductor: per piece the first tones of all
 *   voices, the key at the start and the end and the tempo, so that the
 *   order of an a-cappella programme can be planned by its keys;
 * - the **programme booklet** for the audience: pieces with composer,
 *   lyricist and their life dates, durations and, where the rights allow
 *   publishing them (RightsPolicy::bookletLyricsDenial), the lyrics read
 *   from the MusicXML next to the translation of the version.
 *
 * Both only read. Struck pieces are left out; internal preservation copies
 * give no facts to roles that may not view them, and never lyrics.
 *
 * @psalm-import-type Facts from ScoreFacts
 */
class ProgrammeSheets {
	/** Who makes a programme booklet: the catalog librarian and the conductor */
	public const BOOKLET_MAKERS = [Roles::LIBRARIAN, Roles::CONDUCTOR];

	public function __construct(
		private EventMapper $events,
		private EventItemMapper $items,
		private ProgrammeCopies $programmeCopies,
		private WorkVersionMapper $versions,
		private WorkMapper $works,
		private ContributionMapper $contributions,
		private PersonMapper $persons,
		private ScoreFactsService $facts,
		private RightsPolicy $policy,
		private RightsService $rights,
		private Roles $roles,
	) {
	}

	/**
	 * The pitch sheet of a programme or song list.
	 *
	 * @return array{event: array<string, string|null>, pieces: list<array<string, mixed>>}
	 */
	public function pitchSheet(int $eventId, string $uid): array {
		$this->roles->requireAccess($uid);
		$event = $this->event($eventId);
		$pieces = [];
		foreach ($this->pieces($eventId) as $piece) {
			['item' => $item, 'copy' => $copy, 'version' => $version, 'work' => $work, 'facts' => $facts] = $piece;
			$visible = $version !== null && $this->policy->viewDenial($version, $uid, $copy) === null;
			$pieces[] = [
				'itemId' => $item->getId(),
				'copyId' => $copy?->getId(),
				'shelfmark' => $copy?->getShelfmark(),
				'title' => $work?->getTitle() ?? (string)$item->getTitleText(),
				'composer' => $work?->getComposer() ?? $item->getComposerText(),
				'encore' => $item->getEncore() === true,
				'duration' => $item->getDuration() ?? $version?->getDuration(),
				'keySignature' => $version?->getKeySignature(),
				'facts' => $visible && $facts !== null ? [
					'voices' => array_map(fn (array $v): array => ['name' => $v['name'], 'group' => $v['group'], 'first' => $v['first'],
						'last' => $v['last'], 'low' => $v['low'], 'high' => $v['high']], $facts['voices']),
					'keyStart' => $facts['keyStart'],
					'keyEnd' => $facts['keyEnd'],
					'tempo' => $facts['tempo'],
					'time' => $facts['time'],
					'incipit' => $facts['incipit'],
				] : null,
			];
		}
		return ['event' => self::eventInfo($event), 'pieces' => $pieces];
	}

	/**
	 * The programme booklet of a concert.
	 *
	 * @return array{event: array<string, string|null>, pieces: list<array<string, mixed>>}
	 */
	public function booklet(int $eventId, string $uid): array {
		$this->roles->requireRole($uid, self::BOOKLET_MAKERS);
		$event = $this->event($eventId);
		$day = $this->rights->today($uid);
		$pieces = [];
		foreach ($this->pieces($eventId) as $piece) {
			['item' => $item, 'copy' => $copy, 'version' => $version, 'work' => $work, 'facts' => $facts] = $piece;
			$persons = $version === null ? [] : $this->personsOf($version);
			$denial = $version === null ? 'The piece is not in the catalog.' : $this->policy->bookletLyricsDenial($version, $day, $copy);
			$lyrics = $facts['lyrics'] ?? null;
			$hasText = $version?->getTextOrigin() !== ProtectionTerm::NO_TEXT;
			$pieces[] = [
				'itemId' => $item->getId(),
				'title' => $work?->getTitle() ?? (string)$item->getTitleText(),
				'composer' => $work?->getComposer() ?? $item->getComposerText(),
				'arranger' => $version?->getArrangerText(),
				'persons' => $persons,
				'duration' => $item->getDuration() ?? $version?->getDuration(),
				'encore' => $item->getEncore() === true,
				'participation' => $item->getParticipation(),
				'language' => $version?->getLanguage(),
				'lyrics' => $denial === null && $hasText && $lyrics !== null ? $lyrics['verses'] : null,
				'translation' => $denial === null ? $version?->getLyricsTranslation() : null,
				'translationSource' => $version?->getTranslationSource(),
				// Why no lyrics are printed: the rights, or nothing read from the score
				'lyricsReason' => !$hasText ? null : ($denial ?? ($lyrics === null ? 'No lyrics were found in the MusicXML of this piece.' : null)),
			];
		}
		return ['event' => self::eventInfo($event), 'pieces' => $pieces];
	}

	/**
	 * The pieces of an event in their order, without struck ones, with copy,
	 * version, work and the facts of the score.
	 *
	 * @return list<array{item: EventItem, copy: ?Copy, version: ?WorkVersion, work: ?Work, facts: Facts|null}>
	 */
	private function pieces(int $eventId): array {
		$list = [];
		$copyIds = [];
		foreach ($this->items->findByEvent($eventId) as $item) {
			if ($item->getStruck() === true) {
				continue;
			}
			$copy = $this->programmeCopies->copyOf($item);
			$version = null;
			$work = null;
			try {
				$versionId = $copy?->getVersionId() ?? $item->getVersionId();
				$version = $versionId === null ? null : $this->versions->find($versionId);
				$workId = $version?->getWorkId() ?? $item->getWorkId();
				$work = $workId === null ? null : $this->works->find($workId);
			} catch (DoesNotExistException) {
			}
			if ($copy !== null) {
				$copyIds[] = $copy->getId();
			}
			$list[] = ['item' => $item, 'copy' => $copy, 'version' => $version, 'work' => $work, 'facts' => null];
		}
		$facts = $copyIds === [] ? [] : $this->facts->forCopies($copyIds);
		foreach ($list as &$entry) {
			$copyId = $entry['copy']?->getId();
			$entry['facts'] = $copyId === null ? null : ($facts[$copyId] ?? null);
		}
		unset($entry);
		return $list;
	}

	/**
	 * Composer, lyricist, arranger and translator of a version with their
	 * years of birth and death, in the order of the roles.
	 *
	 * @return list<array{role: string, name: string, born: ?string, died: ?string}>
	 */
	private function personsOf(WorkVersion $version): array {
		$list = [];
		foreach ($this->contributions->findByVersion($version->getId()) as $contribution) {
			if (!in_array($contribution->getRole(), ['composer', 'lyricist', 'arranger', 'translator'], true)) {
				continue;
			}
			try {
				$person = $this->persons->find($contribution->getPersonId());
			} catch (DoesNotExistException) {
				continue;
			}
			$list[] = [
				'role' => $contribution->getRole(),
				'name' => $person->getName(),
				'born' => self::year($person->getBorn()),
				'died' => self::year($person->getDied()),
			];
		}
		$sorted = [];
		foreach (['composer', 'lyricist', 'arranger', 'translator'] as $role) {
			foreach ($list as $entry) {
				if ($entry['role'] === $role) {
					$sorted[] = $entry;
				}
			}
		}
		return $sorted;
	}

	private static function year(?string $date): ?string {
		if ($date === null || preg_match('/^-?\d{1,4}/', $date, $m) !== 1) {
			return null;
		}
		return $m[0];
	}

	private function event(int $eventId): Event {
		try {
			return $this->events->find($eventId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
	}

	/**
	 * @return array<string, string|null>
	 */
	private static function eventInfo(Event $event): array {
		return [
			'kind' => $event->getKind(),
			'title' => $event->getTitle(),
			'date' => $event->getEventDate(),
			'startTime' => $event->getStartTime(),
			'endTime' => $event->getEndTime(),
			'location' => $event->getLocation(),
		];
	}
}
