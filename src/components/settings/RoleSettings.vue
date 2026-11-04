<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Settings "Roles and groups": which Nextcloud groups hold which role.
 * Roles are bound to groups, never to persons. Before saving, the form
 * lists which groups get or lose a role and warns when nobody would keep
 * the catalog.
 */
import type { Settings } from '../../types.ts'

import { showSuccess } from '@nextcloud/dialogs'
import { t } from '../../l10n.ts'
import { computed, reactive, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import GroupSelect from './GroupSelect.vue'
import GuideLink from '../GuideLink.vue'
import { errorMessage, saveSettings } from '../../api.ts'
import { ADMIN_GUIDE, adminGuideUrl } from '../../docs.ts'
import { translateLabel } from '../../format.ts'
import { catalogRoleEmpty, roleChanges } from '../../settings.ts'
import { showError } from '../../toasts.ts'

const props = defineProps<{
	settings: Settings
}>()

const emit = defineEmits<{
	/** the roles were saved; the settings as the server has them now */
	saved: [settings: Settings]
}>()

const draft = reactive<Record<string, string[]>>({})
const saving = ref(false)

/** Role keys with their translated labels, in the order of the server. */
const roles = computed(() => Object.entries(props.settings.roleLabels)
	.map(([key, label]) => ({ key, label: translateLabel(label), description: roleDescription(key) })))

/**
 * Short explanation of what a role may do.
 *
 * @param role - role key
 */
function roleDescription(role: string): string {
	switch (role) {
	case 'librarian':
		return t('cantoria', 'Maintains works, versions and rights; imports the catalog and runs the file sync.')
	case 'operations':
		return t('cantoria', 'Maintains the status and counts of copies.')
	case 'conductor':
		return t('cantoria', 'Decides which version is valid for the choir.')
	case 'contributor':
		return t('cantoria', 'Tools and transcription rounds: proposals only.')
	case 'reader':
		return t('cantoria', 'Reads everything, including the audit log.')
	case 'member':
		return t('cantoria', 'Sees only the pieces and excerpts shared with choir members, while the module "Links for choir members" is on; no access to the catalog.')
	default:
		return ''
	}
}

// The form follows the settings of the server (on load and after saving)
watch(() => props.settings.roles, (value) => {
	for (const key of Object.keys(draft)) {
		delete draft[key]
	}
	for (const key of Object.keys(props.settings.roleLabels)) {
		draft[key] = [...(value[key] ?? [])]
	}
}, { immediate: true })

/**
 * Display name of a group (the id for a group found only now).
 *
 * @param id - group id
 */
function groupName(id: string): string {
	return props.settings.groupNames[id] ?? id
}

/** What saving would change, per role, in words */
const changes = computed(() => roleChanges(props.settings.roles, draft).map((change) => ({
	role: translateLabel(props.settings.roleLabels[change.role] ?? change.role),
	added: change.added.map(groupName).join(', '),
	removed: change.removed.map(groupName).join(', '),
})))
const noCatalogRole = computed(() => catalogRoleEmpty(draft))

async function save(): Promise<void> {
	saving.value = true
	try {
		emit('saved', await saveSettings({ roles: { ...draft } }))
		showSuccess(t('cantoria', 'The roles have been saved.'))
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<form class="settings-block" @submit.prevent="save">
		<NcNoteCard type="info">
			<p>{{ t('cantoria', 'Roles are linked to groups. Whoever has no role cannot see the app.') }}</p>
			<GuideLink :href="adminGuideUrl(ADMIN_GUIDE.roles)" :label="t('cantoria', 'Roles and groups (administration guide)')" />
		</NcNoteCard>
		<NcNoteCard v-if="settings.groupsMissing.length > 0" type="warning">
			{{ t('cantoria', 'These groups no longer exist: {groups}. Remove them from their role and choose the right group instead.', { groups: settings.groupsMissing.join(', ') }) }}
		</NcNoteCard>
		<GroupSelect v-for="role in roles"
			:key="role.key"
			class="settings-role"
			:model-value="draft[role.key] ?? []"
			:input-id="`settings-role-${role.key}`"
			:label="role.label"
			:description="role.description"
			:names="settings.groupNames"
			:disabled="saving"
			@update:model-value="draft[role.key] = $event" />

		<!-- What saving does, before saving -->
		<div v-if="changes.length > 0" class="settings-block settings-roles__changes">
			<p><strong>{{ t('cantoria', 'Not saved yet. Saving changes:') }}</strong></p>
			<ul>
				<li v-for="change in changes" :key="change.role">
					{{ change.role }}:
					<template v-if="change.added">
						{{ t('cantoria', 'gets {groups}', { groups: change.added }) }}<template v-if="change.removed">
							;
						</template>
					</template>
					<template v-if="change.removed">
						{{ t('cantoria', 'loses {groups}', { groups: change.removed }) }}
					</template>
				</li>
			</ul>
			<p class="settings-block__muted">
				{{ t('cantoria', 'Members of a group get or lose the role at once. Afterwards check access to the archive folder: new role holders need it, too.') }}
			</p>
		</div>
		<NcNoteCard v-if="noCatalogRole" type="warning">
			{{ t('cantoria', 'The catalog role (music librarians) has no group: nobody can import or keep the catalog.') }}
		</NcNoteCard>

		<div class="settings-block__actions">
			<NcButton type="submit" variant="primary" :disabled="saving">
				<template v-if="saving" #icon>
					<NcLoadingIcon />
				</template>
				{{ t('cantoria', 'Save roles') }}
			</NcButton>
		</div>
	</form>
</template>

<style scoped src="./settings-block.css"></style>

<style scoped>
.settings-role {
	max-width: 40em;
}

.settings-roles__changes {
	padding: calc(2 * var(--default-grid-baseline));
	border-inline-start: 4px solid var(--color-border-maxcontrast);
}

.settings-roles__changes ul {
	margin: 0;
	padding-inline-start: 1.5em;
	list-style: disc;
}
</style>
