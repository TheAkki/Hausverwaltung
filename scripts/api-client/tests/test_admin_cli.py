# KI-generiert
import os
import tempfile
import unittest
from unittest import mock
from urllib.error import URLError

from hvapi import Admin
from hvapi import cli
from hvapi.client import ApiError
from hvapi.report import CREATED, EXISTING, UPDATED

from .fake_api import FakeApi, FakeClient
from .helpers import EXAMPLE_DIR, START_PASSWORD, run_admin, session


class AdminTest(unittest.TestCase):
    def setUp(self):
        self.api = FakeApi()

    def test_users_groups_and_members_are_set_up_once(self):
        first = run_admin(self.api)
        second = run_admin(self.api)

        self.assertEqual(4, first.report.counts[CREATED])
        self.assertEqual(0, second.report.counts[CREATED])
        self.assertEqual(4, second.report.counts[EXISTING])
        shared = next(g for g in self.api.data["groups"] if g["name"] == "Erbengemeinschaft Beispiel")
        self.assertEqual(["admin", "user"], [u["pivot"]["role"] for u in shared["users"]])
        self.assertEqual(START_PASSWORD, next(c[2] for c in self.api.calls if c[1] == "/users" and c[0] == "POST")["password"])

    def test_without_a_start_password_one_is_generated_and_shown(self):
        lines = []
        Admin(session(self.api), out=lines.append).user("Neu", " NEU@Example.com ")

        self.assertTrue(lines[0].startswith("Startpasswort für neu@example.com: "))
        self.assertGreaterEqual(len(lines[0].split(": ")[1]), 12)

    def test_roles_can_be_changed_and_must_be_valid(self):
        admin = Admin(session(self.api), START_PASSWORD)
        owner, member = admin.user("A", "a@x.de"), admin.user("B", "b@x.de")
        group = admin.group("G", owner, [(member, "user")])

        self.assertTrue(admin.member(group, member, "admin"))
        self.assertEqual(UPDATED, list(admin.session.report.counts)[-1])
        with self.assertRaises(ValueError):
            admin.member(group, member, "chef")


class CliTest(unittest.TestCase):
    def setUp(self):
        self.api = FakeApi()
        self.output = []

    def main(self, kind, path, *extra, environ=None):
        return cli.main(kind, [path, *extra], out=self.output.append,
                        environ={cli.ENV_TOKEN: "t"} if environ is None else environ,
                        client_factory=lambda url: FakeClient(self.api))

    def test_admin_and_user_examples_run_with_a_token(self):
        self.assertEqual(cli.EXIT_OK, self.main(cli.ADMIN, os.path.join(EXAMPLE_DIR, "admin_daten.py")))
        self.assertEqual(cli.EXIT_OK, self.main(cli.USER, os.path.join(EXAMPLE_DIR, "meine_daten.py"), "--dry-run"))
        self.assertIn("Trockenlauf: es wird nichts geändert.", self.output)
        self.assertTrue(self.output[-1].startswith("Ergebnis:"))

    def test_email_and_password_log_in_and_out(self):
        environ = {cli.ENV_EMAIL: "a@x.de", cli.ENV_PASSWORD: "pw", cli.ENV_START_PASSWORD: START_PASSWORD}
        self.main(cli.ADMIN, os.path.join(EXAMPLE_DIR, "admin_daten.py"), environ=environ)

        self.assertEqual(("POST", "/login"), self.api.calls[0][:2])
        self.assertEqual(("POST", "/logout"), self.api.calls[-1][:2])

    def test_problems_return_one(self):
        self.api.fail("POST", r"/users")
        self.assertEqual(cli.EXIT_PROBLEMS, self.main(cli.ADMIN, os.path.join(EXAMPLE_DIR, "admin_daten.py")))

    def test_setup_errors_return_two(self):
        empty = tempfile.NamedTemporaryFile("w", suffix=".py", delete=False)
        empty.write("x = 1\n")
        empty.close()
        self.addCleanup(os.unlink, empty.name)

        self.assertEqual(cli.EXIT_ERROR, self.main(cli.USER, "/gibt/es/nicht.py"))
        self.assertEqual(cli.EXIT_ERROR, self.main(cli.USER, empty.name))
        self.assertEqual(cli.EXIT_ERROR, self.main(cli.USER, os.path.join(EXAMPLE_DIR, "meine_daten.py"), environ={}))
        self.assertIn("HV_API_TOKEN", self.output[-1])

    def test_api_and_connection_errors_return_two(self):
        path = os.path.join(EXAMPLE_DIR, "admin_daten.py")
        for failure in (ApiError("POST", "/login", 422, {"message": "falsch"}), URLError("weg")):
            with mock.patch.object(Admin, "user", side_effect=failure):
                self.assertEqual(cli.EXIT_ERROR, self.main(cli.ADMIN, path))
        self.assertTrue(self.output[-2].startswith("Fehler der API"))
        self.assertTrue(self.output[-1].startswith("Keine Verbindung"))

    def test_arguments(self):
        args = cli.parse_args(["daten.py", "--dry-run", "--url", "http://h/api", "--verbose"], "x")
        self.assertEqual(("daten.py", True, "http://h/api", True), (args.datei, args.dry_run, args.url, args.verbose))


if __name__ == "__main__":
    unittest.main()
