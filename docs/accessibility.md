<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Accessibility statement

This statement covers the Cantoria app for Nextcloud: its pages inside
Nextcloud, the pages "Shared with me" for choir members, and the public
pages (public links and the repertoire page). [Deutsch](de/barrierefreiheit.md)

## Target and checks

The app aims at **WCAG 2.2 level AA**. It is **partially conformant**: the
parts listed below do not meet it yet. The statement is a self-assessment
by the developers, not a formal audit. These checks are done by hand; the
continuous integration does not run them. How far each part was checked:

| Part | Checked |
|---|---|
| Catalog, rights, concerts, sharing and settings (up to milestone 0.12) | in Nextcloud 33 with axe-core, keyboard-only operation, 320 px width, 200 % zoom and increased text spacing, in the light and the dark theme |
| Practising in the score view: starting tones, metronome, transposing, mixer, lyrics, pitch display, remembered passages, recording yourself (0.13, 0.14) | built and tested in a test setup; not yet checked in a real Nextcloud or with a screen reader |
| Practising without a network and the concert folder (0.15, 0.16) | in a test setup and in the browser's emulation of phones and tablets; not yet on real devices |
| The page for choir members, sharing a programme, the choices for the choir in the settings (0.19) | by hand in a test setup at 360 to 1280 px and with the keyboard; not yet in a real Nextcloud. The Windows contrast mode and print are not yet checked with a record; [tools/screenshots](../tools/screenshots/README.md) can now take both with axe-core, but has not been run against this version |

What the app does:

- Every function can be used with the keyboard, except where the known
  limitations say otherwise; the focus moves into a dialog or the sidebar
  and back to where it came from.
- Buttons, fields and icons have labels for screen readers; lists and
  results that change are announced.
- Contrast is sufficient in the light and the dark theme; information is
  never given by colour alone.
- The pages work with zoom up to 200 % and on narrow screens; animations
  respect "reduce motion".
- Error messages stay until they are closed and say what to do.
- The score viewer describes the excerpt in text, announces the current
  measure while playing, and offers playback and text references as an
  alternative to the notation.
- Statistics show every figure as a number in a table; charts are only an
  addition.
- Printouts carry a header with the view, the filters and the date. A
  printed score shows only the notes, black on white, with the focus voice
  bold instead of coloured.
- In the Windows contrast mode the score, the pitch display and the rights
  badges are meant to take the system colours (see the known limitations).

## Known limitations

- **Scans** of printed scores are images (PDF). They have no text layer, and
  notation cannot be read by a screen reader. Where a MusicXML file exists,
  the score viewer and playback are the accessible alternative.
- **Notation** in the score viewer is a drawing. Its content is available as
  the text description and through playback, not as the notation itself.
- The **pitch display** draws the sung pitch as a line next to the tones
  of the voice. Below it the same stands in words (the tone to sing, the
  sung tone and how far off it is, e.g. "a little high"). The words change
  many times a second and are therefore not announced; there is no summary
  afterwards.
- **Microphone and recording** need the permission of the browser. The
  recording is a sound file that can only be listened to; there is no
  transcript.
- **Without a network**, some states (kept, locked, checked) are shown as
  text and announced, but they have not yet been tried with screen readers
  on phones.
- The **stage view** (in the concert folder and under "Shared with me")
  fills the screen; dialogs and selection lists of the page open on top of
  it and keep the focus. On an iPhone the browser does not offer a full
  screen for the page, so the stage view stays an enlarged view inside the
  browser.
- The **scan** opens in a view of its own on a phone. On a tablet and on a
  larger screen it opens in a frame, where zooming and scrolling depend on
  the browser; open the scan in a tab of its own instead. How the browsers
  on Android show a PDF in a frame has not yet been checked on real
  devices.
- In the **Windows contrast mode** the browser removes shadows and replaces
  background colours. The app marks the selected row and card with a frame
  in the system colour; components of Nextcloud inside the app (buttons,
  switches, dialogs) take the system colours in their own way.
- **Page numbers** in printouts depend on the browser (for example Chrome
  and Edge; Firefox prints without them).
- **Parts of Nextcloud** (navigation, file picker, dialogs, the calendar and
  the Files app) are outside the app. Their accessibility is that of the
  Nextcloud version in use.
- **Translations** other than German and English have not yet been checked
  by native speakers; wording there may be less clear.

## Public pages of a choir

A choir that shows public links or the repertoire page on its website is
responsible for its own website, and may be obliged by law to publish an
accessibility statement for it. The public pages of the app follow the
target above; this statement can serve as a basis for the part they cover.

## Feedback

If something in the app is hard or impossible to use for you, please tell
us in an issue of the repository
([github.com/thoscut/Cantoria/issues](https://github.com/thoscut/Cantoria/issues)),
or ask the administration of your Nextcloud to pass it on. Describe the
page, what you wanted to do and which assistive technology you use. Do not
include personal data of others.

This statement was written in October 2026 and is updated with each
release that changes the interface.
