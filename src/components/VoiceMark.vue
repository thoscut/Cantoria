<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<span class="voice-mark" :class="`voice-mark--${(group ?? 'none').toLowerCase()}`">
		<span class="voice-mark__letter" aria-hidden="true">{{ letter }}</span>
		<span v-if="label" class="voice-mark__label">{{ label }}</span>
		<span v-else class="hidden-visually">{{ groupName }}</span>
	</span>
</template>

<script setup lang="ts">
/**
 * A voice in its colour and with its letter (S, A, T, B): the same mark in
 * the score viewer, the practice tools, the ranges and the programme. The
 * letter always goes with the colour, so the colour never carries the
 * information alone.
 */
import type { VoiceGroup } from '../score/voiceGroups.ts'

import { computed } from 'vue'
import { groupLabel } from '../score/voiceGroups.ts'

const props = defineProps<{
	group: VoiceGroup | null
	/** the name of the voice; without it, the group is read out */
	label?: string | null
}>()

const letter = computed(() => props.group ?? '·')

const groupName = computed(() => groupLabel(props.group))
</script>

<style scoped>
.voice-mark {
	--voice: var(--cantoria-voice-none);
	display: inline-flex;
	align-items: center;
	gap: 6px;
	color: var(--color-main-text);
	white-space: nowrap;
}

.voice-mark--s {
	--voice: var(--cantoria-voice-s);
}

.voice-mark--a {
	--voice: var(--cantoria-voice-a);
}

.voice-mark--t {
	--voice: var(--cantoria-voice-t);
}

.voice-mark--b {
	--voice: var(--cantoria-voice-b);
}

.voice-mark__letter {
	display: inline-grid;
	place-items: center;
	inline-size: 1.5em;
	block-size: 1.5em;
	border: 2px solid var(--voice);
	border-radius: 50%;
	color: var(--voice);
	background: color-mix(in srgb, var(--voice) 12%, var(--color-main-background));
	font-size: 0.8em;
	font-weight: 700;
	line-height: 1;
}
</style>
