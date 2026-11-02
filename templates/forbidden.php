<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// "No access yet" for accounts without a role (Nextcloud's error layout):
// what is wrong, whom to ask, and the section of the guide. The texts are
// translated by the controller.

/** @var array{heading: string, message: string, guideUrl: string, guideLabel: string} $_ */
?>

<div class="guest-box">
	<h2><?php p($_['heading']); ?></h2>
	<p class="hint"><?php p($_['message']); ?></p>
	<p><a href="<?php p($_['guideUrl']); ?>" target="_blank" rel="noopener noreferrer"><?php p($_['guideLabel']); ?></a></p>
</div>
