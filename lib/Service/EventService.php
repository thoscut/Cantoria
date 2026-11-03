<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\ContributionMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\LicenseMapper;
use OCA\Cantoria\Db\PersonMapper;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Concert programmes and song lists.
 *
 * Conductor and catalog librarian plan; everybody with access reads. A
 * concert goes draft → fixed → sung. Confirming "sung" (with struck pieces
 * and encores) creates the documented performances; the repertoire figures are
 * derived from them. Imported programmes are history and cannot be edited.
 * A planned concert can be cancelled and reopened; nothing is deleted.
 *
 * A concert may have a start and an end time (local time at the venue). The
 * Nextcloud time zone of the account that enters or changes the time is
 * recorded with it, so that calendar entries show the right hour everywhere.
 *
 * For each item the rights situation is checked and shown as warnings; the
 * app warns, people decide.
 *
 * With the module "version_states", confirming "sung" keeps the revision of
 * each version that was sung (its current revision, else the newest), and
 * the programme and the song order name the revision of each item.
 *
 * @psalm-type Line = array{item: EventItem, copy: ?Copy, version: ?WorkVersion, work: ?Work, warnings: list<string>}
 */
class EventService {
	private const PLANNERS = [Roles::CONDUCTOR, Roles::LIBRARIAN];
	private const EVENT_FIELDS = ['title', 'event_date', 'start_time', 'end_time', 'location', 'period', 'is_public', 'board_agreed', 'notes'];
	private const ITEM_FIELDS = ['duration', 'note', 'encore', 'struck'];

	public function __construct(
		private IDBConnection $db,
		private EventMapper $events,
		private EventItemMapper $items,
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private WorkMapper $works,
		private LicenseMapper $licenses,
		private RightsService $rights,
		private StateService $states,
		private AuditLog $audit,
		private Roles $roles,
		private ITimeFactory $time,
		private IDateTimeZone $timeZone,
		private ContributionMapper $contributions,
		private PersonMapper $persons,
	) {
	}

	/**
	 * @param array<array-key, mixed> $data field => value (request data)
	 */
	public function create(string $kind, array $data, string $uid): Event {
		$this->roles->requireRole($uid, self::PLANNERS);
		if (!in_array($kind, Event::KINDS, true)) {
			throw new InvalidInputException('"%s": value not allowed.', ['Kind']);
		}
		$event = new Event();
		$event->setKind($kind);
		$event->setStatus('draft');
		$event->setImported(false);
		$event->setCreatedBy($uid);
		$event->setCreated($this->time->getTime());
		$event->setModified($this->time->getTime());
		$this->assign($event, $data);
		self::requireTitle($event);
		$this->checkTime($event, true, $uid);
		$event = $this->events->insert($event);
		$this->audit->record($this->audit->newOperation(), $uid, 'event', $event->getId(), 'created', 'title', null, $event->getTitle());
		return $event;
	}

	/**
	 * @param array<array-key, mixed> $data field => value (request data)
	 */
	public function update(int $eventId, array $data, string $uid): Event {
		$event = $this->editable($eventId, $uid);
		$before = $event->jsonSerialize();
		$this->assign($event, $data);
		self::requireTitle($event);
		$this->checkTime($event, $before['start_time'] !== $event->getStartTime() || $before['end_time'] !== $event->getEndTime(), $uid);
		$operation = $this->audit->newOperation();
		foreach (self::EVENT_FIELDS as $field) {
			if ($before[$field] !== $event->getValue($field)) {
				$this->audit->record($operation, $uid, 'event', $eventId, 'changed', $field, $before[$field], $event->getValue($field));
			}
		}
		$event->setModified($this->time->getTime());
		return $this->events->update($event);
	}

	/**
	 * Adds a copy to the programme. The version is the copy's version; a
	 * warning shows if the conductor has chosen another valid version.
	 */
	public function addItem(int $eventId, int $copyId, string $uid): EventItem {
		$event = $this->editable($eventId, $uid);
		try {
			$copy = $this->copies->find($copyId);
			$version = $this->versions->find($copy->getVersionId());
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		$position = count($this->items->findByEvent($eventId)) + 1;
		$item = new EventItem();
		$item->setEventId($eventId);
		$item->setPosition($position);
		$item->setValue('copy_id', $copy->getId());
		$item->setValue('version_id', $version->getId());
		$item->setValue('work_id', $version->getWorkId());
		$item->setDuration($version->getDuration());
		$item->setAssignment(EventItem::CERTAIN);
		$item->setCounts(false);
		$item = $this->items->insert($item);
		$this->audit->record($this->audit->newOperation(), $uid, 'event', $event->getId(), 'item_added', 'copy_id', null, $copy->getShelfmark());
		return $item;
	}

	/**
	 * @param array<array-key, mixed> $data field => value (request data)
	 */
	public function updateItem(int $itemId, array $data, string $uid): EventItem {
		$item = $this->loadItem($itemId);
		$this->editable($item->getEventId(), $uid);
		foreach (Input::values($data) as $field => $value) {
			if (!in_array($field, self::ITEM_FIELDS, true)) {
				throw new InvalidInputException('Unknown field: %s', [$field]);
			}
			$item->setValue($field, match ($field) {
				'duration' => $value === null || $value === '' ? null : self::int($value, 'Duration (seconds)', 0, 36000),
				'encore', 'struck' => Input::bool($value, $field),
				default => Input::text($value, $field),
			});
		}
		return $this->items->update($item);
	}

	public function removeItem(int $itemId, string $uid): void {
		$item = $this->loadItem($itemId);
		$event = $this->editable($item->getEventId(), $uid);
		$this->items->delete($item);
		$this->renumber($event->getId());
		$this->audit->record($this->audit->newOperation(), $uid, 'event', $event->getId(), 'item_removed', 'copy_id', $item->getCopyId(), null);
	}

	/**
	 * @param list<int> $itemIds new order
	 */
	public function reorder(int $eventId, array $itemIds, string $uid): void {
		$this->editable($eventId, $uid);
		$items = [];
		foreach ($this->items->findByEvent($eventId) as $item) {
			$items[$item->getId()] = $item;
		}
		if (count($itemIds) !== count($items) || array_diff($itemIds, array_keys($items)) !== []) {
			throw new InvalidInputException('The new order must contain every item exactly once.');
		}
		foreach ($itemIds as $index => $id) {
			$items[$id]->setPosition($index + 1);
			$this->items->update($items[$id]);
		}
	}

	public function setFixed(int $eventId, string $uid): void {
		$event = $this->editable($eventId, $uid);
		$event->setStatus('fixed');
		$event->setModified($this->time->getTime());
		$this->events->update($event);
		$this->audit->record($this->audit->newOperation(), $uid, 'event', $eventId, 'fixed', 'status', 'draft', 'fixed');
	}

	/**
	 * Cancels a planned concert. It stays in the list with its programme;
	 * notifications about its deadlines are withdrawn and calendar entries
	 * can be updated to "cancelled".
	 */
	public function cancel(int $eventId, string $uid): Event {
		$event = $this->editable($eventId, $uid);
		if ($event->getKind() !== Event::CONCERT) {
			throw new InvalidInputException('Only concerts can be cancelled.');
		}
		$previous = $event->getStatus();
		$event->setStatus(Event::CANCELLED);
		$event->setModified($this->time->getTime());
		$event = $this->events->update($event);
		$this->audit->record($this->audit->newOperation(), $uid, 'event', $eventId, 'cancelled', 'status', $previous, Event::CANCELLED);
		return $event;
	}

	/**
	 * Takes back the cancellation: the concert becomes a draft again.
	 */
	public function reopen(int $eventId, string $uid): Event {
		$this->roles->requireRole($uid, self::PLANNERS);
		try {
			$event = $this->events->find($eventId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		if ($event->getStatus() !== Event::CANCELLED) {
			throw new InvalidInputException('Only a cancelled concert can be reopened.');
		}
		$event->setStatus('draft');
		$event->setModified($this->time->getTime());
		$event = $this->events->update($event);
		$this->audit->record($this->audit->newOperation(), $uid, 'event', $eventId, 'reopened', 'status', Event::CANCELLED, 'draft');
		return $event;
	}

	/**
	 * Confirms the concert as sung. Items listed in $struck were not sung;
	 * every other item counts as a documented performance.
	 *
	 * @param list<int> $struck item ids that were not sung
	 */
	public function confirmSung(int $eventId, array $struck, string $uid): void {
		$event = $this->editable($eventId, $uid);
		if ($event->getKind() !== Event::CONCERT) {
			throw new InvalidInputException('Only concerts can be confirmed as sung.');
		}
		if ($event->getEventDate() === null || $event->getEventDate() > $this->rights->today($uid)) {
			throw new InvalidInputException('A concert can only be confirmed on or after its date.');
		}
		$operation = $this->audit->newOperation();
		$this->db->beginTransaction();
		try {
			foreach ($this->items->findByEvent($eventId) as $item) {
				$wasStruck = in_array($item->getId(), $struck, true);
				$item->setValue('struck', $wasStruck);
				$item->setValue('sung', !$wasStruck);
				$item->setCounts(!$wasStruck && $item->getWorkId() !== null);
				$versionId = $item->getVersionId();
				$item->setStateId($wasStruck || $versionId === null ? null : $this->states->sungState($versionId)?->getId());
				$this->items->update($item);
			}
			$event->setStatus('sung');
			$event->setModified($this->time->getTime());
			$this->events->update($event);
			$this->audit->record($operation, $uid, 'event', $eventId, 'sung', 'status', null, 'sung');
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Resolves an ambiguous or unassigned programme line by choosing the
	 * copy (or confirming it is not in the catalog).
	 */
	public function resolveItem(int $itemId, ?int $copyId, string $uid): void {
		$this->roles->requireRole($uid, self::PLANNERS);
		$item = $this->loadItem($itemId);
		if ($copyId === null) {
			$item->setAssignment(EventItem::NOT_IN_CATALOG);
			$item->setValue('copy_id', null);
			$item->setValue('version_id', null);
			$item->setValue('work_id', null);
			$item->setCounts(false);
		} else {
			try {
				$copy = $this->copies->find($copyId);
				$version = $this->versions->find($copy->getVersionId());
			} catch (DoesNotExistException) {
				throw new NotFoundException('Not found.');
			}
			$item->setAssignment(EventItem::CERTAIN);
			$item->setValue('copy_id', $copy->getId());
			$item->setValue('version_id', $version->getId());
			$item->setValue('work_id', $version->getWorkId());
			$event = $this->events->find($item->getEventId());
			$item->setCounts($event->getKind() === Event::CONCERT && $event->getStatus() === 'sung'
				&& $item->getStruck() !== true && PerformanceImportService::countsByRule($item->getParticipation(), $item->getState(), null));
		}
		$this->items->update($item);
		$this->audit->record($this->audit->newOperation(), $uid, 'event_item', $itemId, 'resolved', 'copy_id', null, $copyId);
	}

	/**
	 * The event with its items, each with context and warnings (English
	 * source strings).
	 *
	 * `revision` of an item: the number of the revision sung, or of the current
	 * revision of its version (module "version_states"), null when there is
	 * none; `revision_valid_since` the day since when it is the current one,
	 * `revision_sung` whether it is the revision kept as sung.
	 *
	 * An internal preservation copy in a programme (milestone 0.18, S N13):
	 * the roles that may not view it get the title of the line, but neither
	 * its shelf mark nor the ids of its copy and version, nor details of its
	 * version; its warnings say only "Not available.". The API for agents
	 * shows the same (ApiV1\ConcertView).
	 *
	 * @return array{event: Event, items: list<array<string, string|int|bool|null|list<string>>>, totalDuration: int, missingDurations: int}
	 */
	public function detail(int $eventId, string $uid): array {
		['event' => $event, 'lines' => $lines] = $this->lines($eventId, $uid);
		$revisions = $this->states->forItems(array_map(fn (array $line): EventItem => $line['item'], $lines));
		$mayViewPreservation = $this->roles->hasAnyRole($uid, RightsPolicy::PRESERVATION_COPY_VIEWERS);
		$items = [];
		$total = 0;
		$missingDurations = 0;
		foreach ($lines as ['item' => $item, 'copy' => $copy, 'version' => $version, 'work' => $work, 'warnings' => $warnings]) {
			$duration = $item->getDuration();
			if ($item->getStruck() !== true) {
				if ($duration === null) {
					$missingDurations++;
				} else {
					$total += $duration;
				}
			}
			$items[] = $item->jsonSerialize() + [
				'shelfmark' => $copy?->getShelfmark(),
				'title' => $work?->getTitle() ?? $item->getTitleText(),
				'composer' => $work?->getComposer() ?? $item->getComposerText(),
				'arranger_text' => $version?->getArrangerText(),
				'publisher' => $version?->getPublisher(),
				'warnings' => $warnings,
				'revision' => $revisions[$item->getId()]['number'] ?? null,
				'revision_valid_since' => $revisions[$item->getId()]['valid_since'] ?? null,
				'revision_sung' => $revisions[$item->getId()]['sung'] ?? false,
			];
			if (!$mayViewPreservation && $version !== null && RightsPolicy::isPreservationCopy($version, $copy)) {
				$items[array_key_last($items)] = [
					'copy_id' => null, 'version_id' => null, 'shelfmark' => null, 'arranger_text' => null, 'publisher' => null,
					'warnings' => [ProgrammeShareService::NOT_AVAILABLE], 'revision' => null, 'revision_valid_since' => null, 'revision_sung' => false,
				] + $items[array_key_last($items)];
			}
		}
		return ['event' => $event, 'items' => $items, 'totalDuration' => $total, 'missingDurations' => $missingDurations];
	}

	/**
	 * The items of an event with copy, version, work and rights warnings.
	 *
	 * @return array{event: Event, lines: list<Line>}
	 */
	private function lines(int $eventId, string $uid): array {
		try {
			$event = $this->events->find($eventId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		$day = $this->rights->today($uid);
		$versions = $this->versions->findAllById();
		$works = $this->works->findAllById();
		$validPerWork = [];
		foreach ($versions as $version) {
			if ($version->getValidSince() !== null) {
				$validPerWork[$version->getWorkId()] = $version->getId();
			}
		}
		$copies = [];
		$copiesPerVersion = [];
		foreach ($this->copies->findAll() as $copy) {
			$copies[$copy->getId()] = $copy;
			$copiesPerVersion[$copy->getVersionId()][] = $copy;
		}
		$licenses = $this->licenses->findAllByVersion();
		$lines = [];
		foreach ($this->items->findByEvent($eventId) as $item) {
			$versionId = $item->getVersionId();
			$workId = $item->getWorkId();
			$copyId = $item->getCopyId();
			$version = $versionId === null ? null : ($versions[$versionId] ?? null);
			$work = $workId === null ? null : ($works[$workId] ?? null);
			$copy = $copyId === null ? null : ($copies[$copyId] ?? null);
			$warnings = [];
			if ($version !== null && $event->getImported() !== true) {
				if (RightsPolicy::isPreservationCopy($version, $copy)) {
					$warnings[] = 'Internal preservation copy: not a singing copy.';
				}
				$versionLicenses = $licenses[$version->getId()] ?? [];
				if ($version->getRightsStatus() === 'protected' && !$this->rights->hasLicense($versionLicenses, 'binder', $day)
					&& ($copy?->getOriginalsCount() === null)) {
					$warnings[] = 'Protected: check that there are enough original copies or a license.';
				}
				if ($version->getRightsStatus() !== 'public_domain' && $version->getRightsStatus() !== 'protected') {
					$warnings[] = 'Rights not clarified.';
				}
				array_push($warnings, ...LicenseCoverage::copies($version, $copiesPerVersion[$version->getId()] ?? [], $versionLicenses, $day));
				$valid = $validPerWork[$version->getWorkId()] ?? null;
				if ($valid === null) {
					$warnings[] = 'No valid version chosen for this work.';
				} elseif ($valid !== $version->getId()) {
					$warnings[] = 'Another version of this work is marked as valid.';
				}
			}
			$lines[] = ['item' => $item, 'copy' => $copy, 'version' => $version, 'work' => $work, 'warnings' => $warnings];
		}
		return ['event' => $event, 'lines' => $lines];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function list(?string $kind): array {
		$counts = [];
		foreach ($this->items->findAll() as $item) {
			$counts[$item->getEventId()] = ($counts[$item->getEventId()] ?? 0) + 1;
		}
		return array_map(fn (Event $e): array => $e->jsonSerialize() + ['items' => $counts[$e->getId()] ?? 0], $this->events->findByKind($kind));
	}

	/**
	 * Song order for the collecting society, programme booklet and binders,
	 * laid out like the music report of GEMA (milestone 0.11): rows with the
	 * data of the event first, then one row per piece with composer,
	 * lyricist and arranger in columns of their own (from the register of
	 * persons, else from the text fields), the work number at the collecting
	 * society and the rights status (a public domain melody in a protected
	 * setting counts only for the arranger). Headers in the language of the
	 * account.
	 */
	public function songOrderCsv(int $eventId, string $uid, IL10N $l): string {
		['event' => $event, 'lines' => $lines] = $this->lines($eventId, $uid);
		// The revision of each piece (module "version_states"): two more columns
		$withRevisions = $this->states->isActive();
		$revisions = $withRevisions ? $this->states->forItems(array_map(fn (array $line): EventItem => $line['item'], $lines)) : [];
		$statuses = [];
		foreach (Fields::RIGHTS_STATUS as $key => $label) {
			$statuses[$key] = $l->t($label);
		}
		$rows = [
			[$l->t('Event'), (string)$event->getTitle()],
			[$l->t('Date'), (string)$event->getEventDate()],
			[$l->t('Start'), (string)$event->getStartTime()],
			[$l->t('Location'), (string)$event->getLocation()],
			[],
		];
		$header = [$l->t('Position'), $l->t('Shelf mark'), $l->t('Title'), $l->t('Composer'), $l->t('Lyricist'), $l->t('Arranger'),
			$l->t('Publisher'), $l->t('Work number (GEMA)'), $l->t('Rights status'), $l->t('Duration'), $l->t('Note')];
		if ($withRevisions) {
			$header = [...$header, $l->t('Revision'), $l->t('Revision valid since')];
		}
		$rows[] = $header;
		foreach ($lines as ['item' => $item, 'copy' => $copy, 'version' => $version, 'work' => $work]) {
			if ($item->getStruck() === true) {
				continue;
			}
			$names = $version === null ? [] : $this->namesByRole($version->getId());
			$duration = $item->getDuration();
			$status = $version?->getRightsStatus();
			$row = [
				(string)$item->getPosition(), $copy?->getShelfmark() ?? '', $work?->getTitle() ?? $item->getTitleText() ?? '',
				$names['composer'] ?? $work?->getComposer() ?? $item->getComposerText() ?? '',
				$names['lyricist'] ?? '',
				$names['arranger'] ?? $version?->getArrangerText() ?? '',
				$version?->getPublisher() ?? '', $version?->getSocietyWorkNo() ?? '',
				$status === null ? '' : ($statuses[$status] ?? $status),
				$duration === null ? '' : sprintf('%d:%02d', intdiv($duration, 60), $duration % 60),
				$item->getEncore() === true ? $l->t('Encore') : '',
			];
			if ($withRevisions) {
				$revision = $revisions[$item->getId()] ?? null;
				$row[] = $revision === null ? '' : (string)$revision['number'];
				$row[] = $revision['valid_since'] ?? '';
			}
			$rows[] = $row;
		}
		return CatalogCsv::writeForPeople(array_shift($rows), $rows);
	}

	/**
	 * Names of the persons of a version per role, joined with "; ".
	 *
	 * @return array<string, string>
	 */
	private function namesByRole(int $versionId): array {
		$names = [];
		foreach ($this->contributions->findByVersion($versionId) as $contribution) {
			try {
				$names[$contribution->getRole()][] = $this->persons->find($contribution->getPersonId())->getName();
			} catch (DoesNotExistException) {
			}
		}
		return array_map(fn (array $list): string => implode('; ', $list), $names);
	}

	private function editable(int $eventId, string $uid): Event {
		$this->roles->requireRole($uid, self::PLANNERS);
		try {
			$event = $this->events->find($eventId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		if ($event->getImported() === true || $event->getStatus() === 'sung') {
			throw new InvalidInputException('Performed or imported programmes are history and cannot be changed.');
		}
		if ($event->getStatus() === Event::CANCELLED) {
			throw new InvalidInputException('This concert is cancelled. Reopen it to change it.');
		}
		return $event;
	}

	/**
	 * @param array<array-key, mixed> $data
	 */
	private function assign(Event $event, array $data): void {
		foreach (Input::values($data) as $field => $value) {
			if (!in_array($field, self::EVENT_FIELDS, true)) {
				throw new InvalidInputException('Unknown field: %s', [$field]);
			}
			if ($field === 'is_public' || $field === 'board_agreed') {
				$event->setValue($field, Input::bool($value, $field));
				continue;
			}
			$text = Input::text($value, $field);
			if ($field === 'event_date' && $text !== null && !FieldValue::isDate($text)) {
				throw new InvalidInputException('"%s" needs a valid date (YYYY-MM-DD).', ['Date']);
			}
			if (($field === 'start_time' || $field === 'end_time') && $text !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $text) !== 1) {
				throw new InvalidInputException('"%s" needs a valid time (HH:MM).', [$field === 'start_time' ? 'Start time' : 'End time']);
			}
			if (in_array($field, ['title', 'location', 'period'], true) && $text !== null && mb_strlen($text) > ($field === 'period' ? 32 : 500)) {
				throw new InvalidInputException('"%s" is too long.', [$field]);
			}
			$event->setValue($field, $text);
		}
	}

	/**
	 * A planned programme needs a title (imported ones may lack it).
	 */
	private static function requireTitle(Event $event): void {
		if (trim($event->getTitle() ?? '') === '') {
			throw new InvalidInputException('Please enter a title.');
		}
	}

	/**
	 * Checks start and end time and records the time zone they refer to:
	 * the Nextcloud time zone of the account that entered or changed them.
	 *
	 * @param bool $changed whether the time was entered or changed now
	 */
	private function checkTime(Event $event, bool $changed, string $uid): void {
		$start = $event->getStartTime();
		$end = $event->getEndTime();
		if ($start === null && $end === null) {
			$event->setTimeZone(null);
			return;
		}
		if ($event->getKind() !== Event::CONCERT) {
			throw new InvalidInputException('Only concerts have a time.');
		}
		if ($start === null) {
			throw new InvalidInputException('Please enter the start time, too.');
		}
		if ($end !== null && $end <= $start) {
			throw new InvalidInputException('The end time must be after the start time.');
		}
		if (!FieldValue::isDate((string)$event->getEventDate())) {
			throw new InvalidInputException('A time needs the date of the concert.');
		}
		if ($changed || $event->getTimeZone() === null) {
			$event->setTimeZone($this->timeZone->getTimeZone(false, $uid)->getName());
		}
	}

	private function renumber(int $eventId): void {
		foreach ($this->items->findByEvent($eventId) as $index => $item) {
			if ($item->getPosition() !== $index + 1) {
				$item->setPosition($index + 1);
				$this->items->update($item);
			}
		}
	}

	private function loadItem(int $itemId): EventItem {
		try {
			return $this->items->find($itemId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
	}

	private static function int(mixed $value, string $label, int $min, int $max): int {
		$int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
		if ($int === false) {
			throw new InvalidInputException('"%s" must be a whole number.', [$label]);
		}
		return $int;
	}
}
