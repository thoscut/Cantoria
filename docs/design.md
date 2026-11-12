<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Design

How Cantoria looks and why. The app lives inside Nextcloud: colours, fonts,
spacing and components come from the Nextcloud theme and `@nextcloud/vue`.
This page describes only what Cantoria adds. For developers; in English.

## Principles

- **Music first.** Where a piece is named, its first bars can stand beside
  it. Notes say more to singers than a shelf mark.
- **Colour never carries information alone** (WCAG 1.4.1). A voice colour
  comes with the letter of its group, a fit with a word and a sign, a
  warning with a text.
- **Everything prints on A4 in black and white.** Print views turn the
  voice colours black and hide decoration.
- **Calm.** One short fade when a view appears, nothing that loops. With
  "reduce motion" the fade is off (`--cantoria-motion: 0ms`).
- **The theme of the choir.** Public pages take the primary colour and the
  logo of the Nextcloud theming. Nothing is loaded from other servers: no
  fonts, no images, no scripts.

## Tokens

`src/design/tokens.css` holds the few values of the app's own. Everything
else is a variable of Nextcloud (`--color-primary-element`,
`--border-radius-large`, `--default-grid-baseline`, …).

| Token | Use |
|---|---|
| `--cantoria-voice-s`, `-a`, `-t`, `-b` | the four voice groups: soprano, alto, tenor, bass |
| `--cantoria-voice-none` | a voice without a group (an instrument, an unnamed voice) |
| `--cantoria-motion`, `--cantoria-ease` | duration and curve of the one fade |
| `--cantoria-shadow` | the soft shadow of floating bars and cards |

### Voice colours

| Group | Light theme | Dark theme |
|---|---|---|
| S | `#b0124f` | `#f792b8` |
| A | `#9a4a00` | `#f5b65a` |
| T | `#046c4e` | `#5fd3a6` |
| B | `#33449e` | `#a5b1f2` |
| none | `#5c5c5c` | `#b4b4b4` |

Each colour has a contrast of at least 4.5:1 against the main background of
its theme. With forced colours (the contrast mode of the system) all become
`CanvasText`, in print black. `groupOf(name)` in `src/score/voiceGroups.ts`
reads the group from the name of a voice ("Sopran II" is S, "Bariton" is
B); `voiceColor(group)` gives the CSS value.

A new place that shows a voice uses `VoiceMark.vue`: a small mark in the
colour of the group with its letter, and the name beside it.

## Building blocks

| Component | What it shows | Where |
|---|---|---|
| `IncipitImage.vue` | the first bars of a piece, drawn by Verovio from the Plaine & Easie Code the server read; drawn only when it scrolls into view | catalog, details of a copy, tasks, pitch sheet |
| `VoiceMark.vue` | letter and colour of a voice group | mixer, ranges, choir profile, pitch sheet |
| `AmbitusChart.vue` | the range of every voice as a bar on a scale of tones, the tessitura stronger, the range of the choir behind | details of a copy, choir profile |
| `FitBadge.vue` | how a piece fits the choir: a sign, a word and the suggested transposition | catalog, details of a copy |
| `ProgrammeArc.vue` | a programme as a band: blocks as wide as the pieces are long, keys, and a sign for every transition | concerts and song lists |
| `NextConcert.vue` | the next concert with the days until then and a ring of the pieces that are ready | start page |
| `EmptyArt.vue` | a line drawing for an empty view (`binder`, `stand`, `tray`, `sheet`, `done`), in `currentColor` | empty lists |

The incipit is data from a file: `src/design/incipit.ts` accepts only the
characters the Plaine & Easie Code needs (`isSafeIncipit`) and passes the
SVG through `sanitizeSvg` before it reaches the page.

## Patterns

- **The transport bar** of the practice views (`ExcerptViewer.vue`) floats
  above the notes: play, the measure that sounds, the tempo, and one chip
  per voice in its colour (two to eight voices). A chip is a toggle button
  with `aria-pressed`; its name is the name of the voice.
- **The focus voice** in the score takes the colour of its group; the other
  voices stay as the theme draws them.
- **A note with a piece** is a slip beside the notes
  (`.member-app__slip`), not a banner.
- **Signs between pieces** in the programme band: `=` the same key, `≈` the
  same signature, `↗` a related key, `♪` a distant key. The legend stands
  below the band, and the list of warnings repeats everything in words.
- **PDFs** (songbook, programme booklet, annual report) are set in the
  browser with pdf-lib in Times, black on white, with page numbers. They
  share `fit` and `wrap` of `src/songbook/build.ts`.
- **Public pages** (`PublicApp.vue`, `templates/repertoire.php`) start with
  `.cantoria-choir-band`: the primary colour and the logo of the theming.

## Checks

- `npx stylelint "src/**/*.{vue,css}"` and `npx eslint src` (with
  `vuejs-accessibility`).
- Contrast: check a new colour in the light and the dark theme against
  `--color-main-background`; 4.5:1 for text, 3:1 for signs.
- Print: open the print preview of every new view in black and white.
- Reduced motion and forced colours: both are media queries in
  `tokens.css`; a new animation uses `--cantoria-motion`.
