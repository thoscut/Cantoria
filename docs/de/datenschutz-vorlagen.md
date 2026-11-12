<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Datenschutz: Vorlagen für den Verein

[English](../privacy-templates.md) | **Deutsch**

Diese Vorlagen helfen dem Verein, die App Cantoria in seine
Datenschutzunterlagen aufzunehmen. Sie beschreiben, was die App speichert
(Stand: November 2026, Version 0.16.1 mit den Änderungen bis Meilenstein
0.20). Sie sind **keine Rechtsberatung**: Passe sie an deinen
Verein, deine Nextcloud und deine Module an, und lass sie im Zweifel von
deiner oder deinem Datenschutzbeauftragten prüfen. Eckige Klammern
`[…]` füllst du selbst aus.

Was die App im Einzelnen speichert, steht im
[Administrationshandbuch, Abschnitt 10](administrationshandbuch.md#10-datenschutz).

## 1. Verzeichnis von Verarbeitungstätigkeiten (Art. 30 DSGVO)

Allgemeine Angaben für alle Einträge:

- **Verantwortlicher:** [Name des Vereins, Anschrift, vertreten durch …]
- **Datenschutzbeauftragte(r), falls benannt:** [Name, Kontakt]
- **Technik:** Nextcloud auf [eigenem Server / bei Anbieter …]; mit einem
  Anbieter besteht ein Vertrag zur Auftragsverarbeitung nach Art. 28 DSGVO.
- **Technische und organisatorische Maßnahmen:** Zugriff nur mit
  Nextcloud-Konto und Rolle im Notenarchiv; Rollen nach Aufgaben getrennt;
  verschlüsselte Verbindung (HTTPS); Sicherungen im Sicherungsordner des
  Kontos, das den Archivordner eingerichtet hat, nur solange er nicht
  geteilt ist; [weitere Maßnahmen der Nextcloud, z. B. Zwei-Faktor-Anmeldung].
- **Sicherungen:** Die Sicherungen der App enthalten alle Daten unten. Von
  den Wochensicherungen bleiben die acht neuesten. Von Hand abgelegte und
  heruntergeladene Sicherungen (das Herunterladen steht im Protokoll)
  bleiben, bis ihr sie löscht; dazu kommen Papierkorb und Versionen der
  Nextcloud ([Frist laut `config.php`, z. B. 30 Tage]) und die Sicherungen
  des Servers ([Frist]). Nach einer Wiederherstellung führt ihr die
  vermerkten Löschungen erneut aus.

**1.1 Notenarchiv: Werkverzeichnis und Protokoll**

| Feld | Eintrag |
|---|---|
| Zweck | Verwaltung des Notenbestands des Vereins, Nachweis der Rechte und Lizenzen, Nachvollziehbarkeit von Änderungen |
| Betroffene | Mitglieder mit einer Rolle im Notenarchiv (Notenwart, Chorleitung, Vorstand …) |
| Daten | Konto und Anzeigename bei jeder Änderung, Zeitpunkt, was geändert wurde; wer Aufgaben, Vorschläge, Prüfpunkte, Links und Konzerte angelegt oder entschieden und wer Rechte bestätigt hat; das Herunterladen der vollständigen Sicherung |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. f DSGVO (Nachweis der Rechte und Änderungen); für Funktionsträger auch die Satzung |
| Empfänger | die Rollen des Notenarchivs; [Anbieter der Nextcloud als Auftragsverarbeiter] |
| Drittland | [nein / ja: …] |
| Löschfrist | Nach [10] Jahren entfernt die App Konto und Namen aus dem Protokoll und behält die Rolle (Einstellung `audit_retention_years`). Bis dahin dient es als Nachweis gegenüber Rechteinhabern bis zur Verjährung (§ 102 UrhG i. V. m. § 199 BGB: höchstens zehn Jahre; Art. 17 Abs. 3 lit. e DSGVO). Wird ein Konto gelöscht, entfernt die App das Konto aus dem Protokoll; der Anzeigename bleibt. Aufgaben, Vorschläge, Prüfpunkte, Links und Konzerte behalten als Nachweis, wer sie angelegt oder entschieden hat. |

**1.2 Personenregister (Urheberinnen und Urheber)**

| Feld | Eintrag |
|---|---|
| Zweck | Einschätzung der Schutzfrist und der Rechte an den Werken im Bestand |
| Betroffene | lebende Personen aus Komposition, Text, Bearbeitung, Übersetzung und Herausgabe (Verstorbene fallen nicht unter die DSGVO) |
| Daten | Name, Rolle an einer Fassung, Geburtsjahr (kein volles Geburtsdatum), Quelle, Identifikatoren (GND, Wikidata) |
| Herkunft | öffentliche Quellen: Notendrucke, Verlagsverzeichnisse, Normdaten |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. f DSGVO; keine Einzelinformation, weil unverhältnismäßig (Art. 14 Abs. 5 lit. b DSGVO) |
| Empfänger | die Rollen des Notenarchivs; mit dem Modul „Öffentliche Repertoireseite“ die Öffentlichkeit (nur Komposition und Text bestätigt gemeinfreier Werke) |
| Löschfrist | solange eine Fassung im Bestand die Person für die Rechte braucht; Lebensdaten auf Widerspruch sofort |

**1.3 Mappenbuch (nur mit dem Modul „Mappenbuch“)**

| Feld | Eintrag |
|---|---|
| Zweck | Nachweis, welche Chormappe bei wem ist, damit sie zurückkommt |
| Betroffene | Chormitglieder, die eine Mappe haben |
| Daten | Name oder Nextcloud-Konto, Tag der Ausgabe und Rücknahme, Notizen zur Ausgabe |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. b DSGVO (Mitgliedschaft) oder lit. f (Eigentum des Vereins) |
| Empfänger | Rollen „Bestand“, „Betrieb“, „Chorleitung“ und „Lesen“ |
| Löschfrist | [12] Monate nach der Rücknahme (einstellbar); eine Mappe, die nicht zurückkommt, 36 Monate nach der Ausgabe; bei gelöschtem Konto sofort (offene Ausgabe: nur das Konto). Sicherungen: siehe oben. |

**1.4 Teilen mit Chormitgliedern (nur mit den Modulen „Links für Chormitglieder“ und „Übe-Audio für Chormitglieder“)**

| Feld | Eintrag |
|---|---|
| Zweck | Üben mit Ausschnitten, die Notenwart oder Chorleitung teilen |
| Betroffene | Chormitglieder mit Nextcloud-Konto |
| Daten | Aufrufe eines Links werden gezählt, ohne festzuhalten, wer; für jede Übe-Datei: Rolle „Chormitglied“, Fassung, Takte, Stimme, Format, Tag (ohne Uhrzeit), **ohne Konto und Namen**; im Log des Webservers wie bei jedem Aufruf der Nextcloud IP-Adresse, Zeitpunkt und aufgerufene Adresse (mit der Adresse des Links) |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. b DSGVO (Mitgliedschaft); für das Protokoll der Übe-Dateien lit. f (Nachweis gegenüber Rechteinhabern) |
| Empfänger | die Rollen des Notenarchivs mit Zugriff auf das Protokoll; Betreiber des Servers [Anbieter] für das Log des Webservers |
| Löschfrist | Die Einträge der Übe-Dateien bleiben als Nachweis; sie enthalten weder Konto noch Namen und nur den Tag. In kleinen Stimmgruppen kann man sie mit anderem Wissen unter Umständen einer Person zuordnen (pseudonym). Log des Webservers: [z. B. 7 Tage]. |

**1.5 Speicher auf dem Gerät (Übe-Einstellungen, Neu-Markierung, Hash des Kontos; mit den Modulen „Üben ohne Netz“ und „Konzertmappe“ auch Stücke)**

| Feld | Eintrag |
|---|---|
| Zweck | Üben und Vortragen, auch ohne Netz: Einstellungen für den nächsten Besuch, auf Wunsch Stücke auf dem eigenen Gerät |
| Betroffene | Chormitglieder, Chorleitung, Rollen des Notenarchivs; auf einem öffentlichen Link auch Besucherinnen und Besucher |
| Daten | nur im Browser des Geräts, nicht beim Verein. **Beim Benutzen:** Größe der Noten, Einzähler, Passagen, Übe-Einstellungen wie Tempo und Mischpult, auf „Mit mir geteilt“ die zuletzt gewählte Stimme (`localStorage`). **Beim Tippen auf „Ohne Netz verfügbar“:** Stücke mit Noten und auf Wunsch Scan (Cache Storage). **Ohne Zutun, bei jedem Aufruf:** ein Hash des angemeldeten Kontos auf jeder Seite der App, auch im Archiv und ohne das Modul „Üben ohne Netz“, damit Stücke und Übe-Einstellungen eines anderen Kontos oder von vor dem Abmelden entfernt werden (IndexedDB); auf „Mit mir geteilt“ Kennungen der gesehenen Links und die Zeit des Besuchs, je Konto als Hash, für die Markierung „Neu“ (`localStorage`). **Beim Öffnen einer Seite mit dem Modul „Üben ohne Netz“** (auf einem öffentlichen Link, sobald das Stück behalten werden darf) meldet sich ein Service Worker im Browser an; Inhalte legt er erst ab, wenn jemand etwas gespeichert hat. Mikrofon („Meine Tonhöhe zeigen“, „Selbst aufnehmen“) nur nach Tippen und Erlaubnis des Browsers; Stimme und Aufnahme bleiben im Browser. |
| Rechtsgrundlage | für gespeicherte Stücke und den Hash des Kontos als ihr Schutz § 25 Abs. 2 Nr. 2 TDDDG (unbedingt erforderlich für die gewünschte Funktion) [prüfen]; für Übe-Einstellungen, die Neu-Markierung, den Hash auf Seiten ohne gespeicherte Stücke und den Service Worker vor dem ersten Speichern [prüfen: Grenzfall; nach strenger Lesart Einwilligung] |
| Empfänger | niemand; nichts davon wird an den Server übertragen |
| Löschfrist | „Alles auf diesem Gerät löschen“ (Seiten für Chormitglieder, Konzertmappe), „Ohne Netz verfügbar“ ausschalten (öffentlicher Link) oder die Websitedaten im Browser löschen; das Abmelden bei Nextcloud löscht es meist auch (in manchen Browsern beim nächsten Start einer Seite von Cantoria); Stücke, die nicht mehr geteilt sind oder nicht mehr behalten werden dürfen, beim nächsten Start mit Netz; nach 30 Tagen ohne Netz gesperrt |

**1.6 Öffentliche Seiten (nur mit den Modulen „Öffentliche Links“ und „Öffentliche Repertoireseite“)**

| Feld | Eintrag |
|---|---|
| Zweck | Ausschnitte und das Repertoire für alle zeigen; Schutz gegen Missbrauch |
| Betroffene | Besucherinnen und Besucher ohne Konto |
| Daten | IP-Adresse, Zeitpunkt, aufgerufene Adresse (mit der Adresse des Links), Browserkennung: im Log des Webservers, im Schutz gegen Brute-Force-Angriffe und in der Begrenzung der Aufrufe der Nextcloud, bei Fehlern in `nextcloud.log` |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. f DSGVO (Sicherheit des Servers) |
| Empfänger | Betreiber des Servers [Anbieter] |
| Löschfrist | Log des Webservers: [z. B. 7 Tage]; Brute-Force-Schutz: [Nextcloud: 48 Stunden]; `nextcloud.log`: [Rotation …]. Die App setzt keine eigenen Cookies und lädt nichts von Dritten; Speicher im Browser siehe 1.5. |

**1.7 Agenten (nur mit dem Modul „Schnittstelle für Agenten“)**

| Feld | Eintrag |
|---|---|
| Zweck | [z. B. Vorschläge für das Werkverzeichnis, Prüfung von Übertragungen] |
| Betroffene | wie 1.1 und 1.2 (mit der Rolle „Vorschläge (Werkzeuge)“ kein Mappenbuch) |
| Daten | was der Agent liest: Werkverzeichnis, Personenregister, Protokoll ohne Mappenbuch |
| Empfänger | Anbieter des Sprachmodells [Name] als Auftragsverarbeiter (Vertrag nach Art. 28 DSGVO) |
| Drittland | [nein / ja: Land, Garantie nach Art. 44 ff. DSGVO, z. B. Angemessenheitsbeschluss oder Standardvertragsklauseln] |
| Löschfrist | beim Anbieter: [laut Vertrag]; im Notenarchiv wie 1.1 |

## 2. Information der Chormitglieder (Art. 13 DSGVO)

Baustein für die Datenschutzhinweise des Vereins, zum Beispiel als Abschnitt
„Notenarchiv“:

> **Notenarchiv.** Der [Verein] verwaltet seinen Notenbestand in einer
> Nextcloud mit der App „Cantoria“. Wenn du eine Chormappe bekommst,
> halten wir fest, welche Mappe du seit wann hast (Name oder
> Nextcloud-Konto, Tag der Ausgabe, Notizen zur Ausgabe). Das dient dazu,
> dass die Noten des Vereins zurückkommen (Art. 6 Abs. 1 lit. b DSGVO).
> [12] Monate nach der Rückgabe entfernen wir Name und Notizen automatisch;
> kommt eine Mappe nicht zurück, nach 36 Monaten. Lesen können diese Angaben
> [Notenwart, Chorleitung, Vorstand].
>
> Wenn wir Ausschnitte mit dir teilen, zählen wir Aufrufe, ohne
> festzuhalten, wer sie geöffnet hat. Machst du eine Übe-Datei, halten wir
> fest, dass ein Chormitglied sie an einem Tag gemacht hat (Stück, Takte,
> Stimme), nicht wer und nicht die Uhrzeit. Das brauchen wir als Nachweis
> gegenüber den Rechteinhabern (Art. 6 Abs. 1 lit. f DSGVO). In einer
> kleinen Stimmgruppe kann man daraus unter Umständen auf dich schließen.
>
> Übe-Einstellungen wie Tempo und Mischpult und Stücke, die du „Ohne Netz
> verfügbar“ machst, speichert die App nur im Browser deines Geräts; sie
> gehen nicht an uns. Außerdem merkt sich der Browser ohne dein Zutun einen
> Hash deines Kontos, damit auf einem geteilten Gerät niemand deine Stücke
> sieht, auf „Mit mir geteilt“, welche Links du schon gesehen hast (für die
> Markierung „Neu“), und mit dem Modul „Üben ohne Netz“ einen Service
> Worker, der Stücke erst nach deinem Tippen ablegt. Rechtsgrundlage:
> [§ 25 Abs. 2 Nr. 2 TDDDG, soweit das für die Funktion unbedingt
> erforderlich ist; für Einstellungen und die Markierung „Neu“: prüfen].
> Mit „Alles auf diesem Gerät löschen“ entfernst du sie. „Meine Tonhöhe zeigen“ und „Selbst aufnehmen“
> nutzen das Mikrofon erst, wenn du es erlaubst; deine Stimme und deine
> Aufnahme bleiben in deinem Browser. Die Stücke sind nur zum Üben im Chor:
> Gib sie und Aufnahmen damit nicht weiter, und lösche sie, wenn du den Chor
> verlässt.
>
> Du kannst Auskunft über deine Daten verlangen, ihre Berichtigung oder
> Löschung und der Verarbeitung widersprechen. Wende dich an [Kontakt].
> Du kannst dich bei einer Aufsichtsbehörde beschweren, zum Beispiel bei
> [zuständige Landesdatenschutzbehörde].

## 3. Datenschutzerklärung der öffentlichen Seiten

Baustein für die Datenschutzerklärung, die unter *Verwaltung → Design* als
Adresse eingetragen ist und in der Fußzeile der öffentlichen Seiten
erscheint:

> **Öffentliche Seiten des Notenarchivs.** Der [Verein] zeigt auf
> [Adresse] Notenausschnitte und sein Repertoire für alle. Beim Aufruf
> verarbeitet unser Server technisch notwendige Daten: IP-Adresse,
> Zeitpunkt, aufgerufene Adresse und Browserkennung. Sie stehen im Log des
> Webservers und dienen dazu, den Server vor Missbrauch zu schützen, zum
> Beispiel vor dem Durchprobieren von Link-Adressen (Art. 6 Abs. 1 lit. f
> DSGVO). Wir löschen sie nach [7 Tagen]; Einträge des Schutzes gegen
> wiederholte Fehlversuche nach [48 Stunden]. Die Seiten laden keine
> Inhalte von Dritten; die App setzt keine eigenen Cookies [die Nextcloud
> setzt technisch notwendige Sitzungs-Cookies: prüfen]. Das Abspielen
> geschieht in deinem Browser. Übe-Einstellungen wie das Tempo und, wenn du
> „Ohne Netz verfügbar“ wählst, das Stück liegen nur im Speicher deines
> Browsers und werden nicht an uns übertragen. Darf ein Stück behalten
> werden, meldet die Seite beim Öffnen einen Service Worker im Browser an,
> der es erst nach deinem Tippen ablegt. Rechtsgrundlage: [§ 25 Abs. 2
> Nr. 2 TDDDG, soweit das für die Funktion, die du nutzt, unbedingt
> erforderlich ist; für Übe-Einstellungen und den Service Worker vor dem
> ersten Speichern: prüfen]. Du löschst das Stück, indem du „Ohne Netz
> verfügbar“ wieder ausschaltest, und alles mit den Websitedaten in deinem
> Browser.
> [Verantwortlicher, Kontakt, Rechte wie in Abschnitt 2.]

Vor dem Einschalten der öffentlichen Module prüfst du:

- Ist unter *Verwaltung → Design* eine Adresse für das **Impressum** und für
  die **Datenschutzerklärung** eingetragen? Die Moduleinstellungen warnen,
  solange eine fehlt.
- Stimmen die Speicherdauern oben mit deinem Server überein (Log-Rotation
  des Webservers, `nextcloud.log`)?
- Sieht die Nextcloud hinter einem Reverse Proxy die echten Adressen
  (`trusted_proxies`)? Sonst trifft der Schutz gegen Brute-Force-Angriffe
  den Proxy.
