"""Beispiel: Der Administrator richtet zwei Benutzerkonten und eine gemeinsame Gruppe ein.

    HV_API_TOKEN=<Token des Administrators> python3 admin_einrichten.py beispiel/admin_daten.py

Passwörter stehen nie in dieser Datei: Mit HV_START_PASSWORD bekommen alle neuen
Benutzer dieses Startpasswort, sonst erzeugt das Script je Benutzer eines und gibt es
einmal aus.
"""


def einrichten(admin):
    anna = admin.user("Anna Beispiel", "anna.beispiel@example.com")
    bert = admin.user("Bert Beispiel", "bert.beispiel@example.com")

    # Gemeinsame Gruppe: Anna ist Eigentümerin, Bert darf alles sehen.
    admin.group("Erbengemeinschaft Beispiel", owner=anna, members=[(bert, "user")])
