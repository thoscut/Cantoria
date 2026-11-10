<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\ExcerptLink;
use OCA\Cantoria\Db\ExcerptLinkMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\NotFoundException;
use OCA\Cantoria\Exception\UserFacingException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Shares a whole programme with the choir members in one step (milestone
 * 0.19): one link per piece, the whole piece, with a common last day and
 * note. Each piece goes through LinkService as if it were shared on its
 * own, so the rights are checked and recorded per piece. A piece that is
 * already shared as a whole with the choir, by a link the account may
 * extend, is extended instead: its token stays, and what choir members
 * kept on their devices stays, too. Such a link shares the whole piece
 * without a voice, verse or tempo (U45: a link for the altos at 80 % stays
 * theirs); it may have ended, as long as its age allows extending it (U40).
 *
 * Milestone 0.18 (review of 3 November 2026): a link is never shortened (a
 * later last day stays as it is), the note and the scans chosen apply to
 * the links that are extended as well, and a piece lasts at most until the
 * license that releases it ends. Only the chosen pieces are checked. An
 * internal preservation copy shows its shelf mark only to the roles that
 * may view it (S N13). `quantity` says when there are more choir members
 * than the licenses were bought for (a hint, W B17).
 *
 * Milestone 0.20 (U24): each link made or extended here records the
 * programme, so that "Shared with me" lists the pieces under it in
 * programme order (SharedLinkService::forMember).
 *
 * `limited` says for each piece that lasts shorter than the chosen day why
 * (U41): `license` (its license ends) or `age` (the link that exists
 * already was made almost a year ago). `playback` says whether the choir
 * members can play the piece, for the message to the choir (U42).
 *
 * Reasons are English source strings with parameters; the controller
 * translates them.
 *
 * @psalm-type ShareReason = array{message: string, parameters: list<string|int>}
 * @psalm-type ProgrammeShareItem = array{id: int, title: string, shelfmark: ?string, allowed: bool, reason: ?ShareReason,
 *     scan: bool, offline: ?bool, playback: bool, existing: ?array{id: int, expires: ?string, scan: bool, limit: string}, copyId: ?int,
 *     scanFileId: ?int, until: ?string, quantity: ?array{licensed: int, members: int}}
 * @psalm-type ShareResult = array{created: int, extended: int, kept: int,
 *     failed: list<array{id: int, title: string, reason: ShareReason}>,
 *     limited: list<array{id: int, title: string, expires: string, reason: 'license'|'age'}>}
 */
class ProgrammeShareService {
	/** Days after the concert a shared programme stays open by default */
	public const DAYS_AFTER = 14;
	/** What a line of an internal preservation copy says to the roles that may not view it */
	public const NOT_AVAILABLE = 'Not available.';

	public function __construct(
		private EventMapper $events,
		private EventItemMapper $items,
		private ProgrammeCopies $programmeCopies,
		private WorkVersionMapper $versions,
		private WorkMapper $works,
		private ExcerptLinkMapper $links,
		private LinkService $linkService,
		private RightsService $rights,
		private Roles $roles,
	) {
	}

	/**
	 * What sharing the programme would do, per piece, and the suggested last
	 * day: two weeks after the concert, within the six months a link may
	 * last.
	 *
	 * @return array{expires: string, latest: string, items: list<ProgrammeShareItem>}
	 * @throws UserFacingException
	 */
	public function options(int $eventId, string $uid): array {
		$this->roles->requireRole($uid, LinkService::CREATORS);
		$event = $this->event($eventId);
		$today = $this->rights->today($uid);
		$latest = LinkService::latestExpiry($today);
		$date = $event->getEventDate();
		$suggested = $date === null || $date < $today ? $latest
			: min($latest, (new \DateTimeImmutable($date))->modify('+' . self::DAYS_AFTER . ' days')->format('Y-m-d'));
		return ['expires' => $suggested, 'latest' => $latest, 'items' => $this->describeAll($eventId, $uid, $today, null)];
	}

	/**
	 * Shares the chosen pieces of a programme with the choir members.
	 *
	 * `extended` counts the links that last longer now, `kept` those that
	 * lasted longer already and keep their last day (the note and the scan
	 * still apply to them); `limited` names the pieces whose license ends
	 * before the chosen day, with the day they last.
	 *
	 * @param array<array-key, mixed> $data items (programme item ids), expires, note, scan
	 * @return ShareResult
	 * @throws UserFacingException
	 */
	public function share(int $eventId, array $data, string $uid): array {
		$this->roles->requireRole($uid, LinkService::CREATORS);
		$chosen = $data['items'] ?? null;
		if (!is_array($chosen) || $chosen === []) {
			throw new InvalidInputException('Please choose at least one piece.');
		}
		$chosen = array_map('intval', array_values(array_filter($chosen, 'is_numeric')));
		$today = $this->rights->today($uid);
		$expires = LinkService::expiry($data['expires'] ?? null, $today);
		$note = isset($data['note']) && is_string($data['note']) && trim($data['note']) !== '' ? $data['note'] : null;
		$withScan = ($data['scan'] ?? false) === true;
		$this->event($eventId);
		$result = ['created' => 0, 'extended' => 0, 'kept' => 0, 'failed' => [], 'limited' => []];
		// copy id => true once this run made or extended its link: a piece
		// sung twice (in the programme and as an encore) gets one link
		$done = [];
		foreach ($this->describeAll($eventId, $uid, $today, $chosen) as $option) {
			if (!$option['allowed'] || $option['copyId'] === null) {
				$result['failed'][] = ['id' => $option['id'], 'title' => $option['title'],
					'reason' => $option['reason'] ?? ['message' => 'This piece cannot be shared.', 'parameters' => []]];
				continue;
			}
			if (isset($done[$option['copyId']])) {
				$result['kept']++;
				continue;
			}
			// at most until the license ends
			$until = $option['until'] !== null && $option['until'] < $expires ? $option['until'] : $expires;
			$scanFileId = $withScan && $option['scan'] ? $option['scanFileId'] : null;
			$why = 'license';
			try {
				$existing = $option['existing'];
				if ($existing !== null) {
					$day = min($until, $existing['limit']);
					if ($existing['limit'] < $until) {
						$why = 'age';
					}
					// never shorter than it is
					$day = $existing['expires'] !== null && $existing['expires'] > $day ? $existing['expires'] : $day;
					$link = $this->linkService->extend($existing['id'], $day, $uid,
						['note' => $note, 'scan_file_id' => $existing['scan'] ? null : $scanFileId, 'programme' => $eventId]);
					$longer = $existing['expires'] === null || (string)$link->getExpires() > $existing['expires'];
					$result[$longer ? 'extended' : 'kept']++;
					$lasts = (string)$link->getExpires();
				} else {
					$this->linkService->create($option['copyId'], ['circle' => ExcerptLink::CIRCLE_MEMBERS, 'expires' => $until,
						'note' => $note, 'scan_file_id' => $scanFileId], $uid, $eventId);
					$result['created']++;
					$lasts = $until;
				}
				$done[$option['copyId']] = true;
				if ($lasts < $expires) {
					$result['limited'][] = ['id' => $option['id'], 'title' => $option['title'], 'expires' => $lasts, 'reason' => $why];
				}
			} catch (UserFacingException $e) {
				$result['failed'][] = ['id' => $option['id'], 'title' => $option['title'],
					'reason' => ['message' => $e->getMessage(), 'parameters' => $e->getParameters()]];
			}
		}
		return $result;
	}

	/**
	 * @throws NotFoundException
	 */
	private function event(int $eventId): Event {
		try {
			return $this->events->find($eventId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
	}

	/**
	 * The pieces of the programme that are not struck, in programme order;
	 * with `$only` just those items (A18: no checks for pieces not chosen).
	 *
	 * @param list<int>|null $only
	 * @return list<ProgrammeShareItem>
	 */
	private function describeAll(int $eventId, string $uid, string $today, ?array $only): array {
		$items = [];
		foreach ($this->items->findByEvent($eventId) as $item) {
			if ($item->getStruck() !== true && ($only === null || in_array($item->getId(), $only, true))) {
				$items[] = $this->describe($item, $uid, $today);
			}
		}
		return $items;
	}

	/**
	 * @return ProgrammeShareItem
	 */
	private function describe(EventItem $item, string $uid, string $today): array {
		$copy = $this->programmeCopies->copyOf($item);
		$version = $this->version($copy?->getVersionId());
		$title = $this->title($item, $version);
		$row = ['id' => $item->getId(), 'title' => $title, 'shelfmark' => $copy?->getShelfmark(), 'allowed' => false, 'reason' => null,
			'scan' => false, 'offline' => null, 'playback' => false, 'existing' => null, 'copyId' => $copy?->getId(), 'scanFileId' => null, 'until' => null, 'quantity' => null];
		if ($copy === null) {
			$row['reason'] = ['message' => 'No copy of this piece is in the catalog.', 'parameters' => []];
			return $row;
		}
		if ($version !== null && RightsPolicy::isPreservationCopy($version, $copy)
			&& !$this->roles->hasAnyRole($uid, RightsPolicy::PRESERVATION_COPY_VIEWERS)) {
			// neither shelf mark nor id of a copy the account may not view
			return ['shelfmark' => null, 'copyId' => null, 'title' => (string)$item->getTitleText() !== '' ? (string)$item->getTitleText() : $title,
				'reason' => ['message' => self::NOT_AVAILABLE, 'parameters' => []]] + $row;
		}
		try {
			$options = $this->linkService->circleOptions($copy->getId(), $uid);
		} catch (UserFacingException $e) {
			$row['reason'] = ['message' => $e->getMessage(), 'parameters' => $e->getParameters()];
			return $row;
		}
		$members = $options['members'];
		$row['allowed'] = $members['allowed'];
		$row['reason'] = $members['allowed'] || $members['reason'] === null ? null : ['message' => $members['reason'], 'parameters' => []];
		$row['scan'] = $members['allowed'] && $members['scan'] && $options['scans'] !== [];
		$row['scanFileId'] = $row['scan'] ? $options['scans'][0]['id'] : null;
		$row['offline'] = $members['offline'];
		$row['playback'] = $members['playback'];
		$row['until'] = $members['until'] ?? null;
		$row['quantity'] = $members['quantity'] ?? null;
		$row['existing'] = $this->existing($copy->getId(), $uid, $today);
		return $row;
	}

	/**
	 * A link for choir members to the whole piece, without a voice, verse or
	 * tempo (U45), that the account may extend: the one that lasts longest;
	 * `limit` is the last day its age allows. A link that has ended counts,
	 * too (U40): extending it keeps its address. Links made more than a year
	 * ago are not extended any more, revoked ones never.
	 *
	 * @return array{id: int, expires: ?string, scan: bool, limit: string}|null
	 */
	private function existing(int $copyId, string $uid, string $today): ?array {
		$librarian = $this->roles->hasAnyRole($uid, [Roles::LIBRARIAN]);
		$found = null;
		foreach ($this->links->findByCopy($copyId) as $link) {
			if ($link->getCircle() !== ExcerptLink::CIRCLE_MEMBERS || $link->getRevoked() !== null || $link->getMeasureFrom() !== null
				|| $link->getVoice() !== null || $link->getVerse() !== null || $link->getTempo() !== null
				|| (!$librarian && $link->getCreatedBy() !== $uid) || LinkService::ageLimit($link) < $today) {
				continue;
			}
			if ($found === null || (string)$link->getExpires() > (string)$found['expires']) {
				$found = ['id' => $link->getId(), 'expires' => $link->getExpires(), 'scan' => $link->getScanFileId() !== null,
					'limit' => LinkService::ageLimit($link)];
			}
		}
		return $found;
	}

	private function version(?int $versionId): ?WorkVersion {
		try {
			return $versionId === null ? null : $this->versions->find($versionId);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	private function title(EventItem $item, ?WorkVersion $version): string {
		try {
			$workId = $version === null ? $item->getWorkId() : $version->getWorkId();
			if ($workId !== null) {
				return (string)$this->works->find($workId)->getTitle();
			}
		} catch (DoesNotExistException) {
		}
		return (string)$item->getTitleText();
	}
}
