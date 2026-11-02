<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Cantoria neben einem Chorsystem

Viele Chöre nutzen schon ein Chorsystem: eine Mitglieder-App oder eine
Website, auf der die Sängerinnen und Sänger ihre Noten und Übe-Aufnahmen, die
Termine und die Neuigkeiten finden. Cantoria ersetzt es nicht. Es ist das
Archiv dahinter: was der Chor besitzt, welche Fassung er singt, was die Rechte
erlauben und was zu den Singenden hinaus darf. Diese Seite ist für die
Administration und die Archivverwaltung (Rolle „Bestand“), die beides
betreiben.
[English](../alongside-a-choir-system.md)

## Wer was führt

| Daten | Geführt in | Kommt ins Chorsystem |
|---|---|---|
| Werkverzeichnis: Werke, Fassungen, Exemplare, Signaturen | Cantoria | Signatur, Titel, Komposition und Fassungszeile in `Liste.csv` des Übergabeordners |
| Scans und MusicXML-Dateien | der Archivordner in Nextcloud | Nur die, die Chormitglieder speichern und drucken dürfen, über den Übergabeordner |
| Übe-Aufnahmen | der Archivordner | Nur wo Übe-Audio erlaubt ist, über den Übergabeordner |
| Rechte, Lizenzen, Nachweise, Erhaltungskopien | Cantoria | Der Lizenzhinweis je Datei in `Liste.csv`; nie die Nachweise |
| Hinweise der Chorleitung je Stelle | die Notizen der Links für Chormitglieder | `… Hinweise.txt` je Stück im Übergabeordner |
| Konzerte und ihr Programm | Cantoria | Als Kalenderdateien (`.ics`), wenn du das willst |
| Mitglieder, Stimmen, Anwesenheit, Neuigkeiten | das Chorsystem | Nie in Cantoria |
| Mappenbuch (wer welche Mappe hat) | Cantoria | Nie |
| Protokoll, Aufgaben, Vorschläge, Übertragungsberichte | Cantoria | Nie |

## Was nie ins Chorsystem geht

- Interne Erhaltungskopien und Scans, deren Rechte Chormitgliedern das
  Speichern und Drucken nicht erlauben.
- Aufnahmen von Werken, deren Rechte kein Übe-Audio erlauben.
- Rechtenachweise, Kaufbelege, Lizenzverträge und die Kontaktdaten der
  Rechteinhaber.
- Namen aus dem Mappenbuch, dem Protokoll oder dem Eingang.

Es gelten dieselben Regeln wie für die Links für Chormitglieder: Im
Chorsystem ist nie ein Stück verfügbar, das ein Link verweigern würde.

## Der Weg hinein: Übergabeordner

Schalte das Modul **Übergabe an das Chorsystem** ein
(Administrationshandbuch,
[Abschnitt 17](administrationshandbuch.md#17-übergabe-an-ein-chorsystem)).
Die App hält einen eigenen Ordner mit dem aktuell, was das Chorportal
freigibt. Jemand aus dem Chor lädt seinen Inhalt in das Chorsystem hoch, oder
das Chorsystem liest den geteilten Ordner direkt aus Nextcloud, wenn es das
kann. Die App selbst lädt nichts zum Anbieter des Chorsystems hoch.

Ob ein Chorsystem einen geteilten Nextcloud-Ordner als Ablage nutzen kann, hängt vom
Anbieter ab; frag dort nach, bevor du dich darauf verlässt.

## Der Weg zurück: einen Export vergleichen

Was wirklich im Chorsystem ist, hält Cantoria im Feld „Im Chorportal seit“
fest: Das Chorportal ist in Cantoria die Liste dessen, was der Chor bekommen
darf, das Chorsystem der Ort, wo er es tatsächlich bekommt. Statt es von Hand zu pflegen, exportiere die Liste der Stücke aus dem
Chorsystem und vergleiche sie mit der Liste **Chorportal**
([Benutzerhandbuch](benutzerhandbuch.md#mit-dem-chorsystem-vergleichen)). Die
Abweichungen werden Vorschläge im Eingang; nichts ändert sich, bevor die Rolle
„Bestand“ sie annimmt. Stücke, die im Chorsystem sind, aber dafür nicht
freigegeben, werden aufgelistet, damit sie dort entfernt werden.

## Konzerte

Konzerte bleiben in Cantoria, weil das Programm entscheidet, welche
Fassungen und Rechte gebraucht werden. Für das Chorsystem oder die Kalender
der Singenden lädst du ein Konzert oder alle kommenden Konzerte als
Kalenderdatei (`.ics`) herunter
([Benutzerhandbuch](benutzerhandbuch.md#konzerte-im-eigenen-kalender)). Jedes
Konzert behält seine Kennung; importierst du die Datei erneut, werden die
Einträge aktualisiert.

## Ein Ablauf, der trägt

1. Nach einer Freigabeentscheidung (Rechte bestätigt, Lizenz erfasst,
   geltende Fassung gewählt) zieht der Übergabeordner am nächsten Tag nach,
   oder sofort mit **Jetzt abgleichen**.
2. Lade, was im Übergabeordner neu ist, in das Chorsystem hoch und entferne
   dort, was ihn verlassen hat. `Liste.csv` nennt den Lizenzhinweis, der bei
   jeder Datei stehen soll.
3. Einmal im Monat oder vor einem Konzert exportierst du die Liste des
   Chorsystems und vergleichst sie; entscheide die Vorschläge im Eingang.
