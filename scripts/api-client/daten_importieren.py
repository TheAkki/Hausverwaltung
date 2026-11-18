#!/usr/bin/env python3
"""Anwender: die eigenen Daten über die REST-API importieren.

    HV_API_TOKEN=<eigener Token> python3 daten_importieren.py beispiel/meine_daten.py --dry-run

Die Datendatei enthält eine Funktion ``importieren(hv)``, siehe README.md.
"""

import sys

from hvapi.cli import USER, main

if __name__ == "__main__":
    sys.exit(main(USER, sys.argv[1:]))
