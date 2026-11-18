"""Rechnungen mit Beleg, Abbuchungen und Mieteingänge."""

from .base import Base, costable_ref, management_group_of, without_none
from .client import ApiError
from .report import CREATED, EXISTING, UPDATED
from .session import DataError, Record, describe

PERIODICITY_ONCE = "once"


class Invoices(Base):
    def invoice(self, issuer: Record, costable: Record, invoice_date: str, periodicity: str = PERIODICITY_ONCE,
                amount: float | None = None, allocations=(), segments=(), billing_interval: Record | None = None,
                issuer_account: Record | None = None, file: str | None = None, **fields) -> Record:
        """Rechnung mit Aufteilung auf Kategorien, z. B. ``allocations=[("Zinsen", 300),
        ("Tilgung", 200)]`` (Betrag None: bei einem Anteil der ganze Betrag, sonst 0).

        Wiederkehrend mit ``periodicity`` ("monthly", "quarterly", "yearly", …) sowie
        ``recurrence_start``/``recurrence_end``; ohne Ende bis Ende des Folgejahres.
        ``segments`` sind Betragsintervalle: [{"start_date", "end_date", "amount", "note",
        "allocations"}]. ``billing_interval`` heftet alle Termine an ein Abrechnungs-
        intervall. ``file`` ist der Beleg (PDF, Bild), relativ zur Datendatei.
        Wiedererkannt wird die Rechnung an Rechnungssteller, Zuordnung, Datum, Betrag und
        Beschreibung.
        """
        management_group = management_group_of(costable)
        label = f"{issuer.label} {invoice_date} {fields.get('description') or ''}".strip()
        record = self.session.ensure(
            "Rechnung", label, [issuer, costable, billing_interval, issuer_account],
            find=lambda: self._find_invoice(issuer, costable, invoice_date, amount, segments, fields.get("description")),
            create=lambda: self.client.post("/invoices", self._invoice_body(
                issuer, costable, invoice_date, periodicity, amount, allocations, segments,
                billing_interval, issuer_account, fields)),
            context={"management_group": management_group, "billing_interval": billing_interval},
        )
        if record.existed and (allocations or segments):
            self.extend_series(record, allocations, segments, billing_interval, fields)
        if file is not None:
            self.attach_file(record, file)
        return record

    def extend_series(self, invoice: Record, allocations, segments, billing_interval: Record | None, fields: dict) -> None:
        """Termine einer schon vorhandenen Rechnung nach der (erneuten) Angabe ergänzen,
        z. B. wenn ein Vertrag verlängert wurde. Vorhandene Termine bleiben unverändert."""
        if self.session.dry_run:
            return
        before = len(invoice.data.get("costs") or [])
        try:
            body = self._series_body(invoice, allocations, segments, billing_interval, fields)
            response = self.client.post(f"/invoices/{invoice.id}/series", body)
        except (ApiError, DataError) as failure:
            self.session.report.problem("Termine ergänzen", invoice.label, describe(failure))
            return
        if len(response.get("costs") or []) > before:
            self.session.report.record(UPDATED, "Termine ergänzt", invoice.label)
        invoice.data.update(response)

    def _find_invoice(self, issuer: Record, costable: Record, invoice_date: str, amount, segments, description):
        nominal = segments[0]["amount"] if segments else amount
        candidates = self.session.list("/invoices", {
            "invoice_issuer_id": issuer.id, "costable": costable_ref(costable), "invoice_date": invoice_date,
            "show_completed": True,
        })
        return self.first_match(candidates, {"amount": nominal, "description": description})

    def _invoice_body(self, issuer, costable, invoice_date, periodicity, amount, allocations, segments,
                      billing_interval, issuer_account, fields) -> dict:
        group_id = management_group_of(costable).data.get("user_group_id")
        body = without_none({
            **fields, "invoice_issuer_id": issuer.id, "costable": costable_ref(costable), "invoice_date": invoice_date,
            "periodicity": periodicity, "invoice_issuer_account_id": issuer_account.id if issuer_account else None,
            "billing_interval_id": billing_interval.id if billing_interval else None,
            "allocations": self._allocations(allocations, group_id) or None,
        })
        if segments:
            return {**body, "segments": [self._segment(segment, group_id) for segment in segments]}
        return {**body, "amount": amount}

    def _segment(self, segment: dict, group_id: int | None) -> dict:
        allocations = segment.get("allocations") or ()
        return without_none({**segment, "allocations": self._allocations(allocations, group_id) or None})

    def _allocations(self, allocations, group_id: int | None) -> list:
        return [{"cost_category_id": self.category_id(name, group_id), "amount": amount} for name, amount in allocations]

    def _series_body(self, invoice: Record, allocations, segments, billing_interval: Record | None, fields: dict) -> dict:
        group_id = management_group_of(invoice).data.get("user_group_id")
        body = {"billing_interval_id": billing_interval.id if billing_interval else None}
        if segments:
            return without_none({**body, "allocations": self._allocations(allocations, group_id) or None,
                                 "segments": [self._segment(segment, group_id) for segment in segments]})
        return {**body, "allocations": self._allocations(allocations, group_id),
                "recurrence_start": fields.get("recurrence_start"), "recurrence_end": fields.get("recurrence_end")}

    def attach_file(self, invoice: Record, path: str) -> bool:
        """Beleg an die Rechnung hängen. Hat sie schon einen Beleg mit diesem Dateinamen,
        bleibt er; eine Datei mit anderem Namen ersetzt ihn (wie beim Seeder)."""
        return self.session.apply("Beleg", f"{invoice.label}: {path}", [invoice], lambda: self._upload(invoice, path))

    def _upload(self, invoice: Record, path: str) -> str:
        name, content = self.file(path)
        if invoice.data.get("file_path") and invoice.data.get("file_original_name") == name:
            return EXISTING
        outcome = UPDATED if invoice.data.get("file_path") else CREATED
        invoice.data.update(self.client.upload(f"/invoices/{invoice.id}/file", {}, {"file": (name, content)}))
        return outcome

    def bookings(self, invoice: Record, entries, refresh_forecast: bool = True) -> bool:
        """Tatsächliche Abbuchungen, z. B. vom Kontoauszug: ``[("2026-02-03", 31.20), …]``
        oder mit Beträgen je Kategorie ``[("2026-02-03", [("Zinsen", 299), ("Tilgung",
        201)]), …]``. Ein dritter Wert eines Anteils heftet ihn an ein
        Abrechnungsintervall. Erneut gesendete Abbuchungen ändern nichts."""
        entries = list(entries)
        return self.session.apply(
            "Abbuchungen", f"{invoice.label} ({len(entries)})", [invoice],
            lambda: self._book(invoice, entries, refresh_forecast))

    def _book(self, invoice: Record, entries: list, refresh_forecast: bool) -> None:
        group_id = management_group_of(invoice).data.get("user_group_id")
        pin = invoice.context.get("billing_interval")
        self.client.post(f"/invoices/{invoice.id}/bookings", without_none({
            "bookings": [self._booking(date, amounts, group_id) for date, amounts in entries],
            "billing_interval_id": pin.id if pin else None,
            "refresh_forecast": refresh_forecast,
        }))

    def _booking(self, date: str, amounts, group_id: int | None) -> dict:
        if not isinstance(amounts, (list, tuple)):
            return {"booking_date": date, "amount": amounts}
        return {"booking_date": date, "allocations": [
            without_none({"cost_category_id": self.category_id(share[0], group_id), "amount": share[1],
                          "billing_interval_id": share[2].id if len(share) > 2 and share[2] else None})
            for share in amounts]}

    def rent_payments(self, lease: Record, dates) -> bool:
        """Eingegangene Mietzahlungen nach Zahlungsdatum; den bezahlten Monat bestimmt
        die Anwendung. Zahlungen ohne offene Miete werden im Bericht genannt."""
        dates = list(dates)
        return self.session.apply("Mietzahlungen", f"{lease.label} ({len(dates)})", [lease],
                                  lambda: self._book_rent(lease, dates))

    def _book_rent(self, lease: Record, dates: list) -> None:
        results = self.client.post(f"/leases/{lease.id}/rent-payments/book", {"booking_dates": dates})
        unmatched = [result["booking_date"] for result in results if result.get("rent_payment") is None]
        if unmatched:
            self.session.report.out(
                f"{'Hinweis':>12}  Mietzahlungen {lease.label}: keine offene Miete für {', '.join(unmatched)}")
