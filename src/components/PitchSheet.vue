<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pitches" :class="{ 'pitches--print': print }">
		<table class="pitches__table">
			<caption :class="{ 'hidden-visually': !print }">
				{{ t('cantoria', 'Pitch sheet') }}
			</caption>
			<thead>
				<tr>
					<th scope="col">
						{{ t('cantoria', 'No.') }}
					</th>
					<th scope="col">
						{{ t('cantoria', 'Piece') }}
					</th>
					<th scope="col">
						{{ t('cantoria', 'Key') }}
					</th>
					<th scope="col">
						{{ t('cantoria', 'First tones') }}
					</th>
					<th scope="col">
						{{ t('cantoria', 'Tempo') }}
					</th>
					<th scope="col">
						{{ t('cantoria', 'From the piece before') }}
					</th>
					<th v-if="!print" scope="col">
						<span class="hidden-visually">{{ t('cantoria', 'Play') }}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(row, index) in rows" :key="row.piece.itemId" :class="{ 'pitches__row--far': row.transition === 'far' }">
					<td class="pitches__number">
						{{ index + 1 }}
					</td>
					<th scope="row" class="pitches__piece">
						<span class="pitches__title">{{ row.piece.title }}</span>
						<span v-if="row.piece.encore" class="pitches__tag">{{ t('cantoria', 'Encore') }}</span>
						<span v-if="row.piece.composer" class="pitches__composer">{{ row.piece.composer }}</span>
						<IncipitImage v-if="row.piece.facts?.incipit"
							class="pitches__incipit"
							:incipit="row.piece.facts.incipit"
							:eager="print" />
					</th>
					<td>
						<template v-if="row.keyStart">
							{{ row.keyStart }}
							<span v-if="row.keyEnd && row.keyEnd !== row.keyStart" class="pitches__end">
								{{ t('cantoria', 'ends in {key}', { key: row.keyEnd }) }}
							</span>
						</template>
						<template v-else>
							{{ row.piece.keySignature ?? '–' }}
						</template>
					</td>
					<td>
						<ul v-if="row.voices.length > 0" class="pitches__tones">
							<li v-for="(voice, voiceIndex) in row.voices" :key="voiceIndex">
								<VoiceMark :group="voice.group" />
								<span class="pitches__tone">{{ voice.tone }}</span>
							</li>
						</ul>
						<span v-else class="pitches__none">{{ row.piece.facts === null ? t('cantoria', 'not read from a score') : '–' }}</span>
					</td>
					<td class="pitches__tempo">
						<template v-if="row.piece.facts?.tempo">
							♩ = {{ row.piece.facts.tempo }}
						</template>
						<template v-if="row.piece.facts?.time">
							<br>{{ row.piece.facts.time }}
						</template>
					</td>
					<td>
						<template v-if="row.transition">
							<strong v-if="row.transition === 'far' || row.transition === 'near'">{{ transitionLabel(row.transition) }}</strong>
							<template v-else>
								{{ transitionLabel(row.transition) }}
							</template>
						</template>
					</td>
					<td v-if="!print">
						<NcButton v-if="row.voices.length > 0"
							variant="tertiary"
							:aria-label="t('cantoria', 'Play the first tones: {piece}', { piece: row.piece.title })"
							@click="play(row)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiPlay" />
							</template>
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
		<p v-if="!print" class="pitches__hint">
			{{ t('cantoria', 'The first tones sound one after the other from the lowest, then together. They are read from the MusicXML of the copy the programme uses; check them against the score.') }}
		</p>
	</div>
</template>

<script setup lang="ts">
/**
 * The pitch sheet for the conductor (proposal 3): per piece the key at the
 * start and the end, the first tone of every voice, tempo and time, and
 * whether the key of the piece before leads into it or a new tone has to
 * be given. The first tones can be played; the same table prints on A4.
 */
import type { PitchPiece, Transition, VoiceGroup } from '../scoreFacts.ts'

import { mdiPlay } from '@mdi/js'
import { getLanguage } from '@nextcloud/l10n'
import { computed, onBeforeUnmount } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import IncipitImage from './IncipitImage.vue'
import VoiceMark from './VoiceMark.vue'
import { t } from '../l10n.ts'
import { ScorePlayer } from '../score/player.ts'
import { keyName, toneName, transition } from '../scoreFacts.ts'
import { modeWords, transitionLabel } from '../scoreFactsLabels.ts'

const props = defineProps<{
	pieces: PitchPiece[]
	/** the version for paper: no buttons, incipits drawn at once */
	print?: boolean
}>()

const german = getLanguage().toLowerCase().startsWith('de')
const words = modeWords()

interface Row {
	piece: PitchPiece
	keyStart: string | null
	keyEnd: string | null
	voices: { group: VoiceGroup | null, tone: string, pitch: number }[]
	transition: Transition | null
}

const rows = computed<Row[]>(() => {
	let before: PitchPiece | null = null
	return props.pieces.map((piece) => {
		const facts = piece.facts
		const fifths = facts?.keyStart?.fifths ?? null
		const row: Row = {
			piece,
			keyStart: facts?.keyStart ? keyName(facts.keyStart, german, words) : null,
			keyEnd: facts?.keyEnd ? keyName(facts.keyEnd, german, words) : null,
			voices: (facts?.voices ?? [])
				.filter((voice) => voice.first !== null)
				.map((voice) => ({ group: voice.group, pitch: voice.first as number, tone: toneName(voice.first as number, fifths, german) })),
			transition: facts?.keyStart && before?.facts?.keyEnd ? transition(before.facts.keyEnd, facts.keyStart) : null,
		}
		before = piece
		return row
	})
})

let player: ScorePlayer | null = null

/**
 * Play the first tones of a piece.
 *
 * @param row - the piece
 */
async function play(row: Row): Promise<void> {
	player ??= new ScorePlayer({ onPosition: () => {}, onEnd: () => {} })
	await player.playTones(row.voices.map((voice) => voice.pitch).sort((a, b) => a - b))
}

onBeforeUnmount(() => {
	player?.destroy()
})
</script>

<style scoped>
.pitches__table {
	width: 100%;
	border-collapse: collapse;
}

.pitches__table th,
.pitches__table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
}

.pitches__table thead th {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
	font-weight: 600;
}

.pitches__number {
	font-variant-numeric: tabular-nums;
	font-weight: 700;
}

.pitches__piece {
	font-weight: normal;
}

.pitches__title {
	display: block;
	font-weight: 700;
}

.pitches__composer,
.pitches__end,
.pitches__none,
.pitches__hint {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.pitches__tag {
	display: inline-block;
	padding: 0 4px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-small, 4px);
	font-size: var(--font-size-small, 13px);
}

.pitches__incipit {
	display: block;
	margin-top: 4px;
}

.pitches__tones {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 12px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.pitches__tones li {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

.pitches__tone {
	font-size: 1.1em;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
}

.pitches__tempo {
	white-space: nowrap;
}

.pitches__row--far {
	box-shadow: inset 4px 0 0 var(--color-warning);
}

.pitches__hint {
	margin: 8px 0 0;
}

.pitches--print {
	color: #000;
}

.pitches--print .pitches__table caption {
	margin-bottom: 3mm;
	font-size: 1.3em;
	font-weight: 700;
	text-align: start;
}

.pitches--print .pitches__table th,
.pitches--print .pitches__table td {
	padding: 1.5mm 2mm;
	border: 0.3mm solid #000;
}

.pitches--print .pitches__table tr {
	break-inside: avoid;
}

.pitches--print .pitches__row--far {
	box-shadow: none;
}

.pitches--print .pitches__composer,
.pitches--print .pitches__end,
.pitches--print .pitches__none,
.pitches--print thead th {
	color: #000;
}
</style>
