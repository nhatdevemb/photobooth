(function () {
  'use strict';
  if (window.__LK_FACE_PRESET_PANEL_V1__) return;
  window.__LK_FACE_PRESET_PANEL_V1__ = true;
  const api = window.LKFacePreset, core = window.LKFacePresetCore;
  if (!api || !core) { console.error('[LK Face màu] Thiếu kết nối tạo preset.'); return; }
  const host = document.createElement('div'); host.id = 'lk-face-preset-panel-v1';
  Object.assign(host.style, { position: 'fixed', zIndex: '1900', display: 'none', right: 0, bottom: 0 });
  const root = host.attachShadow({ mode: 'open' });
  const icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 3C9 7 5 10 5 14a7 7 0 0 0 14 0c0-4-4-7-7-11Z"/><path d="m9 9 6 6m-7 0 2 2"/></svg>';
  const photoIcon = '<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="5" width="24" height="22" rx="4"/><circle cx="12" cy="12" r="2.5"/><path d="m5 24 7-7 5 4 5-8 6 11"/></svg>';
  const card = (role, title, action, button, empty) => `<article class="image-card"><div class="card-heading"><strong>${title}</strong><button data-action="${action}">${button}</button></div><div class="file-name" id="${role}-name">Chưa chọn ảnh</div><div class="well"><div class="empty" id="${role}-empty">${photoIcon}<b>${empty}</b><span>${role === 'reference' ? 'JPG / PNG · chuẩn màu của chủ thể' : 'RAW hoặc JPG / PNG của bạn'}</span></div><div class="canvas-wrap" id="${role}-wrap"><canvas id="${role}-canvas"></canvas><div class="marks" id="${role}-marks"></div></div></div><div class="image-footer"><span id="${role}-region-hint">Kéo trên ảnh để chọn vùng da</span>${role === 'source' ? '<div><button data-action="before" class="active">Gốc</button> <button data-action="after" disabled>Kết quả</button></div>' : ''}</div></article>`;
  root.innerHTML = `<section class="screen" aria-label="Face màu — tạo preset theo chủ thể">
    <header class="heading"><div><h1>Face màu</h1><p>Tạo preset theo chuẩn màu chủ thể</p></div><div class="heading-right"><span class="pill">Trên máy · Bản thử nghiệm</span><button data-action="close">← Quay lại dự án</button></div></header>
    <div class="body"><div class="workflow"><span><b>1</b>Chọn ảnh mẫu</span><span><b>2</b>Chọn RAW cần chỉnh</span><span><b>3</b>Khoanh vùng tương ứng</span><span><b>4</b>Tạo và lưu preset</span></div>
    <div class="layout"><div class="work"><div class="region-toolbar"><span>Vùng cần lấy chuẩn</span><button class="region-button active" data-region="skin">Da · bắt buộc</button><button class="region-button" data-region="hair">Tóc</button><button class="region-button" data-region="clothes">Quần áo</button><button class="clear-region" data-action="clear-region">Xóa vùng đang chọn</button></div>
      <div class="images">${card('reference', 'Ảnh mẫu chuẩn', 'reference', 'Chọn ảnh mẫu', 'Chọn ảnh có màu bạn muốn')}${card('source', 'Ảnh cần chỉnh', 'source', 'Chọn RAW / ảnh', 'Chọn ảnh cần tạo preset')}</div>
      <div class="pairs" id="pairs"></div><p class="hint">Khoanh vùng da trên cả hai ảnh, tránh môi, mắt và vùng cháy sáng. Có thể thêm tóc và quần áo. Bối cảnh không tham gia chấm điểm.</p><div class="mode" id="input-mode" hidden></div>
      <details class="details"><summary>Xem thông số preset</summary><pre id="parameters">Chưa tạo preset.</pre></details>
    </div><aside class="settings"><h2>Tạo preset</h2>
      <label class="field"><span>Mức tìm thông số</span><select id="quality"><option value="90">Thử nhanh · tối đa 90 lượt</option><option value="220" selected>Kỹ hơn · tối đa 220 lượt</option><option value="420">Chi tiết · tối đa 420 lượt</option></select></label>
      <label class="check"><input type="checkbox" id="use-ai" checked><span>Dùng AI tông màu và cân bằng trắng</span></label>
      <label class="check"><input type="checkbox" id="use-masks" checked><span>Chỉnh riêng da, tóc và quần áo bằng mặt nạ LK magic</span></label>
      <label class="field"><span>Màu quần áo</span><select id="clothes-mode"><option value="keep">Giữ màu gốc, lấy độ sáng mẫu</option><option value="sample">Lấy cả màu của mẫu</option></select></label>
      <button class="primary" data-action="run" disabled>Tạo preset theo mẫu</button>
      <div class="score"><small>Sai lệch vùng đã chọn</small><strong id="score">—</strong><small id="score-note">Điểm càng nhỏ càng gần chuẩn mẫu.</small></div>
      <div class="separator"></div><label class="field"><span>Tên preset</span><input id="preset-name" type="text" maxlength="100" placeholder="Ví dụ: Da sáng ấm · Studio"></label>
      <button class="save" data-action="save" disabled>Lưu vào Cài đặt sẵn</button><p class="notice">Preset lưu trong nhóm “Face màu của tôi”. Các RAW khác nhau có thể cần thông số khác nhau.</p>
      <button class="diagnostics" data-action="diagnostics">Lưu chẩn đoán khi có lỗi</button>
    </aside></div></div><footer class="statusbar"><span id="status" class="status" role="status" aria-live="polite">Chọn ảnh mẫu và RAW, sau đó khoanh vùng da trên cả hai ảnh.</span><div class="progress" id="progress"><i id="progress-fill"></i></div><button data-action="stop" hidden>Dừng</button></footer>
  </section>`;
  const style = document.createElement('link'); style.rel = 'stylesheet'; style.href = new URL('panel.css', document.currentScript.src).href; root.prepend(style);
  const $ = id => root.getElementById(id), button = action => root.querySelector('[data-action="' + action + '"]');
  const state = { reference: null, source: null, areas: { reference: {}, source: {} }, selected: 'skin', busy: false, optimizing: false, cancel: false, result: null, view: 'before' };
  let anchor = null, layoutFrame = 0;
  const nav = document.createElement('button'); nav.id = 'lk-face-mau-tab-v1'; nav.type = 'button'; nav.innerHTML = icon + '<span>Face màu</span>';
  nav.setAttribute('aria-controls', host.id); nav.setAttribute('aria-expanded', 'false');
  Object.assign(nav.style, { display: 'none', position: 'fixed', zIndex: '1901', alignItems: 'center', gap: '15px', padding: '0 20px', borderRadius: '11px', border: '1px solid #343a40', background: '#23272b', color: '#eff6fc', font: '14px Segoe UI,Arial,sans-serif', cursor: 'pointer', textAlign: 'left', boxSizing: 'border-box' });
  Object.assign(nav.querySelector('svg').style, { width: '22px', height: '22px', flex: 'none', color: '#26d1f3' });
  function usable(element) { const r = element.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.left >= -2 && r.left < 160 && r.top > 25 && r.top < innerHeight - 70 && getComputedStyle(element).visibility !== 'hidden'; }
  function findAnchor() {
    if (anchor?.isConnected && usable(anchor)) return anchor;
    let best = null, score = -Infinity;
    for (const item of document.querySelectorAll('button,a,[role="tab"],[role="button"],.el-menu-item,div,span')) {
      if (item === nav || item === host || item.children.length > 6 || !['Dự án của tôi','DỰ ÁN CỦA TÔI','我的项目','Dự án của Tôi'].includes((item.textContent || '').replace(/\s+/g, ' ').trim())) continue;
      for (let e = item, level = 0; e && e !== document.body && level < 5; e = e.parentElement, level++) {
        const r = e.getBoundingClientRect();
        if (!usable(e) || r.width < 120 || r.width > 330 || r.height < 28 || r.height > 95) continue;
        const value = r.width - Math.abs(r.height - 48) + (e.tagName === 'BUTTON' ? 30 : 0);
        if (value > score) { best = e; score = value; }
      }
    }
    return (anchor = best);
  }
  function layout() {
    layoutFrame = 0;
    if (!nav.isConnected) document.body.append(nav);
    if (!host.isConnected) document.body.append(host);
    const item = findAnchor();
    if (!item) { nav.style.display = 'none'; if (!state.busy) host.style.display = 'none'; return; }
    const r = item.getBoundingClientRect();
    Object.assign(nav.style, { display: 'flex', left: r.left + 'px', top: r.bottom + 10 + 'px', width: r.width + 'px', height: Math.max(44, r.height) + 'px' });
    let edge = r.right + Math.max(16, r.left), top = 64;
    for (let p = item.parentElement, i = 0; p && p !== document.body && i < 8; p = p.parentElement, i++) {
      const b = p.getBoundingClientRect(); if (b.width > r.width + 8 && b.width < 400 && b.height > innerHeight * .5) { edge = b.right; top = Math.max(0, b.top); break; }
    }
    Object.assign(host.style, { left: edge + 'px', top: top + 'px' });
  }
  function queueLayout() { if (!layoutFrame) layoutFrame = requestAnimationFrame(layout); }
  function message(text, kind = '') { $('status').textContent = text; $('status').className = 'status ' + kind; }
  function checkResponse(result) { if (!result?.ok) { if (result?.canceled) return null; throw new Error(result?.error || 'Không nhận được kết quả từ LK magic.'); } return result; }
  function activeKeys() { return Object.keys(core.regions).filter(k => state.areas.reference[k] && state.areas.source[k]); }
  function update() {
    const locked = state.busy, paired = activeKeys();
    for (const b of root.querySelectorAll('button')) b.disabled = locked && b !== button('stop');
    button('run').disabled = locked || !state.reference || !state.source || !paired.includes('skin');
    button('save').disabled = locked || !state.result || !Object.keys(state.result.best.vector).length;
    button('after').disabled = locked || !state.result;
    button('stop').hidden = !state.optimizing; button('stop').disabled = state.cancel;
    for (const e of root.querySelectorAll('input,select')) e.disabled = locked;
    $('pairs').replaceChildren(...Object.entries(core.regions).map(([k, info]) => {
      const e = document.createElement('span'), count = Number(!!state.areas.reference[k]) + Number(!!state.areas.source[k]);
      e.className = 'pair' + (count === 2 ? ' ready' : ''); e.textContent = info.label + ': ' + count + '/2 vùng'; return e;
    }));
    for (const e of root.querySelectorAll('[data-region]')) e.classList.toggle('active', e.dataset.region === state.selected);
  }
  async function loadFrame(url) {
    if (typeof url !== 'string' || !/^data:image\//.test(url)) throw new Error('Ảnh xem trước chưa hợp lệ.');
    const image = new Image(); image.src = url; await image.decode();
    if (image.naturalWidth < 16 || image.naturalHeight < 16 || image.naturalWidth * image.naturalHeight > 100000000) throw new Error('Kích thước ảnh chưa phù hợp.');
    const ratio = Math.min(1, 1200 / Math.max(image.naturalWidth, image.naturalHeight));
    const c = document.createElement('canvas'); c.width = Math.round(image.naturalWidth * ratio); c.height = Math.round(image.naturalHeight * ratio);
    const ctx = c.getContext('2d', { willReadFrequently: true }); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); ctx.drawImage(image, 0, 0, c.width, c.height);
    return { width: c.width, height: c.height, data: ctx.getImageData(0, 0, c.width, c.height).data, canvas: c };
  }
  function showFrame(role, frame) {
    const c = $(role + '-canvas'); c.width = frame.width; c.height = frame.height;
    c.getContext('2d').drawImage(frame.canvas, 0, 0); $(role + '-wrap').style.display = 'inline-flex'; $(role + '-empty').hidden = true;
    paint(role);
  }
  function paint(role, draft) {
    const colors = { skin: '#ffcf82', hair: '#c9b0ff', clothes: '#72d9ef' }, areas = { ...state.areas[role] };
    if (draft) areas[state.selected] = draft;
    $(role + '-marks').replaceChildren(...Object.entries(areas).map(([k, a]) => {
      const box = document.createElement('div'); box.className = 'mark'; box.style.cssText = `left:${a.x * 100}%;top:${a.y * 100}%;width:${a.w * 100}%;height:${a.h * 100}%;--mark:${colors[k]}`;
      const label = document.createElement('span'); label.textContent = core.regions[k].label; box.append(label); return box;
    }));
    $(role + '-region-hint').textContent = 'Kéo để chọn vùng ' + core.regions[state.selected].label.toLowerCase();
  }
  function invalidate() {
    state.result = null; state.view = 'before';
    if (state.source) showFrame('source', state.source.frame);
    button('before').classList.add('active'); button('after').classList.remove('active');
    $('score').textContent = '—'; $('score-note').textContent = 'Điểm càng nhỏ càng gần chuẩn mẫu.'; $('parameters').textContent = 'Chưa tạo preset.'; update();
  }
  for (const role of ['reference', 'source']) {
    const canvas = $(role + '-canvas'); let origin = null;
    const point = event => { const r = canvas.getBoundingClientRect(); return { x: Math.max(0, Math.min(1, (event.clientX - r.left) / r.width)), y: Math.max(0, Math.min(1, (event.clientY - r.top) / r.height)) }; };
    const area = end => ({ x: Math.min(origin.x, end.x), y: Math.min(origin.y, end.y), w: Math.abs(end.x - origin.x), h: Math.abs(end.y - origin.y) });
    canvas.addEventListener('pointerdown', event => { if (state.busy || !state[role] || event.button !== 0) return; origin = point(event); canvas.setPointerCapture(event.pointerId); event.preventDefault(); });
    canvas.addEventListener('pointermove', event => { if (origin) paint(role, area(point(event))); });
    canvas.addEventListener('pointerup', event => {
      if (!origin) return;
      const a = area(point(event)); origin = null;
      try { core.analyze(state[role].frame, a); state.areas[role][state.selected] = core.rect(a); invalidate(); message('Đã chọn vùng ' + core.regions[state.selected].label.toLowerCase() + '. ' + (activeKeys().includes('skin') ? 'Có thể bấm Tạo preset theo mẫu.' : 'Chọn tiếp vùng tương ứng trên ảnh còn lại.')); }
      catch (error) { message(error.message, 'error'); }
      paint(role); update();
    });
    canvas.addEventListener('pointercancel', () => { origin = null; paint(role); });
  }
  async function pick(role) {
    state.busy = true; state.cancel = false; update();
    message(role === 'source' ? 'Đang nhập ảnh và dựng bản gốc bằng LK magic. Có thể cần chờ khi đọc RAW…' : 'Đang mở ảnh mẫu…');
    try {
      const result = checkResponse(await (role === 'reference' ? api.chooseReference() : api.chooseSource()));
      if (!result) { message('Đã hủy chọn ảnh.'); return; }
      const frame = await loadFrame(result.image); state[role] = { ...result, frame }; state.areas[role] = {};
      $(role + '-name').textContent = result.name; $(role + '-name').title = result.name; invalidate();
      if (role === 'source') {
        state.view = 'before'; const mode = $('input-mode'); mode.hidden = false; mode.classList.toggle('compat', result.mode === 'raw-jpeg-compat');
        mode.textContent = result.mode === 'native-raw' ? 'Đang dùng bộ đọc RAW gốc của LK magic.' : result.mode === 'raw-jpeg-compat' ? 'RAW này được LK magic chuyển qua JPG tương thích. Preset được tìm trên bản JPG đó; dữ liệu RAW đầy đủ chưa được dùng.' : 'Đang dùng ảnh RGB. Bạn có thể chọn RAW để tạo preset trên luồng RAW được hỗ trợ.';
      } else if (!$('preset-name').value.trim()) $('preset-name').value = ('Chuẩn màu · ' + result.name.replace(/\.[^.]+$/, '')).slice(0, 100);
      showFrame(role, frame); message('Đã mở ảnh. Chọn Da rồi kéo một vùng trên cả hai ảnh.');
    } catch (error) {
      if (role === 'source') {
        state.source = null; state.areas.source = {}; invalidate();
        $('source-wrap').style.display = 'none'; $('source-empty').hidden = false; $('source-name').textContent = 'Chưa chọn ảnh'; $('input-mode').hidden = true; paint('source');
      }
      message(error.message, 'error');
    }
    finally { state.busy = false; update(); }
  }
  function statsFor(frame, areas, keys) { return Object.fromEntries(keys.map(k => [k, core.analyze(frame, areas[k])])); }
  async function run() {
    const keys = activeKeys(); if (!keys.includes('skin') || !state.reference || !state.source) return;
    const areas = JSON.parse(JSON.stringify(state.areas)), clothesMode = $('clothes-mode').value;
    const reference = statsFor(state.reference.frame, areas.reference, keys), source = statsFor(state.source.frame, areas.source, keys);
    invalidate(); state.busy = true; state.optimizing = true; state.cancel = false; update(); $('progress').style.display = 'block'; $('progress-fill').style.width = '0%';
    let bestURL = '', bestFrame = null;
    try {
      const result = await core.optimize({ reference, source, clothesMode, ai: $('use-ai').checked, local: $('use-masks').checked, requireChangingPreview: true, budget: Number($('quality').value), canceled: () => state.cancel,
        evaluate: async vector => {
          const response = checkResponse(await api.render({ token: state.source.token, vector }));
          const frame = await loadFrame(response.image);
          return { image: response.image, stats: statsFor(frame, areas.source, keys), frame };
        },
        onProgress: async info => {
          $('score').textContent = info.best.loss.toFixed(2); $('score-note').textContent = 'Tốt nhất trong ' + info.calls + ' lượt đã dựng.';
          $('progress-fill').style.width = Math.round(info.calls / info.budget * 100) + '%';
          message(state.cancel ? 'Đang dừng sau khi LK magic hoàn tất lượt hiện tại…' : 'Đang tìm thông số · ' + info.calls + '/' + info.budget + ' lượt. Bối cảnh không được chấm điểm.');
          // Paint only completed native previews. Avoid retaining every trial.
          if (info.best.image && info.best.image !== bestURL) {
            bestURL = info.best.image; bestFrame = await loadFrame(bestURL); showFrame('source', bestFrame);
            button('after').classList.add('active'); button('before').classList.remove('active');
          }
        }
      });
      if (!bestFrame) bestFrame = await loadFrame(result.best.image);
      state.result = { ...result, frame: bestFrame, clothesMode, keys };
      state.view = 'after'; showFrame('source', bestFrame); button('after').classList.add('active'); button('before').classList.remove('active');
      $('score').textContent = result.baseline.loss.toFixed(2) + ' → ' + result.best.loss.toFixed(2);
      $('score-note').textContent = result.best.loss < result.baseline.loss - .02 ? 'Sai lệch đã giảm trên các vùng bạn chọn.' : 'Chưa cải thiện rõ. Có thể khoanh lại vùng hoặc tìm kỹ hơn.';
      $('parameters').textContent = Object.entries(result.best.vector).map(([name, value]) => { const [scope, key] = name.split('.'); return (scope === 'global' ? 'Toàn ảnh' : core.regions[scope].label) + ' · ' + core.specs.find(s => s.key === key).label + ': ' + value; }).join('\n') || 'Giữ thông số gốc.';
      if (!Object.keys(result.best.vector).length) message(result.best.loss < 1 ? 'Ảnh gốc đã gần chuẩn vùng chọn. Chưa có thông số chỉnh mới cần lưu.' : 'Chưa tìm được bộ chỉnh tốt hơn ảnh gốc. Khoanh lại vùng hoặc chọn mức tìm kỹ hơn.');
      else message((result.canceled ? 'Đã dừng. Giữ kết quả tốt nhất trong ' : 'Đã tìm xong sau ') + result.calls + ' lượt. Xem kết quả rồi lưu preset.', 'success');
    } catch (error) { message(error.message + ' Có thể lưu chẩn đoán để kiểm tra.', 'error'); state.result = null; }
    finally { state.busy = false; state.optimizing = false; state.cancel = false; $('progress').style.display = 'none'; update(); }
  }
  async function save() {
    if (!state.result) return;
    const name = $('preset-name').value.trim(); if (!name) { $('preset-name').focus(); message('Đặt tên preset trước khi lưu.', 'error'); return; }
    state.busy = true; update();
    try {
      const r = state.result, saved = checkResponse(await api.save({ token: state.source.token, name, vector: r.best.vector, measurements: { regions: r.keys, clothesMode: r.clothesMode, loss: r.best.loss, baselineLoss: r.baseline.loss } }));
      message('Đã lưu “' + saved.name + '” vào Cài đặt sẵn → ' + saved.groupName + '. Quay lại Dự án để áp dụng.', 'success');
    } catch (error) { message(error.message, 'error'); }
    finally { state.busy = false; state.cancel = false; update(); }
  }
  root.addEventListener('click', event => {
    const region = event.target.closest('[data-region]');
    if (region && !state.busy) { state.selected = region.dataset.region; for (const role of ['source','reference']) paint(role); update(); return; }
    const action = event.target.closest('[data-action]')?.dataset.action;
    if (action === 'stop') { state.cancel = true; update(); message('Đang dừng sau khi bộ xử lý hoàn tất lượt hiện tại…'); return; }
    if (state.busy) return;
    if (action === 'reference' || action === 'source') void pick(action);
    else if (action === 'run') void run();
    else if (action === 'save') void save();
    else if (action === 'close') { host.style.display = 'none'; nav.setAttribute('aria-expanded', 'false'); nav.style.background = '#23272b'; }
    else if (action === 'clear-region') { delete state.areas.reference[state.selected]; delete state.areas.source[state.selected]; invalidate(); paint('reference'); paint('source'); }
    else if (action === 'before' && state.source) { state.view = 'before'; showFrame('source', state.source.frame); button('before').classList.add('active'); button('after').classList.remove('active'); }
    else if (action === 'after' && state.result) { state.view = 'after'; showFrame('source', state.result.frame); button('after').classList.add('active'); button('before').classList.remove('active'); }
    else if (action === 'diagnostics') void api.diagnostics().then(checkResponse).then(r => { if (r) message('Đã lưu tệp chẩn đoán. Tệp không chứa ảnh.'); }).catch(e => message(e.message, 'error'));
  });
  for (const id of ['quality','use-ai','use-masks','clothes-mode']) $(id).addEventListener('change', () => { if (!state.busy) invalidate(); });
  nav.addEventListener('click', () => { if (host.style.display === 'none') { host.style.display = 'block'; nav.setAttribute('aria-expanded', 'true'); nav.style.background = '#093f50'; queueLayout(); } else if (!state.busy) { host.style.display = 'none'; nav.setAttribute('aria-expanded', 'false'); nav.style.background = '#23272b'; } });
  new MutationObserver(queueLayout).observe(document.body, { childList: true, subtree: true });
  window.addEventListener('resize', queueLayout); setInterval(queueLayout, 1500);
  document.body.append(nav, host); layout(); update();
})();
