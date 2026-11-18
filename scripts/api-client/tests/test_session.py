# KI-generiert
import unittest

from hvapi.client import ApiError
from hvapi.report import CREATED, EXISTING, PLANNED, SKIPPED, UPDATED, Report
from hvapi.session import FAILED, PLANNED_STATE, DataError, Record, Session, date_only, describe, normalize, same


class ScriptedClient:
    def __init__(self, *responses):
        self.responses = list(responses)
        self.paths = []

    def get(self, path, params=None):
        self.paths.append((path, params))
        return self.responses.pop(0)


class SessionReadTest(unittest.TestCase):
    def test_lists_follow_all_pages(self):
        client = ScriptedClient({"data": [1, 2], "last_page": 2}, {"data": [3], "last_page": 2})
        self.assertEqual([1, 2, 3], Session(client, Report(lambda line: None)).list("/x", {"a": 1}))
        self.assertEqual([("/x", {"a": 1}), ("/x", {"a": 1, "page": 2})], client.paths)

    def test_plain_lists_single_objects_and_empty_answers(self):
        session = Session(ScriptedClient([1], {"id": 5}, None), Report(lambda line: None))
        self.assertEqual([1], session.list("/a"))
        self.assertEqual([{"id": 5}], session.list("/b"))
        self.assertEqual([], session.list("/c"))

    def test_cached_lists_are_read_once_until_forgotten(self):
        client = ScriptedClient([1], [2])
        session = Session(client, Report(lambda line: None))
        self.assertEqual([1], session.cached("/a"))
        self.assertEqual([1], session.cached("/a"))
        session.forget("/a")
        self.assertEqual([2], session.cached("/a"))
        self.assertEqual(2, len(client.paths))


class SessionWriteTest(unittest.TestCase):
    def setUp(self):
        self.lines = []
        self.session = Session(None, Report(self.lines.append))

    def test_found_records_are_reused(self):
        record = self.session.ensure("Mieter", "Meier", [], find=lambda: {"id": 4}, create=lambda: self.fail("anlegen"))
        self.assertEqual((4, True, 1), (record.id, record.existed, self.session.report.counts[EXISTING]))

    def test_missing_records_are_created(self):
        record = self.session.ensure("Mieter", "Meier", [], find=lambda: None, create=lambda: {"id": 7}, context={"k": 1})
        self.assertEqual((7, {"k": 1}, False), (record.id, record.context, record.existed))
        self.assertIn("angelegt  Mieter: Meier", self.lines[0])

    def test_dry_runs_only_plan(self):
        self.session.dry_run = True
        record = self.session.ensure("Mieter", "Meier", [], find=lambda: None, create=lambda: self.fail("anlegen"))
        self.assertEqual(PLANNED_STATE, record.state)
        self.assertFalse(self.session.apply("Buchung", "x", [], action=lambda: self.fail("buchen")))
        self.assertEqual(2, self.session.report.counts[PLANNED])

    def test_api_and_data_errors_become_problems(self):
        failing = self.session.ensure("Mieter", "Meier", [], find=lambda: None,
                                      create=lambda: (_ for _ in ()).throw(ApiError("POST", "/t", 422, {"message": "nein"})))

        def broken():
            raise DataError("Datei fehlt")
        self.assertFalse(self.session.apply("Beleg", "x", [], broken))
        self.assertEqual(FAILED, failing.state)
        self.assertEqual(["nein", "Datei fehlt"], [p.message for p in self.session.report.problems])

    def test_children_of_failed_or_planned_parents_are_skipped_or_planned(self):
        failed, planned = Record("A", "a", state=FAILED), Record("B", "b", state=PLANNED_STATE)
        self.assertEqual(FAILED, self.session.ensure("K", "k", [failed, None], find=None, create=None).state)
        self.assertEqual(PLANNED_STATE, self.session.ensure("K", "k", [planned], find=None, create=None).state)
        self.assertFalse(self.session.apply("K", "k", [failed], action=None))
        self.assertEqual((2, 1), (self.session.report.counts[SKIPPED], self.session.report.counts[PLANNED]))

    def test_actions_report_their_outcome(self):
        self.assertTrue(self.session.apply("K", "k", [Record("A", "a", 1)], action=lambda: None))
        self.assertTrue(self.session.apply("K", "k", [], action=lambda: CREATED))
        self.assertEqual((1, 1), (self.session.report.counts[UPDATED], self.session.report.counts[CREATED]))


class ComparisonTest(unittest.TestCase):
    def test_values_compare_like_the_api_stores_them(self):
        self.assertTrue(same("42.00", 42))
        self.assertTrue(same(" Text ", "Text"))
        self.assertTrue(same("", None))
        self.assertTrue(same(True, True))
        self.assertFalse(same("01234", "1234 Ort"))
        self.assertEqual(["a"], normalize(["a"]))
        self.assertEqual("2026-01-05", date_only("2026-01-05T00:00:00.000000Z"))
        self.assertIsNone(date_only(None))
        self.assertEqual("x", describe(DataError("x")))
        self.assertEqual("y", describe(ApiError("GET", "/", 500, {"message": "y"})))


class ReportTest(unittest.TestCase):
    def test_summary_lists_counts_and_problems(self):
        lines = []
        report = Report(lines.append, verbose=True)
        report.record(EXISTING, "Mieter", "Meier")
        report.problem("Rechnung", "R1", "kaputt")
        self.assertIn("vorhanden  Mieter: Meier", lines[0])
        self.assertEqual(["Ergebnis: 1 vorhanden.", "1 Problem(e):", "  - Rechnung [R1]: kaputt"], report.summary())
        self.assertEqual(["Ergebnis: nichts zu tun."], Report(lines.append).summary())


if __name__ == "__main__":
    unittest.main()
