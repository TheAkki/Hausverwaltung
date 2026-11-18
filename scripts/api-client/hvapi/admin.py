"""Einrichten durch den Administrator: Benutzerkonten, Gruppen und Mitglieder.

Nur der Administrator der Anwendung (ADMIN_EMAIL) darf Benutzer anlegen und Gruppen
für andere Benutzer einrichten. Alle Schritte sind wiederholbar: Vorhandenes wird
erkannt (Benutzer an der E-Mail, Gruppen am Namen und Eigentümer).
"""

import secrets

from .report import CREATED, EXISTING, UPDATED
from .session import Record, Session

ROLE_ADMIN = "admin"
ROLE_USER = "user"
ROLES = (ROLE_ADMIN, ROLE_USER)
PASSWORD_BYTES = 12


class Admin:
    def __init__(self, session: Session, start_password: str = "", out=print):
        self.session = session
        self.start_password = start_password
        self.out = out

    def user(self, name: str, email: str) -> Record:
        """Benutzerkonto; legt dabei automatisch die Gruppe "Gruppe von <Name>" an."""
        email = email.strip().lower()
        return self.session.ensure(
            "Benutzer", email, [],
            find=lambda: next(iter(self.session.list("/users", {"email": email})), None),
            create=lambda: self._create_user(name, email),
        )

    def _create_user(self, name: str, email: str) -> dict:
        password = self.start_password or secrets.token_urlsafe(PASSWORD_BYTES)
        created = self.session.client.post("/users", {
            "name": name, "email": email, "password": password, "password_confirmation": password,
        })
        if not self.start_password:
            self.out(f"Startpasswort für {email}: {password}")
        return created

    def group(self, name: str, owner: Record, members=(), description: str | None = None) -> Record:
        """Gruppe von ``owner`` (Eigentümer und Admin) mit weiteren Mitgliedern.

        ``members`` ist eine Liste von (Benutzer, Rolle) mit Rolle "admin" oder "user".
        Die persönliche Gruppe "Gruppe von <Name>" existiert bereits und wird nur ergänzt.
        """
        group = self.session.ensure(
            "Gruppe", name, [owner],
            find=lambda: self._find_group(name, owner),
            create=lambda: self.session.client.post("/groups", {"name": name, "description": description, "owner_id": owner.id}),
        )
        for user, role in members:
            self.member(group, user, role)
        return group

    def _find_group(self, name: str, owner: Record) -> dict | None:
        groups = self.session.get(f"/users/{owner.id}").get("groups") or []
        return next((g for g in groups if g.get("name") == name and g.get("owner_id") == owner.id), None)

    def member(self, group: Record, user: Record, role: str = ROLE_USER) -> bool:
        if role not in ROLES:
            raise ValueError(f"Unbekannte Rolle {role!r}; erlaubt sind {', '.join(ROLES)}.")
        return self.session.apply(
            "Mitglied", f"{group.label}: {user.label} ({role})", [group, user],
            lambda: self._set_member(group, user, role),
        )

    def _set_member(self, group: Record, user: Record, role: str) -> str:
        members = self.session.get(f"/groups/{group.id}").get("users") or []
        current = {u["id"]: (u.get("pivot") or {}).get("role") for u in members}
        outcome = EXISTING
        if user.id not in current:
            self.session.client.post(f"/groups/{group.id}/members", {"email": user.label})
            current[user.id], outcome = ROLE_USER, CREATED
        if current[user.id] != role:
            self.session.client.patch(f"/groups/{group.id}/members/{user.id}", {"role": role})
            outcome = UPDATED if outcome == EXISTING else outcome
        return outcome
