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
- **Benutzer und Gruppen:** Benutzergruppen mit Rollen.
- **Backup (Web und API):** Der Export enthält alle sichtbaren Daten, also jede Gruppe, in
  der man Mitglied ist. Der Import spielt alle Daten wieder ein; Mitglieder und Rollen
  nur für Gruppen, in denen man Admin war, und nur für vorhandene Konten. Konten legt nur
  das Gesamt-Backup des Administrators an.
- **Administrator:** Konto für `ADMIN_EMAIL` per `php artisan hv:admin` mit zufälligem
  Startpasswort, `--reset-password` als Notfallzugang. Die Adresse ist für
  Registrierung, Profil und API gesperrt.
- **REST-API:** mit OpenAPI-Dokumentation (`/api-docs`) und Python-Client für den
  Datenimport. Gemeinsame Gruppen legt der Anwender dort selbst an
  (`hv.group(..., create=True)`).
  `GET /api/user` liefert die persönliche Gruppe mit (`personal_group`), der Client liest
  sie mit `hv.personal_group()` aus, statt ihren Namen zu erraten.
- **Oberfläche:** Deutsch und Englisch, Themes.
- **Docker:** Image mit wählbarer Datenbank (SQLite, MySQL/MariaDB, PostgreSQL),
  veröffentlicht als `ghcr.io/theakki/hausverwaltung` für amd64 und arm64.
- **GitHub Actions:** Tests bei jedem Pull Request; Versions-Tags bauen und
  veröffentlichen das Image automatisch.
