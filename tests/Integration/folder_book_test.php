<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Integration test of the folder book (module "folder_book") against a real
 * Nextcloud instance with an imported catalog:
 * - a folder with contents, the hint for a copy that was retired,
 * - issuing to a name and to a Nextcloud account, issuing twice refused (in
 *   the service and by the unique index), taking back with condition and
 *   missing sheets, which become those of the folder,
 * - taking a folder out of service only when it is back,
 * - the retention period with a staged day: nothing is anonymised a day
 *   before it ends, then name, account and notes disappear from the loan
 *   and from its entries of the audit log; the loan stays and the audit
 *   log notes the anonymisation without the name,
 * - a folder that never comes back, access and erasure by name,
 * - accounts and names in the values of the audit log (who confirmed a
 *   rights status): found by the report, removed by the erasure, by a
 *   deleted account and by the retention period, a second run changes
 *   nothing (R B27),
 * - the roles: catalog librarian and operations maintain it, conductor and
 *   reader read it, contributors do not see it (nor its audit entries),
 * - the routes answer 404 while the module is off; the data stay.
 *
 * Usage (as the web server user):
 *   php tests/Integration/folder_book_test.php <nextcloud-root> <uid> <shelfmark>
 *
 * <uid> must hold the role "librarian"; <shelfmark> is an active copy. For
 * the roles the test gives the account's groups other roles for a while.
 * Everything is rolled back; the module switches and the role groups are
 * exactly as before.
 */

require __DIR__ . '/harness.php';
[$root, $uid, $shelfmark] = IntegrationTest::start($argv, ['nextcloud-root', 'uid', 'shelfmark']);

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Controller\FolderBookController;
use OCA\Cantoria\Db\AuditEntryMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\FolderLoan;
use OCA\Cantoria\Db\FolderLoanMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\ModuleDisabledException;
use OCA\Cantoria\Middleware\ModuleMiddleware;
use OCA\Cantoria\Service\AuditLog;
use OCA\Cantoria\Service\Fields;
use OCA\Cantoria\Service\FolderBookService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\PrivacyService;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCP\DB\Exception as DbException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Server;
use Psr\Log\LoggerInterface;

$check = IntegrationTest::check(...);
$throws = IntegrationTest::throws(...);

$db = Server::get(IDBConnection::class);
$appConfig = Server::get(IAppConfig::class);
$modules = Server::get(Modules::class);
$roles = Server::get(Roles::class);
$book = Server::get(FolderBookService::class);
$loans = Server::get(FolderLoanMapper::class);
$auditEntries = Server::get(AuditEntryMapper::class);
$today = Server::get(RightsService::class)->today($uid);

$switchKeys = [Modules::FOLDER_BOOK];
$switches = function () use ($appConfig, $switchKeys): array {
	$values = [];
	foreach ($switchKeys as $key) {
		$configKey = Modules::CONFIG_PREFIX . $key;
		$values[$key] = $appConfig->hasKey(Application::APP_ID, $configKey, null) ? $appConfig->getValueBool(Application::APP_ID, $configKey) : null;
	}
	return $values;
};
/** @return array<string, list<string>> role => groups */
$roleGroups = function () use ($roles): array {
	$groups = [];
	foreach (Roles::ALL as $role) {
		$groups[$role] = $roles->groups($role);
	}
	return $groups;
};
$originalSwitches = $switches();
$originalGroups = $roleGroups();
$user = Server::get(IUserManager::class)->get($uid);
$check('test account exists', $user !== null);
$userGroups = $user === null ? [] : Server::get(IGroupManager::class)->getUserGroupIds($user);
/** Gives the account exactly these roles (through its own groups). */
$onlyRoles = function (array $wanted) use ($roles, $userGroups, $originalGroups): void {
	foreach (Roles::ALL as $role) {
		$roles->setGroups($role, in_array($role, $wanted, true)
			? array_values(array_unique([...$originalGroups[$role], ...$userGroups]))
			: array_values(array_diff($originalGroups[$role], $userGroups)));
	}
};
/** @return array<string, mixed>|null the folder with this id in the overview */
$folderRow = function (int $id) use ($book, $uid): ?array {
	foreach ($book->overview($uid)['folders'] as $folder) {
		if ($folder['id'] === $id) {
			return $folder;
		}
	}
	return null;
};
/** Old and new values of the audit entries of a loan. */
$loanAudit = function (int $loanId) use ($auditEntries): array {
	return array_map(fn ($entry): array => [$entry->getAction(), $entry->getField(), $entry->getOldValue(), $entry->getNewValue(), $entry->getUserId(),
		$entry->getReason()], $auditEntries->findForObjects([[FolderBookService::OBJECT_LOAN, $loanId]], 100));
};

$db->beginTransaction();
try {
	$modules->change([Modules::FOLDER_BOOK => true]);
	$onlyRoles([Roles::LIBRARIAN]);
	$copy = Server::get(CopyMapper::class)->findAllByShelfmark()[$shelfmark] ?? null;
	$check('the copy exists', $copy !== null, $shelfmark);
	if ($copy === null) {
		throw new RuntimeException('copy ' . $shelfmark . ' not found');
	}
	$label = 'Integration ' . bin2hex(random_bytes(3));

	// ---- a folder with contents -----------------------------------------------------
	$id = $book->createFolder(['label' => $label, 'voice' => 'Tenor 1', 'contents' => [['copyId' => $copy->getId(), 'count' => 2]],
		'note' => 'integration test'], $uid)['id'];
	$folder = $folderRow($id);
	$check('the folder is listed with its contents', $folder !== null && $folder['voice'] === 'Tenor 1' && $folder['wear'] === 'good'
		&& $folder['contents'][0]['shelfmark'] === $shelfmark && $folder['contents'][0]['count'] === 2, json_encode($folder) ?: '');
	$check('a second folder in service with the same name is refused',
		$throws(fn () => $book->createFolder(['label' => mb_strtoupper($label)], $uid)) !== null);

	// ---- issue and take back ----------------------------------------------------------
	$first = $book->issue($id, ['holderName' => 'Folder Test Person', 'note' => 'Folder Test Person comes on Tuesdays'], $uid)['id'];
	$folder = $folderRow($id);
	$check('the folder is out with the person', ($folder['loan']['holderName'] ?? null) === 'Folder Test Person'
		&& $folder['loan']['issuedOn'] === $today && $folder['loan']['holderAccount'] === false, json_encode($folder['loan'] ?? null) ?: '');
	$check('issuing it again is refused', $throws(fn () => $book->issue($id, ['holderName' => 'Someone Else'], $uid))
		=== 'This folder is already out with someone. Take it back first.');
	$check('taking it out of service while it is out is refused',
		$throws(fn () => $book->updateFolder($id, ['status' => 'retired', 'statusReason' => 'test'], $uid)) !== null);
	$duplicate = new FolderLoan();
	$duplicate->setFolderId($id);
	$duplicate->setOpenFolderId($id);
	$duplicate->setHolderName('Race');
	$duplicate->setIssuedOn($today);
	try {
		$db->executeStatement('SAVEPOINT folder_book_race');
		$loans->insert($duplicate);
		$check('the database refuses a second open loan', false);
	} catch (DbException $e) {
		$db->executeStatement('ROLLBACK TO SAVEPOINT folder_book_race');
		$check('the database refuses a second open loan', $e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
	}

	$check('a day before the issue is refused for the return', $throws(fn () => $book->takeBack($first,
		['returnedOn' => (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d'), 'wear' => 'good'], $uid)) !== null);
	$book->takeBack($first, ['wear' => 'worn', 'missing' => 'page 2 of ' . $shelfmark, 'note' => 'Folder Test Person moved away'], $uid);
	$folder = $folderRow($id);
	$check('the folder is back', $folder !== null && $folder['loan'] === null && ($folder['history'][0]['returnedOn'] ?? null) === $today);
	$check('condition and missing sheets are those of the folder now', $folder !== null && $folder['wear'] === 'worn'
		&& $folder['missing'] === 'page 2 of ' . $shelfmark);
	$check('taking it back twice is refused', $throws(fn () => $book->takeBack($first, ['wear' => 'good'], $uid)) !== null);

	// To an account: the display name is kept with the loan
	$second = $book->issue($id, ['holderUid' => $uid], $uid)['id'];
	$loan = $loans->find($second);
	$check('an account is stored with its display name', $loan->getHolderUid() === $uid && $loan->getHolderName() === ($user?->getDisplayName() ?? ''));
	$check('an unknown account is refused', $throws(fn () => $book->issue($id, ['holderUid' => 'no-such-account-' . bin2hex(random_bytes(4))], $uid)) !== null);
	$book->takeBack($second, ['wear' => 'worn'], $uid);

	// ---- the hint for a version that no longer applies -------------------------------------
	$copy->setStatus('retired');
	Server::get(CopyMapper::class)->update($copy);
	$folder = $folderRow($id);
	$check('a retired copy is a hint in the folder', $folder !== null && $folder['outdated'] === true
		&& $folder['contents'][0]['outdated'] === 'retired', json_encode($folder['contents'] ?? null) ?: '');
	$check('only a hint: the contents stay', $folder !== null && count($folder['contents']) === 1);

	// ---- the retention period with a staged day ----------------------------------------------
	$months = $book->retentionMonths();
	$day = fn (int $monthsLater, int $daysLater = 0): string => (new DateTimeImmutable($today))->modify('first day of this month')
		->modify('+' . $monthsLater . ' months')->modify('+' . ((int)substr($today, 8, 2) - 1 + $daysLater) . ' days')->format('Y-m-d');
	$book->anonymizeExpired($day($months, -1));
	$check('nothing is anonymised before the period ends', $loans->find($first)->getHolderName() === 'Folder Test Person'
		&& $loans->find($second)->getHolderUid() === $uid);
	$named = array_filter($loanAudit($first), fn (array $e): bool => in_array('Folder Test Person', [$e[2], $e[3]], true));
	$check('the audit log names the person until then', $named !== []);

	$book->anonymizeExpired($day($months));
	foreach ([$first, $second] as $loanId) {
		$loan = $loans->find($loanId);
		$check('loan ' . $loanId . ': name, account and notes are removed', $loan->getHolderName() === null && $loan->getHolderUid() === null
			&& $loan->getIssueNote() === null && $loan->getReturnNote() === null && $loan->getAnonymized() !== null);
		$check('loan ' . $loanId . ': the loan itself stays', $loan->getIssuedOn() === $today && $loan->getReturnedOn() === $today
			&& $loan->getReturnWear() === 'worn');
	}
	$entries = $loanAudit($first);
	$leaks = array_filter($entries, fn (array $e): bool => str_contains(json_encode($e) ?: '', 'Folder Test Person'));
	$check('no entry of the loan names the person', $leaks === [], json_encode(array_values($leaks)) ?: '');
	$check('the anonymised values are marked', in_array(['folder_issued', 'holder_name', null, FolderBookService::ANONYMIZED, $uid, null], $entries, true));
	$anonymized = array_values(array_filter($entries, fn (array $e): bool => $e[0] === 'anonymized'));
	$check('the audit log notes the anonymisation without a name', count($anonymized) === 1 && $anonymized[0][4] === null
		&& !str_contains((string)$anonymized[0][5], 'Person'), json_encode($anonymized) ?: '');
	$account = array_filter($loanAudit($second), fn (array $e): bool => $e[1] === 'holder_uid');
	$check('the account is gone from the audit log, too', $account !== [] && array_filter($account, fn (array $e): bool => $e[3] === $uid) === []);
	$folderEntries = $auditEntries->findForObjects([[FolderBookService::OBJECT_FOLDER, $id]], 100);
	$check('entries of the folder itself are kept', array_filter($folderEntries, fn ($e): bool => $e->getNewValue() === $label) !== []);
	$book->anonymizeExpired($day($months + 1));
	$check('anonymising again changes nothing', count(array_filter($loanAudit($first), fn (array $e): bool => $e[0] === 'anonymized')) === 1);

	// ---- a folder that never comes back, access and erasure (milestone 0.11) -------------------
	$open = $book->issue($id, ['holderName' => 'Privacy Test Person'], $uid)['id'];
	$book->anonymizeExpired($day(FolderBookService::OPEN_LOAN_MONTHS, -1));
	$check('an open loan keeps its holder until the term ends', $loans->find($open)->getHolderName() === 'Privacy Test Person');
	$privacy = Server::get(PrivacyService::class);
	$report = $privacy->report('privacy test person');
	$check('the report finds the loan by name', array_column($report['loans'], 'id') === [$open], json_encode($report['loans']) ?: '');
	$erased = $privacy->erase('Privacy Test Person');
	$check('erasure anonymises the loan', $erased['loans'] === 1 && $loans->find($open)->getHolderName() === null
		&& $loans->find($open)->getOpenFolderId() === $id);
	$check('nothing of the loan names the person afterwards', array_filter($loanAudit($open),
		fn (array $e): bool => str_contains(json_encode($e) ?: '', 'Privacy Test Person')) === []);
	$check('the report is empty afterwards', $privacy->report('Privacy Test Person')['loans'] === []);
	$book->takeBack($open, ['wear' => 'good'], $uid);

	// ---- accounts and names in the values of the audit log (R B27) ----------------------
	$audit = Server::get(AuditLog::class);
	$valueObject = 2000000000;
	$confirm = function () use ($audit, $uid, $valueObject): void {
		$operation = $audit->newOperation();
		$audit->record($operation, $uid, Fields::VERSION, $valueObject, 'confirmed', 'confirmed_by', null, 'privacy-value-account');
		$audit->record($operation, $uid, Fields::VERSION, $valueObject, 'confirmed', 'confirmed_by_name', null, 'Privacy Value Person');
	};
	$values = function () use ($auditEntries, $valueObject): array {
		$result = [];
		foreach ($auditEntries->findForObjects([[Fields::VERSION, $valueObject]], 100) as $entry) {
			$result[] = [$entry->getField(), $entry->getNewValue()];
		}
		return $result;
	};
	$confirm();
	$found = array_column($privacy->report('Privacy-Value-Account')['auditEntries'], 'field');
	$check('the report finds an account in the values, whatever the case', $found === ['confirmed_by'], json_encode($found) ?: '');
	$check('the report finds a name in the values', array_column($privacy->report('privacy value person')['auditEntries'], 'field') === ['confirmed_by_name']);
	$privacy->forgetAccount('privacy-value-account', 'Privacy Value Person');
	$check('a deleted account leaves the values, its name stays', $values() === [['confirmed_by_name', 'Privacy Value Person'],
		['confirmed_by', PrivacyService::ERASED]], json_encode($values()) ?: '');
	$privacy->erase('Privacy Value Person');
	$check('erasure removes the name from the values', $values() === [['confirmed_by_name', PrivacyService::ERASED],
		['confirmed_by', PrivacyService::ERASED]], json_encode($values()) ?: '');
	$confirm();
	$fields = [...PrivacyService::AUDIT_ACCOUNT_VALUES, ...PrivacyService::AUDIT_NAME_VALUES];
	$changed = $auditEntries->pseudonymizeBefore(time() + 60, $fields, PrivacyService::ERASED);
	$leaks = array_filter($values(), fn (array $row): bool => str_contains((string)$row[1], 'rivacy'));
	$check('after the retention period no value names the account', $changed > 0 && $leaks === [], json_encode($values()) ?: '');
	$check('the retention period again changes nothing', $auditEntries->pseudonymizeBefore(time() + 60, $fields, PrivacyService::ERASED) === 0);

	// ---- out of service ----------------------------------------------------------------
	$book->updateFolder($id, ['status' => 'retired', 'statusReason' => 'pages torn'], $uid);
	$check('a retired folder stays listed', ($folderRow($id)['status'] ?? null) === 'retired');
	$check('a retired folder is not issued', $throws(fn () => $book->issue($id, ['holderName' => 'X'], $uid)) !== null);

	// ---- roles -----------------------------------------------------------------------
	$onlyRoles([Roles::CONTRIBUTOR]);
	$denied = function (callable $action): bool {
		try {
			$action();
		} catch (AccessDeniedException) {
			return true;
		}
		return false;
	};
	$check('contributors do not see the folder book', $denied(fn () => $book->overview($uid)));
	$visible = array_filter($auditEntries->findLatest(1000, FolderBookService::OBJECT_TYPES),
		fn ($e): bool => in_array($e->getObjectType(), FolderBookService::OBJECT_TYPES, true));
	$check('the audit log for contributors leaves the folder book out', $visible === []);
	foreach ([Roles::READER, Roles::CONDUCTOR] as $role) {
		$onlyRoles([$role]);
		$check($role . ' reads the folder book', $folderRow($id) !== null);
		$check($role . ' does not issue', $denied(fn () => $book->createFolder(['label' => 'x'], $uid)));
	}
	$onlyRoles([Roles::OPERATIONS]);
	$check('operations maintains the folder book', $book->createFolder(['label' => $label . ' B'], $uid)['id'] > 0);
	$onlyRoles([Roles::LIBRARIAN]);

	// ---- switched off ----------------------------------------------------------------------
	$l = Server::get(IFactory::class)->get(Application::APP_ID, 'en');
	$controller = new FolderBookController(Server::get(IRequest::class), $uid, $roles, $book, $l, Server::get(LoggerInterface::class));
	$middleware = new ModuleMiddleware($modules, $l);
	$modules->change([Modules::FOLDER_BOOK => false]);
	try {
		$middleware->beforeController($controller, 'overview');
		$check('the routes answer 404 while the module is off', false);
	} catch (ModuleDisabledException $e) {
		$check('the routes answer 404 while the module is off', $middleware->afterException($controller, 'overview', $e)->getStatus() === 404);
	}
	$modules->change([Modules::FOLDER_BOOK => true]);
	$check('the data stay', $folderRow($id) !== null);
} catch (Throwable $e) {
	IntegrationTest::unexpected($e);
} finally {
	$db->rollBack();
	$appConfig->clearCache(true);
	foreach ($originalSwitches as $key => $value) {
		$configKey = Modules::CONFIG_PREFIX . $key;
		if ($value === null) {
			$appConfig->deleteKey(Application::APP_ID, $configKey);
		} elseif (!$appConfig->hasKey(Application::APP_ID, $configKey, null) || $appConfig->getValueBool(Application::APP_ID, $configKey) !== $value) {
			$appConfig->setValueBool(Application::APP_ID, $configKey, $value);
		}
	}
	foreach ($originalGroups as $role => $groups) {
		if ($roles->groups($role) !== $groups) {
			$roles->setGroups($role, $groups);
		}
	}
	$appConfig->clearCache(true);
	$check('module configuration restored exactly', $switches() === $originalSwitches);
	$check('role groups restored exactly', $roleGroups() === $originalGroups);
}

IntegrationTest::finish();
