"""Bibliothek für eigene Import-Scripte der Hausverwaltung.

Ein Anwender schreibt seine Daten als kleines Python-Script mit einer Funktion
``importieren(hv)``; ``hv`` ist ein :class:`Hausverwaltung`-Objekt. Der Administrator
schreibt ``einrichten(admin)`` mit einem :class:`Admin`-Objekt. Beispiele stehen im
Ordner ``beispiel/``.
"""

from .admin import Admin
from .invoices import Invoices
from .masterdata import MasterData
from .structure import Structure


class Hausverwaltung(MasterData, Structure, Invoices):
    """Alle Funktionen für den Import der eigenen Daten (Stammdaten, Verwaltungsstruktur,
    Rechnungen, Buchungen)."""


__all__ = ["Admin", "Hausverwaltung"]
