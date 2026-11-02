<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The page Administration → Cantoria: the setup in six steps, archive
 * folder, roles and groups, access to the archive folder, modules,
 * repertoire, and for admins the complete backup and restore. Each section
 * links its part of the administration guide.
 *
 * For admins and the groups the administration has given the page under
 * Administration privileges. It needs no catalog and loads only the
 * settings (its own small bundle, without the score viewer).
 */
import type { AccessReport, RepertoireThresholds, Settings } from '../../types.ts'

import { mdiArrowRight } from '@mdi/js'
import { t } from '../../l10n.ts'
import { generateUrl } from '@nextcloud/router'
import { nextTick, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import ArchiveAccessCheck from './ArchiveAccessCheck.vue'
import ArchiveFolderSettings from './ArchiveFolderSettings.vue'
import RepertoireSettings from './RepertoireSettings.vue'
import RoleSettings from './RoleSettings.vue'
import SetupChecklist from './SetupChecklist.vue'
import { errorMessage, fetchSettings } from '../../api.ts'
import { ADMIN_GUIDE, adminGuideUrl } from '../../docs.ts'
import { SETTINGS_SECTIONS } from '../../settings.ts'
import BackupRestore from '../BackupRestore.vue'
import FolderBookSettings from '../FolderBookSettings.vue'
import ModuleSettings from '../ModuleSettings.vue'
import PrintMirrorSettings from '../PrintMirrorSettings.vue'

const settings = ref<Settings | null>(null)
const loading = ref(true)
const loadError = ref('')
/** Result of the access check of the saved archive folder, while the page is open */
const accessReport = ref<AccessReport | null>(null)
/** Opened through the link of the app: run the access check right away */
const accessRequested = window.location.hash === '#' + SETTINGS_SECTIONS.access

const appUrl = generateUrl('/apps/cantoria/')
const sections = SETTINGS_SECTIONS

/**
 * Take the server's settings as the state of the page.
 *
 * @param value - settings from the server
 */
function apply(value: Settings): void {
	settings.value = value
}

/**
 * The roles were saved: an earlier access check no longer applies.
 *
 * @param value - settings from the server
 */
function onRolesSaved(value: Settings): void {
	apply(value)
	accessReport.value = null
}

/**
 * New repertoire thresholds.
 *
 * @param thresholds - as saved by the server
 */
function onThresholdsSaved(thresholds: RepertoireThresholds): void {
	if (settings.value) {
		settings.value = { ...settings.value, repertoire: thresholds }
	}
}

async function load(): Promise<void> {
	loading.value = settings.value === null
	loadError.value = ''
	try {
		apply(await fetchSettings())
	} catch (error) {
		loadError.value = errorMessage(error)
	} finally {
		loading.value = false
	}
}

onMounted(async () => {
	await load()
	if (accessRequested) {
		// The section exists only now; the browser could not jump to it.
		await nextTick()
		document.getElementById(SETTINGS_SECTIONS.access)?.scrollIntoView({ block: 'start' })
	}
})
</script>

<template>
	<div class="cantoria-settings">
		<NcSettingsSection :id="sections.setup"
			:name="t('cantoria', 'Cantoria')"
			:description="t('cantoria', 'Settings of Cantoria for the whole instance. The daily work happens in the app.')"
			:doc-url="adminGuideUrl(ADMIN_GUIDE.setup)">
			<div class="cantoria-settings__intro">
				<SetupChecklist v-if="settings" :settings="settings" :report="accessReport" />
				<NcButton :href="appUrl">
					<template #icon>
						<NcIconSvgWrapper :path="mdiArrowRight" />
					</template>
					{{ t('cantoria', 'Open Cantoria') }}
				</NcButton>
				<p v-if="settings?.admin" class="cantoria-settings__muted">
					{{ t('cantoria', 'Administrators can give this page to a group, e.g. the music librarians, under Administration privileges. That gives no role; complete backup and restore stay with administrators.') }}
				</p>
			</div>
		</NcSettingsSection>

		<p v-if="loading" class="cantoria-settings__busy">
			<NcLoadingIcon :size="20" />
			{{ t('cantoria', 'Loading …') }}
		</p>
		<NcNoteCard v-else-if="loadError && settings === null" type="error">
			<p>{{ loadError }}</p>
			<NcButton @click="load">
				{{ t('cantoria', 'Try again') }}
			</NcButton>
		</NcNoteCard>

		<template v-if="settings">
			<NcSettingsSection :id="sections.archive"
				:name="t('cantoria', 'Archive folder')"
				:doc-url="adminGuideUrl(ADMIN_GUIDE.archive)">
				<ArchiveFolderSettings :archive="settings.archive"
					:backups="settings.backups"
					:admin="settings.admin"
					:role-labels="settings.roleLabels"
					@saved="apply" />
			</NcSettingsSection>

			<NcSettingsSection :id="sections.roles"
				:name="t('cantoria', 'Roles and groups')"
				:doc-url="adminGuideUrl(ADMIN_GUIDE.roles)">
				<RoleSettings :settings="settings" @saved="onRolesSaved" />
			</NcSettingsSection>

			<NcSettingsSection :id="sections.access"
				:name="t('cantoria', 'Access to the archive folder')"
				:doc-url="adminGuideUrl(ADMIN_GUIDE.access)">
				<ArchiveAccessCheck v-model:report="accessReport"
					:archive="settings.archive"
					:role-labels="settings.roleLabels"
					:auto-start="accessRequested" />
			</NcSettingsSection>

			<NcSettingsSection :id="sections.modules"
				:name="t('cantoria', 'Modules')"
				:doc-url="adminGuideUrl(ADMIN_GUIDE.modules)">
				<ModuleSettings @changed="load" />
			</NcSettingsSection>

			<NcSettingsSection v-if="settings.repertoire"
				:id="sections.repertoire"
				:name="t('cantoria', 'Repertoire')"
				:doc-url="adminGuideUrl(ADMIN_GUIDE.roles)">
				<RepertoireSettings :thresholds="settings.repertoire" @saved="onThresholdsSaved" />
			</NcSettingsSection>

			<NcSettingsSection v-if="settings.printMirror"
				:id="sections.printMirror"
				:name="t('cantoria', 'Print station: mirror folder')"
				:doc-url="adminGuideUrl(ADMIN_GUIDE.printMirror)">
				<PrintMirrorSettings />
			</NcSettingsSection>

			<NcSettingsSection v-if="settings.choirHandover"
				:id="sections.choirHandover"
				:name="t('cantoria', 'Hand-over to the choir system')"
				:doc-url="adminGuideUrl(ADMIN_GUIDE.choirHandover)">
				<PrintMirrorSettings kind="handover" />
			</NcSettingsSection>

			<NcSettingsSection v-if="settings.folderBook"
				:id="sections.folderBook"
				:name="t('cantoria', 'Folder book: retention period')"
				:doc-url="adminGuideUrl(ADMIN_GUIDE.folderBook)">
				<FolderBookSettings />
			</NcSettingsSection>

			<!-- Restoring stays with admins; giving the page to a group does not include it -->
			<NcSettingsSection v-if="settings.admin"
				:id="sections.backup"
				:name="t('cantoria', 'Complete backup and restore')"
				:doc-url="adminGuideUrl(ADMIN_GUIDE.backup)">
				<BackupRestore :catalog-filled="settings.catalogEmpty === false" @restored="load" />
			</NcSettingsSection>
		</template>
	</div>
</template>

<style scoped>
.cantoria-settings__intro {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: calc(3 * var(--default-grid-baseline));
}

.cantoria-settings__intro p {
	max-width: 70ch;
	margin: 0;
}

.cantoria-settings__muted {
	color: var(--color-text-maxcontrast);
}

.cantoria-settings__busy {
	display: flex;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
	padding: calc(4 * var(--default-grid-baseline));
}
</style>
