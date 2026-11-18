# Changelog

Alle nutzersichtbaren Änderungen der Hausverwaltung. Das Format folgt
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung
[Semantic Versioning](https://semver.org/lang/de/).

## [Unveröffentlicht]

Erste öffentliche Version unter der GNU General Public License, Version 2 oder später.

### Enthalten

- **Struktur:** Verwaltungsgruppen, Häuser, Mieteinheiten und Abrechnungszeiträume.
- **Mieter und Mietverträge:** Mietintervalle, gekoppelte Verträge, Vertragsdokumente
  und Zuordnung der Mieteingänge.
- **Rechnungen:** einmalig und wiederkehrend, mit Kostenkategorien, Betragsintervallen,
  Belegen, Buchungen und Prognose künftiger Beträge.
- **Konten und Verträge:** Kontensystem mit Umbuchungen, Wartungsverträge.
- **Übersichten:** offene Zahlungen, Ausgaben je Abrechnungszeitraum, Konten- und
  Steuerübersicht.
- **Betriebskostenabrechnung:** als PDF mit frei gestaltbaren Vorlagen, mit Leerständen,
  Nachzahlungen und Erstattungen.
- **Benutzer und Gruppen:** Benutzergruppen mit Rollen, Backup-Export und -Import.
- **Administrator:** Konto für `ADMIN_EMAIL` per `php artisan hv:admin` mit zufälligem
  Startpasswort, `--reset-password` als Notfallzugang. Die Adresse ist für
  Registrierung, Profil und API gesperrt.
- **REST-API:** mit OpenAPI-Dokumentation (`/api-docs`) und Python-Client für den
  Datenimport.
- **Oberfläche:** Deutsch und Englisch, Themes.
- **Docker:** Image mit wählbarer Datenbank (SQLite, MySQL/MariaDB, PostgreSQL),
  veröffentlicht als `ghcr.io/theakki/hausverwaltung` für amd64 und arm64.
- **GitHub Actions:** Tests bei jedem Pull Request; Versions-Tags bauen und
  veröffentlichen das Image automatisch.
