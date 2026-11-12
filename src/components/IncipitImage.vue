<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<span ref="root"
		class="incipit"
		:class="{ 'incipit--drawn': svg !== null, 'incipit--large': large }"
		aria-hidden="true">
		<!-- eslint-disable-next-line vue/no-v-html -- Verovio's SVG of a checked incipit, sanitized -->
		<span v-if="svg !== null" class="incipit__svg" v-html="svg" />
	</span>
</template>

<script setup lang="ts">
/**
 * The first bars of a piece (an incipit), drawn when it scrolls into view.
 * Decoration only: the title next to it carries the information, so the
 * image is hidden from screen readers.
 */
import type { Incipit } from '../scoreFacts.ts'

import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { drawIncipit } from '../design/incipit.ts'

const props = defineProps<{
	incipit: Incipit | null
	/** larger, e.g. in the sidebar or the annual report */
	large?: boolean
}>()

const root = ref<HTMLElement | null>(null)
const svg = ref<string | null>(null)
let observer: IntersectionObserver | null = null
let visible = false

async function draw() {
	if (!visible || props.incipit === null) {
		svg.value = null
		return
	}
	const wanted = props.incipit
	const result = await drawIncipit(wanted)
	if (props.incipit === wanted) {
		svg.value = result
	}
}

onMounted(() => {
	if (typeof IntersectionObserver === 'undefined') {
		visible = true
		draw()
		return
	}
	observer = new IntersectionObserver((entries) => {
		if (entries.some((entry) => entry.isIntersecting) && !visible) {
			visible = true
			observer?.disconnect()
			draw()
		}
	}, { rootMargin: '200px' })
	if (root.value) {
		observer.observe(root.value)
	}
})

onBeforeUnmount(() => observer?.disconnect())

watch(() => props.incipit, draw)
</script>

<style scoped>
.incipit {
	display: inline-block;
	inline-size: 9.5rem;
	block-size: 2.25rem;
	overflow: hidden;
	vertical-align: middle;
	color: var(--color-main-text);
}

.incipit--large {
	inline-size: 16rem;
	block-size: 3.5rem;
}

.incipit__svg {
	display: block;
	block-size: 100%;
	opacity: 0;
	animation: incipit-in var(--cantoria-motion, 0ms) var(--cantoria-ease, ease) forwards;
}

.incipit__svg :deep(svg) {
	block-size: 100%;
	inline-size: auto;
	max-inline-size: 100%;
	fill: currentcolor;
	stroke: currentcolor;
}

@keyframes incipit-in {
	to {
		opacity: 1;
	}
}

@media (prefers-reduced-motion: reduce) {
	.incipit__svg {
		opacity: 1;
		animation: none;
	}
}
</style>
