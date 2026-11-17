/* LK magic Face màu 0.1.0 - an independent local colour-matching workspace. */
(function () {
  'use strict';
  if (window.__LK_FACE_MAU_V1__) return;
  window.__LK_FACE_MAU_V1__ = true;
  const core = window.LKFaceColorCore;
  if (!core) { console.error('[LK Face màu] Thiếu color-core.js'); return; }
  const scriptURL = document.currentScript.src;
  const icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 3C9 7 5 10 5 14a7 7 0 0 0 14 0c0-4-4-7-7-11Z"/><path d="M8 14a4 4 0 0 0 4 4"/><path d="m9 7 6 6"/></svg>';
  const imageIcon = '<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="4" y="5" width="24" height="22" rx="4"/><circle cx="12" cy="12" r="2.5"/><path d="m5 24 7-7 5 4 5-8 6 11"/></svg>';
  const host = document.createElement('div');
  host.id = 'lk-face-mau-panel-v1';
  Object.assign(host.style, { position: 'fixed', zIndex: '1900', display: 'none', right: '0', bottom: '0' });
  const root = host.attachShadow({ mode: 'open' });
  root.innerHTML = `
    <section class="screen" aria-label="Face màu theo ảnh mẫu">
      <header class="heading"><div class="title">${icon}<div><h1>Face màu</h1><p>Lấy phong cách màu từ ảnh mẫu</p></div></div><div class="heading-right"><span class="pill">● Xử lý trên máy</span><button data-action="close" class="small subtle">← Quay lại dự án</button></div></header>
      <div class="toolbar"><button data-action="reference" class="primary">+ Chọn ảnh mẫu</button><button data-action="targets">+ Thêm ảnh cần chỉnh</button><button data-action="clear" class="small subtle">Xóa danh sách</button><span class="summary">JPG / PNG · Ảnh gốc được giữ riêng</span></div>
      <div class="editor"><div class="main-column"><div class="canvas-grid">
        <article class="card image-card"><div class="card-header"><strong>Ảnh mẫu</strong><span class="input-hint">Màu tham chiếu</span></div><div class="file-label" id="reference-name">Chưa chọn ảnh mẫu</div><div class="image-well" id="reference-well"><div class="empty" id="reference-empty">${imageIcon}<strong>Chọn ảnh có màu bạn thích</strong><p>Có thể kéo thả ảnh mẫu vào đây.</p></div><div class="image-content sample-area" id="reference-area"><canvas id="reference-canvas"></canvas><div class="sample-box" id="reference-box"></div></div></div><div class="image-footer"><button data-action="sample-reference" class="small">Khoanh vùng màu</button><button data-action="reset-reference-region" class="small subtle">Toàn ảnh</button><span class="region-label" id="reference-region">Lấy màu trên toàn ảnh</span></div></article>
        <article class="card image-card"><div class="card-header"><strong>Ảnh cần chỉnh</strong><span class="input-hint" id="dimensions">Xem trước / sau</span></div><div class="file-label" id="target-name">Chưa có ảnh cần chỉnh</div><div class="image-well" id="target-well"><div class="empty" id="target-empty">${imageIcon}<strong>Thêm ảnh của bạn</strong><p>Ảnh mẫu chỉ được dùng để tham chiếu màu sắc.</p></div><div class="image-content sample-area" id="target-area"><canvas id="original-canvas"></canvas><canvas class="result-canvas" id="result-canvas"></canvas><div class="divider" id="compare-divider"></div><div class="sample-box" id="target-box"></div></div></div><div class="image-footer"><button data-action="sample-target" class="small">Khoanh vùng màu</button><button data-action="reset-target-region" class="small subtle">Toàn ảnh</button><span class="region-label" id="target-region">Phân tích trên toàn ảnh</span></div></article>
      </div><div class="compare-bar"><span>Ảnh gốc</span><input id="compare" type="range" min="0" max="100" value="50" aria-label="So sánh ảnh gốc và kết quả"><span>Kết quả</span><button data-action="before" class="small subtle">Gốc</button><button data-action="after" class="small subtle">Sau</button></div><div class="strip" id="photo-list"><div class="strip-empty">Danh sách ảnh cần chỉnh sẽ hiện ở đây.</div></div></div>
      <aside class="card settings"><h2>Điều chỉnh màu</h2>
        <div class="control"><label class="control-label" for="amount">Mức áp dụng <output id="amount-value">70%</output></label><input class="slider" id="amount" type="range" min="0" max="100" value="70"><p>Giảm để giữ nhiều màu ảnh gốc hơn.</p></div>
        <div class="control"><label class="control-label" for="color">Sắc màu <output id="color-value">80%</output></label><input class="slider" id="color" type="range" min="0" max="100" value="80"></div>
        <div class="control"><label class="control-label" for="tone">Độ sáng <output id="tone-value">45%</output></label><input class="slider" id="tone" type="range" min="0" max="100" value="45"></div>
        <div class="control"><label class="control-label" for="contrast">Tương phản màu <output id="contrast-value">45%</output></label><input class="slider" id="contrast" type="range" min="0" max="100" value="45"></div>
        <label class="check-label"><input id="skin" type="checkbox" checked><span>Giảm đổi màu da</span></label><div class="divider-line"></div>
        <p class="note">Để bắt màu sát hơn, khoanh vùng tương ứng trên hai ảnh: da với da, hoặc nền với nền.</p><p class="note">Ánh sáng và bối cảnh khác nhau sẽ cho kết quả màu khác nhau.</p>
        <div class="settings-bottom"><button data-action="reset" class="small subtle">Đặt lại thông số</button><button data-action="export-one" class="primary">Xuất ảnh đang chọn</button><button data-action="export-all">Xuất tất cả ảnh</button><button data-action="folder" class="small subtle">Chọn thư mục xuất</button><button data-action="open-folder" class="small subtle" hidden>Mở thư mục xuất</button></div>
      </aside></div>
      <footer class="statusbar"><span class="status" id="status" role="status" aria-live="polite">Chọn ảnh mẫu và ảnh cần chỉnh để bắt đầu.</span><div class="progress" id="progress"><div id="progress-fill"></div></div><button data-action="cancel" class="small" hidden>Dừng</button><span class="count" id="count">0 ảnh</span></footer>
      <input id="reference-input" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png"><input id="targets-input" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" multiple>
    </section>`;
  const stylesheet = document.createElement('link'); stylesheet.rel = 'stylesheet'; stylesheet.href = new URL('face-mau.css', scriptURL).href; root.prepend(stylesheet);
  const $ = id => root.getElementById(id);
  const state = { reference: null, files: [], active: -1, busy: false, loading: false, cancel: false, sampling: null, output: null, sequence: 0, activeSequence: 0, previewValid: false };
  const original = $('original-canvas'), result = $('result-canvas'), referenceCanvas = $('reference-canvas');
  const nav = document.createElement('button');
  nav.id = 'lk-face-mau-tab-v1';
  nav.type = 'button'; nav.innerHTML = icon + '<span>Face màu</span>';
  nav.setAttribute('aria-controls', host.id); nav.setAttribute('aria-expanded', 'false');
  Object.assign(nav.style, { display: 'none', position: 'fixed', zIndex: '1901', alignItems: 'center', gap: '15px', padding: '0 20px', borderRadius: '11px', border: '1px solid #343a40', background: '#23272b', color: '#eff6fc', font: '14px Segoe UI, Arial, sans-serif', cursor: 'pointer', boxSizing: 'border-box', textAlign: 'left' });
  Object.assign(nav.querySelector('svg').style, { width: '22px', height: '22px', flex: 'none', color: '#26d1f3' });
  let anchor = null, previewTimer = null, anchorTimer = null, layoutQueued = false;
  function isOpen() { return host.style.display !== 'none'; }
  function message(text, type) { $('status').textContent = text; $('status').title = text; $('status').className = 'status' + (type ? ' ' + type : ''); }
  const pause = () => new Promise(resolve => setTimeout(resolve, 0));
  function progress(p) { $('progress').style.display = p == null ? 'none' : 'block'; $('progress-fill').style.width = Math.max(0, Math.min(100, p || 0)) + '%'; }
  function options() { return Object.assign(Object.fromEntries(['amount', 'color', 'tone', 'contrast'].map(k => [k, Number($(k).value)])), { skin: $('skin').checked }); }
  function updateControls() {
    const locked = state.busy || state.loading;
    root.querySelectorAll('button').forEach(button => {
      const action = button.dataset.action;
      button.disabled = locked && !['cancel', 'close'].includes(action);
      if (['export-one', 'export-all'].includes(action)) button.disabled = locked || !state.reference || !state.files.length;
      if (action && (action.startsWith('sample-') || action.startsWith('reset-') && action.endsWith('-region'))) {
        const has = action.includes('reference') ? !!state.reference : state.active >= 0;
        button.disabled = locked || !has;
      }
    });
    root.querySelectorAll('.slider,#skin').forEach(input => { input.disabled = locked; });
    root.querySelector('[data-action="cancel"]').hidden = !state.busy;
    root.querySelector('[data-action="open-folder"]').hidden = !state.output;
    $('count').textContent = state.files.length + ' ảnh';
  }
  function findAnchor() {
    if (anchor && anchor.isConnected && usable(anchor)) return anchor;
    const known = document.querySelector('[data-lk-projects],#lk-my-projects,#lk-projects-tab,#lk-magic-my-projects');
    if (known && usable(known)) { anchor = known; return anchor; }
    const candidates = document.querySelectorAll('button,a,[role="tab"],[role="button"],.el-menu-item,div,span');
    let best = null, score = -Infinity;
    for (const item of candidates) {
      if (item === nav || item === host || item.children.length > 6) continue;
      const text = (item.textContent || '').replace(/\s+/g, ' ').trim();
      if (!['Dự án của tôi', 'DỰ ÁN CỦA TÔI', '我的项目', 'Dự án của Tôi'].includes(text)) continue;
      let element = item;
      for (let level = 0; level < 5 && element && element !== document.body; level++, element = element.parentElement) {
        const r = element.getBoundingClientRect();
        if (!usable(element) || r.width < 120 || r.width > 330 || r.height < 28 || r.height > 95) continue;
        const value = r.width - Math.abs(r.height - 48) + (element.tagName === 'BUTTON' ? 30 : 0);
        if (value > score) { best = element; score = value; }
      }
    }
    anchor = best; return best;
  }
  function usable(element) {
    const r = element.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && r.left >= -2 && r.left < 160 && r.top > 25 && r.top < window.innerHeight - 100 && getComputedStyle(element).visibility !== 'hidden';
  }
  function layout() {
    layoutQueued = false;
    if (!nav.isConnected) document.body.appendChild(nav);
    if (!host.isConnected) document.body.appendChild(host);
    const item = findAnchor();
    if (!item) { nav.style.display = 'none'; if (isOpen()) close(); return; }
    const r = item.getBoundingClientRect();
    nav.style.display = 'flex'; nav.style.left = r.left + 'px'; nav.style.top = r.bottom + 10 + 'px'; nav.style.width = r.width + 'px'; nav.style.height = Math.max(44, r.height) + 'px';
    let edge = r.right + Math.max(16, r.left), top = 64;
    for (let p = item.parentElement, i = 0; p && p !== document.body && i < 8; p = p.parentElement, i++) {
      const b = p.getBoundingClientRect();
      if (b.width > r.width + 8 && b.width < 400 && b.height > innerHeight * 0.5 && b.left < 50) { edge = b.right; top = Math.max(40, b.top); break; }
    }
    host.style.left = Math.round(edge) + 'px'; host.style.top = Math.round(top) + 'px';
    if (isOpen()) fitImages();
  }
  function queueLayout() { if (!layoutQueued) { layoutQueued = true; requestAnimationFrame(layout); } }
  function open() { host.style.display = 'block'; nav.setAttribute('aria-expanded', 'true'); nav.style.background = '#123b46'; nav.style.borderColor = '#11c9ef'; layout(); updateControls(); root.querySelector('h1').setAttribute('tabindex', '-1'); root.querySelector('h1').focus(); }
  function close() { host.style.display = 'none'; nav.setAttribute('aria-expanded', 'false'); nav.style.background = '#23272b'; nav.style.borderColor = '#343a40'; state.sampling = null; for (const role of ['reference', 'target']) { $(role + '-area').classList.remove('sampling'); root.querySelector('[data-action="sample-' + role + '"]').classList.remove('active'); } }
  function fitOne(area, canvas, well) {
    if (!canvas.width || !canvas.height || area.style.display === 'none') return;
    const w = Math.max(20, well.clientWidth - 24), h = Math.max(20, well.clientHeight - 24);
    const scale = Math.min(w / canvas.width, h / canvas.height);
    area.style.width = Math.round(canvas.width * scale) + 'px'; area.style.height = Math.round(canvas.height * scale) + 'px';
  }
  function fitImages() { fitOne($('reference-area'), referenceCanvas, $('reference-well')); fitOne($('target-area'), original, $('target-well')); }
  function validFile(file) { return /\.(jpe?g|png)$/i.test(file.name) && file.size > 0 && file.size <= 180 * 1024 * 1024; }
  async function decode(file) {
    if (!validFile(file)) throw new Error('Bản này nhận JPG/PNG, tối đa 180 MB mỗi ảnh.');
    const url = URL.createObjectURL(file), image = new Image();
    try {
      image.src = url;
      if (image.decode) await image.decode(); else await new Promise((resolve, reject) => { image.onload = resolve; image.onerror = reject; });
      if (!image.naturalWidth || image.naturalWidth * image.naturalHeight > 64000000) throw new Error('Ảnh vượt giới hạn thử nghiệm 64 megapixel.');
      return { image, width: image.naturalWidth, height: image.naturalHeight, release() { image.src = ''; URL.revokeObjectURL(url); } };
    } catch (error) { image.src = ''; URL.revokeObjectURL(url); throw new Error(error.message || 'Không đọc được ảnh.'); }
  }
  function drawPreview(decoded, canvas, maxSide) {
    const scale = Math.min(1, maxSide / Math.max(decoded.width, decoded.height));
    canvas.width = Math.max(1, Math.round(decoded.width * scale)); canvas.height = Math.max(1, Math.round(decoded.height * scale));
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, canvas.width, canvas.height); ctx.drawImage(decoded.image, 0, 0, canvas.width, canvas.height);
  }
  function stats(canvas, region) {
    const c = document.createElement('canvas');
    const rect = region || { x: 0, y: 0, w: 1, h: 1 };
    const sw = Math.max(1, Math.round(canvas.width * rect.w)), sh = Math.max(1, Math.round(canvas.height * rect.h));
    const scale = Math.min(1, 350 / Math.max(sw, sh));
    c.width = Math.max(1, Math.round(sw * scale)); c.height = Math.max(1, Math.round(sh * scale));
    const ctx = c.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(canvas, Math.round(rect.x * canvas.width), Math.round(rect.y * canvas.height), sw, sh, 0, 0, c.width, c.height);
    const values = core.analyze(ctx.getImageData(0, 0, c.width, c.height).data); c.width = c.height = 1; return values;
  }
  function paintRegion(role, rect) {
    const box = $(role + '-box'); box.style.display = rect ? 'block' : 'none';
    if (rect) { box.style.left = rect.x * 100 + '%'; box.style.top = rect.y * 100 + '%'; box.style.width = rect.w * 100 + '%'; box.style.height = rect.h * 100 + '%'; }
    $(role + '-region').textContent = rect ? 'Đang phân tích vùng đã khoanh' : (role === 'reference' ? 'Lấy màu trên toàn ảnh' : 'Phân tích trên toàn ảnh');
  }
  async function loadReference(file) {
    if (!file || state.busy || state.loading) return;
    state.loading = true; updateControls(); message('Đang đọc ảnh mẫu…');
    let d;
    try {
      d = await decode(file); drawPreview(d, referenceCanvas, 900);
      state.reference = { name: file.name, stats: stats(referenceCanvas), region: null };
      $('reference-name').textContent = file.name; $('reference-name').title = file.name;
      $('reference-empty').hidden = true; $('reference-area').style.display = 'block';
      paintRegion('reference', null); fitImages(); schedulePreview(0);
      if (!state.files.length) message('Đã chọn ảnh mẫu. Thêm ảnh cần chỉnh để xem kết quả.');
    } catch (error) { message(error.message, 'error'); }
    finally { if (d) d.release(); state.loading = false; updateControls(); }
  }
  async function addTargets(files) {
    if (state.busy || state.loading) return;
    let rejected = 0;
    for (const file of files) {
      if (!validFile(file) || state.files.length >= 200) { rejected++; continue; }
      const key = file.name + ':' + file.size + ':' + file.lastModified;
      if (state.files.some(x => x.key === key)) continue;
      state.files.push({ file, key, region: null, stats: null, status: '', path: '' });
    }
    renderList();
    if (state.active < 0 && state.files.length) await activate(0);
    if (rejected) message('Đã bỏ qua ' + rejected + ' ảnh không hỗ trợ hoặc vượt giới hạn 200 ảnh.', 'error');
    updateControls();
  }
  async function activate(index) {
    if (state.busy || state.loading || !state.files[index]) return;
    state.loading = true; state.active = index; state.previewValid = false; state.sequence++; state.activeSequence++;
    const version = state.activeSequence, record = state.files[index];
    updateControls(); renderList(); message('Đang đọc ' + record.file.name + '…');
    let d;
    try {
      d = await decode(record.file);
      if (version !== state.activeSequence) return;
      record.width = d.width; record.height = d.height; drawPreview(d, original, 1100);
      result.width = original.width; result.height = original.height; result.getContext('2d').drawImage(original, 0, 0);
      record.stats = stats(original, record.region);
      $('target-name').textContent = record.file.name; $('target-name').title = record.file.name;
      $('dimensions').textContent = d.width + ' × ' + d.height;
      $('target-empty').hidden = true; $('target-area').style.display = 'block';
      paintRegion('target', record.region); fitImages(); schedulePreview(0);
      if (!state.reference) message('Đã mở ảnh. Chọn ảnh mẫu để lấy phong cách màu.');
    } catch (error) { record.status = 'failed'; record.error = error.message; message(error.message, 'error'); renderList(); $('target-area').style.display = 'none'; $('target-empty').hidden = false; }
    finally { if (d) d.release(); state.loading = false; updateControls(); }
  }
  function renderList() {
    const list = $('photo-list'); list.replaceChildren();
    if (!state.files.length) { const e = document.createElement('div'); e.className = 'strip-empty'; e.textContent = 'Danh sách ảnh cần chỉnh sẽ hiện ở đây.'; list.appendChild(e); }
    state.files.forEach((record, index) => {
      const button = document.createElement('button'); button.type = 'button'; button.className = 'photo-item' + (index === state.active ? ' active' : '') + (record.status ? ' ' + record.status : ''); button.dataset.action = 'activate'; button.dataset.index = index; button.title = record.file.name + (record.error ? '\n' + record.error : '');
      const number = document.createElement('span'); number.className = 'number'; number.textContent = 'ẢNH ' + String(index + 1).padStart(2, '0');
      const name = document.createElement('span'); name.className = 'name'; name.textContent = record.file.name;
      const status = document.createElement('span'); status.className = 'state'; status.textContent = record.status === 'done' ? '✓ Đã xuất' : record.status === 'failed' ? 'Lỗi · Có thể thử lại' : index === state.active ? 'Đang chọn' : 'Chưa xuất';
      const remove = document.createElement('span'); remove.className = 'remove'; remove.dataset.action = 'remove'; remove.dataset.index = index; remove.setAttribute('aria-label', 'Bỏ ảnh khỏi danh sách'); remove.textContent = '×';
      button.append(number, name, status, remove); list.appendChild(button);
    });
    updateControls();
  }
  function schedulePreview(delay = 110) {
    clearTimeout(previewTimer); const version = ++state.sequence; state.previewValid = false;
    if (!state.busy && state.reference && state.files[state.active] && state.files[state.active].stats) message('Đang tính màu xem trước…');
    previewTimer = setTimeout(() => preview(version).catch(error => message(error.message, 'error')), delay);
  }
  async function preview(version) {
    const record = state.files[state.active];
    if (state.busy || !state.reference || !record || !record.stats || version !== state.sequence) return;
    message('Đang tính màu xem trước…');
    const input = original.getContext('2d', { willReadFrequently: true }).getImageData(0, 0, original.width, original.height);
    const output = new ImageData(input.width, input.height); const plan = core.makePlan(record.stats, state.reference.stats, options());
    for (let p = 0; p < input.data.length; p += 131072) {
      if (version !== state.sequence || state.busy) return;
      core.transformRange(input.data, output.data, plan, p, Math.min(input.data.length, p + 131072));
      await pause();
    }
    if (version !== state.sequence || state.busy) return;
    result.getContext('2d').putImageData(output, 0, 0); state.previewValid = true;
    message('Đã cập nhật màu. Kéo thanh so sánh để xem trước / sau.', 'success');
  }
  function setCompare(value) { $('compare').value = value; result.style.clipPath = 'inset(0 0 0 ' + value + '%)'; $('compare-divider').style.left = value + '%'; }
  async function chooseFolder() {
    if (!window.LKFaceMau) throw new Error('Chưa kết nối phần lưu ảnh. Hãy cài đầy đủ gói cập nhật và mở lại LK magic.');
    const response = await window.LKFaceMau.chooseFolder();
    if (!response.ok) { if (response.canceled) return false; throw new Error(response.error || 'Không chọn được thư mục.'); }
    state.output = response; updateControls(); message('Thư mục xuất: ' + response.folder); return true;
  }
  async function exportRecord(record, onProgress) {
    let decoded, canvas;
    try {
      decoded = await decode(record.file); canvas = document.createElement('canvas'); canvas.width = decoded.width; canvas.height = decoded.height;
      const ctx = canvas.getContext('2d', { willReadFrequently: true }); ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, canvas.width, canvas.height); ctx.drawImage(decoded.image, 0, 0);
      const sample = document.createElement('canvas'); drawPreview(decoded, sample, 1100);
      const plan = core.makePlan(stats(sample, record.region), state.reference.stats, options()); sample.width = sample.height = 1;
      // Process stripes, rather than allocating two extra full-resolution RGBA images.
      const rows = Math.max(1, Math.min(96, Math.floor(180000 / canvas.width)));
      for (let y = 0; y < canvas.height; y += rows) {
        if (state.cancel) throw new Error('LK_CANCELED');
        const height = Math.min(rows, canvas.height - y), stripe = ctx.getImageData(0, y, canvas.width, height);
        const out = new Uint8ClampedArray(stripe.data.length); core.transformRange(stripe.data, out, plan); stripe.data.set(out); ctx.putImageData(stripe, 0, y);
        onProgress((y + height) / canvas.height * 0.9); await pause();
      }
      if (state.cancel) throw new Error('LK_CANCELED');
      const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.96));
      if (!blob) throw new Error('Không mã hóa được JPG.');
      if (state.cancel) throw new Error('LK_CANCELED');
      const response = await window.LKFaceMau.saveJpeg({ token: state.output.token, name: record.file.name, bytes: new Uint8Array(await blob.arrayBuffer()) });
      if (!response.ok) { const error = new Error(response.error || 'Không lưu được ảnh.'); error.license = response.license; throw error; }
      record.status = 'done'; record.path = response.path; record.error = ''; onProgress(1); return response;
    } finally { if (decoded) decoded.release(); if (canvas) canvas.width = canvas.height = 1; }
  }
  async function exportImages(all) {
    if (state.busy || state.loading || !state.reference || state.active < 0) return;
    if (!state.output && !(await chooseFolder())) return;
    const records = all ? state.files.slice() : [state.files[state.active]];
    state.busy = true; state.cancel = false; ++state.sequence; clearTimeout(previewTimer); updateControls();
    let saved = 0, failed = 0, license = false;
    try {
      for (let i = 0; i < records.length; i++) {
        if (state.cancel) break;
        const record = records[i]; message('Đang xuất ' + (i + 1) + '/' + records.length + ': ' + record.file.name);
        try { await exportRecord(record, part => progress((i + part) / records.length * 100)); saved++; }
        catch (error) {
          if (error.message === 'LK_CANCELED') break;
          record.status = 'failed'; record.error = error.message; failed++;
          if (error.license) { license = true; break; }
        }
        renderList();
      }
      renderList();
      message(license ? 'Cần kích hoạt LICENSE KEY để xuất ảnh.' : (state.cancel ? 'Đã dừng. ' : '') + 'Đã lưu ' + saved + ' ảnh' + (failed ? '; ' + failed + ' ảnh lỗi, bấm vào ảnh để xem lý do.' : '.'), license || failed ? 'error' : 'success');
    } finally { state.busy = false; progress(null); updateControls(); }
  }
  function resetActive() {
    ++state.sequence; state.previewValid = false; original.width = original.height = result.width = result.height = 1;
    $('target-area').style.display = 'none'; $('target-empty').hidden = false; $('target-name').textContent = 'Chưa có ảnh cần chỉnh'; $('dimensions').textContent = 'Xem trước / sau';
  }
  function toggleSample(role) {
    state.sampling = state.sampling === role ? null : role;
    for (const name of ['reference', 'target']) { $(name + '-area').classList.toggle('sampling', state.sampling === name); root.querySelector('[data-action="sample-' + name + '"]').classList.toggle('active', state.sampling === name); }
    if (state.sampling) { if (role === 'target') setCompare(100); message('Kéo chuột để khoanh vùng ' + (role === 'reference' ? 'ảnh mẫu' : 'ảnh cần chỉnh') + ' dùng để phân tích màu.'); }
  }
  function setupSampling(role) {
    const area = $(role + '-area'); let drag = null;
    function position(event) { const r = area.getBoundingClientRect(); return { x: Math.max(0, Math.min(1, (event.clientX - r.left) / r.width)), y: Math.max(0, Math.min(1, (event.clientY - r.top) / r.height)) }; }
    function rect(a, b) { return { x: Math.min(a.x, b.x), y: Math.min(a.y, b.y), w: Math.abs(a.x - b.x), h: Math.abs(a.y - b.y) }; }
    area.addEventListener('pointerdown', event => { if (state.sampling !== role || state.busy || event.button !== 0) return; drag = position(event); area.setPointerCapture(event.pointerId); event.preventDefault(); });
    area.addEventListener('pointermove', event => { if (drag) paintRegion(role, rect(drag, position(event))); });
    area.addEventListener('pointerup', event => {
      if (!drag) return; const selected = rect(drag, position(event)); drag = null; if (area.hasPointerCapture(event.pointerId)) area.releasePointerCapture(event.pointerId);
      if (selected.w < .025 || selected.h < .025) { paintRegion(role, role === 'reference' ? state.reference.region : state.files[state.active].region); return; }
      if (role === 'reference') { state.reference.region = selected; state.reference.stats = stats(referenceCanvas, selected); }
      else { const record = state.files[state.active]; record.region = selected; record.stats = stats(original, selected); }
      paintRegion(role, selected); toggleSample(role); setCompare(50); schedulePreview(0);
    });
    area.addEventListener('pointercancel', () => { drag = null; paintRegion(role, role === 'reference' ? state.reference && state.reference.region : state.files[state.active] && state.files[state.active].region); });
  }
  root.addEventListener('click', async event => {
    const button = event.target.closest('[data-action]'); if (!button || button.disabled) return;
    const action = button.dataset.action;
    try {
      if (action === 'close') close();
      else if (action === 'reference') $('reference-input').click();
      else if (action === 'targets') $('targets-input').click();
      else if (action === 'activate') await activate(Number(button.dataset.index));
      else if (action === 'remove') {
        const index = Number(button.dataset.index); state.files.splice(index, 1);
        if (!state.files.length) { state.active = -1; resetActive(); renderList(); }
        else if (index === state.active) { state.active = -1; await activate(Math.min(index, state.files.length - 1)); }
        else { if (index < state.active) state.active--; renderList(); }
      }
      else if (action === 'clear') { state.files = []; state.active = -1; resetActive(); renderList(); message('Đã xóa danh sách. Ảnh gốc vẫn được giữ trên máy.'); }
      else if (action === 'before') setCompare(100);
      else if (action === 'after') setCompare(0);
      else if (action === 'sample-reference') toggleSample('reference');
      else if (action === 'sample-target') toggleSample('target');
      else if (action === 'reset-reference-region') { state.reference.region = null; state.reference.stats = stats(referenceCanvas); paintRegion('reference', null); schedulePreview(0); }
      else if (action === 'reset-target-region') { const record = state.files[state.active]; record.region = null; record.stats = stats(original); paintRegion('target', null); schedulePreview(0); }
      else if (action === 'reset') { for (const [k, v] of Object.entries({ amount: 70, color: 80, tone: 45, contrast: 45 })) { $(k).value = v; $(k + '-value').textContent = v + '%'; } $('skin').checked = true; schedulePreview(0); }
      else if (action === 'folder') await chooseFolder();
      else if (action === 'open-folder') { const response = await window.LKFaceMau.openFolder(state.output.token); if (!response.ok) throw new Error(response.error); }
      else if (action === 'export-one') await exportImages(false);
      else if (action === 'export-all') await exportImages(true);
      else if (action === 'cancel') { state.cancel = true; message('Đang dừng sau thao tác hiện tại…'); }
    } catch (error) { message(error.message || 'Không thực hiện được thao tác.', 'error'); }
  });
  $('reference-input').addEventListener('change', event => { const file = event.target.files[0]; event.target.value = ''; loadReference(file).catch(error => message(error.message, 'error')); });
  $('targets-input').addEventListener('change', event => { const files = Array.from(event.target.files); event.target.value = ''; addTargets(files).catch(error => message(error.message, 'error')); });
  for (const k of ['amount', 'color', 'tone', 'contrast']) $(k).addEventListener('input', () => { $(k + '-value').textContent = $(k).value + '%'; schedulePreview(); });
  $('skin').addEventListener('change', () => schedulePreview()); $('compare').addEventListener('input', () => setCompare($('compare').value));
  for (const role of ['reference', 'target']) {
    setupSampling(role); const well = $(role + '-well');
    well.addEventListener('dragover', event => { event.preventDefault(); event.stopPropagation(); well.classList.add('drop'); });
    well.addEventListener('dragleave', () => well.classList.remove('drop'));
    well.addEventListener('drop', event => { event.preventDefault(); event.stopPropagation(); well.classList.remove('drop'); if (role === 'reference') loadReference(event.dataTransfer.files[0]); else addTargets(Array.from(event.dataTransfer.files)); });
  }
  nav.addEventListener('click', () => { if (isOpen()) close(); else open(); });
  document.addEventListener('click', event => {
    if (!isOpen() || event.composedPath().includes(nav) || event.composedPath().includes(host)) return;
    const r = nav.getBoundingClientRect();
    if (event.clientX < r.right + 30 && event.clientY > 63) close();
  }, true);
  window.addEventListener('keydown', event => { if (isOpen() && event.key === 'Escape' && !state.busy) { close(); nav.focus(); } });
  window.addEventListener('resize', queueLayout);
  const observer = new MutationObserver(() => { if (anchor && !anchor.isConnected) anchor = null; clearTimeout(anchorTimer); anchorTimer = setTimeout(queueLayout, 200); });
  function mount() { document.body.append(nav, host); observer.observe(document.getElementById('app') || document.body, { childList: true, subtree: true, characterData: true }); layout(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, { once: true }); else mount();
  setInterval(queueLayout, 1800);
})();
