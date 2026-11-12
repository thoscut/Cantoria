/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import './design/tokens.css'
import { createApp } from 'vue'
import App from './App.vue'
import { installEscapeClose } from './escapeClose.ts'
import { claimDevice } from './offline/device.ts'

import '@nextcloud/dialogs/style.css'

installEscapeClose()

// what another account kept in this browser goes first: pieces, passages and settings (S N6)
claimDevice(null).finally(() => createApp(App).mount('#cantoria'))
