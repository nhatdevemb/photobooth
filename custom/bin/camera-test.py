#!/usr/bin/env python3
"""Start an isolated camera booth with localhost HTTP and optional LAN HTTPS."""
import argparse
import errno
import fcntl
import http.client
import ipaddress
import os
from pathlib import Path
import secrets
import signal
import socket
import ssl
import subprocess
import sys
import threading
import time
import webbrowser
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


def free_port():
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        return listener.getsockname()[1]


def lan_address():
    result = subprocess.run(['hostname', '-I'], capture_output=True, text=True, check=False)
    for value in result.stdout.split():
        address = ipaddress.ip_address(value)
        if address.version == 4 and not address.is_loopback:
            return value
    return '192.168.1.50'


def proxy_handler(operator_port, guest_port):
    class Proxy(BaseHTTPRequestHandler):
        def forward(self):
            self.connection.settimeout(30)
            if self.headers.get('Transfer-Encoding'):
                self.send_error(400, 'Use Content-Length')
                return
            try:
                length = int(self.headers.get('Content-Length', '0'))
            except ValueError:
                self.send_error(400)
                return
            if length < 0 or length > 32 * 1024 * 1024:
                self.send_error(413)
                return
            port = guest_port if self.path.startswith('/share/') else operator_port
            upstream = http.client.HTTPConnection('127.0.0.1', port, timeout=60)
            skip = {'connection', 'keep-alive', 'transfer-encoding', 'upgrade', 'te', 'trailer', 'proxy-authorization', 'proxy-authenticate'}
            headers = {name: value for name, value in self.headers.items() if name.lower() not in skip}
            try:
                body = self.rfile.read(length) if length else None
                if body is not None and len(body) != length:
                    self.send_error(400)
                    return
                upstream.request(self.command, self.path, body, headers)
                response = upstream.getresponse()
                self.send_response(response.status)
                for name, value in response.getheaders():
                    if name.lower() not in skip:
                        if name.lower() == 'set-cookie' and '; secure' not in value.lower():
                            value += '; Secure'
                        self.send_header(name, value)
                self.end_headers()
                while chunk := response.read(64 * 1024):
                    self.wfile.write(chunk)
            except (OSError, http.client.HTTPException):
                # The app's own log contains details; never expose local paths to clients.
                self.close_connection = True
            finally:
                upstream.close()

        do_GET = forward
        do_POST = forward
        do_HEAD = forward

        def log_message(self, message, *args):
            pass

    return Proxy


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--lan-ip', default=None, help='Ubuntu IPv4 address reachable from the phone')
    parser.add_argument('--cert', type=Path, help='PEM certificate trusted by laptop/phone, with LAN IP in SAN')
    parser.add_argument('--key', type=Path, help='Private key matching --cert')
    parser.add_argument('--port', type=int, default=None, help='HTTP port without a certificate; HTTPS port with --cert/--key')
    parser.add_argument('--http-port', type=int, default=None, help='Local HTTP port alongside HTTPS (default: 8080); requires --cert/--key')
    parser.add_argument('--open-browser', action='store_true', help='Open the laptop UI after startup when a desktop session is available')
    parser.add_argument('--data-dir', type=Path, default=Path.home() / '.cache/photobooth/camera-test')
    args = parser.parse_args()
    if bool(args.cert) != bool(args.key):
        parser.error('--cert and --key are required together')
    if args.http_port is not None and not args.cert:
        parser.error('--http-port requires --cert/--key; use --port for HTTP-only mode')
    lan_ip = args.lan_ip or lan_address()
    if ipaddress.ip_address(lan_ip).version != 4 or ipaddress.ip_address(lan_ip).is_loopback:
        parser.error('--lan-ip must be a non-loopback IPv4 address')
    port = args.port if args.port is not None else (8443 if args.cert else 8080)
    operator_port = (args.http_port if args.http_port is not None else 8080) if args.cert else port
    if not 1 <= port <= 65535 or not 1 <= operator_port <= 65535:
        parser.error('Ports must be between 1 and 65535')
    if args.cert and operator_port == port:
        parser.error('HTTP and HTTPS need different ports; select a different --http-port')
    root = Path(__file__).resolve().parents[1]
    data = args.data_dir.resolve()
    marker = data / '.camera-test'
    if data.exists() and any(data.iterdir()) and not marker.is_file():
        parser.error('Use an empty test data directory; existing app data is never reused')
    data.mkdir(parents=True, exist_ok=True, mode=0o700)
    marker.touch(mode=0o600)
    # Hold the launcher lock until all child services have stopped. Legacy
    # launches did not use it, so also check the worker's real singleton lock.
    launcher_lock = (data / '.launcher.lock').open('a+')
    try:
        fcntl.flock(launcher_lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        launcher_lock.close()
        raise RuntimeError('WORKER_ALREADY_RUNNING: Phiên camera test đang chạy. Dừng phiên cũ bằng Ctrl+C trước khi chạy lại; đổi cổng không giải phóng worker dùng chung.') from None
    worker_lock = data / 'locks/worker.lock'
    if worker_lock.exists():
        with worker_lock.open('a+') as handle:
            try:
                fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise RuntimeError('WORKER_ALREADY_RUNNING: Worker của phiên camera cũ vẫn đang chạy. Quay lại terminal cũ và nhấn Ctrl+C, rồi chạy lại. Tài khoản hiện tại được giữ nguyên.') from None
    password = secrets.token_urlsafe(16) + 'Aa1!'
    signing_key = data / '.share-key'
    if not signing_key.exists():
        with signing_key.open('x') as handle:
            os.chmod(signing_key, 0o600)
            handle.write(secrets.token_hex(32))
    guest_port = free_port()
    while guest_port in (operator_port, port):
        guest_port = free_port()
    share_base = f'https://{lan_ip}:{port}' if args.cert else f'http://{lan_ip}:{guest_port}'
    env = {
        **os.environ,
        'APP_ENV': 'development', 'DATA_DIR': str(data),
        'DATABASE_PATH': str(data / 'db/photobooth.sqlite'),
        'CAMERA_ADAPTER': 'browser', 'PRINTER_ADAPTER': 'mock',
        'TRUSTED_HOSTS': f'127.0.0.1,localhost,{lan_ip}',
        'SHARE_BASE_URL': share_base, 'SHARE_SIGNING_KEY': signing_key.read_text(),
        'PB_CAMERA_TEST_PASSWORD': password,
        'MOCK_PRINTER_SCENARIO': 'normal', 'MOCK_FAULT_SCENARIO': 'none',
    }
    server = None
    server_thread = None
    # Check before changing the test account. HTTP stays on loopback even
    # when the HTTPS listener is reachable over the LAN.
    with socket.socket() as local_listener:
        try:
            local_listener.bind(('127.0.0.1', operator_port))
        except OSError as error:
            if error.errno == errno.EADDRINUSE:
                option = '--http-port' if args.cert else '--port'
                raise RuntimeError(f'PORT_IN_USE: Cổng HTTP local {operator_port} đang được sử dụng. Dừng phiên cũ hoặc chọn cổng khác với {option}.') from None
            raise
    if args.cert:
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(args.cert, args.key)
        try:
            server = ThreadingHTTPServer(('0.0.0.0', port), proxy_handler(operator_port, guest_port))
        except OSError as error:
            if error.errno == errno.EADDRINUSE:
                raise RuntimeError(f'PORT_IN_USE: Cổng {port} đang được sử dụng. Dừng phiên cũ bằng Ctrl+C hoặc chọn cổng khác với --port.') from None
            raise
        server.daemon_threads = True
        server.socket = context.wrap_socket(server.socket, server_side=True)
    children = []
    with (data / 'dev-server.log').open('a') as log:
        try:
            subprocess.run([
                'php', '-r', "require 'vendor/autoload.php'; $c = new \\Photobooth\\Operator\\Container(); $c->authService->createUser('camera-test', getenv('PB_CAMERA_TEST_PASSWORD'), 'admin');",
            ], cwd=root, env=env, check=True, stdout=log, stderr=log)
            env.pop('PB_CAMERA_TEST_PASSWORD')
            commands = [
                ['php', str(root / 'bin/worker.php'), '--sleep-ms=100'],
                ['php', '-S', f'{"127.0.0.1" if args.cert else "0.0.0.0"}:{guest_port}', str(root / 'public/guest.php')],
                ['php', '-d', 'upload_max_filesize=5M', '-d', 'post_max_size=32M', '-S', f'127.0.0.1:{operator_port}', str(root / 'public/index.php')],
            ]
            for command in commands:
                children.append(subprocess.Popen(command, cwd=root, env=env, stdout=log, stderr=log))
            # Wait until the operator listener accepts connections before printing credentials.
            deadline = time.monotonic() + 10
            while True:
                if any(child.poll() is not None for child in children):
                    raise RuntimeError(f'A service failed; see {data / "dev-server.log"}')
                try:
                    with socket.create_connection(('127.0.0.1', operator_port), timeout=0.2):
                        break
                except OSError:
                    if time.monotonic() > deadline:
                        raise RuntimeError('Operator server did not start')
                    time.sleep(0.1)
            if server:
                server_thread = threading.Thread(target=server.serve_forever, daemon=True)
                server_thread.start()
            print(f'Laptop HTTP: http://localhost:{operator_port}', flush=True)
            if args.cert:
                print(f'Laptop HTTPS: https://localhost:{port}', flush=True)
                print(f'Điện thoại cùng Wi-Fi: https://{lan_ip}:{port}', flush=True)
            else:
                print('Điện thoại cần --cert/--key với HTTPS được tin cậy; xem plan/webcam-testing.md.', flush=True)
            print(f'Tài khoản test: camera-test / {password}\nMáy in MÔ PHỎNG. Dữ liệu: {data}\nCtrl+C để dừng.', flush=True)
            if args.open_browser:
                if os.environ.get('DISPLAY') or os.environ.get('WAYLAND_DISPLAY'):
                    try:
                        if not webbrowser.open(f'http://localhost:{operator_port}', new=2):
                            print('Không tự mở được trình duyệt. Hãy mở địa chỉ Laptop ở trên.', flush=True)
                    except (webbrowser.Error, OSError):
                        print('Không tự mở được trình duyệt. Hãy mở địa chỉ Laptop ở trên.', flush=True)
                else:
                    print('Không có desktop trong terminal này. Hãy mở địa chỉ Laptop ở trên bằng trình duyệt trên laptop.', flush=True)
            while all(child.poll() is None for child in children):
                time.sleep(0.25)
            raise RuntimeError(f'A service stopped; see {data / "dev-server.log"}')
        except KeyboardInterrupt:
            pass
        finally:
            if server:
                if server_thread:
                    server.shutdown()
                    server_thread.join(timeout=5)
                server.server_close()
            for child in children:
                if child.poll() is None:
                    child.terminate()
            for child in children:
                try:
                    child.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    child.kill()
                    child.wait()
            launcher_lock.close()


def stop(*_):
    raise KeyboardInterrupt


if __name__ == '__main__':
    signal.signal(signal.SIGTERM, stop)
    try:
        main()
    except (OSError, RuntimeError, subprocess.CalledProcessError) as error:
        print(f'Lỗi: {error}', file=sys.stderr)
        sys.exit(1)
