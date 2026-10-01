"""Exercise the isolated loopback prototype without printing credentials."""
from __future__ import annotations

import http.cookiejar
import json
import socket
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONFIG = json.loads((ROOT / '.runtime/local.json').read_text(encoding='utf-8'))
original_resolve = socket.getaddrinfo


def resolve(host, port, *args, **kwargs):
    if host in ('customer.localhost', 'admin.localhost'):
        host = '127.0.0.1'
    return original_resolve(host, port, *args, **kwargs)


socket.getaddrinfo = resolve


class Client:
    def __init__(self, host='customer.localhost'):
        self.host = host
        self.token = None
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(self.cookies))

    def request(self, method, path, payload=None, csrf=True, extra_headers=None):
        headers = {'Accept': 'application/json'}
        if payload is not None:
            headers['Content-Type'] = 'application/json'
        if csrf and self.token:
            headers['X-CSRF-TOKEN'] = self.token
        headers.update(extra_headers or {})
        data = None if payload is None else json.dumps(payload).encode()
        req = urllib.request.Request(f'http://{self.host}:18080{path}', data=data, headers=headers, method=method)
        try:
            response = self.opener.open(req, timeout=30)
        except urllib.error.HTTPError as exc:
            response = exc
        if response.headers.get('X-CSRF-TOKEN'):
            self.token = response.headers['X-CSRF-TOKEN']
        raw = response.read()
        try:
            body = json.loads(raw)
        except json.JSONDecodeError:
            raise AssertionError(f'Non-JSON response: {path}, status={response.status}') from None
        if isinstance(body, dict) and 'csrf_metadata' in body:
            self.token = body['csrf_metadata']['token_value']
        return response.status, body

    def login(self, identifier):
        assert self.request('GET', '/api/v1/auth/csrf')[0] == 200
        status, body = self.request('POST', '/api/v1/auth/login', {'login_identifier': identifier, 'credential': CONFIG['seed_password']})
        assert status == 200, f'Login status={status}, code={body.get("error",{}).get("code")}'
        return body


def main():
    client = Client()
    assert client.request('GET','/api/v1/auth/me')[0] == 401
    assert client.request('GET','/api/v1/auth/csrf')[0] == 200
    assert client.request('POST','/api/v1/auth/login',{'login_identifier':'membera@example.test','credential':'wrong-test-credential'})[0] == 401
    assert client.request('POST','/api/v1/auth/login',{'login_identifier':'membera@example.test','credential':CONFIG['seed_password']},csrf=False)[0] == 403
    login = client.login('membera@example.test')
    assert login['user_context']['groups'] == ['member']
    assert client.request('GET','/api/v1/auth/me')[0] == 200
    assert all(not cookie.domain_specified and cookie.has_nonstandard_attr('HttpOnly') for cookie in client.cookies)
    assert client.request('POST','/api/v1/auth/logout',{})[0] == 200
    assert client.request('GET','/api/v1/auth/me')[0] == 401
    admin = Client('admin.localhost')
    assert 'admin' in admin.login('admin@example.test')['user_context']['groups']
    assert admin.request('GET','/api/v1/auth/me')[0] == 200
    try:
        urllib.request.build_opener(urllib.request.ProxyHandler({})).open('http://127.0.0.1:18100/api/v1/auth/csrf',timeout=10)
        raise AssertionError('Direct API request was accepted')
    except urllib.error.HTTPError as exc:
        assert exc.code == 403
    print('P1 HTTP checks passed: authentication, CSRF, roles, host-only cookies, logout, ingress isolation')


if __name__ == '__main__':
    main()
