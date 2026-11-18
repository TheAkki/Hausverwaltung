"""Verwaltungsstruktur: Verwaltungsgruppen, Abrechnungsintervalle, Konten,
Abrechnungsschlüssel, Verwaltungs- und Mieteinheiten, Mietverträge, Forderungen und
Umbuchungen."""

from .base import Base, management_group_of
from .report import EXISTING, UPDATED
from .session import Record, date_only, same

LANDLORD_SCOPE_OVERRIDE = "override"
LEASE_INTERVAL_FIELDS = ("start_date", "end_date", "rent_amount", "occupant_count", "utility_advance_payment", "notes")


class Structure(Base):
    def management_group(self, group: Record, description: str, kontosystem_enabled: bool = False) -> Record:
        """Verwaltungsgruppe; wiedererkannt an der Beschreibung in allen eigenen Gruppen."""
        return self.session.ensure(
            "Verwaltungsgruppe", description, [group],
            find=lambda: self.first_match(self.session.cached("/management-groups"), {"description": description}),
            create=lambda: self._created("/management-groups", {
                "description": description, "user_group_id": group.id, "kontosystem_enabled": kontosystem_enabled}),
            context={"costable_type": "management_group"},
        )

    def management_group_landlord(self, management_group: Record, **fields) -> bool:
        """Abweichender Vermieter nur für diese Verwaltungsgruppe."""
        return self.session.apply(
            "Vermieter", management_group.label, [management_group],
            lambda: self.client.put(f"/management-groups/{management_group.id}/landlord",
                                    {**fields, "scope": LANDLORD_SCOPE_OVERRIDE}) and None)

    def move_management_group(self, management_group: Record, group: Record,
                              default_billing_template: Record | None = None) -> bool:
        """Verwaltungsgruppe in eine (gemeinsame) Gruppe verschieben und ihre
        Standardvorlage setzen."""
        return self.session.apply(
            "Verwaltungsgruppe verschieben", f"{management_group.label} → {group.label}",
            [management_group, group, default_billing_template],
            lambda: self._move(management_group, group, default_billing_template))

    def _move(self, management_group: Record, group: Record, template: Record | None) -> str:
        current = self.session.get(f"/management-groups/{management_group.id}")
        target = {"user_group_id": group.id, "default_billing_template_id": template.id if template else None}
        if self.matches(current, target):
            return EXISTING
        self.client.put(f"/management-groups/{management_group.id}", {
            "description": current["description"], "kontosystem_enabled": bool(current.get("kontosystem_enabled")), **target})
        management_group.data.update(target)
        return UPDATED

    def billing_interval(self, management_group: Record, start_date: str, end_date: str,
                         description: str | None = None) -> Record:
        """Abrechnungsintervall (meist ein Kalenderjahr)."""
        path = f"/management-groups/{management_group.id}/billing-intervals"
        return self.session.ensure(
            "Abrechnungsintervall", f"{management_group.label} {start_date}–{end_date}", [management_group],
            find=lambda: next((i for i in self.session.cached(path)
                               if date_only(i["start_date"]) == start_date and date_only(i["end_date"]) == end_date), None),
            create=lambda: self._created_in(path, "/billing-intervals", {
                "management_group_id": management_group.id, "start_date": start_date, "end_date": end_date,
                "description": description}),
        )

    def account(self, management_group: Record, name: str, **fields) -> Record:
        """Konto der Verwaltungsgruppe (Kontensystem), z. B. Girokonto oder Rücklage."""
        return self.session.ensure(
            "Konto", f"{management_group.label}: {name}", [management_group],
            find=lambda: self.first_match(self._details(management_group).get("accounts") or [], {"name": name}),
            create=lambda: self._created_in(f"/management-groups/{management_group.id}", "/accounts", {
                **fields, "name": name, "management_group_id": management_group.id}),
        )

    def allocation_key(self, management_group: Record, category: str, allocation_key: str) -> bool:
        """Abrechnungsschlüssel einer Kategorie in dieser Verwaltungsgruppe
        (person_count, area, individual oder rental_unit)."""
        return self.session.apply(
            "Abrechnungsschlüssel", f"{management_group.label}: {category} = {allocation_key}", [management_group],
            lambda: self.client.put(
                f"/management-groups/{management_group.id}/cost-categories/"
                f"{self.category_id(category, management_group.data.get('user_group_id'))}",
                {"allocation_key": allocation_key}) and None)

    def management_unit(self, management_group: Record, street: str, postal_code: str, city: str,
                        description: str | None = None) -> Record:
        """Verwaltungseinheit (Haus); wiedererkannt an der Anschrift."""
        key = {"management_group_id": management_group.id, "street": street, "postal_code": postal_code, "city": city}
        return self.session.ensure(
            "Verwaltungseinheit", f"{street}, {city}", [management_group],
            find=lambda: self.first_match(self.session.cached("/management-units"), key),
            create=lambda: self._created("/management-units", {**key, "description": description}),
            context={"costable_type": "management_unit", "management_group": management_group},
        )

    def rental_unit(self, management_unit: Record, type: str, description: str | None = None, **fields) -> Record:
        """Mieteinheit (Wohnung, Garage, Stellplatz); wiedererkannt an Typ und Beschreibung."""
        key = {"management_unit_id": management_unit.id, "type": type, "description": description}
        return self.session.ensure(
            "Mieteinheit", f"{management_unit.label}: {type} {description or ''}".strip(), [management_unit],
            find=lambda: self.first_match(self.session.cached("/rental-units"), key),
            create=lambda: self._created("/rental-units", {**fields, **key}),
            context={"costable_type": "rental_unit", "management_group": management_group_of(management_unit)},
        )

    def lease(self, rental_unit: Record, start_date: str, tenants=(), coupled_to: Record | None = None,
              intervals=(), **fields) -> Record:
        """Mietvertrag mit Mietern oder – für eine nicht abrechnungsrelevante Mieteinheit –
        gekoppelt an einen anderen Mietvertrag. ``intervals`` sind die Mietintervalle
        (Kaltmiete, Vorauszahlung, Personen ab einem Datum); das erste legt die
        Anwendung selbst an, es wird auf die angegebenen Werte gesetzt."""
        lease = self.session.ensure(
            "Mietvertrag", f"{rental_unit.label} ab {start_date}", [rental_unit, coupled_to, *tenants],
            find=lambda: self._find_lease(rental_unit, start_date),
            create=lambda: self._created("/leases", self._lease_body(rental_unit, start_date, tenants, coupled_to, fields)),
            context={"costable_type": "lease", "management_group": management_group_of(rental_unit)},
        )
        if intervals:
            self.session.apply("Mietintervalle", lease.label, [lease], lambda: self._sync_intervals(lease, list(intervals)))
        return lease

    def _find_lease(self, rental_unit: Record, start_date: str) -> dict | None:
        return next((lease for lease in self.session.cached("/leases", {"show_expired": True})
                     if lease.get("rental_unit_id") == rental_unit.id and date_only(lease.get("start_date")) == start_date), None)

    @staticmethod
    def _lease_body(rental_unit: Record, start_date: str, tenants, coupled_to: Record | None, fields: dict) -> dict:
        body = {**fields, "rental_unit_id": rental_unit.id, "start_date": start_date}
        if coupled_to is not None:
            return {**body, "assignment_mode": "reference", "coupled_to_lease_id": coupled_to.id}
        return {**body, "assignment_mode": "tenants", "tenant_ids": [tenant.id for tenant in tenants]}

    def _sync_intervals(self, lease: Record, wanted: list) -> str:
        """Fehlende Intervalle anhängen und danach alle auf die gewünschten Werte setzen
        (beim Anhängen kürzt die Anwendung das vorige Intervall)."""
        existing = {date_only(i["start_date"]): i for i in self.session.get(f"/leases/{lease.id}").get("lease_intervals") or []}
        outcome = EXISTING
        for interval in sorted(wanted, key=lambda item: item["start_date"]):
            if interval["start_date"] not in existing:
                existing[interval["start_date"]] = self.client.post("/lease-intervals", {**interval, "lease_id": lease.id})
                outcome = UPDATED
        for interval in wanted:
            current = existing[interval["start_date"]]
            if not all(same(date_only(current.get(field)), interval.get(field)) for field in LEASE_INTERVAL_FIELDS):
                values = {field: interval.get(field) for field in LEASE_INTERVAL_FIELDS}
                self.client.put(f"/lease-intervals/{current['id']}", values)
                outcome = UPDATED
        return outcome

    def settlement_claim(self, lease: Record, billing_interval: Record, date: str, amount: float, **fields) -> Record:
        """Forderung aus der Betriebskostenabrechnung (positiv = Nachzahlung)."""
        return self.session.ensure(
            "Forderung", f"{lease.label}: {date}", [lease, billing_interval],
            find=lambda: self._find_claim(lease, billing_interval, date),
            create=lambda: self.client.post("/settlement-claims", {
                **fields, "lease_id": lease.id, "billing_interval_id": billing_interval.id, "date": date, "amount": amount}),
        )

    def _find_claim(self, lease: Record, billing_interval: Record, date: str) -> dict | None:
        claims = self.session.get(f"/leases/{lease.id}").get("settlement_claims") or []
        return next((claim for claim in claims
                     if claim.get("billing_interval_id") == billing_interval.id and date_only(claim.get("date")) == date), None)

    def transfer(self, management_group: Record, source: Record, target: Record, transfer_date: str, amount: float,
                 comment: str | None = None) -> Record:
        """Umbuchung zwischen zwei Konten derselben Verwaltungsgruppe."""
        return self.session.ensure(
            "Umbuchung", f"{source.label} → {target.label} {transfer_date}", [management_group, source, target],
            find=lambda: self._find_transfer(management_group, source, target, transfer_date, amount),
            create=lambda: self._created_in(f"/management-groups/{management_group.id}", "/account-transfers", {
                "management_group_id": management_group.id, "from_account_id": source.id, "to_account_id": target.id,
                "transfer_date": transfer_date, "amount": amount, "comment": comment}),
        )

    def _find_transfer(self, management_group: Record, source: Record, target: Record, transfer_date: str, amount: float):
        account = next((a for a in self._details(management_group).get("accounts") or [] if a["id"] == source.id), {})
        return next((t for t in account.get("transfers_out") or []
                     if t.get("to_account_id") == target.id and date_only(t.get("transfer_date")) == transfer_date
                     and same(t.get("amount"), amount)), None)

    def _details(self, management_group: Record) -> dict:
        path = f"/management-groups/{management_group.id}"
        cached = self.session.cached(path)
        return cached[0] if cached else {}

    def _created_in(self, cached_path: str, path: str, body: dict) -> dict:
        self.session.forget(cached_path)
        return self.client.post(path, body)
