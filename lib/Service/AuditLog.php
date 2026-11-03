<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\AuditEntry;
use OCA\Cantoria\Db\AuditEntryMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;

/**
 * Writes the audit log: who, when, what, old, new, reason. Changes that belong
 * together share one operation id. Choir members are recorded by their role
 * only, without account or name, and with the day only (in a small voice
 * group the time of day could tell who it was).
 */
class AuditLog {
	public function __construct(
		private AuditEntryMapper $mapper,
		private IUserManager $userManager,
		private Roles $roles,
		private ITimeFactory $time,
	) {
	}

	public function newOperation(): string {
		return bin2hex(random_bytes(16));
	}

	public function record(
		string $operation,
		?string $uid,
		string $objectType,
		?int $objectId,
		string $action,
		?string $field = null,
		mixed $oldValue = null,
		mixed $newValue = null,
		?string $reason = null,
	): void {
		$entry = new AuditEntry();
		$entry->setOperation($operation);
		$time = $this->time->getTime();
		$roles = $uid === null ? [] : $this->roles->rolesOf($uid);
		if ($uid !== null && $roles === [] && $this->roles->isMember($uid)) {
			// A choir member (practice audio of a shared excerpt): recorded
			// without account and name, only the role (milestone 0.11), and
			// at the start of the day (UTC) instead of to the second.
			$roles = [Roles::MEMBER];
			$uid = null;
			$time -= $time % 86400;
		}
		$entry->setTime($time);
		$entry->setUserId($uid);
		$entry->setUserName($uid === null ? null : $this->userManager->getDisplayName($uid));
		$entry->setRoles($roles === [] && $uid === null ? null : implode(',', $roles));
		$entry->setObjectType($objectType);
		$entry->setObjectId($objectId);
		$entry->setAction($action);
		$entry->setField($field);
		$entry->setOldValue(self::asText($oldValue));
		$entry->setNewValue(self::asText($newValue));
		$entry->setReason($reason === '' ? null : $reason);
		$this->mapper->insert($entry);
	}

	private static function asText(mixed $value): ?string {
		if ($value === null) {
			return null;
		}
		if (is_bool($value)) {
			return $value ? '1' : '0';
		}
		if (is_scalar($value)) {
			return (string)$value;
		}
		return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}
}
