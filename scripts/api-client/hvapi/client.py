"""HTTP-Client für die REST-API der Hausverwaltung (nur Python-Standardbibliothek)."""

import json
import mimetypes
import uuid
from urllib import error, parse, request

DEFAULT_TIMEOUT = 120
DEVICE_NAME = "api-client"
JSON_TYPE = "application/json"
FALLBACK_FILE_TYPE = "application/octet-stream"
CRLF = "\r\n"
STATUS_NO_CONTENT = 204
SUMMARY_LENGTH = 300


class ApiError(Exception):
    """Antwort der API mit Fehlerstatus, z. B. 422 bei Validierungsfehlern."""

    def __init__(self, method: str, path: str, status: int, body):
        self.method = method
        self.path = path
        self.status = status
        self.body = body
        super().__init__(f"{method} {path} -> {status}: {self.summary()}")

    def summary(self) -> str:
        if isinstance(self.body, dict):
            errors = self.body.get("errors")
            if isinstance(errors, dict) and errors:
                return "; ".join(f"{field}: {' '.join(map(str, messages))}" for field, messages in errors.items())
            if self.body.get("message"):
                return str(self.body["message"])
        return str(self.body)[:SUMMARY_LENGTH]


def form_value(value) -> str:
    """Formularwert, wie Laravel ihn erwartet (Booleans als 1/0)."""
    if isinstance(value, bool):
        return "1" if value else "0"
    return str(value)


def encode_multipart(fields: dict, files: dict) -> tuple:
    """Kodiert Felder und Dateien als multipart/form-data.

    ``files`` bildet Feldnamen auf (Dateiname, Inhalt als bytes) ab; Felder mit dem
    Wert None werden weggelassen.
    """
    boundary = uuid.uuid4().hex
    parts = []
    for name, value in fields.items():
        if value is None:
            continue
        parts.append(
            f"--{boundary}{CRLF}Content-Disposition: form-data; name=\"{name}\"{CRLF}{CRLF}"
            f"{form_value(value)}{CRLF}".encode()
        )
    for name, (filename, content) in files.items():
        content_type = mimetypes.guess_type(filename)[0] or FALLBACK_FILE_TYPE
        header = (
            f"--{boundary}{CRLF}Content-Disposition: form-data; name=\"{name}\"; filename=\"{filename}\"{CRLF}"
            f"Content-Type: {content_type}{CRLF}{CRLF}"
        )
        parts.append(header.encode() + content + CRLF.encode())
    parts.append(f"--{boundary}--{CRLF}".encode())
    return b"".join(parts), f"multipart/form-data; boundary={boundary}"


def with_query(path: str, params: dict | None) -> str:
    """Hängt Suchparameter an einen Pfad; None-Werte entfallen, Booleans werden 1/0."""
    query = {key: form_value(value) for key, value in (params or {}).items() if value is not None}
    return f"{path}?{parse.urlencode(query)}" if query else path


class ApiClient:
    """Ein Client je Benutzer: das Token bestimmt, wer die Aufrufe ausführt."""

    def __init__(self, base_url: str, token: str = "", timeout: int = DEFAULT_TIMEOUT, opener=None):
        self.base_url = base_url.rstrip("/")
        self.token = token
        self.timeout = timeout
        self._open = opener or request.urlopen

    def login(self, email: str, password: str) -> "ApiClient":
        """Meldet einen Benutzer an und liefert einen Client mit dessen Token."""
        response = self.post("/login", {"email": email, "password": password, "device_name": DEVICE_NAME})
        return ApiClient(self.base_url, response["token"], self.timeout, self._open)

    def logout(self) -> None:
        """Widerruft das Token dieses Clients."""
        self.post("/logout")

    def get(self, path: str, params: dict | None = None):
        return self.request("GET", with_query(path, params))

    def post(self, path: str, body=None):
        return self.request("POST", path, body)

    def put(self, path: str, body=None):
        return self.request("PUT", path, body)

    def patch(self, path: str, body=None):
        return self.request("PATCH", path, body)

    def delete(self, path: str):
        return self.request("DELETE", path)

    def upload(self, path: str, fields: dict, files: dict):
        body, content_type = encode_multipart(fields, files)
        return self._send("POST", path, body, content_type)

    def request(self, method: str, path: str, body=None):
        data = json.dumps(body).encode() if body is not None else None
        return self._send(method, path, data, JSON_TYPE if data is not None else None)

    def _send(self, method: str, path: str, data, content_type):
        headers = {"Accept": JSON_TYPE}
        if content_type:
            headers["Content-Type"] = content_type
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"
        req = request.Request(f"{self.base_url}{path}", data=data, headers=headers, method=method)
        try:
            with self._open(req, timeout=self.timeout) as response:
                payload = response.read()
                status = response.status
        except error.HTTPError as http_error:
            raise ApiError(method, path, http_error.code, decode(http_error.read())) from http_error
        if status == STATUS_NO_CONTENT or not payload:
            return None
        return decode(payload)


def decode(payload: bytes):
    try:
        return json.loads(payload.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError):
        return payload.decode("utf-8", errors="replace")
