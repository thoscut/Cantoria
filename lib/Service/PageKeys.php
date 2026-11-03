<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\WorkVersion;
use OCP\Security\ICrypto;

/**
 * Keys under which the pages for choir members and the concert folder name
 * programmes and pieces without the ids of the catalog (S N21): an HMAC
 * with the secret of the instance, so that no one can tell the id from a
 * key or work out the key of another id (a plain hash of a fixed text and
 * a running number could be reversed by trying the numbers). The same for
 * every page and every account; they change only with the secret of the
 * instance, e.g. after moving the archive to another Nextcloud. The browser
 * keeps the practice settings and passages of a piece under its practice
 * key; under a new key they start afresh.
 */
class PageKeys {
	/** Characters of a key (hexadecimal, 80 bits) */
	public const LENGTH = 20;

	public function __construct(
		private ICrypto $crypto,
	) {
	}

	/**
	 * The key under which the browser keeps the practice settings and
	 * passages of a piece (milestone 0.15): the same for every link to the
	 * same version, as its measures are the same, and unchanged when a title
	 * is corrected.
	 */
	public function practice(WorkVersion $version): string {
		return $this->key('practice', $version->getId());
	}

	/** The key of a programme on the page "Shared with me" */
	public function programme(int $eventId): string {
		return $this->key('programme', $eventId);
	}

	private function key(string $purpose, int $id): string {
		return substr(bin2hex($this->crypto->calculateHMAC('cantoria-' . $purpose . '|' . $id)), 0, self::LENGTH);
	}
}
