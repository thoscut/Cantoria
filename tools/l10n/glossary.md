<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Translation glossary

English is the source language; German (`de` informal, `de_DE` formal) is
maintained by the project. The translations into French, Spanish, Italian,
Dutch and Polish were made with the help of AI and **need a review by native
speakers**. Corrections are welcome as an issue or a pull request against
`tools/l10n/translations.<lang>.mjs`; run `npm run l10n` afterwards.

## Rules for all languages

- **Neutral and inclusive.** Prefer roles, collectives and gender-neutral
  nouns over gendered person nouns (*Chorleitung* = "the conductor's role",
  not "the conductor"). Do not use gender stars, middle dots or slashes:
  screen readers read them out. Persons involved in a work are named by the
  activity, as in German: *composition*, *arrangement*, *text*.
- **Form of address** as usual in Nextcloud for the language: French *vous*;
  Spanish *tú*; Italian *tu*; Dutch *je/jij*; Polish informal imperative and
  *Twój* in instructions, otherwise impersonal forms (*Zapisano*,
  *Nie można …*), never gendered past-tense forms of the second person.
- **Placeholders** (`{name}`, `%n`, `%s`, `%1$s`) stay exactly as in the
  source; only their position may change.
- **Law.** The app models German copyright law. Legal references such as
  "§ 70 UrhG" stay unchanged; terms are translated descriptively and
  neutrally, without claiming a national legal concept of the target country.
- **Catalog columns** of the CSV format (e.g. *Signatur*, *Werke*) are German
  names in the file and stay German in quotes.
- **Typography.** Keep "…" and the spacing of the source. Quotation marks:
  French « » with no-break spaces inside, Spanish, Italian and Dutch “ ”,
  Polish „ ”. French puts a no-break space before `:` `;` `!` `?`.

## One word, one meaning

In German each of these words has one meaning only; `translations.test.mjs`
checks that a German text with the word translates a source that says so.
The other languages follow the same split with the terms below.

| German | means only | not |
|---|---|---|
| Stand | revision of a version | "as of" a date (*am {date}*, *erstellt am*), a state or status (*Status*), a snapshot (*Jahresstand*, *das Archiv einfrieren*) |
| Ausgabe | loan of a choir folder | an edition (*Edition*; the legal term *wissenschaftliche Ausgabe* stays), outputs (*Exporte und Ausdrucke*) |
| Freigabe, freigegeben | release by the rights | sharing in Nextcloud or with choir members (*teilen*, *Mit mir geteilt*) |
| Schleife | the loop of the playback | the repeats of the score (*Wiederholungen*) |
| H | the note B (German and Polish note names in the correction form) | B flat, which is *B* |
| Tonhöhe | the pitch one sings, in the pitch display (*Meine Tonhöhe zeigen*) | moving the playback up or down (*Transponieren*) |
| Chorportal | the release of a version for the choir and the list of what is released (*Im Chorportal*) | the members' system of the choir outside Cantoria (*Chorsystem*), the page of a choir member in Cantoria (*Mit mir geteilt*) |
| Chorsystem | the members' system of the choir outside Cantoria, which gets the handover folder | the release for the choir (*Chorportal*) |

The English source follows the same rule for the roles and the new
functions:

- *catalog librarian* is only the role "Catalog (music librarian)" (German
  *die Rolle „Bestand“*); *music librarians* are the people who keep the
  archive, whatever their role (German *Archivverwaltung*). The role
  "Operations (music librarian)" is named *the role "Operations"* in texts.
- *singers' portal* is only the release for the choir and its list; the
  choir's own member app is the *choir system*; the page of a choir member
  is *Shared with me*.
- *available without a network* (German *Ohne Netz verfügbar*) is a piece
  kept in the browser of a device; *keep* is the action, never *save*
  (which is the button of a form) or *download* (which the rights may
  forbid).
- *pitch* is the pitch one sings; moving the playback is *transposing*.

## Core terms

| German | English source | fr | es | it | nl | pl |
|---|---|---|---|---|---|---|
| Cantoria (the app, a name: never translated) | Cantoria | Cantoria | Cantoria | Cantoria | Cantoria | Cantoria |
| Notenarchiv (the archive of a choir) | music archive | archives de partitions | archivo de partituras | archivio di spartiti | bladmuziekarchief | archiwum nut |
| Werkverzeichnis | catalog | catalogue | catálogo | catalogo | catalogus | katalog |
| Werk | work | œuvre | obra | opera | werk | utwór |
| Fassung | version | version | versión | versione | versie | wersja |
| Exemplar | copy | exemplaire | ejemplar | esemplare | exemplaar | egzemplarz |
| Signatur | shelf mark | cote | signatura | segnatura | signatuur | sygnatura |
| Archivverwaltung | archive management, music librarian(s) | gestion des archives | gestión del archivo | gestione dell'archivio | archiefbeheer | administracja archiwum |
| Chorleitung | conductor | direction du chœur | dirección del coro | direzione del coro | koorleiding | kierownictwo chóru |
| Chormitglieder | choir members, singers | membres du chœur | integrantes del coro | componenti del coro | koorleden | osoby z chóru |
| Chorportal | singers' portal | portail du chœur | portal del coro | portale del coro | koorportaal | portal chóru |
| Rechtestatus | rights status | statut des droits | estado de los derechos | stato dei diritti | rechtenstatus | status praw |
| gemeinfrei | public domain | dans le domaine public | de dominio público | di pubblico dominio | publiek domein | w domenie publicznej |
| Erhaltungskopie | preservation copy | copie de conservation | copia de conservación | copia di conservazione | conserveringskopie | kopia zabezpieczająca |
| Freigabe (rights) | release, released | autorisation, autorisé | autorización, autorizado | autorizzazione, autorizzato | vrijgave, vrijgegeven | zatwierdzenie, zatwierdzony |
| Notenansicht | score view | vue de la partition | vista de partitura | vista dello spartito | bladmuziekweergave | widok nut |

## Further terms

| German | English source | fr | es | it | nl | pl |
|---|---|---|---|---|---|---|
| Komposition | composer | composition | composición | composizione | compositie | kompozycja |
| Bearbeitung | arranger, arrangement | arrangement | arreglo | arrangiamento | bewerking | aranżacja |
| Text | lyricist | texte | letra | testo | tekst | tekst |
| geltende Fassung | valid version | version en vigueur | versión vigente | versione valida | geldende versie | obowiązująca wersja |
| Stück | piece | morceau | pieza | brano | stuk | utwór |
| Programmpunkt | programme line | élément du programme | punto del programa | voce del programma | programmaonderdeel | punkt programu |
| Notenliste | song list | liste de partitions | lista de partituras | elenco di spartiti | bladmuzieklijst | lista nut |
| Mappe (choir folder) | folder | classeur | carpeta de coro | cartellina | koormap | teczka |
| Archivmappe | binder | classeur d'archives | archivador | raccoglitore d'archivio | archiefordner | segregator archiwalny |
| Ordner (Nextcloud) | folder | dossier | carpeta | cartella | map | folder |
| Archivordner | archive folder | dossier d'archives | carpeta del archivo | cartella dell'archivio | archiefmap | folder archiwum |
| Ausgabe (of a folder) | loan | prêt | préstamo | prestito | uitlening | wypożyczenie |
| Mappenbuch | folder book | registre des classeurs | registro de carpetas | registro delle cartelline | koormappenboek | rejestr teczek |
| Druckstation | print station | poste d'impression | estación de impresión | postazione di stampa | printstation | stanowisko druku |
| Übe-Audio | practice audio | audio d'entraînement | audio de ensayo | audio per lo studio | oefenaudio | nagranie do ćwiczeń |
| Stimme | voice | voix | voz | voce | stem | głos |
| Ausschnitt | excerpt | extrait | fragmento | estratto | fragment | fragment |
| teilen (Nextcloud) | share | partager | compartir | condividere | delen | udostępnić |
| Vorschlag | proposal | proposition | propuesta | proposta | voorstel | propozycja |
| Eingang | inbox | boîte de réception | bandeja de entrada | posta in arrivo | inbox | skrzynka odbiorcza |
| Aufgabe | task | tâche | tarea | compito | taak | zadanie |
| Stand (of a version) | revision | révision | revisión | revisione | revisie | rewizja |
| Jahresstand | snapshot | instantané annuel | instantánea anual | istantanea annuale | jaaroverzicht | migawka roczna |
| Übertragung | transcription | transcription | transcripción | trascrizione | transcriptie | transkrypcja |
| Prüfpunkt | review point | point de vérification | punto de revisión | punto di verifica | controlepunt | punkt kontrolny |
| Rückfrage | question | question | consulta | domanda | vraag | pytanie |
| Korrekturauftrag | correction order | demande de correction | solicitud de corrección | richiesta di correzione | correctieopdracht | zlecenie korekty |
| Dateiabgleich | file sync | synchronisation des fichiers | sincronización de archivos | sincronizzazione dei file | bestandssynchronisatie | synchronizacja plików |
| Lizenz | license | licence | licencia | licenza | licentie | licencja |
| Schutzfrist, Schutzende | protection term, protection end | durée de protection, fin de la protection | plazo de protección, fin de la protección | durata della protezione, fine della protezione | beschermingstermijn, einde van de bescherming | okres ochrony, koniec ochrony |
| Repertoire | repertoire | répertoire | repertorio | repertorio | repertoire | repertuar |
| Administration | administration, admins | administration | administración | amministrazione | beheer | administracja |
| Konto | account | compte | cuenta | account | account | konto |

## Roles and terms settled in the review

Texts that refer to a role use the short name of its label, as German does
with „Bestand“ and „Betrieb“.

| German | English source | fr | es | it | nl | pl |
|---|---|---|---|---|---|---|
| Rolle „Bestand“ | Catalog (music librarian), catalog role | rôle « Catalogue » | rol “Catálogo” | ruolo “Catalogo” | rol “Collectie” | rola „Katalog” |
| Rolle „Betrieb“ | Operations (music librarian) | Exploitation | Operaciones | Operatività | Bedrijfsvoering | Obsługa |
| Vorstand, Revision | board, auditors | comité, vérification des comptes | junta directiva, revisión de cuentas | consiglio direttivo, revisione dei conti | bestuur, kascommissie | zarząd, komisja rewizyjna |
| hervorgehobene Stimme | focus voice | voix mise en évidence | voz destacada | voce in evidenza | uitgelichte stem | wyróżniony głos |
| Einzähler | count-in | décompte | cuenta previa | conteggio iniziale | aftellen | odliczanie |
| Takt | measure | mesure | compás | battuta | maat | takt |
| Protokoll | audit log | journal d'audit | registro de actividad | registro delle attività | auditlog | dziennik zmian |
| Neuzugang | accession | entrée | entrada | acquisizione | aanwinst | nabytek |
| Voreinstellung | preset | préréglage | ajuste predefinido | preimpostazione | voorinstelling | ustawienie wstępne |
