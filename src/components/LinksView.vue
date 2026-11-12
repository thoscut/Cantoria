<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * All active excerpt links, for review by the board and the auditors: who
 * can open them, where they point, until when they are valid, how often
 * they were opened (a count only, never who). Below them the links that
 * ended in the last 30 days, which can still be extended (U40).
 */
import type { ExcerptLink } from '../types.ts'

import { mdiAlertCircleOutline, mdiBookOpenPageVariantOutline, mdiLinkVariantOff } from '@mdi/js'
import { n, t } from '../l10n.ts'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import LinkList from './LinkList.vue'
import ReloadError from './ReloadError.vue'
import ViewHeader from './ViewHeader.vue'
import { errorMessage, fetchLinks } from '../api.ts'
import { canonicalShelfmark } from '../catalog.ts'
import { toIsoDate } from '../format.ts'
import { navigate } from '../navigation.ts'
import { hasEnded } from '../sharing/linkDates.ts'
import { entriesById, isModuleActive } from '../store.ts'

const links = ref<ExcerptLink[]>([])
const loaded = ref(false)
const loading = ref(false)
const error = ref('')

/** Counter of the loads: only the answer to the latest one counts (a filter switched quickly) */
let loadRun = 0

async function load(): Promise<void> {
	const run = ++loadRun
	loading.value = true
	error.value = ''
	try {
		const result = await fetchLinks()
		if (run !== loadRun) {
			return
		}
		links.value = result
		loaded.value = true
	} catch (caught) {
		if (run === loadRun) {
			error.value = errorMessage(caught)
		}
	} finally {
		if (run === loadRun) {
			loading.value = false
		}
	}
}

onMounted(load)

/** Links for choir members or the public can exist (modules of milestone 0.7). */
const sharingOn = computed(() => isModuleActive('member_links') || isModuleActive('public_links'))

/** The links that have not ended (the others follow in a section of their own) */
const activeCount = computed(() => links.value.filter((link) => !hasEnded(link.expires, toIsoDate(new Date()))).length)

const collator = new Intl.Collator(undefined, { numeric: true })

const sorted = computed(() => [...links.value].sort((a, b) => collator.compare(
	canonicalShelfmark(entriesById.value.get(a.copy_id)?.shelfmark ?? ''),
	canonicalShelfmark(entriesById.value.get(b.copy_id)?.shelfmark ?? ''),
) || (a.expires ?? '').localeCompare(b.expires ?? '')))
</script>

<template>
	<div class="links-view">
		<ViewHeader :title="t('cantoria', 'Links')"
			:description="sharingOn
				? t('cantoria', 'All active excerpt links: internal links for the archive roles, links for choir members and public links. Each entry says who can open it. The server checks the rights on every call. Calls are counted without names.')
				: t('cantoria', 'All active excerpt links. They are only opened by people with a role in the music archive; the server checks the rights on every call. Calls are counted without names.')"
			printable
			reloadable
			:loading="loading"
			@reload="load" />
		<div class="links-view__content">
			<ReloadError v-if="loaded && error" :error="error" @retry="load()" />
			<NcEmptyContent v-if="!loaded && loading" :name="t('cantoria', 'Loading …')">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="!loaded && error"
				:name="t('cantoria', 'The links could not be loaded')"
				:description="error">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
				</template>
				<template #action>
					<NcButton @click="load">
						{{ t('cantoria', 'Try again') }}
					</NcButton>
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="links.length === 0"
				:name="t('cantoria', 'No active links')"
				:description="t('cantoria', 'Links are created in the score view: open a copy in the catalog, then “Score”.')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiLinkVariantOff" />
				</template>
				<template #action>
					<NcButton @click="navigate('catalog')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiBookOpenPageVariantOutline" />
						</template>
						{{ t('cantoria', 'Open the catalog') }}
					</NcButton>
				</template>
			</NcEmptyContent>
			<template v-else>
				<p class="links-view__count">
					{{ n('cantoria', '%n active link', '%n active links', activeCount) }}
				</p>
				<LinkList :links="sorted" show-copy @changed="load" />
			</template>
		</div>
	</div>
</template>

<style scoped>
.links-view__content {
	padding: calc(3 * var(--default-grid-baseline));
}

.links-view__count {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
