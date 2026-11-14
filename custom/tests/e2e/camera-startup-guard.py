"""A failed duplicate camera launch must not rotate the working account password."""
import fcntl
import os
from pathlib import Path
import socket
import sqlite3
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]

with tempfile.TemporaryDirectory(prefix='pb-camera-guard-') as directory:
    folder = Path(directory)
    data = folder / 'data'
    data.mkdir()
    (data / '.camera-test').touch()
    env = {**os.environ, 'DATA_DIR': str(data), 'DATABASE_PATH': str(data / 'db/photobooth.sqlite'), 'CAMERA_ADAPTER': 'browser', 'PRINTER_ADAPTER': 'mock', 'DISK_LOW_BYTES': '1048576'}
    subprocess.run(['php', '-r', "require 'vendor/autoload.php'; $c = new \\Photobooth\\Operator\\Container(); $c->authService->createUser('camera-test', 'TestFixtureOnly123!', 'admin');"], cwd=ROOT, env=env, check=True, capture_output=True)

    def password_hash():
        with sqlite3.connect(data / 'db/photobooth.sqlite') as db:
            return db.execute("SELECT password_hash FROM users WHERE username='camera-test'").fetchone()[0]

    cert, key = folder / 'cert.pem', folder / 'key.pem'
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', str(key), '-out', str(cert), '-days', '1', '-subj', '/CN=localhost'], check=True, capture_output=True)
    with socket.socket() as local:
        local.bind(('127.0.0.1', 0))
        http_port = local.getsockname()[1]
    command = ['python3', str(ROOT / 'bin/camera-test.py'), '--lan-ip', '192.168.101.9', '--data-dir', str(data), '--cert', str(cert), '--key', str(key), '--http-port', str(http_port)]
    failures = []
    (data / 'locks').mkdir(exist_ok=True)
    with (data / 'locks/worker.lock').open('a+') as lock, socket.socket() as temporary:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        temporary.bind(('127.0.0.1', 0))
        port = temporary.getsockname()[1]
        temporary.close()
        before = password_hash()
        result = subprocess.run(command + ['--port', str(port)], cwd=ROOT, env=env, capture_output=True, text=True, timeout=15)
        if result.returncode == 0 or 'WORKER_ALREADY_RUNNING' not in result.stderr or 'Traceback' in result.stderr:
            failures.append('Duplicate worker must produce WORKER_ALREADY_RUNNING without a traceback')
        if password_hash() != before:
            failures.append('Duplicate worker startup changed the existing login password')
    with socket.socket() as busy:
        busy.bind(('0.0.0.0', 0))
        busy.listen()
        result = subprocess.run(command + ['--port', str(busy.getsockname()[1])], cwd=ROOT, env=env, capture_output=True, text=True, timeout=15)
        if result.returncode == 0 or 'PORT_IN_USE' not in result.stderr or 'Traceback' in result.stderr:
            failures.append('Busy HTTPS port must produce PORT_IN_USE without a traceback')
    with socket.socket() as busy, socket.socket() as available:
        busy.bind(('127.0.0.1', 0))
        busy.listen()
        available.bind(('127.0.0.1', 0))
        https_port = available.getsockname()[1]
        available.close()
        http_command = command.copy()
        http_command[http_command.index('--http-port') + 1] = str(busy.getsockname()[1])
        before = password_hash()
        result = subprocess.run(http_command + ['--port', str(https_port)], cwd=ROOT, env=env, capture_output=True, text=True, timeout=15)
        if result.returncode == 0 or 'PORT_IN_USE' not in result.stderr or '--http-port' not in result.stderr or 'Traceback' in result.stderr:
            failures.append('Busy localhost HTTP port must produce a clear --http-port diagnostic')
        if password_hash() != before:
            failures.append('Busy localhost HTTP port changed the existing login password')
    if failures:
        raise SystemExit('\n'.join(failures))
    print('Duplicate worker/password preservation + busy HTTP/HTTPS port diagnostics: PASS')
