<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Integration test of the review of transcriptions and of small corrections
 * in the app (milestone 0.6) against a real Nextcloud instance with an
 * imported catalog and a file sync.
 *
 * Usage (as the web server user):
 *   php tests/Integration/review_test.php <nextcloud-root> <uid> <shelfmark>
 *
 * <shelfmark> is a copy with a MusicXML score (.musicxml or .mxl) that <uid>
 * may read and whose folder <uid> may write to; preferably one without a
 * transcription report of its own. The test writes its reports itself from
 * the shelf mark, the name of the score file and the measures and parts of
 * the score, so it runs on any catalog. <uid> must hold the role
 * "librarian". The test temporarily gives the user's groups the role
 * "conductor" (and for one check takes the role "librarian" away) and
 * switches on the modules it needs. Database changes are rolled back, the
 * corrected file is deleted, roles and module switches are restored.
 */

require __DIR__ . '/harness.php';
[$root, $uid, $shelfmark] = IntegrationTest::start($argv, ['nextcloud-root', 'uid', 'shelfmark']);

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\AuditEntryMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\TaskMapper;
use OCA\Cantoria\Db\TranscriptionReportMapper;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Service\CatalogService;
use OCA\Cantoria\Service\CorrectionService;
use OCA\Cantoria\Service\FileSyncService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\ReviewService;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\ScoreService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;

$check = IntegrationTest::check(...);
$throws = IntegrationTest::throws(...);

$db = Server::get(IDBConnection::class);
$appConfig = Server::get(IAppConfig::class);
$modules = Server::get(Modules::class);
$roles = Server::get(Roles::class);
$catalog = Server::get(CatalogService::class);
$scores = Server::get(ScoreService::class);
$review = Server::get(ReviewService::class);
$corrections = Server::get(CorrectionService::class);
$fileMapper = Server::get(ArchiveFileMapper::class);
$versions = Server::get(WorkVersionMapper::class);
$userFolder = Server::get(IRootFolder::class)->getUserFolder($uid);

$copy = Server::get(CopyMapper::class)->findAllByShelfmark()[$shelfmark] ?? null;
if ($copy === null) {
	$check('the copy exists', false, $shelfmark);
	IntegrationTest::finish();
}
$versionId = $copy->getVersionId();

/** Entity or array as array (entities serialize with column names) */
$row = fn (object|array $value): array => is_array($value) ? $value : $value->jsonSerialize();

/** The MusicXML text of a score file (.mxl: the root file named in the container) */
$scoreText = function (string $bytes): string {
	if (!str_starts_with($bytes, "PK\x03\x04")) {
		return $bytes;
	}
	$path = tempnam(sys_get_temp_dir(), 'mxl');
	file_put_contents($path, $bytes);
	$zip = new ZipArchive();
	$zip->open($path);
	$name = '';
	$container = $zip->getFromName('META-INF/container.xml');
	if (is_string($container) && preg_match('/full-path\s*=\s*"([^"]+)"/', $container, $m) === 1) {
		$name = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
	}
	if ($name === '' || $zip->locateName($name) === false) {
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$candidate = (string)$zip->getNameIndex($i);
			if (!str_starts_with($candidate, 'META-INF/') && preg_match('/\.(musicxml|xml)$/i', $candidate) === 1) {
				$name = $candidate;
				break;
			}
		}
	}
	$text = (string)$zip->getFromName($name);
	$zip->close();
	unlink($path);
	return $text;
};

$loadXml = function (string $xml): DOMDocument {
	$document = new DOMDocument();
	$document->preserveWhiteSpace = true;
	$document->loadXML($xml, LIBXML_NONET);
	return $document;
};

/** A minimal valid bericht/1 with the given fields */
$report = fn (array $fields): string => json_encode($fields + [
	'format' => 'bericht/1',
	'weg' => 'neuaufbau',
	'vorlage' => ['typ' => 'druck', 'beschreibung' => 'Integration test', 'seiten' => 1, 'schreiber_oder_verlag' => 'Integration test'],
	'umfang' => ['takte' => 1, 'auftakt' => false, 'stimmen' => 1, 'systeme' => 1, 'strophen' => 1],
	'gegenprobe' => ['audiveris' => 'keine', 'ersatz' => []],
	'geprueft' => ['taktlaengen' => 'voll'],
	'vortragszeichen' => ['dynamik' => 0, 'gabeln' => 0, 'boegen' => 0, 'haltebogen' => 0, 'fermaten' => 0, 'akzente' => 0, 'staccato' => 0, 'ausgelassen' => []],
	'unsicher' => [],
	'material' => [],
	'rueckfragen' => [],
	'rechte' => ['einstufung' => 'offen', 'beteiligte' => []],
	'wv' => ['herkunft' => 'Integration test', 'pruefstand' => 'Integration test', 'begruendung' => 'Integration test', 'felder' => new stdClass()],
	'lernen' => [],
	'scores' => '| test |',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

$pointsOf = function (int $reportId) use ($review, $versionId, $uid, $row): array {
	return array_values(array_filter(array_map($row, $review->forVersion($versionId, $uid)['points']),
		fn (array $p): bool => $p['report_id'] === $reportId));
};
$point = function (int $id) use ($review, $versionId, $uid, $row): array {
	foreach ($review->forVersion($versionId, $uid)['points'] as $p) {
		if ($row($p)['id'] === $id) {
			return $row($p);
		}
	}
	return [];
};

// What the test changes outside the transaction, restored in "finally"
$needed = ['score_viewer', 'transcription_review', 'corrections', 'tasks'];
$storedSwitches = [];
foreach ($needed as $key) {
	$configKey = Modules::CONFIG_PREFIX . $key;
	$storedSwitches[$key] = $appConfig->hasKey(Application::APP_ID, $configKey, null) ? $appConfig->getValueBool(Application::APP_ID, $configKey) : null;
}
$conductorGroups = $roles->groups(Roles::CONDUCTOR);
$librarianGroups = $roles->groups(Roles::LIBRARIAN);
$userGroups = Server::get(IGroupManager::class)->getUserGroupIds(Server::get(IUserManager::class)->get($uid));
/** @var list<array{folder: Folder, name: string}> $written */
$written = [];

$db->beginTransaction();
try {
	$modules->change(array_fill_keys($needed, true));
	$check('modules for review and corrections are active', $modules->isEnabled('transcription_review') && $modules->isEnabled('corrections'));

	// ---- the score of the copy -------------------------------------------------
	$score = $catalog->entry($copy->getId(), $uid)['score'];
	$check('the copy has a MusicXML score', $score !== null && $score['canView'], $shelfmark);
	if ($score === null || !$score['canView']) {
		throw new RuntimeException('The copy ' . $shelfmark . ' has no MusicXML score this account may view.');
	}
	$fileId = (int)$score['fileId'];
	$scoreFile = $fileMapper->find($fileId);
	$extension = (string)$scoreFile->getType();
	$basename = (string)preg_replace('/\.[^.]+$/', '', $scoreFile->getName());
	$oldNode = $userFolder->getFirstNodeById($scoreFile->getFileId());
	$check('the score file is readable', $oldNode instanceof File);
	assert($oldNode instanceof File);
	$folder = $oldNode->getParent();
	$oldBytes = $oldNode->getContent();
	$oldHash = hash('sha256', $oldBytes);
	$xml = $scoreText($scores->content($fileId, $uid)['content']);
	$document = $loadXml($xml);
	$xpath = new DOMXPath($document);

	// Printed measure numbers of the first part, part names, and a measure
	// with a pitched note in the first part (the place of the correction)
	$partNames = [];
	foreach ($xpath->query('/score-partwise/part-list/score-part') ?: [] as $scorePart) {
		$partNames[] = trim((string)$xpath->evaluate('string(part-name)', $scorePart));
	}
	$firstPart = $xpath->query('/score-partwise/part')?->item(0);
	$numbers = [];
	$target = null;
	$measures = $firstPart === null ? false : $xpath->query('measure', $firstPart);
	foreach ($measures ?: [] as $measure) {
		assert($measure instanceof DOMElement);
		if ($measure->getAttribute('implicit') === 'yes') {
			continue;
		}
		$numbers[] = $measure->getAttribute('number');
		if ($target === null && count($numbers) >= 2) {
			$note = $xpath->query('note[pitch and not(grace)]', $measure)?->item(0);
			if ($note instanceof DOMElement) {
				$target = ['measure' => $measure->getAttribute('number'), 'note' => $note];
			}
		}
	}
	$check('the score has four measures and a pitched note to correct', count($numbers) >= 4 && $target !== null && $partNames !== [],
		count($numbers) . ' measures, parts ' . implode(' | ', $partNames));
	if (count($numbers) < 4 || $target === null || $partNames === []) {
		throw new RuntimeException('The score of ' . $shelfmark . ' is too short for this test.');
	}
	$voiceA = $partNames[0] ?? 'alle';
	$voiceB = $partNames[count($partNames) - 1] ?? 'alle';
	$targetMeasure = $target['measure'] ?? $numbers[1];

	// ---- reading a report --------------------------------------------------------
	$version = $versions->find($versionId);
	$rightsClass = $version->getRightsStatus() === 'open' ? 'gemeinfrei' : 'offen';
	$publisher = 'Integration test edition ' . bin2hex(random_bytes(4));
	$raw = $report([
		'signatur' => $copy->getShelfmark(),
		'basisname' => $basename,
		'titel' => 'Integration test',
		'unsicher' => [
			['takt' => $targetMeasure, 'stimme' => $voiceA, 'was' => 'Integration test: first note uncertain'],
			['takt' => $numbers[2] . '–' . $numbers[3], 'stimme' => $voiceB, 'was' => 'Integration test: lyrics unclear'],
		],
		'vortragszeichen' => ['dynamik' => 0, 'gabeln' => 0, 'boegen' => 0, 'haltebogen' => 0, 'fermaten' => 0, 'akzente' => 0, 'staccato' => 0,
			'ausgelassen' => ['T. ' . $numbers[1] . ' Integration test: breath mark']],
		'material' => ['Integration test: page 2 of the scan is missing'],
		'rueckfragen' => ['T. ' . $numbers[2] . ': Integration test: which verse comes first?', 'Integration test: is this the right edition?'],
		'rechte' => ['einstufung' => $rightsClass, 'beteiligte' => [['rolle' => 'Satz', 'name' => 'Integration Test', 'lebensdaten' => '–', 'beleg' => 'test']]],
		'wv' => ['herkunft' => 'Integration test origin', 'pruefstand' => 'Integration test review', 'begruendung' => 'Integration test reasoning',
			'felder' => ['Verlag / Bestellnummer' => $publisher]],
	]);
	$check('a report for another shelf mark is refused', $throws(fn () => $review->ingest($versionId,
		str_replace('"signatur":"' . $copy->getShelfmark() . '"', '"signatur":"999-999"', $raw), $uid)) !== null);
	$check('a report with a path in the base name is refused', $throws(fn () => $review->ingest($versionId,
		$report(['signatur' => $copy->getShelfmark(), 'basisname' => '../' . $basename]), $uid)) !== null);

	// Reports read before (e.g. by the file sync) may leave points open with a warning
	$earlierReports = Server::get(TranscriptionReportMapper::class)->findByCopy($copy->getId());
	$result = $review->ingest($versionId, $raw, $uid);
	$reportId = $result['report']->getId();
	$reportRow = $row($result['report']);
	$check('report read', !$result['unchanged'] && $result['points']['created'] === 6, json_encode($result['points']));
	$check('report refers to the score file', ($reportRow['musicxml_id'] ?? null) === $fileId && ($reportRow['musicxml_sha256'] ?? null) === $oldHash
		&& ($reportRow['sha256'] ?? null) === hash('sha256', $raw) && ($reportRow['basename'] ?? null) === $basename);
	if ($earlierReports === []) {
		// at most the note that the proposals inbox is switched off
		$check('no warnings', count($result['warnings']) <= ($modules->isEnabled('proposals') ? 0 : 1),
			json_encode($result['warnings'], JSON_UNESCAPED_UNICODE));
	}
	if ($modules->isEnabled('proposals')) {
		$check('rights and the catalog field went to the inbox', $result['proposals'] === 2 && !$result['proposalsSkipped'], (string)$result['proposals']);
	} else {
		$check('without the inbox no proposals', $result['proposals'] === 0 && $result['proposalsSkipped']);
	}
	$check('the catalog itself is unchanged', $versions->find($versionId)->getRightsStatus() === $version->getRightsStatus()
		&& $versions->find($versionId)->getValue('publisher') === $version->getValue('publisher'));

	$points = $pointsOf($reportId);
	$byText = [];
	foreach ($points as $p) {
		$byText[$p['text']] = $p;
	}
	$u1 = $byText['Integration test: first note uncertain'] ?? [];
	$u2 = $byText['Integration test: lyrics unclear'] ?? [];
	$om = $byText['Integration test: breath mark'] ?? array_values(array_filter($points, fn (array $p): bool => $p['category'] === 'omitted'))[0] ?? [];
	$mat = $byText['Integration test: page 2 of the scan is missing'] ?? [];
	$q1 = array_values(array_filter($points, fn (array $p): bool => $p['category'] === 'question' && $p['measure_from'] !== null))[0] ?? [];
	$q2 = $byText['Integration test: is this the right edition?'] ?? [];
	$check('six review points with their categories', count($points) === 6
		&& ($u1['category'] ?? '') === 'uncertain' && ($u2['category'] ?? '') === 'uncertain' && ($om['category'] ?? '') === 'omitted'
		&& ($mat['category'] ?? '') === 'material' && ($q1['category'] ?? '') === 'question' && ($q2['category'] ?? '') === 'question',
		json_encode(array_column($points, 'category')));
	$check('musical points go to the conductor, material and questions without measures to the librarian',
		($u1['role'] ?? '') === 'conductor' && ($u2['role'] ?? '') === 'conductor' && ($om['role'] ?? '') === 'conductor'
		&& ($q1['role'] ?? '') === 'conductor' && ($mat['role'] ?? '') === 'librarian' && ($q2['role'] ?? '') === 'librarian');
	$check('measures and voice of the points', ($u1['measure_from'] ?? null) === $targetMeasure && ($u1['voice'] ?? null) === $voiceA
		&& ($u2['measure_from'] ?? null) === $numbers[2] && ($u2['measure_to'] ?? null) === $numbers[3] && ($om['measure_from'] ?? null) === $numbers[1]
		&& ($q1['measure_from'] ?? null) === $numbers[2], json_encode([$u1['measures'] ?? null, $u2['measures'] ?? null, $om['measures'] ?? null]));
	$check('points refer to the file and its checksum', ($u1['file_id'] ?? null) === $fileId && ($u1['file_sha256'] ?? null) === $oldHash
		&& ($u1['status'] ?? '') === 'open');
	$review1 = $review->forVersion($versionId, $uid);
	$check('the report shows the review state of the version', in_array('Integration test review', array_column(array_map($row, $review1['reports']), 'review'), true)
		&& ($review1['may']['decideMusical'] ?? false) && ($review1['may']['decideMaterial'] ?? false));
	$entryRow = array_values(array_filter($catalog->entries(), fn (array $e): bool => $e['id'] === $copy->getId()))[0] ?? [];
	$check('the catalog row counts the open points', ($entryRow['review_open'] ?? 0) >= 6, json_encode(['review_open' => $entryRow['review_open'] ?? null]));

	$again = $review->ingest($versionId, $raw, $uid);
	$check('the same report again creates nothing', $again['unchanged'] && $again['report']->getId() === $reportId && count($pointsOf($reportId)) === 6);

	// ---- decisions ---------------------------------------------------------------
	$decided = $review->decide($u1['id'], 'confirmed', null, 'as printed', null, $uid);
	$check('reading confirmed', $row($decided)['status'] === 'confirmed' && $row($decided)['decided_by'] === $uid
		&& $row($decided)['decision_reason'] === 'as printed');
	$check('a correction order needs a correction text', $throws(fn () => $review->decide($u2['id'], 'correct', null, null, null, $uid)) !== null);
	$review->decide($u2['id'], 'correct', 'Read the syllables again from the scan', null, null, $uid);
	$orders = array_column($review->orders($uid), null, 'id');
	$check('the order is listed for the tool chain', isset($orders[$u2['id']]) && $orders[$u2['id']]['shelfmark'] === $copy->getShelfmark()
		&& $orders[$u2['id']]['correction'] === 'Read the syllables again from the scan');
	$check('dismissing needs a reason', $throws(fn () => $review->decide($om['id'], 'dismissed', null, null, null, $uid)) !== null);
	$review->decide($om['id'], 'dismissed', null, 'not in this edition', null, $uid);
	$check('the same status again is refused', $throws(fn () => $review->decide($om['id'], 'dismissed', null, 'again', null, $uid)) !== null);
	$check('corrected is not a decision', $throws(fn () => $review->decide($mat['id'], 'corrected', null, 'x', null, $uid)) !== null);
	$check('unknown status is refused', $throws(fn () => $review->decide($mat['id'], 'deleted', null, 'x', null, $uid)) !== null);
	if ($modules->isEnabled('tasks')) {
		$asked = $review->decide($q1['id'], 'question', null, 'please answer', 'conductor', $uid);
		$taskId = $row($asked)['task_id'];
		$task = $taskId === null ? null : Server::get(TaskMapper::class)->find((int)$taskId);
		$check('a question becomes a task with the location', $row($asked)['status'] === 'question' && $task !== null
			&& $task->getCopyId() === $copy->getId() && $task->getAddressee() === 'conductor' && $task->getMeasureFrom() === $numbers[2],
			json_encode(['task' => $taskId]));
	}
	$review->decide($q2['id'], 'correct', 'Compare with the edition named in the catalog', null, null, $uid);

	// Conductor: decides musical points, not material points
	$roles->setGroups(Roles::CONDUCTOR, array_values(array_unique(array_merge($conductorGroups, $userGroups))));
	$roles->setGroups(Roles::LIBRARIAN, array_values(array_diff($librarianGroups, $userGroups)));
	if (!$roles->hasAnyRole($uid, [Roles::LIBRARIAN])) {
		$review->decide($u1['id'], 'open', null, 'look again', null, $uid);
		$check('the conductor reopens a musical point', $point($u1['id'])['status'] === 'open');
		$check('the conductor may not decide a material point', $throws(fn () => $review->decide($mat['id'], 'confirmed', null, null, null, $uid)) !== null);
		$check('the conductor sees what it may decide', ($review->forVersion($versionId, $uid)['may']['decideMaterial'] ?? true) === false);
	} else {
		$check('the user is librarian through another group: conductor rules skipped', true);
		$review->decide($u1['id'], 'open', null, 'look again', null, $uid);
	}
	$roles->setGroups(Roles::LIBRARIAN, $librarianGroups);

	// ---- corrections in the app --------------------------------------------------
	$note = $target['note'] ?? null;
	assert($note instanceof DOMElement);
	$stepNode = $xpath->query('pitch/step', $note)?->item(0);
	$octave = trim((string)$xpath->evaluate('string(pitch/octave)', $note));
	$alter = trim((string)$xpath->evaluate('string(pitch/alter)', $note));
	$oldStep = trim((string)$stepNode?->textContent);
	$newStep = $oldStep === 'A' ? 'B' : 'A';
	$variant = function (callable $change) use ($loadXml, $xml): string {
		$copyDocument = $loadXml($xml);
		$copyXpath = new DOMXPath($copyDocument);
		$change($copyDocument, $copyXpath);
		return (string)$copyDocument->saveXML();
	};
	$noteIn = function (DOMXPath $x) use ($targetMeasure): DOMElement {
		$found = $x->query('/score-partwise/part[1]/measure[@number="' . $targetMeasure . '"]/note[pitch and not(grace)]')?->item(0);
		assert($found instanceof DOMElement);
		return $found;
	};
	$modified = $variant(function (DOMDocument $d, DOMXPath $x) use ($noteIn, $newStep): void {
		$step = $x->query('pitch/step', $noteIn($x))?->item(0);
		assert($step instanceof DOMElement);
		$step->textContent = $newStep;
	});
	$longer = $variant(function (DOMDocument $d, DOMXPath $x) use ($noteIn): void {
		$duration = $x->query('duration', $noteIn($x))?->item(0);
		assert($duration instanceof DOMElement);
		$duration->textContent = (string)((int)$duration->textContent * 2);
	});
	$addedNote = $variant(function (DOMDocument $d, DOMXPath $x) use ($noteIn): void {
		$found = $noteIn($x);
		$found->parentNode?->insertBefore($found->cloneNode(true), $found->nextSibling);
	});
	$fewerMeasures = $variant(function (DOMDocument $d, DOMXPath $x): void {
		foreach ($x->query('/score-partwise/part') ?: [] as $part) {
			$measures = $x->query('measure', $part);
			$last = $measures?->item($measures->length - 1);
			$last?->parentNode?->removeChild($last);
		}
	});
	// sorted: the order of a directory listing is not fixed (PostgreSQL returns it in any order)
	$listing = function () use ($folder): array {
		$names = array_map(fn ($n) => $n->getName(), $folder->getDirectoryListing());
		sort($names);
		return $names;
	};
	$before = $listing();
	$check('a changed note value is refused', $throws(fn () => $corrections->correct($versionId, $fileId, $longer, [], null, $uid)) !== null);
	$check('an added note is refused', $throws(fn () => $corrections->correct($versionId, $fileId, $addedNote, [], null, $uid)) !== null);
	$check('a changed number of measures is refused', $throws(fn () => $corrections->correct($versionId, $fileId, $fewerMeasures, [], null, $uid)) !== null);
	$check('a correction without a change is refused', $throws(fn () => $corrections->correct($versionId, $fileId, $xml, [], null, $uid)) !== null);
	$check('refused corrections write no file', $listing() === $before, implode(', ', array_diff($listing(), $before)));

	$corrected = $corrections->correct($versionId, $fileId, $modified, [$u1['id'], $u2['id']], 'integration test', $uid);
	$newFile = $corrected['file'];
	$newName = $newFile->getName();
	$written[] = ['folder' => $folder, 'name' => $newName];
	$today = Server::get(RightsService::class)->today($uid);
	$check('the new file is named after the old one and the day', preg_match('/^' . preg_quote($basename, '/') . ' \((?:corrected|korrigiert) '
		. preg_quote($today, '/') . '(?:, \d+)?\)\.' . preg_quote($extension, '/') . '$/u', $newName) === 1, $newName);
	$check('the new file lies next to the old one', $folder->nodeExists($newName));
	$check('the old file is unchanged', hash('sha256', $oldNode->getContent()) === $oldHash);
	$change = $corrected['changes'][0] ?? [];
	$sign = match ($alter) {
		'1' => '#', '2' => '##', '-1' => 'b', '-2' => 'bb', default => '',
	};
	$check('the change list names measure, kind and pitches', count($corrected['changes']) === 1 && ($change['kind'] ?? '') === 'pitch'
		&& ($change['measure'] ?? '') === $targetMeasure && str_starts_with((string)($change['old'] ?? ''), $oldStep)
		&& ($change['new'] ?? '') === $newStep . $sign . $octave,
		json_encode($corrected['changes'], JSON_UNESCAPED_UNICODE));
	$check('the new file is the MusicXML of the copy', $newFile->getCopyId() === $copy->getId() && $newFile->getRole() === 'musicxml'
		&& $newFile->getMusicxmlValid() === true && $newFile->getType() === $extension);
	$check('the version names the new file as valid MusicXML', $versions->find($versionId)->getMusicxmlValid() === $newName);
	$check('the old file stays linked, no longer valid', $fileMapper->find($fileId)->getCopyId() === $copy->getId()
		&& $fileMapper->find($fileId)->getMusicxmlValid() !== true);
	$check('the score view shows the new file', ($catalog->entry($copy->getId(), $uid)['score']['fileId'] ?? null) === $newFile->getId());
	$newXml = $scoreText($scores->content($newFile->getId(), $uid)['content']);
	$newXpath = new DOMXPath($loadXml($newXml));
	$check('the new file has the corrected pitch and nothing else changed',
		trim((string)$newXpath->evaluate('string(pitch/step)', $noteIn($newXpath))) === $newStep
		&& $newXpath->evaluate('count(//note)') === $xpath->evaluate('count(//note)')
		&& $newXpath->evaluate('count(//measure)') === $xpath->evaluate('count(//measure)'));
	$audit = Server::get(AuditEntryMapper::class)->findForObjects([['file', $newFile->getId()]]);
	$check('the change is in the audit log', array_filter($audit, fn ($e) => $e->getAction() === 'corrected') !== [], (string)count($audit));
	$check('the chosen points are corrected', $point($u1['id'])['status'] === 'corrected' && $point($u2['id'])['status'] === 'corrected');
	$check('a corrected order leaves the list', !isset(array_column($review->orders($uid), null, 'id')[$u2['id']]));

	// The file sync keeps the new file as the valid MusicXML of the copy
	$sync = Server::get(FileSyncService::class)->sync($uid);
	$synced = $fileMapper->find($newFile->getId());
	$check('the file sync keeps the corrected file', $synced->getCopyId() === $copy->getId() && $synced->getMusicxmlValid() === true
		&& $synced->getFound() !== false, json_encode(['new' => $sync['new'], 'missing' => $sync['missing']]));

	// ---- a new round ---------------------------------------------------------------
	// The tool chain delivers a new report for the corrected file: it names
	// the question again, names a new uncertainty, no longer names the
	// material point and reports the order q2 as done.
	$newBasename = (string)preg_replace('/\.[^.]+$/', '', $newName);
	$raw2 = $report([
		'signatur' => $copy->getShelfmark(),
		'basisname' => $newBasename,
		'titel' => 'Integration test, round 2',
		'unsicher' => [['takt' => $numbers[3], 'stimme' => $voiceA, 'was' => 'Integration test, round 2: tie or slur?']],
		'rueckfragen' => ['T. ' . $numbers[2] . ': Integration test: which verse comes first?'],
		'erledigt' => [$q2['id']],
	]);
	$second = $review->ingest($versionId, $raw2, $uid);
	$secondId = $second['report']->getId();
	$check('the new round creates and keeps points', !$second['unchanged'] && $second['points']['created'] === 1 && $second['points']['kept'] === 1,
		json_encode($second['points']));
	$check('the new round refers to the corrected file', ($row($second['report'])['musicxml_id'] ?? null) === $newFile->getId());
	$check('points no longer named are done in the new version', $second['points']['closed'] >= 2
		&& $point($mat['id'])['status'] === 'corrected' && $point($mat['id'])['resolved_report_id'] === $secondId
		&& $point($q2['id'])['status'] === 'corrected', json_encode($second['points']));
	$check('decided points stay as decided', $point($om['id'])['status'] === 'dismissed');
	$reports = array_column(array_map($row, $review->forVersion($versionId, $uid)['reports']), null, 'id');
	$check('the first report is superseded', ($reports[$reportId]['superseded_by'] ?? null) === $secondId);
	$check('no order is left', array_filter($review->orders($uid), fn (array $o): bool => $o['copy_id'] === $copy->getId()) === []);
} catch (Throwable $e) {
	IntegrationTest::unexpected($e);
} finally {
	$db->rollBack();
	$roles->setGroups(Roles::CONDUCTOR, $conductorGroups);
	$roles->setGroups(Roles::LIBRARIAN, $librarianGroups);
	// Module switches exactly as before, also when the rollback did not cover
	// them (the app config caches its values).
	$appConfig->clearCache(true);
	foreach ($storedSwitches as $key => $value) {
		$configKey = Modules::CONFIG_PREFIX . $key;
		if ($value === null) {
			$appConfig->deleteKey(Application::APP_ID, $configKey);
		} elseif (!$appConfig->hasKey(Application::APP_ID, $configKey, null) || $appConfig->getValueBool(Application::APP_ID, $configKey) !== $value) {
			$appConfig->setValueBool(Application::APP_ID, $configKey, $value);
		}
	}
	$appConfig->clearCache(true);
	// Delete by name: the rollback also removed the new file from the file
	// cache, so it no longer shows up in a directory listing.
	foreach ($written as ['folder' => $where, 'name' => $name]) {
		if ($where->nodeExists($name)) {
			$where->get($name)->delete();
		}
	}
	$check('the corrected file is deleted again', array_filter($written, fn (array $w): bool => $w['folder']->nodeExists($w['name'])) === []);
}

IntegrationTest::finish();
