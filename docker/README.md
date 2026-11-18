# Hausverwaltung mit Docker betreiben

Das Image enthält Webserver (nginx), PHP-FPM und die fertig gebaute Oberfläche. Die
Datenbank ist wählbar: SQLite, MySQL/MariaDB oder PostgreSQL.

## Schnellstart

Das fertige Image `ghcr.io/theakki/hausverwaltung` gibt es für amd64 und arm64. Dafür
genügen zwei Dateien, das Repository muss nicht geklont werden:

```bash
mkdir hausverwaltung && cd hausverwaltung
curl -fsSLO https://raw.githubusercontent.com/TheAkki/Hausverwaltung/main/docker-compose.yml
curl -fsSL -o .env.docker https://raw.githubusercontent.com/TheAkki/Hausverwaltung/main/.env.docker.example
# .env.docker öffnen: mindestens ADMIN_EMAIL eintragen, Version und Datenbank wählen
docker compose --env-file .env.docker up -d
```

Danach ist die Anwendung unter `APP_URL` erreichbar (Standard
http://localhost:8080). Registriere dich dort mit der Adresse aus `ADMIN_EMAIL`; dieses
Konto ist der Administrator.

`.env.docker` enthält Passwörter. Sie ist per `.gitignore` ausgeschlossen und darf nicht
eingecheckt werden. Die Datei `.env` im Projektordner bleibt der Entwicklung vorbehalten.
Deshalb immer `--env-file .env.docker` angeben.

## Versionen und Updates

`HV_VERSION` in `.env.docker` wählt das Image:

| Wert | Bedeutung |
|---|---|
| `v1.2.3` | genau diese Version (empfohlen) |
| `v1.2` bzw. `v1` | jeweils die neueste Version dieser Reihe |
| `latest` | die neueste veröffentlichte Version |
| `edge` | aktueller Entwicklungsstand von `main`, nur zum Testen |

Aktualisieren: Version in `.env.docker` anpassen (oder bei `latest` unverändert lassen),
dann:

```bash
docker compose --env-file .env.docker pull
docker compose --env-file .env.docker up -d
```

Die Migrationen laufen beim Start automatisch. Vorher eine Sicherung anlegen (siehe
unten).

## Image selbst bauen

Aus einem geklonten Repository, statt das Image herunterzuladen:

```bash
docker compose -f docker-compose.yml -f docker-compose.build.yml --env-file .env.docker up -d --build
```

## Datenbank wählen

Die Wahl trifft `DB_CONNECTION` in `.env.docker`. Alle Varianten stehen auskommentiert
in [.env.docker.example](../.env.docker.example).

| Variante | `DB_CONNECTION` | Mitgelieferter Server | Weitere Angaben |
|---|---|---|---|
| SQLite (Standard) | `sqlite` | – | keine; Datei im `storage`-Volume |
| MariaDB | `mariadb` | `COMPOSE_PROFILES=mariadb`, `DB_HOST=mariadb` | `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_ROOT_PASSWORD` |
| MySQL | `mysql` | – (eigener Server) | `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| PostgreSQL | `pgsql` | `COMPOSE_PROFILES=postgres`, `DB_HOST=postgres` | `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, optional `DB_SSLMODE` |

- **Eigener Datenbankserver:** `COMPOSE_PROFILES` leer lassen und `DB_HOST` (sowie bei
  Bedarf `DB_PORT`) auf den Server setzen. Datenbank und Benutzer müssen dort schon
  existieren.
- **Standardports:** 3306 für MySQL/MariaDB, 5432 für PostgreSQL.
- **Start:** Der Container wartet bis zu `DB_WAIT_TIMEOUT` Sekunden (Standard 60) auf
  den Server und führt dann die Migrationen aus.
- **Wechsel:** Ein Wechsel der Datenbank überträgt keine Daten. Dafür im alten System
  ein Backup exportieren und im neuen importieren.

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
   Änderungen an `.env.docker` genügt deshalb ein Neustart:
   `docker compose --env-file .env.docker up -d`.

## Daten und Sicherung

| Volume | Inhalt |
|---|---|
| `storage` | hochgeladene Belege, Kopfbilder, Mietvertragsdokumente, Schlüssel, SQLite-Datenbank |
| `db-data` | Daten des MariaDB-Containers |
| `postgres-data` | Daten des PostgreSQL-Containers |

Sichere das `storage`-Volume und die Datenbank regelmäßig, oder nutze den
Backup-Export der Anwendung. Ohne das `storage`-Volume (und damit ohne Schlüssel)
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
