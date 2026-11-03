<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A link to an excerpt of a score: printed measures, a voice as focus, an
 * optional verse, tempo and note. The link carries no rights of its own: the
 * rights are checked again on every call. It expires and can be revoked;
 * calls are only counted, not attributed to persons.
 *
 * `file_etag` remembers the state of the MusicXML when the link was created,
 * so a later correction of the transcription can be shown as "changed".
 *
 * `circle` says who opens the link: the archive roles (`internal`), the
 * choir members (`members`, module member_links) or everyone (`public`,
 * module public_links). Choir members and the public get the measures of
 * the excerpt, cut on the server, or the whole piece when the link has no
 * measures; never the score file.
 *
 * `scan_file_id` (circles `members` and `public` only) is the PDF scan
 * (`cantoria_files.id`, role `score_pdf`) offered besides the notes; null
 * for none.
 *
 * `event_id` (circle `members` only) is the programme the link was last
 * shared with as a whole ("Share the programme with the choir",
 * ProgrammeShareService; review of 3 November 2026, U24), so that "Shared
 * with me" lists it under that programme; null for a link made on its own.
 *
 * `token` holds the SHA-256 hash of the token in the address (milestone
 * 0.11): a link is found by the hash, so a copy of the table opens no link.
 * `token_secret` holds the token encrypted with the secret of the instance,
 * so that the creator and the catalog librarian can copy the link again;
 * null for links whose token is gone (restored from a backup). Neither
 * column is serialized; LinkService::view adds the token for those who may
 * see it.
 *
 * @method string getToken()
 * @method void setToken(string $token)
 * @method string|null getTokenSecret()
 * @method void setTokenSecret(?string $tokenSecret)
 * @method int getCopyId()
 * @method void setCopyId(int $copyId)
 * @method int|null getFileId()
 * @method void setFileId(?int $fileId)
 * @method string|null getFileEtag()
 * @method void setFileEtag(?string $fileEtag)
 * @method string|null getMeasureFrom()
 * @method void setMeasureFrom(?string $measureFrom)
 * @method string|null getMeasureTo()
 * @method void setMeasureTo(?string $measureTo)
 * @method string|null getVoice()
 * @method void setVoice(?string $voice)
 * @method int|null getVerse()
 * @method void setVerse(?int $verse)
 * @method int|null getTempo()
 * @method void setTempo(?int $tempo)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method int|null getTaskId()
 * @method void setTaskId(?int $taskId)
 * @method string getCircle()
 * @method void setCircle(string $circle)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method string|null getExpires()
 * @method void setExpires(?string $expires)
 * @method int|null getRevoked()
 * @method void setRevoked(?int $revoked)
 * @method int getHits()
 * @method void setHits(int $hits)
 * @method int|null getScanFileId()
 * @method void setScanFileId(?int $scanFileId)
 * @method int|null getEventId()
 * @method void setEventId(?int $eventId)
 */
class ExcerptLink extends Entity implements JsonSerializable {
	use FieldAccess {
		jsonSerialize as private columns;
	}

	public const CIRCLE_INTERNAL = 'internal';
	public const CIRCLE_MEMBERS = 'members';
	public const CIRCLE_PUBLIC = 'public';
	public const CIRCLES = [self::CIRCLE_INTERNAL, self::CIRCLE_MEMBERS, self::CIRCLE_PUBLIC];

	protected string $token = '';
	protected ?string $tokenSecret = null;
	protected int $copyId = 0;
	protected ?int $fileId = null;
	protected ?string $fileEtag = null;
	protected ?string $measureFrom = null;
	protected ?string $measureTo = null;
	protected ?string $voice = null;
	protected ?int $verse = null;
	protected ?int $tempo = null;
	protected ?string $note = null;
	protected ?int $taskId = null;
	protected string $circle = self::CIRCLE_INTERNAL;
	protected ?string $createdBy = null;
	protected int $created = 0;
	protected ?string $expires = null;
	protected ?int $revoked = null;
	protected int $hits = 0;
	protected ?int $scanFileId = null;
	protected ?int $eventId = null;
	/** The token of a link just made, for the answer to its creator; not a column */
	private ?string $issued = null;

	public function __construct() {
		foreach (['copyId', 'fileId', 'verse', 'tempo', 'taskId', 'created', 'revoked', 'hits', 'scanFileId', 'eventId'] as $field) {
			$this->addType($field, 'integer');
		}
		foreach (['token', 'tokenSecret', 'fileEtag', 'measureFrom', 'measureTo', 'voice', 'note', 'circle', 'createdBy', 'expires'] as $field) {
			$this->addType($field, 'string');
		}
	}

	/**
	 * Remembers the token of a link just made (LinkTokens::issue).
	 */
	public function issue(string $token): void {
		$this->issued = $token;
	}

	/**
	 * The token of a link just made; null for links read from the table.
	 */
	public function issuedToken(): ?string {
		return $this->issued;
	}

	/**
	 * The hash stored in `token` for a token of an address.
	 */
	public static function hashToken(string $token): string {
		return hash('sha256', $token);
	}

	/**
	 * The columns without the token hash and the encrypted token.
	 *
	 * @return array<string, string|int|bool|null> column => value
	 */
	#[\Override]
	public function jsonSerialize(): array {
		$data = $this->columns();
		unset($data['token'], $data['token_secret']);
		return $data;
	}
}
