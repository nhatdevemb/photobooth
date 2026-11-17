'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { fileURLToPath } = require('node:url');
const core = require('../../renderer/assets/lk-face-preset/preset-core.js');
const { createNativeEngine } = require('./native-engine.cjs');
const PREFIX = 'lk-face-preset:v1:';
const EXTENSIONS = ['cr2','cr3','nef','nrw','arw','dng','raf','rw2','orf','pef','srw','raw','crw','x3f','mrw','kdc','3fr','fff','jpg','jpeg','png'];

function saveLocalPreset(root, version, name, vector, provenance) {
  const title = String(name || '').normalize('NFC').trim();
  if (!title || title.length > 100 || /[\x00-\x1f]/.test(title)) throw new Error('Tên preset cần có từ 1 đến 100 ký tự.');
  const folder = path.join(root, 'presets'), target = path.join(folder, 'local_presets.json');
  fs.mkdirSync(folder, { recursive: true });
  const before = fs.existsSync(target) ? fs.readFileSync(target) : null;
  let data;
  try { data = before ? JSON.parse(before.toString('utf8').replace(/^\uFEFF/, '')) : { groups: [], presets: [] }; }
  catch (_) { throw new Error('Danh sách preset hiện có không đọc được. Đã giữ nguyên tệp; chưa lưu preset mới.'); }
  if (!data || typeof data !== 'object' || !Array.isArray(data.groups) || !Array.isArray(data.presets)) throw new Error('Danh sách preset hiện có khác cấu trúc; chưa ghi thay đổi.');
  const used = new Set([...data.groups, ...data.presets].map(x => String(x.id)));
  let next = Date.now(); const id = () => { while (used.has(String(next))) next++; used.add(String(next)); return next++; };
  let group = data.groups.find(g => g.name === 'Face màu của tôi' && g.status !== 1);
  if (!group) { group = { id: id(), name: 'Face màu của tôi', space_type: 2, cid: '', status: 0 }; data.groups.push(group); }
  const payload = core.toNative(vector);
  const info = { ...payload, version, options: {}, lk_face_preset: { schema: 1, created_at: new Date().toISOString(), regions: provenance.regions, clothes_mode: provenance.clothesMode, input_mode: provenance.mode, baseline_loss: provenance.baselineLoss, result_loss: provenance.loss } };
  const preset = { id: id(), group_id: group.id, name: title, info: JSON.stringify(info), space_type: group.space_type ?? 2, cid: group.cid || '', status: 0, version, group_ctime: Date.now() };
  data.presets.push(preset);
  const temporary = target + '.lk-face-' + crypto.randomBytes(6).toString('hex') + '.tmp';
  if (before) {
    const backupDir = path.join(root, 'lk-face-preset', 'preset-backups'); fs.mkdirSync(backupDir, { recursive: true });
    fs.writeFileSync(path.join(backupDir, Date.now() + '-' + crypto.randomBytes(4).toString('hex') + '.json'), before, { flag: 'wx' });
  }
  try {
    fs.writeFileSync(temporary, JSON.stringify(data, null, 2), { encoding: 'utf8', flag: 'wx' });
    // All steps above are synchronous in Electron's main process, so existing
    // preset handlers cannot interleave a write between the read and rename.
    fs.renameSync(temporary, target);
  } catch (e) { try { fs.unlinkSync(temporary); } catch (_) {} throw e; }
  return { id: preset.id, groupId: group.id, groupName: group.name, name: title };
}

function install(electron, deps) {
  if (global.__LK_FACE_PRESET_INSTALLED_V1__) return;
  global.__LK_FACE_PRESET_INSTALLED_V1__ = true;
  const engine = createNativeEngine(deps, electron);
  global.__LK_FACE_PRESET_NATIVE_V1__ = engine;
  const { ipcMain, BrowserWindow, dialog, app } = electron;
  const expected = path.resolve(__dirname, '../../renderer/main/index.html');
  const normalize = p => process.platform === 'win32' ? p.toLowerCase() : p;
  let session = null, busy = false;
  const history = [];
  function record(action, details = {}) { history.push({ at: new Date().toISOString(), action, ...details }); if (history.length > 80) history.shift(); }
  function verify(event) {
    if (event.senderFrame !== event.sender.mainFrame) throw new Error('Yêu cầu không hợp lệ.');
    const url = new URL(event.senderFrame.url); url.search = ''; url.hash = '';
    if (url.protocol !== 'file:' || normalize(path.resolve(fileURLToPath(url))) !== normalize(expected)) throw new Error('Chỉ dùng chức năng này trong cửa sổ chính của LK magic.');
    return event.sender;
  }
  function grant(sender, token) {
    if (!session || session.owner !== sender.id || session.token !== token) throw new Error('Phiên ảnh đã thay đổi. Hãy chọn lại ảnh cần chỉnh.');
    return session;
  }
  function handle(name, fn, serial = false) {
    ipcMain.handle(PREFIX + name, async (event, args) => {
      let locked = false;
      try {
        const sender = verify(event);
        if (serial) { if (busy) throw new Error('Đang xử lý thao tác trước. Hãy chờ.'); busy = true; locked = true; }
        return await fn(sender, args || {});
      } catch (e) { record('error', { operation: name, error: String(e.message || e).slice(0, 400) }); return { ok: false, error: e.message || String(e) }; }
      finally { if (locked) busy = false; }
    });
  }
  async function select(sender, title, extensions) {
    const result = await dialog.showOpenDialog(BrowserWindow.fromWebContents(sender), { title, properties: ['openFile'], filters: [{ name: 'Ảnh', extensions }] });
    if (result.canceled || !result.filePaths?.length) return null;
    const source = fs.realpathSync(result.filePaths[0]), stat = fs.statSync(source);
    if (!stat.isFile() || !stat.size || stat.size > 800 * 1024 * 1024 || !extensions.includes(path.extname(source).slice(1).toLowerCase())) throw new Error('Tệp ảnh không đúng định dạng hoặc quá lớn.');
    return source;
  }
  handle('status', () => ({ ok: true, version: core.version, engine: engine.state(), session: session ? engine.info() : null }));
  handle('reference', async sender => {
    const source = await select(sender, 'Chọn JPG / PNG làm chuẩn màu chủ thể', ['jpg','jpeg','png']);
    if (!source) return { ok: false, canceled: true };
    const bytes = fs.readFileSync(source);
    if (bytes.length > 120 * 1024 * 1024) throw new Error('Ảnh mẫu cần nhỏ hơn 120 MB.');
    const png = bytes.subarray(0, 8).equals(Buffer.from([137,80,78,71,13,10,26,10]));
    if (!png && !(bytes[0] === 255 && bytes[1] === 216)) throw new Error('Nội dung ảnh mẫu không phải JPG / PNG.');
    return { ok: true, name: path.basename(source), image: 'data:image/' + (png ? 'png' : 'jpeg') + ';base64,' + bytes.toString('base64') };
  }, true);
  handle('source', async sender => {
    const source = await select(sender, 'Chọn một RAW hoặc ảnh cần tạo preset', EXTENSIONS);
    if (!source) return { ok: false, canceled: true };
    if (session) { if (session.owner !== sender.id) throw new Error('Cửa sổ khác đang dùng Face màu.'); engine.close(); session = null; }
    const info = await engine.create(source);
    session = { owner: sender.id, token: crypto.randomBytes(24).toString('hex'), verified: new Map(), info, image: null };
    const image = await engine.render(core.toNative({}));
    session.verified.set('{}', true); session.image = image;
    record('source', { mode: info.mode, workspace: info.workspaceId, fileid: info.id });
    return { ok: true, token: session.token, name: path.basename(source), image, mode: info.mode, projectName: info.projectName };
  }, true);
  handle('render', async (sender, args) => {
    const s = grant(sender, args.token), vector = core.cleanVector(args.vector), payload = core.toNative(vector);
    const image = await engine.render(payload);
    s.verified.set(JSON.stringify(vector), true);
    if (s.verified.size > 550) s.verified.delete(s.verified.keys().next().value);
    return { ok: true, image };
  }, true);
  handle('close', async (sender, args) => { grant(sender, args.token); engine.close(); session = null; return { ok: true }; }, true);
  handle('save', async (sender, args) => {
    const s = grant(sender, args.token), vector = core.cleanVector(args.vector);
    if (!Object.keys(vector).length) throw new Error('Không có thông số chỉnh mới để lưu thành preset.');
    if (!s.verified.has(JSON.stringify(vector))) throw new Error('Bộ thông số này chưa được bộ xử lý dựng thử. Hãy tạo lại preset.');
    const meta = args.measurements;
    if (!meta || !Array.isArray(meta.regions) || !meta.regions.includes('skin') || meta.regions.some(k => !core.regions[k]) || !['keep','sample'].includes(meta.clothesMode) || !Number.isFinite(meta.loss) || !Number.isFinite(meta.baselineLoss)) throw new Error('Thiếu kết quả đo của preset.');
    const saved = saveLocalPreset(app.getPath('userData'), app.getVersion(), args.name, vector, { ...meta, mode: s.info.mode });
    record('saved', { id: saved.id, regionCount: meta.regions.length, loss: meta.loss });
    return { ok: true, ...saved };
  }, true);
  handle('diagnostics', async sender => {
    const result = await dialog.showSaveDialog(BrowserWindow.fromWebContents(sender), { title: 'Lưu chẩn đoán Face màu', defaultPath: 'LK-FaceMau-chan-doan.json', filters: [{ name: 'JSON', extensions: ['json'] }] });
    if (result.canceled || !result.filePath) return { ok: false, canceled: true };
    const report = { version: core.version, appVersion: app.getVersion(), platform: process.platform, engine: engine.state(), native: engine.diagnostics(), sourceMode: engine.info()?.mode, history, containsPhotos: false };
    fs.writeFileSync(result.filePath, JSON.stringify(report, null, 2), 'utf8');
    return { ok: true };
  });
  return { engine };
}
module.exports = { install, saveLocalPreset };
