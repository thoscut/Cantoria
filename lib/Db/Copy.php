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
 * A copy: the place in an archive binder, identified by its shelf mark
 * (binder-number). Copies are never deleted; they get a status instead.
 *
 * @method int getVersionId()
 * @method void setVersionId(int $versionId)
 * @method int getBinder()
 * @method void setBinder(int $binder)
 * @method int getNumber()
 * @method void setNumber(int $number)
 * @method string getShelfmark()
 * @method void setShelfmark(string $shelfmark)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getStatusReason()
 * @method void setStatusReason(?string $statusReason)
 * @method string|null getStatusSince()
 * @method void setStatusSince(?string $statusSince)
 * @method string|null getKind()
 * @method void setKind(?string $kind)
 * @method int|null getOriginalsCount()
 * @method void setOriginalsCount(?int $originalsCount)
 * @method int|null getCopiesCount()
 * @method void setCopiesCount(?int $copiesCount)
 * @method string|null getLastCounted()
 * @method void setLastCounted(?string $lastCounted)
 * @method string|null getTitleSource()
 * @method void setTitleSource(?string $titleSource)
 * @method string|null getPages()
 * @method void setPages(?string $pages)
 * @method string|null getVersionsCount()
 * @method void setVersionsCount(?string $versionsCount)
 * @method string|null getPdfFiles()
 * @method void setPdfFiles(?string $pdfFiles)
 * @method string|null getScanFormats()
 * @method void setScanFormats(?string $scanFormats)
 * @method string|null getScanFiles()
 * @method void setScanFiles(?string $scanFiles)
 * @method string|null getHiredFrom()
 * @method void setHiredFrom(?string $hiredFrom)
 * @method string|null getReturnDate()
 * @method void setReturnDate(?string $returnDate)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method int getModified()
 * @method void setModified(int $modified)
 */
class Copy extends Entity implements JsonSerializable {
	use FieldAccess;

	protected int $versionId = 0;
	protected int $binder = 0;
	protected int $number = 0;
	protected string $shelfmark = '';
	protected int $sortOrder = 0;
	protected string $status = 'active';
	protected ?string $statusReason = null;
	protected ?string $statusSince = null;
	protected ?string $kind = null;
	protected ?int $originalsCount = null;
	protected ?int $copiesCount = null;
	protected ?string $lastCounted = null;
	protected ?string $titleSource = null;
	protected ?string $pages = null;
	protected ?string $versionsCount = null;
	protected ?string $pdfFiles = null;
	protected ?string $scanFormats = null;
	protected ?string $scanFiles = null;
	/** hired material (round 3, November 2026): who it is hired from, and the day it must be back */
	protected ?string $hiredFrom = null;
	protected ?string $returnDate = null;
	protected int $created = 0;
	protected int $modified = 0;

	public function __construct() {
		foreach (['versionId', 'binder', 'number', 'sortOrder', 'originalsCount', 'copiesCount', 'created', 'modified'] as $field) {
			$this->addType($field, 'integer');
		}
		foreach (['shelfmark', 'status', 'statusReason', 'statusSince', 'kind', 'lastCounted', 'titleSource', 'pages',
			'versionsCount', 'pdfFiles', 'scanFormats', 'scanFiles', 'hiredFrom', 'returnDate'] as $field) {
			$this->addType($field, 'string');
		}
	}
}
