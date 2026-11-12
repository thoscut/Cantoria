/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Whether the playback sings (vowels of the lyrics) or plays the organ
 * tone: one setting for the score view, the shared pieces and the practice
 * audio, remembered in this browser.
 */
import type { Timbre } from './synth.ts'

import { computed, ref, watch } from 'vue'
import { readStored, writeStored } from '../storage.ts'

const KEY = 'practice.sung'

/** true: the sung voice */
export const sungVoice = ref(readStored(KEY) === '1')

watch(sungVoice, (sung) => writeStored(KEY, sung ? '1' : '0'))

/** The timbre for the synthesizer */
export const timbre = computed<Timbre>(() => sungVoice.value ? 'voice' : 'organ')
