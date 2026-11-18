"""Gemeinsame Grundlage aller Importe: Wiederfinden oder Anlegen, Trockenlauf, Bericht.

Jeder Datensatz wird zuerst über seine fachlichen Merkmale gesucht (z. B. ein Mieter
über Name und Anschrift). Nur wenn er fehlt, wird er angelegt – ein zweiter Lauf
ergänzt also nur Neues. Im Trockenlauf wird nur gelesen; was angelegt würde, steht
im Bericht. Schlägt ein Datensatz fehl, werden die davon abhängigen übersprungen.
"""

from dataclasses import dataclass, field

from .client import ApiError
from .report import CREATED, EXISTING, PLANNED, SKIPPED, UPDATED, Report

READY = "bereit"
PLANNED_STATE = "geplant"
FAILED = "fehlgeschlagen"


class DataError(Exception):
    """Fehler in den Daten des Anwenders (z. B. unbekannte Kategorie, fehlende Datei)."""


@dataclass
class Record:
    """Verweis auf einen Datensatz der API; ``id`` fehlt, solange er nur geplant ist
    oder nicht angelegt werden konnte."""

    kind: str
    label: str
    id: int | None = None
    data: dict = field(default_factory=dict)
    state: str = READY
    context: dict = field(default_factory=dict)
    existed: bool = False

    @property
    def ready(self) -> bool:
        return self.id is not None


class Session:
    def __init__(self, client, report: Report, dry_run: bool = False):
        self.client = client
        self.report = report
        self.dry_run = dry_run
        self._cache: dict[str, list] = {}

    # --- Lesen ----------------------------------------------------------------

    def get(self, path: str, params: dict | None = None):
        return self.client.get(path, params)

    def list(self, path: str, params: dict | None = None) -> list:
        """Alle Einträge einer Liste, auch über mehrere Seiten; ein einzelnes Objekt
        (Detailansicht) ergibt eine Liste mit diesem Objekt."""
        page, items = 1, []
        while True:
            response = self.client.get(path, {**(params or {}), "page": page} if page > 1 else params)
            if isinstance(response, list):
                return response
            if not isinstance(response, dict):
                return []
            if "data" not in response:
                return [response]
            items += response["data"]
            if page >= int(response.get("last_page") or 1):
                return items
            page += 1

    def cached(self, path: str, params: dict | None = None) -> list:
        key = f"{path}?{sorted((params or {}).items())}"
        if key not in self._cache:
            self._cache[key] = self.list(path, params)
        return self._cache[key]

    def forget(self, path: str) -> None:
        self._cache = {key: value for key, value in self._cache.items() if not key.startswith(f"{path}?")}

    # --- Schreiben ------------------------------------------------------------

    def ensure(self, kind: str, label: str, parents, find, create, context: dict | None = None) -> Record:
        """Findet einen Datensatz oder legt ihn an.

        ``find`` liefert den vorhandenen Datensatz (dict) oder None, ``create`` legt ihn an
        und liefert die Antwort der API.
        """
        blocked = self._blocked(kind, label, parents)
        if blocked is not None:
            return Record(kind, label, state=blocked, context=context or {})
        try:
            found = find()
            if found is not None:
                self.report.record(EXISTING, kind, label)
                return Record(kind, label, found["id"], found, context=context or {}, existed=True)
            if self.dry_run:
                self.report.record(PLANNED, kind, label)
                return Record(kind, label, state=PLANNED_STATE, context=context or {})
            created = create()
        except (ApiError, DataError) as failure:
            self.report.problem(kind, label, describe(failure))
            return Record(kind, label, state=FAILED, context=context or {})
        self.report.record(CREATED, kind, label)
        return Record(kind, label, created["id"], created, context=context or {})

    def apply(self, kind: str, label: str, parents, action) -> bool:
        """Führt eine Änderung aus (z. B. Buchen). Liefert, ob sie ausgeführt wurde."""
        if self._blocked(kind, label, parents) is not None:
            return False
        if self.dry_run:
            self.report.record(PLANNED, kind, label)
            return False
        try:
            outcome = action()
        except (ApiError, DataError) as failure:
            self.report.problem(kind, label, describe(failure))
            return False
        self.report.record(outcome or UPDATED, kind, label)
        return True

    def _blocked(self, kind: str, label: str, parents) -> str | None:
        missing = [parent for parent in parents if parent is not None and not parent.ready]
        if not missing:
            return None
        if any(parent.state == FAILED for parent in missing):
            self.report.record(SKIPPED, kind, label)
            return FAILED
        self.report.record(PLANNED, kind, label)
        return PLANNED_STATE


def describe(failure: Exception) -> str:
    return failure.summary() if isinstance(failure, ApiError) else str(failure)


def same(value, expected) -> bool:
    """Vergleich wie die API speichert: Leerzeichen am Rand entfallen, "" wird None,
    Zahlen werden numerisch verglichen."""
    return normalize(value) == normalize(expected)


def normalize(value):
    if value is None or isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        return round(float(value), 2)
    if not isinstance(value, str):
        return value
    stripped = value.strip()
    if stripped == "":
        return None
    try:
        return round(float(stripped), 2)
    except ValueError:
        return stripped


def date_only(value):
    """"2026-01-05T00:00:00.000000Z" -> "2026-01-05"."""
    return value[:10] if isinstance(value, str) else value
