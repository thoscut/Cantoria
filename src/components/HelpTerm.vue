<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Help at the place: a small "?" button next to a term of the archive. It
 * opens a short explanation with a link into the guide (texts in
 * helpTerms.ts). Keyboard: Enter or Space opens it, the focus moves into it,
 * Escape closes it and the focus returns to the button. Not for use inside
 * a dialog: there the explanation belongs into the text of the dialog.
 */
import type { HelpTermKey } from '../helpTerms.ts'

import { mdiHelpCircleOutline } from '@mdi/js'
import { computed, useId } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import GuideLink from './GuideLink.vue'
import { userGuideUrl } from '../docs.ts'
import { helpTerm } from '../helpTerms.ts'
import { t } from '../l10n.ts'

const props = defineProps<{
	term: HelpTermKey
}>()

const help = computed(() => helpTerm(props.term))
const headingId = useId()
</script>

<template>
	<NcPopover popup-role="dialog" class="help-term">
		<template #trigger>
			<!-- the normal size: a target of at least 34 px, also on a touch screen -->
			<NcButton variant="tertiary-no-background"
				class="help-term__button"
				:aria-label="t('cantoria', 'Explain: {term}', { term: help.name })"
				:title="t('cantoria', 'Explain: {term}', { term: help.name })">
				<template #icon>
					<NcIconSvgWrapper :path="mdiHelpCircleOutline" :size="20" />
				</template>
			</NcButton>
		</template>
		<div class="help-term__content" role="dialog" :aria-labelledby="headingId">
			<p :id="headingId" class="help-term__name">
				{{ help.name }}
			</p>
			<p>{{ help.text }}</p>
			<p>
				<GuideLink :href="userGuideUrl(help.section)" :label="t('cantoria', 'More in the guide')" />
			</p>
		</div>
	</NcPopover>
</template>

<style scoped>
.help-term {
	display: inline-flex;
	vertical-align: middle;
}

.help-term__content {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	max-width: min(22em, calc(100vw - 32px));
	padding: calc(2 * var(--default-grid-baseline)) calc(3 * var(--default-grid-baseline));
	overflow-wrap: anywhere;
}

.help-term__content p {
	margin: 0;
}

.help-term__name {
	font-weight: bold;
}
</style>
