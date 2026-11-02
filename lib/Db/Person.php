<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Db;

use JsonSerializable;
use OCA\Cantoria\Service\LifeDates;
use OCP\AppFramework\Db\Entity;

/**
 * A person involved in versions of works: composer, lyricist, arranger,
 * translator, editor.
 *
 * Life dates carry their precision (`exact` = full date YYYY-MM-DD, `year` =
 * YYYY, `circa` = approximate year, `living`, `unknown`) and their source; the
 * raw text as found (e.g. "ca. 1850-1910") is always kept in
 * `life_dates_text`. `identification` says how sure it is that the name
 * means this person (`certain`, `probable`, `uncertain`): a name alone is no
 * identification ("unnamed is not anonymous").
 *
 * `checked_by`/`checked_at` are set only when a librarian marks the record as
 * checked; imports never set them.
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getGnd()
 * @method void setGnd(?string $gnd)
 * @method string|null getWikidata()
 * @method void setWikidata(?string $wikidata)
 * @method string|null getBorn()
 * @method void setBorn(?string $born)
 * @method string|null getBornPrecision()
 * @method void setBornPrecision(?string $bornPrecision)
 * @method string|null getDied()
 * @method void setDied(?string $died)
 * @method string|null getDiedPrecision()
 * @method void setDiedPrecision(?string $diedPrecision)
 * @method string|null getLifeDatesText()
 * @method void setLifeDatesText(?string $lifeDatesText)
 * @method string|null getSource()
 * @method void setSource(?string $source)
 * @method string|null getIdentification()
 * @method void setIdentification(?string $identification)
 * @method string|null getCheckedBy()
 * @method void setCheckedBy(?string $checkedBy)
 * @method string|null getCheckedAt()
 * @method void setCheckedAt(?string $checkedAt)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method int getModified()
 * @method void setModified(int $modified)
 */
class Person extends Entity implements JsonSerializable {
	use FieldAccess;

	public const PRECISIONS = ['exact', 'year', 'circa', 'living', 'unknown'];
	public const IDENTIFICATIONS = ['certain', 'probable', 'uncertain'];

	protected string $name = '';
	protected ?string $gnd = null;
	protected ?string $wikidata = null;
	protected ?string $born = null;
	protected ?string $bornPrecision = null;
	protected ?string $died = null;
	protected ?string $diedPrecision = null;
	protected ?string $lifeDatesText = null;
	protected ?string $source = null;
	protected ?string $identification = null;
	protected ?string $checkedBy = null;
	protected ?string $checkedAt = null;
	protected ?string $notes = null;
	protected int $created = 0;
	protected int $modified = 0;

	public function __construct() {
		foreach (['created', 'modified'] as $field) {
			$this->addType($field, 'integer');
		}
		foreach (['name', 'gnd', 'wikidata', 'born', 'bornPrecision', 'died', 'diedPrecision', 'lifeDatesText', 'source',
			'identification', 'checkedBy', 'checkedAt', 'notes'] as $field) {
			$this->addType($field, 'string');
		}
	}

	/**
	 * Without a known death only the year of birth is kept, also in the
	 * free text (milestone 0.11, data minimisation).
	 */
	public function keepBirthYearOnly(): void {
		if ($this->died !== null) {
			return;
		}
		$born = LifeDates::birthYearOnly(['born' => $this->born, 'born_precision' => $this->bornPrecision, 'died' => $this->died]);
		$this->setValue('born', $born['born']);
		$this->setValue('born_precision', $born['born_precision']);
		foreach (['life_dates_text' => $this->lifeDatesText, 'notes' => $this->notes] as $field => $text) {
			if ($text !== null) {
				$this->setValue($field, LifeDates::yearsOnly($text));
			}
		}
	}

	/**
	 * Year of death if it is known to the year (precision `exact` or `year`),
	 * otherwise null. Values as stored by LifeDates: "1850", "1850-03-12" or
	 * "-8" (8 BC).
	 */
	public function securedDeathYear(): ?int {
		if (!in_array($this->diedPrecision, ['exact', 'year'], true) || $this->died === null
			|| preg_match('/^(-?\d{1,4})(?:-\d{2}-\d{2})?$/', $this->died, $m) !== 1) {
			return null;
		}
		return (int)$m[1];
	}
}
