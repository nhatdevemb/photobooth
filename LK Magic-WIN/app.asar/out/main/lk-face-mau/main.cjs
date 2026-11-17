'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { fileURLToPath } = require('node:url');
const PREFIX = 'lk-face-mau:v1:';

function safeName(name) {
  const cleaned = String(name || 'Anh').normalize('NFC').replace(/\.[^.]+$/, '')
    .replace(/[<>:"/\\|?*\x00-\x1f]/g, '_').replace(/[. ]+$/g, '').trim().slice(0, 110) || 'Anh';
  return /^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i.test(cleaned) ? '_' + cleaned : cleaned;
}
function install(electron, options = {}) {
  if (global.__LK_FACE_MAU_V1_INSTALLED__) return;
  global.__LK_FACE_MAU_V1_INSTALLED__ = true;
  const { ipcMain, BrowserWindow, dialog, shell } = electron;
  const grants = new Map();
  const expected = path.resolve(__dirname, '../../renderer/main/index.html');
  const normalize = p => process.platform === 'win32' ? p.toLowerCase() : p;
  function verify(event) {
    if (event.senderFrame !== event.sender.mainFrame) throw new Error('Yêu cầu không hợp lệ.');
    const url = new URL(event.senderFrame.url);
    url.search = ''; url.hash = '';
    if (url.protocol !== 'file:' || normalize(path.resolve(fileURLToPath(url))) !== normalize(expected)) {
      throw new Error('Chỉ sử dụng Face màu trong cửa sổ chính của LK magic.');
    }
    return event.sender;
  }
  function register(name, handler) {
    ipcMain.handle(PREFIX + name, async (event, args) => {
      try { const sender = verify(event); return await handler(sender, args || {}); }
      catch (error) { return { ok: false, error: error && error.message || 'Không thực hiện được thao tác.' }; }
    });
  }
  register('choose-folder', async sender => {
    const parent = BrowserWindow.fromWebContents(sender);
    const selection = await dialog.showOpenDialog(parent, {
      title: 'Chọn thư mục lưu ảnh Face màu', properties: ['openDirectory', 'createDirectory']
    });
    if (selection.canceled || !selection.filePaths.length) return { ok: false, canceled: true };
    const folder = fs.realpathSync(selection.filePaths[0]);
    if (!fs.statSync(folder).isDirectory()) throw new Error('Thư mục không hợp lệ.');
    const token = crypto.randomBytes(24).toString('hex');
    grants.set(sender.id, { folder, token });
    if (!sender.__lkFaceCleanup) {
      sender.__lkFaceCleanup = true;
      sender.once('destroyed', () => grants.delete(sender.id));
    }
    return { ok: true, token, folder };
  });
  register('write-jpeg', async (sender, args) => {
    const grant = grants.get(sender.id);
    if (!grant || args.token !== grant.token) throw new Error('Hãy chọn lại thư mục xuất ảnh.');
    if (options.authorizeExport && !(await options.authorizeExport())) return { ok: false, license: true, error: 'Cần kích hoạt LICENSE KEY để xuất ảnh.' };
    if (!(args.bytes instanceof Uint8Array) || args.bytes.length < 4 || args.bytes.length > 200 * 1024 * 1024) {
      throw new Error('Dữ liệu JPG không hợp lệ hoặc quá lớn.');
    }
    const bytes = Buffer.from(args.bytes);
    if (bytes[0] !== 0xff || bytes[1] !== 0xd8 || bytes[bytes.length - 2] !== 0xff || bytes[bytes.length - 1] !== 0xd9) throw new Error('Ảnh chưa được mã hóa thành JPG.');
    const base = safeName(args.name) + '_FaceMau';
    for (let i = 0; i < 10000; i++) {
      const target = path.join(grant.folder, base + (i ? '_' + i : '') + '.jpg');
      let handle;
      try {
        handle = await fs.promises.open(target, 'wx');
        try { await handle.writeFile(bytes); }
        catch (error) { await handle.close(); handle = null; await fs.promises.unlink(target).catch(() => {}); throw error; }
        await handle.close(); handle = null;
        return { ok: true, path: target, name: path.basename(target) };
      } catch (error) {
        if (handle) await handle.close().catch(() => {});
        if (error.code === 'EEXIST') continue;
        throw error;
      }
    }
    throw new Error('Thư mục đã có quá nhiều ảnh trùng tên.');
  });
  register('open-folder', async (sender, args) => {
    const grant = grants.get(sender.id);
    if (!grant || args.token !== grant.token) throw new Error('Hãy chọn thư mục xuất ảnh trước.');
    const error = await shell.openPath(grant.folder);
    return error ? { ok: false, error } : { ok: true };
  });
}
module.exports = { install, safeName };
