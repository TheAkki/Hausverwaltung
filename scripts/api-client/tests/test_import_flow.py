# KI-generiert
"""Ganze Abläufe mit den Beispieldaten gegen die API im Speicher."""

import unittest

from hvapi import Hausverwaltung
from hvapi.report import CREATED, EXISTING, PLANNED, SKIPPED, UPDATED
from hvapi.session import FAILED, Record

from .fake_api import FakeApi
from .helpers import EXAMPLE_DIR, run_admin, run_user, session


class ExampleImportTest(unittest.TestCase):
    def setUp(self):
        self.api = FakeApi()
        run_admin(self.api)

    def test_the_first_run_creates_everything_without_problems(self):
        current = run_user(self.api)

        self.assertEqual([], current.report.problems)
        self.assertEqual(2, len(self.api.data["tenants"]))
        self.assertEqual(2, len(self.api.data["leases"]))
        self.assertEqual(3, len(self.api.data["lease-intervals"]))
        invoice = self.api.data["invoices"][0]
        self.assertEqual("stadtwerke-wasser-2026-01.pdf", invoice["file_original_name"])
        self.assertEqual([30.0, 15.0], [a["amount"] for a in invoice["allocations"]])
        self.assertTrue(self.api.data["billing-templates"][0]["has_image"])
        moved = self.api.data["management-groups"][0]
        self.assertEqual(self.api.data["billing-templates"][0]["id"], moved["default_billing_template_id"])

    def test_bookings_carry_categories_amounts_and_the_payment_dates(self):
        run_user(self.api)

        water, loan = self.api.data["bookings"]
        self.assertEqual(["2026-01-05", "2026-02-04"], [b["booking_date"] for b in water["bookings"]])
        self.assertTrue(water["refresh_forecast"])
        self.assertEqual(310.0, loan["bookings"][0]["allocations"][0]["amount"])
        rent_calls = [call for call in self.api.calls if call[1].endswith("/rent-payments/book")]
        self.assertEqual([["2024-12-30", "2025-02-01", "2025-03-03"], ["2025-03-31", "2025-05-02"]],
                         [call[2]["booking_dates"] for call in rent_calls])

    def test_lease_intervals_are_set_to_the_given_values(self):
        run_user(self.api)

        first = self.api.data["lease-intervals"][0]
        self.assertEqual((500.0, 120.0, 2), (first["rent_amount"], first["utility_advance_payment"], first["occupant_count"]))

    def test_a_second_run_finds_everything_and_creates_nothing(self):
        run_user(self.api)
        sizes = {kind: len(items) for kind, items in self.api.data.items() if kind != "bookings"}

        current = run_user(self.api)

        self.assertEqual(0, current.report.counts[CREATED])
        self.assertEqual([], current.report.problems)
        self.assertEqual(sizes, {kind: len(items) for kind, items in self.api.data.items() if kind != "bookings"})
        self.assertEqual(2, sum(1 for call in self.api.calls if call[1].endswith("/series")))
        self.assertGreater(current.report.counts[EXISTING], 20)

    def test_a_dry_run_only_reads(self):
        before = len(self.api.calls)
        current = run_user(self.api, dry_run=True)

        self.assertEqual({"GET"}, {call[0] for call in self.api.calls[before:]})
        self.assertGreater(current.report.counts[PLANNED], 20)

    def test_a_failing_record_skips_what_depends_on_it(self):
        self.api.fail("POST", r"/management-groups")
        current = run_user(self.api)

        self.assertEqual(["Verwaltungsgruppe"], [problem.kind for problem in current.report.problems])
        self.assertGreater(current.report.counts[SKIPPED], 10)
        self.assertEqual([], self.api.data["invoices"])

    def test_unmatched_rent_payments_are_mentioned(self):
        self.api.rent_unmatched.add("2025-03-03")
        lines = []
        run_user(self.api, lines=lines)

        self.assertTrue(any("keine offene Miete für 2025-03-03" in line for line in lines))


class SingleCallsTest(unittest.TestCase):
    def setUp(self):
        self.api = FakeApi()
        run_admin(self.api)
        self.lines = []
        self.session = session(self.api, lines=self.lines)
        self.hv = Hausverwaltung(self.session, EXAMPLE_DIR)
        self.group = self.hv.personal_group()
        self.mg = self.hv.management_group(self.group, "Haus")
        self.groups_before = self.created_groups()

    def issuer(self):
        return self.hv.invoice_issuer(self.group, name="Bank", postal_code="1", city="O")

    # KI-generiert
    def created_groups(self):
        """Anzahl der POST /groups-Aufrufe (ohne Mitglieder-Endpunkte)."""
        return sum(1 for method, path, _ in self.api.calls if method == "POST" and path == "/groups")

    def test_an_unknown_group_is_a_problem_for_the_administrator(self):
        group = self.hv.group("Gibt es nicht")

        self.assertEqual(FAILED, group.state)
        self.assertIn("Administrator", self.session.report.problems[0].message)
        self.assertIn("create=True", self.session.report.problems[0].message)
        self.assertEqual(self.groups_before, self.created_groups())

    # KI-generiert
    def test_a_user_can_create_an_own_group_once(self):
        created_before = self.session.report.counts[CREATED]

        group = self.hv.group("Eigene GbR", create=True, description="Gemeinsame Vermietung")
        again = self.hv.group("Eigene GbR", create=True)

        self.assertTrue(group.ready)
        self.assertEqual(group.id, again.id)
        self.assertEqual(self.groups_before + 1, self.created_groups())
        body = [c[2] for c in self.api.calls if c[0] == "POST" and c[1] == "/groups"][-1]
        self.assertEqual({"name": "Eigene GbR", "description": "Gemeinsame Vermietung"}, body)
        self.assertEqual(created_before + 1, self.session.report.counts[CREATED])

    # KI-generiert
    def test_the_personal_group_is_read_from_the_api(self):
        self.assertEqual("Gruppe von Anna Beispiel", self.group.label)
        self.assertTrue(self.group.ready)
        self.assertEqual(self.group.id, self.hv.group("Gruppe von Anna Beispiel").id)

    # KI-generiert
    def test_a_renamed_personal_group_keeps_working(self):
        self.api._get("groups", self.group.id)["name"] = "Annas Häuser"

        group = self.hv.personal_group()

        self.assertEqual(("Annas Häuser", self.group.id), (group.label, group.id))

    # KI-generiert
    def test_a_missing_personal_group_is_a_problem(self):
        owner = self.api._get("groups", self.group.id)["owner_id"]
        self.api.data["groups"] = [g for g in self.api.data["groups"] if g["owner_id"] != owner]

        group = self.hv.personal_group()

        self.assertEqual(FAILED, group.state)
        self.assertIn("persönliche Gruppe", str(self.session.report.problems[-1]))

    # KI-generiert
    def test_an_outdated_server_is_reported_instead_of_a_deleted_group(self):
        self.api.personal_group_supported = False

        group = self.hv.personal_group()

        self.assertEqual(FAILED, group.state)
        message = str(self.session.report.problems[-1])
        self.assertIn("aktualisieren", message)
        self.assertNotIn("gelöscht", message)

    # KI-generiert
    def test_after_login_the_personal_group_of_that_user_is_returned(self):
        logged_in = self.session.client.login("bert.beispiel@example.com", "egal")
        other = Hausverwaltung(session(self.api), EXAMPLE_DIR)
        other.session.client = logged_in

        self.assertEqual("Gruppe von Bert Beispiel", other.personal_group().label)

    # KI-generiert
    def test_an_existing_group_is_found_with_create(self):
        group = self.hv.group("Gruppe von Anna Beispiel", create=True)

        self.assertEqual(self.group.id, group.id)
        self.assertEqual(self.groups_before, self.created_groups())

    # KI-generiert
    def test_creating_a_group_is_only_planned_in_a_dry_run(self):
        self.session.dry_run = True

        group = self.hv.group("Eigene GbR", create=True)

        self.assertFalse(group.ready)
        self.assertEqual(self.groups_before, self.created_groups())
        self.assertEqual(1, self.session.report.counts[PLANNED])

    # KI-generiert
    def test_a_rejected_group_creation_is_a_problem(self):
        self.api.fail("POST", r"/groups")

        group = self.hv.group("Eigene GbR", create=True)

        self.assertEqual(FAILED, group.state)
        self.assertEqual(["Gruppe"], [problem.kind for problem in self.session.report.problems])

    def test_unknown_categories_and_missing_files_are_reported_per_record(self):
        invoice = self.hv.invoice(self.issuer(), self.mg, "2026-01-01", amount=5, allocations=[("Gibt es nicht", None)])
        missing = self.hv.invoice(self.issuer(), self.mg, "2026-01-02", amount=5, allocations=[("Zinsen", None)],
                                  file="fehlt.pdf")

        self.assertEqual(FAILED, invoice.state)
        self.assertTrue(missing.ready)
        self.assertEqual(["Rechnung", "Beleg"], [problem.kind for problem in self.session.report.problems])

    def test_subcategories_need_their_parent(self):
        self.hv.category(self.group, "Lohn", is_relevant_for_billing=False)
        sub = self.hv.category(self.group, "Lohn::Steuer")
        orphan = self.hv.category(self.group, "Fehlt::Unter")

        self.assertEqual("Lohn::Steuer", self.api._get("cost-categories", sub.id)["name"])
        self.assertEqual(FAILED, orphan.state)

    def test_categories_of_other_own_groups_are_found_after_a_move(self):
        own = self.hv.category(self.group, "Hausmeister")
        self.mg.data["user_group_id"] = 9999

        self.assertEqual(own.id, self.hv.category_id("Hausmeister", self.mg.data["user_group_id"]))
        self.assertEqual(1, self.hv.category_id("Wasserversorgung", 9999))

    def test_segments_and_pinned_intervals_are_sent_to_the_api(self):
        interval = self.hv.billing_interval(self.mg, "2025-01-01", "2025-12-31")
        segments = [{"start_date": "2026-01-01", "end_date": "2026-03-31", "amount": 100.0, "note": "alt"},
                    {"start_date": "2026-04-01", "end_date": None, "amount": 110.0,
                     "allocations": [("Zinsen", 60.0), ("Tilgung", 50.0)]}]
        invoice = self.hv.invoice(self.issuer(), self.mg, "2026-01-01", "monthly", segments=segments,
                                  allocations=[("Zinsen", None)], billing_interval=interval)
        self.hv.bookings(invoice, [("2026-01-02", 100.0), ("2026-04-01", [("Zinsen", 60.0, interval), ("Tilgung", 50.0)])],
                         refresh_forecast=False)

        sent = next(call[2] for call in self.api.calls if call[:2] == ("POST", "/invoices"))
        created = self.api.data["invoices"][0]
        self.assertNotIn("amount", sent)
        self.assertEqual(interval.id, created["billing_interval_id"])
        self.assertEqual("alt", created["segments"][0]["note"])
        self.assertNotIn("end_date", created["segments"][1])
        booking = self.api.data["bookings"][0]
        self.assertEqual({"booking_date": "2026-01-02", "amount": 100.0}, booking["bookings"][0])
        self.assertEqual(interval.id, booking["bookings"][1]["allocations"][0]["billing_interval_id"])
        self.assertEqual(interval.id, booking["billing_interval_id"])
        self.assertFalse(booking["refresh_forecast"])

    def test_a_redeclared_invoice_extends_its_series(self):
        issuer = self.issuer()
        first = self.hv.invoice(issuer, self.mg, "2025-01-15", "monthly", amount=338.39, description="Hausmeister",
                                recurrence_start="2024-01-05", recurrence_end="2024-12-31", allocations=[("Zinsen", None)])
        again = self.hv.invoice(issuer, self.mg, "2025-01-15", "monthly", amount=338.39, description="Hausmeister",
                                recurrence_start="2025-01-01", recurrence_end="2025-08-31", allocations=[("Zinsen", None)])
        segmented = self.hv.invoice(issuer, self.mg, "2025-01-15", "monthly", amount=338.39, description="Hausmeister",
                                    segments=[{"start_date": "2026-01-01", "amount": 338.39}], allocations=[("Zinsen", None)])

        self.assertEqual(first.id, again.id)
        self.assertEqual(first.id, segmented.id)
        series = self.api._get("invoices", first.id)["series"]
        self.assertEqual([{"start_date": "2026-01-01", "amount": 338.39}], series["segments"])
        self.assertNotIn("recurrence_start", series)
        self.assertTrue(any("Termine ergänzt" in line for line in self.lines))

    def test_extending_a_series_reports_api_errors(self):
        issuer = self.issuer()
        self.hv.invoice(issuer, self.mg, "2025-01-15", "monthly", amount=1.0, allocations=[("Zinsen", None)])
        self.api.fail("POST", r"/invoices/\d+/series")
        self.hv.invoice(issuer, self.mg, "2025-01-15", "monthly", amount=1.0, allocations=[("Zinsen", None)])

        self.assertEqual("Termine ergänzen", self.session.report.problems[0].kind)

    def test_templates_without_image_and_dry_runs_of_known_invoices(self):
        template = self.hv.billing_template(self.group, "Schlicht", content="Text")
        issuer = self.issuer()
        self.hv.invoice(issuer, self.mg, "2025-01-15", "monthly", amount=1.0, allocations=[("Zinsen", None)])
        self.session.dry_run = True
        self.hv.invoice(issuer, self.mg, "2025-01-15", "monthly", amount=1.0, allocations=[("Zinsen", None)])

        self.assertFalse(self.api._get("billing-templates", template.id)["has_image"])
        self.assertEqual(0, sum(1 for call in self.api.calls if call[1].endswith("/series")))

    # KI-generiert
    def test_a_changed_template_text_is_updated_and_keeps_the_other_texts(self):
        template = self.hv.billing_template(self.group, "Brief", content="Alt", css_content="a {}", footer="Fuß")

        again = self.hv.billing_template(self.group, "Brief", content="Neu", css_content="a {}")

        stored = self.api._get("billing-templates", template.id)
        self.assertEqual(template.id, again.id)
        self.assertEqual(("Neu", "a {}", "Fuß"), (stored["content"], stored["css_content"], stored["footer"]))
        self.assertEqual(self.group.id, self.api.calls[-1][2]["user_group_id"])
        self.assertEqual(1, self.session.report.counts[UPDATED])
        self.assertEqual("Neu", again.data["content"])

    # KI-generiert
    def test_an_unchanged_template_is_not_sent_again(self):
        self.hv.billing_template(self.group, "Brief", content="Text\n", header=None)

        self.hv.billing_template(self.group, "Brief", content="  Text", header="")

        self.assertEqual(0, self.api.count("PUT", "/billing-templates"))
        self.assertEqual(0, self.session.report.counts[UPDATED])

    # KI-generiert
    def test_a_changed_template_is_only_planned_in_a_dry_run(self):
        template = self.hv.billing_template(self.group, "Brief", content="Alt")
        self.session.dry_run = True

        self.hv.billing_template(self.group, "Brief", content="Neu")

        self.assertEqual(0, self.api.count("PUT", "/billing-templates"))
        self.assertEqual("Alt", self.api._get("billing-templates", template.id)["content"])
        self.assertEqual(1, self.session.report.counts[PLANNED])

    # KI-generiert
    def test_a_rejected_template_update_is_reported_as_problem(self):
        self.hv.billing_template(self.group, "Brief", content="Alt")
        self.api.fail("PUT", r"/billing-templates/\d+")

        self.hv.billing_template(self.group, "Brief", content="Neu")

        self.assertEqual(["Vorlage"], [problem.kind for problem in self.session.report.problems])

    def test_the_same_receipt_is_not_uploaded_again_but_another_one_replaces_it(self):
        invoice = self.hv.invoice(self.issuer(), self.mg, "2026-01-01", amount=5, allocations=[("Zinsen", None)],
                                  file="belege/stadtwerke-wasser-2026-01.pdf")
        self.assertTrue(self.hv.attach_file(invoice, "belege/stadtwerke-wasser-2026-01.pdf"))
        self.assertEqual(1, self.api.count("POST", f"/invoices/{invoice.id}/file"))

        self.assertTrue(self.hv.attach_file(invoice, "kopfbild.png"))
        self.assertEqual(2, self.api.count("POST", f"/invoices/{invoice.id}/file"))
        self.assertEqual("kopfbild.png", self.api._get("invoices", invoice.id)["file_original_name"])

    def test_moving_to_the_current_group_changes_nothing(self):
        self.assertTrue(self.hv.move_management_group(self.mg, self.group))
        self.assertTrue(self.hv.move_management_group(self.mg, self.group))

        self.assertEqual(0, self.api.count("PUT", f"/management-groups/{self.mg.id}"))

    def test_transfers_and_claims_are_recognised_again(self):
        giro, reserve = self.hv.account(self.mg, "Giro"), self.hv.account(self.mg, "Rücklage")
        interval = self.hv.billing_interval(self.mg, "2025-01-01", "2025-12-31")
        rental = self.hv.rental_unit(self.hv.management_unit(self.mg, "Weg 1", "1", "O"), "Garage", "G1")
        tenant = self.hv.tenant(self.group, last_name="Meier", street="Weg 1", postal_code="1", city="O")
        lease = self.hv.lease(rental, "2025-01-01", tenants=[tenant], rent_adjustment_type="fixed")
        coupled = self.hv.lease(rental, "2025-02-01", coupled_to=lease)

        for _ in range(2):
            self.hv.transfer(self.mg, giro, reserve, "2026-01-01", 100.0, "Rücklage")
            self.hv.settlement_claim(lease, interval, "2026-03-01", 12.5)

        self.assertEqual(1, len(self.api.data["transfers"]))
        self.assertEqual(1, len(self.api.data["settlement-claims"]))
        self.assertEqual("reference", self.api._get("leases", coupled.id)["assignment_mode"])
        self.assertEqual(lease.id, self.api._get("leases", coupled.id)["coupled_to_lease_id"])

    def test_landlords_and_allocation_keys_are_applied(self):
        self.assertTrue(self.hv.group_landlord(self.group, name="Anna"))
        self.assertTrue(self.hv.management_group_landlord(self.mg, name="GbR"))
        self.assertTrue(self.hv.allocation_key(self.mg, "Wasserversorgung", "area"))

        self.assertEqual(1, self.api.count("PUT", f"/management-groups/{self.mg.id}/cost-categories/"))

    def test_records_of_planned_parents_are_planned_too(self):
        planned = Record("Verwaltungsgruppe", "neu", state="geplant")

        unit = self.hv.management_unit(planned, "Weg 1", "1", "O")

        self.assertEqual("geplant", unit.state)
        self.assertEqual(PLANNED, list(self.session.report.counts)[-1])


if __name__ == "__main__":
    unittest.main()
