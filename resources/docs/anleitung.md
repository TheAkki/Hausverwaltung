# Bedienungsanleitung Hausverwaltung

Diese Anleitung beschreibt die Bedienung der Hausverwaltungs-Software. Sie richtet sich an Nutzer, die Immobilien, Mietverhältnisse, Kosten und Betriebskostenabrechnungen verwalten.

## Erster Start

Administrator ist das Konto mit der E-Mail-Adresse aus der Einstellung `ADMIN_EMAIL`. Im Docker-Betrieb legst du es an, indem du dich mit dieser Adresse über **Registrieren** anmeldest.

In einer Entwicklungsumgebung (`php artisan db:seed`) steht stattdessen ein **Admin-Account** (`admin@example.com`) mit leerem Passwort zur Verfügung. Nach dem ersten Login wirst du automatisch auf die Profilseite geleitet und musst dort ein neues Passwort vergeben, bevor du die restliche Anwendung nutzen kannst.

Alternativ kannst du dich über **Registrieren** einen eigenen Account anlegen. Dafür werden ein Name, eine gültige E-Mail-Adresse und ein Passwort benötigt. Beim Anlegen wird automatisch eine eigene Benutzergruppe („Gruppe von [Name]“) erzeugt, in der du als Administrator eingetragen bist.

## Benutzer und Gruppen

- Unter **Benutzer** siehst du alle Nutzer, mit denen du gemeinsam in einer Gruppe bist.
- Unter **Gruppen** kannst du neue Benutzergruppen anlegen und – als Besitzer – wieder löschen.
- Innerhalb einer Gruppe kannst du als Administrator weitere Mitglieder hinzufügen, entfernen oder zu Administratoren machen.
- Ein Benutzer kann sich selbst nicht löschen und auch keine anderen Benutzer löschen.

## Verwaltungsstruktur

Die Verwaltung ist dreistufig aufgebaut:

1. **Verwaltungsgruppe** – die oberste Ebene, z. B. für eine Liegenschaft mit mehreren Gebäuden oder eine gemeinsam gepflegte Grünfläche. Beim Anlegen wird automatisch das erste **Abrechnungsintervall** erzeugt.
2. **Verwaltungseinheit** – ein postalisch adressierbares Gebäude innerhalb einer Verwaltungsgruppe (Straße, PLZ, Ort).
3. **Mieteinheit** – eine einzelne vermietbare Einheit (Wohnung, Garage, Stellplatz) innerhalb einer Verwaltungseinheit, mit Größe und Angabe, ob sie für die Betriebskostenabrechnung relevant ist.

Du siehst dabei immer nur die Verwaltungsgruppen, -einheiten und Mieteinheiten, die einer deiner Benutzergruppen zugeordnet sind.

Im Detailbild einer Verwaltungseinheit findest du unterhalb der Mieteinheiten-Tabelle eine ein- und ausklappbare **Belegungsmatrix** (standardmäßig eingeklappt, klappt sich beim Wechsel des Jahres automatisch auf), die pro Mieteinheit und Monat anzeigt, ob und von wem (per Tooltip) sie im gewählten Jahr belegt war.

Die **Mieteinnahmen**-Tabelle lässt sich zwischen drei Gruppierungen umschalten: nach Mieter und Mieteinheit (Standard), nur nach Mieter (mit zusammengefassten Beträgen über alle Mieteinheiten), oder nach Mieter mit getrennter Ausweisung von Kaltmiete und Nebenkosten.

## Mieter und Mietverträge

- Unter **Mieter** verwaltest du Namen, Anschrift sowie optional Firma, E-Mail, Handynummer und IBAN/BIC der Mietparteien.
- Ein **Mietvertrag** verbindet eine Mieteinheit mit einem oder mehreren Mietern. Er hat ein Startdatum, optional ein Enddatum, sowie eine Mietanpassungsart (Fix, Staffel oder Index). Zusätzlich können Kaution, Mindestlaufzeit, Kündigungsfrist und freie Notizen hinterlegt werden.
- Beim Anlegen eines Mietvertrags über den Dialog **Mietvertrag erstellen** sind die Felder umso stärker vorbelegt und schreibgeschützt, je konkreter der Ausgangspunkt ist (z. B. direkt aus einer Mieteinheit heraus).
- Mit dem Anlegen eines Mietvertrags wird automatisch das erste **Mietintervall** erzeugt. Ein Mietintervall enthält die aktuell gültige Kaltmiete, die Betriebskostenvorauszahlung, die Personenzahl sowie optional eine Notiz.
- Weitere Mietintervalle (z. B. bei Staffel- oder Indexmieten oder bei einer Änderung der Personenzahl) legst du manuell an; das vorherige Intervall wird dabei automatisch zum Vortag des neuen Startdatums geschlossen.
- In der Übersicht **Mietverträge** werden abgelaufene Verträge ausgegraut dargestellt. Ein Klick auf die Mieteinheit zeigt die zugehörigen Mietintervalle; ein Klick auf ein Intervall öffnet dessen Bearbeitung.

## Rechnungen, Rechnungssteller und Kosten

- **Rechnungssteller** (z. B. Versorger, Handwerker) werden mit Name, Adresse, optional Anrede, Firma, E-Mail, Telefon, Mobilfunk, IBAN, BIC und Kommentar angelegt. Ein Rechnungssteller kann mehrere **Kundenaccounts** haben (z. B. getrennte Accounts für Strom, Wasser oder Fernwärme bei einem Versorger), jeweils mit Kundennummer, optionaler Beschreibung und Lastschrift-Kennzeichen.
- Beim Anlegen einer **Rechnung** gibst du Rechnungsbetrag, Rechnungsnummer, Rechnungs- und Buchungsdatum, den Rechnungssteller sowie optional dessen Kundenaccount an. Optional sind Beschreibung, Datei-Upload und ein abweichender wirksamer Betrag (z. B. bei Skonto).
- Rechnungen können auch für wiederkehrende Abbuchungen stehen: einmalig (Standard), wöchentlich, 2-wöchentlich, monatlich, quartalsweise, jährlich oder mit händisch erfassten Einzelterminen samt Beträgen.
- Innerhalb einer Rechnung ordnest du die Kosten zu: zuerst wählst du die Verwaltungsgruppe, -einheit oder Mieteinheit, danach das passende Abrechnungsintervall sowie die Kostenkategorie. Die Summe der Kosten darf den (wirksamen) Rechnungsbetrag nicht übersteigen. Diese Zuordnung ist der Wirkungsrahmen der Rechnung und wird in der Rechnungsübersicht als „Zugeordnet zu" angezeigt.
- Pro Verwaltungsgruppe kann je Kostenkategorie ein **Abrechnungsschlüssel** festgelegt werden (z. B. nach Fläche, Personenzahl oder individuell).
- **Kostenzuweisung:** Hat eine Kategorie den Abrechnungsschlüssel **„Individuell"**, kann eine Kostenzeile bei der Zuordnung zusätzlich direkt einem **Mietvertrag** zugewiesen werden (in der Auswahlliste als „––– Mietername (Mieteinheit)" erkennbar). Solche Kosten gehen nicht in die Kontensystem-Bilanzierung ein und können keinem Konto zugeordnet werden, da sie nur extern (außerhalb des Kontensystems) ermittelt wurden – bei aktivem Kontensystem steht für sie deshalb kein Konto-Feld zur Verfügung.

## Wartungsverträge

Unter **Wartungsverträge** hinterlegst du wiederkehrende Verträge wie Rauchwarnanlagen- oder Lüftungswartung mit Name, Typ, Anbieter und Laufzeit je Verwaltungsgruppe.

## Übersichten

- Im Detailbild einer **Verwaltungseinheit** findest du die monatlichen Mieteinnahmen (Kaltmiete + Betriebskostenvorauszahlung je Mieter und Monat) sowie die Ausgaben gruppiert nach Kategorie – umschaltbar nach Jahr.
- Im Detailbild einer **Verwaltungsgruppe** stehen die Einnahmen gruppiert nach Mieteinheitstyp sowie die Ausgaben gruppiert nach Kategorie (inklusive der Kosten untergeordneter Verwaltungseinheiten und Mieteinheiten) zur Verfügung.

## Betriebskostenabrechnung

- Unter **Abrechnungs-Templates** verwaltest du die Vorlagen für die Betriebskostenabrechnung. Jeder Verwaltungsgruppe kann ein Standard-Template zugeordnet werden. Templates lassen sich duplizieren – auch das eingebaute Standard-Template.
- Ein Template enthält ein Anschreiben nach DIN 5008 mit Angaben zu Mieter, Verwaltungsgruppe, -einheit und Mieteinheit sowie zum Abrechnungsintervall und zur Mietdauer darin. Kopf- und Fußzeile inklusive Bild lassen sich frei gestalten.
- Das Standard-Template enthält zusätzlich eine Übersicht der monatlich angefallenen Nebenkosten innerhalb des Abrechnungsintervalls.
- Für jede Verwaltungseinheit und jedes Abrechnungsintervall kann die Abrechnung angezeigt und als PDF exportiert werden – Kopf-Bild und alle anderen Inhalte funktionieren dabei identisch in der HTML-Vorschau wie im PDF-Export.
- Der auf einen Mietvertrag entfallende Kostenanteil wird mehrstufig berechnet: Kosten, die auf Ebene der Verwaltungsgruppe, der Verwaltungseinheit oder direkt auf einem Mietvertrag (bei „Individuell"-Kategorien) gebucht wurden, werden je nach Abrechnungsschlüssel der Kategorie (Fläche, Personen oder individuell) anteilig zugerechnet und zu einer Gesamtsumme je Mietvertrag addiert. Details zur genauen Berechnung stehen auf der Seite „Hilfe: Templates schreiben".

### Templates und Benutzergruppen

Jedes selbst erstellte Template wird beim Anlegen einer deiner Benutzergruppen zugeordnet (Auswahlfeld „Gruppe"). In der Templateübersicht wird diese Gruppe hinter dem Namen angezeigt. Du siehst dort nur Templates deiner eigenen Gruppen sowie das eingebaute Template „Standard (DIN 5008)" (als „Intern" gekennzeichnet) – Templates anderer Gruppen sind nicht sichtbar.

Das eingebaute „Intern"-Template kann von jedem dupliziert, aber nicht direkt bearbeitet oder gelöscht werden – für eigene Anpassungen legst du dir am besten eine Kopie an.

Die Checkbox **„Als Standard-Template der ausgewählten Gruppe verwenden"** setzt dieses Template als Standard-Template für **alle** Verwaltungsgruppen, die deiner ausgewählten Benutzergruppe gehören (nicht für die gesamte Anwendung).

### Kopf-Bild hochladen und anzeigen

Beim Erstellen oder Bearbeiten eines Templates gibt es das Feld **„Kopf-Bild (optional)"** – ein normaler Datei-Upload (JPG/PNG/GIF/…, maximal 2 MB). Nach dem Speichern erscheint dort eine Vorschau des hochgeladenen Bildes mit der Möglichkeit, es wieder zu entfernen.

Das Hochladen allein zeigt das Bild aber noch nirgends an: In der Kopfzeile, Fußzeile oder im Inhalt (jeweils Blade-Syntax) steht dafür die Variable `$headerImage` zur Verfügung (`null`, wenn keins hochgeladen wurde). Du musst sie also selbst referenzieren, zum Beispiel in der Kopfzeile:

```blade
@if ($headerImage)
    <img src="{{ $headerImage }}" style="height: 20mm;">
@endif
```

Das mitgelieferte Standard-Template nutzt aktuell keine Kopf-/Fußzeile und zeigt daher auch kein Bild an – dafür legst du am besten ein eigenes Template an (oder ergänzt das Standard-Template um eine Kopfzeile wie oben).

`$headerImage` liefert das Bild dabei als eingebettete Daten-URI, nicht als Speicher-Link – so wird es zuverlässig sowohl in der HTML-Vorschau als auch im PDF-Export angezeigt, ohne dass dafür eine Netzwerkanfrage nötig ist.

### Template schreiben, prüfen und ausprobieren

Über den Link **„Hilfe: Templates schreiben"** (auf der Templateübersicht sowie im Bearbeiten-Formular) erreichst du eine Seite, die die wichtigsten Blade-Befehle erklärt (Werte ausgeben, Bedingungen, Schleifen über Listen, eigene Zwischenwerte per `@php` berechnen und "merken") und alle verfügbaren Variablen mit ihrem genauen Aufbau auflistet. Ein Button dort rendert ein Beispiel-Template mit erfundenen Testdaten.

Im Bearbeiten-Formular eines Templates stehen zusätzlich zwei Buttons zur Verfügung, die den aktuellen (auch noch ungespeicherten) Inhalt von Kopfzeile, Inhalt und Fußzeile verwenden:

- **„Vorschau mit Testdaten generieren"** öffnet die gerenderte Ansicht in einem neuen Tab.
- **„Template überprüfen"** prüft Blade-Syntax und verwendete Variablen, ohne die Seite zu verlassen. Bei einem Fehler (z. B. einer falsch geschriebenen Variable oder einem fehlenden `@endforeach`) erscheint ein Hinweis mit einer möglichst konkreten Fehlermeldung; ist alles in Ordnung, erscheint eine Erfolgsmeldung.

## Themes

Unter **Themes** kannst du dein persönliches Erscheinungsbild wählen: das moderne Standard-Theme, ein an den Film "Matrix" angelehntes Theme (grün auf schwarz) oder ein an Norton Commander erinnerndes Theme (weiß/gelb auf Blau). Themes werden über CSS definiert und können erweitert werden.

### Ein eigenes Theme erstellen

1. Gehe zu **Themes → Neues Theme**.
2. Vergib einen **Namen** und einen eindeutigen **Slug** (kurze technische Kennung, z. B. `mein-theme`).
3. Trage im Feld **CSS** deine eigenen Regeln ein. Jede Regel besteht aus einem Selektor (z. B. `.bg-white`) und den gewünschten Eigenschaften in geschweiften Klammern, z. B.:
   ```css
   .bg-white { background-color: #1a1a1a; }
   .text-gray-900 { color: #e5e5e5; }
   ```
4. Unterhalb des CSS-Feldes zeigt die Liste **Bekannte CSS-Elemente** alle Selektoren, die die Standard-Oberfläche verwendet. Grün markierte Einträge deckt dein CSS bereits ab, grau markierte fehlen noch – die Liste aktualisiert sich live beim Tippen.
5. Ein bestehendes Theme (auch ein eingebautes) lässt sich über **Kopieren** als Ausgangspunkt für ein eigenes, angepasstes Theme verwenden.

**Was die einzelnen CSS-Selektoren steuern:**

| Selektor-Muster | Bedeutung |
| --- | --- |
| `.bg-*` (z. B. `.bg-white`, `.bg-gray-100`) | Hintergrundfarben von Karten, Seiten und Kopfzeile |
| `.text-*` (z. B. `.text-gray-900`, `.text-red-600`) | Textfarben, u. a. für Überschriften, normalen Text und Fehlermeldungen |
| `.border-*`, `.border`, `.border-t`, `.border-b` | Rahmenfarben und -linien zwischen Bereichen und Tabellenzeilen |
| `.shadow`, `.shadow-sm` | Schlagschatten von Karten und Dialogen |
| `.rounded-md`, `.rounded-lg` | Rundung der Ecken von Karten, Buttons und Eingabefeldern |
| `body` | Grundschrift und -hintergrund der gesamten Seite |
| `a`, `.hover\:underline:hover` | Linkfarbe bzw. Unterstreichung beim Überfahren mit der Maus |
| `button` | Aussehen von Schaltflächen |
| `input`, `select`, `textarea` | Aussehen von Formularfeldern |

Die Farbfamilien `.bg-red-*`/`.text-red-*` werden speziell für Fehler- und Lösch-Aktionen verwendet (z. B. der "Entfernen"-Link).

## Testdaten (`seeddata.yaml`)

Beim Start der Anwendung (bzw. bei `php artisan db:seed`) liest ein Seeder optional die Datei `seeddata.yaml` im Ordner `private-assets/` ein und füllt damit die Datenbank. Fehlt die Datei oder ist sie leer, passiert nichts – die Anwendung startet trotzdem normal.

### Aufbau der Datei

Die Datei beginnt mit der Sprachangabe `lang: "de"`. Darunter folgen beliebig viele **Accounts** (Benutzerkonten), jeweils mit `Name` und `Passwort`. Innerhalb eines Accounts können unter anderem angegeben werden:

- **Verwaltungsgruppen** (`Verwaltungsgruppe-1`, `Verwaltungsgruppe-2`, …) mit Beschreibung, optionalem Kontensystem-Schalter und darin verschachtelt **Verwaltungseinheiten** und wiederum darin **Mieteinheiten** samt **Mietverträgen** und **Mietintervallen**.
- **Mieter** (`Mieter-1`, `Mieter-2`, …) mit Name, Anschrift und optionalen Kontaktdaten.
- **Rechnungssteller** (`Rechnungssteller-1`, …) mit optionalen **Kundenaccounts**.
- **Rechnungen**, gruppiert nach Verwaltungsgruppe/-einheit/Mieteinheit, mit Kostenzuordnung.
- **Kategorien**, um eigene Kostenkategorien anzulegen (z. B. "Zinsen" oder "Tilgung" für Kreditkosten), optional mit einem Abrechnungsschlüssel, der als Vorbelegung für alle Verwaltungsgruppen dieses Accounts übernommen wird.

Ein minimales Beispiel:

```yaml
lang: "de"

Account-1:
  Name: "Vermieter"
  Passwort: "geheim"

  Verwaltungsgruppe-1:
    Beschreibung: "Musterstraße 1"

    Verwaltungseinheit-1:
      Straße: "Musterstraße 1"
      PLZ: 12345
      Stadt: "Musterstadt"

      Mieteinheit-1:
        Typ: "Wohnung"
        Größe: 65,00
        Abrechnungsrelevant: true

        Mietvertrag-1:
          MieterId: Mieter-1
          Beginn: 2024-01-01
          Mietpreistyp: "Fix"
          Kaltmiete: 500
          Nebenkosten: 150

  Mieter-1:
    Vorname: "Max"
    Name: "Mustermann"
    Straße: "Musterweg 2"
    PLZ: 12345
    Ort: "Musterstadt"
```

Attribute in eckigen Klammern (z. B. `[Beschreibung]`) sind optional; ein Sternchen im Namen (z. B. `Mieteinheit-*`) bedeutet, dass beliebig viele nummerierte Einträge dieser Art vorkommen können (`Mieteinheit-1`, `Mieteinheit-2`, …). Die vollständige, ausführlich kommentierte Schemabeschreibung findest du im Abschnitt "Testdaten" der `Konzept.md` im Projektverzeichnis.

**Hinweis:** Wird die Datenbank komplett neu aufgebaut (z. B. `php artisan migrate:fresh --seed`), gehen alle Daten verloren, die nicht aus `seeddata.yaml` rekonstruierbar sind (z. B. Buchungsdaten, die du zuvor nur über die Oberfläche bestätigt hast). Für ein reines Nachziehen von Änderungen an `seeddata.yaml` reicht in der Regel `php artisan db:seed --class=TestDataSeeder` – das ergänzt bzw. aktualisiert Daten, ohne Bestehendes zu löschen.

## Backup (Export und Import)

Auf der **Profilseite** findest du den Abschnitt **Backup**. Dort kannst du deine Daten als ZIP-Datei exportieren und später (auch auf einer anderen Installation) wieder importieren.

Der Export enthält:

- deine Profileinstellungen (Name, gewähltes Theme),
- alle Benutzergruppen, in denen du **Administrator** bist – samt Mitgliederliste und Rollen,
- die komplette Verwaltungsstruktur dieser Gruppen (Verwaltungsgruppen, Verwaltungseinheiten, Mieteinheiten, Mietverträge mit Mietern und Mietzahlungen, Rechnungssteller, Rechnungen, Kostenzeilen, Konten, Wartungsverträge, Abrechnungsintervalle),
- alle angehängten Dateien (Rechnungs-PDFs, Mietvertragsdokumente) im Ordner `files/` des Archivs.

**Passwörter sind bewusst nicht enthalten.** Beim Import werden die Gruppen aus dem Backup neu angelegt und alle Mitglieder mit ihren damaligen Rollen wieder eingetragen. Benutzer, die auf dem Zielsystem noch nicht existieren, werden dabei automatisch angelegt – ohne Passwort und mit Passwortzwang beim ersten Login (wie beim Admin-Account).

Der **Admin-Account** kann zusätzlich über **„Alle Einstellungen exportieren“** ein Gesamt-Backup aller Benutzer, Gruppen, Themes, Abrechnungsvorlagen und Kostenkategorien erstellen. Ein solches Gesamt-Backup kann auch nur vom Admin-Account importiert werden; dabei werden die Berechtigungen (Gruppenmitgliedschaften und Rollen) aller Benutzer wiederhergestellt.

**Hinweis:** Der Import legt die enthaltenen Gruppen immer **neu** an, statt bestehende Gruppen zu überschreiben – ein versehentlicher Doppel-Import erzeugt also Duplikate, löscht aber niemals vorhandene Daten.

## Mehrsprachigkeit

Über das Benutzermenü kannst du zwischen Deutsch und Englisch umschalten.
