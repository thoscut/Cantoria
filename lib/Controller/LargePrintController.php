<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Attribute\RequiresModule;
use OCA\Cantoria\Service\LargePrintService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Large-print parts for singers with a visual impairment
 * (LargePrintService): whether a part may be made, and its record.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
#[RequiresModule('large_print')]
class LargePrintController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private LargePrintService $largePrint,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Whether a large-print part of the copy may be made; the reason is
	 * translated.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/copies/{id}/large-print', requirements: ['id' => '\d+'])]
	public function check(int $id): JSONResponse {
		return $this->run(function (string $uid) use ($id): array {
			$check = $this->largePrint->check($id, $uid);
			$check['reason'] = $check['reason'] === null ? null : $this->l->t($check['reason']);
			return $check;
		});
	}

	/**
	 * Records the part that is being printed.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/copies/{id}/large-print', requirements: ['id' => '\d+'])]
	public function record(int $id, string $voice = '', bool $accessible = false): JSONResponse {
		return $this->run(fn (string $uid): array => $this->largePrint->record($id, $voice, $accessible, $uid));
	}
}
