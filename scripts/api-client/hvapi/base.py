"""Gemeinsame Hilfen der Anwender-Bibliothek."""

import os

from .session import DataError, Record, Session, same

SEPARATOR = "::"


class Base:
    def __init__(self, session: Session, base_dir: str = "."):
        self.session = session
        self.base_dir = base_dir

    @property
    def client(self):
        return self.session.client

    def file(self, path: str) -> tuple[str, bytes]:
        """(Dateiname, Inhalt) einer Datei relativ zur Datendatei."""
        full = path if os.path.isabs(path) else os.path.join(self.base_dir, path)
        if not os.path.isfile(full):
            raise DataError(f"Datei nicht gefunden: {full}")
        with open(full, "rb") as handle:
            return os.path.basename(full), handle.read()

    def _created(self, path: str, body: dict) -> dict:
        """Legt einen Datensatz an und verwirft die zwischengespeicherte Liste."""
        self.session.forget(path)
        return self.client.post(path, body)

    def _appended(self, path: str, body: dict) -> dict:
        """Legt einen Datensatz an und ergänzt ihn in der zwischengespeicherten Liste –
        für Listen, die nur nach Feldern des Datensatzes selbst durchsucht werden."""
        created = self.client.post(path, body)
        self.session.remember(path, created)
        return created

    @staticmethod
    def matches(candidate: dict, expected: dict) -> bool:
        return all(same(candidate.get(field), value) for field, value in expected.items())

    def first_match(self, items: list, expected: dict) -> dict | None:
        return next((item for item in items if self.matches(item, expected)), None)

    def category_id(self, name: str, group_id: int | None) -> int:
        """Kategorie nach (vollem) Namen: bevorzugt die der Gruppe ``group_id``, dann die
        systemweite, sonst eine aus einer anderen eigenen Gruppe (z. B. nachdem die
        Verwaltungsgruppe in eine gemeinsame Gruppe verschoben wurde)."""
        named = [c for c in self.session.cached("/cost-categories") if c.get("name") == name.strip()]
        for wanted in (group_id, None):
            match = next((c for c in named if c.get("user_group_id") == wanted), None)
            if match is not None:
                return match["id"]
        if named:
            return named[0]["id"]
        raise DataError(f"Kategorie {name!r} ist nicht vorhanden – zuerst mit category() anlegen.")


def management_group_of(record: Record) -> Record:
    """Die Verwaltungsgruppe einer Kostenstelle (sie selbst, wenn es eine ist)."""
    return record.context.get("management_group", record)


def costable_ref(record: Record) -> str:
    return f"{record.context['costable_type']}:{record.id}"


def without_none(values: dict) -> dict:
    return {key: value for key, value in values.items() if value is not None}
