'use strict';
const path = require('node:path');
const { fileURLToPath } = require('node:url');

// These calls use the signatures of the shipped TAIVOTO 3.12.10 bridge.
// No DLL is loaded a second time and no guessed native symbol is called.
function createNativeEngine(deps, electron, timing = {}) {
  const { koffi, callbacks, useFFI } = deps;
  const timeoutMs = timing.timeoutMs || 90000;
  const settleMs = timing.settleMs == null ? 180 : timing.settleMs;
  let active = null, pending = null, quarantine = false, handles = null;
  const events = [];
  function trace(action, details = {}) { events.push({ at: new Date().toISOString(), action, ...details }); if (events.length > 60) events.shift(); }
  const timers = new Set();
  function timer(fn, ms) { const t = setTimeout(() => { timers.delete(t); fn(); }, ms); timers.add(t); return t; }
  function clear(t) { clearTimeout(t); timers.delete(t); }
  function ffi() {
    const f = useFFI();
    if (!f || f.globalInit !== true) throw new Error('Bộ xử lý LK magic chưa sẵn sàng. Chờ ứng dụng khởi tạo rồi thử lại.');
    return f;
  }
  function callOnce(type, invoke, decode) {
    return new Promise((resolve, reject) => {
      let settled = false, released = false;
      trace('call', { type });
      const t = timer(() => { settled = true; quarantine = true; trace('timeout', { type }); reject(new Error('Bộ xử lý chưa trả kết quả. Đóng và mở lại LK magic trước khi thử tiếp.')); }, timeoutMs);
      let cb;
      const release = () => { if (!released) { released = true; koffi.unregister(cb); } };
      cb = koffi.register((...args) => {
        clear(t);
        trace('callback', { type, error: Number(args[type === 'detect' ? 0 : 1]) });
        try { if (!settled) { settled = true; resolve(decode(...args)); } }
        catch (e) { if (!settled) settled = true; reject(e); }
        finally { release(); }
      }, koffi.pointer(callbacks[type]));
      try {
        const code = invoke(cb);
        if (code !== 0 && code !== undefined && !settled) {
          settled = true; clear(t); release(); reject(new Error('Bộ xử lý từ chối yêu cầu, mã ' + code + '.'));
        }
      } catch (e) { clear(t); if (!settled) { settled = true; release(); reject(e); } }
      // A timeout deliberately keeps a still-native-owned callback registered.
    });
  }
  function checked(error, value, operation) {
    if (Number(error) !== 0) throw new Error(operation + ' không thành công, mã ' + error + '.');
    return value;
  }
  function ensureIdle() {
    if (quarantine) throw new Error('Phiên xử lý trước chưa kết thúc an toàn. Đóng và mở lại LK magic.');
    if (pending) throw new Error('Bộ xử lý đang hoàn tất ảnh trước. Hãy chờ.');
  }
  function initHandles() {
    if (handles) return;
    const reg = (type, fn) => koffi.register(fn, koffi.pointer(callbacks[type]));
    handles = {
      thumbnail: reg('thumbnail', (id, error, data) => {
        if (!pending || Number(id) !== pending.id) return;
        if (Number(error) !== 0) return fail(new Error('Không dựng được ảnh thử, mã ' + error + '.'));
        if (typeof data !== 'string' || !data || data === 'error') return fail(new Error('Bộ xử lý trả ảnh thử không hợp lệ.'));
        trace('thumbnail', { fileid: Number(id), format: data.startsWith('data:') ? 'data-url' : /^(\/9j\/|iVBOR)/.test(data) ? 'base64' : 'path-or-json', length: data.length });
        pending.image = data; completeSoon();
      }),
      histogram: reg('histogram', () => {}),
      write: reg('writeInfo', () => {}),
      switchParams: reg('switchParams', () => {}),
      universal: reg('universal', error => {
        if (pending && Number(error) !== 0) fail(new Error('Bộ xử lý báo lỗi ' + error + '.'));
      })
    };
    // Retouch callbacks have app lifetime, matching the original bridge. They
    // are never unregistered while native code could still invoke them.
  }
  function fail(error) {
    if (!pending) return;
    const p = pending; pending = null; clear(p.timeout); clear(p.settle);
    quarantine = true; trace('render-error', { error: error.message }); p.reject(error);
  }
  function completeSoon() {
    const p = pending;
    if (!p || !p.done || !p.image) return;
    clear(p.settle);
    p.settle = timer(() => {
      if (pending !== p) return;
      pending = null; clear(p.timeout); p.resolve(p.image);
    }, settleMs);
  }
  function onProgress(data) {
    const p = pending;
    if (!p || Number(data.fileid) !== p.id) return;
    if (data.error && Number(data.error) !== 0) return fail(new Error('Bộ xử lý báo lỗi ' + data.error + '.'));
    p.onProgress?.(Math.max(0, Math.min(100, Number(data.loading) || 0)));
    if (Number(data.loading) >= 100) { trace('completed', { fileid: p.id, loading: Number(data.loading) }); p.done = true; completeSoon(); }
  }
  async function create(sourcePath) {
    ensureIdle();
    if (active) throw new Error('Hãy đóng ảnh RAW hiện tại trước khi chọn ảnh khác.');
    const canvas = deps.getCurrentCanvasInfo?.();
    if (canvas && Number(canvas.id)) throw new Error('Hãy đóng ảnh đang chỉnh trong Dự án, quay về trang chính rồi mở Face màu.');
    const f = ffi();
    const prepared = await deps.prepareRawImportList([sourcePath]);
    if (!Array.isArray(prepared) || prepared.length !== 1) throw new Error('Không chuẩn bị được tệp ảnh gốc.');
    const originalExt = path.extname(sourcePath).toLowerCase(), preparedExt = path.extname(prepared[0]).toLowerCase();
    const isRaw = !['.jpg', '.jpeg', '.png'].includes(originalExt);
    const mode = isRaw && ['.jpg', '.jpeg', '.png'].includes(preparedExt) ? 'raw-jpeg-compat' : isRaw ? 'native-raw' : 'rgb';
    trace('input', { originalExt, preparedExt, mode });
    if (isRaw && mode === 'native-raw' && deps.isNativeRawSupported && !deps.isNativeRawSupported(sourcePath)) {
      throw new Error('Bộ đọc RAW hiện tại chưa hỗ trợ tệp này và chưa tạo được ảnh tương thích. Hãy dùng RAW được hỗ trợ hoặc JPG xuất từ RAW.');
    }
    const nativeName = path.basename(sourcePath, originalExt).normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-zA-Z0-9_-]/g, '_').slice(0, 60) || 'Anh';
    const projectName = 'Face mau - ' + nativeName + ' - ' + Date.now();
    const workspaceId = await callOnce('writeId', cb => f.aipic_insert_workspace_item(0, [1], JSON.stringify({ name: projectName, option: '{}' }), cb),
      (_cookie, error, id) => { checked(error, null, 'Tạo dự án Face màu'); if (!(Number(id) > 0)) throw new Error('Mã dự án không hợp lệ.'); return Number(id); });
    const importResult = await callOnce('batchWrite', cb => f.aipic_batch_insert_filelist_item(0, [1], JSON.stringify([{
      workspaceid: workspaceId, path: prepared[0], extinfo: '', shoottime: new Date().toISOString().slice(0, 19).replace('T', ' '), import_path: ''
    }]), 0, 1, true, cb), (_cookie, error, items) => JSON.parse(checked(error, items || '{}', 'Nhập ảnh')));
    let rows = typeof importResult.select_item === 'string' ? JSON.parse(importResult.select_item || '[]') : importResult.select_item;
    if (!Array.isArray(rows) || !rows.length) {
      rows = await callOnce('getData', cb => f.aipic_select_filelist_item_multi(0, [1], workspaceId, JSON.stringify({ filter_items: [0], sort_type: 1, asc_type: 1 }), cb),
        (_cookie, error, items) => JSON.parse(checked(error, items || '[]', 'Đọc ảnh vừa nhập')));
    }
    if (rows.length !== 1 || !(Number(rows[0].id) > 0)) throw new Error('Không tìm được đúng một ảnh vừa nhập. Dự án Face màu đã tạo vẫn được giữ.');
    active = { id: Number(rows[0].id), workspaceId, projectName, path: prepared[0], sourcePath, mode, segmentationReady: false, loaded: false };
    return { ...active, project: undefined };
  }
  async function ensureSegmentation() {
    if (!active || active.segmentationReady) return;
    const f = ffi();
    for (const detectType of [14, 15]) {
      await callOnce('detect', cb => f.aipic_get_some_dectect_info(0, detectType, '', active.id, [0], cb),
        (error, fileid) => { checked(error, null, 'Nhận diện chủ thể'); if (Number(fileid) !== active.id) throw new Error('Kết quả nhận diện sai ảnh.'); });
    }
    active.segmentationReady = true;
  }
  async function render(payload, onProgress) {
    ensureIdle();
    if (!active) throw new Error('Hãy chọn ảnh cần chỉnh.');
    const canvas = deps.getCurrentCanvasInfo?.();
    if (canvas && Number(canvas.id)) throw new Error('Ảnh trong Dự án đang mở. Hãy quay lại trang chính trước khi tạo preset.');
    initHandles();
    const f = ffi(), id = active.id;
    if (!active.loaded) {
      await pendingImage(() => f.aipic_retouch_switch_image([1], id, 4, handles.histogram, handles.thumbnail, handles.switchParams), id, onProgress);
      active.loaded = true;
    }
    if (payload.sex_retoucher_color_params.partial_params.length) await ensureSegmentation();
    const raw = await pendingImage(() => f.aipic_retouch_image(id, JSON.stringify({ fileid: id, param: JSON.stringify(payload), retouch_param_type: 2 }),
      1, false, false, true, false, handles.thumbnail, handles.histogram, handles.write, handles.universal, 0), id, onProgress);
    return previewDataURL(raw);
  }
  async function pendingImage(invoke, id, onProgress) {
    trace('render-begin', { fileid: id });
    const raw = await new Promise((resolve, reject) => {
      pending = { id, resolve, reject, image: '', done: false, onProgress };
      pending.timeout = timer(() => fail(new Error('Chưa nhận đủ ảnh thử và xác nhận hoàn tất từ bộ xử lý. Đóng và mở lại LK magic; có thể gửi tệp chẩn đoán để kiểm tra.')), timeoutMs);
      try {
        const code = invoke();
        if (code !== 0) fail(new Error('Bộ xử lý không nhận thông số, mã ' + code + '.'));
      } catch (e) { fail(e); }
    });
    return raw;
  }
  function previewDataURL(raw) {
    let value = raw.trim();
    if (value.startsWith('{')) { const obj = JSON.parse(value); value = obj.thumbnail || obj.path || obj.image || ''; }
    if (!value || value.length > 50 * 1024 * 1024) throw new Error('Ảnh thử quá lớn hoặc trống.');
    let image;
    if (/^data:image\/(?:png|jpeg|jpg);base64,/i.test(value)) image = electron.nativeImage.createFromDataURL(value);
    else if (/^(?:\/9j\/|iVBOR)/.test(value)) image = electron.nativeImage.createFromBuffer(Buffer.from(value, 'base64'));
    else {
      let local;
      if (value.startsWith('ai-static://file.disk.net/')) local = decodeURIComponent(new URL(value).pathname.slice(1));
      else if (value.startsWith('ai-image://')) local = deps.getCachePathByFileName?.(new URL(value).hostname);
      else if (value.startsWith('file:')) local = fileURLToPath(value);
      else if (path.isAbsolute(value)) local = value;
      if (!local) throw new Error('Định dạng ảnh thử chưa được nhận diện. Hãy gửi tệp chẩn đoán.');
      image = electron.nativeImage.createFromPath(local);
    }
    if (!image || image.isEmpty()) throw new Error('Không giải mã được ảnh thử từ bộ xử lý.');
    const size = image.getSize();
    if (size.width < 32 || size.height < 32) throw new Error('Ảnh thử quá nhỏ để đo màu.');
    if (Math.max(size.width, size.height) > 1200) {
      const ratio = 1200 / Math.max(size.width, size.height);
      image = image.resize({ width: Math.round(size.width * ratio), height: Math.round(size.height * ratio), quality: 'best' });
    }
    trace('decoded', { width: image.getSize().width, height: image.getSize().height });
    return image.toDataURL();
  }
  function close() { ensureIdle(); active = null; }
  function diagnostics() {
    let nativeVersion = '';
    try { nativeVersion = String(useFFI().aipic_get_version?.() || ''); } catch (_) {}
    return { nativeVersion, events: events.slice() };
  }
  return { create, render, close, onProgress, diagnostics, info: () => active && ({ id: active.id, workspaceId: active.workspaceId, projectName: active.projectName, mode: active.mode }), state: () => ({ busy: !!pending, quarantine }) };
}
module.exports = { createNativeEngine };
