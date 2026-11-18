# Sicherheit

Die Hausverwaltung verarbeitet personenbezogene und finanzielle Daten. Sicherheitslücken
nehmen wir deshalb ernst.

## Lücke melden

Bitte **kein öffentliches Issue** anlegen. Nutze stattdessen die private Meldung von
GitHub:

1. Im Repository den Reiter **Security** öffnen.
2. **Report a vulnerability** wählen
   (https://github.com/TheAkki/Hausverwaltung/security/advisories/new).
3. Beschreiben:
   - betroffene Version oder Commit;
   - Schritte zum Nachstellen;
   - mögliche Auswirkungen.

Du erhältst eine Rückmeldung, sobald die Meldung gesichtet ist. Nach der Behebung
veröffentlichen wir einen Hinweis und nennen dich auf Wunsch als Finder.

## Unterstützte Versionen

Sicherheitskorrekturen gibt es für die jeweils neueste veröffentlichte Version und den
Branch `main`.

## Hinweise für den Betrieb

- **HTTPS:** Betreibe die Anwendung nur über HTTPS. Hinter einem Reverse Proxy
  `SESSION_SECURE_COOKIE=true` setzen.
- **Administrator:** `ADMIN_EMAIL` auf die eigene Adresse setzen und das
  Administratorkonto sofort anlegen.
- **Kein Entwicklungs-Seeder:** `php artisan db:seed` ohne `--class` nicht auf einem
  erreichbaren Server ausführen. Er legt Testkonten mit bekannten Passwörtern an.
- **Updates:** Abhängigkeiten aktuell halten und regelmäßig `composer audit` und
  `npm audit` prüfen. Das Docker-Image warnt beim Bauen vor bekannten Lücken.
- **Sicherungen:** Das `storage`-Verzeichnis und die Datenbank regelmäßig sichern.
