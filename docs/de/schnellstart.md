# Schnellstart

[English](../user-guide.md) | **Deutsch**

Diese Kurzanleitung zeigt der Archivverwaltung und der Chorleitung die ersten
Schritte in Cantoria. Ausführlich beschreiben es das
[Benutzerhandbuch](benutzerhandbuch.md) und für die Einrichtung das
[Administrationshandbuch](administrationshandbuch.md).

> [!NOTE]
> Du siehst Cantoria nur, wenn dein Konto in einer Gruppe mit einer
> Rolle ist. Die Rollen ordnet die Administration den Gruppen zu. Die
> deutsche Oberfläche gibt es in der Du- und in der Sie-Form; du wählst sie
> mit der Sprache in deinen persönlichen Nextcloud-Einstellungen.

> [!TIP]
> Die App öffnet Dateien nur mit den Rechten deines eigenen Kontos. Meldet
> sie „Die Datei liegt in einem Ordner, der nicht mit deinem Konto geteilt
> ist“, bitte die Archivverwaltung, den Archivordner mit dir oder deiner
> Gruppe zu teilen; Leserechte genügen.

**Inhalt**

- [Begriffe](#begriffe)
- [Zurechtfinden](#zurechtfinden)
- [Für die Archivverwaltung](#für-die-archivverwaltung)
- [Für die Chorleitung](#für-die-chorleitung)
- [Häufige Probleme](#häufige-probleme)
- [Mehr](#mehr)

## Begriffe

Neben diesen Begriffen zeigt die App ein kleines **?**. Es öffnet eine kurze
Erklärung mit einem Link in das
[Benutzerhandbuch](benutzerhandbuch.md#begriffe); Escape schließt sie wieder.

### Werk, Fassung und Exemplar

Das **Werk** ist das Stück selbst mit Titel und Komposition. Eine **Fassung**
ist eine Ausgabe oder Bearbeitung des Werks; die Rechte gehören zur Fassung,
nicht zum Werk. Ein **Exemplar** ist ein gedrucktes oder digitales Exemplar
einer Fassung mit eigener Signatur.

### Signatur

Die Signatur besteht aus der Nummer der Archivmappe und der Nummer in der
Mappe, zum Beispiel `5-12`. Die Dateinamen im Archivordner beginnen mit der
Signatur; daran ordnet der Dateiabgleich jede Datei ihrem Exemplar zu.

### Rechtestatus und Bestätigung

Der Rechtestatus einer Fassung ist „gemeinfrei“, „geschützt“ oder „offen“.
Die App schlägt einen Status aus den Daten vor (etwa aus den Lebensdaten),
die Archivverwaltung bestätigt ihn mit Begründung und Beleg. Nur ein
bestätigter Status zählt für Freigaben; die App ist keine Rechtsberatung.

### Freigabe

Eine Freigabe sagt, ob eine Fassung auf einem bestimmten Weg weitergegeben
werden darf: im Chorportal, an der Druckstation oder als Veröffentlichung.
Sie folgt aus den bestätigten Rechten oder einer gültigen Lizenz und gilt nie
für interne Erhaltungskopien.

### Geltende Fassung

Die geltende Fassung ist die Fassung, die der Chor singt. Je Werk gibt es
höchstens eine, und nur die Chorleitung legt sie fest. Über die Rechte sagt
sie nichts aus.

### Interne Erhaltungskopie

Eine interne Erhaltungskopie wird nur für das Archiv aufbewahrt, zum Beispiel
der Scan eines geschützten Drucks. Sie wird nie weitergegeben, abgespielt
oder exportiert; nur Archivverwaltung und Chorleitung sehen sie.

## Zurechtfinden

### Startseite „Fällig“

![Die Startseite „Fällig“ in der deutschen Oberfläche: Karten mit der Zahl der anstehenden Dinge, zum Beispiel eine offene Aufgabe, Programmpunkte mit unklarer Signatur und Vorschläge im Eingang, darunter die Konzertfristen mit dem Adventskonzert 2026.](../screenshots/de/start-page.png)

Cantoria öffnet mit **Fällig**: Karten zeigen, was für deine Rollen
ansteht, zum Beispiel offene Aufgaben, Vorschläge im Eingang oder Fassungen,
deren Gemeinfreiheit zu bestätigen ist. Ein Klick auf eine Karte öffnet die
passende Liste. Darunter stehen die Konzertfristen und die Aufgaben für deine
Rollen, sortiert nach Archivmappe.

### Werkverzeichnis

![Das Werkverzeichnis in der deutschen Oberfläche: die Exemplare des Demo-Archivs mit Signatur, Titel, Komposition, Rechte-Kennzeichen und Repertoire, links das Suchfeld und die Filter.](../screenshots/de/catalog.png)

1. Öffne **Werkverzeichnis**.
2. Tippe in **Signatur, Titel oder Komposition** eine Signatur wie `2-1`
   oder ein Wort aus Titel oder Komposition.
3. Grenze die Liste mit **Rechtestatus** oder **Repertoire** ein. Die übrigen
   Filter, zum Beispiel **Archivmappe**, **Mit MusicXML** oder **Keine
   Fassung gilt für den Chor**, öffnest du mit **Weitere Filter**. Die App
   merkt sich, ob sie offen sind; sind dort Filter aktiv, zeigt die
   Schaltfläche, wie viele.
4. Klicke auf eine Zeile. Die Seitenleiste zeigt die Reiter **Details**,
   **Rechte**, **Fragen**, **Dateien** (jede Datei mit ihrem Ordner und
   einem Symbol für die Dateiart) und **Verlauf** (das Protokoll aller
   Änderungen).

Das Rechte-Kennzeichen zeigt immer Text und Symbol: „gemeinfrei“,
„gemeinfrei (Empfehlung, unbestätigt)“, „geschützt“, „offen“ oder „Interne
Erhaltungskopie – keine Weitergabe“. Ein **?** neben einem Begriff erklärt
ihn kurz (siehe [Begriffe](#begriffe)).

## Für die Archivverwaltung

### Rechte bestätigen

![Der Dialog „Rechtestatus bestätigen“: die Wahl zwischen gemeinfrei und geschützt sowie die Pflichtfelder Begründung und Beleg.](../screenshots/de/rights-confirm.png)

Ein importierter Rechtestatus bleibt eine Empfehlung, bis du ihn bestätigst.

1. Öffne das Exemplar, Reiter **Rechte**, und wähle **Bestätigen**.
2. Wähle den bestätigten Status: gemeinfrei oder geschützt.
3. Trage die **Begründung** und den **Beleg** ein, zum Beispiel „Alle
   Beteiligten sind vor mehr als 70 Jahren gestorben“ und „Lebensdaten aus
   dem Personenregister“. Beides ist Pflicht.
4. Vor dem Speichern sagt der Dialog, was danach möglich wird, je nach
   eingeschalteten Modulen: Chorportal und Druckstation, Übe-Dateien, Teilen
   mit Chormitgliedern und, sobald die Fassung zur Veröffentlichung
   freigegeben ist, öffentliche Links. Bei „geschützt“ sagt er, dass
   Freigaben dann eine gültige Lizenz brauchen.
5. Wähle **Bestätigen**. Dein Name und das Datum werden festgehalten.

Wenn du unsicher bist: **Im Zweifel: auf offen setzen**.

> [!NOTE]
> Die Rechte-Hilfen bilden deutsches Urheberrecht ab. Sie unterstützen deine
> Entscheidung, sind aber keine Rechtsberatung.

### Rückfragen übernehmen

1. Die Karte **Beantwortete Aufgaben zum Übernehmen** führt zu den Antworten.
2. Berichtige, wenn nötig, das Werkverzeichnis oder die Datei.
3. Wähle bei der Aufgabe **Schließen** und dann **Übernommen** oder
   **Verworfen**, danach **Aufgabe abschließen**.

Sind **Aufgaben** oder **Eingang** leer, zeigen sie dir den nächsten Schritt:
Aufgaben legst du in der Seitenleiste eines Exemplars an (Reiter
**Fragen**); der Eingang erklärt, woher Vorschläge kommen, und führt zur
Startseite.

### Neuzugang erfassen

1. Öffne **Neuzugang** und dort **Neues Stück erfassen**.
2. Wähle die **Archivmappe**. Die App schlägt die nächste freie Nummer vor;
   Nummern werden nie wieder vergeben.
3. Trage Titel, Komposition, Titelquelle, Zugangsdatum und Herkunft ein.
4. Wähle **Zugang erfassen**. Das Etikett kommt auf den Etikettenbogen
   darunter.

### Sicherung

Die App legt jede Woche eine vollständige Sicherung in den Archivordner. Vor
größeren Änderungen wählst du unten in der Navigation **Archivverwaltung** und
dort **Vollständige Sicherung jetzt ablegen**.

## Für die Chorleitung

### Geltende Fassung festlegen

![Der Dialog „Als geltend für den Chor festlegen“ mit dem Feld „Begründung (optional)“ und der Schaltfläche „Als geltend festlegen“.](../screenshots/de/valid-version.png)

Nur die Chorleitung legt fest, welche Fassung der Chor singt. Je Werk gilt
höchstens eine Fassung. Die Rechte ändern sich dadurch nicht.

**Mehrere Werke auf einmal:** Öffne in der Navigation **Geltende Fassungen
festlegen** (nur für die Chorleitung und nur, solange das Modul „Geltende
Fassung“ eingeschaltet ist). Dort stehen die Werke mit mehreren Fassungen,
von denen noch keine für den Chor gilt, jede Fassung mit ihren Exemplaren
(Signaturen), deren Art und dem Rechtestatus. Mit dem Schalter für das
aktuelle Repertoire siehst du nur die Stücke, die ihr gerade singt. Trag auf
Wunsch eine Begründung ein und wähle bei der passenden Fassung **Als geltend
festlegen**, direkt in der Liste.

**Ein einzelnes Werk:**

1. Öffne ein Exemplar dieser Fassung, Reiter **Rechte**, Abschnitt **Gilt für
   den Chor**.
2. Wähle **Als geltend für den Chor festlegen**, auf Wunsch mit Begründung,
   und dann **Als geltend festlegen**.

Die Karte **Aktuelles Repertoire: keine geltende Fassung gewählt** auf der
Startseite öffnet das Werkverzeichnis mit dem passenden Filter.

### Noten ansehen und anhören

![Die Notenansicht von 1-1 „Der Mond ist aufgegangen“ in der deutschen Oberfläche: die hervorgehobene Stimme Alt in Farbe, die Schaltflächen zum Teilen und für eine neue Aufgabe und die Noten der ersten Takte.](../screenshots/de/score-voice.png)

1. Wähle in der Seitenleiste **Noten**.
2. Trage **Von Takt** und **Bis Takt** ein und wähle **Anzeigen**. Es gelten
   die gedruckten Taktnummern; der Auftakt ist Takt 0.
3. Wähle die **Hervorgehobene Stimme**. Sie erscheint farbig mit kräftigeren
   Notenköpfen, die übrigen Stimmen heller.
4. Wähle unter **Stimmen beim Abspielen** „Alle gleich“, „Stimme betont“ oder
   „Nur diese Stimme“ und dann **Abspielen**. Die Leertaste spielt und hält an.
   **Tempo, Lautstärke und Üben** öffnet die Regler, die Schalter für
   Wiederholungen und Mitscrollen und die Werkzeuge zum Üben (Anfangstöne,
   Metronom, Tonhöhe, Mischpult).
5. Unter **Diese Stelle** kopierst du einen Textverweis, erstellst einen
   internen Link oder legst eine Rückfrage zu den gezeigten Takten an.

Abspielen gibt es für gemeinfreie Fassungen und für Fassungen mit einer
Lizenz für Übe-Audio, nie für interne Erhaltungskopien.

#### So übst du eine Stelle in Schleife

1. Trage die Stelle in **Von Takt** und **Bis Takt** ein.
2. Schalte daneben **Schleife** ein. Die Stelle wird gezeigt und beim
   Abspielen immer wieder gespielt, bis du **Pause** oder **Stopp** wählst.
3. Unter **Tempo, Lautstärke und Üben** wählst du einen **Einzähler** von 1 oder
   2 Takten. Die Wahl merkt sich dein Browser.

Ein Klick auf eine Note startet die Wiedergabe dort. Mit der Tastatur gehst
du in den Noten mit den Pfeiltasten links und rechts zum vorigen oder
nächsten Takt und spielst mit der Leertaste ab.

### Übe-Dateien erstellen

![Der Dialog „Übe-Dateien“: Takte und Tempo aus der Notenansicht, die Wahl zwischen einer Datei und einem ZIP-Archiv mit jeder Stimme und einem Tutti, die Stimme und das Format WAV oder MIDI.](../screenshots/de/practice-audio.png)

1. Stelle in der Notenansicht Takte, Tempo und Wiederholungen ein (Tempo und
   Wiederholungen unter **Tempo, Lautstärke und Üben**).
2. Wähle **Übe-Dateien …**.
3. Wähle **Eine Datei** oder **Jede Stimme und ein Tutti (ZIP)** und das
   Format **WAV (Audio)** oder **MIDI (eine Spur je Stimme)**.
4. Wähle **Datei erstellen** oder **ZIP-Archiv erstellen**. Der Browser legt
   das Ergebnis in deine Downloads.

> [!IMPORTANT]
> Übe-Dateien gibt es nur für bestätigt gemeinfreie Fassungen und für
> Fassungen mit einer gültigen Lizenz für Übe-Audio, nie für interne
> Erhaltungskopien. Jede Ausgabe steht im Protokoll der Fassung (Reiter
> **Verlauf**). Ins Chorsystem lädst du die Dateien selbst hoch; die App gibt
> nichts an Chormitglieder weiter.

### Rückfrage beantworten

![Der Dialog „Aufgabe beantworten“: eine Rückfrage an die Chorleitung zu Takt 3 im Alt und das Feld „Antwort (Pflicht)“ mit einer Antwort.](../screenshots/de/question-answer.png)

1. Öffne die Rückfrage über die Startseite, über **Aufgaben** oder über die
   Benachrichtigung.
2. **In den Noten zeigen** zeigt dir die Stelle.
3. Wähle **Antworten**, schreib deine Antwort und wähle **Antwort speichern**.

Die Archivverwaltung übernimmt die Antwort danach ins Werkverzeichnis.

### Konzert planen

![Ein Konzertprogramm in der deutschen Oberfläche: die Stücke in ihrer Reihenfolge mit Dauer und Warnungen, zum Beispiel zu einer Erhaltungskopie oder zu einer fehlenden geltenden Fassung.](../screenshots/de/concert.png)

1. **Konzerte** → **Neues Konzert**: Titel, Datum, auf Wunsch Beginn und Ende,
   Ort. **Speichern**.
2. **Stück hinzufügen (Suche nach Signatur oder Titel)** → **Hinzufügen**.
   Bring die Stücke in die richtige Reihenfolge und trage die Dauer ein.
3. Prüfe die Warnungen: Erhaltungskopie, ungeklärte Rechte, keine oder eine
   andere geltende Fassung, geschützt ohne Lizenz.
4. **Programm festlegen**, wenn es steht.
5. Nach dem Konzert: **Gesungen bestätigen** und die Stücke anhaken, die
   nicht gesungen wurden. **Liederfolge (CSV)** liefert die Liste für
   Verwertungsgesellschaft und Programmheft.

Die App erinnert dich 10, 8 und 6 Wochen vor dem Konzert an die Fristen.

### Kalender, Dashboard und Dateien

- **In meinen Kalender eintragen** trägt ein Konzert in einen deiner
  Nextcloud-Kalender ein, **Kalenderdatei (.ics)** lädt es für jedes
  Kalenderprogramm herunter. Mit **Mit den Vorbereitungsfristen (10, 8 und 6
  Wochen vorher)** kommen die Fristen als ganztägige Einträge dazu.
- Das Widget **Cantoria – fällig** zeigt im Nextcloud-Dashboard dasselbe
  wie die Startseite „Fällig“, mit Links in Cantoria: jeder Eintrag mit
  kurzem Titel, Einzelheiten in der zweiten Zeile und einem Symbol für
  Konzert, Karte oder Aufgabe.
- In der **Dateien**-App öffnet ein Klick auf eine MusicXML-Datei im
  Archivordner die Notenansicht ihres Exemplars. Bei Scans und anderen
  Dateien steht **In Cantoria öffnen** im Aktionsmenü.

## Häufige Probleme

### Kein Zugang zum Notenarchiv

„Du hast keinen Zugang zum Notenarchiv.“ Dein Konto hat keine Rolle im
Notenarchiv; die App oder die Seite „Noch kein Zugang“ sagt dir das. Rollen
hängen an Nextcloud-Gruppen. Bitte die Administration, dein Konto in die
Gruppe deiner Rolle aufzunehmen (siehe
[Administrationshandbuch](administrationshandbuch.md#2-rollen-und-gruppen)).
Chormitglieder sehen nur **Mit mir geteilt**, und das nur, wenn es
eingeschaltet ist.

### Datei lässt sich nicht öffnen

Der Archivordner ist nicht mit deinem Konto geteilt; mit der Datei und
deiner Rolle ist alles in Ordnung. Bitte die Archivverwaltung, den
Archivordner mit dir oder deiner Gruppe zu teilen; Leserechte genügen.
Lade danach die Seite neu.

### Ein Exemplar fehlt im Werkverzeichnis

Prüfe die Schreibweise; eine Signatur muss vollständig sein (`2-1`, nicht
`2-`). Schau, ob unter **Weitere Filter** noch ein Filter aktiv ist, zum
Beispiel der Status: Ausgeschiedene, fehlende und verliehene Exemplare
erscheinen erst mit ihrem Status oder **Jeder Status**. **Suche und Filter
zurücksetzen** hebt alle Filter auf.

### Abspielen oder Übe-Dateien werden nicht angeboten

Die Gemeinfreiheit ist noch nicht bestätigt, es fehlt eine Lizenz für
Übe-Audio, oder es ist eine interne Erhaltungskopie. Die Archivverwaltung
bestätigt die Rechte im Reiter **Rechte** oder trägt die Lizenz ein.

### „Keine Schreibberechtigung für Exporte im Archivordner.“

Das Konto, das den Archivordner gewählt hat, braucht Schreibrechte darauf.
Bitte die Administration darum.

## Mehr

- **Auswertungen** zeigt Bestand, Rechte, Repertoire und Pflege in Zahlen;
  der **Jahresbericht** fasst ein Jahr für Vorstand und Mitgliederversammlung
  zusammen.
- [Benutzerhandbuch](benutzerhandbuch.md): alle Funktionen, nach Rollen und
  Aufgaben geordnet, mit dem Abschnitt
  [Häufige Probleme](benutzerhandbuch.md#häufige-probleme)
- [Administrationshandbuch](administrationshandbuch.md): Installation,
  Rollen, Archivordner, Import, Sicherung
- [Demo-Bestand](../../tools/demo/README.md): ein erfundenes Notenarchiv zum
  Ausprobieren
