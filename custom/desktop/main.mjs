import { app, BrowserWindow, ipcMain, dialog, Menu } from 'electron';
import { spawn } from 'node:child_process';
import { readFile, writeFile, appendFile, mkdir, access, stat } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import os from 'node:os';
import { randomBytes } from 'node:crypto';
import { validateSettings, publicSettings } from './lib/settings.mjs';
import { dccResponds } from './lib/dcc-probe.mjs';

const directory = path.dirname(fileURLToPath(import.meta.url));
const resources = app.isPackaged ? process.resourcesPath : directory;
const project = app.isPackaged ? path.join(resources, 'app') : path.resolve(directory, '../..');
let runtime = path.join(resources, 'runtime');
const data = process.env.PB_DESKTOP_DATA_DIR ? path.resolve(process.env.PB_DESKTOP_DATA_DIR) : path.join(app.getPath('appData'), '..', 'Local', 'PhotoboothOperator', 'data');
if (process.env.PB_DESKTOP_DATA_DIR) {
    app.setPath('userData', path.join(path.dirname(data), 'electron-profile'));
    app.setPath('sessionData', path.join(path.dirname(data), 'electron-session'));
    app.setPath('crashDumps', path.join(path.dirname(data), 'electron-crashes'));
}
const setupUrl = pathToFileURL(path.join(directory, 'setup.html')).href;
const lkmagicUrl = new URL(process.env.LKMAGIC_BRIDGE_URL || ('http://127.0.0.1:' + (process.env.LK_PHOTOBOOTH_PORT || 5520)));
if (lkmagicUrl.protocol !== 'http:' || !['127.0.0.1', '[::1]', 'localhost'].includes(lkmagicUrl.hostname)
    || lkmagicUrl.username || lkmagicUrl.password || lkmagicUrl.pathname !== '/' || lkmagicUrl.search || lkmagicUrl.hash) {
    throw new Error('LKMAGIC_BRIDGE_URL phải trỏ đến bridge HTTP trên máy.');
}
const lkmagicPort = lkmagicUrl.port || '80';
let window, supervisor, ownedDcc, ownedLkMagic = false, stopping = false, ready = false;
let runtimeGeneration = 0, launchPending = false, lastRuntimeRecovery = -Infinity;

if (!app.requestSingleInstanceLock()) app.quit();
else {
    app.on('second-instance', () => { if (window) { window.restore(); window.focus(); } });
    app.whenReady().then(openSetup);
}

async function exists(file) { return access(file).then(() => true).catch(() => false); }
async function resolveRuntime() {
    const candidates = [
        process.env.PB_DESKTOP_RUNTIME_DIR,
        app.isPackaged ? path.join(resources, 'runtime') : null,
        path.join(directory, 'runtime'),
        path.join(directory, 'dist', 'win-unpacked', 'resources', 'runtime'),
    ].filter(Boolean);
    for (const candidate of candidates) {
        if (await exists(path.join(candidate, 'python/Lib/site-packages/PIL/Image.py'))
            && await exists(path.join(candidate, 'php/php.exe'))
            && await exists(path.join(candidate, 'caddy/caddy.exe'))) {
            return candidate;
        }
    }
    return path.join(resources, 'runtime');
}
async function findLkMagicExecutable(configured = '') {
    const candidates = [
        configured,
        process.env.LKMAGIC_EXECUTABLE,
        path.resolve(project, 'LK Magic-WIN', 'LK magic.exe'),
        path.resolve(project, '..', 'LK Magic-WIN', 'LK magic.exe'),
        path.join(path.dirname(app.getPath('exe')), 'LK Magic-WIN', 'LK magic.exe'),
        path.join(app.getPath('appData'), '..', 'Local', 'Programs', 'LK magic', 'LK magic.exe'),
    ].filter(Boolean);
    for (const candidate of candidates) {
        if (path.isAbsolute(candidate) && path.basename(candidate).toLowerCase() === 'lk magic.exe'
            && await stat(candidate).then((info) => info.isFile()).catch(() => false)) return candidate;
    }
    return '';
}
async function loadSettings() {
    try { return JSON.parse(await readFile(path.join(data, 'desktop-settings.json'), 'utf8')); }
    catch { return {}; }
}

async function openSetup() {
    await mkdir(data, { recursive: true });
    window = new BrowserWindow({ show: process.env.PB_DESKTOP_TEST !== '1', width: 1366, height: 850, minWidth: 900, minHeight: 600, title: 'Photobooth Operator', webPreferences: { preload: path.join(directory, 'preload.cjs'), nodeIntegration: false, contextIsolation: true, sandbox: true } });
    Menu.setApplicationMenu(Menu.buildFromTemplate([{ label: 'Photobooth', submenu: [
        { label: 'Cấu hình / khởi động lại', click: async () => { await stopRuntime(); await window.loadURL(setupUrl); } },
        { role: 'quit', label: 'Thoát' },
    ] }]));
    window.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
    window.webContents.on('will-navigate', (event, url) => {
        if (url !== setupUrl && !(ready && url.startsWith(`http://127.0.0.1:${window.operatorPort}/`))) event.preventDefault();
    });
    window.on('close', (event) => {
        if (stopping || !supervisor) return;
        event.preventDefault();
        if (process.env.PB_DESKTOP_TEST === '1') {
            stopping = true;
            stopRuntime().then(() => window.destroy());
            return;
        }
        dialog.showMessageBox(window, { type: 'question', buttons: ['Đợi tôi kiểm tra phiên', 'Đóng ứng dụng'], defaultId: 0, cancelId: 0, message: 'Hoàn tất job đang chạy trước khi đóng. Nếu đóng lúc đang chụp, lần mở sau sẽ báo job bị gián đoạn và không tự chụp lại.' }).then(async ({ response }) => {
            if (response === 1) { stopping = true; await stopRuntime(); window.close(); }
        });
    });
    await window.loadURL(setupUrl);
}

function assertSetup(event) {
    if (event.sender !== window?.webContents || event.senderFrame?.url !== setupUrl) throw new Error('Chỉ màn hình thiết lập được gọi chức năng này.');
}

ipcMain.handle('setup:info', async (event) => {
    assertSetup(event);
    const ips = Object.values(os.networkInterfaces()).flat().filter((nic) => nic && !nic.internal && nic.family === 'IPv4').map((nic) => nic.address);
    const executable = 'C:\\Program Files (x86)\\digiCamControl\\CameraControl.exe';
    return { saved: await loadSettings(), ips, lkMagicExecutable: await findLkMagicExecutable(), dccExecutable: await exists(executable) ? executable : '', data, packaged: app.isPackaged };
});

ipcMain.handle('setup:launch', async (event, input) => {
    assertSetup(event);
    if (supervisor || launchPending) return { error: 'Dịch vụ đang chạy. Đóng hoặc khởi động lại trước.' };
    launchPending = true;
    const generation = ++runtimeGeneration;
    try {
        const settings = validateSettings(input);
        runtime = await resolveRuntime();
        const python = path.join(runtime, 'python', 'python.exe');
        for (const file of [python, path.join(runtime, 'php', 'php.exe'), path.join(runtime, 'php', 'php-cgi.exe'), path.join(runtime, 'caddy', 'caddy.exe'), path.join(runtime, 'python/Lib/site-packages/PIL/Image.py'), path.join(project, 'custom', 'vendor', 'autoload.php')]) {
            if (!await exists(file)) throw new Error(`Thiếu thành phần: ${path.basename(file)}. Chạy bước prepare runtime và stage trước.`);
        }
        if (settings.cameraMode === 'digicamcontrol' && settings.dccExecutable) {
            const running = await dccResponds(path.join(runtime, 'php'), settings.dccPort);
            if (!running) {
                if (path.basename(settings.dccExecutable).toLowerCase() !== 'cameracontrol.exe' || !await exists(settings.dccExecutable)) throw new Error('Đường dẫn digiCamControl không hợp lệ.');
                ownedDcc = spawn(settings.dccExecutable, [], { windowsHide: false, stdio: 'ignore' });
                ownedDcc.on('error', () => { ownedDcc = null; });
                const launched = ownedDcc;
                launched.on('exit', () => { if (ownedDcc === launched) ownedDcc = null; });
            }
        }
        if (process.env.PB_DESKTOP_TEST !== '1' && process.env.LKMAGIC_AUTO_START !== '0') {
            const lkRunning = await fetch(new URL('/api/v1/health', lkmagicUrl), { signal: AbortSignal.timeout(1000) })
                .then((response) => response.ok ? response.json() : null)
                .then((health) => health?.ok === true && typeof health.ready === 'boolean')
                .catch(() => false);
            if (!lkRunning) {
                const lkExe = await findLkMagicExecutable(settings.lkMagicExecutable);
                if (settings.lkMagicExecutable && lkExe !== settings.lkMagicExecutable) {
                    throw new Error('Chọn đường dẫn đến LK magic.exe hoặc để trống.');
                }
                if (lkExe) {
                    const lkProc = spawn(lkExe, [], {
                        cwd: path.dirname(lkExe),
                        windowsHide: true,
                        stdio: 'ignore',
                        env: { ...process.env, LK_PHOTOBOOTH_BACKGROUND: '1', LK_PHOTOBOOTH_PORT: String(lkmagicPort) },
                    });
                    lkProc.on('error', () => {});
                    ownedLkMagic = true;
                }
            }
        }
        const secretFile = path.join(data, 'desktop-secret.json');
        let secret;
        if (await exists(secretFile)) secret = JSON.parse(await readFile(secretFile, 'utf8')).signingKey;
        else { secret = randomBytes(32).toString('hex'); await writeFile(secretFile, JSON.stringify({ signingKey: secret }), { mode: 0o600, flag: 'wx' }); }
        const env = { ...process.env, LKMAGIC_BRIDGE_URL: lkmagicUrl.origin, LKMAGIC_AUTO_START: '0', DATA_DIR: data.replaceAll('\\', '/'), DATABASE_PATH: path.join(data, 'db/photobooth.sqlite').replaceAll('\\', '/'), PYTHON_BIN: python, MPLCONFIGDIR: path.join(data, 'cache/matplotlib'), CAMERA_ADAPTER: settings.cameraMode, PRINTER_ADAPTER: 'mock', DCC_BASE_URL: `http://127.0.0.1:${settings.dccPort}`, DCC_CAMERA_ID: settings.cameraId, SHARE_BASE_URL: `http://${settings.lanIp}:${settings.guestPort}`, SHARE_SIGNING_KEY: secret, TRUSTED_HOSTS: `127.0.0.1,localhost,${settings.lanIp}`, APP_ENV: 'production', APP_VERSION: app.getVersion(), OPERATOR_BIND: `127.0.0.1:${settings.operatorPort}`, GUEST_BIND: `0.0.0.0:${settings.guestPort}` };
        window.operatorPort = settings.operatorPort;
        const { username, password, ...runtimeSettings } = settings;
        await startSupervisor(python, env, runtimeSettings, { username, password }, generation);
        if (generation !== runtimeGeneration || !supervisor) return { error: 'Dịch vụ đã dừng khi khởi động.' };
        await writeFile(path.join(data, 'desktop-settings.json'), JSON.stringify(publicSettings(settings), null, 2));
        ready = true;
        await window.loadURL(`http://127.0.0.1:${settings.operatorPort}/`);
        return { ok: true };
    } catch (error) {
        if (generation === runtimeGeneration) await stopRuntime();
        return { error: error.message };
    } finally {
        launchPending = false;
    }
});

function startSupervisor(python, env, settings, account, generation) {
    return new Promise((resolve, reject) => {
        const supervisorScript = app.isPackaged ? path.join(resources, 'app.asar.unpacked', 'supervisor.py') : path.join(directory, 'supervisor.py');
        const child = spawn(python, [supervisorScript], { windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'], env });
        supervisor = child;
        let buffer = '', errors = '', runtimeError = '';
        const timeout = setTimeout(() => {
            child.kill();
            reject(new Error('Khởi động quá 60 giây. Xem logs/desktop-runtime.log.'));
        }, 60000);
        child.stderr.on('data', (bytes) => { errors = (errors + bytes.toString()).slice(-3000); });
        child.stdin.on('error', (error) => reject(error));
        child.on('error', (error) => { clearTimeout(timeout); reject(error); });
        child.stdout.setEncoding('utf8');
        child.stdout.on('data', (chunk) => {
            buffer += chunk;
            let end;
            while ((end = buffer.indexOf('\n')) >= 0) {
                const line = buffer.slice(0, end);
                buffer = buffer.slice(end + 1);
                let message;
                try { message = JSON.parse(line); } catch { continue; }
                if (!window?.isDestroyed()) window?.webContents.send('setup:status', message.message || message.status);
                if (message.status === 'ready') { clearTimeout(timeout); resolve(); }
                if (message.status === 'error') {
                    runtimeError = message.message || '';
                    clearTimeout(timeout);
                    reject(new Error(runtimeError));
                }
            }
        });
        child.on('close', (code) => {
            clearTimeout(timeout);
            if (supervisor !== child) return;
            supervisor = null;
            const reason = runtimeError || 'Tiến trình giám sát kết thúc (mã thoát ' + code + ').';
            if (generation !== runtimeGeneration || stopping) {
                reject(new Error('Đã dừng dịch vụ.'));
            } else if (!ready) {
                reject(new Error(runtimeError || errors || reason));
            } else if (runtimeError) {
                ready = false;
                showRuntimeFailure(reason);
            } else {
                ready = false;
                void recoverSupervisor(python, env, settings, generation, reason);
            }
        });
        child.stdin.write(JSON.stringify({ project, runtime, data, settings, account }) + '\n');
    });
}

async function recoverSupervisor(python, env, settings, generation, reason) {
    const now = performance.now();
    if (now - lastRuntimeRecovery < 60_000) {
        showRuntimeFailure(reason);
        return;
    }
    lastRuntimeRecovery = now;
    await appendFile(path.join(data, 'logs', 'desktop-runtime.log'),
        '[' + new Date().toISOString() + '] [electron] ' + reason + ' Đang phục hồi runtime.\n').catch(() => {});
    await new Promise((resolve) => setTimeout(resolve, 1000));
    if (generation !== runtimeGeneration || stopping || window?.isDestroyed()) return;
    try {
        await startSupervisor(python, env, settings, {}, generation);
        if (generation === runtimeGeneration && supervisor) ready = true;
    } catch (error) {
        if (generation === runtimeGeneration && !stopping) {
            await stopRuntime();
            showRuntimeFailure(reason + '\n' + error.message);
        }
    }
}

function showRuntimeFailure(reason) {
    if (!window || window.isDestroyed()) return;
    void window.loadURL(setupUrl).catch(() => {});
    dialog.showErrorBox('Dịch vụ đã dừng', reason + '\nXem logs/desktop-runtime.log và khởi động lại để phục hồi phiên.');
}

async function stopRuntime() {
    ready = false;
    runtimeGeneration++;
    const child = supervisor;
    if (ownedLkMagic) {
        ownedLkMagic = false;
        await fetch(new URL('/api/v1/shutdown', lkmagicUrl), { method: 'POST', signal: AbortSignal.timeout(2000) }).catch(() => {});
    }
    if (!child) { if (ownedDcc) { ownedDcc.kill(); ownedDcc = null; } return; }
    await new Promise((resolve) => {
        const timeout = setTimeout(() => { child.kill(); resolve(); }, 8000);
        child.once('close', () => { clearTimeout(timeout); resolve(); });
        if (child.exitCode !== null || child.signalCode !== null) {
            clearTimeout(timeout);
            resolve();
            return;
        }
        child.stdin.end();
    });
    if (supervisor === child) supervisor = null;
    if (ownedDcc) { ownedDcc.kill(); ownedDcc = null; }
}

app.on('window-all-closed', () => app.quit());
