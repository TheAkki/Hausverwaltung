# KI-generiert
"""Eine kleine REST-API im Speicher, die die von hvapi genutzten Endpunkte nachbildet."""

import re
from collections import defaultdict
from urllib.parse import parse_qs, urlsplit

from hvapi.client import ApiError

PAGE_SIZE = 2
# Angemeldeter Benutzer, solange sich kein Test anders anmeldet (Konto aus beispiel/).
DEFAULT_USER_EMAIL = "anna.beispiel@example.com"
GLOBAL_CATEGORIES = ("Wasserversorgung", "Entwässerung", "Zinsen", "Tilgung")


class FakeApi:
    def __init__(self):
        self.next_id = 1
        self.calls = []
        self.failures = {}
        self.data = defaultdict(list)
        for name in GLOBAL_CATEGORIES:
            self._add("cost-categories", {"name": name, "user_group_id": None})
        self.rent_unmatched = set()
        self.current_email = DEFAULT_USER_EMAIL
        # False bildet einen Server nach, der GET /user noch ohne persönliche Gruppe liefert.
        self.personal_group_supported = True

    # --- Hilfen -----------------------------------------------------------

    def _add(self, kind, values):
        item = {"id": self.next_id, **values}
        self.next_id += 1
        self.data[kind].append(item)
        return item

    def _get(self, kind, item_id):
        return next(item for item in self.data[kind] if item["id"] == int(item_id))

    def fail(self, method, pattern, status=422, times=1):
        self.failures[(method, pattern)] = [status] * times

    def count(self, method, prefix):
        return sum(1 for call in self.calls if call[0] == method and call[1].startswith(prefix))

    @staticmethod
    def _page(items, query):
        page = int(query.get("page", ["1"])[0])
        last = max(1, -(-len(items) // PAGE_SIZE))
        return {"data": items[(page - 1) * PAGE_SIZE:page * PAGE_SIZE], "current_page": page, "last_page": last}

    # --- Verteiler ----------------------------------------------------------

    def handle(self, method, path, body=None, files=None):
        parts = urlsplit(path)
        route, query = parts.path, parse_qs(parts.query)
        self.calls.append((method, route, body))
        for (fail_method, pattern), statuses in self.failures.items():
            if fail_method == method and re.fullmatch(pattern, route) and statuses:
                raise ApiError(method, route, statuses.pop(0), {"message": "abgelehnt", "errors": {"feld": ["kaputt"]}})
        for pattern, handler in self._routes(method):
            match = re.fullmatch(pattern, route)
            if match:
                return handler(*match.groups(), body=body or {}, query=query, files=files)
        raise ApiError(method, route, 404, {"message": "unbekannt"})

    def _routes(self, method):
        return {
            "GET": [
                (r"/user", self.current_user),
                (r"/users", self.list_users), (r"/users/(\d+)", self.show_user), (r"/groups", self.list_groups),
                (r"/groups/(\d+)", self.show_group), (r"/tenants", self.paged("tenants")),
                (r"/invoice-issuers", self.paged("invoice-issuers")), (r"/cost-categories", self.plain("cost-categories")),
                (r"/billing-templates", self.plain("billing-templates")),
                (r"/management-groups", self.plain("management-groups")),
                (r"/management-groups/(\d+)", self.show_management_group),
                (r"/management-groups/(\d+)/billing-intervals", self.list_intervals),
                (r"/management-units", self.plain("management-units")), (r"/rental-units", self.plain("rental-units")),
                (r"/leases", self.plain("leases")), (r"/leases/(\d+)", self.show_lease), (r"/invoices", self.list_invoices),
            ],
            "POST": [
                (r"/login", self.login), (r"/logout", self.ok), (r"/users", self.create_user), (r"/groups", self.create_group),
                (r"/groups/(\d+)/members", self.add_member), (r"/tenants", self.creator("tenants")),
                (r"/invoice-issuers", lambda body, query, files: self._add("invoice-issuers", {**body, "accounts": []})),
                (r"/invoice-issuer-accounts", self.create_issuer_account),
                (r"/cost-categories", self.create_category), (r"/billing-templates", self.create_template),
                (r"/management-groups", self.creator("management-groups")), (r"/billing-intervals", self.creator("intervals")),
                (r"/accounts", self.creator("accounts")), (r"/account-transfers", self.creator("transfers")),
                (r"/management-units", self.creator("management-units")), (r"/rental-units", self.creator("rental-units")),
                (r"/leases", self.create_lease), (r"/lease-intervals", self.creator("lease-intervals")),
                (r"/settlement-claims", self.creator("settlement-claims")), (r"/invoices", self.create_invoice),
                (r"/invoices/(\d+)/file", self.attach_file), (r"/invoices/(\d+)/series", self.extend_series),
                (r"/settlement-claims/(\d+)/file", self.file_attacher("settlement-claims")),
                (r"/account-transfers/(\d+)/file", self.file_attacher("transfers")),
                (r"/invoices/(\d+)/bookings", self.book), (r"/leases/(\d+)/rent-payments/book", self.book_rent),
            ],
            "PUT": [
                (r"/groups/(\d+)/landlord", self.ok), (r"/management-groups/(\d+)/landlord", self.ok),
                (r"/management-groups/(\d+)/cost-categories/(\d+)", self.ok),
                (r"/management-groups/(\d+)", self.update_management_group), (r"/lease-intervals/(\d+)", self.update_interval),
                (r"/billing-templates/(\d+)", self.update_template),
            ],
            "PATCH": [(r"/groups/(\d+)/members/(\d+)", self.set_role)],
        }.get(method, [])

    # --- Allgemein ----------------------------------------------------------

    def ok(self, *args, **kwargs):
        return None

    def plain(self, kind):
        return lambda body, query, files: list(self.data[kind])

    def paged(self, kind):
        return lambda body, query, files: self._page(list(self.data[kind]), query)

    def creator(self, kind, **defaults):
        return lambda body, query, files: self._add(kind, {**defaults, **body})

    def login(self, body, query, files):
        self.current_email = body["email"]
        return {"token": f"token-{body['email']}"}

    def current_user(self, body, query, files):
        """GET /user: angemeldeter Benutzer samt persönlicher Gruppe (erste eigene Gruppe)."""
        user = next(u for u in self.data["users"] if u["email"] == self.current_email)
        if not self.personal_group_supported:
            return dict(user)
        own = [g for g in self.data["groups"] if g["owner_id"] == user["id"]]
        personal = min(own, key=lambda g: g["id"]) if own else None
        return {**user, "personal_group": {"id": personal["id"], "name": personal["name"]} if personal else None}

    # --- Benutzer und Gruppen -------------------------------------------------

    def list_users(self, body, query, files):
        email = query.get("email", [None])[0]
        return self._page([u for u in self.data["users"] if email is None or u["email"] == email], query)

    def create_user(self, body, query, files):
        user = self._add("users", {"name": body["name"], "email": body["email"]})
        self._add("groups", {"name": f"Gruppe von {body['name']}", "owner_id": user["id"],
                             "users": [{"id": user["id"], "pivot": {"role": "admin"}}]})
        return user

    def show_user(self, user_id, body, query, files):
        groups = [g for g in self.data["groups"] if any(u["id"] == int(user_id) for u in g["users"])]
        return {**self._get("users", user_id), "groups": groups}

    def list_groups(self, body, query, files):
        return list(self.data["groups"])

    def show_group(self, group_id, body, query, files):
        return self._get("groups", group_id)

    def create_group(self, body, query, files):
        # Ohne owner_id legt der angemeldete Benutzer die Gruppe für sich selbst an.
        owner = body.get("owner_id")
        return self._add("groups", {"name": body["name"], "owner_id": owner, "description": body.get("description"),
                                    "users": [{"id": owner, "pivot": {"role": "admin"}}]})

    def add_member(self, group_id, body, query, files):
        user = next(u for u in self.data["users"] if u["email"] == body["email"])
        self._get("groups", group_id)["users"].append({"id": user["id"], "pivot": {"role": "user"}})

    def set_role(self, group_id, user_id, body, query, files):
        member = next(u for u in self._get("groups", group_id)["users"] if u["id"] == int(user_id))
        member["pivot"]["role"] = body["role"]

    # --- Stammdaten -----------------------------------------------------------

    def create_issuer_account(self, body, query, files):
        account = self._add("invoice-issuer-accounts", body)
        self._get("invoice-issuers", body["invoice_issuer_id"])["accounts"].append(account)
        return account

    def create_category(self, body, query, files):
        name = body["name"]
        if body.get("parent_id"):
            name = f"{self._get('cost-categories', body['parent_id'])['name']}::{name}"
        return self._add("cost-categories", {"name": name, "user_group_id": body["user_group_id"]})

    def create_template(self, body, query, files):
        return self._add("billing-templates", {**body, "has_image": bool(files)})

    def update_template(self, template_id, body, query, files):
        template = self._get("billing-templates", template_id)
        template.update(body)
        return dict(template)

    # --- Struktur -------------------------------------------------------------

    def show_management_group(self, group_id, body, query, files):
        accounts = [{**a, "transfers_out": [t for t in self.data["transfers"] if t["from_account_id"] == a["id"]]}
                    for a in self.data["accounts"] if a["management_group_id"] == int(group_id)]
        return {**self._get("management-groups", group_id), "accounts": accounts}

    def update_management_group(self, group_id, body, query, files):
        self._get("management-groups", group_id).update(body)

    def list_intervals(self, group_id, body, query, files):
        return [i for i in self.data["intervals"] if i["management_group_id"] == int(group_id)]

    def create_lease(self, body, query, files):
        lease = self._add("leases", body)
        self._add("lease-intervals", {"lease_id": lease["id"], "start_date": f"{body['start_date']}T00:00:00.000000Z",
                                      "end_date": None, "rent_amount": "0.00", "occupant_count": 1,
                                      "utility_advance_payment": "0.00", "notes": None})
        return lease

    def show_lease(self, lease_id, body, query, files):
        return {**self._get("leases", lease_id),
                "lease_intervals": [i for i in self.data["lease-intervals"] if i["lease_id"] == int(lease_id)],
                "settlement_claims": [c for c in self.data["settlement-claims"] if c["lease_id"] == int(lease_id)]}

    def update_interval(self, interval_id, body, query, files):
        self._get("lease-intervals", interval_id).update(body)

    # --- Rechnungen -----------------------------------------------------------

    def list_invoices(self, body, query, files):
        wanted = {key: values[0] for key, values in query.items() if key in ("invoice_issuer_id", "costable", "invoice_date")}
        items = [i for i in self.data["invoices"] if all(str(i.get(key)) == value for key, value in wanted.items())]
        return self._page(items, query)

    def create_invoice(self, body, query, files):
        # Wie die echte API: Zuordnung als costable_type/costable_id.
        costable_type, _, costable_id = body["costable"].partition(":")
        return self._add("invoices", {**body, "amount": body.get("amount") or body["segments"][0]["amount"],
                                      "costable_type": costable_type, "costable_id": int(costable_id),
                                      "file_path": None, "costs": [{"id": 0}]})

    def attach_file(self, invoice_id, body, query, files):
        return self.file_attacher("invoices")(invoice_id, body, query, files)

    # KI-generiert
    def file_attacher(self, kind):
        def attach(record_id, body, query, files):
            record = self._get(kind, record_id)
            record["file_path"] = f"{kind}/{self.next_id}.bin"
            record["file_original_name"] = files["file"][0]
            self.next_id += 1
            return record
        return attach

    def extend_series(self, invoice_id, body, query, files):
        invoice = self._get("invoices", invoice_id)
        invoice["costs"] = invoice["costs"] + [{"id": len(invoice["costs"])}]
        invoice["series"] = body
        return invoice

    def book(self, invoice_id, body, query, files):
        self.data["bookings"].append({"invoice_id": int(invoice_id), **body})
        return {"costs": []}

    def book_rent(self, lease_id, body, query, files):
        return [{"booking_date": date, "rent_payment": None if date in self.rent_unmatched else {"id": 1}}
                for date in body["booking_dates"]]


class FakeClient:
    """Verhält sich wie hvapi.client.ApiClient, spricht aber mit FakeApi."""

    def __init__(self, api: FakeApi | None = None, token: str = ""):
        self.api = api or FakeApi()
        self.token = token

    def login(self, email, password):
        return FakeClient(self.api, self.api.handle("POST", "/login", {"email": email, "password": password})["token"])

    def logout(self):
        self.api.handle("POST", "/logout")

    def get(self, path, params=None):
        from hvapi.client import with_query
        return self.api.handle("GET", with_query(path, params))

    def post(self, path, body=None):
        return self.api.handle("POST", path, body)

    def put(self, path, body=None):
        return self.api.handle("PUT", path, body)

    def patch(self, path, body=None):
        return self.api.handle("PATCH", path, body)

    def upload(self, path, fields, files):
        return self.api.handle("POST", path, fields, files)
