/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The concert folder of the conductor (milestone 0.15): a small bundle of
 * its own, without the catalog, that also works without a network. Before
 * it shows anything, what another account or a signed-out one kept on the
 * device goes (S N6), as on the page "Shared with me".
 */
import './design/tokens.css'
import { createApp } from 'vue'
import { claimDevice } from './offline/device.ts'
import ConcertFolderApp from './sharing/ConcertFolderApp.vue'

claimDevice('folder').then((reload) => {
	if (reload) {
		window.location.reload()
	} else {
		createApp(ConcertFolderApp).mount('#cantoria-concert-folder')
	}
})
