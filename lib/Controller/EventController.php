<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Attribute\RequiresModule;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\UserFacingException;
use OCA\Cantoria\Service\EventService;
use OCA\Cantoria\Service\PerformanceImportService;
use OCA\Cantoria\Service\ProgrammeShareService;
use OCA\Cantoria\Service\RepertoireService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\UserFiles;
use OCA\Cantoria\Settings\Admin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Concerts, song lists, documented performances and repertoire figures
 * (milestone 0.4, see docs/api.md).
 *
 * @psalm-suppress UnusedClass
 */
#[RequiresModule('concerts')]
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class EventController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private EventService $events,
		private EventItemMapper $items,
		private PerformanceImportService $performanceImport,
		private ProgrammeShareService $programmeShare,
		private RepertoireService $repertoire,
		private UserFiles $userFiles,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/events')]
	public function list(?string $kind = null): JSONResponse {
		return $this->run(function (string $uid) use ($kind) {
			$this->roles->requireAccess($uid);
			return $this->events->list($kind === '' ? null : $kind);
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/events/{id}', requirements: ['id' => '\d+'])]
	public function detail(int $id): JSONResponse {
		return $this->run(function (string $uid) use ($id) {
			$this->roles->requireAccess($uid);
			$detail = $this->events->detail($id, $uid);
			foreach ($detail['items'] as $index => $item) {
				$warnings = $item['warnings'] ?? [];
				if (is_array($warnings)) {
					$detail['items'][$index]['warnings'] = $this->translateTexts($warnings);
				}
			}
			return $detail;
		});
	}

	/**
	 * @param array<string, mixed> $event
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/events')]
	public function create(string $kind = 'concert', array $event = []): JSONResponse {
		return $this->run(fn (string $uid) => $this->events->create($kind, $event, $uid));
	}

	/**
	 * @param array<string, mixed> $event
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/events/{id}', requirements: ['id' => '\d+'])]
	public function update(int $id, array $event = []): JSONResponse {
		return $this->run(fn (string $uid) => $this->events->update($id, $event, $uid));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/events/{id}/items', requirements: ['id' => '\d+'])]
	public function addItem(int $id, int $copyId = 0): JSONResponse {
		return $this->run(fn (string $uid) => $this->events->addItem($id, $copyId, $uid));
	}

	/**
	 * @param array<string, mixed> $item
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/event-items/{id}', requirements: ['id' => '\d+'])]
	public function updateItem(int $id, array $item = []): JSONResponse {
		return $this->run(fn (string $uid) => $this->events->updateItem($id, $item, $uid));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/event-items/{id}', requirements: ['id' => '\d+'])]
	public function removeItem(int $id): JSONResponse {
		return $this->run(function (string $uid) use ($id) {
			$this->events->removeItem($id, $uid);
			return ['ok' => true];
		});
	}

	/**
	 * @param list<int> $order
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/events/{id}/order', requirements: ['id' => '\d+'])]
	public function reorder(int $id, array $order = []): JSONResponse {
		return $this->run(function (string $uid) use ($id, $order) {
			$this->events->reorder($id, array_map('intval', $order), $uid);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/events/{id}/fix', requirements: ['id' => '\d+'])]
	public function fix(int $id): JSONResponse {
		return $this->run(function (string $uid) use ($id) {
			$this->events->setFixed($id, $uid);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/events/{id}/cancel', requirements: ['id' => '\d+'])]
	public function cancel(int $id): JSONResponse {
		return $this->run(fn (string $uid) => $this->events->cancel($id, $uid));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/events/{id}/reopen', requirements: ['id' => '\d+'])]
	public function reopen(int $id): JSONResponse {
		return $this->run(fn (string $uid) => $this->events->reopen($id, $uid));
	}

	/**
	 * @param list<int> $struck
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/events/{id}/sung', requirements: ['id' => '\d+'])]
	public function sung(int $id, array $struck = []): JSONResponse {
		return $this->run(function (string $uid) use ($id, $struck) {
			$this->events->confirmSung($id, array_map('intval', $struck), $uid);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	#[RequiresModule('performance_import')]
	#[FrontpageRoute(verb: 'POST', url: '/api/event-items/{id}/resolve', requirements: ['id' => '\d+'])]
	public function resolve(int $id, ?int $copyId = null): JSONResponse {
		return $this->run(function (string $uid) use ($id, $copyId) {
			$this->events->resolveItem($id, $copyId, $uid);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	#[RequiresModule('performance_import')]
	#[FrontpageRoute(verb: 'GET', url: '/api/event-items/ambiguous')]
	public function ambiguous(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireAccess($uid);
			return $this->items->findByAssignment(EventItem::AMBIGUOUS);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 60, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/events/{id}/song-order', requirements: ['id' => '\d+'])]
	public function songOrder(int $id): Response {
		if ($this->userId === null || !$this->roles->hasAccess($this->userId)) {
			return new JSONResponse(['error' => $this->l->t('You have no access to the music archive.')], Http::STATUS_FORBIDDEN);
		}
		try {
			$csv = $this->events->songOrderCsv($id, $this->userId, $this->l);
		} catch (UserFacingException $e) {
			$status = $e instanceof AccessDeniedException ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
			return new JSONResponse(['error' => $this->translate($e->getMessage(), $e->getParameters())], $status);
		}
		$response = new DataDownloadResponse($csv, 'song-order-' . $id . '.csv', 'text/csv; charset=utf-8');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		return $response;
	}

	#[NoAdminRequired]
	#[RequiresModule('repertoire')]
	#[FrontpageRoute(verb: 'GET', url: '/api/repertoire')]
	public function repertoire(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireAccess($uid);
			return ['thresholds' => $this->repertoire->thresholds(), 'works' => $this->repertoire->perWork()];
		});
	}

	/**
	 * A setting: admins and the groups given the settings page (Administration privileges).
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[RequiresModule('repertoire')]
	#[FrontpageRoute(verb: 'PUT', url: '/api/repertoire/thresholds')]
	public function setThresholds(int $current = RepertoireService::DEFAULT_CURRENT_YEARS, int $dormant = RepertoireService::DEFAULT_DORMANT_YEARS): JSONResponse {
		return $this->run(function (string $uid) use ($current, $dormant) {
			$this->roles->requireSettingsAccess($uid);
			$this->repertoire->setThresholds($current, $dormant);
			return $this->repertoire->thresholds();
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 600)]
	#[RequiresModule('performance_import')]
	#[FrontpageRoute(verb: 'POST', url: '/api/performances/import')]
	public function importPerformances(int $fileId, bool $dryRun = true, ?string $ownChoir = null): JSONResponse {
		return $this->run(function (string $uid) use ($fileId, $dryRun, $ownChoir) {
			$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
			$file = $this->userFiles->read($uid, $fileId, UserFiles::MAX_IMPORT_BYTES,
				new InvalidInputException('The selected file is not accessible.'));
			return $this->performanceImport->import($file['content'], $file['name'], $uid, $dryRun,
				$ownChoir === null || trim($ownChoir) === '' ? null : trim($ownChoir));
		});
	}

	/**
	 * What sharing a programme with the choir members would do, per piece
	 * (milestone 0.19).
	 */
	#[NoAdminRequired]
	#[RequiresModule('excerpt_links')]
	#[RequiresModule('member_links')]
	#[FrontpageRoute(verb: 'GET', url: '/api/events/{id}/share-options', requirements: ['id' => '\d+'])]
	public function shareOptions(int $id): JSONResponse {
		return $this->run(function (string $uid) use ($id) {
			$options = $this->programmeShare->options($id, $uid);
			$options['items'] = array_map(fn (array $item): array => ['reason' => $this->reasonText($item['reason'])] + $item, $options['items']);
			return $options;
		});
	}

	/**
	 * Shares the chosen pieces of a programme with the choir members: one
	 * link per piece, or an existing one extended (milestone 0.19).
	 *
	 * @param list<int> $items
	 */
	#[NoAdminRequired]
	#[RequiresModule('excerpt_links')]
	#[RequiresModule('member_links')]
	#[UserRateLimit(limit: 30, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/events/{id}/share', requirements: ['id' => '\d+'])]
	public function share(int $id, array $items = [], ?string $expires = null, ?string $note = null, bool $scan = false): JSONResponse {
		return $this->run(function (string $uid) use ($id, $items, $expires, $note, $scan) {
			$result = $this->programmeShare->share($id, ['items' => $items, 'expires' => $expires, 'note' => $note, 'scan' => $scan], $uid);
			$result['failed'] = array_map(fn (array $failed): array => ['reason' => $this->reasonText($failed['reason'])] + $failed, $result['failed']);
			return $result;
		});
	}

	/**
	 * @param array{message: string, parameters: list<string|int>}|null $reason
	 */
	private function reasonText(?array $reason): ?string {
		return $reason === null ? null : $this->l->t($reason['message'], $reason['parameters']);
	}
}
