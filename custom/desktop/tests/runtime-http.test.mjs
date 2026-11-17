import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer as httpServer } from 'node:http';
import { createServer as tcpServer } from 'node:net';
import { readFile, writeFile, mkdir, mkdtemp, access } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';
import { randomUUID, createHash } from 'node:crypto';
import jsQR from 'jsqr';
import pngjs from 'pngjs';

const desktop = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const project = path.resolve(desktop, '../..');
const runtime = path.join(desktop, 'runtime');
const python = path.join(runtime, 'python/python.exe');
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const port = async () => {
    const server = tcpServer();
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const number = server.address().port;
    await new Promise((resolve) => server.close(resolve));
    return number;
};

test('Windows FastCGI + worker + DCC HTTP: four layouts, retake, immutable JPEG, QR and listener isolation', { timeout: process.env.PB_SOAK_SESSIONS ? 600000 : 180000 }, async (t) => {
    if (process.platform !== 'win32' || !await access(python).then(() => true).catch(() => false)) return t.skip('Prepared Windows runtime required');
    const cache = path.join(desktop, '.cache');
    await mkdir(cache, { recursive: true });
    const data = await mkdtemp(path.join(cache, 'http-e2e-Thử-'));
    const fixture = await readFile(path.join(project, 'custom/tests/fixtures/slot-1-v1.jpg'));
    const settings = {};
    let last = '-', triggers = 0;
    const dcc = httpServer(async (request, response) => {
        try {
            const url = new URL(request.url, 'http://127.0.0.1');
            const q = Object.fromEntries(url.searchParams);
            let body = 'OK';
            if (url.pathname === '/liveview.jpg') { response.setHeader('Content-Type', 'image/jpeg'); response.end(fixture); return; }
            if (q.slc === 'list') body = '1234567890';
            else if (q.slc === 'set') settings[q.param1] = q.param2;
            else if (q.slc === 'get') body = q.param1 === 'session' ? 'Photobooth' : q.param1 === 'camera' ? '1234567890' : q.param1 === 'lastcaptured' ? last : settings[q.param1] || '';
            else if (q.slc === 'capture') {
                triggers++;
                last = settings['camera.session.filenametemplate'] + '_0001.jpg';
                await writeFile(path.join(settings['camera.session.folder'], last), fixture);
            }
            response.end(body);
        } catch (error) { response.statusCode = 500; response.end(error.message); }
    });
    await new Promise((resolve) => dcc.listen(0, '127.0.0.1', resolve));
    const operatorPort = await port(), guestPort = await port();
    const origin = `http://127.0.0.1:${operatorPort}`;
    const guest = `http://127.0.0.1:${guestPort}`;
    const child = spawn(python, [path.join(desktop, 'supervisor.py')], {
        cwd: project, windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'],
        env: { ...process.env, TEMP: cache, TMP: cache, MPLCONFIGDIR: path.join(cache, 'matplotlib'), DATA_DIR: data, DATABASE_PATH: path.join(data, 'db/photobooth.sqlite'), PYTHON_BIN: python, CAMERA_ADAPTER: 'digicamcontrol', PRINTER_ADAPTER: 'mock', COUNTDOWN_SECONDS: '0', DCC_BASE_URL: `http://127.0.0.1:${dcc.address().port}`, DCC_CAMERA_ID: '1234567890', SHARE_BASE_URL: `http://192.168.1.50:${guestPort}`, TRUSTED_HOSTS: '127.0.0.1,localhost,192.168.1.50', SHARE_SIGNING_KEY: randomUUID() + randomUUID() },
    });
    let output = '';
    child.stdout.on('data', (bytes) => { output += bytes.toString(); });
    child.stderr.on('data', (bytes) => { output += bytes.toString(); });
    const exited = new Promise((resolve) => child.on('exit', resolve));
    t.after(async () => {
        child.stdin.end();
        await Promise.race([exited, delay(7000).then(() => child.kill())]);
        await new Promise((resolve) => dcc.close(resolve));
    });
    child.stdin.write(JSON.stringify({ project, runtime, data, settings: { operatorPort, guestPort }, account: { username: 'testadmin', password: 'TestAdminPrivate123!' } }) + '\n');
    for (let i = 0; !output.includes('"ready"'); i++) {
        assert.ok(i < 200 && child.exitCode === null, output);
        await delay(100);
    }
    assert.equal((await fetch(origin + '/custom/api/camera')).status, 401);
    for (const route of ['/', '/custom/api/camera', '/admin/', '/custom/assets/js/operator.js']) assert.equal((await fetch(guest + route)).status, 403);
    const login = await fetch(origin + '/custom/api/auth/login', { method: 'POST', headers: { Origin: origin, 'Content-Type': 'application/json' }, body: JSON.stringify({ username: 'testadmin', password: 'TestAdminPrivate123!' }) });
    assert.equal(login.status, 200, await login.clone().text());
    const cookie = login.headers.get('set-cookie').split(';')[0];
    const csrf = (await login.json()).csrf_token;
    const api = async (method, route, body, key = randomUUID(), expected = 200) => {
        const response = await fetch(origin + '/custom/api' + route, { method, headers: { Cookie: cookie, Origin: origin, 'X-CSRF-Token': csrf, 'Idempotency-Key': key, 'Content-Type': 'application/json' }, ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
        const result = await response.json();
        assert.equal(response.status, expected, JSON.stringify(result));
        return result;
    };
    assert.equal((await api('GET', '/camera')).camera_state, 'connected');
    const csrfDenied = await fetch(origin + '/custom/api/camera/preview/start', { method: 'POST', headers: { Cookie: cookie, Origin: origin, 'Content-Type': 'application/json' }, body: '{}' });
    assert.equal(csrfDenied.status, 403);
    await api('POST', '/camera/preview/start', {});
    const frame = await fetch(origin + '/custom/api/camera/preview.jpg', { headers: { Cookie: cookie } });
    assert.equal(frame.status, 200);
    assert.equal(createHash('sha256').update(Buffer.from(await frame.arrayBuffer())).digest('hex'), createHash('sha256').update(fixture).digest('hex'));
    const finish = async (id) => {
        for (let i = 0; i < 300; i++) {
            const session = await api('GET', '/sessions/' + id);
            if (!session.active_job) return session;
            await delay(100);
        }
        assert.fail('Job timeout');
    };
    let expectedTriggers = 0;
    const layouts = ['single', 'strip-three', 'postcard-three', 'grid-four'];
    const sessionCount = Number(process.env.PB_SOAK_SESSIONS || 4);
    assert.ok(Number.isInteger(sessionCount) && sessionCount >= 4 && sessionCount <= 100);
    for (let index = 0; index < sessionCount; index++) {
        const layout = layouts[index % layouts.length];
        let session = await api('POST', '/sessions', {}, randomUUID(), 201);
        const id = session.id;
        const templates = await api('GET', '/templates?layout_id=' + layout);
        assert.ok(templates.items.length, 'No template for ' + layout);
        session = await api('POST', `/sessions/${id}/configuration`, { revision: session.revision, layout_id: layout, template_id: templates.items[0].id, beauty_preset: 'off' });
        for (let slot = 1; slot <= session.capture_count; slot++) {
            const payload = { revision: session.revision }, key = randomUUID();
            const first = await api('POST', `/sessions/${id}/slots/${slot}/capture`, payload, key, 202);
            assert.deepEqual(await api('POST', `/sessions/${id}/slots/${slot}/capture`, payload, key, 202), first);
            session = await finish(id);
            assert.ok(session.slots[slot - 1].photo, JSON.stringify(session));
            assert.equal(session.slots[slot - 1].photo.sha256, createHash('sha256').update(fixture).digest('hex'));
            expectedTriggers++;
        }
        await api('POST', `/sessions/${id}/slots/1/retake`, { revision: session.revision }, randomUUID(), 202);
        session = await finish(id);
        expectedTriggers++;
        await api('POST', `/sessions/${id}/render`, { revision: session.revision }, randomUUID(), 202);
        session = await finish(id);
        assert.equal(session.state, 'REVIEW', JSON.stringify(session));
        const final = await api('POST', `/sessions/${id}/finalize`, { revision: session.revision, render_id: session.current_render.id }, randomUUID(), 201);
        const share = await api('POST', `/sessions/${id}/shares`, { revision: final.revision, final_asset_id: final.final_asset_id }, randomUUID(), 201);
        const download = await fetch(guest + new URL(share.download_url).pathname);
        assert.equal(download.status, 200);
        assert.equal(createHash('sha256').update(Buffer.from(await download.arrayBuffer())).digest('hex'), final.sha256);
        assert.ok(share.qr_data_uri?.startsWith('data:image/') || share.qr?.startsWith('data:image/'), JSON.stringify(share));
        const qrPath = path.join(data, 'qr.png');
        await writeFile(qrPath, Buffer.from(share.qr_data_uri.split(',')[1], 'base64'));
        const pixels = pngjs.PNG.sync.read(await readFile(qrPath));
        const decoded = jsQR(new Uint8ClampedArray(pixels.data), pixels.width, pixels.height);
        assert.equal(decoded?.data, share.url, 'QR must decode to the actual guest page URL');
        session = await api('GET', '/sessions/' + id);
        await api('POST', `/sessions/${id}/close`, { revision: session.revision });
    }
    assert.equal(triggers, expectedTriggers);
    console.log(`Verified ${sessionCount} closed sessions, ${triggers} shutter commands, one retake per session and decoded QR downloads.`);
    await api('POST', '/camera/preview/stop', {});
    child.stdin.end();
    await exited;
    await assert.rejects(fetch(origin + '/', { signal: AbortSignal.timeout(2000) }));
});
