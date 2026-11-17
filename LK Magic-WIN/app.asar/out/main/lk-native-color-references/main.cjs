'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { fileURLToPath } = require('node:url');
const CHANNEL = 'lk-native-color-references:v1:';
const MAX_REFERENCES = 300;
const VALID_ID = /^[a-f0-9]{64}$/;

function cleanName(value) {
  return String(value || 'Ảnh mẫu').normalize('NFC').replace(/\.[^.]+$/, '')
    .replace(/[\x00-\x1f<>]/g, '').trim().slice(0, 100) || 'Ảnh mẫu';
}

function createStore(electron, resourcesPath) {
  const { nativeImage } = electron;
  const metadataDir = path.join(resourcesPath, 'lk-native-color-references');
  const catalogFile = path.join(metadataDir, 'catalog.json');
  const imageDir = path.join(resourcesPath, 'render/color_ai_example/images/lk_magic_personal_v1');
  const iconDir = path.join(resourcesPath, 'render/color_ai_example/icon/lk_magic_personal_v1');
  function readCatalog() {
    if (!fs.existsSync(catalogFile)) return { schema: 'lk-native-color-references-v1', items: [] };
    if (fs.statSync(catalogFile).size > 2 * 1024 * 1024) throw new Error('Danh sách ảnh mẫu quá lớn. Chưa thay đổi dữ liệu.');
    const data = JSON.parse(fs.readFileSync(catalogFile, 'utf8'));
    if (data.schema !== 'lk-native-color-references-v1' || !Array.isArray(data.items)) throw new Error('Danh sách ảnh mẫu không hợp lệ. Chưa thay đổi dữ liệu.');
    if (data.items.some(item => !VALID_ID.test(item.id) || typeof item.name !== 'string')) throw new Error('Dữ liệu ảnh mẫu không hợp lệ.');
    return data;
  }
  function record(item) {
    const scene = `color_ai_example/images/lk_magic_personal_v1/${item.id}.jpg`;
    const material_icon = `color_ai_example/icon/lk_magic_personal_v1/${item.id}.jpg`;
    return {
      id: item.id,
      name: 'Cá nhân · ' + item.name,
      traditional_name: 'Cá nhân · ' + item.name,
      ename: 'lk_magic_personal',
      scene,
      material_icon,
      file_path: path.join(resourcesPath, 'render', scene),
      material_icon_path: path.join(resourcesPath, 'render', material_icon),
      icon: path.join(resourcesPath, 'render', material_icon),
      lk_personal: true,
      created_at: item.created_at,
      hidden: !!item.hidden
    };
  }
  function list(includeHidden = false) {
    return readCatalog().items.filter(item => includeHidden || !item.hidden).map(record)
      .filter(item => fs.existsSync(item.file_path) && fs.existsSync(item.material_icon_path));
  }
  function saveCatalog(data) {
    fs.mkdirSync(metadataDir, { recursive: true });
    const temporary = path.join(metadataDir, '.catalog-' + crypto.randomBytes(8).toString('hex') + '.tmp');
    try {
      fs.writeFileSync(temporary, JSON.stringify(data, null, 2), { encoding: 'utf8', flag: 'wx' });
      fs.renameSync(temporary, catalogFile);
    } finally { if (fs.existsSync(temporary)) fs.unlinkSync(temporary); }
  }
  function createFile(target, bytes) {
    if (fs.existsSync(target)) {
      if (!fs.readFileSync(target).equals(bytes)) throw new Error('Bản sao ảnh mẫu đã bị thay đổi. Hãy giữ lại thư mục để kiểm tra trước khi thêm lại.');
      return;
    }
    try { fs.writeFileSync(target, bytes, { flag: 'wx' }); }
    catch (error) {
      if (error.code === 'EEXIST' && fs.readFileSync(target).equals(bytes)) return;
      if (error.code !== 'EEXIST') { try { fs.unlinkSync(target); } catch (_) {} }
      throw error;
    }
  }
  async function importFiles(filePaths) {
    const catalog = readCatalog();
    const imported = [], errors = [];
    for (const file of filePaths.slice(0, 50)) {
      try {
        if (!/\.(jpe?g|png)$/i.test(file)) throw new Error('Chỉ nhận JPG hoặc PNG.');
        const stat = fs.statSync(file);
        if (!stat.isFile() || !stat.size || stat.size > 120 * 1024 * 1024) throw new Error('Ảnh không hợp lệ hoặc lớn hơn 120 MB.');
        const bytes = fs.readFileSync(file);
        const isJpeg = bytes[0] === 0xff && bytes[1] === 0xd8;
        const isPng = bytes.length >= 8 && bytes.subarray(0, 8).equals(Buffer.from([137,80,78,71,13,10,26,10]));
        if (!isJpeg && !isPng) throw new Error('Nội dung tệp không phải JPG/PNG.');
        const image = nativeImage.createFromBuffer(bytes);
        if (image.isEmpty()) throw new Error('Không đọc được ảnh.');
        const size = image.getSize();
        if (!size.width || !size.height || size.width * size.height > 64000000) throw new Error('Ảnh vượt giới hạn 64 megapixel.');
        const id = crypto.createHash('sha256').update(bytes).digest('hex');
        let item = catalog.items.find(value => value.id === id);
        if (!item && catalog.items.length >= MAX_REFERENCES) throw new Error('Đã đạt giới hạn 300 ảnh mẫu.');
        fs.mkdirSync(imageDir, { recursive: true }); fs.mkdirSync(iconDir, { recursive: true });
        const fullJpeg = isJpeg ? bytes : image.toJPEG(96);
        if (!fullJpeg || fullJpeg[0] !== 0xff || fullJpeg[1] !== 0xd8) throw new Error('Không chuyển được ảnh mẫu sang JPG.');
        const scale = Math.min(1, 420 / Math.max(size.width, size.height));
        const thumbnail = image.resize({ width: Math.max(1, Math.round(size.width * scale)), height: Math.max(1, Math.round(size.height * scale)), quality: 'good' }).toJPEG(88);
        if (!thumbnail || thumbnail[0] !== 0xff || thumbnail[1] !== 0xd8) throw new Error('Không tạo được ảnh xem trước.');
        createFile(path.join(imageDir, id + '.jpg'), fullJpeg);
        createFile(path.join(iconDir, id + '.jpg'), thumbnail);
        if (!item) {
          item = { id, name: cleanName(path.basename(file)), created_at: new Date().toISOString(), hidden: false };
          catalog.items.unshift(item);
        } else item.hidden = false;
        imported.push(record(item));
      } catch (error) { errors.push({ name: path.basename(file), error: error.message || 'Không thêm được ảnh.' }); }
    }
    if (imported.length) saveCatalog(catalog);
    if (filePaths.length > 50) errors.push({ name: '', error: 'Mỗi lần chỉ thêm tối đa 50 ảnh mẫu.' });
    return { ok: true, items: list(), imported: imported.length, importedItems: imported, errors };
  }
  function setHidden(id, hidden) {
    if (!VALID_ID.test(String(id))) throw new Error('Mã ảnh mẫu không hợp lệ.');
    const catalog = readCatalog(), item = catalog.items.find(value => value.id === id);
    if (!item) throw new Error('Không tìm thấy ảnh mẫu.');
    item.hidden = !!hidden; saveCatalog(catalog);
    // Keep reference files: previously edited/saved projects still need them.
    return { ok: true, items: list() };
  }
  function removeReference(id) {
    if (!VALID_ID.test(String(id))) throw new Error('Mã ảnh mẫu không hợp lệ.');
    const catalog = readCatalog();
    const index = catalog.items.findIndex(item => item.id === id);
    if (index < 0) throw new Error('Không tìm thấy ảnh mẫu cá nhân.');
    catalog.items.splice(index, 1);
    saveCatalog(catalog);
    // A saved edit contains the scene path. Retain its cached image so exports
    // and previously edited projects still work after deleting the catalog entry.
    return { ok: true, items: list(), removed: id };
  }
  return { list, importFiles, setHidden, removeReference, catalogFile, record };
}

function install(electron, options = {}) {
  if (global.__LK_NATIVE_COLOR_REFERENCES_V1__) return;
  const resourcesPath = options.resourcesPath || path.resolve(__dirname, '../../../..');
  const expected = path.resolve(__dirname, '../../renderer/main/index.html');
  const normalize = value => process.platform === 'win32' ? value.toLowerCase() : value;
  const store = createStore(electron, resourcesPath);
  let queue = Promise.resolve();
  function serial(action) { const result = queue.then(action); queue = result.catch(() => {}); return result; }
  function verify(event) {
    if (event.senderFrame !== event.sender.mainFrame) throw new Error('Yêu cầu không hợp lệ.');
    const url = new URL(event.senderFrame.url); url.hash = ''; url.search = '';
    if (url.protocol !== 'file:' || normalize(path.resolve(fileURLToPath(url))) !== normalize(expected)) throw new Error('Chỉ thêm ảnh mẫu trong cửa sổ chính của LK magic.');
  }
  function handle(name, action) {
    electron.ipcMain.handle(CHANNEL + name, async (event, args) => {
      try { verify(event); return await action(event, args || {}); }
      catch (error) { return { ok: false, error: error.message || 'Không thực hiện được thao tác.' }; }
    });
  }
  handle('list', async () => ({ ok: true, items: store.list() }));
  handle('import', (event, args) => serial(async () => {
    const single = args.single === true;
    const selected = await electron.dialog.showOpenDialog(electron.BrowserWindow.fromWebContents(event.sender), {
      title: single ? 'Chọn ảnh mẫu để Face màu' : 'Thêm ảnh mẫu màu của tôi',
      properties: single ? ['openFile'] : ['openFile', 'multiSelections'],
      filters: [{ name: 'Ảnh JPG / PNG', extensions: ['jpg', 'jpeg', 'png'] }]
    });
    if (selected.canceled || !selected.filePaths.length) return { ok: false, canceled: true };
    return store.importFiles(single ? selected.filePaths.slice(0, 1) : selected.filePaths);
  }));
  handle('hide', (_event, args) => serial(async () => store.setHidden(args.id, true)));
  handle('remove', (_event, args) => serial(async () => store.removeReference(args.id)));
  global.__LK_NATIVE_COLOR_REFERENCES_V1__ = true;
}
module.exports = { install, createStore, cleanName };
