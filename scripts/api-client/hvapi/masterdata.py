"""Stammdaten einer Gruppe: Vermieter, Mieter, Rechnungssteller, Kategorien, Vorlagen."""

from .base import SEPARATOR, Base, without_none
from .report import EXISTING, UPDATED
from .session import FAILED, Record

TENANT_KEY_FIELDS = ("first_name", "last_name", "street", "postal_code", "city")
ISSUER_KEY_FIELDS = ("name", "postal_code", "city")
TEMPLATE_TEXT_FIELDS = ("content", "css_content", "header", "footer")
# Feld von GET /user mit der persönlichen Gruppe (fehlt bei Servern vor dieser Funktion).
PERSONAL_GROUP_FIELD = "personal_group"
PERSONAL_GROUP_LABEL = "persönliche Gruppe"


class MasterData(Base):
    def group(self, name: str, create: bool = False, description: str | None = None) -> Record:
        """Eine Gruppe, in der du Mitglied bist, z. B. "Gruppe von Anna" oder eine
        gemeinsame Gruppe.

        Mit ``create=True`` legst du die Gruppe selbst an, falls es sie noch nicht gibt;
        du bist dann Besitzer und Admin. Ohne den Schalter muss sie schon existieren
        (z. B. vom Administrator eingerichtet) – so erzeugt ein Tippfehler im Namen
        keine neue Gruppe."""
        if create:
            return self.session.ensure(
                "Gruppe", name, [],
                find=lambda: self._find_group(name),
                create=lambda: self.client.post("/groups", without_none({"name": name, "description": description})),
            )
        found = self._find_group(name)
        if found is None:
            self.session.report.problem("Gruppe", name, "nicht gefunden – selbst anlegen mit create=True "
                                                        "oder vom Administrator anlegen lassen.")
            return Record("Gruppe", name, state=FAILED)
        self.session.report.record(EXISTING, "Gruppe", name)
        return Record("Gruppe", name, found["id"], found)

    def personal_group(self) -> Record:
        """Deine persönliche Gruppe ("Gruppe von <Name>"). Sie entsteht automatisch mit
        deinem Konto; ihr Name wird von der API gelesen statt erraten – er ändert sich,
        wenn du oder die Gruppe umbenannt werdet."""
        me = self.session.get("/user") or {}
        if PERSONAL_GROUP_FIELD not in me:
            return self._personal_group_problem("der Server liefert sie nicht – er ist älter als dieses Skript. "
                                                "Bitte die Hausverwaltung auf dem Server aktualisieren.")
        group = me[PERSONAL_GROUP_FIELD]
        if not group:
            return self._personal_group_problem("nicht vorhanden – sie wurde gelöscht.")
        self.session.report.record(EXISTING, "Gruppe", group["name"])
        return Record("Gruppe", group["name"], group["id"], group)

    def _personal_group_problem(self, message: str) -> Record:
        self.session.report.problem("Gruppe", PERSONAL_GROUP_LABEL, message)
        return Record("Gruppe", PERSONAL_GROUP_LABEL, state=FAILED)

    def _find_group(self, name: str) -> dict | None:
        return next((g for g in self.session.get("/groups") or [] if g.get("name") == name), None)

    def group_landlord(self, group: Record, **fields) -> bool:
        """Vermieter der Gruppe (erscheint z. B. in der Betriebskostenabrechnung)."""
        return self.session.apply("Vermieter", group.label, [group],
                                  lambda: self.client.put(f"/groups/{group.id}/landlord", fields) and None)

    def tenant(self, group: Record, **fields) -> Record:
        """Mieter; wiedererkannt an Name und Anschrift."""
        key = {field: fields.get(field) for field in TENANT_KEY_FIELDS}
        return self.session.ensure(
            "Mieter", " ".join(str(v) for v in (fields.get("first_name"), fields.get("last_name")) if v), [group],
            find=lambda: self.first_match(self.session.cached("/tenants"), {"user_group_id": group.id, **key}),
            create=lambda: self._appended("/tenants", {**fields, "user_group_id": group.id}),
        )

    def invoice_issuer(self, group: Record, **fields) -> Record:
        """Rechnungssteller (Firma oder Person); wiedererkannt an Name, PLZ und Ort."""
        key = {field: fields.get(field) for field in ISSUER_KEY_FIELDS}
        return self.session.ensure(
            "Rechnungssteller", fields.get("name", ""), [group],
            find=lambda: self.first_match(self.session.cached("/invoice-issuers"), {"user_group_id": group.id, **key}),
            create=lambda: self._created("/invoice-issuers", {**fields, "user_group_id": group.id}),
        )

    def issuer_account(self, issuer: Record, customer_number: str, **fields) -> Record:
        """Kundenaccount beim Rechnungssteller (z. B. Strom und Wasser getrennt)."""
        return self.session.ensure(
            "Kundenaccount", f"{issuer.label} {customer_number}", [issuer],
            find=lambda: self.first_match(issuer.data.get("accounts") or [], {"customer_number": customer_number}),
            create=lambda: self.client.post("/invoice-issuer-accounts", {
                **fields, "customer_number": customer_number, "invoice_issuer_id": issuer.id}),
        )

    def category(self, group: Record, name: str, is_relevant_for_billing: bool = True, is_tax_relevant: bool = True) -> Record:
        """Eigene Kostenkategorie; Unterkategorien mit "Haupt::Unter" (die Hauptkategorie
        muss schon existieren; sie bestimmt Abrechnungs- und Steuerrelevanz)."""
        return self.session.ensure(
            "Kategorie", name, [group],
            find=lambda: self.first_match(self.session.cached("/cost-categories"), {"name": name, "user_group_id": group.id}),
            create=lambda: self._create_category(group, name, is_relevant_for_billing, is_tax_relevant),
        )

    def _create_category(self, group: Record, name: str, billing: bool, tax: bool) -> dict:
        parent, _, own = name.rpartition(SEPARATOR)
        body = {"user_group_id": group.id, "name": own, "is_relevant_for_billing": billing, "is_tax_relevant": tax}
        if parent:
            body["parent_id"] = self.category_id(parent, group.id)
        return self._created("/cost-categories", body)

    def billing_template(self, group: Record, name: str, header_image: str | None = None, **fields) -> Record:
        """Vorlage der Betriebskostenabrechnung, optional mit Kopfbild (Pfad zur Bilddatei).

        Gibt es die Vorlage schon, werden geänderte Texte (``content``, ``css_content``,
        ``header``, ``footer``) übernommen; das Kopfbild bleibt dann unverändert."""
        record = self.session.ensure(
            "Vorlage", name, [group],
            find=lambda: self.first_match(self.session.cached("/billing-templates"), {"name": name, "user_group_id": group.id}),
            create=lambda: self._create_template(group, name, header_image, fields),
        )
        if record.existed and self._template_changed(record, fields):
            self.session.apply("Vorlage", name, [group], lambda: self._update_template(record, group, fields))
        return record

    def _template_changed(self, template: Record, fields: dict) -> bool:
        return not self.matches(template.data, {field: fields[field] for field in TEMPLATE_TEXT_FIELDS if field in fields})

    def _update_template(self, template: Record, group: Record, fields: dict) -> str:
        current = {field: template.data.get(field) for field in TEMPLATE_TEXT_FIELDS}
        body = {**current, **fields, "name": template.label, "user_group_id": group.id}
        self.session.forget("/billing-templates")
        template.data.update(self.client.put(f"/billing-templates/{template.id}", body))
        return UPDATED

    def _create_template(self, group: Record, name: str, header_image: str | None, fields: dict) -> dict:
        body = {**fields, "name": name, "user_group_id": group.id}
        self.session.forget("/billing-templates")
        if header_image is None:
            return self.client.post("/billing-templates", body)
        return self.client.upload("/billing-templates", body, {"header_image": self.file(header_image)})
