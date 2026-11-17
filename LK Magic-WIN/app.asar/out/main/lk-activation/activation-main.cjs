'use strict';
const fs = require('node:fs');
const path = require('node:path');
const {pathToFileURL} = require('node:url');
const core = require('./license-core.cjs');

async function ensureActivated(electron, issuerConfig, options = {}) {
  const issuer = core.validateIssuer(issuerConfig);
  const licenseFile = path.join(electron.app.getPath('appData'), 'LKmagic', 'Activation', issuer.kid, 'license.lkkey');
  let machineCode = '', machineError = '';
  try { machineCode = await core.readMachineCode(); } catch (error) { machineError = error.message; }
  if (machineCode && core.verifyLicense(core.readSavedLicense(licenseFile), machineCode, issuerConfig).ok) return true;

  return new Promise((resolve, reject) => {
    let settled = false, busy = false;
    const html = path.join(__dirname, 'activation.html');
    const allowedURL = pathToFileURL(html).href;
    const win = new electron.BrowserWindow({
      width: 600, height: 590, resizable: false, maximizable: false,
      ...(options.parent && !options.parent.isDestroyed() ? {parent: options.parent, modal: true} : {}),
      title: 'Kích hoạt KEY để xuất ảnh', backgroundColor: '#14171d', show: false, autoHideMenuBar: true,
      webPreferences: {preload: path.join(__dirname, 'activation-preload.cjs'), nodeIntegration: false,
        contextIsolation: true, sandbox: true, webSecurity: true, devTools: false,
        partition: 'lk-magic-activation'},
    });
    win.setMenu(null);
    const channels = [];
    function onSecondInstance() { if (!win.isDestroyed()) { if (win.isMinimized()) win.restore(); win.show(); win.focus(); } }
    function finish(ok) {
      if (settled) return;
      settled = true;
      for (const channel of channels) electron.ipcMain.removeHandler(channel);
      electron.app.removeListener('second-instance', onSecondInstance);
      resolve(ok);
      if (!win.isDestroyed()) win.destroy();
    }
    function trusted(event) {
      return !settled && !win.isDestroyed() && event.sender === win.webContents &&
        event.senderFrame === win.webContents.mainFrame && event.senderFrame.url === allowedURL;
    }
    function handle(name, fn) {
      const channel = 'lk-activation:' + name;
      channels.push(channel);
      electron.ipcMain.handle(channel, async (event, ...args) => {
        if (!trusted(event)) return {ok: false, message: 'Yêu cầu không hợp lệ.'};
        try { return await fn(...args); }
        catch { return {ok: false, message: 'Chưa thực hiện được thao tác. Vui lòng thử lại.'}; }
      });
    }
    handle('state', () => ({ok: !!machineCode, machineCode, message: machineError}));
    handle('retry', async () => {
      if (busy) return {ok: false, message: 'Đang đọc mã máy.'};
      busy = true;
      try { machineCode = await core.readMachineCode(); machineError = ''; return {ok: true, machineCode}; }
      catch (error) { machineCode = ''; machineError = error.message; return {ok: false, machineCode: '', message: machineError}; }
      finally { busy = false; }
    });
    handle('copy', () => {
      if (!machineCode) return {ok: false, message: 'Chưa có mã máy.'};
      electron.clipboard.writeText(machineCode); return {ok: true};
    });
    handle('choose', async () => {
      const selected = await electron.dialog.showOpenDialog(win, {title: 'Chọn file key LK magic',
        properties: ['openFile'], filters: [{name: 'Key LK magic', extensions: ['lkkey','txt']}]});
      if (selected.canceled || !selected.filePaths.length) return {ok: false, cancelled: true};
      const file = selected.filePaths[0];
      if (!fs.statSync(file).isFile() || fs.statSync(file).size > core.MAX_KEY_LENGTH + 8) return {ok: false, message: 'File key không hợp lệ hoặc quá lớn.'};
      return {ok: true, token: fs.readFileSync(file, 'utf8').replace(/^\uFEFF/, '').trim()};
    });
    handle('activate', async (token) => {
      if (busy) return {ok: false, message: 'Đang xử lý. Vui lòng chờ.'};
      if (!machineCode) return {ok: false, message: 'Chưa có mã máy. Bấm Thử lại trước.'};
      // The renderer supplies only a token; it cannot supply a substitute device ID.
      const checked = core.verifyLicense(token, machineCode, issuerConfig);
      if (!checked.ok) return checked;
      busy = true;
      try { core.saveLicense(licenseFile, checked.token); }
      catch { busy = false; return {ok: false, message: 'Không lưu được kích hoạt vào thư mục người dùng. Vui lòng kiểm tra quyền ghi.'}; }
      // Report success only after the signed license has been persisted.
      setImmediate(() => finish(true));
      return {ok: true};
    });
    handle('support', async () => { await electron.shell.openExternal('https://zalo.me/0985062345'); return {ok: true}; });
    handle('quit', () => { setImmediate(() => finish(false)); return {ok: true}; });
    win.webContents.setWindowOpenHandler(() => ({action: 'deny'}));
    win.webContents.on('will-navigate', event => event.preventDefault());
    win.webContents.on('will-attach-webview', event => event.preventDefault());
    win.webContents.session.setPermissionRequestHandler((_wc, _permission, callback) => callback(false));
    win.once('ready-to-show', () => { if (!settled) win.show(); });
    win.once('closed', () => finish(false));
    win.webContents.on('render-process-gone', () => finish(false));
    electron.app.on('second-instance', onSecondInstance);
    win.loadFile(html).catch(error => { finish(false); });
  });
}
module.exports = {ensureActivated};
