# AGENTS.md

## Code-Konventionen
- Keine Hardcodierung von Konstanten: Magic Numbers, Strings, Pfade, URLs und
  Schwellwerte gehören in benannte Konstanten oder Konfiguration
  (z. B. `config/`, Umgebungsvariablen). Ausnahme: 0, 1, "" in offensichtlichem Kontext.
- Keine Secrets im Code; nur über Umgebungsvariablen oder Secret-Store.
- Funktionen klein halten, eine Aufgabe pro Funktion.
- Programmiere defensiv. Überprüfe alle Eingaben des Anwenders.
- Scanne bekannte Sicherheitslücken und warne entsprechend

## Tests
- Jede neue Funktion/Änderung braucht Unit-Tests.
- Zielabdeckung: mindestens 95 % für neuen Code; Randfälle und Fehlerpfade testen.
- Vor Abschluss: Tests und Linter müssen grün sein.
- Markiere die erstellten Tests mit "KI-generiert" als Kommentar

## Arbeitsweise
- Bestehende Projektstruktur und Namensgebung beibehalten.
- Keine neuen Abhängigkeiten ohne Begründung.
- Bei Unklarheiten nachfragen statt zu raten.
- Zuerst Planen und Nachfragen, danach ausführen und wenig unterbrechen
- Im Unterordner private-assets keine Änderungen vornehmen.
- Wenn aus Testdateien oder Seeddateien persönliche Daten enthalten sind dürfen diese nur anonymisiert in Tests oder Kommentare verwendet werden.
- Checke nicht selbstständig in Git ein

## Lizensierung
- Bereite die Dateien darauf vor das Projekt unter einer neuen GPL auf GitHub zur Verfügung zu stellen. Das beinhaltet auch eine Readme.md mit einer Anleitung.
- Achte auf Lizenzkonflikte