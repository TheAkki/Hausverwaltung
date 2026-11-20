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
  Belegen, Buchungen und Prognose künftiger Beträge. Die Betragsintervalle werden an der
  Rechnung gespeichert.
- **Belege** auch für Forderungen (Nachzahlung/Rückzahlung) und Umbuchungen – in
  Weboberfläche, API, Python-Client und Backup.
- **Konten und Verträge:** Kontensystem mit Umbuchungen, Wartungsverträge.
- **Übersichten:** offene Zahlungen, nicht zugeordnete Buchungen (Termine, deren
  Kostenzeilen nicht den Rechnungsbetrag ergeben), Ausgaben je Abrechnungszeitraum,
  Konten- und Steuerübersicht.
- **Betriebskostenabrechnung:** als PDF mit frei gestaltbaren Vorlagen, mit Leerständen,
  Nachzahlungen und Erstattungen.
- **Benutzer und Gruppen:** Benutzergruppen mit Rollen. Mieter, Rechnungssteller und
  eigene Kategorien gehören zur Gruppe der Verwaltungsgruppe, in der sie verwendet
  werden; Weboberfläche, API und Seeder lehnen eine Mischung ab, damit eine gemeinsame
  Gruppe keine Daten der persönlichen Gruppe sieht. Wechselt eine Verwaltungsgruppe die
  Gruppe, ziehen ihre Stammdaten mit um (mehrfach verwendete werden kopiert).
  Rechnungssteller lassen sich samt Kundenaccounts in eine andere Gruppe kopieren. Im
  Seed stehen sie dafür unter `Group-*`. Die Detailseiten von Mietern und
  Rechnungsstellern zeigen nur Mietverträge und Rechnungen, die man sehen darf.
  Abrechnungsintervall, Konto, Kundenaccount und Standardvorlage müssen zur
  Verwaltungsgruppe passen; eine fremde Id wird abgelehnt. Kategorienamen sind je Gruppe
  eindeutig.
- **Dateien geschützt:** Belege, Mietvertragsdokumente und Kopfbilder liegen nicht mehr
  öffentlich unter `/storage`, sondern werden nur an Berechtigte ausgeliefert; vorhandene
  Dateien zieht die Migration um.
- **Bedienungsanleitung** mit Screenshots aus Beispieldaten (`php artisan docs:screenshots`).
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
