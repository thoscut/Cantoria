/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The page "Shared with me" for choir members (milestone 0.7): a small
 * bundle of its own, without the catalog. Before it shows anything, what
 * another account or a signed-out one kept on the device goes (S N6); when
 * that was this very page, kept on the device, it loads again from the
 * server, or not at all without a network.
 */
import './design/tokens.css'
import { createApp } from 'vue'
import { claimDevice } from './offline/device.ts'
import MemberApp from './sharing/MemberApp.vue'

claimDevice('member').then((reload) => {
	if (reload) {
		window.location.reload()
	} else {
		createApp(MemberApp).mount('#cantoria-member')
	}
})
