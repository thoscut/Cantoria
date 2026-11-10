<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Controller;

use OCA\Cantoria\Controller\ApiV1CatalogController;
use OCA\Cantoria\Controller\ApiV1ConcertController;
use OCA\Cantoria\Controller\ApiV1ProposalController;
use OCA\Cantoria\Controller\ApiV1ReviewController;
use OCA\Cantoria\Controller\ApiV1StatusController;
use OCA\Cantoria\Controller\ApiV1TaskController;
use OCA\Cantoria\Db\ArchiveFile;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\Proposal;
use OCA\Cantoria\Db\ReviewPoint;
use OCA\Cantoria\Db\Task;
use OCA\Cantoria\Db\TranscriptionReport;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\ModuleDisabledException;
use OCA\Cantoria\Exception\NotFoundException;
use OCA\Cantoria\Service\ApiV1\Page;
use OCA\Cantoria\Service\ApiV1\ReviewView;
use OCA\Cantoria\Service\CatalogService;
use OCA\Cantoria\Service\DueService;
use OCA\Cantoria\Service\EventService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\ProposalService;
use OCA\Cantoria\Service\ReviewService;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\TaskService;
use OCA\Cantoria\Tests\Unit\Support\ModuleSwitches;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The controllers of the API for agents (v1) with the services as test
 * doubles: roles and the visibility of preservation copies, the module
 * status, the mapping of errors to OCS exceptions, pagination and the
 * fixed response shapes.
 */
#[CoversClass(ApiV1StatusController::class)]
#[CoversClass(ApiV1CatalogController::class)]
#[CoversClass(ApiV1TaskController::class)]
#[CoversClass(ApiV1ProposalController::class)]
#[CoversClass(ApiV1ConcertController::class)]
#[CoversClass(ApiV1ReviewController::class)]
#[CoversClass(ReviewView::class)]
#[CoversClass(Page::class)]
final class ApiV1ControllerTest extends TestCase {
	use ModuleSwitches;

	private Roles&Stub $roles;
	private RightsPolicy&Stub $policy;
	private CatalogService&Stub $catalog;

	#[\Override]
	protected function setUp(): void {
		$this->roles = $this->createStub(Roles::class);
		$this->roles->method('rolesOf')->willReturn([Roles::CONTRIBUTOR]);
		$this->roles->method('hasAnyRole')->willReturnCallback(fn (string $uid, array $allowed): bool => in_array(Roles::CONTRIBUTOR, $allowed, true));
		$this->policy = $this->createStub(RightsPolicy::class);
		$this->catalog = $this->createStub(CatalogService::class);
		$this->catalog->method('entries')->willReturn([
			self::entry(3, '1-2', 1, 2),
			self::entry(1, '1-1', 1, 1, ['title' => 'Freude, schöner Götterfunken', 'composer' => 'Beethoven']),
			self::entry(7, '3-1', 3, 1, ['preservation_copy' => true, 'title' => 'Geschützt']),
			self::entry(5, '2-1', 2, 1, ['rights_status' => 'protected', 'confirmed_at' => '2026-01-02', 'has_musicxml' => true]),
			self::entry(9, '10-1', 10, 1, ['status' => 'retired']),
		]);
	}

	/**
	 * A row as CatalogService::entries() returns it.
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private static function entry(int $id, string $shelfmark, int $binder, int $number, array $values = []): array {
		return $values + ['id' => $id, 'work_id' => $id + 100, 'version_id' => $id + 200, 'shelfmark' => $shelfmark, 'binder' => $binder,
			'number' => $number, 'status' => 'active', 'title' => 'Titel ' . $shelfmark, 'composer' => null, 'work_no' => null,
			'arranger_text' => null, 'rights_status' => 'public_domain', 'rights_confirmed' => null, 'confirmed_at' => null,
			'preservation_copy' => false, 'publication' => null, 'valid_since' => null, 'repertoire' => null, 'last_performed' => null,
			'repertoire_class' => 'current', 'repertoire_source' => 'import', 'performances' => 3, 'last_performed_on' => '2025-12-06',
			'song_lists' => [], 'has_musicxml' => false, 'files' => 2, 'pdfs' => 1];
	}

	private function l10n(): IL10N {
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return $l;
	}

	private function catalogController(?string $uid = 'agent'): ApiV1CatalogController {
		return new ApiV1CatalogController($this->createStub(IRequest::class), $uid, $this->roles, $this->catalog, $this->policy,
			$this->l10n(), $this->createStub(LoggerInterface::class));
	}

	/**
	 * @return list<string>
	 */
	private static function shelfmarks(mixed $data): array {
		self::assertIsArray($data);
		self::assertIsArray($data['items']);
		return array_map(fn (mixed $row): string => is_array($row) && is_string($row['shelfmark']) ? $row['shelfmark'] : '', $data['items']);
	}

	public function testTheSearchFollowsTheShelfAndPages(): void {
		$controller = $this->catalogController();
		$first = $controller->search(limit: 2)->getData();
		self::assertSame(['1-1', '1-2'], self::shelfmarks($first), 'shelf order, preservation copy left out');
		self::assertSame(4, $first['total']);
		self::assertIsString($first['next_cursor']);

		$second = $controller->search(limit: 2, cursor: $first['next_cursor'])->getData();
		self::assertSame(['2-1', '10-1'], self::shelfmarks($second), 'binder 10 after binder 2');
		self::assertNull($second['next_cursor'], 'last page');
		self::assertSame(200, $controller->search()->getStatus());
	}

	public function testTheRowsHaveTheFixedShape(): void {
		$rows = $this->catalogController()->search(shelfmark: '2-01')->getData()['items'];
		self::assertSame([[
			'id' => 5, 'shelfmark' => '2-1', 'binder' => 2, 'number' => 1, 'status' => 'active', 'work_id' => 105, 'version_id' => 205,
			'title' => 'Titel 2-1', 'composer' => null, 'work_no' => null, 'arranger_text' => null, 'rights_status' => 'protected',
			'rights_confirmed' => true, 'preservation_copy' => false, 'valid_for_choir' => false, 'repertoire_class' => 'current',
			'performances' => 3, 'last_performed_on' => '2025-12-06', 'has_musicxml' => true, 'files' => 2,
		]], $rows);
	}

	public function testFiltersCombine(): void {
		$controller = $this->catalogController();
		self::assertSame(['1-1'], self::shelfmarks($controller->search(q: 'schöner GÖTTERFUNKEN')->getData()));
		self::assertSame(['1-1'], self::shelfmarks($controller->search(q: 'beethoven', binder: 1)->getData()));
		self::assertSame(['10-1'], self::shelfmarks($controller->search(status: 'retired')->getData()));
		self::assertSame(['2-1'], self::shelfmarks($controller->search(rights_status: 'protected', confirmed: true, has_musicxml: true)->getData()));
		self::assertSame([], self::shelfmarks($controller->search(rights_status: 'none')->getData()));
	}

	public function testPreservationCopiesOnlyForRolesThatMayViewThem(): void {
		$this->policy->method('mayViewPreservationCopies')->willReturn(true);
		$rows = $this->catalogController()->search(shelfmark: '3-1')->getData()['items'];
		self::assertCount(1, $rows);
		self::assertTrue($rows[0]['preservation_copy']);
	}

	/**
	 * @return iterable<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function invalidSearches(): iterable {
		yield 'limit too small' => [['limit' => 0], 'The limit must be between 1 and 100.'];
		yield 'limit too large' => [['limit' => 101], 'The limit must be between 1 and 100.'];
		yield 'made-up cursor' => [['cursor' => 'eyJ4Ijox'], 'This cursor is not valid. Request the first page again without a cursor.'];
		yield 'not base64url' => [['cursor' => 'a b'], 'This cursor is not valid. Request the first page again without a cursor.'];
		yield 'unknown status' => [['status' => 'burnt'], '"Status": value not allowed.'];
		yield 'unknown repertoire class' => [['repertoire_class' => 'evergreen'], '"Repertoire": value not allowed.'];
	}

	/**
	 * @param array<string, mixed> $arguments
	 */
	#[DataProvider('invalidSearches')]
	public function testInvalidSearchesAnswer400(array $arguments, string $message): void {
		try {
			$this->catalogController()->search(...$arguments);
			self::fail('accepted');
		} catch (OCSBadRequestException $e) {
			self::assertSame($message, $e->getMessage());
			self::assertSame(Http::STATUS_BAD_REQUEST, $e->getCode());
		}
	}

	public function testWithoutARoleEverythingAnswers403(): void {
		$this->roles->method('requireAccess')->willThrowException(new AccessDeniedException('You have no access to the music archive.'));
		$this->expectException(OCSForbiddenException::class);
		$this->expectExceptionMessage('You have no access to the music archive.');
		$this->catalogController()->search();
	}

	public function testErrorsBecomeOcsExceptions(): void {
		$catalog = $this->createStub(CatalogService::class);
		$catalog->method('entry')->willThrowException(new NotFoundException('Not found.'));
		$catalog->method('entries')->willThrowException(new \RuntimeException('database gone: secret detail'));
		$catalog->method('copyWithContext')->willThrowException(new ModuleDisabledException('tasks'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error');
		$controller = new ApiV1CatalogController($this->createStub(IRequest::class), 'agent', $this->roles, $catalog, $this->policy,
			$this->l10n(), $logger);

		try {
			$controller->copy(99);
			self::fail('found');
		} catch (OCSNotFoundException $e) {
			self::assertSame('Not found.', $e->getMessage());
		}
		try {
			$controller->search();
			self::fail('no error');
		} catch (OCSException $e) {
			self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $e->getCode());
			self::assertStringNotContainsString('secret', $e->getMessage(), 'internal details stay in the log');
		}
		try {
			$controller->reference(1);
			self::fail('no error');
		} catch (OCSNotFoundException $e) {
			self::assertSame('This function is switched off.', $e->getMessage());
		}
	}

	public function testWithoutLoginNothingRuns(): void {
		$this->expectException(OCSForbiddenException::class);
		$this->catalogController(null)->search();
	}

	/**
	 * @return array{copy: Copy, version: WorkVersion, work: Work}
	 */
	private static function copyContext(bool $preservationCopy): array {
		$work = new Work();
		$work->setId(101);
		$work->setTitle('Freude,  schöner Götterfunken');
		$version = new WorkVersion();
		$version->setId(201);
		$version->setWorkId(101);
		$version->setPreservationCopy($preservationCopy);
		$version->setRightsStatus('public_domain');
		$version->setConfirmedAt('2026-03-01');
		$version->setConfirmedByName('Anna Archiv');
		$copy = new Copy();
		$copy->setId(1);
		$copy->setVersionId(201);
		$copy->setShelfmark('5-1');
		$copy->setBinder(5);
		$copy->setNumber(1);
		$copy->setStatus('active');
		return ['copy' => $copy, 'version' => $version, 'work' => $work];
	}

	public function testACopyWithoutAccountNamesAndAPreservationCopyOnlyForItsRoles(): void {
		$context = self::copyContext(false);
		$file = new ArchiveFile();
		$file->setId(7);
		$file->setName('5-01_Ehre.pdf');
		$file->setPath('Mappe_5/5-01_Ehre.pdf');
		$file->setType('pdf');
		$file->setRole('score_pdf');
		$file->setFound(true);
		$task = new Task();
		$task->setId(4);
		$task->setCopyId(1);
		$task->setAddressee(Roles::CONDUCTOR);
		$task->setQuestion('Takt 3?');
		$task->setCreatedBy('agent');
		$task->setCreated(1761134400);
		$this->catalog->method('entry')->willReturn($context + ['files' => [$file], 'score' => null, 'licenses' => [],
			'releases' => ['portal' => 'Rights not clarified.', 'onPortal' => false, 'printStation' => null], 'tasks' => [$task], 'links' => [],
			'proposals' => [], 'siblings' => [['id' => 2, 'shelfmark' => '6-8', 'version_id' => 5, 'status' => 'active']], 'contributions' => [],
			'protection' => null, 'merges' => [], 'audit' => []]);
		$this->policy->method('viewDenial')->willReturnCallback(fn (WorkVersion $version): ?string => $version->getPreservationCopy() === true
			? 'Internal preservation copy: only the catalog librarian and the conductor may view it.' : null);

		$copy = $this->catalogController()->copy(1)->getData();
		self::assertSame('5-1', $copy['shelfmark']);
		self::assertSame(['work', 'version', 'copy'], array_keys($copy['fields']));
		self::assertArrayHasKey('rights_status', $copy['fields']['version']);
		self::assertArrayNotHasKey('confirmed_by_name', $copy['fields']['version'], 'no account names');
		self::assertArrayNotHasKey('confirmed_by', $copy['fields']['version']);
		self::assertSame('2026-03-01', $copy['rights']['confirmed_on']);
		self::assertTrue($copy['rights']['confirmed']);
		self::assertSame(['allowed' => false, 'reason' => 'Rights not clarified.', 'on_portal' => false], $copy['rights']['portal']);
		self::assertSame([['id' => 7, 'name' => '5-01_Ehre.pdf', 'path' => 'Mappe_5/5-01_Ehre.pdf', 'type' => 'pdf', 'role' => 'score_pdf',
			'found' => true]], $copy['files']);
		self::assertSame('2025-10-22T12:00:00Z', $copy['tasks'][0]['created_at']);
		self::assertTrue($copy['tasks'][0]['own']);
		self::assertSame('5-1', $copy['tasks'][0]['shelfmark']);
		self::assertStringNotContainsString('Anna Archiv', (string)json_encode($copy));

		$version = $context['version'];
		$version->setPreservationCopy(true);
		$this->expectException(OCSForbiddenException::class);
		$this->expectExceptionMessage('Internal preservation copy: only the catalog librarian and the conductor may view it.');
		$this->catalogController()->copy(1);
	}

	public function testATextReference(): void {
		$this->catalog->method('copyWithContext')->willReturn(self::copyContext(false));
		$data = $this->catalogController()->reference(1, page: 2, measure_from: '17', measure_to: '24', voice: 'Tenor II')->getData();
		self::assertSame(['text' => '5-1 · Freude, schöner Götterfunken · p. 2 · m. 17–24 · Tenor II', 'shelfmark' => '5-1', 'title' => 'Freude,  schöner Götterfunken'], $data);
		$this->expectException(OCSBadRequestException::class);
		$this->catalogController()->reference(1, measure_from: '17-24');
	}

	public function testTheModuleStatusTellsWhatTheAccountMayDo(): void {
		$controller = new ApiV1StatusController($this->createStub(IRequest::class), 'agent', $this->roles,
			$this->modulesWithout(['statistics']), $this->policy, $this->l10n(), $this->createStub(LoggerInterface::class));
		$data = $controller->modules()->getData();
		self::assertSame(1, $data['api_version']);
		self::assertSame([Roles::CONTRIBUTOR], $data['roles']);
		self::assertTrue($data['modules'][Modules::AGENT_API]);
		self::assertFalse($data['modules']['statistics']);
		self::assertSame(['create_tasks' => true, 'answer_tasks_for' => [], 'propose' => true, 'view_preservation_copies' => false,
			'submit_transcription_reports' => true], $data['permissions']);
		self::assertContains('key_signature', $data['proposable_fields']['version']);
		self::assertNotContains('confirmed_at', $data['proposable_fields']['version'], 'decisions are never proposed');
		self::assertNotContains('valid_since', $data['proposable_fields']['version']);
		self::assertSame(['question', 'check', 'report'], $data['task_options']['kinds']);
	}

	private function taskController(TaskService $tasks): ApiV1TaskController {
		$this->catalog->method('copyWithContext')->willReturn(self::copyContext(false));
		return new ApiV1TaskController($this->createStub(IRequest::class), 'agent', $this->roles, $this->createStub(DueService::class), $tasks,
			$this->catalog, $this->policy, $this->l10n(), $this->createStub(LoggerInterface::class));
	}

	public function testCreatingATaskAnswers201WithTheTask(): void {
		$tasks = $this->createMock(TaskService::class);
		$tasks->expects(self::once())->method('create')
			->with(1, ['kind' => 'question', 'addressee' => 'conductor', 'question' => 'Takt 3: fis oder f?', 'measure_from' => '3'], 'agent')
			->willReturnCallback(function (int $copyId, array $data): Task {
				$task = new Task();
				$task->setId(12);
				return $task;
			});
		$tasks->method('withContext')->willReturn([['id' => 12, 'kind' => 'question', 'status' => 'open', 'copy_id' => 1, 'addressee' => 'conductor',
			'question' => 'Takt 3: fis oder f?', 'measure_from' => '3', 'created_by' => 'agent', 'created' => 1761134400, 'shelfmark' => '5-1',
			'binder' => 5, 'number' => 1, 'title' => 'Freude, schöner Götterfunken']]);
		$response = $this->taskController($tasks)->createTask(1, addressee: 'conductor', question: 'Takt 3: fis oder f?', measure_from: '3');
		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame(12, $response->getData()['id']);
		self::assertSame('open', $response->getData()['status']);
		self::assertTrue($response->getData()['own']);
	}

	public function testOnlyTheAddressedRoleAnswers(): void {
		$tasks = $this->createStub(TaskService::class);
		$tasks->method('answer')->willThrowException(new AccessDeniedException('Only the addressed role can answer this task.'));
		$this->expectException(OCSForbiddenException::class);
		$this->expectExceptionMessage('Only the addressed role can answer this task.');
		$this->taskController($tasks)->answer(4, 'yes');
	}

	public function testTaskListsPageInShelfOrder(): void {
		$tasks = $this->createStub(TaskService::class);
		$row = fn (int $id, int $binder, int $number): array => ['id' => $id, 'kind' => 'check', 'status' => 'open', 'copy_id' => $id,
			'addressee' => 'reader', 'created' => 1, 'binder' => $binder, 'number' => $number, 'shelfmark' => $binder . '-' . $number];
		$tasks->method('all')->willReturn([$row(1, 1, 1), $row(2, 1, 2), $row(3, 2, 1)]);
		$controller = $this->taskController($tasks);
		$first = $controller->tasks('open', limit: 2)->getData();
		self::assertSame(['1-1', '1-2'], self::shelfmarks($first));
		$second = $controller->tasks('open', limit: 2, cursor: $first['next_cursor'])->getData();
		self::assertSame(['2-1'], self::shelfmarks($second));
		self::assertSame(['1-2'], self::shelfmarks($controller->tasks('all', copy_id: 2)->getData()));
		$this->expectException(OCSBadRequestException::class);
		/** @psalm-suppress InvalidArgument a value the API refuses, on purpose */
		$controller->tasks('everything');
	}

	public function testProposalsAre201AndOwnOnesCanBeRead(): void {
		$proposal = new Proposal();
		$proposal->setId(8);
		$proposal->setObjectType('version');
		$proposal->setObjectId(201);
		$proposal->setField('key_signature');
		$proposal->setNewValue('"D major"');
		$proposal->setStatus(Proposal::OPEN);
		$proposal->setCreatedBy('agent');
		$proposal->setCreated(1761134400);
		$proposals = $this->createMock(ProposalService::class);
		$proposals->expects(self::once())->method('propose')
			->with('version', 201, ['key_signature' => 'D major'], 'agent', 'from the score', 'tool')->willReturn([$proposal]);
		$proposals->method('own')->willReturnCallback(fn (string $uid, ?string $status): array => $status === 'rejected' ? [] : [$proposal]);
		$controller = new ApiV1ProposalController($this->createStub(IRequest::class), 'agent', $this->roles, $proposals, $this->l10n(),
			$this->createStub(LoggerInterface::class));

		$response = $controller->propose('version', 201, ['key_signature' => 'D major'], 'from the score', 'tool');
		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame([['id' => 8, 'level' => 'version', 'object_id' => 201, 'field' => 'key_signature', 'value' => 'D major',
			'reason' => null, 'source' => null, 'status' => 'open', 'decision_reason' => null, 'created_at' => '2025-10-22T12:00:00Z',
			'decided_at' => null, 'own' => true]], $response->getData());
		self::assertSame(1, $controller->proposals()->getData()['total']);
		self::assertSame([], $controller->proposals('rejected')->getData()['items']);
	}

	public function testRefusedProposalsAnswer403And400(): void {
		$errors = [
			new AccessDeniedException('This requires one of these roles: %s', ['Proposals (tools)']),
			new InvalidInputException('"%s" cannot be proposed.', ['confirmed_at']),
		];
		$proposals = $this->createStub(ProposalService::class);
		$proposals->method('propose')->willReturnCallback(function () use (&$errors): array {
			throw array_shift($errors) ?? new \LogicException('called too often');
		});
		$controller = new ApiV1ProposalController($this->createStub(IRequest::class), 'reader', $this->roles, $proposals, $this->l10n(),
			$this->createStub(LoggerInterface::class));
		try {
			$controller->propose('version', 1, ['scoring' => 'TTBB']);
			self::fail('accepted');
		} catch (OCSForbiddenException $e) {
			self::assertSame('This requires one of these roles: Proposals (tools)', $e->getMessage());
		}
		try {
			$controller->propose('version', 1, ['confirmed_at' => '2026-01-01']);
			self::fail('accepted');
		} catch (OCSBadRequestException $e) {
			self::assertSame('"confirmed_at" cannot be proposed.', $e->getMessage());
		}
	}

	public function testConcertsAreListedByDateAndRead(): void {
		$events = $this->createStub(EventService::class);
		$event = fn (int $id, ?string $date, string $kind = 'concert'): array => ['id' => $id, 'kind' => $kind, 'title' => 'E' . $id,
			'event_date' => $date, 'status' => 'draft', 'imported' => false, 'items' => 2];
		$events->method('list')->willReturn([$event(1, '2026-12-06'), $event(2, null, 'song_list'), $event(3, '2026-05-01')]);
		$concert = new Event();
		$concert->setId(3);
		$concert->setTitle('Frühjahrskonzert');
		$concert->setStatus('fixed');
		$events->method('detail')->willReturn(['event' => $concert, 'items' => [['id' => 30, 'position' => 1, 'copy_id' => 1, 'shelfmark' => '5-1',
			'title' => 'Freude, schöner Götterfunken', 'duration' => 180, 'encore' => false, 'struck' => false, 'assignment' => 'certain',
			'warnings' => ['Rights not clarified.']]], 'totalDuration' => 180, 'missingDurations' => 0]);
		$controller = new ApiV1ConcertController($this->createStub(IRequest::class), 'agent', $this->roles, $events, $this->l10n(),
			$this->createStub(LoggerInterface::class));

		$ids = fn (array $data): array => array_column($data['items'], 'id');
		self::assertSame([3, 1, 2], $ids($controller->concerts()->getData()), 'by date, without a date last');
		self::assertSame([3], $ids($controller->concerts(kind: 'concert', from: '2026-01-01', to: '2026-06-30')->getData()));
		$detail = $controller->concert(3)->getData();
		self::assertSame('Frühjahrskonzert', $detail['title']);
		self::assertSame(['Rights not clarified.'], $detail['items'][0]['warnings']);
		self::assertSame(180, $detail['total_duration']);
		$this->expectException(OCSBadRequestException::class);
		$controller->concerts(from: '2026-02-31');
	}

	private function reviewController(ReviewService $review): ApiV1ReviewController {
		return new ApiV1ReviewController($this->createStub(IRequest::class), 'agent', $this->roles, $review, $this->catalog, $this->policy,
			$this->l10n(), $this->createStub(LoggerInterface::class));
	}

	private static function report(int $id, int $copyId, ?int $supersededBy = null): TranscriptionReport {
		$report = new TranscriptionReport();
		$report->setId($id);
		$report->setCopyId($copyId);
		$report->setVersionId(201);
		$report->setSource(TranscriptionReport::SOURCE_API);
		$report->setSha256(str_repeat('a', 64));
		$report->setRound('2');
		$report->setBasename('5-01_Ehre');
		$report->setReview('7 measures, 4 voices; uncertain: m. 3');
		$report->setRaw('{"format":"bericht/1","secret":"evidence only"}');
		$report->setSummary('{"title":"Freude, schöner Götterfunken","warnings":[["Unknown field \"%s\" in the report was ignored.",["farbe"]]]}');
		$report->setSupersededBy($supersededBy);
		$report->setCreatedBy('agent');
		$report->setCreated(1761134400);
		return $report;
	}

	private static function reviewPoint(int $id, int $copyId, string $status = ReviewPoint::OPEN, string $role = Roles::CONDUCTOR): ReviewPoint {
		$point = new ReviewPoint();
		$point->setId($id);
		$point->setCopyId($copyId);
		$point->setVersionId(201);
		$point->setReportId(4);
		$point->setCategory(ReviewPoint::UNCERTAIN);
		$point->setMeasures('3');
		$point->setMeasureFrom('3');
		$point->setMeasureTo('3');
		$point->setVoice('Tenor');
		$point->setText('c or c sharp');
		$point->setPointKey('internal-key');
		$point->setRole($role);
		$point->setStatus($status);
		$point->setCreatedBy('someone-else');
		$point->setCreated(1761134400);
		if ($status !== ReviewPoint::OPEN) {
			$point->setDecidedBy('anna');
			$point->setDecidedByName('Anna Archiv');
			$point->setDecided(1761138000);
			$point->setDecisionReason('as in the scan');
		}
		return $point;
	}

	public function testTheReviewOfACopyHasNoAccountNamesAndComesInPages(): void {
		$this->catalog->method('copyWithContext')->willReturn(self::copyContext(false));
		$review = $this->createStub(ReviewService::class);
		$review->method('forVersion')->willReturn(['version_id' => 201,
			'reports' => [self::report(6, 1), self::report(5, 2), self::report(4, 1, 6)],
			'points' => [self::reviewPoint(10, 1), self::reviewPoint(11, 2), self::reviewPoint(12, 1, ReviewPoint::CONFIRMED),
				self::reviewPoint(13, 1, ReviewPoint::OPEN, Roles::LIBRARIAN)],
			'counts' => [], 'may' => ['decideMusical' => false, 'decideMaterial' => false, 'ingest' => true, 'correct' => false]]);
		$controller = $this->reviewController($review);

		$first = $controller->review(1, limit: 2)->getData();
		self::assertSame(1, $first['copy_id']);
		self::assertSame('5-1', $first['shelfmark']);
		self::assertSame(201, $first['version_id']);
		self::assertSame(['open' => 2, 'confirmed' => 1, 'correct' => 0, 'corrected' => 0, 'question' => 0, 'dismissed' => 0], $first['counts'],
			'only the points of this copy');
		self::assertSame([6, 4], array_column($first['reports'], 'id'), 'reports of this copy, newest first');
		self::assertSame('Freude, schöner Götterfunken', $first['reports'][0]['title']);
		self::assertSame(['Unknown field "farbe" in the report was ignored.'], $first['reports'][0]['warnings']);
		self::assertTrue($first['reports'][0]['own']);
		self::assertSame('2025-10-22T12:00:00Z', $first['reports'][0]['created_at']);
		self::assertSame([10, 12], array_column($first['items'], 'id'));
		self::assertSame(3, $first['total']);
		self::assertIsString($first['next_cursor']);
		self::assertSame('2025-10-22T13:00:00Z', $first['items'][1]['decided_at']);
		self::assertSame('as in the scan', $first['items'][1]['decision_reason']);
		self::assertFalse($first['items'][0]['own']);
		$json = (string)json_encode($first);
		foreach (['decided_by', 'created_by', 'Anna Archiv', 'anna', 'someone-else', 'point_key', 'internal-key', 'evidence only', 'raw'] as $hidden) {
			self::assertStringNotContainsString($hidden, $json, 'no account names, no internal key, no received text');
		}

		$second = $controller->review(1, limit: 2, cursor: $first['next_cursor'])->getData();
		self::assertSame([13], array_column($second['items'], 'id'));
		self::assertNull($second['next_cursor']);
		self::assertSame([13], array_column($controller->review(1, role: 'librarian')->getData()['items'], 'id'));
		self::assertSame([12], array_column($controller->review(1, status: 'confirmed')->getData()['items'], 'id'));
		self::assertSame(200, $controller->review(1)->getStatus());
	}

	public function testTheReviewOfAPreservationCopyIsRefused(): void {
		$this->catalog->method('copyWithContext')->willReturn(self::copyContext(true));
		$this->policy->method('viewDenial')->willReturn('Internal preservation copy: only the catalog librarian and the conductor may view it.');
		$review = $this->createMock(ReviewService::class);
		$review->expects(self::never())->method('forVersion');
		$review->expects(self::never())->method('ingestForCopy');
		$controller = $this->reviewController($review);
		foreach ([fn () => $controller->review(1), fn () => $controller->submitReport(1, '{}')] as $call) {
			try {
				$call();
				self::fail('a preservation copy was passed on');
			} catch (OCSForbiddenException $e) {
				self::assertSame('Internal preservation copy: only the catalog librarian and the conductor may view it.', $e->getMessage());
			}
		}
	}

	/**
	 * @return iterable<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function invalidReviewFilters(): iterable {
		yield 'unknown status' => [['status' => 'deleted'], '"Status": value not allowed.'];
		yield 'role without points' => [['role' => 'operations'], '"Role": value not allowed.'];
		yield 'limit too large' => [['limit' => 101], 'The limit must be between 1 and 100.'];
	}

	/**
	 * @param array<string, mixed> $arguments
	 */
	#[DataProvider('invalidReviewFilters')]
	public function testInvalidReviewFiltersAnswer400(array $arguments, string $message): void {
		$this->catalog->method('copyWithContext')->willReturn(self::copyContext(false));
		$review = $this->createStub(ReviewService::class);
		$review->method('forVersion')->willReturn(['version_id' => 201, 'reports' => [], 'points' => [], 'counts' => [],
			'may' => ['decideMusical' => false, 'decideMaterial' => false, 'ingest' => false, 'correct' => false]]);
		$this->expectExceptionObject(new OCSBadRequestException($message));
		$this->reviewController($review)->review(1, ...$arguments);
	}

	public function testCorrectionOrdersLeaveOutPreservationCopiesAndComeInPages(): void {
		$order = fn (int $id, int $copyId, bool $preservation): array => ['id' => $id, 'version_id' => 201, 'copy_id' => $copyId,
			'report_id' => 4, 'file_id' => 12, 'file_sha256' => 'abc', 'category' => 'uncertain', 'measures' => '12–14', 'measure_from' => '12',
			'measure_to' => '14', 'voice' => 'B', 'verse' => null, 'page' => null, 'text' => 'd or c', 'point_key' => 'k', 'role' => 'conductor',
			'status' => 'correct', 'correction' => 'd, as in the scan', 'decided_by' => 'anna', 'decided_by_name' => 'Anna Archiv',
			'decided' => 1761138000, 'decision_reason' => null, 'task_id' => null, 'resolved_report_id' => null, 'created_by' => 'agent',
			'created' => 1761134400, 'shelfmark' => '5-1', 'title' => 'Freude, schöner Götterfunken', 'basename' => '5-01_Ehre', 'file_name' => '5-01_Ehre.mxl',
			'preservation_copy' => $preservation];
		$review = $this->createStub(ReviewService::class);
		$review->method('orders')->willReturn([$order(30, 1, false), $order(31, 7, true), $order(32, 2, false), $order(33, 1, false)]);
		$controller = $this->reviewController($review);

		$first = $controller->orders(limit: 2)->getData();
		self::assertSame([30, 32], array_column($first['items'], 'id'), 'the preservation copy is left out');
		self::assertSame(3, $first['total']);
		self::assertSame([33], array_column($controller->orders(limit: 2, cursor: $first['next_cursor'])->getData()['items'], 'id'));
		self::assertSame([30, 33], array_column($controller->orders(copy_id: 1)->getData()['items'], 'id'));
		self::assertSame(['id' => 30, 'copy_id' => 1, 'version_id' => 201, 'shelfmark' => '5-1', 'title' => 'Freude, schöner Götterfunken', 'report_id' => 4,
			'basename' => '5-01_Ehre', 'file_id' => 12, 'file_name' => '5-01_Ehre.mxl', 'file_sha256' => 'abc', 'category' => 'uncertain',
			'measures' => '12–14', 'measure_from' => '12', 'measure_to' => '14', 'voice' => 'B', 'verse' => null, 'page' => null,
			'text' => 'd or c', 'role' => 'conductor', 'correction' => 'd, as in the scan', 'decision_reason' => null,
			'decided_at' => '2025-10-22T13:00:00Z', 'own' => true], $first['items'][0]);

		$this->policy->method('mayViewPreservationCopies')->willReturn(true);
		self::assertSame([30, 31, 32, 33], array_column($controller->orders()->getData()['items'], 'id'), 'the librarian sees every order');
	}

	public function testAReportIs201AndTheSameReportAgain200(): void {
		$this->catalog->method('copyWithContext')->willReturn(self::copyContext(false));
		$text = '{"format":"bericht/1","signatur":"5-1","basisname":"5-01_Ehre"}';
		$result = fn (bool $unchanged): array => ['report' => self::report(6, 1), 'unchanged' => $unchanged,
			'points' => ['created' => $unchanged ? 0 : 3, 'kept' => 1, 'closed' => 0, 'open' => 4], 'proposals' => 1, 'proposalsSkipped' => false,
			'warnings' => [['"%s" is missing in the report.', ['lernen']]]];
		$review = $this->createMock(ReviewService::class);
		$review->expects(self::exactly(2))->method('ingestForCopy')
			->with(1, self::callback(fn (string $raw): bool => json_decode($raw, true) === json_decode($text, true)), 'agent')
			->willReturnOnConsecutiveCalls($result(false), $result(true));
		$controller = $this->reviewController($review);

		$created = $controller->submitReport(1, $text);
		self::assertSame(Http::STATUS_CREATED, $created->getStatus());
		self::assertSame(['created' => 3, 'kept' => 1, 'closed' => 0, 'open' => 4], $created->getData()['points']);
		self::assertSame(['"lernen" is missing in the report.'], $created->getData()['warnings']);
		self::assertFalse($created->getData()['proposals_skipped']);
		self::assertSame(6, $created->getData()['report']['id']);
		self::assertStringNotContainsString('evidence only', (string)json_encode($created->getData()), 'the received text stays on the server');

		$again = $controller->submitReport(1, report: ['format' => 'bericht/1', 'signatur' => '5-1', 'basisname' => '5-01_Ehre']);
		self::assertSame(Http::STATUS_OK, $again->getStatus());
		self::assertTrue($again->getData()['unchanged']);
	}

	public function testAReportComesAsTextOrAsObjectAndOnlyFromItsRoles(): void {
		$this->catalog->method('copyWithContext')->willReturn(self::copyContext(false));
		$review = $this->createStub(ReviewService::class);
		$review->method('ingestForCopy')->willThrowException(new AccessDeniedException('This requires one of these roles: %s', ['Catalog librarian']));
		$controller = $this->reviewController($review);
		foreach ([[null, null], ['{}', ['format' => 'bericht/1']]] as [$content, $report]) {
			try {
				$controller->submitReport(1, $content, $report);
				self::fail('accepted');
			} catch (OCSBadRequestException $e) {
				self::assertSame('Please send the report either as text or as an object.', $e->getMessage());
			}
		}
		$this->expectException(OCSForbiddenException::class);
		$controller->submitReport(1, '{}');
	}
}
