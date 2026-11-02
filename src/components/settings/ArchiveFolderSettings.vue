<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Settings "Archive folder": the folder in Nextcloud with the score files,
 * chosen with Nextcloud's file picker and saved as seen by the person who
 * chooses it. Before saving, the page says what saving does and checks
 * right away which accounts of the role groups cannot open the chosen
 * folder, so that the problem is fixed before anybody meets it.
 */
import type { AccessReport, ArchiveInfo, Settings } from '../../types.ts'

import { mdiFolderOutline } from '@mdi/js'
import { FilePickerClosed, FilePickerType, getFilePickerBuilder, showSuccess } from '@nextcloud/dialogs'
import { t } from '../../l10n.ts'
import { computed, ref, useTemplateRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import GuideLink from '../GuideLink.vue'
import { errorMessage, fetchArchiveAccess, saveSettings } from '../../api.ts'
import { ADMIN_GUIDE, adminGuideUrl } from '../../docs.ts'
import { focusAfterDialog, focusAfterUpdate } from '../../focus.ts'
import { translateLabel } from '../../format.ts'
import { accessRows } from '../../settings.ts'
import { showError } from '../../toasts.ts'

const props = defineProps<{
	archive: ArchiveInfo
	/** the backup folder outside the archive folder */
	backups: Settings['backups']
	/** only administrators change the archive folder */
	admin: boolean
	/** role key => English label */
	roleLabels: Record<string, string>
}>()

const emit = defineEmits<{
	/** the archive folder was saved; the settings as the server has them now */
	saved: [settings: Settings]
}>()

const pending = ref<{ id: number, path: string } | null>(null)
const saving = ref(false)

/** Access check of the chosen folder, before it is saved */
const check = ref<AccessReport | null>(null)
const checking = ref(false)
const checkError = ref('')

/** Accounts of the role groups that cannot open the chosen folder, with group and role */
const problems = computed(() => (check.value ? accessRows(check.value) : [])
	.flatMap((row) => row.cannotOpen.map((account) => ({
		key: `${row.role}-${row.id}-${account.uid}`,
		account: account.displayName === account.uid ? account.uid : `${account.displayName} (${account.uid})`,
		group: row.displayName,
		role: translateLabel(props.roleLabels[row.role] ?? row.role),
	}))))
const noRoleGroups = computed(() => check.value !== null && accessRows(check.value).length === 0)

/** Where the focus goes after the file picker and after saving (WCAG 2.4.3) */
const chooseButton = useTemplateRef('chooseButton')
const saveButton = useTemplateRef('saveButton')

/** Forget the chosen folder; the button to choose one gets the focus. */
function cancel(): void {
	pending.value = null
	check.value = null
	checkError.value = ''
	focusAfterUpdate(chooseButton)
}

/**
 * Check who can open the chosen folder.
 *
 * @param folderId - Nextcloud id of the chosen folder
 */
async function checkChosen(folderId: number): Promise<void> {
	checking.value = true
	check.value = null
	checkError.value = ''
	try {
		const report = await fetchArchiveAccess(folderId)
		// Another folder may have been chosen meanwhile
		if (pending.value?.id === folderId) {
			check.value = report
		}
	} catch (error) {
		checkError.value = errorMessage(error)
	} finally {
		checking.value = false
	}
}

async function choose(): Promise<void> {
	let nodes
	try {
		nodes = await getFilePickerBuilder(t('cantoria', 'Choose the archive folder'))
			.setMultiSelect(false)
			.allowDirectories(true)
			.setMimeTypeFilter(['httpd/unix-directory'])
			.setType(FilePickerType.Choose)
			.build()
			.pickNodes()
	} catch (error) {
		if (!(error instanceof FilePickerClosed)) {
			showError(errorMessage(error))
		}
		await focusAfterDialog(chooseButton)
		return
	}
	const node = nodes[0]
	if (!node?.fileid || node.mime !== 'httpd/unix-directory') {
		showError(t('cantoria', 'Please choose a folder.'))
		await focusAfterDialog(chooseButton)
		return
	}
	pending.value = { id: node.fileid, path: node.path }
	// Next step: save the chosen folder; meanwhile the check runs
	checkChosen(node.fileid)
	await focusAfterDialog(saveButton)
}

async function save(): Promise<void> {
	if (!pending.value) {
		return
	}
	saving.value = true
	try {
		emit('saved', await saveSettings({ archiveFolderId: pending.value.id }))
		pending.value = null
		check.value = null
		showSuccess(t('cantoria', 'The archive folder has been saved.'))
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		saving.value = false
	}
	// The buttons to save or cancel are gone (or were disabled)
	await focusAfterUpdate(() => (pending.value ? saveButton.value : chooseButton.value))
}
</script>

<template>
	<div class="settings-block">
		<p class="settings-block__text">
			{{ t('cantoria', 'The folder in Nextcloud that holds the score files (for example one subfolder per binder). The app only reads files. Exports of the catalog are stored in its subfolder "_cantoria-app".') }}
		</p>
		<p v-if="archive.id > 0" class="settings-block__text">
			{{ t('cantoria', 'Backups and annual snapshots name choir members and accounts. They lie outside the archive folder, in a folder of the account that set it, which the role groups do not see unless it is shared with them.') }}
			<template v-if="backups.path">
				<strong>{{ t('cantoria', 'Backup folder:') }}</strong>
				{{ backups.path }}
			</template>
		</p>
		<p v-if="archive.id > 0">
			<strong>{{ t('cantoria', 'Current archive folder:') }}</strong>
			{{ archive.path }}
			<span v-if="archive.user" class="settings-block__muted">
				{{ t('cantoria', '(chosen by {user})', { user: archive.user }) }}
			</span>
		</p>
		<NcNoteCard v-else type="warning">
			{{ t('cantoria', 'No archive folder has been chosen yet.') }}
		</NcNoteCard>
		<p class="settings-block__text settings-block__muted">
			{{ t('cantoria', 'Everyone with a role needs access to this folder in their own Nextcloud, e.g. through a share or a group folder. "Access to the archive folder" below shows who cannot open it.') }}
		</p>

		<div class="settings-block__actions">
			<NcButton ref="chooseButton" :disabled="saving || (!admin && archive.id > 0)" @click="choose">
				<template #icon>
					<NcIconSvgWrapper :path="mdiFolderOutline" />
				</template>
				{{ t('cantoria', 'Choose folder …') }}
			</NcButton>
		</div>
		<p v-if="!admin && archive.id > 0" class="settings-block__text settings-block__muted">
			{{ t('cantoria', 'Only an administrator can change the archive folder: background jobs and backups run as the account that sets it.') }}
		</p>
		<template v-if="pending">
			<p>
				<strong>{{ t('cantoria', 'New archive folder:') }}</strong>
				{{ pending.path }}
				<span class="settings-block__muted">{{ t('cantoria', '(not saved yet)') }}</span>
			</p>

			<!-- What saving does, before saving -->
			<p class="settings-block__text">
				{{ t('cantoria', 'Saving makes this folder the archive folder: the app reads the score files from it as your account sees it, and writes the weekly backups into a folder of your own files outside it ("Music archive backups").') }}
				<template v-if="archive.id > 0">
					{{ t('cantoria', 'Files in the former folder are not moved or deleted; after the next file sync they are listed as "not found" until they are in the new folder too.') }}
				</template>
			</p>

			<!-- Who can open the chosen folder; announced when the check is done -->
			<div role="status" class="settings-block">
				<p v-if="checking" class="settings-block__busy">
					<NcLoadingIcon :size="20" />
					{{ t('cantoria', 'Checking who of the role groups can open this folder …') }}
				</p>
				<NcNoteCard v-else-if="checkError" type="warning">
					{{ t('cantoria', 'It could not be checked who can open this folder: {error}', { error: checkError }) }}
				</NcNoteCard>
				<NcNoteCard v-else-if="problems.length > 0" type="warning">
					<p>{{ t('cantoria', 'These accounts of the role groups cannot open this folder, so the app will show them no files:') }}</p>
					<ul class="archive-folder__problems">
						<li v-for="problem in problems" :key="problem.key">
							{{ t('cantoria', '{account} – group {group} ({role})', { account: problem.account, group: problem.group, role: problem.role }) }}
						</li>
					</ul>
					<p>{{ t('cantoria', 'Share the folder with their groups before or right after saving; reading is enough.') }}</p>
					<GuideLink :href="adminGuideUrl(ADMIN_GUIDE.sharing)" :label="t('cantoria', 'Sharing the archive folder (administration guide)')" />
				</NcNoteCard>
				<NcNoteCard v-else-if="noRoleGroups" type="info">
					{{ t('cantoria', 'No group has a role yet. Give the roles their groups below; then check access.') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="check" type="success">
					{{ t('cantoria', 'Every checked account of the role groups can open this folder.') }}
				</NcNoteCard>
			</div>

			<div class="settings-block__actions">
				<NcButton :disabled="saving" @click="cancel">
					{{ t('cantoria', 'Cancel') }}
				</NcButton>
				<NcButton ref="saveButton"
					variant="primary"
					:disabled="saving"
					@click="save">
					<template v-if="saving" #icon>
						<NcLoadingIcon />
					</template>
					{{ t('cantoria', 'Save archive folder') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<style scoped src="./settings-block.css"></style>

<style scoped>
.archive-folder__problems {
	margin: var(--default-grid-baseline) 0;
	padding-inline-start: 1.5em;
	list-style: disc;
}
</style>
