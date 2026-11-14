"""Smoke test the isolated HTTP and TLS launchers, including a real multipart upload."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import socket
import ssl
import subprocess
import tempfile
import time
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[2]


def free_port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]


def run_case(temp, tls):
    port = free_port()
    http_port = free_port() if tls else port
    while tls and http_port == port:
        http_port = free_port()
    output = temp / 'launcher.log'
    command = ['python3', str(ROOT / 'bin/camera-test.py'), '--port', str(port), '--lan-ip', '192.168.1.50', '--data-dir', str(temp / 'data')]
    if tls:
        cert, key = temp / 'cert.pem', temp / 'key.pem'
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', str(key), '-out', str(cert), '-days', '1', '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost,IP:127.0.0.1,IP:192.168.1.50'], check=True, capture_output=True)
        command += ['--cert', str(cert), '--key', str(key), '--http-port', str(http_port)]
    with output.open('w') as log:
        process = subprocess.Popen(command, stdout=log, stderr=log, env={**os.environ, 'DISK_LOW_BYTES': '1048576'})
        try:
            deadline = time.monotonic() + 20
            credentials = None
            while time.monotonic() < deadline:
                credentials = re.search(r'camera-test / (\S+)', output.read_text())
                if credentials or process.poll() is not None:
                    break
                time.sleep(0.1)
            assert credentials and process.poll() is None, output.read_text()
            base = f'{"https" if tls else "http"}://127.0.0.1:{port}'
            jar = http.cookiejar.CookieJar()
            handlers = [urllib.request.HTTPCookieProcessor(jar)]
            if tls:
                handlers.append(urllib.request.HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
            client = urllib.request.build_opener(*handlers)

            def request(method, path, payload=None, csrf=None, key=None, multipart=False):
                headers = {'Origin': base}
                if csrf:
                    headers['X-CSRF-Token'] = csrf
                    headers['Idempotency-Key'] = key or str(uuid.uuid4())
                body = payload if multipart else json.dumps(payload).encode() if payload is not None else None
                headers['Content-Type'] = 'multipart/form-data; boundary=pb-camera-test' if multipart else 'application/json'
                with client.open(urllib.request.Request(base + path, data=body, headers=headers, method=method), timeout=20) as response:
                    return response.status, response.read(), response.headers

            assert 'laptop / điện thoại' in request('GET', '/')[1].decode()
            auth = json.loads(request('POST', '/custom/api/auth/login', {'username': 'camera-test', 'password': credentials[1]})[1])
            if tls:
                assert all(cookie.secure for cookie in jar)
            csrf = auth['csrf_token']
            session = json.loads(request('POST', '/custom/api/sessions', {}, csrf)[1])
            photo = (ROOT / 'tests/fixtures/slot-1-v1.jpg').read_bytes()
            body = (f'--pb-camera-test\r\nContent-Disposition: form-data; name="revision"\r\n\r\n{session["revision"]}\r\n--pb-camera-test\r\nContent-Disposition: form-data; name="file"; filename="camera.jpg"\r\nContent-Type: image/jpeg\r\n\r\n'.encode() + photo + b'\r\n--pb-camera-test--\r\n')
            path = f'/custom/api/sessions/{session["id"]}/slots/1/capture'
            key = str(uuid.uuid4())
            status, response, _ = request('POST', path, body, csrf, key, True)
            assert status == 202
            assert request('POST', path, body, csrf, key, True)[1] == response
            job_id = json.loads(response)['job_id']
            deadline = time.monotonic() + 20
            while time.monotonic() < deadline:
                job = json.loads(request('GET', f'/custom/api/jobs/{job_id}')[1])
                if job['status'] in ('SUCCEEDED', 'FAILED'):
                    break
                time.sleep(0.1)
            assert job['status'] == 'SUCCEEDED', job
            snapshot = json.loads(request('GET', f'/custom/api/sessions/{session["id"]}')[1])
            assert snapshot['configuration_locked'] and snapshot['accepted_slots'] == 1
            assert request('GET', snapshot['slots'][0]['photo']['url'])[1] == photo
            if tls:
                # The loopback HTTP UI and LAN HTTPS UI share the same session,
                # assets and worker, with independent browser login cookies.
                local_base = f'http://localhost:{http_port}'
                local_jar = http.cookiejar.CookieJar()
                local_client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(local_jar))

                def local_request(method, path, payload=None, csrf=None, key=None, multipart=False):
                    headers = {'Origin': local_base}
                    if csrf:
                        headers['X-CSRF-Token'] = csrf
                        headers['Idempotency-Key'] = key or str(uuid.uuid4())
                    body = payload if multipart else json.dumps(payload).encode() if payload is not None else None
                    headers['Content-Type'] = 'multipart/form-data; boundary=pb-camera-test' if multipart else 'application/json'
                    with local_client.open(urllib.request.Request(local_base + path, data=body, headers=headers, method=method), timeout=20) as response:
                        return response.status, response.read()

                assert 'laptop / điện thoại' in local_request('GET', '/')[1].decode()
                local_auth = json.loads(local_request('POST', '/custom/api/auth/login', {'username': 'camera-test', 'password': credentials[1]})[1])
                assert list(local_jar) and all(not cookie.secure for cookie in local_jar)
                shared = json.loads(local_request('GET', '/custom/api/sessions/active')[1])['active_session']
                assert shared['id'] == session['id'] and shared['accepted_slots'] == 1
                assert local_request('GET', shared['slots'][0]['photo']['url'])[1] == photo
                assert local_request('POST', path, body, local_auth['csrf_token'], key, True)[1] == response
                second_body = (f'--pb-camera-test\r\nContent-Disposition: form-data; name="revision"\r\n\r\n{shared["revision"]}\r\n--pb-camera-test\r\nContent-Disposition: form-data; name="file"; filename="camera.jpg"\r\nContent-Type: image/jpeg\r\n\r\n'.encode() + photo + b'\r\n--pb-camera-test--\r\n')
                second_path = f'/custom/api/sessions/{session["id"]}/slots/2/capture'
                status, second = local_request('POST', second_path, second_body, local_auth['csrf_token'], multipart=True)
                assert status == 202
                deadline = time.monotonic() + 20
                second_id = json.loads(second)['job_id']
                while time.monotonic() < deadline:
                    second_job = json.loads(request('GET', f'/custom/api/jobs/{second_id}')[1])
                    if second_job['status'] in ('SUCCEEDED', 'FAILED'):
                        break
                    time.sleep(0.1)
                assert second_job['status'] == 'SUCCEEDED', second_job
                shared = json.loads(request('GET', '/custom/api/sessions/active')[1])['active_session']
                assert shared['id'] == session['id'] and shared['accepted_slots'] == 2
                assert all(cookie.secure for cookie in jar)
                print('Simultaneous localhost HTTP + HTTPS: shared capture/session/replay/assets, separate secure cookies: PASS')
            print(f'{"HTTPS" if tls else "HTTP"} camera launcher + auth + multipart + replay + worker + original bytes: PASS')
        finally:
            process.terminate()
            process.wait(timeout=15)
    assert process.returncode == 0, output.read_text()
    for closed_port in {port, http_port}:
        with socket.socket() as probe:
            probe.settimeout(0.2)
            assert probe.connect_ex(('127.0.0.1', closed_port)) != 0, 'A listener was left running after shutdown'


with tempfile.TemporaryDirectory(prefix='pb-camera-launcher-') as directory:
    temp = Path(directory)
    for tls in (False, True):
        case = temp / ('tls' if tls else 'http')
        case.mkdir()
        run_case(case, tls)
