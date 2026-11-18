# Mitwirken

Danke für dein Interesse an der Hausverwaltung! Fehlerberichte, Vorschläge und Pull
Requests sind willkommen.

## Fehler melden und Vorschläge

- Lege ein [Issue](https://github.com/TheAkki/Hausverwaltung/issues) an.
- Beschreibe, was du erwartet hast, was stattdessen passiert ist und wie man es
  nachstellt.
- **Keine echten Daten:** Bitte keine echten Mieter-, Bank- oder Steuerdaten in Issues,
  Screenshots oder Testdateien. Verwende erfundene Beispiele.
- **Sicherheitslücken** bitte nicht öffentlich melden, sondern wie in
  [SECURITY.md](SECURITY.md) beschrieben.

## Pull Requests

1. Forke das Repository und lege einen Branch für deine Änderung an.
2. Halte dich an die Konventionen in [AGENTS.md](AGENTS.md). Sie gelten für Menschen wie
   für KI-Assistenten:
   - keine fest eingebauten Konstanten, sondern benannte Konstanten oder Konfiguration;
   - keine Secrets im Code;
   - kleine Funktionen mit je einer Aufgabe;
   - Eingaben des Anwenders immer prüfen.
3. Schreibe Tests für jede neue Funktion und jede Änderung. Ziel sind mindestens 95 %
   Abdeckung für neuen Code, einschließlich Rand- und Fehlerfällen.
4. Vor dem Pull Request muss alles grün sein:

   ```bash
   composer test
   npm test
   vendor/bin/pint --test
   ```

5. Trage nutzersichtbare Änderungen unter „Unveröffentlicht“ im
   [CHANGELOG.md](CHANGELOG.md) ein.

GitHub Actions führt die Tests für jeden Pull Request aus
([tests.yml](.github/workflows/tests.yml)). Docker-Images veröffentlicht nur der
Maintainer über Versions-Tags (siehe README, „Version veröffentlichen“).

## Lizenz deiner Beiträge

Die Hausverwaltung steht unter der GNU General Public License, Version 2 oder später
(`GPL-2.0-or-later`). Mit einem Pull Request stimmst du zu, dass dein Beitrag unter
derselben Lizenz veröffentlicht wird. Bitte reiche nur Code ein, den du selbst geschrieben
hast oder der unter einer GPL-2.0-kompatiblen Lizenz steht. Fremden Code kennzeichne bitte
mit Herkunft und Lizenz.

Neue Abhängigkeiten brauchen eine Begründung und eine GPL-2.0-kompatible Lizenz (z. B.
MIT, BSD, LGPL, GPL-2.0). Ergänze sie in
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
