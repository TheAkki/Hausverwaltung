# Hausverwaltung mit Docker betreiben

Das Image enthält Webserver (nginx), PHP-FPM und die fertig gebaute Oberfläche. Die
Datenbank ist wählbar: SQLite, MySQL/MariaDB oder PostgreSQL.

## Schnellstart

Das fertige Image `ghcr.io/theakki/hausverwaltung` gibt es für amd64 und arm64. Dafür
genügen zwei Dateien, das Repository muss nicht geklont werden:

```bash
mkdir hausverwaltung && cd hausverwaltung
curl -fsSLO https://raw.githubusercontent.com/TheAkki/Hausverwaltung/main/docker-compose.yml
curl -fsSL -o .env https://raw.githubusercontent.com/TheAkki/Hausverwaltung/main/.env.docker.example
# .env öffnen: mindestens ADMIN_EMAIL eintragen, Version und Datenbank wählen
docker compose up -d
```

Danach das Konto des Administrators anlegen:

```bash
docker compose exec -u www-data app php artisan hv:admin
```

Der Befehl legt das Konto für die Adresse aus `ADMIN_EMAIL` an und zeigt **einmalig** ein
zufälliges Startpasswort. Melde dich damit unter `APP_URL` an (Standard
http://localhost:8080). Beim ersten Login musst du ein eigenes Passwort vergeben.

## Administrator

| Aufruf | Wirkung |
|---|---|
| `hv:admin` | legt das Konto an, falls es fehlt; sonst ändert sich nichts |
| `hv:admin --reset-password` | neues Startpasswort für das vorhandene Konto; API-Tokens und Anmeldungen des Kontos werden beendet |

Immer mit `docker compose exec -u www-data app php artisan …` aufrufen.

- **Reservierte Adresse:** Die Adresse aus `ADMIN_EMAIL` kann sich niemand über
  **Registrieren** holen. Auch per Profil, Benutzerverwaltung oder API kann sie kein
  Konto bekommen. Der Administrator entsteht nur über diesen Befehl.
- **Passwort vergessen:** Ohne eingerichteten Mailversand hilft
  `hv:admin --reset-password`.
- **Andere Adresse:** Wird `ADMIN_EMAIL` später geändert, legt `hv:admin` ein Konto für
  die neue Adresse an. Das alte Konto bleibt als normaler Benutzer bestehen.

## Die Datei `.env`

Alle Einstellungen stehen in der Datei `.env` neben `docker-compose.yml`. Compose nutzt sie
auf zwei Wegen:

- **Compose selbst** liest daraus Port, Image-Version und Profil (`APP_PORT`,
  `HV_VERSION`, `COMPOSE_PROFILES`) und die Zugangsdaten für einen mitgelieferten
  Datenbank-Container.
- **Die Anwendung** bekommt alle Einträge als Umgebungsvariablen (`env_file`). Weitere
  Laravel-Einstellungen, etwa `MAIL_ENCRYPTION`, trägst du einfach dort ein. Die
  Compose-Datei muss dafür nicht geändert werden.

Nicht überschreiben lassen sich die Werte, die im Docker-Betrieb fest sind: `APP_ENV`
(production), `APP_DEBUG` (aus), Logs nach stderr sowie Sitzungen, Cache und Warteschlange
in der Datenbank.

**Port und Adresse:** Den Port änderst du nur bei `APP_PORT`. `APP_URL` übernimmt ihn
über `${APP_PORT}`:

```dotenv
APP_PORT=8181
APP_URL=http://msi-box:${APP_PORT}
```

Alle Links und Weiterleitungen entstehen aus `APP_URL` (`APP_FORCE_URL` ist im Image
fest eingeschaltet). Der Host muss deshalb der sein, den du im Browser eingibst: `localhost`
nur auf demselben Rechner, sonst Name oder IP des Servers. Hinter einem Reverse Proxy mit
HTTPS trägst du die öffentliche Adresse ohne Port ein (`https://hausverwaltung.example.org`).
`APP_PORT` bestimmt dann nur noch, wo der Proxy den Container erreicht. Hintergrund: nginx
in Debian reicht aus Sicherheitsgründen nur den Hostnamen ohne Port an PHP weiter.

Die Datei enthält Passwörter: nicht weitergeben und nicht einchecken. Werte ohne
Anführungszeichen schreiben, Kommentare immer in eigene Zeilen. Fehlt die Datei, bricht
`docker compose up` mit einer Meldung ab.

## Versionen und Updates

`HV_VERSION` in `.env` wählt das Image:

| Wert | Bedeutung |
|---|---|
| `v1.2.3` | genau diese Version (empfohlen) |
| `v1.2` bzw. `v1` | jeweils die neueste Version dieser Reihe |
| `latest` | die neueste veröffentlichte Version |
| `edge` | aktueller Entwicklungsstand von `main`, nur zum Testen |

Aktualisieren: Version in `.env` anpassen (oder bei `latest` unverändert lassen), dann:

```bash
docker compose pull
docker compose up -d
```

Die Migrationen laufen beim Start automatisch. Vorher eine Sicherung anlegen (siehe
unten).

## Image selbst bauen

Aus einem geklonten Repository, statt das Image herunterzuladen. Dort ist `.env` die
Datei der Entwicklungsumgebung, deshalb kommen die Docker-Einstellungen aus
`.env.docker`. Die Datei ist per `.gitignore` ausgeschlossen, und
[docker-compose.build.yml](../docker-compose.build.yml) lädt sie anstelle der `.env`:

```bash
cp .env.docker.example .env.docker      # bearbeiten
docker compose -f docker-compose.yml -f docker-compose.build.yml --env-file .env.docker up -d --build
```

## Datenbank wählen

Die Wahl trifft `DB_CONNECTION` in `.env`. Alle Varianten stehen auskommentiert
in [.env.docker.example](../.env.docker.example).

| Variante | `DB_CONNECTION` | Mitgelieferter Server | Weitere Angaben |
|---|---|---|---|
| SQLite (Standard) | `sqlite` | – | keine; Datei im `storage`-Volume |
| MariaDB | `mariadb` | `COMPOSE_PROFILES=mariadb`, `DB_HOST=mariadb` | `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| MySQL | `mysql` | – (eigener Server) | `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| PostgreSQL | `pgsql` | `COMPOSE_PROFILES=postgres`, `DB_HOST=postgres` | `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, optional `DB_SSLMODE` |

- **Eigener Datenbankserver:** `COMPOSE_PROFILES` leer lassen und `DB_HOST` (sowie bei
  Bedarf `DB_PORT`) auf den Server setzen. Datenbank und Benutzer müssen dort schon
  existieren.
- **Standardports:** 3306 für MySQL/MariaDB, 5432 für PostgreSQL.
- **Root-Konto:** Der mitgelieferte MariaDB-Container bekommt ein zufälliges
  Root-Passwort. Die Anwendung braucht es nicht, deshalb steht es auch nirgends in der
  `.env`.
- **Start:** Der Container wartet bis zu `DB_WAIT_TIMEOUT` Sekunden (Standard 60) auf
  den Server und führt dann die Migrationen aus.
- **Wechsel:** Ein Wechsel der Datenbank überträgt keine Daten. Dafür im alten System
  ein Backup exportieren und im neuen importieren.
- **SQLite-Schreibmodus:** Standard ist `DB_JOURNAL_MODE=wal` mit
  `DB_SYNCHRONOUS=normal`. SQLite schreibt dann nicht nach jeder Änderung synchron auf
  den Datenträger – das macht vor allem auf Mini-PCs mit eMMC- oder SD-Speicher einen
  großen Unterschied. Bei einem Stromausfall können die letzten Sekunden an Änderungen
  fehlen, die Datenbank bleibt aber intakt. Wer das nicht möchte, setzt
  `DB_SYNCHRONOUS=full`. Liegt die Datei auf einem Netzlaufwerk (NFS, SMB), muss
  `DB_JOURNAL_MODE=delete` gesetzt werden. Neben `database.sqlite` liegen im WAL-Modus
  zeitweise `database.sqlite-wal` und `database.sqlite-shm`; sie gehören zur Datenbank.
- **Transaktionen:** Jede ändernde Anfrage wird als Ganzes gespeichert. Scheitert sie,
  bleibt nichts halb gespeichert.

## Was beim Start passiert

1. **Prüfen:** Die Einstellungen werden geprüft. Bei einem Fehler bricht der Start mit
   einer Meldung ab (`docker compose logs app`), etwa wenn `ADMIN_EMAIL` fehlt, die
   Datenbank unbekannt ist oder Zugangsdaten fehlen.
2. **Schlüssel:** Ohne `APP_KEY` wird einmalig ein Schlüssel erzeugt und unter
   `storage/app/.app_key` im Volume gespeichert. Er bleibt beim Neuerstellen des
   Containers erhalten.
3. **Migrationen:** Die Datenbankstruktur wird angelegt oder aktualisiert.
4. **Referenzdaten:** Standardkategorien, Standardvorlage und Themes werden ergänzt.
   Vorhandenes bleibt unverändert.
5. **Caches:** Konfiguration, Routen und Views werden zwischengespeichert. Nach
   Änderungen an `.env` genügt deshalb: `docker compose up -d`.

## Daten und Sicherung

| Volume | Inhalt |
|---|---|
| `storage` | hochgeladene Belege, Kopfbilder, Mietvertragsdokumente, Schlüssel, SQLite-Datenbank |
| `db-data` | Daten des MariaDB-Containers |
| `postgres-data` | Daten des PostgreSQL-Containers |

Sichere das `storage`-Volume und die Datenbank regelmäßig, oder nutze den
Backup-Export der Anwendung. Eine SQLite-Datei bei laufendem Container nur zusammen mit
`database.sqlite-wal` kopieren, oder den Container vorher anhalten. Ohne das `storage`-Volume (und damit ohne Schlüssel)
lassen sich verschlüsselte Daten nicht mehr lesen.

## Sicherheit

- **Image:** Es enthält keine privaten Daten. `private-assets/`, `scripts/`, lokale
  Uploads, Datenbankdateien und `.env*` schließt [.dockerignore](../.dockerignore) aus.
- **PHP:** Nur `index.php` wird ausgeführt. Eine hochgeladene `.php`-Datei unter
  `/storage` liefert 404.
- **HTTPS:** Hinter einem Reverse Proxy mit HTTPS `SESSION_SECURE_COOKIE=true` setzen.
- **Sicherheitslücken:** Beim Bauen prüft `composer audit` die PHP-Pakete auf bekannte
  Lücken. Die veröffentlichten Images werden mit `FAIL_ON_AUDIT=true` gebaut: Bei einem
  Fund wird nichts veröffentlicht. Beim eigenen Bauen gibt es nur eine Warnung im
  Build-Log, es sei denn, du baust mit
  `docker compose -f docker-compose.yml -f docker-compose.build.yml build --build-arg FAIL_ON_AUDIT=true`.

## Einstellungen beim Bauen

| Build-Argument | Standard | Bedeutung |
|---|---|---|
| `PHP_VERSION` | `8.3` | PHP-Version des Basis-Images |
| `NODE_VERSION` | `22` | Node.js für den Frontend-Build |
| `DEBIAN_RELEASE` | `trixie` | Debian-Version des PHP-Images |
| `FAIL_ON_AUDIT` | `false` | Build bei bekannten Sicherheitslücken abbrechen |

Upload-Grenzen (20 MB) stehen in [php/hausverwaltung.ini](php/hausverwaltung.ini) und
[nginx/default.conf](nginx/default.conf) und müssen zueinander passen.
