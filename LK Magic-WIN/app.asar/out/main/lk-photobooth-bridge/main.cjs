'use strict';

const http = require('node:http');
const fs = require('node:fs');
const fsp = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const { fileURLToPath } = require('node:url');

/**
 * Photobooth <-> LK Magic Native AI Engine (aipic.dll + aipic-host.exe) Bridge.
 * - Listens strictly on 127.0.0.1 (loopback only).
 * - Exposes GET /api/v1/health, POST /api/v1/retouch, POST /api/v1/shutdown.
 * - Strict NO-FACE-SLIMMING / NO-BODY-RESHAPING policy across all presets.
 */

const SEX_AGE_BUCKETS = [
  { sex: 2, age: 2 }, // Nữ trẻ
  { sex: 1, age: 2 }, // Nam trẻ
  { sex: 2, age: 1 }, // Trẻ em (nữ)
  { sex: 1, age: 1 }, // Trẻ em (nam)
  { sex: 2, age: 3 }, // Nữ lớn tuổi
  { sex: 1, age: 3 }, // Nam lớn tuổi
];

// Strictly NO face/body reshaping IDs (230001..230012, 290001..290009 are excluded).
const PRESET_ALGORITHMS = {
  natural: [
    { id: 220003, algorithm: { value: 75 } },   // Nhặt mụn mặt (0..100, scope 1)
    { id: 220004, algorithm: { value: 60 } },   // Nhặt mụn cơ thể (0..100, scope 1)
    { id: 220001, algorithm: { value: 28 } },   // Mịn da tách tần số mặt (0..100, scope 1)
    { id: 220012, algorithm: { value: 40 } },   // Mịn da Neutral Grey mặt (0..100, scope 1)
    { id: 220002, algorithm: { value: 0.28 } }, // Mịn da cơ thể (0..1, scope 0.01)
    { id: 220013, algorithm: { value: 40 } },   // Đều màu da mặt (0..100, scope 1)
    { id: 220017, algorithm: { value: 0.15 } }, // Trắng da nhẹ (0..1, scope 0.01)
    { id: 220016, algorithm: { value: 0.20 } }, // Da trong trẻo (0..1, scope 0.01)
    { id: 201001, algorithm: { value: 30 } },   // Trắng răng nhẹ (0..100, scope 1)
    { id: 240001, algorithm: { value: 0.25 } }, // Sáng mắt (0..1, scope 0.01)
  ],
  soft: [
    { id: 220003, algorithm: { value: 90 } },   // Nhặt mụn mặt
    { id: 220004, algorithm: { value: 75 } },   // Nhặt mụn cơ thể
    { id: 220008, algorithm: { value: 45 } },   // Giảm quầng thâm mắt
    { id: 220009, algorithm: { value: 40 } },   // Giảm rãnh cười
    { id: 220001, algorithm: { value: 45 } },   // Mịn da tách tần số mặt
    { id: 220012, algorithm: { value: 60 } },   // Mịn da Neutral Grey mặt
    { id: 220002, algorithm: { value: 0.45 } }, // Mịn da cơ thể
    { id: 220013, algorithm: { value: 55 } },   // Đều màu da mặt
    { id: 220018, algorithm: { value: 50 } },   // Đều màu da cơ thể
    { id: 220017, algorithm: { value: 0.30 } }, // Trắng da
    { id: 220016, algorithm: { value: 0.30 } }, // Da trong trẻo
    { id: 220019, algorithm: { scene: '/filter/skin/naibai.png', value: 0.45 } }, // Tone Trắng sữa
    { id: 201001, algorithm: { value: 45 } },   // Trắng răng
    { id: 240001, algorithm: { value: 0.35 } }, // Sáng mắt
    { id: 710001, algorithm: { value: 50 } },   // Xóa nhăn quần áo
  ],
  'korean-glow': [
    { id: 220003, algorithm: { value: 100 } },  // Nhặt mụn mặt tối đa
    { id: 220004, algorithm: { value: 85 } },   // Nhặt mụn cơ thể
    { id: 220008, algorithm: { value: 60 } },   // Giảm quầng thâm mắt
    { id: 220009, algorithm: { value: 55 } },   // Giảm rãnh cười
    { id: 220010, algorithm: { value: 50 } },   // Giảm nếp nhăn cổ
    { id: 220001, algorithm: { value: 55 } },   // Mịn da tách tần số mặt
    { id: 220012, algorithm: { value: 70 } },   // Mịn da Neutral Grey mặt
    { id: 220002, algorithm: { value: 0.55 } }, // Mịn da cơ thể
    { id: 220013, algorithm: { value: 65 } },   // Đều màu da mặt
    { id: 220018, algorithm: { value: 60 } },   // Đều màu da cơ thể
    { id: 220017, algorithm: { value: 0.40 } }, // Trắng da
    { id: 220016, algorithm: { value: 0.40 } }, // Da trong trẻo
    { id: 220015, algorithm: { value: 0.25 } }, // Da hồng hào
    { id: 220019, algorithm: { scene: '/filter/skin/fenbai.png', value: 0.60 } }, // Tone Trắng hồng Hàn Quốc
    { id: 220025, algorithm: { value: 35 } },   // Highlight khối mặt 3D
    { id: 201001, algorithm: { value: 60 } },   // Trắng răng
    { id: 240001, algorithm: { value: 0.45 } }, // Sáng mắt
    { id: 710001, algorithm: { value: 70 } },   // Xóa nhăn quần áo
  ],
};

function buildRetouchPayload(presetName, beautyConfig = {}) {
  const normalizedPreset = String(presetName || 'natural').trim().toLowerCase();
  const baseAlgos = PRESET_ALGORITHMS[normalizedPreset] || PRESET_ALGORITHMS.natural;
  const cloned = JSON.parse(JSON.stringify(baseAlgos));

  if (beautyConfig && typeof beautyConfig === 'object') {
    const smooth = Number(beautyConfig.smooth);
    const brighten = Number(beautyConfig.brighten);
    const tone = Number(beautyConfig.tone);

    for (const item of cloned) {
      if (Number.isFinite(smooth) && smooth >= 0 && smooth <= 1) {
        if (item.id === 220001) item.algorithm.value = Math.round(Math.max(15, Math.min(85, smooth * 100)));
        if (item.id === 220012) item.algorithm.value = Math.round(Math.max(20, Math.min(90, smooth * 125)));
        if (item.id === 220002) item.algorithm.value = Number(Math.max(0.15, Math.min(0.85, smooth)).toFixed(2));
      }
      if (Number.isFinite(brighten) && brighten >= 0 && brighten <= 1) {
        if (item.id === 220017) item.algorithm.value = Number(Math.max(0.10, Math.min(0.75, brighten * 2.5)).toFixed(2));
        if (item.id === 220016) item.algorithm.value = Number(Math.max(0.15, Math.min(0.75, brighten * 2.5)).toFixed(2));
      }
      if (Number.isFinite(tone) && tone >= 0 && tone <= 1) {
        if (item.id === 220013) item.algorithm.value = Math.round(Math.max(25, Math.min(90, tone * 320)));
      }
    }
  }

  return {
    sex_retoucher_beauty_params: SEX_AGE_BUCKETS.map((bucket) => ({
      sex: bucket.sex,
      age: bucket.age,
      params: cloned,
      combine_classifyid_group: [],
    })),
    sex_retoucher_color_params: {
      params: [],
      partial_params: [],
    },
  };
}

function install(electron, deps) {
  if (global.__LK_PHOTOBOOTH_BRIDGE_V1__) return global.__LK_PHOTOBOOTH_BRIDGE_V1__;

  const { koffi, callbacks, useFFI, quitApp, getCachePathByFileName } = deps;
  const port = Number(process.env.LK_PHOTOBOOTH_PORT || 5520);
  const stageRoot = path.join(os.tmpdir(), 'lkmagic-photobooth-stage');
  fs.mkdirSync(stageRoot, { recursive: true });

  let workspaceId = null;
  let busy = false;
  let totalProcessed = 0;
  let lastError = null;
  const events = [];
  let queue = Promise.resolve();
  let pendingCanvas = null;
  let canvasHandles = null;
  const exportWaiters = new Map();
  const completedExports = new Map();

  function trace(action, details = {}) {
    events.push({ at: new Date().toISOString(), action, ...details });
    if (events.length > 60) events.shift();
  }

  function getFFI() {
    const f = useFFI();
    if (!f || f.globalInit !== true || (typeof deps.isReady === 'function' && !deps.isReady())) {
      throw new Error('ENGINE_NOT_READY: Lõi aipic.dll chưa khởi tạo xong.');
    }
    return f;
  }

  function callOnce(type, invoke, decode, timeoutMs = 20000) {
    return new Promise((resolve, reject) => {
      let settled = false;
      let released = false;
      let cb;
      trace('call-begin', { type });
      const release = () => {
        if (!released && cb) {
          released = true;
          try { koffi.unregister(cb); } catch (_) {}
        }
      };
      const timer = setTimeout(() => {
        if (!settled) {
          settled = true;
          trace('call-timeout', { type });
          reject(new Error(`TIMEOUT: Quá thời gian chờ lệnh native (${type}).`));
        }
      }, timeoutMs);

      cb = koffi.register((...args) => {
        clearTimeout(timer);
        trace('call-cb', { type, arg0: Number(args[0]), arg1: Number(args[1]) });
        try {
          if (!settled) {
            const result = decode(...args);
            settled = true;
            resolve(result);
          }
        } catch (err) {
          if (!settled) {
            settled = true;
            reject(err);
          }
        } finally {
          release();
        }
      }, koffi.pointer(callbacks[type]));

      try {
        const rc = invoke(cb);
        if (rc !== 0 && rc !== undefined && !settled) {
          clearTimeout(timer);
          settled = true;
          release();
          reject(new Error(`NATIVE_CALL_FAILED: ${type} trả mã lỗi ${rc}.`));
        }
      } catch (err) {
        clearTimeout(timer);
        if (!settled) {
          settled = true;
          release();
          reject(err);
        }
      }
    });
  }

  function settlePendingCanvas() {
    const p = pendingCanvas;
    if (!p || !p.done || !p.image) return;
    if (p.settleTimer) clearTimeout(p.settleTimer);
    p.settleTimer = setTimeout(() => {
      if (pendingCanvas !== p) return;
      pendingCanvas = null;
      clearTimeout(p.timeoutTimer);
      p.resolve(p.image);
    }, 120);
  }

  function failPendingCanvas(err) {
    const p = pendingCanvas;
    if (!p) return;
    pendingCanvas = null;
    if (p.timeoutTimer) clearTimeout(p.timeoutTimer);
    if (p.settleTimer) clearTimeout(p.settleTimer);
    trace('canvas-error', { error: err.message });
    p.reject(err);
  }

  function ensureCanvasHandles() {
    if (canvasHandles) return canvasHandles;
    const reg = (type, fn) => koffi.register(fn, koffi.pointer(callbacks[type]));
    canvasHandles = {
      thumbnail: reg('thumbnail', (id, error, data) => {
        trace('raw-thumb-cb', { id: Number(id), error: Number(error), len: typeof data === 'string' ? data.length : 0 });
        if (!pendingCanvas || Number(id) !== pendingCanvas.fileid) return;
        if (Number(error) !== 0 && Number(error) !== 810002) {
          return failPendingCanvas(new Error(`Canvas thumbnail lỗi ${error}`));
        }
        if (typeof data === 'string' && data && data !== 'error') {
          trace('thumbnail-cb', { fileid: Number(id), len: data.length, prefix: data.slice(0, 32) });
          pendingCanvas.image = data;
          settlePendingCanvas();
        }
      }),
      histogram: reg('histogram', () => {}),
      writeInfo: reg('writeInfo', () => {}),
      switchParams: reg('switchParams', (id, error) => {
        trace('raw-switch-cb', { id: Number(id), error: Number(error) });
      }),
      universal: reg('universal', (error) => {
        trace('raw-univ-cb', { error: Number(error) });
        if (pendingCanvas && Number(error) !== 0) {
          failPendingCanvas(new Error(`Canvas universal lỗi ${error}`));
        }
      }),
    };
    return canvasHandles;
  }

  function onRetouchLoadingEvent(rawJson) {
    trace('raw-loading-ev', { raw: String(rawJson).slice(0, 120) });
    try {
      const data = typeof rawJson === 'string' ? JSON.parse(rawJson) : rawJson;
      if (!pendingCanvas || Number(data.fileid) !== pendingCanvas.fileid) return;
      if (data.error && Number(data.error) !== 0) {
        return failPendingCanvas(new Error(`Retouch loading lỗi ${data.error}`));
      }
      if (Number(data.loading) >= 100) {
        trace('loading-100', { fileid: Number(data.fileid) });
        pendingCanvas.done = true;
        settlePendingCanvas();
      }
    } catch (_) {}
  }

  function onExportTaskEvent(rawJson) {
    trace('export-event', { raw: String(rawJson).slice(0, 160) });
    try {
      const ev = typeof rawJson === 'string' ? JSON.parse(rawJson) : rawJson;
      const taskId = String(ev?.id ?? '');
      const waiter = exportWaiters.get(taskId);
      if (waiter && (String(ev?.status) === '2' || String(ev?.status) === '3')) {
        exportWaiters.delete(taskId);
        clearTimeout(waiter.timer);
        waiter.resolve(ev);
      } else if (['2', '3'].includes(String(ev?.status))) {
        completedExports.set(taskId, ev);
        if (completedExports.size > 60) completedExports.delete(completedExports.keys().next().value);
      }
    } catch (_) {}
  }

  async function ensureWorkspace() {
    if (workspaceId && workspaceId > 0) return workspaceId;
    const f = getFFI();
    const name = 'Photobooth_AI_Bridge_' + Date.now();
    workspaceId = await callOnce(
      'writeId',
      (cb) => f.aipic_insert_workspace_item(0, [1], JSON.stringify({ name, option: '{}' }), cb),
      (_cookie, error, id) => {
        if (Number(error) !== 0 || !(Number(id) > 0)) {
          throw new Error(`Không tạo được workspace Photobooth (error=${error}, id=${id}).`);
        }
        return Number(id);
      }
    );
    return workspaceId;
  }

  async function importStagedJpeg(stagedPath, wsId) {
    const f = getFFI();
    const shoottime = new Date().toISOString().slice(0, 19).replace('T', ' ');
    const importRes = await callOnce(
      'batchWrite',
      (cb) =>
        f.aipic_batch_insert_filelist_item(
          0,
          [1],
          JSON.stringify([{ workspaceid: wsId, path: stagedPath, extinfo: '', shoottime, import_path: '' }]),
          0,
          1,
          true,
          cb
        ),
      (_cookie, error, items) => {
        if (Number(error) !== 0) throw new Error(`Nhập ảnh vào aipic thất bại (error=${error}).`);
        return JSON.parse(items || '{}');
      }
    );
    let rows =
      typeof importRes.select_item === 'string'
        ? JSON.parse(importRes.select_item || '[]')
        : importRes.select_item;
    if (!Array.isArray(rows) || !rows.length) {
      rows = await callOnce(
        'getData',
        (cb) =>
          f.aipic_select_filelist_item_multi(
            0,
            [1],
            wsId,
            JSON.stringify({ filter_items: [0], sort_type: 1, asc_type: 1 }),
            cb
          ),
        (_cookie, error, items) => {
          if (Number(error) !== 0) throw new Error(`Đọc danh sách ảnh thất bại (error=${error}).`);
          return JSON.parse(items || '[]');
        }
      );
    }
    const matched = Array.isArray(rows)
      ? rows.find((r) => r && String(r.path || '').toLowerCase() === stagedPath.toLowerCase()) || rows[rows.length - 1]
      : null;
    if (!matched || !(Number(matched.id) > 0)) {
      throw new Error('Không lấy được fileid sau khi nhập ảnh vào aipic.dll.');
    }
    return Number(matched.id);
  }

  async function renderViaNativeEngine(fileid, payload, targetPath, expectedSize, timeoutMs = 25000) {
    const f = getFFI();
    const h = ensureCanvasHandles();

    const runStep = (stepName, invoke) =>
      new Promise((resolve, reject) => {
        trace('step-begin', { stepName, fileid });
        const timeoutTimer = setTimeout(() => {
          failPendingCanvas(new Error(`TIMEOUT_${stepName}`));
        }, timeoutMs);
        pendingCanvas = { fileid, image: null, done: false, resolve, reject, timeoutTimer, settleTimer: null };
        try {
          const rc = invoke();
          if (rc !== 0) {
            failPendingCanvas(new Error(`${stepName} trả mã lỗi ${rc}`));
          }
        } catch (err) {
          failPendingCanvas(err);
        }
      });

    // Step 1: Switch image into native retouch canvas (loads image & runs base face detection)
    await runStep('switch_image', () =>
      f.aipic_retouch_switch_image([1], fileid, 4, h.histogram, h.thumbnail, h.switchParams)
    );

    // Step 2: Apply AI beauty retouch parameters via aipic_retouch_image (with is_file_cache=true)
    await runStep('retouch_image', () =>
      f.aipic_retouch_image(
        fileid,
        JSON.stringify({ fileid, param: JSON.stringify(payload), retouch_param_type: 2 }),
        1,
        true,
        false,
        true,
        false,
        h.thumbnail,
        h.histogram,
        h.writeInfo,
        h.universal,
        0
      )
    );

    // Only a confirmed full-resolution JPEG export may leave the bridge.
    const exportDir = path.dirname(targetPath);
    await callOnce(
      'writeItem',
      (cb) => f.aipic_modify_filelist_item(0, [1], JSON.stringify({ id: fileid, edit: 1 }), cb),
      (_cookie, error) => {
        if (Number(error) !== 0) throw new Error('EXPORT_FAILED: Cannot mark image edited, error=' + error);
        return true;
      },
      5000
    );
    const exportObj = {
      version: '1.0',
      fileitems: [{ fileid }],
      by_origin_path: 0,
      location: '0',
      cid: '0',
      create_new_folder: 0,
      keep_import_folder: 0,
      faceitems: [],
      operation_type: 0,
      quality: 96,
      export_setting: JSON.stringify({ export_max_size: 0, quality: 96, export_type: 1 }),
    };
    const exportId = await callOnce(
      'buildExport',
      (cb) => f.aipic_build_export_task(0, workspaceId, exportDir, JSON.stringify(exportObj), cb),
      (error, id) => {
        if (Number(error) !== 0 || !(Number(id) > 0)) throw new Error('EXPORT_FAILED: buildExport error=' + error);
        return Number(id);
      },
      8000
    );
    const exportEvent = completedExports.get(String(exportId)) || await new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        exportWaiters.delete(String(exportId));
        reject(new Error('EXPORT_TIMEOUT: Full-resolution export did not complete.'));
      }, 12000);
      exportWaiters.set(String(exportId), { resolve, timer });
    });
    completedExports.delete(String(exportId));
    if (String(exportEvent.status) !== '2') throw new Error('EXPORT_FAILED: Native export did not succeed.');

    for (const name of fs.readdirSync(exportDir).filter((n) => /\.jpe?g$/i.test(n) && n !== path.basename(targetPath))) {
      const candidate = path.join(exportDir, name);
      if (!fs.statSync(candidate).isFile()) continue;
      const bytes = fs.readFileSync(candidate);
      if (bytes[0] !== 0xff || bytes[1] !== 0xd8) continue;
      const image = electron.nativeImage.createFromPath(candidate);
      const size = image.getSize();
      if (image.isEmpty() || size.width !== expectedSize.width || size.height !== expectedSize.height) continue;
      fs.copyFileSync(candidate, targetPath);
      trace('export-fullres-ok', { size: bytes.length, file: name, width: size.width, height: size.height });
      return targetPath;
    }
    throw new Error('INVALID_EXPORT: No full-resolution JPEG matching the source dimensions.');
  }

  async function processRetouchRequest(body) {
    const startMs = Date.now();
    const inputPath = path.resolve(String(body.input_path || ''));
    const outputPath = path.resolve(String(body.output_path || ''));
    const preset = String(body.preset || 'natural').trim().toLowerCase();
    const beautyConfig = body.beauty_config || {};
    const timeoutMs = Math.min(60000, Math.max(5000, Number(body.timeout_ms) || 25000));

    if (!inputPath || !fs.existsSync(inputPath)) {
      throw new Error('MISSING_INPUT: File ảnh đầu vào không tồn tại.');
    }
    if (!outputPath) {
      throw new Error('INVALID_OUTPUT: Thiếu đường dẫn output_path.');
    }

    const jobUuid = crypto.randomUUID().replace(/-/g, '').slice(0, 16);
    const jobDir = path.join(stageRoot, `job_${jobUuid}`);
    const stageInDir = path.join(jobDir, 'in');
    const stageOutDir = path.join(jobDir, 'out');
    fs.mkdirSync(stageInDir, { recursive: true });
    fs.mkdirSync(stageOutDir, { recursive: true });

    const stagedInput = path.join(stageInDir, `pb_${jobUuid}.jpg`);
    const stagedOutput = path.join(stageOutDir, `out_${jobUuid}.jpg`);
    let fileid = null;
    let wsId = null;

    try {
      fs.copyFileSync(inputPath, stagedInput);
      wsId = await ensureWorkspace();
      fileid = await importStagedJpeg(stagedInput, wsId);

      const inputImage = electron.nativeImage.createFromPath(stagedInput);
      if (inputImage.isEmpty()) throw new Error('INVALID_INPUT: Cannot decode source JPEG.');
      const expectedSize = inputImage.getSize();
      const payload = buildRetouchPayload(preset, beautyConfig);
      const producedFile = await renderViaNativeEngine(fileid, payload, stagedOutput, expectedSize, timeoutMs);

      fs.mkdirSync(path.dirname(outputPath), { recursive: true });
      const tmpOut = `${outputPath}.lk.${jobUuid}.tmp`;
      fs.copyFileSync(producedFile, tmpOut);
      fs.renameSync(tmpOut, outputPath);

      totalProcessed += 1;
      const durationMs = Date.now() - startMs;
      trace('retouch-ok', { fileid, durationMs });
      return {
        ok: true,
        status: 'success',
        engine: 'lkmagic_native',
        method: 'export_fullres',
        preset,
        face_slim: false,
        output_path: outputPath,
        duration_ms: durationMs,
      };
    } finally {
      try {
        await fsp.rm(jobDir, { recursive: true, force: true });
      } catch (_) {}
    }
  }

  function enqueueRetouch(body) {
    const run = queue.then(async () => {
      busy = true;
      try {
        return await processRetouchRequest(body);
      } catch (err) {
        lastError = err.message || String(err);
        throw err;
      } finally {
        busy = false;
      }
    });
    queue = run.catch(() => {});
    return run;
  }

  function readJsonBody(req) {
    return new Promise((resolve, reject) => {
      const chunks = [];
      let total = 0;
      req.on('data', (chunk) => {
        total += chunk.length;
        if (total > 2 * 1024 * 1024) {
          reject(new Error('Payload quá lớn.'));
          req.destroy();
          return;
        }
        chunks.push(chunk);
      });
      req.on('end', () => {
        try {
          const raw = Buffer.concat(chunks).toString('utf8');
          resolve(raw ? JSON.parse(raw) : {});
        } catch (err) {
          reject(new Error('JSON không hợp lệ.'));
        }
      });
      req.on('error', reject);
    });
  }

  function sendJson(res, statusCode, payload) {
    const body = Buffer.from(JSON.stringify(payload), 'utf8');
    res.writeHead(statusCode, {
      'Content-Type': 'application/json; charset=utf-8',
      'Content-Length': body.length,
      'Cache-Control': 'no-store',
    });
    res.end(body);
  }

  const server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url || '/', `http://127.0.0.1:${port}`);
      if (req.method === 'GET' && url.pathname === '/api/v1/health') {
        const f = useFFI();
        const ready = Boolean(f && f.globalInit === true && (typeof deps.isReady !== 'function' || deps.isReady()));
        let version = '';
        if (ready && typeof f.aipic_get_version === 'function') {
          try { version = String(f.aipic_get_version() || ''); } catch (_) {}
        }
        return sendJson(res, 200, {
          ok: true,
          ready,
          busy,
          version,
          background: process.env.LK_PHOTOBOOTH_BACKGROUND === '1',
          face_slim_enabled: false,
          total_processed: totalProcessed,
          last_error: lastError,
          events: events.slice(-15),
        });
      }

      if (req.method === 'POST' && url.pathname === '/api/v1/retouch') {
        const body = await readJsonBody(req);
        const result = await enqueueRetouch(body);
        return sendJson(res, 200, result);
      }

      if (req.method === 'POST' && url.pathname === '/api/v1/bake-batch') {
        const body = await readJsonBody(req);
        const run = queue.then(async () => {
          busy = true;
          try {
            const rawInputPath = path.resolve(String(body.input_path || ''));
            const stagedHald = path.join(stageRoot, 'hald_stage_' + Date.now() + '.jpg');
            fs.copyFileSync(rawInputPath, stagedHald);
            const filters = Array.isArray(body.filters) ? body.filters : [];
            const f = getFFI();
            const h = ensureCanvasHandles();
            const wsId = await ensureWorkspace();
            const fileid = await importStagedJpeg(stagedHald, wsId);

            const runStep = (stepName, invoke) =>
              new Promise((resolve, reject) => {
                trace('bake-step', { stepName, fileid });
                const timeoutTimer = setTimeout(() => {
                  failPendingCanvas(new Error(`TIMEOUT_${stepName}`));
                }, 35000);
                pendingCanvas = { stepName, fileid, image: null, done: false, resolve, reject, timeoutTimer, settleTimer: null };
                try {
                  const rc = invoke();
                  if (rc !== 0) failPendingCanvas(new Error(`${stepName} rc=${rc}`));
                } catch (err) {
                  failPendingCanvas(err);
                }
              });

            await runStep('switch_image', () =>
              f.aipic_retouch_switch_image([1], fileid, 4, h.histogram, h.thumbnail, h.switchParams)
            );

            const results = [];
            for (const item of filters) {
              const filterId = Number(item.filter_id);
              const outPng = path.resolve(String(item.output_path));
              const val = Number(item.value ?? 1.0);
              const payload = {
                sex_retoucher_beauty_params: [],
                sex_retoucher_color_params: {
                  params: filterId > 0 ? [{ id: filterId, algorithm: { value: val } }] : [],
                  partial_params: [],
                },
              };
              const raw = await runStep(`bake_${filterId}`, () =>
                f.aipic_retouch_image(
                  fileid,
                  JSON.stringify({ fileid, param: JSON.stringify(payload), retouch_param_type: 2 }),
                  1,
                  false,
                  false,
                  true,
                  false,
                  h.thumbnail,
                  h.histogram,
                  h.writeInfo,
                  h.universal,
                  0
                )
              );
              let value = String(raw || '').trim();
              if (value.startsWith('{')) {
                const parsed = JSON.parse(value);
                value = parsed.thumbnail || parsed.path || parsed.image || '';
              }
              let localPath = null;
              if (value.startsWith('ai-static://file.disk.net/')) {
                localPath = decodeURIComponent(new URL(value).pathname.slice(1));
              } else if (value.startsWith('ai-image://') && typeof getCachePathByFileName === 'function') {
                localPath = getCachePathByFileName(new URL(value).hostname);
              } else if (value.startsWith('file:')) {
                localPath = fileURLToPath(value);
              } else if (path.isAbsolute(value)) {
                localPath = value;
              }
              let img;
              if (localPath && fs.existsSync(localPath)) {
                img = electron.nativeImage.createFromPath(localPath);
              } else if (/^data:image\/(?:png|jpeg|jpg);base64,/i.test(value)) {
                img = electron.nativeImage.createFromDataURL(value);
              } else if (/^(?:\/9j\/|iVBOR)/.test(value)) {
                img = electron.nativeImage.createFromBuffer(Buffer.from(value, 'base64'));
              }
              if (!img || img.isEmpty()) throw new Error(`Decode failed for ${filterId} (raw=${String(value).slice(0, 80)})`);
              fs.mkdirSync(path.dirname(outPng), { recursive: true });
              fs.writeFileSync(outPng, img.toPNG());
              results.push({ filter_id: filterId, size: img.getSize(), output_path: outPng });
            }
            try { fs.unlinkSync(stagedHald); } catch (_) {}
            return { ok: true, count: results.length, results };
          } catch (err) {
            lastError = err.message || String(err);
            throw err;
          } finally {
            busy = false;
          }
        });
        queue = run.catch(() => {});
        const result = await run;
        return sendJson(res, 200, result);
      }

      if (req.method === 'POST' && url.pathname === '/api/v1/shutdown') {
        sendJson(res, 200, { ok: true, shutting_down: true });
        setTimeout(async () => {
          try {
            if (typeof quitApp === 'function') await quitApp();
          } catch (_) {}
          electron.app.exit(0);
        }, 150);
        return;
      }

      return sendJson(res, 404, { ok: false, error: 'NOT_FOUND' });
    } catch (err) {
      return sendJson(res, 500, {
        ok: false,
        error: err && err.message ? err.message : String(err),
        events: events.slice(-15),
      });
    }
  });

  server.on('error', (err) => {
    console.error('[LK Photobooth Bridge] Server error:', err && err.message);
  });

  server.listen(port, '127.0.0.1', () => {
    console.log(`[LK Photobooth Bridge] Listening on http://127.0.0.1:${port}`);
  });

  // Pre-warm AI models in background only when explicitly requested
  if (process.env.LK_PHOTOBOOTH_WARMUP === '1') {
    const warmupSample = path.resolve(__dirname, '../../../../resources/render/color_ai_example/icon/chunbaishiyan/chunbaishiyan01.jpg');
    const warmupInterval = setInterval(() => {
      const f = useFFI();
      if (!f || f.globalInit !== true) return;
      clearInterval(warmupInterval);
      if (totalProcessed === 0 && !busy && fs.existsSync(warmupSample)) {
        const warmupOut = path.join(stageRoot, 'warmup_out.jpg');
        enqueueRetouch({
          input_path: warmupSample,
          output_path: warmupOut,
          preset: 'natural',
          timeout_ms: 40000,
        })
          .then(() => {
            trace('warmup-complete');
            try { fs.unlinkSync(warmupOut); } catch (_) {}
          })
          .catch((err) => {
            trace('warmup-skip', { error: err.message });
          });
      }
    }, 1500);
    if (typeof warmupInterval.unref === 'function') warmupInterval.unref();
  }

  const api = {
    server,
    onRetouchLoadingEvent,
    onExportTaskEvent,
    buildRetouchPayload,
  };
  global.__LK_PHOTOBOOTH_BRIDGE_V1__ = api;
  return api;
}

module.exports = {
  install,
  buildRetouchPayload,
  PRESET_ALGORITHMS,
};
