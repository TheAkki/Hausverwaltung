# KI-generiert
"""Weniger Anfragen: Listen werden zwischengespeichert und nach eigenem Anlegen ergänzt."""

import unittest

from hvapi import Hausverwaltung
from hvapi.report import Report
from hvapi.session import Record, Session

from .fake_api import FakeApi
from .helpers import EXAMPLE_DIR, run_admin, run_user, session


class ScriptedClient:
    def __init__(self, *responses):
        self.responses = list(responses)

    def get(self, path, params=None):
        return self.responses.pop(0)


class RememberTest(unittest.TestCase):
    def test_a_created_record_is_added_to_every_cached_list_of_its_path(self):
        current = Session(ScriptedClient([{"id": 1}], [{"id": 2}], [{"id": 9}]), Report(lambda line: None))
        current.cached("/leases")
        current.cached("/leases", {"show_expired": True})
        current.cached("/tenants")

        current.remember("/leases", {"id": 3})
        current.remember("/rental-units", {"id": 4})

        self.assertEqual([1, 3], [item["id"] for item in current.cached("/leases")])
        self.assertEqual([2, 3], [item["id"] for item in current.cached("/leases", {"show_expired": True})])
        self.assertEqual([9], [item["id"] for item in current.cached("/tenants")])


class ExampleLookupTest(unittest.TestCase):
    def setUp(self):
        self.api = FakeApi()
        run_admin(self.api)

    def gets(self, route):
        return sum(1 for method, path, _ in self.api.calls if method == "GET" and path == route)

    def test_lists_are_read_once_although_records_are_created_in_between(self):
        run_user(self.api)

        self.assertEqual(2, len(self.api.data["leases"]))
        self.assertEqual(1, self.gets("/leases"))
        self.assertEqual(1, self.gets("/rental-units"))
        self.assertEqual(1, self.gets("/management-units"))
        self.assertLessEqual(self.gets("/tenants"), 1)

    def test_invoices_are_read_once_per_issuer(self):
        run_user(self.api)

        issuers = {invoice["invoice_issuer_id"] for invoice in self.api.data["invoices"]}
        self.assertEqual(len(issuers), self.gets("/invoices"))
        self.assertLess(len(issuers), len(self.api.data["invoices"]) + 1)

    def test_lease_details_are_reread_only_after_an_own_change(self):
        hv = Hausverwaltung(session(self.api), EXAMPLE_DIR)
        lease_id = self.api._add("leases", {"rental_unit_id": 1, "start_date": "2026-01-01"})["id"]
        self.api._add("lease-intervals", {"lease_id": lease_id, "start_date": "2026-01-01T00:00:00.000000Z",
                                          "end_date": None, "rent_amount": "500.00", "occupant_count": 1,
                                          "utility_advance_payment": "0.00", "notes": None})
        lease = Record("Mietvertrag", "L", lease_id)
        interval = Record("Abrechnungsintervall", "2025", 99)
        detail = f"/leases/{lease_id}"

        hv.settlement_claim(lease, interval, "2026-02-01", 10)
        hv.settlement_claim(lease, interval, "2026-02-01", 10)
        after_claims = self.gets(detail)
        hv.lease(Record("Mieteinheit", "W", 1), "2026-01-01", intervals=[
            {"start_date": "2026-01-01", "rent_amount": 500, "utility_advance_payment": 0, "occupant_count": 1}])
        unchanged = self.gets(detail)
        hv.settlement_claim(lease, interval, "2026-03-01", 10)

        self.assertEqual(2, after_claims)  # vor und nach dem Anlegen der ersten Forderung
        self.assertEqual(after_claims, unchanged)  # Intervalle unverändert: nichts neu geladen
        self.assertEqual(1, len([c for c in self.api.data["settlement-claims"] if c["date"] == "2026-02-01"]))
        self.assertEqual(unchanged, self.gets(detail))


class InvoiceLookupTest(unittest.TestCase):
    def setUp(self):
        self.api = FakeApi()
        self.hv = Hausverwaltung(session(self.api), EXAMPLE_DIR)
        group = Record("Gruppe", "G", 50, {"user_group_id": 50})
        self.management_group = Record("Verwaltungsgruppe", "MG", 60, {"user_group_id": 50},
                                       context={"costable_type": "management_group"})
        self.management_group.context["management_group"] = self.management_group
        self.issuer = Record("Rechnungssteller", "Stadtwerke", 70)
        self.other_issuer = Record("Rechnungssteller", "Bank", 71)
        self.group = group

    def stored(self, **fields):
        return self.api._add("invoices", {
            "invoice_issuer_id": self.issuer.id, "costable_type": "management_group", "costable_id": 60,
            "invoice_date": "2026-01-05T00:00:00.000000Z", "amount": "45.00", "description": "Wasser", "costs": [], **fields})

    def test_only_an_invoice_with_the_same_issuer_assignment_date_amount_and_description_is_found(self):
        self.stored(costable_type="management_unit")
        self.stored(invoice_date="2026-02-05T00:00:00.000000Z")
        self.stored(amount="46.00")
        self.stored(description="Abwasser")
        self.stored(invoice_issuer_id=self.other_issuer.id)
        match = self.stored()

        found = self.hv._find_invoice(self.issuer, self.management_group, "2026-01-05", 45, (), "Wasser")

        self.assertEqual(match["id"], found["id"])

    def test_a_missing_invoice_is_created_and_found_again_without_a_new_request(self):
        first = self.hv.invoice(self.issuer, self.management_group, "2026-01-05", amount=45, description="Wasser")
        second = self.hv.invoice(self.issuer, self.management_group, "2026-01-05", amount=45, description="Wasser")

        self.assertEqual(first.id, second.id)
        self.assertTrue(second.existed)
        self.assertEqual(1, len(self.api.data["invoices"]))
        self.assertEqual(1, sum(1 for method, path, _ in self.api.calls if method == "GET" and path == "/invoices"))


if __name__ == "__main__":
    unittest.main()
