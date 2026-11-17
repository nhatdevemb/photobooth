import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { createServer as tcpServer } from 'node:net';
import { readFile, writeFile, mkdir, mkdtemp } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { _electron as electron } from 'playwright';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { createHash } from 'node:crypto';

const desktop = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const project = path.resolve(desktop, '../..');
const port = async () => {
    const server = tcpServer();
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const number = server.address().port;
    await new Promise((resolve) => server.close(resolve));
    return number;
};
const eventually = async (predicate, message, timeout = 20000) => {
    const end = Date.now() + timeout;
    while (Date.now() < end) {
        if (await predicate()) return;
        await new Promise((resolve) => setTimeout(resolve, 75));
    }
    assert.fail(message);
};

const sessionCycle = process.env.PB_TEST_SESSION_CYCLE === '1';
test(sessionCycle ? 'packaged app finishes print/QR/close and starts a clean second session with live view' : 'live view streams through 3-2-1 and layout/capture/filter/template/review without duplicate shutters', { timeout: sessionCycle ? 180000 : 120000 }, async (t) => {
    if (process.platform !== 'win32') return t.skip('Windows desktop integration');
    const real = process.env.PB_REAL_DCC === '1';
    assert.ok(!sessionCycle || !real, 'The repeated session test uses simulated DCC only');
    const cache = path.join(desktop, '.cache');
    await mkdir(cache, { recursive: true });
    const base = await mkdtemp(path.join(cache, 'liveview-workflow-'));
    const fixtures = await Promise.all([1, 2, 3].map((n) => readFile(path.join(project, `custom/tests/fixtures/slot-${n}-v1.jpg`))));
    const settings = {};
    let last = '-', frames = 0, shutters = 0, previewStarts = 0;
    const dcc = createServer(async (request, response) => {
        try {
            const url = new URL(request.url, 'http://localhost');
            const q = Object.fromEntries(url.searchParams);
            if (url.pathname === '/liveview.jpg') {
                response.setHeader('Content-Type', 'image/jpeg');
                response.end(fixtures[frames++ % fixtures.length]);
                return;
            }
            let body = 'OK';
            if (q.CMD === 'LiveViewWnd_Show') previewStarts++;
            if (q.slc === 'list') body = '1234567890';
            else if (q.slc === 'set') settings[q.param1] = q.param2;
            else if (q.slc === 'get') body = q.param1 === 'session' ? 'Photobooth' : q.param1 === 'camera' ? '1234567890' : q.param1 === 'lastcaptured' ? last : settings[q.param1] || '';
            else if (q.slc === 'capture') {
                shutters++;
                last = settings['session.filenametemplate'] + '.jpg';
                await writeFile(path.join(settings['session.folder'], last), fixtures[0]);
            }
            response.end(body);
        } catch (error) { response.statusCode = 500; response.end(error.message); }
    });
    await new Promise((resolve) => dcc.listen(0, '127.0.0.1', resolve));
    t.after(() => new Promise((resolve) => dcc.close(resolve)));
    const packaged = process.env.PB_TEST_PACKAGED_EXE;
    const app = await electron.launch({
        executablePath: packaged || path.join(desktop, 'node_modules/electron/dist/electron.exe'),
        args: [...(packaged ? [] : [desktop]), '--disable-gpu'],
        env: { ...process.env, PB_DESKTOP_TEST: '1', PB_DESKTOP_DATA_DIR: path.join(base, 'data'), TEMP: cache, TMP: cache, COUNTDOWN_SECONDS: '3' },
    });
    t.after(() => app.close().catch(() => {}));
    if (real) {
        const phpDirectory = process.env.PB_DESKTOP_RUNTIME_DIR ? path.join(process.env.PB_DESKTOP_RUNTIME_DIR, 'php') : path.join(desktop, 'runtime/php');
        const script = "require $argv[1].'/custom/vendor/autoload.php';$c=\\Photobooth\\Operator\\Config\\AppConfig::fromEnvironment(['DATA_DIR'=>$argv[2]]);$d=new \\Photobooth\\Operator\\Camera\\DigiCamControlClient($c);if($argv[3]==='save'){$b=['camera'=>$d->selectCamera(),'session'=>$d->slc('get','session'),'folder'=>$d->slc('get','session.folder'),'stem'=>$d->slc('get','session.filenametemplate')];file_put_contents($argv[2].'/dcc-before.json',json_encode($b));}else{$b=json_decode(file_get_contents($argv[2].'/dcc-before.json'),true);$d->command('set','session',$b['session'],$b['camera']);$d->command('set','session.folder',str_replace(chr(92).chr(92),chr(92),$b['folder']),$b['camera']);$d->command('set','session.filenametemplate',$b['stem'],$b['camera']);$d->preview(true,$b['camera']);}";
        const dccSession = (action) => promisify(execFile)(path.join(phpDirectory, 'php.exe'), ['-n', '-d', 'extension_dir=ext', '-d', 'extension=curl', '-d', 'extension=mbstring', '-r', script, project, base, action], { cwd: phpDirectory, windowsHide: true, timeout: 10000 });
        await dccSession('save');
        t.after(() => dccSession('restore'));
    }
    const page = await app.firstWindow();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    if (real) {
        page.on('response', (response) => { if (response.url().endsWith('/camera/preview.jpg') && response.status() === 200) frames++; });
        page.on('request', (request) => { if (request.method() === 'POST' && /\/slots\/1\/capture$/.test(request.url())) shutters++; });
    }
    await page.locator('select[name=cameraMode]').selectOption('digicamcontrol');
    await page.locator('input[name=dccExecutable]').fill('');
    await page.locator('input[name=dccPort]').fill(String(real ? 5513 : dcc.address().port));
    await page.locator('input[name=lanIp]').fill('192.168.1.50');
    await page.locator('input[name=operatorPort]').fill(String(await port()));
    const guestPort = await port();
    await page.locator('input[name=guestPort]').fill(String(guestPort));
    await page.locator('input[name=username]').fill('liveviewadmin');
    await page.locator('input[name=password]').fill('LiveviewPrivate123!');
    await page.locator('#launch').click();
    await page.waitForURL((url) => url.protocol === 'http:');
    await page.locator('#login-username').fill('liveviewadmin');
    await page.locator('#login-password').fill('LiveviewPrivate123!');
    await page.locator('#btn-login-submit').click();
    await eventually(() => frames >= 3, 'Auto live view did not start');
    if (!real) assert.equal(previewStarts, 1);
    await page.setViewportSize({ width: 1366, height: 768 });
    await page.locator('#btn-new-session').click();
    await page.locator('[data-layout-id=single]').click();
    assert.equal(await page.locator('#btn-next').evaluate((button) => button.getBoundingClientRect().bottom <= innerHeight), true, 'Layout footer below viewport');
    await page.locator('#btn-next').click();
    await eventually(() => page.locator('[data-step="2"]').getAttribute('aria-current').then(Boolean), 'Capture step missing');
    assert.equal(await page.locator('#btn-next').evaluate((button) => button.getBoundingClientRect().bottom <= innerHeight), true, 'Capture footer below viewport');
    let displayed = 0;
    await page.exposeFunction('frameDisplayed', () => { displayed++; });
    await page.locator('#live-preview-img').evaluate((image) => image.addEventListener('load', () => window.frameDisplayed()));
    await page.locator('#btn-auto-capture-all').click();
    for (const number of ['3', '2', '1']) {
        await eventually(() => page.locator('#countdown-number').textContent().then((text) => text === number), `Countdown ${number} missing`);
        const before = displayed;
        await eventually(() => displayed > before, `No new displayed frame during countdown ${number}`, 850);
        assert.equal(await page.locator('#live-preview-img').isVisible(), true);
    }
    await eventually(() => page.locator('.slot-thumb-box img').count().then((n) => n === 1), 'Capture did not complete');
    await eventually(() => page.locator('#btn-next').isEnabled(), 'Capture remained busy');
    assert.equal(shutters, 1);
    if (!real) assert.ok(previewStarts >= 2, 'Live view was not resumed after shutter');
    await page.locator('#btn-next').click();
    await page.locator('[data-lut-id=film-400]').click();
    await page.locator('[data-beauty-preset=off]').click();
    assert.equal(await page.locator('#btn-next').evaluate((button) => button.getBoundingClientRect().bottom <= innerHeight), true, 'Filter footer below viewport');
    await page.reload();
    await eventually(() => page.locator('[data-step="3"]').getAttribute('aria-current').then(Boolean), 'Reload did not restore filter step');
    await page.locator('#btn-next').click();
    await page.locator('[data-template-id=cute-single]').click();
    assert.equal(await page.locator('#live-preview-img').isVisible(), true);
    assert.equal(await page.locator('#btn-next').evaluate((button) => button.getBoundingClientRect().bottom <= innerHeight), true, 'Template footer below viewport');
    await page.locator('#btn-next').click();
    await eventually(() => page.locator('#btn-finalize-session').isEnabled(), 'Review did not load');
    const beforeFrames = frames;
    await eventually(() => frames >= beforeFrames + 3, 'Live view stopped at review');
    assert.equal(await page.locator('#live-preview-img').isVisible(), true);
    for (const size of [{ width: 1366, height: 768 }, { width: 1920, height: 1080 }, { width: 390, height: 844 }]) {
        await page.setViewportSize(size);
        const overflow = await page.evaluate(() => ({ viewport:innerWidth, width:document.documentElement.scrollWidth, elements:[...document.querySelectorAll('body *')].filter((e) => { const r=e.getBoundingClientRect();return r.width>0 && (r.right>innerWidth+1 || r.left < -1); }).map((e)=>({id:e.id,tag:e.tagName,class:e.className,width:e.getBoundingClientRect().width,right:e.getBoundingClientRect().right})).slice(0,20) }));
        assert.equal(overflow.width > overflow.viewport, false, JSON.stringify(overflow));
    }
    await page.setViewportSize({ width: 1366, height: 768 });
    await app.evaluate(({ BrowserWindow }) => { BrowserWindow.getAllWindows()[0].show(); });
    await page.screenshot({ path: path.join(base, 'review-liveview.png'), fullPage: true });
    await page.locator('#btn-finalize-session').click();
    await eventually(() => page.locator('#modal-qr-img').isVisible(), 'QR not created');
    assert.equal(shutters, 1);
    if (sessionCycle) {
        const active = () => page.evaluate(async () => (await (await fetch('/custom/api/sessions/active')).json()).active_session);
        const first = await active();
        const firstQr = await page.locator('#modal-qr-url').textContent();
        const firstQrImage = await page.locator('#modal-qr-img').getAttribute('src');
        const download = async (url) => {
            const response = await fetch(`http://127.0.0.1:${guestPort}${new URL(url).pathname}/download`);
            assert.equal(response.status, 200, 'Closed session QR must still download');
            return createHash('sha256').update(Buffer.from(await response.arrayBuffer())).digest('hex');
        };
        assert.equal(await download(firstQr), first.current_final.sha256);
        await page.locator('#btn-open-print-modal').click();
        await page.locator('#btn-confirm-print-submit').click();
        await eventually(() => page.locator('#session-prints-list').textContent().then((text) => text.includes('COMPLETED')), 'First simulated print did not complete');
        await page.locator('#btn-close-session').click();
        await eventually(() => page.locator('#btn-new-session').isVisible(), 'Cannot create a session after finishing');
        assert.equal(await active(), null);
        assert.equal(await page.locator('#modal-qr-img').isVisible(), false);
        await eventually(() => page.locator('#webcam-lut-canvas').isHidden(), 'Old session filter remained on live view');
        const beforeNew = frames;
        await eventually(() => frames >= beforeNew + 3, 'Live view stopped between sessions');
        assert.equal(await download(firstQr), first.current_final.sha256);
        await page.locator('#btn-new-session').click();
        await eventually(() => page.locator('[data-step="1"]').getAttribute('aria-current').then(Boolean), 'New session did not return to layout selection');
        const second = await active();
        assert.notEqual(second.id, first.id);
        assert.equal(second.state, 'NEW');
        assert.equal(second.ui_step, 1);
        assert.equal(second.accepted_slots, 0);
        assert.equal(second.configuration_locked, false);
        assert.equal(second.current_render, null);
        assert.equal(second.current_final, null);
        assert.equal(second.active_job, null);
        assert.equal(second.selected_lut, 'original');
        assert.ok(second.slots.every((slot) => slot.photo === null));
        assert.equal(second.print_summary.requests.length, 0);
        assert.equal(await page.locator('[data-step="1"]').getAttribute('aria-current'), 'step');
        assert.equal(await page.locator('#btn-next').isEnabled(), true);
        assert.equal(shutters, 1, 'Creating a new session must never trigger capture');
        await page.locator('[data-layout-id=single]').click();
        await page.locator('#btn-next').click();
        assert.equal(await page.locator('.slot-thumb-box img').count(), 0, 'Old photo leaked into the second session');
        await page.locator('#btn-auto-capture-all').click();
        await eventually(() => page.locator('.slot-thumb-box img').count().then((n) => n === 1), 'Second capture did not complete');
        await eventually(() => page.locator('#btn-next').isEnabled(), 'Second capture remained busy');
        assert.equal(shutters, 2);
        const captured = await active();
        assert.notEqual(captured.slots[0].photo.id, first.slots[0].photo.id);
        await page.locator('#btn-next').click();
        await page.locator('[data-lut-id=original]').click();
        await page.locator('[data-beauty-preset=off]').click();
        await page.locator('#btn-next').click();
        await page.locator('[data-template-id=minimal-single]').click();
        await page.locator('#btn-next').click();
        await eventually(() => page.locator('#btn-finalize-session').isEnabled(), 'Second review did not load');
        assert.equal(await page.locator('#modal-qr-img').isVisible(), false, 'Old QR appeared before approving the second session');
        await page.locator('#btn-finalize-session').click();
        await eventually(() => page.locator('#modal-qr-img').isVisible(), 'Second QR not created');
        assert.notEqual(await page.locator('#modal-qr-url').textContent(), firstQr);
        assert.notEqual(await page.locator('#modal-qr-img').getAttribute('src'), firstQrImage);
        await page.locator('#btn-open-print-modal').click();
        await page.locator('#btn-confirm-print-submit').click();
        await eventually(() => page.locator('#session-prints-list').textContent().then((text) => text.includes('COMPLETED')), 'Second simulated print did not complete');
        const finishedSecond = await active();
        assert.equal(await download(await page.locator('#modal-qr-url').textContent()), finishedSecond.current_final.sha256);
        await page.locator('#btn-close-session').click();
        await eventually(() => page.locator('#btn-new-session').isVisible(), 'Second session could not close');
        assert.equal(await active(), null);
        assert.equal(await download(firstQr), first.current_final.sha256);
        assert.equal(shutters, 2, 'Two sessions should have exactly two captures');
        console.log('Verified two complete sessions: capture, style, approve, QR download, simulated print, close; clean defaults and moving live view between sessions.');
    }
    assert.deepEqual(errors, []);
    console.log(`Verified ${real ? 'REAL R10' : 'simulated DCC'} moving frames during 3-2-1; ${frames} frames, ${shutters} shutter. Screenshot: ${base}`);
    await app.close();
});
