# KI-generiert
"""Gemeinsame Bausteine der Tests."""

import importlib.util
import os

from hvapi import Admin, Hausverwaltung
from hvapi.report import Report
from hvapi.session import Session

from .fake_api import FakeApi, FakeClient

HERE = os.path.dirname(os.path.abspath(__file__))
EXAMPLE_DIR = os.path.join(os.path.dirname(HERE), "beispiel")
START_PASSWORD = "Sicher-Start-1"


def load_example(name: str):
    spec = importlib.util.spec_from_file_location(f"beispiel_{name}", os.path.join(EXAMPLE_DIR, f"{name}.py"))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def session(api: FakeApi, dry_run: bool = False, lines: list | None = None) -> Session:
    lines = [] if lines is None else lines
    return Session(FakeClient(api), Report(lines.append), dry_run)


def run_admin(api: FakeApi, dry_run: bool = False, lines: list | None = None) -> Session:
    current = session(api, dry_run, lines)
    load_example("admin_daten").einrichten(Admin(current, START_PASSWORD, out=(lines or []).append))
    return current


def run_user(api: FakeApi, dry_run: bool = False, lines: list | None = None) -> Session:
    current = session(api, dry_run, lines)
    load_example("meine_daten").importieren(Hausverwaltung(current, EXAMPLE_DIR))
    return current
