<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Erklärung zur Barrierefreiheit

Diese Erklärung gilt für die App Cantoria für Nextcloud: ihre Seiten in
Nextcloud, die Seiten „Mit mir geteilt“ für Chormitglieder und die
öffentlichen Seiten (öffentliche Links und die Repertoireseite).
[English](../accessibility.md)

## Ziel und Prüfungen

Die App zielt auf **WCAG 2.2, Stufe AA**. Sie ist **teilweise konform**: Die
unten genannten Teile erfüllen das Ziel noch nicht. Die Erklärung ist eine
Selbsteinschätzung der Entwickler, keine förmliche Prüfung. Die Prüfungen
geschehen von Hand; die automatische Prüfung (CI) führt sie nicht aus. Wie
weit jeder Teil geprüft ist:

| Teil | Geprüft |
|---|---|
| Werkverzeichnis, Rechte, Konzerte, Teilen und Einstellungen (bis Meilenstein 0.12) | in Nextcloud 33 mit axe-core, reiner Tastaturbedienung, 320 px Breite, 200 % Zoom und vergrößerten Textabständen, im hellen und im dunklen Design |
| Üben in der Notenansicht: Anfangstöne, Metronom, Transponieren, Mischpult, Text, Tonhöhen-Anzeige, gemerkte Stellen, Selbst aufnehmen (0.13, 0.14) | gebaut und getestet im Testaufbau; noch nicht in einem echten Nextcloud und nicht mit einem Screenreader geprüft |
| Üben ohne Netz und die Konzertmappe (0.15, 0.16) | im Testaufbau und in der Nachbildung von Handys und Tablets im Browser; noch nicht auf echten Geräten |
| Die Seite für Chormitglieder, ein Programm teilen, die Wahl für den Chor in den Einstellungen (0.19) | von Hand im Testaufbau bei 360 bis 1280 px und mit der Tastatur; noch nicht in einem echten Nextcloud. Kontrastmodus von Windows und Druck sind noch nicht mit Protokoll geprüft; [tools/screenshots](../../tools/screenshots/README.md) kann beides jetzt mit axe-core aufnehmen, ist für diese Version aber noch nicht gelaufen |

Was die App tut:

- Jede Funktion lässt sich mit der Tastatur bedienen, außer wo die bekannten
  Einschränkungen etwas anderes sagen; der Fokus springt in einen Dialog oder
  die Seitenleiste und zurück dorthin, wo er herkam.
- Schaltflächen, Felder und Symbole sind für Screenreader beschriftet;
  Listen und Ergebnisse, die sich ändern, werden angesagt.
- Der Kontrast reicht im hellen und im dunklen Design; Informationen stehen
  nie nur in der Farbe.
- Die Seiten funktionieren mit Zoom bis 200 % und auf schmalen Bildschirmen;
  Animationen beachten „Bewegung reduzieren“.
- Fehlermeldungen bleiben stehen, bis man sie schließt, und sagen, was zu
  tun ist.
- Die Notenansicht beschreibt den Ausschnitt in Text, sagt beim Abspielen den
  aktuellen Takt an und bietet Abspielen und Textverweise als Alternative zum
  Notenbild.
- Auswertungen zeigen jede Zahl als Zahl in einer Tabelle; Diagramme sind nur
  eine Ergänzung.
- Ausdrucke tragen eine Kopfzeile mit Ansicht, Filtern und Datum. Gedruckte
  Noten zeigen nur das Notenbild, schwarz auf weiß, die hervorgehobene
  Stimme fett statt farbig.
- Im Kontrastmodus von Windows sollen Notenbild, Tonhöhen-Anzeige und die
  Plaketten der Rechte die Systemfarben übernehmen (siehe die bekannten
  Einschränkungen).

## Bekannte Einschränkungen

- **Scans** gedruckter Noten sind Bilder (PDF). Sie haben keine Textebene,
  und Notenschrift kann kein Screenreader vorlesen. Wo es eine
  MusicXML-Datei gibt, sind die Notenansicht und das Abspielen die
  barrierefreie Alternative.
- Das **Notenbild** in der Notenansicht ist eine Zeichnung. Sein Inhalt steht
  als Textbeschreibung und über das Abspielen zur Verfügung, nicht als
  Notenschrift selbst.
- Die **Tonhöhen-Anzeige** zeichnet die gesungene Tonhöhe als Linie neben
  den Tönen der Stimme. Darunter steht dasselbe in Worten (der zu singende
  Ton, der gesungene und wie weit er abweicht, z. B. „etwas zu hoch“). Die
  Worte ändern sich viele Male in der Sekunde und werden deshalb nicht
  angesagt; eine Zusammenfassung danach gibt es nicht.
- **Mikrofon und Aufnahme** brauchen die Erlaubnis des Browsers. Die
  Aufnahme ist eine Tondatei, die sich nur anhören lässt; eine Abschrift
  gibt es nicht.
- **Ohne Netz** stehen manche Zustände (verfügbar, gesperrt, abgeglichen)
  als Text da und werden angesagt, sie sind aber noch nicht mit
  Screenreadern auf Handys ausprobiert.
- Die **Bühnenansicht** (in der Konzertmappe und unter „Mit mir geteilt“)
  füllt den Bildschirm; Dialoge und Auswahllisten der Seite öffnen sich
  darüber und behalten den Fokus. Auf dem iPhone bietet der Browser der
  Seite keinen Vollbildmodus, die Bühnenansicht bleibt dort eine vergrößerte
  Ansicht im Browser.
- Der **Scan** öffnet sich am Handy in einer eigenen Ansicht. Auf dem Tablet
  und auf größeren Bildschirmen öffnet er sich in einem Rahmen, in dem
  Zoomen und Blättern vom Browser abhängen; öffne den Scan dann besser in
  einem eigenen Tab. Wie die Browser unter Android ein PDF im Rahmen zeigen,
  ist noch nicht auf echten Geräten geprüft.
- Im **Kontrastmodus von Windows** entfernt der Browser Schatten und ersetzt
  Hintergrundfarben. Die App markiert die gewählte Zeile und Karte mit einem
  Rahmen in der Systemfarbe; Bausteine von Nextcloud in der App (Knöpfe,
  Schalter, Dialoge) übernehmen die Systemfarben auf ihre eigene Art.
- **Seitenzahlen** in Ausdrucken hängen vom Browser ab (zum Beispiel Chrome
  und Edge; Firefox druckt ohne).
- **Teile von Nextcloud** (Navigation, Dateiauswahl, Dialoge, der Kalender
  und die Dateien-App) liegen außerhalb der App. Ihre Barrierefreiheit ist
  die der genutzten Nextcloud-Version.
- **Übersetzungen** außer Deutsch und Englisch sind noch nicht von
  Muttersprachlern geprüft; die Formulierungen dort können weniger klar sein.

## Öffentliche Seiten eines Chors

Ein Chor, der öffentliche Links oder die Repertoireseite auf seiner Website
zeigt, ist für seine Website selbst verantwortlich und kann gesetzlich
verpflichtet sein, dafür eine Erklärung zur Barrierefreiheit zu
veröffentlichen. Die öffentlichen Seiten der App folgen dem Ziel oben; diese
Erklärung kann als Grundlage für den Teil dienen, den sie abdecken.

## Rückmeldung

Wenn dir etwas in der App schwer fällt oder nicht gelingt, sag es uns bitte
in einem Issue des Repositorys
([github.com/thoscut/choircoresmanager/issues](https://github.com/thoscut/choircoresmanager/issues))
oder bitte die Administration deiner Nextcloud, es weiterzugeben. Beschreibe
die Seite, was du tun wolltest, und welche Hilfstechnik du nutzt. Nenne
keine personenbezogenen Daten anderer.

Diese Erklärung entstand im Oktober 2026 und wird mit jeder Version
aktualisiert, die die Oberfläche ändert.
