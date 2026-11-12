<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<svg class="empty-art"
		viewBox="0 0 96 96"
		aria-hidden="true"
		focusable="false">
		<!-- An open binder with empty staves -->
		<g v-if="kind === 'binder'">
			<path d="M14 22h30c2 0 4 2 4 4v50c0-2-2-4-4-4H14z" />
			<path d="M82 22H52c-2 0-4 2-4 4v50c0-2 2-4 4-4h30z" />
			<path d="M20 34h20M20 40h20M20 46h20M56 34h20M56 40h20M56 46h20" class="empty-art__thin" />
			<circle cx="48" cy="34" r="1.6" />
			<circle cx="48" cy="50" r="1.6" />
			<circle cx="48" cy="66" r="1.6" />
		</g>
		<!-- A music stand waiting for its programme -->
		<g v-else-if="kind === 'stand'">
			<path d="M22 20h52l-4 30H26z" />
			<path d="M30 30h36M30 36h36M30 42h36" class="empty-art__thin" />
			<path d="M24 52h48M48 52v26M48 78l-14 8M48 78l14 8" />
		</g>
		<!-- An empty tray: nothing has come in -->
		<g v-else-if="kind === 'tray'">
			<path d="M16 50l10-26h44l10 26v22H16z" />
			<path d="M16 50h20c0 6 5 10 12 10s12-4 12-10h20" />
			<path d="M40 14v14M48 10v18M56 14v14" class="empty-art__thin" />
		</g>
		<!-- A sheet with a single note: nothing shared yet -->
		<g v-else-if="kind === 'sheet'">
			<path d="M26 12h34l12 12v60H26z" />
			<path d="M60 12v12h12" />
			<path d="M34 40h30M34 46h30M34 52h30M34 58h30M34 64h30" class="empty-art__thin" />
			<ellipse cx="44"
				cy="61"
				rx="4.2"
				ry="3.2"
				class="empty-art__fill" />
			<path d="M48 61V42" />
		</g>
		<!-- A double bar with a fermata: everything is done -->
		<g v-else>
			<path d="M14 40h68M14 48h68M14 56h68M14 64h68M14 72h68" class="empty-art__thin" />
			<path d="M70 40v32" />
			<path d="M78 40v32" class="empty-art__thick" />
			<path d="M30 30a14 14 0 0 1 28 0" />
			<circle cx="44"
				cy="28"
				r="2"
				class="empty-art__fill" />
		</g>
	</svg>
</template>

<script setup lang="ts">
/**
 * Line drawings for empty views (design proposal V8): a binder, a music
 * stand, a tray, a sheet, a final bar. Drawn with the colour of the text
 * around them (currentColor), so they follow every theme, the dark design
 * and the contrast mode. Decoration only: the text beside them says it.
 */
defineProps<{
	kind: 'binder' | 'stand' | 'tray' | 'sheet' | 'done'
}>()
</script>

<style scoped>
.empty-art {
	inline-size: 96px;
	block-size: 96px;
	fill: none;
	stroke: currentcolor;
	stroke-width: 2.4;
	stroke-linecap: round;
	stroke-linejoin: round;
}

.empty-art__thin {
	stroke-width: 1.2;
}

.empty-art__thick {
	stroke-width: 5;
	stroke-linecap: butt;
}

.empty-art__fill {
	fill: currentcolor;
}
</style>
