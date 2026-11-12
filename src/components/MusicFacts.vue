<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * What the score of a copy tells (module score_facts): the first bars, key,
 * time and length, and the range of each voice next to the choir profile,
 * with the transposition that fits best and the voices the piece needs that
 * the choir does not have.
 */
import type { CatalogFacts } from '../scoreFacts.ts'

import { mdiAccountMusicOutline } from '@mdi/js'
import { getLanguage } from '@nextcloud/l10n'
import { n, t } from '../l10n.ts'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import AmbitusChart from './AmbitusChart.vue'
import ChoirProfileDialog from './ChoirProfileDialog.vue'
import FitBadge from './FitBadge.vue'
import IncipitImage from './IncipitImage.vue'
import { groupLabel, keyName, pieceFit, transposedKey } from '../scoreFacts.ts'
import { modeWords, transposeLabel } from '../scoreFactsLabels.ts'
import { scoreFacts } from '../store.ts'

const props = defineProps<{
	facts: CatalogFacts
}>()

const german = getLanguage().toLowerCase().startsWith('de')
const profileOpen = ref(false)
const showTransposed = ref(false)

const profile = computed(() => scoreFacts.value?.profile.groups ?? [])
const fit = computed(() => pieceFit(props.facts.voices, profile.value))
const shift = computed(() => (showTransposed.value ? (fit.value.transpose ?? 0) : 0))

const keyText = computed(() => {
	const key = props.facts.keyStart
	if (!key) {
		return ''
	}
	const name = keyName(key, german, modeWords())
	if (fit.value.transpose && fit.value.transpose !== 0) {
		const moved = keyName(transposedKey(key, fit.value.transpose), german, modeWords())
		return t('cantoria', '{key}; for our ranges {transposition}: {moved}', { key: name, transposition: transposeLabel(fit.value.transpose), moved })
	}
	return name
})

const missingText = computed(() => fit.value.missing.map(groupLabel).join(', '))
const unsungText = computed(() => fit.value.unsung.map(groupLabel).join(', '))
</script>

<template>
	<section class="music-facts cantoria-appear" aria-labelledby="music-facts-heading">
		<h3 id="music-facts-heading">
			{{ t('cantoria', 'Music') }}
		</h3>
		<IncipitImage v-if="facts.incipit" :incipit="facts.incipit" large />
		<dl class="music-facts__list">
			<template v-if="keyText">
				<dt>{{ t('cantoria', 'Key') }}</dt>
				<dd>{{ keyText }}</dd>
			</template>
			<template v-if="facts.measures > 0">
				<dt>{{ t('cantoria', 'Length of the piece') }}</dt>
				<dd>{{ n('cantoria', '%n measure', '%n measures', facts.measures) }}</dd>
			</template>
		</dl>
		<div v-if="facts.voices.length > 0" class="music-facts__ranges">
			<div class="music-facts__fit">
				<FitBadge :fit="fit" />
				<NcButton v-if="fit.transpose" variant="tertiary" @click="showTransposed = !showTransposed">
					{{ showTransposed ? t('cantoria', 'Show as written') : t('cantoria', 'Show {transposition}', { transposition: transposeLabel(fit.transpose) }) }}
				</NcButton>
			</div>
			<AmbitusChart :voices="facts.voices"
				:profile="profile"
				:transpose="shift"
				:fifths="facts.keyStart?.fifths ?? null" />
			<p v-if="missingText" class="music-facts__note">
				{{ t('cantoria', 'The piece needs voices the choir profile does not have: {groups}.', { groups: missingText }) }}
			</p>
			<p v-if="unsungText" class="music-facts__note">
				{{ t('cantoria', 'Not used in this piece: {groups}.', { groups: unsungText }) }}
			</p>
		</div>
		<NcButton variant="tertiary" @click="profileOpen = true">
			<template #icon>
				<NcIconSvgWrapper :path="mdiAccountMusicOutline" />
			</template>
			{{ t('cantoria', 'Choir profile') }}
		</NcButton>
		<ChoirProfileDialog v-if="profileOpen" @close="profileOpen = false" />
	</section>
</template>

<style scoped>
.music-facts {
	display: grid;
	gap: 10px;
	margin-block-end: 20px;
	padding: 12px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.music-facts h3 {
	margin: 0;
}

.music-facts__list {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 2px 12px;
	margin: 0;
}

.music-facts__list dt {
	color: var(--color-text-maxcontrast);
}

.music-facts__list dd {
	margin: 0;
}

.music-facts__fit {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.music-facts__ranges {
	display: grid;
	gap: 10px;
}

.music-facts__note {
	color: var(--color-text-maxcontrast);
}
</style>
