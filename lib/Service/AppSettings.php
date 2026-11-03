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
use OCP\Share\IManager as IShareManager;

/**
 * App settings: the archive folder with the score files, its sub folder for
 * exports of the catalog, and the backup folder.
 *
 * The backup folder holds what names people or accounts: the backups (ODS
 * with the audit log, complete JSON backup) and the annual snapshots. It lies
 * outside the archive folder, in the files of the account that set the
 * archive folder, so that the role groups who read the archive folder do not
 * see it. The app reads and writes it only as that account, and only while
 * the folder belongs to that account and nobody else can open it: no share
 * of it (or of a folder above it) to people, groups, other servers or by
 * link, and no folder another account shared with it (see privateFolder).
 * Its name, "Music archive backups" in the language of the account, is a
 * generic name on purpose, like the "music archive" of the user interface.
 */
class AppSettings {
	public const EXPORT_FOLDER = '_cantoria-app';
	/** App config: Nextcloud file id of the backup folder (0: not created yet) */
	public const BACKUP_FOLDER_KEY = 'backup_folder_id';
	/** Folders tried with a number after the name before giving up */
	private const MAX_NAME_TRIES = 20;

	public function __construct(
		private IAppConfig $appConfig,
		private IRootFolder $rootFolder,
		private IFactory $l10nFactory,
		private IUserManager $userManager,
		private IShareManager $shareManager,
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
	 * folder, and it is used only while it is private to that account
	 * (privateFolder): a stored folder that was shared since stops the
	 * backup with what to do; a folder of the same name that is not the
	 * account's own, or is shared, is never taken over, a new one with a
	 * number after the name is created instead.
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
			if (!$this->privateFolder($folder, $info['user'])) {
				throw new InvalidInputException('The backup folder %1$s is shared or no longer belongs to the account %2$s, so others could read the backups. Remove the shares of the folder, or move it away so that the app creates a new one.',
					[$home->getRelativePath($folder->getPath()) ?? $folder->getName(), $info['user']]);
			}
			return $folder;
		}
		$archive = $home->getFirstNodeById($info['id']);
		if ($archive instanceof Folder && $archive->getId() === $home->getId()) {
			throw new InvalidInputException('The archive folder is the whole of the files of the account %s, so the backups would lie inside it. An administrator chooses a sub folder as archive folder.', [$info['user']]);
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $this->l10nFactory->getUserLanguage($this->userManager->get($info['user'])));
		// Named in the language of the account when it is created
		$base = $l->t('Music archive backups');
		$folder = null;
		try {
			for ($i = 1; $folder === null && $i <= self::MAX_NAME_TRIES; $i++) {
				$name = $i === 1 ? $base : $base . ' (' . $i . ')';
				if (!$home->nodeExists($name)) {
					$folder = $home->newFolder($name);
					break;
				}
				// An existing folder of that name only when it is the account's own and private
				$existing = $home->get($name);
				if ($existing instanceof Folder && !$this->insideArchive($home, $existing) && $this->privateFolder($existing, $info['user'])) {
					$folder = $existing;
				}
			}
		} catch (NotPermittedException) {
			throw new InvalidInputException('The account %s may not create the backup folder in its files.', [$info['user']]);
		}
		if ($folder === null) {
			throw new InvalidInputException('The account %s may not create the backup folder in its files.', [$info['user']]);
		}
		$this->appConfig->setValueInt(Application::APP_ID, self::BACKUP_FOLDER_KEY, $folder->getId());
		return $folder;
	}

	/**
	 * Whether only the account can open a folder: it is the account's own
	 * (not a folder another account shared with it, not on external
	 * storage), and neither it nor a folder above it is shared with others
	 * or by link. Fails closed.
	 */
	public function privateFolder(Folder $folder, string $account): bool {
		try {
			if ($folder->getOwner()?->getUID() !== $account || $folder->isShared() || $folder->isMounted()) {
				return false;
			}
			$access = $this->shareManager->getAccessList($folder);
		} catch (\Throwable) {
			return false;
		}
		$users = array_diff(array_map('strval', $access['users'] ?? []), [$account]);
		return $users === [] && ($access['public'] ?? false) !== true && ($access['remote'] ?? false) === false
			&& ($access['mail'] ?? []) === [];
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
