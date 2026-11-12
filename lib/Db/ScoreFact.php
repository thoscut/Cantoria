<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Db;

use OCP\AppFramework\Db\Entity;

/**
 * What ScoreFacts read from one MusicXML file of the archive, with the
 * checksum of the content and the format of the analysis. Derived data:
 * rebuilt from the file when the content or the format changes.
 *
 * @method int getArchiveFileId()
 * @method void setArchiveFileId(int $archiveFileId)
 * @method string getSha256()
 * @method void setSha256(string $sha256)
 * @method int getFormat()
 * @method void setFormat(int $format)
 * @method int getAnalyzedAt()
 * @method void setAnalyzedAt(int $analyzedAt)
 * @method string|null getFacts()
 * @method void setFacts(?string $facts)
 */
class ScoreFact extends Entity {
	protected int $archiveFileId = 0;
	protected string $sha256 = '';
	protected int $format = 0;
	protected int $analyzedAt = 0;
	protected ?string $facts = null;

	public function __construct() {
		foreach (['archiveFileId', 'format', 'analyzedAt'] as $field) {
			$this->addType($field, 'integer');
		}
		foreach (['sha256', 'facts'] as $field) {
			$this->addType($field, 'string');
		}
	}
}
