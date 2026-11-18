# Hausverwaltung

Webanwendung zur Verwaltung von Mietimmobilien für private Vermieter und kleine
Eigentümergemeinschaften. Sie erfasst Mieter, Mietverträge, Rechnungen und tatsächliche
Buchungen, behält offene Zahlungen im Blick und erstellt die Betriebskostenabrechnung
als PDF nach einer frei gestaltbaren Vorlage.

Die Oberfläche ist auf Deutsch und Englisch verfügbar.

## Funktionen

- **Struktur:** Verwaltungsgruppen (z. B. eine Eigentümergemeinschaft), Häuser,
  Mieteinheiten (Wohnung, Garage, Stellplatz) und Abrechnungszeiträume.
- **Mieter und Mietverträge:**
  - Mietintervalle mit Kaltmiete und Vorauszahlungen, gekoppelte Verträge,
    Vertragsdokumente;
  - Mieteingänge werden dem richtigen Monat zugeordnet.
- **Rechnungen und Kosten:**
  - einmalige und wiederkehrende Rechnungen mit Aufteilung auf Kostenkategorien und
    Betragsintervallen, Belege als PDF oder Bild;
  - tatsächliche Abbuchungen werden dem passenden Termin zugeordnet, künftige
    Beträge prognostiziert.
- **Konten:** optionales Kontensystem mit Umbuchungen, Wartungsverträge.
- **Übersichten:** offene Zahlungen, Ausgaben je Abrechnungszeitraum, Kontenübersicht und
  eine Steuerübersicht (Zuflussprinzip).
- **Betriebskostenabrechnung:**
  - Umlageschlüssel nach Fläche, Personen, Mieteinheit oder individuell;
  - Leerstände, Nachzahlungen und Erstattungen;
  - PDF je Mietvertrag oder gesammelt als ZIP, Vorlagen mit Blade/HTML/CSS und
    Kopfbild, passend für DIN-5008-Fensterumschläge.
- **Benutzer und Gruppen:** Daten gehören Benutzergruppen, Mitglieder mit den Rollen
  Administrator oder Benutzer.
- **Backup:** Export und Import der eigenen Daten samt Dateien als ZIP.
- **REST-API:**
  - nahezu alle Funktionen über Tokens (Laravel Sanctum);
  - interaktive Dokumentation unter `/api-docs`;
  - Python-Client für den Datenimport in [scripts/api-client](scripts/api-client/README.md).
- **Themes:** eigene Farbschemata per CSS.

Die Bedienung beschreibt die [Bedienungsanleitung](resources/docs/anleitung.md). Sie ist
in der Anwendung unter **Hilfe** erreichbar. Das fachliche Konzept steht in
[Konzept.md](Konzept.md).

## Schnellstart mit Docker

Der einfachste Weg für den Betrieb. Die Datenbank ist wählbar: SQLite, MySQL/MariaDB oder
PostgreSQL.

```bash
git clone https://github.com/TheAkki/Hausverwaltung.git
cd Hausverwaltung
cp .env.docker.example .env.docker      # mindestens ADMIN_EMAIL eintragen
docker compose --env-file .env.docker up -d --build
```

Die Anwendung läuft dann unter http://localhost:8080. Registriere dich dort mit der
Adresse aus `ADMIN_EMAIL`; dieses Konto ist der Administrator. Alle Einstellungen,
Datenbankvarianten und Hinweise zu Sicherung und Sicherheit stehen in
[docker/README.md](docker/README.md).

## Installation ohne Docker

### Voraussetzungen

- PHP 8.3 oder neuer mit den Erweiterungen `gd`, `intl`, `mbstring`, `zip`, `bcmath` und
  `pdo_sqlite`. Für einen Datenbankserver zusätzlich `pdo_mysql` oder `pdo_pgsql`.
- [Composer](https://getcomposer.org) 2
- Node.js 22 (mindestens 20.19) mit npm
- Datenbank: SQLite (Standard), MySQL/MariaDB oder PostgreSQL

### Einrichten

```bash
git clone https://github.com/TheAkki/Hausverwaltung.git
cd Hausverwaltung
composer run setup
```

`composer run setup` installiert die Abhängigkeiten, legt die `.env` an, erzeugt den
Anwendungsschlüssel, richtet die Datenbank ein und baut die Oberfläche.

Danach in der `.env` mindestens setzen:

```dotenv
APP_URL=http://localhost:8000
ADMIN_EMAIL=deine.adresse@example.org   # dieses Konto ist der Administrator
```

Datenbankserver statt SQLite: `DB_CONNECTION` (`mysql`, `mariadb` oder `pgsql`) sowie
`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` und `DB_PASSWORD` eintragen, dann
`php artisan migrate`.

Referenzdaten anlegen (Standardkategorien, Standardvorlage, Themes):

```bash
php artisan db:seed --class=CostCategorySeeder
php artisan db:seed --class=BillingTemplateSeeder
php artisan db:seed --class=ThemeSeeder
```

> `php artisan db:seed` ohne `--class` ist für die Entwicklung gedacht. Es legt
> zusätzlich Testkonten an, darunter `admin@example.com` mit leerem Passwort, und liest
> Testdaten aus `private-assets/seeddata.yaml`. Auf einem erreichbaren Server nicht
> verwenden.

### Starten

Für die Entwicklung (Webserver, Vite und Log-Anzeige zusammen):

```bash
composer run dev
```

Im Betrieb zeigt ein Webserver (nginx oder Apache mit PHP-FPM) auf den Ordner `public/`.
Ein Beispiel für nginx liegt in [docker/nginx/default.conf](docker/nginx/default.conf).
Für die PDF-Abrechnung muss `storage/` für den Webserver beschreibbar sein.

## Entwicklung

### Tests und Prüfungen

```bash
composer test                       # PHP-Tests (PHPUnit)
npm test                            # JavaScript-Tests
vendor/bin/pint --test              # Code-Stil PHP
composer audit && npm audit         # bekannte Sicherheitslücken

cd scripts/api-client               # Python-Client
python3 -m unittest discover -s tests -t .
flake8 .
```

### Mitwirken

Beiträge sind willkommen. Hinweise zu Code-Konventionen, Tests und Lizenz stehen in
[CONTRIBUTING.md](CONTRIBUTING.md), Sicherheitslücken bitte gemäß
[SECURITY.md](SECURITY.md) melden. Änderungen je Version stehen im
[CHANGELOG.md](CHANGELOG.md).

## Lizenz

Copyright (C) 2026 The Akki

Dieses Programm ist freie Software. Du kannst es unter den Bedingungen der GNU General
Public License, wie von der Free Software Foundation veröffentlicht, weitergeben und/oder
verändern, entweder gemäß Version 2 der Lizenz oder (nach deiner Wahl) jeder späteren
Version.

Die Veröffentlichung dieses Programms erfolgt in der Hoffnung, dass es dir von Nutzen sein
wird, aber OHNE IRGENDEINE GARANTIE, sogar ohne die implizite Garantie der MARKTREIFE oder
der VERWENDBARKEIT FÜR EINEN BESTIMMTEN ZWECK. Details findest du in der GNU General
Public License.

Den vollständigen Lizenztext (englisch, rechtlich maßgeblich) enthält die Datei
[LICENSE](LICENSE). SPDX-Kennung: `GPL-2.0-or-later`.

Die Anwendung nutzt Komponenten anderer Autoren unter eigenen Lizenzen, darunter mPDF
(GPL-2.0-only). Die Übersicht steht in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

**Hinweis:** Die Software ersetzt keine steuerliche oder rechtliche Beratung. Prüfe
Betriebskostenabrechnungen und Steuerübersichten vor der Verwendung.
