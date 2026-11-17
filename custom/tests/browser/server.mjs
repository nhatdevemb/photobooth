import { spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync, cpSync, existsSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { once } from 'node:events';

const root = resolve(import.meta.dirname, '../..');
const data = mkdtempSync(join(tmpdir(), 'pb-browser-'));
const port = Number(process.env.PB_BROWSER_PORT || 18080);
const env = {
    ...process.env,
    APP_ENV: 'testing',
    DATA_DIR: data,
    DATABASE_PATH: join(data, 'db/photobooth.sqlite'),
    SHARE_BASE_URL: `http://192.168.4.1:${port + 1}`,
    SHARE_SIGNING_KEY: 'browser-test-only-0123456789abcdef0123456789',
    TRUSTED_HOSTS: '127.0.0.1,localhost,192.168.4.1',
    CAMERA_ADAPTER: process.env.PB_BROWSER_CAMERA || 'mock',
    PRINTER_ADAPTER: 'mock',
    BEAUTY_ENGINE: 'mediapipe',
    LKMAGIC_AUTO_START: '0',
    MOCK_CAPTURE_DELAY_MS: '0',
    MOCK_COUNTDOWN_DELAY_MS: '0',
    MOCK_PRINTER_SCENARIO: process.env.PB_BROWSER_PRINTER_SCENARIO || 'normal',
    MOCK_FAULT_SCENARIO: 'none',
    COUNTDOWN_SECONDS: process.env.PB_BROWSER_CAMERA === 'browser' ? '1' : '3',
    DISK_LOW_BYTES: '1048576',
};
const bootstrap = spawnSync('php', ['-r', `require 'vendor/autoload.php'; $c = new \\Photobooth\\Operator\\Container(); $c->authService->createUser('operator1', 'OperatorPass123!', 'operator'); $c->authService->createUser('admin1', 'AdminPass123!', 'admin');`], { cwd: root, env, stdio: 'inherit' });
if (bootstrap.status !== 0) {
    rmSync(data, { recursive: true, force: true });
    process.exit(1);
}
const children = [
    spawn('php', [join(root, 'bin/worker.php')], { cwd: root, env, stdio: 'inherit' }),
    spawn('php', ['-S', `127.0.0.1:${port + 1}`, join(root, 'public/guest.php')], { cwd: root, env, stdio: 'ignore' }),
    spawn('php', ['-d','upload_max_filesize=5M','-d','post_max_size=32M','-S', `127.0.0.1:${port}`, join(root, 'public/index.php')], { cwd: root, env, stdio: 'ignore' }),
];
let stopping = false;
async function stop(code)
{
    if (stopping) return;
    stopping = true;
    const exits = children.map((child) => child.exitCode === null && child.signalCode === null ? once(child, 'exit') : Promise.resolve());
    for (const child of children) child.kill('SIGTERM');
    const timer = setTimeout(() => children.forEach((child) => child.kill('SIGKILL')), 3000);
    await Promise.all(exits);
    clearTimeout(timer);
    const logs = join(root, 'test-results/server-logs');
    mkdirSync(logs, { recursive: true });
    if (existsSync(join(data, 'logs'))) cpSync(join(data, 'logs'), logs, { recursive: true });
    rmSync(data, { recursive: true, force: true });
    process.exit(code);
}
process.on('SIGTERM', () => stop(0));
process.on('SIGINT', () => stop(0));
children.forEach((child) => {
    child.on('error', () => stop(1));
    child.on('exit', () => { if (!stopping) stop(1); });
});
