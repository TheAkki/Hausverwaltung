"""Was ein Lauf getan hat: angelegt, schon vorhanden, geändert, geplant (Trockenlauf), Probleme."""

from collections import Counter
from dataclasses import dataclass

CREATED = "angelegt"
EXISTING = "vorhanden"
UPDATED = "aktualisiert"
PLANNED = "geplant"
SKIPPED = "übersprungen"
OUTCOMES = (CREATED, EXISTING, UPDATED, PLANNED, SKIPPED)


@dataclass
class Problem:
    kind: str
    label: str
    message: str

    def __str__(self) -> str:
        return f"{self.kind} [{self.label}]: {self.message}"


class Report:
    def __init__(self, out=print, verbose: bool = False):
        self.out = out
        self.verbose = verbose
        self.counts = Counter()
        self.problems: list[Problem] = []

    def record(self, outcome: str, kind: str, label: str) -> None:
        self.counts[outcome] += 1
        if self.verbose or outcome in (CREATED, UPDATED, PLANNED):
            self.out(f"{outcome:>12}  {kind}: {label}")

    def problem(self, kind: str, label: str, message: str) -> None:
        self.problems.append(Problem(kind, label, message))
        self.out(f"{'Problem':>12}  {kind}: {label} – {message}")

    def summary(self) -> list[str]:
        totals = ", ".join(f"{self.counts[outcome]} {outcome}" for outcome in OUTCOMES if self.counts[outcome])
        lines = [f"Ergebnis: {totals or 'nichts zu tun'}."]
        if self.problems:
            lines.append(f"{len(self.problems)} Problem(e):")
            lines += [f"  - {problem}" for problem in self.problems]
        return lines
