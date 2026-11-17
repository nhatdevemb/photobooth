"""Own the Windows runtime in a Job Object. EOF from Electron stops the app's children."""
from __future__ import annotations

import ctypes
from ctypes import wintypes
import json
import os
from pathlib import Path
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


def reserve_ports(operator_port, guest_port):
    sockets = []
    try:
        for host, port in [("127.0.0.1", operator_port), ("0.0.0.0", guest_port)] + [("127.0.0.1", 0)] * 4:
            sock = socket.socket()
            if os.name == "nt":
                sock.setsockopt(socket.SOL_SOCKET, socket.SO_EXCLUSIVEADDRUSE, 1)
            sock.bind((host, port))
            sockets.append(sock)
        return sockets, [sock.getsockname()[1] for sock in sockets[2:]]
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
        transport fastcgi {{
            root {json.dumps(public)}
            split .php
            env SCRIPT_FILENAME {json.dumps(public + '/' + script)}
            env SCRIPT_NAME /{script}
            env REQUEST_URI {{http.request.orig_uri}}
            read_timeout 45s
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
    sockets, ports = reserve_ports(int(settings["operatorPort"]), int(settings["guestPort"]))
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
    children = []
    for sock in sockets:
        sock.close()
    try:
        for port in ports:
            children.append(subprocess.Popen([str(runtime / "php/php-cgi.exe"), *php_options, "-b", f"127.0.0.1:{port}"],
                                             stdin=subprocess.DEVNULL, stdout=log, stderr=log, cwd=runtime / "php", creationflags=flags))
        config = data / "Caddyfile"
        config.write_text(caddy_config(project / "custom/public", settings, ports, data), encoding="utf-8")
        children.append(subprocess.Popen([str(runtime / "caddy/caddy.exe"), "run", "--config", str(config), "--adapter", "caddyfile"],
                                         stdin=subprocess.DEVNULL, stdout=log, stderr=log, cwd=project, creationflags=flags))
        children.append(subprocess.Popen([str(php), *php_options, str(project / "custom/bin/worker.php")],
                                         stdin=subprocess.DEVNULL, stdout=log, stderr=log, cwd=runtime / "php", creationflags=flags))
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        deadline = time.monotonic() + 20
        while time.monotonic() < deadline:
            if any(child.poll() is not None for child in children):
                raise RuntimeError("Dịch vụ không khởi động được. Xem logs/desktop-runtime.log.")
            try:
                with opener.open(f'http://127.0.0.1:{settings["operatorPort"]}/', timeout=1) as response:
                    if response.status == 200:
                        break
            except OSError:
                time.sleep(0.15)
        else:
            raise RuntimeError("Web server chưa sẵn sàng. Xem logs/desktop-runtime.log.")
        emit("ready", "Photobooth đã sẵn sàng.")
        maintenance = None
        next_maintenance = time.monotonic() + 300
        while not stopped.wait(0.25):
            if any(child.poll() is not None for child in children):
                raise RuntimeError("Một dịch vụ đã dừng; không tự gửi lại lệnh chụp.")
            if time.monotonic() >= next_maintenance and (maintenance is None or maintenance.poll() is not None):
                maintenance = subprocess.Popen([str(php), *php_options, str(project / "custom/bin/maintenance.php")],
                                               stdin=subprocess.DEVNULL, stdout=log, stderr=log, cwd=runtime / "php", creationflags=flags)
                next_maintenance = time.monotonic() + 3600
    finally:
        for child in reversed(children):
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
        main()
    except Exception as error:
        emit("error", str(error))
        sys.exit(1)
