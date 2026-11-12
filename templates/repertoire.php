<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The public repertoire page (milestone 0.7), rendered on the server without
// scripts. Every value is printed with p() (escaped); the links are https
// links to CPDL and IMSLP, checked by PublicRepertoireService. The texts come
// translated from the controller.

/** @var array{entries: list<array{title: string, composer: string, persons: list<array{role: string, name: string, lifeDates: ?string}>, scoring: list<string>, cpdl: ?string, imslp: ?string}>, texts: array<string, string>} $_ */
$texts = $_['texts'];
?>

<style>
	/* Nextcloud's public layout holds #content to the height of the window
	   and clips it: the page scrolls in its own element */
	.cantoria-repertoire-page {
		flex: 1 1 auto;
		min-width: 0;
		overflow-y: auto;
	}

	/* A page of its own on the background picture of the public layout, as the
	   page of a public link: the text needs the main background for contrast */
	.cantoria-repertoire {
		max-width: 50em;
		margin: calc(4 * var(--default-grid-baseline, 4px)) auto;
		padding: calc(4 * var(--default-grid-baseline, 4px));
		border-radius: var(--border-radius-container-large, var(--border-radius-large, 12px));
		background-color: var(--color-main-background, #fff);
		color: var(--color-main-text, #222);
	}

	.cantoria-repertoire h2 {
		margin-top: 0;
	}

	/* The colour and the logo of the choir's Nextcloud above the list */
	.cantoria-repertoire__band {
		height: 72px;
		margin-bottom: calc(4 * var(--default-grid-baseline, 4px));
		border-radius: var(--border-radius-container, 16px);
		background:
			var(--image-logo, none) center / auto 44px no-repeat,
			var(--color-primary, #00679e);
	}

	@media (forced-colors: active) {
		.cantoria-repertoire__band {
			border: 1px solid CanvasText;
		}
	}

	@media print {
		.cantoria-repertoire__band {
			display: none;
		}
	}

	.cantoria-repertoire__list {
		padding: 0;
		list-style: none;
	}

	.cantoria-repertoire__item {
		padding-block: calc(3 * var(--default-grid-baseline, 4px));
		border-bottom: 1px solid var(--color-border, #ccc);
	}

	.cantoria-repertoire__item h3 {
		margin: 0 0 var(--default-grid-baseline, 4px);
		font-size: 1.15em;
	}

	.cantoria-repertoire__item p {
		margin: 0;
	}

	.cantoria-repertoire__links {
		display: flex;
		flex-wrap: wrap;
		gap: calc(3 * var(--default-grid-baseline, 4px));
		margin-top: var(--default-grid-baseline, 4px);
	}

	.cantoria-repertoire__links a {
		color: var(--color-main-text, #000);
		text-decoration: underline;
	}

	.cantoria-repertoire__links a:focus-visible {
		outline: 2px solid var(--color-main-text, #000);
		outline-offset: 2px;
	}
</style>

<main class="cantoria-repertoire-page">
<div class="cantoria-repertoire">
	<div class="cantoria-repertoire__band" aria-hidden="true"></div>
	<h2><?php p($texts['heading']); ?></h2>
	<p><?php p($texts['intro']); ?></p>
	<?php if ($_['entries'] === []): ?>
		<p><?php p($texts['empty']); ?></p>
	<?php else: ?>
		<ul class="cantoria-repertoire__list">
			<?php foreach ($_['entries'] as $entry): ?>
				<li class="cantoria-repertoire__item">
					<h3><?php p($entry['title']); ?></h3>
					<?php /* The composer as written, unless the register names one (e.g. "Volksweise") */ ?>
					<?php if ($entry['composer'] !== '' && !in_array('composer', array_column($entry['persons'], 'role'), true)): ?>
						<p><?php p($texts['composer']); ?>: <?php p($entry['composer']); ?></p>
					<?php endif; ?>
					<?php foreach ($entry['persons'] as $person): ?>
						<p>
							<?php p($person['role'] === 'lyricist' ? $texts['lyricist'] : $texts['composer']); ?>:
							<?php p($person['name']); ?><?php if ($person['lifeDates'] !== null): ?> (<?php p($person['lifeDates']); ?>)<?php endif; ?>
						</p>
					<?php endforeach; ?>
					<?php if ($entry['scoring'] !== []): ?>
						<p><?php p($texts['scoring']); ?>: <?php p(implode(', ', $entry['scoring'])); ?></p>
					<?php endif; ?>
					<?php if ($entry['cpdl'] !== null || $entry['imslp'] !== null): ?>
						<p class="cantoria-repertoire__links">
							<?php if ($entry['cpdl'] !== null): ?>
								<a href="<?php p($entry['cpdl']); ?>" rel="external noopener noreferrer"><?php p($texts['cpdl']); ?></a>
							<?php endif; ?>
							<?php if ($entry['imslp'] !== null): ?>
								<a href="<?php p($entry['imslp']); ?>" rel="external noopener noreferrer"><?php p($texts['imslp']); ?></a>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
</main>
