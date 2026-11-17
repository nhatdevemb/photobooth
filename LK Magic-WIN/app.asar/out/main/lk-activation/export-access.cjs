'use strict';
const path = require('node:path');
const {fileURLToPath} = require('node:url');
const core = require('./license-core.cjs');
const activation = require('./activation-main.cjs');

function install(electron, issuerConfig, options) {
  const issuer = core.validateIssuer(issuerConfig);
  const licenseFile = path.join(electron.app.getPath('appData'), 'LKmagic', 'Activation', issuer.kid, 'license.lkkey');
  const rendererFile = path.resolve(__dirname, '../../renderer/main/index.html');
  let machinePromise = null, activationPending = null;

  function machine() {
    if (!machinePromise) {
      machinePromise = core.readMachineCode().catch(error => { machinePromise = null; throw error; });
    }
    return machinePromise;
  }
  async function state() {
    try {
      const machineCode = await machine();
      const checked = core.verifyLicense(core.readSavedLicense(licenseFile), machineCode, issuerConfig);
      return {ready: true, activated: checked.ok, message: checked.ok ? 'Đã kích hoạt vĩnh viễn' : 'Kích hoạt KEY để xuất ảnh. Bạn vẫn có thể chỉnh ảnh bình thường.'};
    } catch {
      return {ready: true, activated: false, message: 'Chưa đọc được thông tin kích hoạt. Bạn vẫn có thể chỉnh ảnh và thử kích hoạt lại.'};
    }
  }
  function mainWindow() {
    try { const win = options.getMainWindow(); return win && !win.isDestroyed() ? win : null; }
    catch { return null; }
  }
  function trusted(event) {
    const win = mainWindow();
    if (!win || event.sender !== win.webContents || event.senderFrame !== win.webContents.mainFrame) return false;
    try {
      const url = new URL(event.senderFrame.url);
      const candidate = path.resolve(fileURLToPath(url));
      return url.protocol === 'file:' && (path.sep === '\\' ? candidate.toLowerCase() === rendererFile.toLowerCase() : candidate === rendererFile);
    } catch { return false; }
  }
  function notify(value) {
    const win = mainWindow();
    try { if (win && !win.webContents.isDestroyed()) win.webContents.send('lk-export:changed', value); } catch {}
  }
  async function refresh() { const value = await state(); notify(value); return value; }
  function openActivation() {
    if (activationPending) return activationPending;
    activationPending = (async () => {
      const before = await state();
      if (before.activated) { notify(before); return before; }
      const parent = mainWindow();
      if (!parent) return before;
      try { await activation.ensureActivated(electron, issuerConfig, {parent}); }
      catch { /* Closing or failing the activation dialog must never close the editor. */ }
      machinePromise = null;
      return refresh();
    })().finally(() => { activationPending = null; });
    return activationPending;
  }
  async function requireExport() {
    const current = await refresh();
    if (current.activated) return true;
    // This attempt remains cancelled even if activation succeeds. The user starts export explicitly afterwards.
    await openActivation();
    return false;
  }
  electron.ipcMain.handle('lk-export:state', async event => trusted(event) ? refresh() : {ready: true, activated: false, message: 'Yêu cầu không hợp lệ.'});
  electron.ipcMain.handle('lk-export:activate', async event => trusted(event) ? openActivation() : {ready: true, activated: false, message: 'Yêu cầu không hợp lệ.'});
  // No startup dialog and no awaited activation gate: editing starts immediately.
  return Object.freeze({state, requireExport});
}
module.exports = {install};
