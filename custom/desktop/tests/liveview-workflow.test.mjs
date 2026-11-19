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
test(sessionCycle ? 'packaged app keeps live view on capture only across two sessions' : 'live view streams only on capture including 3-2-1', { timeout: sessionCycle ? 180000 : 120000 }, async (t) => {
    if (process.platform !== 'win32') return t.skip('Windows desktop integration');
    const real = process.env.PB_REAL_DCC === '1';
    const beauty = process.env.PB_TEST_BEAUTY === '1';
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
        env: { ...process.env, PB_DESKTOP_TEST: '1', ...(beauty ? { BEAUTY_ENGINE: 'mediapipe' } : {}), PB_DESKTOP_DATA_DIR: path.join(base, 'data'), TEMP: cache, TMP: cache, COUNTDOWN_SECONDS: '3' },
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
    await page.locator('#btn-new-session').waitFor();
    const checkCameraOff = async (label) => {
        assert.equal(await page.locator('#liveview-panel').isHidden(), true, label);
        const before = frames;
        const starts = previewStarts;
        await new Promise((resolve) => setTimeout(resolve, 350));
        assert.equal(frames, before, label + ' requested a camera frame');
        assert.equal(previewStarts, starts, label + ' restarted live view');
    };
    await checkCameraOff('Waiting screen');
    assert.equal(frames, 0);
    assert.equal(previewStarts, 0);
    if (beauty) {
        await page.locator('#btn-nav-health').click();
        await eventually(() => page.locator('#health-lkmagic').textContent().then((text) => text.includes('LK Magic')), 'Bridge status missing');
        assert.equal(await page.locator('#btn-beauty-test').isVisible(), true);
        await page.locator('#btn-nav-health').click();
    }
    await page.setViewportSize({ width: 1366, height: 768 });
    const settingsSave = async (name, category, logo, newEvent = false) => {
        await page.locator('#btn-nav-settings').click();
        await page.locator('#event-name').fill(name);
        await page.locator('#event-category').selectOption(category);
        await page.locator('#event-frame-name').fill(name);
        await page.locator('#event-frame-date').fill('2026-11-18');
        await page.locator('#event-frame-wish').fill('Chúc mừng ngày đặc biệt');
        await page.locator('#event-logo').setInputFiles(path.join(project, 'custom/public/assets/mock-preview', logo));
        if (await page.locator('#event-no-limit').isChecked()) await page.locator('#event-no-limit').uncheck();
        await page.locator('#event-print-limit').fill('5');
        await page.locator('#btn-event-preview').click();
        await page.locator('#event-frame-preview').waitFor();
        const saved = page.waitForResponse((r) => r.url().endsWith('/admin/settings') && r.request().method() === 'POST');
        if (newEvent) page.once('dialog', (dialog) => dialog.accept());
        await page.locator(newEvent ? '#btn-event-new' : '#btn-event-save').click();
        const result = await saved;
        assert.equal(result.status(), 200, await result.text());
        const settings = await result.json();
        await page.locator('#btn-nav-workspace').click();
        return settings;
    };
    const firstEvent = sessionCycle ? await settingsSave('Minh và Lan', 'wedding', 'frame-1.jpg') : null;
    await page.locator('#btn-new-session').click();
    await page.locator('[data-layout-id=single]').click();
    await checkCameraOff('Layout selection');
    assert.equal(await page.locator('#btn-next').evaluate((button) => button.getBoundingClientRect().bottom <= innerHeight), true, 'Layout footer below viewport');
    await page.locator('#btn-next').click();
    await eventually(() => page.locator('[data-step="2"]').getAttribute('aria-current').then(Boolean), 'Capture step missing');
    await eventually(() => frames >= 3, 'Live view did not start at capture');
    if (!real) assert.equal(previewStarts, 1);
    if (process.env.PB_TEST_RUNTIME_RECOVERY === '1') {
        assert.equal(real, false);
        const active = () => page.evaluate(async () => (await (await fetch('/custom/api/sessions/active')).json()).active_session);
        const before = await active();
        const runtimeLog = path.join(base, 'data', 'logs', 'desktop-runtime.log');
        const runtimePid = async () => {
            const log = await readFile(runtimeLog, 'utf8');
            return Number([...log.matchAll(/Runtime started \(PID (\d+)\)/g)].at(-1)?.[1]);
        };
        const pid = await runtimePid();
        const oldFrames = frames;
        process.kill(pid, 'SIGTERM');
        await eventually(async () => await runtimePid() !== pid && frames > oldFrames + 2, 'Supervisor or live view did not recover');
        assert.equal((await active()).id, before.id);
        assert.equal(shutters, 0, 'Restarting the supervisor must not fire the shutter');
        console.log('Verified Electron supervisor recovery with the same session and resumed live view.');
    }
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
    const leaveCapture = async (stepButton) => {
        const stopped = page.waitForResponse((res) => res.url().endsWith('/camera/preview/stop'));
        await page.locator(stepButton).click();
        assert.equal((await stopped).status(), 200, 'Could not stop DCC preview when leaving capture');
    };
    await leaveCapture('#btn-next');
    await checkCameraOff('Filter selection');
    const selectionPreview = page.locator('#selection-preview');
    await selectionPreview.waitFor();
    const capturedMatches = await page.evaluate(async () => {
        const session = (await (await fetch('/custom/api/sessions/active')).json()).active_session;
        const image = new Image();
        image.src = session.slots[0].photo.url;
        await image.decode();
        const preview = document.getElementById('selection-preview');
        const pixel = document.createElement('canvas');
        pixel.width = pixel.height = 1;
        const ctx = pixel.getContext('2d');
        ctx.drawImage(image, Math.floor(image.naturalWidth / 2), Math.floor(image.naturalHeight / 2), 1, 1, 0, 0, 1, 1);
        const source = ctx.getImageData(0, 0, 1, 1).data;
        const rendered = preview.getContext('2d').getImageData(590, 874, 1, 1).data;
        return source.slice(0, 3).every((value, i) => Math.abs(value - rendered[i]) < 24);
    });
    assert.equal(capturedMatches, true, 'Filter preview does not contain the captured JPEG');
    await page.locator('[data-lut-id=film-400]').click();
    await page.locator(beauty ? '[data-beauty-preset=natural]' : '[data-beauty-preset=off]').click();
    assert.equal(await page.locator('#btn-next').evaluate((button) => button.getBoundingClientRect().bottom <= innerHeight), true, 'Filter footer below viewport');
    await page.reload();
    await eventually(() => page.locator('[data-step="3"]').getAttribute('aria-current').then(Boolean), 'Reload did not restore filter step');
    await page.locator('#btn-next').click();
    await page.locator(sessionCycle ? '[data-template-id=wedding-burgundy-single]' : '[data-template-id=cute-single]').click();
    if (sessionCycle) {
        assert.equal(await page.locator('[data-template-id=cute-single]').count(), 0);
        assert.equal(await page.locator('#btn-nav-settings').isDisabled(), true);
    }
    await selectionPreview.waitFor();
    await checkCameraOff('Template selection');
    const previewWidth = await selectionPreview.evaluate((canvas) => canvas.getBoundingClientRect().width);
    assert.ok(previewWidth >= 240, 'Captured photo preview is too small to compare templates');
    assert.equal(await page.locator('#btn-next').evaluate((button) => button.getBoundingClientRect().bottom <= innerHeight), true, 'Template footer below viewport');
    const enterReview = async () => {
        await page.locator('#btn-next').click();
        await eventually(() => page.locator('#btn-finalize-session').isEnabled(), 'Review did not load');
        await checkCameraOff('Review');
    };
    await enterReview();
    await page.locator('[data-step="4"]').click();
    await selectionPreview.waitFor();
    await checkCameraOff('Back to template selection');
    await enterReview();
    const reviewFrames = frames;
    await page.locator('#btn-review-retake').click();
    await eventually(() => frames >= reviewFrames + 3, 'Live view did not resume for retake');
    assert.equal(await page.locator('#live-preview-img').isVisible(), true);
    await leaveCapture('[data-step="4"]');
    await selectionPreview.waitFor();
    await enterReview();
    for (const size of [{ width: 1366, height: 768 }, { width: 1920, height: 1080 }, { width: 390, height: 844 }]) {
        await page.setViewportSize(size);
        const overflow = await page.evaluate(() => ({ viewport:innerWidth, width:document.documentElement.scrollWidth, elements:[...document.querySelectorAll('body *')].filter((e) => { const r=e.getBoundingClientRect();return r.width>0 && (r.right>innerWidth+1 || r.left < -1); }).map((e)=>({id:e.id,tag:e.tagName,class:e.className,width:e.getBoundingClientRect().width,right:e.getBoundingClientRect().right})).slice(0,20) }));
        assert.equal(overflow.width > overflow.viewport, false, JSON.stringify(overflow));
    }
    await page.setViewportSize({ width: 1366, height: 768 });
    await app.evaluate(({ BrowserWindow }) => { BrowserWindow.getAllWindows()[0].show(); });
    await page.screenshot({ path: path.join(base, 'review-liveview.png'), fullPage: true });
    const finalResponse = page.waitForResponse((res) => res.request().method() === 'POST' && res.url().endsWith('/finalize'));
    await page.locator('#btn-finalize-session').click();
    const approved = await finalResponse;
    assert.equal(approved.status(), 201, await approved.text());
    await eventually(() => page.locator('#modal-qr-img').isVisible(), 'QR not created');
    assert.equal(await page.locator('#liveview-panel').isHidden(), true);
    const qrFrames = frames;
    assert.equal(shutters, 1);
    if (sessionCycle) {
        const active = () => page.evaluate(async () => (await (await fetch('/custom/api/sessions/active')).json()).active_session);
        const first = await active();
        if (beauty) assert.equal(first.current_render.beauty_mode, 'mediapipe');
        const firstQr = await page.locator('#modal-qr-url').textContent();
        const firstQrImage = await page.locator('#modal-qr-img').getAttribute('src');
        const download = async (url) => {
            const response = await fetch(`http://127.0.0.1:${guestPort}${new URL(url).pathname}/download`);
            assert.equal(response.status, 200, 'Closed session QR must still download');
            return createHash('sha256').update(Buffer.from(await response.arrayBuffer())).digest('hex');
        };
        const guestHtml = async (url) => {
            const response = await fetch('http://127.0.0.1:' + guestPort + new URL(url).pathname);
            assert.equal(response.status, 200);
            return response.text();
        };
        assert.equal(await download(firstQr), first.current_final.sha256);
        assert.ok((await guestHtml(firstQr)).includes(firstEvent.logo_url));
        await page.locator('#print-plus').click();
        await page.locator('#print-plus').click();
        await page.locator('#btn-open-print-modal').click();
        assert.equal(await page.locator('#modal-print-title').textContent(), 'Xác nhận in 3 bản');
        assert.equal(await page.locator('#liveview-panel').isHidden(), true);
        await page.locator('#btn-confirm-print-submit').click();
        await eventually(() => page.locator('#session-prints-list').textContent().then((text) => text.includes('COMPLETED')), 'First simulated print did not complete');
        assert.equal(frames, qrFrames, 'QR or printing restarted live view');
        assert.ok((await page.locator('#print-quota').textContent()).includes('Đã in 3/5'));
        assert.equal((await active()).print_quota.remaining, 2);
        await page.locator('#btn-close-session').click();
        await eventually(() => page.locator('#btn-new-session').isVisible(), 'Cannot create a session after finishing');
        assert.equal(await active(), null);
        assert.equal(await page.locator('#modal-qr-img').isVisible(), false);
        await eventually(() => page.locator('#webcam-lut-canvas').isHidden(), 'Old session filter remained on live view');
        await checkCameraOff('Between sessions');
        assert.equal(await download(firstQr), first.current_final.sha256);
        const nextEvent = await settingsSave('Sinh nhật Bé Na', 'birthday', 'frame-2.jpg', true);
        assert.notEqual(nextEvent.id, firstEvent.id);
        assert.equal(nextEvent.quota.printed, 0);
        const oldHtml = await guestHtml(firstQr);
        assert.ok(oldHtml.includes(firstEvent.logo_url) && oldHtml.includes('Minh và Lan'));
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
        await checkCameraOff('Second layout selection');
        const firstFrames = frames;
        await page.locator('#btn-next').click();
        await eventually(() => frames > firstFrames + 2, 'Live view did not start for the next session');
        assert.equal(await page.locator('.slot-thumb-box img').count(), 0, 'Old photo leaked into the second session');
        await page.locator('#btn-auto-capture-all').click();
        await eventually(() => page.locator('.slot-thumb-box img').count().then((n) => n === 1), 'Second capture did not complete');
        await eventually(() => page.locator('#btn-next').isEnabled(), 'Second capture remained busy');
        assert.equal(shutters, 2);
        const captured = await active();
        assert.notEqual(captured.slots[0].photo.id, first.slots[0].photo.id);
        await page.locator('#btn-next').click();
        await page.locator('[data-lut-id=original]').click();
        await page.locator(beauty ? '[data-beauty-preset=natural]' : '[data-beauty-preset=off]').click();
        await page.locator('#btn-next').click();
        await page.locator('[data-template-id=birthday-gala-single]').click();
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
        const secondQr = await page.locator('#modal-qr-url').textContent();
        assert.equal(await download(secondQr), finishedSecond.current_final.sha256);
        const nextHtml = await guestHtml(secondQr);
        assert.ok(nextHtml.includes(nextEvent.logo_url) && nextHtml.includes('Sinh nhật Bé Na'));
        await page.locator('#btn-close-session').click();
        await eventually(() => page.locator('#btn-new-session').isVisible(), 'Second session could not close');
        assert.equal(await active(), null);
        assert.equal(await download(firstQr), first.current_final.sha256);
        assert.equal(shutters, 2, 'Two sessions should have exactly two captures');
        console.log('Verified two events with personalized frames, QR branding snapshots, multi-copy mock printing and live view.');
    }
    assert.deepEqual(errors, []);
    console.log(`Verified ${real ? 'REAL R10' : 'simulated DCC'} moving frames during 3-2-1; ${frames} frames, ${shutters} shutter. Screenshot: ${base}`);
    await app.close();
});
