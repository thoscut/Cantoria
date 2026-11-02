<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Integration test of the modules against a real Nextcloud instance with an
 * imported catalog and the notifications app: a switched-off module is off
 * on the server (routes, daily checks, notifications, start page, dashboard
 * widget), its data stay, switching it on shows everything again, and
 * switching off never relaxes the rights policy.
 *
 * Usage (as the web server user):
 *   php tests/Integration/modules_test.php <nextcloud-root> <uid> <shelfmark-protected>
 *
 * <uid> must hold the role "librarian" and be the configured archive user.
 * <shelfmark-protected> is a copy of a protected version without a license
 * for practice audio. Database changes are rolled back; at the end the
 * module switches are exactly as before (also those that were not stored).
 */

require __DIR__ . '/harness.php';
[$root, $uid, $protectedMark] = IntegrationTest::start($argv, ['nextcloud-root', 'uid', 'shelfmark-protected']);

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\BackgroundJob\DailyChecks;
use OCA\Cantoria\Controller\EventController;
use OCA\Cantoria\Dashboard\DueWidget;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\ModuleDisabledException;
use OCA\Cantoria\Middleware\ModuleMiddleware;
use OCA\Cantoria\Notification\Notifier;
use OCA\Cantoria\Service\CatalogService;
use OCA\Cantoria\Service\DueService;
use OCA\Cantoria\Service\EventService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\NotificationService;
use OCA\Cantoria\Service\PerformanceImportService;
use OCA\Cantoria\Service\PracticeAudioService;
use OCA\Cantoria\Service\ProgrammeShareService;
use OCA\Cantoria\Service\RepertoireService;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\UserFiles;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

$check = IntegrationTest::check(...);

$db = Server::get(IDBConnection::class);
$appConfig = Server::get(IAppConfig::class);
$modules = Server::get(Modules::class);
$due = Server::get(DueService::class);
$events = Server::get(EventService::class);
$eventMapper = Server::get(EventMapper::class);
$daily = Server::get(DailyChecks::class);

/**
 * The stored module switches: key => value, or null when not stored.
 *
 * @return array<string, bool|null>
 */
$switches = function () use ($appConfig): array {
	$values = [];
	foreach (array_keys(Modules::MODULES) as $key) {
		$configKey = Modules::CONFIG_PREFIX . $key;
		$values[$key] = $appConfig->hasKey(Application::APP_ID, $configKey, null)
			? $appConfig->getValueBool(Application::APP_ID, $configKey)
			: null;
	}
	return $values;
};
$original = $switches();

$runJob = function (object $job): void {
	(new ReflectionMethod($job, 'run'))->invoke($job, null);
};
$notificationsFor = function (string $subject, string $objectId) use ($uid): int {
	$qb = Server::get(IDBConnection::class)->getQueryBuilder();
	$qb->select('notification_id')->from('notifications')
		->where($qb->expr()->eq('app', $qb->createNamedParameter(Application::APP_ID)))
		->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($uid)))
		->andWhere($qb->expr()->eq('subject', $qb->createNamedParameter($subject)))
		->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($objectId)));
	return count($qb->executeQuery()->fetchAllAssociative());
};
$user = Server::get(IUserManager::class)->get($uid);
$check('test account exists', $user !== null);
Server::get(IUserSession::class)->setUser($user);
$widget = Server::get(DueWidget::class);
$widgetLinks = fn (): array => array_map(fn ($item): string => $item->getLink(), $widget->getItemsV2($uid, null, 100)->getItems());
$l = Server::get(IFactory::class)->get(Application::APP_ID, 'en');
$eventController = new EventController(Server::get(IRequest::class), $uid, Server::get(Roles::class), $events,
	Server::get(EventItemMapper::class), Server::get(PerformanceImportService::class), Server::get(ProgrammeShareService::class), Server::get(RepertoireService::class),
	Server::get(UserFiles::class), $l, Server::get(LoggerInterface::class));
$middleware = new ModuleMiddleware($modules, $l);
/** The answer of the middleware for a route: null when the route may run. */
$route = function (string $method) use ($middleware, $eventController): ?JSONResponse {
	try {
		$middleware->beforeController($eventController, $method);
		return null;
	} catch (ModuleDisabledException $e) {
		$response = $middleware->afterException($eventController, $method, $e);
		return $response instanceof JSONResponse ? $response : new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
	}
};

$db->beginTransaction();
try {
	$modules->applyPreset(Modules::COMPLETE);
	// "complete" leaves the modules of Modules::OUTSIDE_PRESETS as they are, so
	// its own switches are checked: a module that needs one of those (e.g. the
	// notification about the print station) is switched on but not active.
	$notOn = array_values(array_filter(array_keys(Modules::presetValues(Modules::COMPLETE)), fn (string $key): bool => !$modules->isSwitchedOn($key)));
	$check('all modules of the preset switched on', $notOn === [], implode(', ', $notOn));

	// ---- concerts ----
	$today = Server::get(RightsService::class)->today($uid);
	$date = (new DateTimeImmutable($today))->modify('+29 days')->format('Y-m-d');
	$concert = $events->create('concert', ['title' => 'Module test concert', 'event_date' => $date], $uid);
	$id = (string)$concert->getId();
	$copy = array_values(Server::get(CopyMapper::class)->findAllByShelfmark())[0];
	$events->addItem($concert->getId(), $copy->getId(), $uid);

	$runJob($daily);
	$check('concert deadline notified', $notificationsFor(NotificationService::CONCERT_DEADLINE, $id) === 1);
	$check('start page lists the concert', in_array($concert->getId(), array_column($due->forUser($uid)['concerts'], 'id'), true));
	$check('widget is shown', $widget->isEnabled());
	$check('widget lists the concert', in_array('?event=' . $id, array_map(fn (string $link): string => strstr($link, '?') ?: '', $widgetLinks()), true));
	$check('route of concerts answers', $route('list') === null);

	$changes = $modules->change(['concerts' => false]);
	$check('switching off concerts names what needs it', in_array('calendar', $changes['inactive'], true), implode(', ', $changes['inactive']));
	$response = $route('list');
	$check('route of concerts answers 404', $response !== null && $response->getStatus() === Http::STATUS_NOT_FOUND);
	$check('404 says the function is switched off', $response !== null && $response->getData() === ['error' => 'This function is switched off.'],
		json_encode($response?->getData()) ?: '');
	$check('start page lists no concerts', $due->forUser($uid)['concerts'] === []);
	$check('widget lists no concert', !in_array(true, array_map(fn (string $link): bool => str_contains($link, '?event='), $widgetLinks()), true));
	$notification = Server::get(IManager::class)->createNotification()->setApp(Application::APP_ID)->setUser($uid)
		->setObject('event', $id)->setSubject(NotificationService::CONCERT_DEADLINE, ['eventId' => (int)$id, 'title' => 'x', 'date' => $date, 'weeks' => 6])
		->setDateTime(new DateTime());
	try {
		Server::get(Notifier::class)->prepare($notification, 'en');
		$check('a shown concert notification is withdrawn', false);
	} catch (AlreadyProcessedException) {
		$check('a shown concert notification is withdrawn', true);
	}
	$runJob($daily);
	$check('daily checks create no concert deadline and withdraw the old one', $notificationsFor(NotificationService::CONCERT_DEADLINE, $id) === 0);
	$check('the deadline state is cleared', $appConfig->getValueString(Application::APP_ID, 'notified_event_' . $id, '', true) === '');
	$check('the concert is kept', $eventMapper->find($concert->getId())->getTitle() === 'Module test concert');

	$changes = $modules->change(['concerts' => true]);
	$check('switching on brings back what needs it', in_array('calendar', $changes['activated'], true), implode(', ', $changes['activated']));
	$check('route of concerts answers again', $route('list') === null);
	$check('start page lists the concert again', in_array($concert->getId(), array_column($due->forUser($uid)['concerts'], 'id'), true));
	$check('widget lists the concert again', in_array(true, array_map(fn (string $link): bool => str_ends_with($link, '?event=' . $id), $widgetLinks()), true));
	$runJob($daily);
	$check('concert deadline notified again', $notificationsFor(NotificationService::CONCERT_DEADLINE, $id) === 1);
	$detail = $events->detail($concert->getId(), $uid);
	$check('the programme is kept', count($detail['items']) === 1);

	// ---- dashboard widget ----
	$modules->change(['dashboard_widget' => false]);
	$check('no widget while it is switched off', !$widget->isEnabled() && $widgetLinks() === []);
	$modules->change(['dashboard_widget' => true]);

	// ---- rights: switching off relaxes nothing ----
	$protected = Server::get(CopyMapper::class)->findAllByShelfmark()[$protectedMark];
	$version = Server::get(WorkVersionMapper::class)->find($protected->getVersionId());
	if ($version->getRightsStatus() !== 'protected') {
		// Precondition, so that the test also runs on another catalog
		Server::get(CatalogService::class)->update('version', $version->getId(), ['rights_status' => 'protected'], $uid, 'integration test');
		$version = Server::get(WorkVersionMapper::class)->find($protected->getVersionId());
	}
	$policy = Server::get(RightsPolicy::class);
	$check('the version is protected', $version->getRightsStatus() === 'protected', (string)$version->getRightsStatus());
	$refusal = $policy->playDenial($version, $uid);
	$check('playback is refused with rights on', $refusal !== null, (string)$refusal);
	$modules->change(['rights' => false]);
	$check('rights are switched off', !$modules->isEnabled('rights') && !$modules->isEnabled('releases'));
	$check('playback is still refused with rights off', $policy->playDenial($version, $uid) === $refusal);
	$entry = Server::get(CatalogService::class)->entry($protected->getId(), $uid);
	$check('no license maintenance in the entry', $entry['licenses'] === []);
	$check('the score may not be played', $entry['score'] === null || $entry['score']['canPlay'] === false);
	$message = null;
	try {
		Server::get(PracticeAudioService::class)->authorize($version->getId(), ['copy_id' => $protected->getId(), 'measure_from' => '1',
			'measure_to' => '4', 'mode' => 'all', 'format' => 'wav'], $uid);
	} catch (AccessDeniedException $e) {
		$message = $e->getMessage();
	}
	$check('practice audio is still refused', $message !== null, (string)$message);
	$modules->change(['rights' => true]);
	$check('rights are on again', $modules->isEnabled('rights') && $modules->isEnabled('portal'));
} catch (Throwable $e) {
	IntegrationTest::unexpected($e);
} finally {
	$db->rollBack();
	Server::get(IUserSession::class)->setUser(null);
	// Exactly the switches of before, also when the rollback did not cover
	// them (the app config caches its values).
	$appConfig->clearCache(true);
	foreach ($original as $key => $value) {
		$configKey = Modules::CONFIG_PREFIX . $key;
		if ($value === null) {
			$appConfig->deleteKey(Application::APP_ID, $configKey);
		} elseif (!$appConfig->hasKey(Application::APP_ID, $configKey, null) || $appConfig->getValueBool(Application::APP_ID, $configKey) !== $value) {
			$appConfig->setValueBool(Application::APP_ID, $configKey, $value);
		}
	}
	$appConfig->clearCache(true);
	$check('module configuration restored exactly', $switches() === $original);
}

IntegrationTest::finish();
