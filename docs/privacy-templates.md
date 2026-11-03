<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Data protection: templates for the choir

**English** | [Deutsch](de/datenschutz-vorlagen.md)

These templates help a choir add the music archive to its data protection
records. They describe what the app stores (as of November 2026, version
0.16.1 with the changes up to milestone 0.20). They are
**no legal advice**: adapt them to your choir, your Nextcloud and your
modules, and if in doubt have your data protection officer check them. Fill
in what is in square brackets `[…]`.

What the app stores in detail is in the
[administration guide, section 10](admin-guide.md#10-data-protection). The
German version, [datenschutz-vorlagen.md](de/datenschutz-vorlagen.md), is the
one to use for German choirs; it follows the same structure.

## 1. Register of processing activities (Art. 30 GDPR)

For all entries:

- **Controller:** [name of the choir, address, represented by …]
- **Data protection officer, if appointed:** [name, contact]
- **Technology:** Nextcloud on [own server / at provider …]; with a provider
  there is a data processing agreement (Art. 28 GDPR).
- **Technical and organisational measures:** access only with a Nextcloud
  account and a role in the music archive; roles separated by task;
  encrypted connections (HTTPS); backups in the backup folder of the account
  that set the archive folder, only while it is not shared; [further
  measures of the Nextcloud, e.g. two-factor login].
- **Backups:** the app's backups hold all data below. Of the weekly backups
  the newest eight stay. Backups stored by hand or downloaded (the download
  is in the audit log) stay until you delete them; add the trash bin and
  the versions of Nextcloud ([period in `config.php`, e.g. 30 days]) and the
  server backups ([period]). After a restore, run the recorded erasures
  again.

| Processing | Purpose | Data subjects | Data | Legal basis | Erasure |
|---|---|---|---|---|---|
| Catalog and audit log | managing the sheet music, evidence of rights and changes | members with a role in the archive | account and display name with every change; who created or decided tasks, proposals, review points, links and concerts and who confirmed rights; downloads of the complete backup | Art. 6 (1) f | after [10] years the app removes account and name from the audit log and keeps the role (setting `audit_retention_years`); until then evidence towards rights holders until claims are time-barred (§ 102 UrhG with § 199 BGB: at most ten years; Art. 17 (3) e GDPR); a deleted account is removed, the display name stays; tasks, proposals, review points, links and concerts keep who created or decided them as evidence |
| Register of persons | protection terms and rights of the works | living composers, lyricists, arrangers, translators, editors (the dead are not covered) | name, role, year of birth (no full date), source, GND/Wikidata | Art. 6 (1) f; no individual information (Art. 14 (5) b) | while a version needs the person; life dates at once on objection |
| Folder book (module) | getting the choir folders back | choir members with a folder | name or account, days of issue and return, notes | Art. 6 (1) b or f | [12] months after the return; 36 months after the issue if the folder never comes back; at once when the account is deleted (open loan: only the account); backups: see above |
| Sharing with choir members (modules) | practising with shared excerpts | choir members | calls counted without who; practice files with the role and the day only, **no account, name or time of day**; in the web server log, as for every call of Nextcloud, IP address, time and address called (with the address of the link) | Art. 6 (1) b; for the log f | practice file entries stay as evidence, without account or name and with the day only; in small voice groups they may point to a person together with other knowledge (pseudonymous); web server log [7 days] |
| Storage on the device (practice settings; with the modules "Practising without a network" and "Concert folder" also pieces) | practising and performing, also without a network | choir members, conductor, archive roles; on a public link also visitors | only in the browser of the device, not with the choir: size of the notes, count-in, passages, practice settings such as tempo and mixer (`localStorage`); pieces kept on a tap, with notes and, if chosen, the scan (Cache Storage); a hash of the signed-in account, so that the pieces of another account or kept before signing out are removed (IndexedDB). Microphone ("Show my pitch", "Record myself") only after a tap and the browser's permission; voice and recording stay in the browser | § 25 (2) no. 2 TDDDG (strictly necessary for the function asked for); remembering practice settings [check: borderline case] | "Delete everything on this device" (pages for choir members, concert folder), switching off "Available without a network" (public link) or clearing the site data in the browser; signing out of Nextcloud usually deletes it too (in some browsers at the next start of a page of Cantoria); pieces no longer shared or allowed at the next start with a network; locked after 30 days without one |
| Public pages (modules) | showing excerpts and the repertoire; protection against abuse | visitors without an account | IP address, time, address called, user agent in the web server log, brute-force protection, rate limit, `nextcloud.log` on errors | Art. 6 (1) f | web server log [7 days]; brute-force protection [48 hours]; no cookies of the app, nothing from third parties; browser storage see the row above |
| Agents (module) | [proposals, reviewing transcriptions] | as catalog and register (role "Proposals (tools)": no folder book) | what the agent reads | Art. 6 (1) f | provider of the model as processor (Art. 28), [third country and safeguard, Art. 44 ff.] |

## 2. Information for choir members (Art. 13 GDPR)

> **Music archive.** [The choir] keeps its sheet music in a Nextcloud with
> the app "Cantoria". When you get a choir folder, we record which folder
> you have since when (name or Nextcloud account, day of issue, notes), so
> that the choir's music comes back (Art. 6 (1) b GDPR). [12] months after
> the return we remove name and notes automatically; if a folder does not
> come back, after 36 months. [Music librarians, conductor, board] can read
> these data.
>
> When we share excerpts with you, we count calls without recording who
> opened them. When you make a practice file, we record that a choir member
> made it on a certain day (piece, measures, voice), not who and not the
> time. We need this as evidence for the rights holders (Art. 6 (1) f
> GDPR). In a small voice group it may still point to you.
>
> Practice settings such as tempo and mixer, and pieces you make "Available
> without a network", are stored only in the browser of your device; they
> do not reach us (§ 25 (2) no. 2 TDDDG). "Delete everything on this
> device" removes them. "Show my pitch" and "Record myself" use the
> microphone only when you allow it; your voice and your recording stay in
> your browser. The pieces are for practising in the choir only: do not pass
> them or recordings with them on, and delete them when you leave the choir.
>
> You can ask for access to your data, their correction or erasure, and
> object to the processing: [contact]. You can complain to a supervisory
> authority, e.g. [competent authority].

## 3. Privacy policy of the public pages

> **Public pages of the music archive.** [The choir] shows excerpts and its
> repertoire for everyone at [address]. Our server processes technically
> necessary data: IP address, time, address called and user agent. They are
> kept in the log of the web server to protect the server against abuse, for
> example against guessing link addresses (Art. 6 (1) f GDPR), and deleted
> after [7 days]; entries of the protection against repeated failed attempts
> after [48 hours]. The pages load nothing from third parties; the app sets
> no cookies of its own [Nextcloud sets technically necessary session
> cookies: check]. Playback happens in your browser. Practice settings such
> as the tempo and, if you choose "Available without a network", the piece
> are stored only in your browser and not sent to us; this is necessary for
> the function you use (§ 25 (2) no. 2 TDDDG). You delete the piece by
> switching "Available without a network" off again, and everything with
> the site data in your browser. [Controller, contact, rights as in
> section 2.]

Before switching on the public modules, check:

- Are the addresses of the **imprint** and the **privacy policy** set under
  *Administration → Theming*? The module settings warn while one is missing.
- Do the retention periods above match your server (log rotation of the web
  server, `nextcloud.log`)?
- Behind a reverse proxy, does Nextcloud see the real addresses
  (`trusted_proxies`)? Otherwise the brute-force protection hits the proxy.
