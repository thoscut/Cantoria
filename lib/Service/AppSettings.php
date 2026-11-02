<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Exception\InvalidInputException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * App settings: the archive folder with the score files, its sub folder for
 * exports of the catalog, and the backup folder.
 *
 * The backup folder holds what names people or accounts: the backups (ODS
 * with the audit log, complete JSON backup) and the annual snapshots. It lies
 * outside the archive folder, in the files of the account that set the
 * archive folder, so that the role groups who read the archive folder do not
 * see it. The app reads and writes it only as that account.
 */
class AppSettings {
	public const EXPORT_FOLDER = '_cantoria-app';
	/** App config: Nextcloud file id of the backup folder (0: not created yet) */
	public const BACKUP_FOLDER_KEY = 'backup_folder_id';
	/** Backups in the export folder of versions before 0.10, moved to the backup folder */

	public function __construct(
		private IAppConfig $appConfig,
		private IRootFolder $rootFolder,
		private IFactory $l10nFactory,
		private IUserManager $userManager,
	) {
	}

	/**
	 * @return array{id: int, path: string, user: string}
	 */
	public function archiveInfo(): array {
		return [
			'id' => $this->appConfig->getValueInt(Application::APP_ID, 'archive_folder_id', 0),
			'path' => $this->appConfig->getValueString(Application::APP_ID, 'archive_folder_path', ''),
			'user' => $this->appConfig->getValueString(Application::APP_ID, 'archive_user', ''),
		];
	}

	/**
	 * Sets the archive folder as seen by the user who configures it.
	 */
	public function setArchiveFolder(string $uid, int $folderId): string {
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$folder = $userFolder->getFirstNodeById($folderId);
		if (!$folder instanceof Folder) {
			throw new InvalidInputException('The selected archive folder is not a folder or not accessible.');
		}
		$path = $userFolder->getRelativePath($folder->getPath()) ?? $folder->getPath();
		$this->appConfig->setValueInt(Application::APP_ID, 'archive_folder_id', $folderId);
		$this->appConfig->setValueString(Application::APP_ID, 'archive_folder_path', $path);
		$this->appConfig->setValueString(Application::APP_ID, 'archive_user', $uid);
		return $path;
	}

	/**
	 * The archive folder as seen by a user; the user must have access to it.
	 */
	public function archiveFolder(string $uid): Folder {
		$info = $this->archiveInfo();
		if ($info['id'] === 0) {
			throw new InvalidInputException('No archive folder has been set yet.');
		}
		$folder = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($info['id']);
		if (!$folder instanceof Folder) {
			throw new InvalidInputException('The archive folder is not accessible for this account.');
		}
		return $folder;
	}

	/**
	 * Sub folder for exports and snapshots; created when needed.
	 */
	public function exportFolder(string $uid): Folder {
		$archive = $this->archiveFolder($uid);
		if ($archive->nodeExists(self::EXPORT_FOLDER)) {
			$folder = $archive->get(self::EXPORT_FOLDER);
			if ($folder instanceof Folder) {
				return $folder;
			}
			throw new InvalidInputException('The archive folder contains a file named %s.', [self::EXPORT_FOLDER]);
		}
		try {
			return $archive->newFolder(self::EXPORT_FOLDER);
		} catch (NotPermittedException) {
			throw new InvalidInputException('No write permission for exports in the archive folder.');
		}
	}

	/**
	 * The backup folder as the account that set the archive folder sees it;
	 * created in its files when needed. It never lies inside the archive
	 * folder. The first time, backups and annual snapshots that versions
	 * before 0.10 wrote into the export folder are moved there.
	 *
	 * @throws InvalidInputException with what to do when there is none
	 */
	public function backupFolder(): Folder {
		$info = $this->archiveInfo();
		if ($info['id'] === 0 || $info['user'] === '') {
			throw new InvalidInputException('No archive folder has been set yet.');
		}
		try {
			$home = $this->rootFolder->getUserFolder($info['user']);
		} catch (\Throwable) {
			throw new InvalidInputException('The account that set the archive folder no longer exists. An administrator sets the archive folder again in the settings.');
		}
		$id = $this->appConfig->getValueInt(Application::APP_ID, self::BACKUP_FOLDER_KEY, 0);
		$folder = $id > 0 ? $home->getFirstNodeById($id) : null;
		if ($folder instanceof Folder && !$this->insideArchive($home, $folder)) {
			return $folder;
		}
		$archive = $home->getFirstNodeById($info['id']);
		if ($archive instanceof Folder && $archive->getId() === $home->getId()) {
			throw new InvalidInputException('The archive folder is the whole of the files of the account %s, so the backups would lie inside it. An administrator chooses a sub folder as archive folder.', [$info['user']]);
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $this->l10nFactory->getUserLanguage($this->userManager->get($info['user'])));
		// Named in the language of the account when it is created
		$name = $l->t('Music archive backups');
		try {
			$existing = $home->nodeExists($name) ? $home->get($name) : null;
			$folder = $existing instanceof Folder ? $existing : $home->newFolder($existing === null ? $name : $name . ' (2)');
		} catch (NotPermittedException) {
			throw new InvalidInputException('The account %s may not create the backup folder in its files.', [$info['user']]);
		}
		$this->appConfig->setValueInt(Application::APP_ID, self::BACKUP_FOLDER_KEY, $folder->getId());
		return $folder;
	}

	/**
	 * The path of the backup folder as the account that set the archive
	 * folder sees it, or null when it has not been created yet.
	 */
	public function backupFolderPath(): ?string {
		$id = $this->appConfig->getValueInt(Application::APP_ID, self::BACKUP_FOLDER_KEY, 0);
		$user = $this->archiveInfo()['user'];
		if ($id === 0 || $user === '') {
			return null;
		}
		try {
			$home = $this->rootFolder->getUserFolder($user);
			$folder = $home->getFirstNodeById($id);
			return $folder === null ? null : $home->getRelativePath($folder->getPath());
		} catch (\Throwable) {
			return null;
		}
	}

	private function insideArchive(Folder $home, Folder $folder): bool {
		$archiveId = $this->archiveInfo()['id'];
		foreach ($home->getById($archiveId) as $archive) {
			if (str_starts_with($folder->getPath() . '/', rtrim($archive->getPath(), '/') . '/')) {
				return true;
			}
		}
		return false;
	}
}
