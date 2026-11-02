<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\ExcerptLink;
use OCA\Cantoria\Db\ExcerptLinkMapper;
use OCA\Cantoria\Db\WorkMapper;
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
 * kept on their devices stays, too.
 *
 * Reasons are English source strings with parameters; the controller
 * translates them.
 *
 * @psalm-type ShareReason = array{message: string, parameters: list<string|int>}
 * @psalm-type ProgrammeShareItem = array{id: int, title: string, shelfmark: ?string, allowed: bool, reason: ?ShareReason,
 *     scan: bool, offline: ?bool, existing: ?array{id: int, expires: ?string}, copyId: ?int, scanFileId: ?int}
 */
class ProgrammeShareService {
	/** Days after the concert a shared programme stays open by default */
	public const DAYS_AFTER = 14;

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
		try {
			$event = $this->events->find($eventId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		$today = $this->rights->today($uid);
		$latest = LinkService::latestExpiry($today);
		$date = $event->getEventDate();
		$suggested = $date === null || $date < $today ? $latest
			: min($latest, (new \DateTimeImmutable($date))->modify('+' . self::DAYS_AFTER . ' days')->format('Y-m-d'));
		$items = [];
		foreach ($this->items->findByEvent($eventId) as $item) {
			if ($item->getStruck() !== true) {
				$items[] = $this->describe($item, $uid, $today);
			}
		}
		return ['expires' => $suggested, 'latest' => $latest, 'items' => $items];
	}

	/**
	 * Shares the chosen pieces of a programme with the choir members.
	 *
	 * @param array<array-key, mixed> $data items (programme item ids), expires, note, scan
	 * @return array{created: int, extended: int, failed: list<array{id: int, title: string, reason: ShareReason}>}
	 * @throws UserFacingException
	 */
	public function share(int $eventId, array $data, string $uid): array {
		$chosen = $data['items'] ?? null;
		if (!is_array($chosen) || $chosen === []) {
			throw new InvalidInputException('Please choose at least one piece.');
		}
		$chosen = array_map('intval', array_values(array_filter($chosen, 'is_numeric')));
		$expires = LinkService::expiry($data['expires'] ?? null, $this->rights->today($uid));
		$note = isset($data['note']) && is_string($data['note']) ? $data['note'] : null;
		$withScan = ($data['scan'] ?? false) === true;
		$result = ['created' => 0, 'extended' => 0, 'failed' => []];
		foreach ($this->options($eventId, $uid)['items'] as $option) {
			if (!in_array($option['id'], $chosen, true)) {
				continue;
			}
			if (!$option['allowed'] || $option['copyId'] === null) {
				$result['failed'][] = ['id' => $option['id'], 'title' => $option['title'],
					'reason' => $option['reason'] ?? ['message' => 'This piece cannot be shared.', 'parameters' => []]];
				continue;
			}
			try {
				if ($option['existing'] !== null) {
					$this->linkService->extend($option['existing']['id'], $expires, $uid);
					$result['extended']++;
				} else {
					$this->linkService->create($option['copyId'], ['circle' => ExcerptLink::CIRCLE_MEMBERS, 'expires' => $expires,
						'note' => $note, 'scan_file_id' => $withScan && $option['scan'] ? $option['scanFileId'] : null], $uid);
					$result['created']++;
				}
			} catch (UserFacingException $e) {
				$result['failed'][] = ['id' => $option['id'], 'title' => $option['title'],
					'reason' => ['message' => $e->getMessage(), 'parameters' => $e->getParameters()]];
			}
		}
		return $result;
	}

	/**
	 * @return ProgrammeShareItem
	 */
	private function describe(EventItem $item, string $uid, string $today): array {
		$copy = $this->programmeCopies->copyOf($item);
		$title = $this->title($item, $copy?->getVersionId());
		$row = ['id' => $item->getId(), 'title' => $title, 'shelfmark' => $copy?->getShelfmark(), 'allowed' => false, 'reason' => null,
			'scan' => false, 'offline' => null, 'existing' => null, 'copyId' => $copy?->getId(), 'scanFileId' => null];
		if ($copy === null) {
			$row['reason'] = ['message' => 'No copy of this piece is in the catalog.', 'parameters' => []];
			return $row;
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
		$row['existing'] = $this->existing($copy->getId(), $uid, $today);
		return $row;
	}

	/**
	 * An active link for choir members to the whole piece that the account
	 * may extend: the one that lasts longest.
	 *
	 * @return array{id: int, expires: ?string}|null
	 */
	private function existing(int $copyId, string $uid, string $today): ?array {
		$librarian = $this->roles->hasAnyRole($uid, [Roles::LIBRARIAN]);
		$found = null;
		foreach ($this->links->findByCopy($copyId) as $link) {
			if ($link->getCircle() !== ExcerptLink::CIRCLE_MEMBERS || $link->getRevoked() !== null || $link->getMeasureFrom() !== null
				|| ($link->getExpires() !== null && $link->getExpires() < $today) || (!$librarian && $link->getCreatedBy() !== $uid)) {
				continue;
			}
			if ($found === null || (string)$link->getExpires() > (string)$found['expires']) {
				$found = ['id' => $link->getId(), 'expires' => $link->getExpires()];
			}
		}
		return $found;
	}

	private function title(EventItem $item, ?int $versionId): string {
		try {
			$workId = $versionId === null ? $item->getWorkId() : $this->versions->find($versionId)->getWorkId();
			if ($workId !== null) {
				return (string)$this->works->find($workId)->getTitle();
			}
		} catch (DoesNotExistException) {
		}
		return (string)$item->getTitleText();
	}
}
