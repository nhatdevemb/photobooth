"""Own the Windows runtime in a Job Object. EOF from Electron stops the app's children."""
from __future__ import annotations

import ctypes
from ctypes import wintypes
from dataclasses import dataclass
from datetime import datetime
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import sys
import threading
import time
import urllib.request

if hasattr(sys.stdin, "reconfigure"):
    sys.stdin.reconfigure(encoding="utf-8")
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")


def emit(status, message):
    print(json.dumps({"status": status, "message": message}, ensure_ascii=True), flush=True)


def create_job():
    if os.name != "nt":
        raise RuntimeError("Supervisor này cần Windows.")

    class BasicLimit(ctypes.Structure):
        _fields_ = [("process_time", ctypes.c_int64), ("job_time", ctypes.c_int64),
                    ("flags", wintypes.DWORD), ("minimum", ctypes.c_size_t),
                    ("maximum", ctypes.c_size_t), ("active", wintypes.DWORD),
                    ("affinity", ctypes.c_size_t), ("priority", wintypes.DWORD),
                    ("scheduling", wintypes.DWORD)]

    class IoCounters(ctypes.Structure):
        _fields_ = [(key, ctypes.c_uint64) for key in ("read_ops", "write_ops", "other_ops", "read_bytes", "write_bytes", "other_bytes")]

    class ExtendedLimit(ctypes.Structure):
        _fields_ = [("basic", BasicLimit), ("io", IoCounters), ("process_memory", ctypes.c_size_t),
                    ("job_memory", ctypes.c_size_t), ("peak_process", ctypes.c_size_t), ("peak_job", ctypes.c_size_t)]

    kernel = ctypes.WinDLL("kernel32", use_last_error=True)
    kernel.CreateJobObjectW.restype = wintypes.HANDLE
    kernel.CreateJobObjectW.argtypes = [ctypes.c_void_p, wintypes.LPCWSTR]
    kernel.SetInformationJobObject.argtypes = [wintypes.HANDLE, ctypes.c_int, ctypes.c_void_p, wintypes.DWORD]
    kernel.AssignProcessToJobObject.argtypes = [wintypes.HANDLE, wintypes.HANDLE]
    kernel.GetCurrentProcess.restype = wintypes.HANDLE
    handle = kernel.CreateJobObjectW(None, None)
    limits = ExtendedLimit()
    limits.basic.flags = 0x2000  # JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE
    if not handle or not kernel.SetInformationJobObject(handle, 9, ctypes.byref(limits), ctypes.sizeof(limits)):
        raise ctypes.WinError(ctypes.get_last_error())
    # Put ourselves in the job BEFORE spawning children, eliminating an assignment race.
    if not kernel.AssignProcessToJobObject(handle, kernel.GetCurrentProcess()):
        raise ctypes.WinError(ctypes.get_last_error())
    return kernel, handle  # Keep the non-inheritable job handle alive until process exit.


@dataclass
class Service:
    name: str
    command: list
    cwd: Path
    env: dict | None = None
    required: bool = True
    isolated: bool = True
    process: subprocess.Popen | None = None
    started_at: float = 0
    retry_at: float = 0
    failures: int = 0

    def start(self, log):
        command = self.command
        if self.isolated:
            command = [sys.executable, str(Path(__file__).resolve()), "--service", self.name, *command]
        self.process = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=log, stderr=log,
                                        cwd=self.cwd, env=self.env, creationflags=subprocess.CREATE_NO_WINDOW)
        self.started_at = time.monotonic()
        log_event(log, f"{self.name} started (PID {self.process.pid})")

    def tick(self, log):
        now = time.monotonic()
        if self.process is not None:
            code = self.process.poll()
            if code is None:
                return
            if now - self.started_at >= 60:
                self.failures = 0
            reason = f"PID {self.process.pid}, mã thoát {code}"
            self.process = None
            self.schedule_retry(now, log, reason)
        elif now >= self.retry_at:
            try:
                self.start(log)
            except OSError as error:
                self.schedule_retry(now, log, str(error))

    def schedule_retry(self, now, log, reason):
        self.failures += 1
        if self.required and self.failures > 3:
            message = f"{self.name} đã dừng sau 3 lần phục hồi ({reason})."
            log_event(log, message)
            raise RuntimeError(message)
        delay = (1, 2, 4, 10, 30)[min(self.failures - 1, 4)]
        self.retry_at = now + delay
        log_event(log, f"{self.name} đã dừng ({reason}). Khởi động lại sau {delay} giây.")


def log_event(log, message):
    timestamp = datetime.now().astimezone().isoformat(timespec="seconds")
    log.write(f"[{timestamp}] [supervisor] {message}\n".encode("utf-8"))


def run_isolated_service(name, command):
    # Attach before spawning so a crashed PHP process cannot leave a renderer
    # holding a lock or writing to a job's files when the replacement starts.
    job = create_job()
    child = subprocess.Popen(command, creationflags=subprocess.CREATE_NO_WINDOW)
    print(f"[service] {name} executable PID {child.pid}", flush=True)
    code = child.wait()
    print(f"[service] {name} executable PID {child.pid} exited ({code})", flush=True)
    _ = job  # The OS closes this handle on process exit and stops remaining children.
    return 0 if code == 0 else 1


def reserve_ports(operator_port, guest_port):
    sockets = []
    try:
        for host, port in [("127.0.0.1", operator_port), ("0.0.0.0", guest_port)] + [("127.0.0.1", 0)] * 5:
            sock = socket.socket()
            if os.name == "nt":
                sock.setsockopt(socket.SOL_SOCKET, socket.SO_EXCLUSIVEADDRUSE, 1)
            sock.bind((host, port))
            sockets.append(sock)
        ephemeral = [sock.getsockname()[1] for sock in sockets[2:]]
        return sockets, ephemeral[:4], ephemeral[4]
    except Exception:
        for sock in sockets:
            sock.close()
        raise RuntimeError("Cổng operator/guest đang bận. Đổi cổng hoặc đóng ứng dụng đang dùng cổng.")


def caddy_config(public, settings, ports, data):
    upstreams = " ".join(f"127.0.0.1:{port}" for port in ports)
    public = str(public).replace("\\", "/")
    blocks = []
    for host, port, script in [("127.0.0.1", settings["operatorPort"], "index.php"), ("0.0.0.0", settings["guestPort"], "guest.php")]:
        blocks.append(f'''http://:{port} {{
    bind {host}
    reverse_proxy {upstreams} {{
        lb_policy least_conn
        lb_try_duration 1s
        fail_duration 3s
        lb_retry_match {{
            method GET HEAD
        }}
        transport fastcgi {{
            root {json.dumps(public)}
            split .php
            env SCRIPT_FILENAME {json.dumps(public + '/' + script)}
            env SCRIPT_NAME /{script}
            env REQUEST_URI {{http.request.orig_uri}}
            read_timeout 120s
        }}
    }}
}}''')
    storage = str(Path(data) / 'caddy').replace('\\', '/')
    return '{\n admin off\n auto_https off\n persist_config off\n storage file_system {\n root ' + json.dumps(storage) + '\n }\n}\n' + '\n'.join(blocks) + '\n'


def main():
    payload = json.loads(sys.stdin.readline())
    project, runtime, data = [Path(payload[key]).resolve() for key in ("project", "runtime", "data")]
    settings = payload["settings"]
    (data / "logs").mkdir(parents=True, exist_ok=True)
    (data / "tmp").mkdir(exist_ok=True)
    sockets, ports, daemon_port = reserve_ports(int(settings["operatorPort"]), int(settings["guestPort"]))
    daemon_token = secrets.token_hex(32)
    daemon_url = f"http://127.0.0.1:{daemon_port}"
    job = create_job()
    php = runtime / "php" / "php.exe"
    ini = data / "php-desktop.ini"
    extension_dir = str(runtime / "php" / "ext").replace("\\", "/")
    temp = str(data / "tmp").replace("\\", "/")
    ini.write_text(f'extension_dir="{extension_dir}"\nsys_temp_dir="{temp}"\n' +
                   "\n".join("extension=" + name for name in ("pdo_sqlite", "sqlite3", "gd", "mbstring", "curl", "openssl", "zip", "fileinfo")) +
                   '\ndisplay_errors=Off\nlog_errors=On\ncgi.fix_pathinfo=0\ncgi.force_redirect=0\nupload_max_filesize=32M\npost_max_size=64M\n', encoding="utf-8")
    flags = subprocess.CREATE_NO_WINDOW
    emit("starting", "Đang kiểm tra database và tài khoản…")
    # PHP's Windows CLI can silently ignore a -c path containing non-ASCII characters.
    # Relative extension paths and an explicit argument list avoid that code-page dependency.
    php_options = ["-n", "-d", "extension_dir=ext"]
    for extension in ("pdo_sqlite", "sqlite3", "gd", "mbstring", "curl", "openssl", "zip", "fileinfo"):
        php_options.extend(["-d", "extension=" + extension])
    for option in ("display_errors=Off", "log_errors=On", "cgi.fix_pathinfo=0", "cgi.force_redirect=0", "upload_max_filesize=32M", "post_max_size=64M"):
        php_options.extend(["-d", option])
    bootstrap = subprocess.run([str(php), *php_options, str(project / "custom/bin/desktop-bootstrap.php")],
                               input=json.dumps(payload.get("account", {})), text=True, encoding="utf-8", capture_output=True,
                               cwd=runtime / "php", creationflags=flags, timeout=45)
    if bootstrap.returncode:
        raise RuntimeError(bootstrap.stderr.strip() or "Không khởi tạo được database.")
    payload.pop("account", None)
    stopped = threading.Event()
    threading.Thread(target=lambda: (sys.stdin.read(), stopped.set()), daemon=True).start()
    log = (data / "logs/desktop-runtime.log").open("ab", buffering=0)
    log_event(log, f"Runtime started (PID {os.getpid()})")
    # Individual FastCGI, Image Daemon, and Beauty failures can degrade without stopping the booth.
    daemon_env = {**os.environ, "PYTHONDONTWRITEBYTECODE": "1", "IMAGE_DAEMON_TOKEN": daemon_token}
    php_env = {**os.environ, "IMAGE_DAEMON_URL": daemon_url, "IMAGE_DAEMON_TOKEN": daemon_token}
    cgi_env = {**php_env, "PHP_FCGI_MAX_REQUESTS": "0"}
    config = data / "Caddyfile"
    services = [
        Service("Python Image Daemon",
                [sys.executable, "-B", str(project / "custom/python/image_daemon.py"), "--host", "127.0.0.1", "--port", str(daemon_port)],
                project / "custom/python", env=daemon_env, required=False),
        *[
            Service(f"PHP FastCGI :{port}",
                    [str(runtime / "php/php-cgi.exe"), *php_options, "-b", f"127.0.0.1:{port}"],
                    runtime / "php", env=cgi_env, required=False)
            for port in ports
        ],
    ]
    services.extend([
        Service("Caddy", [str(runtime / "caddy/caddy.exe"), "run", "--config", str(config), "--adapter", "caddyfile"],
                project, isolated=False),
        Service("Worker chụp/ghép/in", [str(php), *php_options, str(project / "custom/bin/worker.php")],
                runtime / "php", env=php_env),
        Service("Worker Beauty", [str(php), *php_options, str(project / "custom/bin/beauty-worker.php")],
                runtime / "php", env=php_env, required=False),
    ])
    maintenance = None
    try:
        config.write_text(caddy_config(project / "custom/public", settings, ports, data), encoding="utf-8")
        # Release the reserved daemon port right as Python Image Daemon starts to minimize port race window on Windows.
        sockets.pop().close()
        services[0].tick(log)
        for sock in sockets:
            sock.close()
        sockets.clear()
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        deadline = time.monotonic() + 20
        while time.monotonic() < deadline:
            for service in services:
                service.tick(log)
            try:
                with opener.open(f'http://127.0.0.1:{settings["operatorPort"]}/', timeout=1) as response:
                    if response.status == 200:
                        break
            except OSError:
                time.sleep(0.15)
        else:
            raise RuntimeError("Web server chưa sẵn sàng. Xem logs/desktop-runtime.log.")
        emit("ready", "Photobooth đã sẵn sàng.")
        next_maintenance = time.monotonic() + 300
        while not stopped.wait(0.25):
            for service in services:
                service.tick(log)
            if maintenance is not None and (code := maintenance.poll()) is not None:
                if code != 0:
                    log_event(log, f"Bảo trì thoát với mã {code}.")
                maintenance = None
            if maintenance is None and time.monotonic() >= next_maintenance:
                try:
                    maintenance = subprocess.Popen(
                        [str(php), *php_options, str(project / "custom/bin/maintenance.php")],
                        stdin=subprocess.DEVNULL, stdout=log, stderr=log, cwd=runtime / "php", env=php_env, creationflags=flags)
                    next_maintenance = time.monotonic() + 3600
                except OSError as error:
                    log_event(log, f"Không khởi động được bảo trì ({error}). Thử lại sau 60 giây.")
                    next_maintenance = time.monotonic() + 60
    finally:
        for sock in sockets:
            sock.close()
        children = [service.process for service in reversed(services) if service.process is not None]
        if maintenance is not None:
            children.append(maintenance)
        for child in children:
            if child.poll() is None:
                child.terminate()
        for child in children:
            try:
                child.wait(timeout=3)
            except subprocess.TimeoutExpired:
                child.kill()
        log.close()
        # Process exit closes the Job Object; descendant Python renders are killed too.
        _ = job


if __name__ == "__main__":
    try:
        if len(sys.argv) > 3 and sys.argv[1] == "--service":
            sys.exit(run_isolated_service(sys.argv[2], sys.argv[3:]))
        main()
    except Exception as error:
        emit("error", str(error))
        sys.exit(1)
