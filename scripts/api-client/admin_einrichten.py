#!/usr/bin/env python3
"""Administrator: Benutzerkonten und Gruppen einrichten.

    HV_API_TOKEN=<Token des Administrators> python3 admin_einrichten.py beispiel/admin_daten.py --dry-run

Die Datendatei enthält eine Funktion ``einrichten(admin)``, siehe README.md.
"""

import sys

from hvapi.cli import ADMIN, main

if __name__ == "__main__":
    sys.exit(main(ADMIN, sys.argv[1:]))
