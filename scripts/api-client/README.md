# Eigene Daten über die REST-API importieren

Mit diesem Script trägst du deine Daten in die Hausverwaltung ein, ohne jede Rechnung
einzeln im Web zu erfassen: Mieter, Häuser und Wohnungen, Mietverträge, Rechnungen samt
Beleg (PDF), Abbuchungen vom Kontoauszug und Mieteingänge.

Du beschreibst deine Daten in einer kleinen Python-Datei. Das Script spricht damit die
REST-API an. Die Anwendung rechnet dabei alles selbst aus, genauso wie beim Seeder:
- welche Termine eine wiederkehrende Rechnung hat,
- in welches Abrechnungsjahr ein Betrag gehört,
- welcher Monat mit einer Mietzahlung bezahlt wurde.

Benötigt wird nur Python ab 3.10, keine zusätzlichen Pakete.

## Zwei Rollen

| Wer | Script | Aufgabe |
|---|---|---|
| Administrator | `admin_einrichten.py` | Benutzerkonten und gemeinsame Gruppen anlegen, Mitglieder zuordnen |
| Anwender | `daten_importieren.py` | die eigenen Daten importieren |

Nur der Administrator der Anwendung (die E-Mail-Adresse aus `ADMIN_EMAIL`) darf Benutzer
anlegen und Gruppen für andere Benutzer einrichten. Jeder Anwender importiert danach
seine eigenen Daten mit seinem eigenen Zugang.

## Anmelden

1. Im Web unter **Profil → API-Zugang** einen Token erzeugen und kopieren. Er wird nur
   einmal angezeigt.
2. Den Token als Umgebungsvariable setzen, nie in eine Datei schreiben:

   ```bash
   export HV_API_URL=https://hausverwaltung.example.org/api   # Standard: http://127.0.0.1:8000/api
   export HV_API_TOKEN=1|abc…
   ```

Alternativ gehen `HV_API_EMAIL` und `HV_API_PASSWORD`. Das Script meldet sich dann an und
widerruft seinen Token am Ende wieder. Nicht mehr benötigte Tokens widerrufst du im Profil.

## Erst prüfen, dann importieren

```bash
cd scripts/api-client
python3 daten_importieren.py beispiel/meine_daten.py --dry-run   # nur anzeigen, nichts ändern
python3 daten_importieren.py beispiel/meine_daten.py
```

Das Script kann beliebig oft laufen. Jeder Datensatz wird zuerst gesucht, und nur was fehlt,
wird angelegt. So ergänzt du später einfach neue Rechnungen oder Kontoauszüge in derselben
Datei und startest das Script erneut. Zum Schluss steht eine Zusammenfassung da:

```
Ergebnis: 3 angelegt, 18 vorhanden, 9 aktualisiert.
```

- Exit-Code `0`: alles in Ordnung.
- Exit-Code `1`: einzelne Datensätze hatten Probleme. Sie werden mit der Meldung der API
  aufgelistet, die davon abhängigen Datensätze übersprungen. Daten korrigieren und erneut
  starten.
- Exit-Code `2`: keine Verbindung, kein Zugang oder Datendatei nicht lesbar.

Im Trockenlauf zählen Buchungen und Einstellungen (Abrechnungsschlüssel, Vermieter) immer
als „geplant“, weil erst der Server entscheidet, ob sich dadurch etwas ändert.

## Die Datendatei

Eine Datendatei für Anwender enthält eine Funktion `importieren(hv)`. Ein vollständiges
Beispiel mit erfundenen Daten steht in [beispiel/meine_daten.py](beispiel/meine_daten.py).
Die Feldnamen sind dieselben wie in der API-Dokumentation (`/api-docs` im Web).

```python
def importieren(hv):
    gruppe = hv.group("Gruppe von Anna Beispiel")
    meier = hv.tenant(gruppe, first_name="Maria", last_name="Meier", street="Musterstraße 1",
                      postal_code="01234", city="Musterstadt")
    stadtwerke = hv.invoice_issuer(gruppe, name="Stadtwerke Musterstadt", postal_code="01234", city="Musterstadt")

    haus = hv.management_group(gruppe, "Musterstraße 1")
    hv.billing_interval(haus, "2026-01-01", "2026-12-31")
    gebaeude = hv.management_unit(haus, "Musterstraße 1", "01234", "Musterstadt")
    eg = hv.rental_unit(gebaeude, "Wohnung", "EG links", size=62.5, is_relevant_for_billing=True)
    vertrag = hv.lease(eg, "2025-01-01", tenants=[meier], rent_adjustment_type="fixed", cold_rent=500.0)

    abschlag = hv.invoice(stadtwerke, haus, "2026-01-05", "monthly", amount=45.0,
                          recurrence_start="2026-01-05", recurrence_end="2026-12-05",
                          allocations=[("Wasserversorgung", 30.0), ("Entwässerung", 15.0)],
                          file="belege/stadtwerke-wasser-2026-01.pdf")
    hv.bookings(abschlag, [("2026-02-04", [("Wasserversorgung", 32.0), ("Entwässerung", 16.0)])])
    hv.rent_payments(vertrag, ["2025-12-30", "2026-02-01"])
```

### Was es gibt

| Aufruf | Bedeutung | Wiedererkannt an |
|---|---|---|
| `group(name)` | eigene Gruppe (legt der Administrator an) | Name |
| `group_landlord(gruppe, …)` | Vermieter der Gruppe | – |
| `tenant(gruppe, …)` | Mieter | Name und Anschrift |
| `invoice_issuer(gruppe, …)` | Rechnungssteller | Name, PLZ, Ort |
| `issuer_account(rechnungssteller, kundennummer, …)` | Kundenaccount, z. B. Strom und Wasser getrennt | Kundennummer |
| `category(gruppe, name, …)` | eigene Kategorie, Unterkategorie als `"Haupt::Unter"` | Name |
| `billing_template(gruppe, name, header_image=…, content=…)` | Vorlage der Betriebskostenabrechnung mit Kopfbild; geänderte Texte werden beim nächsten Lauf übernommen, das Kopfbild bleibt | Name |
| `management_group(gruppe, beschreibung, …)` | Verwaltungsgruppe | Beschreibung |
| `management_group_landlord(vg, …)` | abweichender Vermieter der Verwaltungsgruppe | – |
| `billing_interval(vg, beginn, ende)` | Abrechnungsjahr; schließt an die vorhandenen an | Beginn und Ende |
| `account(vg, name, …)` | Konto (Kontensystem) | Name |
| `allocation_key(vg, kategorie, schlüssel)` | Abrechnungsschlüssel: `person_count`, `area`, `individual`, `rental_unit` | – |
| `management_unit(vg, straße, plz, ort)` | Haus | Anschrift |
| `rental_unit(haus, typ, beschreibung, …)` | Wohnung, Garage, Stellplatz | Typ und Beschreibung |
| `lease(einheit, beginn, tenants=[…] oder coupled_to=…, intervals=[…])` | Mietvertrag mit Mietintervallen | Mieteinheit und Beginn |
| `settlement_claim(vertrag, abrechnungsjahr, datum, betrag)` | Nachzahlung (+) oder Erstattung (−) | Abrechnungsjahr und Datum |
| `transfer(vg, von_konto, nach_konto, datum, betrag)` | Umbuchung | Konten, Datum, Betrag |
| `invoice(rechnungssteller, zuordnung, datum, wiederholung, …)` | Rechnung, siehe unten | Rechnungssteller, Zuordnung, Datum, Betrag, Beschreibung |
| `bookings(rechnung, [(datum, betrag oder [(kategorie, betrag)])])` | tatsächliche Abbuchungen | – (erneut senden ändert nichts) |
| `rent_payments(vertrag, [datum, …])` | eingegangene Mieten | – (erneut senden ändert nichts) |
| `move_management_group(vg, gruppe, default_billing_template=…)` | Verwaltungsgruppe in eine gemeinsame Gruppe verschieben | – |

### Rechnungen

- **Zuordnung:** Eine Rechnung gehört zu einer Verwaltungsgruppe, einem Haus, einer
  Mieteinheit oder einem Mietvertrag. Du übergibst den passenden Datensatz.
- **Aufteilung** (`allocations`): Liste von (Kategorie, Betrag). Fehlt der Betrag (`None`),
  gilt bei einer einzigen Kategorie der ganze Rechnungsbetrag, sonst 0. Der Betrag kommt
  dann mit den Abbuchungen, etwa bei Zins und Tilgung eines Kredits.
  - Die Summe muss dem Rechnungsbetrag entsprechen, außer bei `disable_amount_cap=True`.
- **Wiederholung:**
  - `"once"` (einmalig) oder `"weekly"`, `"biweekly"`, `"monthly"`, `"quarterly"`, `"yearly"`.
  - Die Termine laufen von `recurrence_start` bis `recurrence_end`. Ohne Ende laufen sie
    bis zum Ende des Folgejahres.
  - Mit Ende bleibt die Rechnung im Abrechnungsjahr ihres Beginns. Ohne Ende kommt jeder
    Termin in das Jahr seines Datums.
  - `billing_interval=` heftet alle Termine an ein bestimmtes Abrechnungsjahr.
- **Betragsintervalle** (`segments`): wenn sich der Betrag ab einem Datum ändert, z. B.
  `[{"start_date": "2026-01-01", "end_date": "2026-06-30", "amount": 100}, {"start_date":
  "2026-07-01", "amount": 110}]`.
- **Erneute Angabe:** Gibst du eine vorhandene Rechnung erneut mit neuer Laufzeit an,
  etwa nach einer Vertragsverlängerung, ergänzt das Script die fehlenden Termine.
- **Beleg** (`file`): Pfad zu einer PDF-, Word- oder Bilddatei (bis 10 MB), relativ zur
  Datendatei. Das Script lädt den Beleg direkt nach dem Anlegen hoch. Hat die Rechnung
  schon einen Beleg mit diesem Dateinamen, bleibt er; eine Datei mit anderem Namen
  ersetzt ihn. `attach_file(rechnung, pfad)` hängt einen Beleg auch nachträglich an.
- **Abbuchungen** (`bookings`):
  - Jede Abbuchung wird dem nächstgelegenen Termin zugeordnet. Ein paar Tage früher
    oder später ist kein Problem.
  - Danach passt die Anwendung die künftigen offenen Termine an, aber nur bei
    schwankenden Beträgen: Betragsprüfung aus oder mindestens zwei verschiedene gebuchte
    Beträge. Es gilt der Prognosemodus der Verwaltungsgruppe (letzter Betrag, Regression
    oder keine Prognose). Mit `refresh_forecast=False` bleiben sie unverändert.
  - Ein dritter Wert je Anteil heftet ihn an ein Abrechnungsjahr.

### Mieten

`rent_payments` bucht Mieteingänge nach ihrem Zahlungsdatum. Eine Zahlung kurz vor dem
Monatswechsel zählt für den Folgemonat. Maßgeblich ist der Tag des Mietbeginns, höchstens
15 Tage vorher. Gibt es für ein Datum keine offene Miete, steht ein Hinweis im Bericht.

### Gemeinsame Gruppen

- Lege die Verwaltungsgruppe zuerst in deiner eigenen Gruppe an und importiere alles.
- Verschiebe sie danach mit `move_management_group` in die gemeinsame Gruppe. Eigene
  Kategorien bleiben dabei nutzbar.

## Für den Administrator

```bash
export HV_API_TOKEN=<Token des Administrators>
export HV_START_PASSWORD=<Startpasswort für neue Benutzer>   # sonst je Benutzer zufällig und einmal angezeigt
python3 admin_einrichten.py beispiel/admin_daten.py --dry-run
python3 admin_einrichten.py beispiel/admin_daten.py
```

Die Datendatei enthält eine Funktion `einrichten(admin)`, siehe
[beispiel/admin_daten.py](beispiel/admin_daten.py):

```python
def einrichten(admin):
    anna = admin.user("Anna Beispiel", "anna.beispiel@example.com")
    bert = admin.user("Bert Beispiel", "bert.beispiel@example.com")
    admin.group("Erbengemeinschaft Beispiel", owner=anna, members=[(bert, "user")])
```

- **Benutzer:** Jeder neue Benutzer bekommt automatisch seine Gruppe „Gruppe von <Name>“.
  Er sollte sein Startpasswort nach der ersten Anmeldung ändern.
- **Rollen:** `"admin"` darf die Gruppe verwalten, `"user"` darf in ihr arbeiten.

## Eigene Daten ablegen

Lege deine Datendateien und Belege im Ordner `meine_daten/` ab. Er ist per `.gitignore`
ausgeschlossen und wird nie veröffentlicht. Passwörter und Tokens gehören nie in die
Datendateien.

## Tests

```bash
cd scripts/api-client
python3 -m unittest discover -s tests -t .
flake8 .
```
