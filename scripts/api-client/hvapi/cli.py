"""Kommandozeile für ``admin_einrichten.py`` und ``daten_importieren.py``.

Zugangsdaten kommen aus Umgebungsvariablen, nie aus den Datendateien:

- ``HV_API_URL``: Adresse der API, Standard http://127.0.0.1:8000/api
- ``HV_API_TOKEN``: persönlicher Token (Profil → API-Zugang), empfohlen
- ``HV_API_EMAIL`` und ``HV_API_PASSWORD``: alternativ zum Token
- ``HV_START_PASSWORD``: Startpasswort neuer Benutzer (nur Administrator); ohne wird
  je Benutzer ein zufälliges erzeugt und einmal ausgegeben
"""

import argparse
import importlib.util
import os
from urllib.error import URLError

from .admin import Admin
from .client import ApiClient, ApiError
from .report import Report
from .session import DataError, Session

DEFAULT_URL = "http://127.0.0.1:8000/api"
ENV_URL = "HV_API_URL"
ENV_TOKEN = "HV_API_TOKEN"
ENV_EMAIL = "HV_API_EMAIL"
ENV_PASSWORD = "HV_API_PASSWORD"
ENV_START_PASSWORD = "HV_START_PASSWORD"

EXIT_OK = 0
EXIT_PROBLEMS = 1
EXIT_ERROR = 2

ADMIN = "einrichten"
USER = "importieren"


class SetupError(Exception):
    """Fehlende Zugangsdaten oder unbrauchbare Datendatei."""


def parse_args(argv, description: str):
    parser = argparse.ArgumentParser(description=description)
    parser.add_argument("datei", help="Python-Datei mit den Daten")
    parser.add_argument("--dry-run", action="store_true", help="nur prüfen und anzeigen, nichts ändern")
    parser.add_argument("--url", help=f"Adresse der API (sonst {ENV_URL} oder {DEFAULT_URL})")
    parser.add_argument("--verbose", action="store_true", help="auch Vorhandenes einzeln auflisten")
    return parser.parse_args(argv)


def load_function(path: str, name: str):
    if not os.path.isfile(path):
        raise SetupError(f"Datendatei nicht gefunden: {path}")
    spec = importlib.util.spec_from_file_location("hv_daten", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    function = getattr(module, name, None)
    if not callable(function):
        raise SetupError(f"{path} enthält keine Funktion {name}(…).")
    return function


def connect(url: str, environ, client_factory=ApiClient):
    """Liefert (Client, angemeldet_per_Passwort)."""
    client = client_factory(url)
    if environ.get(ENV_TOKEN):
        client.token = environ[ENV_TOKEN]
        return client, False
    if environ.get(ENV_EMAIL) and environ.get(ENV_PASSWORD):
        return client.login(environ[ENV_EMAIL], environ[ENV_PASSWORD]), True
    raise SetupError(f"Bitte {ENV_TOKEN} setzen (Profil → API-Zugang) oder {ENV_EMAIL} und {ENV_PASSWORD}.")


def build_target(kind: str, session: Session, data_path: str, environ, out):
    if kind == ADMIN:
        return Admin(session, environ.get(ENV_START_PASSWORD, ""), out)
    from . import Hausverwaltung
    return Hausverwaltung(session, os.path.dirname(os.path.abspath(data_path)))


def main(kind: str, argv=None, out=print, environ=None, client_factory=ApiClient) -> int:
    environ = os.environ if environ is None else environ
    args = parse_args(argv, "Administrator: Benutzer und Gruppen einrichten" if kind == ADMIN else "Eigene Daten importieren")
    report = Report(out, args.verbose)
    try:
        function = load_function(args.datei, kind)
        client, logged_in = connect(args.url or environ.get(ENV_URL) or DEFAULT_URL, environ, client_factory)
        if args.dry_run:
            out("Trockenlauf: es wird nichts geändert.")
        function(build_target(kind, Session(client, report, args.dry_run), args.datei, environ, out))
        if logged_in:
            client.logout()
    except (SetupError, DataError) as setup_error:
        out(f"Fehler: {setup_error}")
        return EXIT_ERROR
    except ApiError as api_error:
        out(f"Fehler der API: {api_error}")
        return EXIT_ERROR
    except URLError as url_error:
        out(f"Keine Verbindung zur API: {url_error.reason}")
        return EXIT_ERROR
    for line in report.summary():
        out(line)
    return EXIT_PROBLEMS if report.problems else EXIT_OK
