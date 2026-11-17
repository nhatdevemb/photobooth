'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { install } = require('../../../LK Magic-WIN/app.asar/out/main/lk-photobooth-bridge/main.cjs');

async function fixture(t) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'pb-bridge-test-'));
  const input = path.join(directory, 'input.jpg');
  fs.writeFileSync(input, Buffer.from([0xff, 0xd8, 0xff, 0xd9]));
  const previousPort = process.env.LK_PHOTOBOOTH_PORT;
  process.env.LK_PHOTOBOOTH_PORT = '0';
  delete global.__LK_PHOTOBOOTH_BRIDGE_V1__;
  let api, importedPath;
  const state = { failure: null, ready: true };
  const fail = (name) => {
    if (state.failure !== name) return false;
    state.failure = null;
    return true;
  };
  const ffi = {
    globalInit: true,
    aipic_get_version: () => 'test-native',
    aipic_insert_workspace_item: (cookie, type, data, cb) => {
      setImmediate(() => cb(0, fail('workspace') ? 17 : 0, 7));
      return 0;
    },
    aipic_batch_insert_filelist_item: (a, b, json, c, d, e, cb) => {
      importedPath = JSON.parse(json)[0].path;
      const result = fail('import-json') ? '{broken' : JSON.stringify({ select_item: [{ id: 10, path: importedPath }] });
      setImmediate(() => cb(0, 0, result));
      return 0;
    },
    aipic_retouch_switch_image: (a, id, b, histogram, thumbnail) => {
      setImmediate(() => {
        thumbnail(id, 0, 'preview-only');
        api.onRetouchLoadingEvent({ fileid: id, loading: 100 });
      });
      return 0;
    },
    aipic_retouch_image: (id, json, a, b, c, d, e, thumbnail) => {
      setImmediate(() => {
        thumbnail(id, 0, 'preview-only');
        api.onRetouchLoadingEvent({ fileid: id, loading: 100 });
      });
      return 0;
    },
    aipic_modify_filelist_item: (a, b, json, cb) => {
      setImmediate(() => cb(0, 0));
      return 0;
    },
    aipic_build_export_task: (cookie, workspace, directory, json, cb) => {
      const error = fail('export-error');
      const missing = fail('missing-export');
      const small = fail('small-export');
      if (!error && !missing) fs.writeFileSync(path.join(directory, small ? 'small.jpg' : 'full.jpg'), fs.readFileSync(input));
      setImmediate(() => {
        cb(error ? 17 : 0, 99);
        // Completion may arrive before the awaiting caller registers its waiter.
        api.onExportTaskEvent({ id: 99, status: error ? 3 : 2 });
      });
      return 0;
    },
  };
  api = install({
    app: { exit() {} },
    nativeImage: { createFromPath(file) {
      return { isEmpty: () => false, getSize: () => file.endsWith('small.jpg') ? { width: 160, height: 120 } : { width: 640, height: 480 } };
    } },
  }, {
    koffi: { register: (fn) => fn, pointer: (fn) => fn, unregister() {} },
    callbacks: {}, useFFI: () => ffi, isReady: () => state.ready,
  });
  t.after(async () => {
    api.server.closeAllConnections();
    await new Promise((resolve) => api.server.close(resolve));
    delete global.__LK_PHOTOBOOTH_BRIDGE_V1__;
    if (previousPort === undefined) delete process.env.LK_PHOTOBOOTH_PORT;
    else process.env.LK_PHOTOBOOTH_PORT = previousPort;
    fs.rmSync(directory, { recursive: true, force: true });
  });
  await new Promise((resolve) => api.server.once('listening', resolve));
  const url = `http://127.0.0.1:${api.server.address().port}`;
  const output = path.join(directory, 'output.jpg');
  return {
    state, output,
    health: () => fetch(url + '/api/v1/health').then((r) => r.json()),
    retouch: async () => {
      const res = await fetch(url + '/api/v1/retouch', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ input_path: input, output_path: output, preset: 'natural' }),
        signal: AbortSignal.timeout(2000),
      });
      return { status: res.status, body: await res.json() };
    },
  };
}

test('health waits for model and account initialization before accepting jobs', async (t) => {
  const bridge = await fixture(t);
  bridge.state.ready = false;
  assert.equal((await bridge.health()).ready, false);
  const rejected = await bridge.retouch();
  assert.equal(rejected.status, 500);
  assert.match(rejected.body.error, /ENGINE_NOT_READY/);
  bridge.state.ready = true;
  assert.equal((await bridge.health()).ready, true);
  assert.equal((await bridge.retouch()).status, 200);
});

for (const failure of ['workspace', 'import-json', 'export-error']) {
  test(`native ${failure} error rejects and leaves the queue usable`, async (t) => {
    const bridge = await fixture(t);
    bridge.state.failure = failure;
    assert.equal((await bridge.retouch()).status, 500);
    assert.equal((await bridge.health()).busy, false);
    const next = await bridge.retouch();
    assert.equal(next.status, 200);
    assert.equal(next.body.method, 'export_fullres');
  });
}

for (const failure of ['missing-export', 'small-export']) {
  test(`${failure} cannot publish a thumbnail as a successful export`, async (t) => {
    const bridge = await fixture(t);
    bridge.state.failure = failure;
    const result = await bridge.retouch();
    assert.equal(result.status, 500);
    assert.match(result.body.error, /INVALID_EXPORT/);
    assert.equal(fs.existsSync(bridge.output), false);
    assert.equal((await bridge.health()).busy, false);
  });
}
