<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Attribute\RequiresModule;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\ResponseDefinitions;
use OCA\Cantoria\Service\ApiV1\Page;
use OCA\Cantoria\Service\ApiV1\TaskView;
use OCA\Cantoria\Service\ApiV1\Values;
use OCA\Cantoria\Service\CatalogService;
use OCA\Cantoria\Service\DueService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\TaskService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The due list of the own roles, questions and tasks: read, ask, answer
 * (API v1, see docs/api-v1.md). Who may ask and answer is decided by
 * TaskService, as in the app; closing a task stays with people in the app.
 *
 * @psalm-import-type CantoriaDue from ResponseDefinitions
 * @psalm-import-type CantoriaTask from ResponseDefinitions
 * @psalm-import-type CantoriaTaskPage from ResponseDefinitions
 * @psalm-suppress UnusedClass
 */
#[RequiresModule(Modules::AGENT_API)]
class ApiV1TaskController extends OCSController {
	use RunsOcsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private DueService $due,
		private TaskService $tasks,
		private CatalogService $catalog,
		private RightsPolicy $policy,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * What is due for the roles of the account
	 *
	 * The cards of the start page (key, number, translated label) and the
	 * concerts with a deadline now, only of active modules. The tasks behind
	 * the card "tasks_open" are listed by GET /api/v1/tasks?scope=mine.
	 *
	 * @return DataResponse<Http::STATUS_OK, CantoriaDue, array{}>
	 * @throws OCSForbiddenException The account has no role in the music archive
	 * @throws OCSNotFoundException The interface for agents is switched off
	 *
	 * 200: Due list returned
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 600)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/due')]
	public function due(): DataResponse {
		return new DataResponse($this->ocs(function (string $uid): array {
			$this->roles->requireAccess($uid);
			$due = $this->due->forUser($uid);
			return [
				'cards' => array_map(fn (array $card): array => ['label' => $this->l->t($card['label'])] + $card, $due['cards']),
				'concerts' => array_map(fn (array $concert): array => ['label' => $this->l->t($concert['label'])] + $concert,
					$due['concerts']),
			];
		}));
	}

	/**
	 * List questions and tasks
	 *
	 * "mine": open tasks addressed to a role of the account and, for the
	 * catalog librarian, answered tasks to apply; "open": all open and
	 * answered tasks; "all": also the closed ones. In the order of the shelf.
	 *
	 * @param 'mine'|'open'|'all' $scope Which tasks
	 * @param int|null $copy_id Only the tasks of this copy
	 * @param int<1, 100> $limit Tasks per page
	 * @param string|null $cursor The "next_cursor" of the page before; omit it for the first page
	 * @return DataResponse<Http::STATUS_OK, CantoriaTaskPage, array{}>
	 * @throws OCSBadRequestException The scope, the limit or the cursor is not valid
	 * @throws OCSForbiddenException The account has no role in the music archive
	 * @throws OCSNotFoundException Questions and tasks or the interface for agents are switched off
	 *
	 * 200: Page of tasks returned
	 */
	#[NoAdminRequired]
	#[RequiresModule('tasks')]
	#[UserRateLimit(limit: 120, period: 600)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/tasks')]
	public function tasks(string $scope = 'mine', ?int $copy_id = null, int $limit = 25, ?string $cursor = null): DataResponse {
		return new DataResponse($this->ocs(function (string $uid) use ($scope, $copy_id, $limit, $cursor): array {
			$this->roles->requireAccess($uid);
			$rows = match (TaskView::scope($scope)) {
				'open' => $this->tasks->all(false, $uid),
				'all' => $this->tasks->all(true, $uid),
				default => $this->tasks->forUser($uid),
			};
			if ($copy_id !== null) {
				$rows = array_values(array_filter($rows, fn (array $row): bool => Values::intOrNull($row['copy_id'] ?? null) === $copy_id));
			}
			$page = Page::of($rows, TaskView::key(...), $cursor, $limit);
			return ['items' => array_map(fn (array $row): array => TaskView::task($row, $uid), $page['items'])] + $page;
		}));
	}

	/**
	 * Ask a question or create a task about a copy
	 *
	 * Addressed to a role (conductor, librarian, operations, reader), with an
	 * optional place in the score. Allowed for the roles librarian,
	 * conductor, operations and contributor; the addressed role is notified.
	 *
	 * @param int $id ID of the copy
	 * @param 'question'|'check'|'report' $kind Question, check or error report
	 * @param string $addressee Role that should answer: "conductor", "librarian", "operations" or "reader"
	 * @param string $question The question or task (at most 10,000 characters)
	 * @param 'reading'|'structure'|'origin'|'shelf'|'rights'|'other'|null $category Topic
	 * @param int|null $page Page of the score
	 * @param string|null $measure_from First measure as printed, e.g. "17"
	 * @param string|null $measure_to Last measure as printed
	 * @param string|null $voice Voice, e.g. "Tenor II"
	 * @param int|null $verse Verse
	 * @param string|null $origin Where the question comes from, e.g. the name of a transcription round
	 * @param string|null $due Date by which an answer is needed (YYYY-MM-DD)
	 * @return DataResponse<Http::STATUS_CREATED, CantoriaTask, array{}>
	 * @throws OCSBadRequestException A value is missing or not valid
	 * @throws OCSForbiddenException The roles of the account may not create tasks, or the copy is an internal preservation copy it may not view
	 * @throws OCSNotFoundException The copy does not exist, or questions and tasks or the interface for agents are switched off
	 *
	 * 201: Task created
	 */
	#[NoAdminRequired]
	#[RequiresModule('tasks')]
	#[UserRateLimit(limit: 30, period: 600)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/copies/{id}/tasks', requirements: ['id' => '\d+'])]
	public function createTask(int $id, string $kind = 'question', string $addressee = '', string $question = '', ?string $category = null,
		?int $page = null, ?string $measure_from = null, ?string $measure_to = null, ?string $voice = null, ?int $verse = null,
		?string $origin = null, ?string $due = null): DataResponse {
		$data = ['kind' => $kind, 'addressee' => $addressee, 'question' => $question, 'category' => $category, 'page' => $page,
			'measure_from' => $measure_from, 'measure_to' => $measure_to, 'voice' => $voice, 'verse' => $verse, 'origin' => $origin, 'due' => $due];
		return new DataResponse($this->ocs(function (string $uid) use ($id, $data): array {
			['copy' => $copy, 'version' => $version] = $this->catalog->copyWithContext($id);
			$denial = $this->policy->viewDenial($version, $uid, $copy);
			if ($denial !== null) {
				throw new AccessDeniedException($denial);
			}
			$task = $this->tasks->create($id, array_filter($data, fn ($value): bool => $value !== null), $uid);
			return TaskView::task($this->tasks->withContext([$task])[0], $uid);
		}), Http::STATUS_CREATED);
	}

	/**
	 * Answer a question or task
	 *
	 * Only a role the task is addressed to answers, and only while the task
	 * is open. The catalog librarian then applies or discards the answer in
	 * the app.
	 *
	 * @param int $id ID of the task
	 * @param string $answer The answer
	 * @return DataResponse<Http::STATUS_OK, CantoriaTask, array{}>
	 * @throws OCSBadRequestException The answer is empty, or the task is not open any more
	 * @throws OCSForbiddenException The task is addressed to a role the account does not hold
	 * @throws OCSNotFoundException The task does not exist, or questions and tasks or the interface for agents are switched off
	 *
	 * 200: Task answered
	 */
	#[NoAdminRequired]
	#[RequiresModule('tasks')]
	#[UserRateLimit(limit: 30, period: 600)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/tasks/{id}/answer', requirements: ['id' => '\d+'])]
	public function answer(int $id, string $answer = ''): DataResponse {
		return new DataResponse($this->ocs(function (string $uid) use ($id, $answer): array {
			$this->tasks->answer($id, $answer, $uid);
			return TaskView::task($this->tasks->withContext([$this->tasks->find($id, $uid)])[0], $uid);
		}));
	}
}
