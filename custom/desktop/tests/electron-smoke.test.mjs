import test from 'node:test';
import assert from 'node:assert/strict';
import { access, mkdir, mkdtemp, readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { _electron as electron } from 'playwright';
import { createServer } from 'node:net';

const desktop = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const port = async () => {
    const server = createServer();
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const result = server.address().port;
    await new Promise((resolve) => server.close(resolve));
    return result;
};

test('Electron setup launches the real mock runtime without persisting the Admin password', { timeout: 90000 }, async (t) => {
    const packaged = process.env.PB_TEST_PACKAGED_EXE;
    const executablePath = packaged || path.join(desktop, 'node_modules/electron/dist/electron.exe');
    if (process.platform !== 'win32' || !await access(executablePath).then(() => true).catch(() => false)) return t.skip('Electron Windows runtime required');
    const cache = path.join(desktop, '.cache');
    await mkdir(cache, { recursive: true });
    const base = await mkdtemp(path.join(cache, 'electron-test-'));
    const data = path.join(base, 'data');
    const instance = await electron.launch({ executablePath, args: [...(packaged ? [] : [desktop]), '--disable-gpu'], env: { ...process.env, PB_DESKTOP_TEST: '1', PB_DESKTOP_DATA_DIR: data, TEMP: cache, TMP: cache }, timeout: 20000 });
    t.after(() => instance.close().catch(() => {}));
    const page = await instance.firstWindow();
    await page.locator('select[name=cameraMode]').selectOption('mock');
    await page.locator('input[name=lanIp]').fill('192.168.1.50');
    await page.locator('input[name=operatorPort]').fill(String(await port()));
    await page.locator('input[name=guestPort]').fill(String(await port()));
    await page.locator('input[name=username]').fill('desktopadmin');
    await page.locator('input[name=password]').fill('DesktopPrivate123!');
    await page.locator('#launch').click();
    await page.waitForURL((url) => url.hostname === '127.0.0.1' && url.protocol === 'http:', { timeout: 30000 });
    assert.equal(await page.locator('body').getAttribute('data-camera'), 'mock');
    assert.match(await page.locator('.sim-banner').innerText(), /MÔ PHỎNG/);
    const saved = await readFile(path.join(data, 'desktop-settings.json'), 'utf8');
    assert.ok(!saved.includes('DesktopPrivate123!'));
    assert.ok(!saved.includes('desktopadmin'));
    await instance.evaluate(({ BrowserWindow }) => { const window = BrowserWindow.getAllWindows()[0]; window.show(); window.focus(); });
    await page.screenshot({ path: path.join(base, 'operator-start.png'), fullPage: true, timeout: 10000 });
    await instance.close();
});
