<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Checksums of the archive files: how far the background check is, and the
 * files whose content changed without a visible modification (possibly
 * damaged). The librarian restores such a file or accepts the new content.
 */
import type { ChecksumMismatch, ChecksumStatus } from '../types.ts'

import { mdiAlertCircleOutline, mdiCheckCircleOutline, mdiFileAlertOutline, mdiOpenInNew } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { generateUrl } from '@nextcloud/router'
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ActionDialog from './ActionDialog.vue'
import ViewHeader from './ViewHeader.vue'
import { acceptChecksum, errorMessage, fetchChecksums } from '../api.ts'
import { formatDateTime } from '../format.ts'
import { isLibrarian, loadDetail, openEntry } from '../store.ts'

const status = ref<ChecksumStatus | null>(null)
const loading = ref(false)
const error = ref('')

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		status.value = await fetchChecksums()
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		loading.value = false
	}
}

onMounted(load)

/**
 * Link that opens the file in Nextcloud.
 *
 * @param mismatch - file with a deviation
 */
function fileUrl(mismatch: ChecksumMismatch): string {
	return generateUrl('/f/{fileId}', { fileId: mismatch.file_id })
}

// ---- accept ----

const accepting = ref<ChecksumMismatch | null>(null)
const reason = ref('')
const busy = ref(false)
const acceptError = ref('')

/**
 * @param mismatch - file whose new content is accepted
 */
function startAccept(mismatch: ChecksumMismatch): void {
	accepting.value = mismatch
	reason.value = ''
	acceptError.value = ''
}

async function confirmAccept(): Promise<void> {
	if (!accepting.value) {
		return
	}
	busy.value = true
	acceptError.value = ''
	try {
		await acceptChecksum(accepting.value.id, reason.value.trim() === '' ? null : reason.value.trim())
		showSuccess(t('cantoria', 'The new content has been accepted.'))
		accepting.value = null
		await Promise.all([load(), loadDetail()])
	} catch (caught) {
		acceptError.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<div class="checksums-view">
		<ViewHeader :title="t('cantoria', 'Checksums')"
			:description="t('cantoria', 'A background job reads the archive files round by round and keeps their checksum (SHA-256). A file whose content changed without a visible modification may be damaged.')"
			reloadable
			:loading="loading"
			@reload="load" />

		<div class="checksums-view__content">
			<NcEmptyContent v-if="!status && loading" :name="t('cantoria', 'Loading …')">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="!status && error" :name="t('cantoria', 'The list could not be loaded')" :description="error">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
				</template>
				<template #action>
					<NcButton @click="load">
						{{ t('cantoria', 'Try again') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<template v-else-if="status">
				<NcNoteCard v-if="error" type="error" role="alert">
					{{ error }}
				</NcNoteCard>
				<dl class="checksums-view__facts">
					<dt>{{ t('cantoria', 'Files') }}</dt>
					<dd>{{ status.files }}</dd>
					<dt>{{ t('cantoria', 'With checksum') }}</dt>
					<dd>{{ status.hashed }}</dd>
					<dt>{{ t('cantoria', 'Not yet read') }}</dt>
					<dd>{{ status.pending }}</dd>
					<dt>{{ t('cantoria', 'Oldest check') }}</dt>
					<dd>{{ status.oldestCheck ? formatDateTime(status.oldestCheck) : '–' }}</dd>
				</dl>
				<p class="checksums-view__muted">
					{{ n('cantoria', 'Each hourly run reads up to %n file.', 'Each hourly run reads up to %n files.', status.perRun) }}
				</p>

				<section class="checksums-view__section" aria-labelledby="checksums-mismatches">
					<h3 id="checksums-mismatches">
						{{ t('cantoria', 'Deviations') }}
						<template v-if="status.mismatches.length > 0">
							({{ status.mismatches.length }})
						</template>
					</h3>
					<p v-if="status.mismatches.length === 0" class="checksums-view__ok">
						<NcIconSvgWrapper :path="mdiCheckCircleOutline" inline />
						{{ t('cantoria', 'No deviations: every file read so far is unchanged.') }}
					</p>
					<template v-else>
						<NcNoteCard type="warning">
							{{ t('cantoria', 'These files changed although their size and modification time stayed the same. Restore the file from a backup or from the version history; the mark then disappears by itself. Accept the new content only if the change was intended.') }}
						</NcNoteCard>
						<ul class="checksums-view__list">
							<li v-for="mismatch in status.mismatches" :key="mismatch.id" class="checksums-view__item">
								<NcIconSvgWrapper class="checksums-view__icon" :path="mdiFileAlertOutline" />
								<div class="checksums-view__text">
									<span class="checksums-view__name">{{ mismatch.shelfmark }} – {{ mismatch.title }}</span>
									<span class="checksums-view__path">{{ mismatch.path }}</span>
									<span v-if="mismatch.sha256_at" class="checksums-view__muted">
										{{ t('cantoria', 'Deviation found on {date}', { date: formatDateTime(mismatch.sha256_at) }) }}
									</span>
								</div>
								<div class="checksums-view__actions">
									<NcButton variant="tertiary"
										:href="fileUrl(mismatch)"
										target="_blank"
										rel="noopener noreferrer"
										:aria-label="t('cantoria', 'Open file {path} (opens in a new tab)', { path: mismatch.path })">
										<template #icon>
											<NcIconSvgWrapper :path="mdiOpenInNew" />
										</template>
										{{ t('cantoria', 'Open file') }}
									</NcButton>
									<NcButton variant="tertiary" @click="openEntry(mismatch.copyId, 'files')">
										{{ t('cantoria', 'Open {shelfmark}', { shelfmark: mismatch.shelfmark }) }}
									</NcButton>
									<NcButton v-if="isLibrarian" variant="secondary" @click="startAccept(mismatch)">
										{{ t('cantoria', 'Accept new content') }}
									</NcButton>
								</div>
							</li>
						</ul>
					</template>
				</section>
			</template>
		</div>

		<ActionDialog v-if="accepting"
			:name="t('cantoria', 'Accept new content')"
			:submit-label="t('cantoria', 'Accept')"
			:busy="busy"
			:error="acceptError"
			variant="error"
			@submit="confirmAccept"
			@close="accepting = null">
			<p>{{ t('cantoria', 'File: {path}', { path: accepting.path }) }}</p>
			<p class="action-dialog__hint">
				{{ t('cantoria', 'Only if you know that the file was changed on purpose. The next check stores the checksum of the current content; the old content can then no longer be recognised as the original.') }}
			</p>
			<NcTextField v-model="reason" :label="t('cantoria', 'Reason (optional)')" />
		</ActionDialog>
	</div>
</template>

<style scoped>
.checksums-view__content {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
	max-width: 60em;
	padding: calc(3 * var(--default-grid-baseline));
}

.checksums-view__facts {
	display: grid;
	grid-template-columns: max-content auto;
	gap: 2px calc(4 * var(--default-grid-baseline));
	margin: 0;
}

.checksums-view__facts dt {
	color: var(--color-text-maxcontrast);
}

.checksums-view__facts dd {
	margin: 0;
	font-variant-numeric: tabular-nums;
}

.checksums-view__section {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.checksums-view__section h3 {
	margin: 0;
	font-size: 1.2em;
	font-weight: bold;
}

.checksums-view__ok {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	margin: 0;
}

.checksums-view__list {
	display: flex;
	flex-direction: column;
}

.checksums-view__item {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	gap: calc(2 * var(--default-grid-baseline));
	padding-block: calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.checksums-view__icon {
	flex-shrink: 0;
	color: var(--color-warning-text, var(--color-warning));
}

.checksums-view__text {
	display: flex;
	flex: 1 1 18em;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	overflow-wrap: anywhere;
}

.checksums-view__name {
	font-weight: bold;
}

.checksums-view__path,
.checksums-view__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.checksums-view__actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}
</style>
