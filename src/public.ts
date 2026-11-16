/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The page of a public link (milestone 0.7): one excerpt, viewed and played
 * without signing in. Everything it shows comes with the page; it makes no
 * further requests (the browser fetches the manifest only for the home
 * screen). It also removes what was kept on the device before
 * someone signed out (S N6); the pieces of an account whose session only
 * ended stay.
 */
import './design/tokens.css'
import { createApp } from 'vue'
import { claimDevice } from './offline/device.ts'
import { useOwnManifest } from './offline/manifest.ts'
import PublicApp from './sharing/PublicApp.vue'

// the home screen opens this link, not the login (U25)
useOwnManifest('public')
claimDevice('public').finally(() => createApp(PublicApp).mount('#cantoria-public'))
