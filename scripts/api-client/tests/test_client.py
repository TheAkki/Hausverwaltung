# KI-generiert
import io
import json
import unittest
from urllib.error import HTTPError

from hvapi.client import ApiClient, ApiError, decode, encode_multipart, form_value, with_query


class FakeResponse:
    def __init__(self, status=200, payload=b""):
        self.status = status
        self.payload = payload

    def read(self):
        return self.payload

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return False


class Opener:
    def __init__(self, *responses):
        self.responses = list(responses)
        self.requests = []

    def __call__(self, req, timeout):
        self.requests.append(req)
        response = self.responses.pop(0)
        if isinstance(response, Exception):
            raise response
        return response


def json_response(data, status=200):
    return FakeResponse(status, json.dumps(data).encode())


class ClientTest(unittest.TestCase):
    def test_login_returns_a_client_with_the_token_and_logout_revokes_it(self):
        opener = Opener(json_response({"token": "abc"}, 201), json_response({"id": 1}), FakeResponse(204))
        client = ApiClient("http://x/api/", opener=opener).login("a@x.de", "pw")
        client.get("/user", {"page": 2, "leer": None, "alle": True})
        client.logout()
        login, profile, logout = opener.requests
        self.assertEqual("http://x/api/login", login.full_url)
        self.assertEqual("api-client", json.loads(login.data)["device_name"])
        self.assertEqual("Bearer abc", profile.headers["Authorization"])
        self.assertEqual("http://x/api/user?page=2&alle=1", profile.full_url)
        self.assertEqual("http://x/api/logout", logout.full_url)

    def test_methods_send_json_and_handle_empty_answers(self):
        opener = Opener(json_response({"ok": 1}), FakeResponse(204), FakeResponse(200, b""), json_response([]))
        client = ApiClient("http://x/api", "t", opener=opener)
        self.assertEqual({"ok": 1}, client.put("/a", {"b": 1}))
        self.assertIsNone(client.delete("/a"))
        self.assertIsNone(client.patch("/a", {}))
        self.assertEqual([], client.post("/a"))
        self.assertEqual(["PUT", "DELETE", "PATCH", "POST"], [r.get_method() for r in opener.requests])
        self.assertEqual("application/json", opener.requests[0].headers["Content-type"])
        self.assertIsNone(opener.requests[1].data)

    def test_errors_carry_status_and_validation_messages(self):
        body = json.dumps({"message": "x", "errors": {"name": ["fehlt"]}}).encode()
        opener = Opener(HTTPError("http://x/api/a", 422, "x", {}, io.BytesIO(body)))
        with self.assertRaises(ApiError) as raised:
            ApiClient("http://x/api", opener=opener).post("/a", {})
        self.assertEqual(422, raised.exception.status)
        self.assertEqual("name: fehlt", raised.exception.summary())
        self.assertIn("POST /a -> 422", str(raised.exception))

    def test_error_summaries_fall_back_to_message_or_text(self):
        self.assertEqual("kaputt", ApiError("GET", "/a", 500, {"message": "kaputt"}).summary())
        self.assertEqual("{}", ApiError("GET", "/a", 500, {}).summary())
        self.assertEqual("Text", ApiError("GET", "/a", 500, "Text").summary())

    def test_upload_sends_multipart(self):
        opener = Opener(json_response({"id": 3}))
        result = ApiClient("http://x/api", opener=opener).upload("/f", {"name": "n"}, {"file": ("a.pdf", b"%PDF")})
        self.assertEqual({"id": 3}, result)
        self.assertTrue(opener.requests[0].headers["Content-type"].startswith("multipart/form-data"))

    def test_multipart_encoding(self):
        files = {"file": ("bild.png", b"PNG"), "x": ("datei", b"?")}
        body, content_type = encode_multipart({"a": True, "b": None, "c": 5}, files)
        text = body.decode("latin-1")
        self.assertIn('name="a"\r\n\r\n1\r\n', text)
        self.assertNotIn('name="b"', text)
        self.assertIn("Content-Type: image/png", text)
        self.assertIn("Content-Type: application/octet-stream", text)
        self.assertTrue(text.endswith("--\r\n"))
        self.assertIn(content_type.split("=")[1], text)

    def test_form_values_queries_and_decoding(self):
        self.assertEqual(("1", "0", "x"), (form_value(True), form_value(False), form_value("x")))
        self.assertEqual("/p", with_query("/p", None))
        self.assertEqual({"a": 1}, decode(b'{"a": 1}'))
        self.assertEqual("kein json", decode(b"kein json"))
        self.assertEqual("�", decode(b"\xff"))


if __name__ == "__main__":
    unittest.main()
