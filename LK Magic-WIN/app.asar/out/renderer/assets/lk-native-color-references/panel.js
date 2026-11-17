/* LK magic 0.1.6: viewer controls and personal colour references. */
(function () {
  'use strict';
  if (window.__LK_NATIVE_COLOR_PANEL_V1__) return;
  window.__LK_NATIVE_COLOR_PANEL_V1__ = true;
  const viewerStyle = document.createElement('style');
  viewerStyle.id = 'lk-native-color-viewer-style-v1';
  viewerStyle.textContent = `
    .lk-canvas-scale,.lk-canvas-frame,.lk-canvas-view,.lk-view-split-group{display:inline-flex;align-items:center;flex:0 0 auto;box-sizing:border-box}
    .lk-canvas-scale{height:28px;margin-right:10px;border:1px solid #3d4144;border-radius:5px;background:#202325;overflow:hidden}
    .lk-canvas-scale button,.lk-canvas-scale select,.lk-canvas-view button,.lk-view-menu button{font:12px 'Segoe UI',Arial,sans-serif;color:#e4e8ec;box-sizing:border-box;cursor:pointer;outline-offset:2px}
    .lk-canvas-scale button{height:26px;padding:0 9px;border:0;background:transparent;white-space:nowrap}
    .lk-canvas-scale .lk-frame-fit{width:90px;min-width:90px;flex:0 0 90px;padding:0 12px}
    .lk-canvas-scale .lk-frame-actual{border-left:1px solid #3d4144;color:#bbc3c9;padding:0 7px}
    .lk-canvas-zoom{height:26px;min-width:65px;padding:0 5px;border:0;border-left:1px solid #3d4144;border-radius:0;background:#202325;color-scheme:dark}
    .lk-canvas-scale button:hover,.lk-canvas-zoom:hover{background:#353a3e}
    .lk-canvas-scale button:disabled,.lk-canvas-scale select:disabled,.lk-canvas-view button:disabled{opacity:.45;cursor:default}
    .lk-canvas-view{gap:6px;margin:0 0 0 2px;height:28px}
    .lk-canvas-view button{height:28px;padding:0;border:1px solid transparent;background:transparent;border-radius:4px;display:inline-flex;align-items:center;justify-content:center}
    .lk-view-single{width:29px}
    .lk-canvas-view .lk-view-single.active,.lk-view-split-group.active{background:#434a50;border-radius:4px}
    .lk-view-split-group{height:28px}
    .lk-canvas-view .lk-view-split{width:29px;border-radius:4px 0 0 4px}
    .lk-canvas-view .lk-view-expand{width:21px;border-radius:0 4px 4px 0}
    .lk-canvas-view button:hover{background:#454c52}
    .lk-canvas-view button:focus-visible,.lk-canvas-scale button:focus-visible,.lk-canvas-zoom:focus-visible,.lk-view-menu button:focus-visible{outline:2px solid #67d9f5}
    .lk-view-icon{width:16px;height:18px;display:inline-flex;align-items:center;justify-content:center;border-radius:2px;font:bold 9px/1 'Segoe UI',Arial,sans-serif;overflow:hidden}
    .lk-view-icon-single{background:#f4f5f6;color:#464e54}
    .lk-view-icon-split{border:1px solid #e6e9eb;background:#e6e9eb;color:#30363b}
    .lk-view-icon-split>span{height:100%;width:50%;display:flex;align-items:center;justify-content:center;font-size:8px}
    .lk-view-icon-split>span:first-child{background:#9da7af;border-right:1px solid #424950;color:#141a20}
    .lk-view-chevron{display:block;width:6px;height:6px;border-right:1px solid #cad2d8;border-bottom:1px solid #cad2d8;transform:translateY(-2px) rotate(45deg)}
    .lk-view-menu{z-index:2147483000;min-width:196px;padding:5px;background:#2a2e33;border:1px solid #4d575f;border-radius:6px;box-shadow:0 6px 22px #0009}
    .lk-view-menu button{display:flex;align-items:center;gap:9px;width:100%;height:34px;padding:0 9px;border:0;border-radius:4px;background:transparent;text-align:left;white-space:nowrap}
    .lk-view-menu button.active{background:#3a4650;color:#a3e9fa}
    .lk-view-menu button:hover{background:#454e56}
    .operation-bar .color-change-btn,.operate-bar .color-change-btn,.color-change-btn{display:none!important}
    .operation-bar [data-v-05137a9e],.operate-bar [data-v-05137a9e]{display:none!important}
    .lk-color-sample-section{padding:0 0 14px;box-sizing:border-box}
    .lk-color-sample-section .sample-card-list-wrapper{height:auto!important;min-height:0!important;overflow:visible!important;padding:0!important}
    .lk-color-section-title{font:600 13px/22px 'Segoe UI',Arial,sans-serif;color:#e2e7eb;margin:8px 0 10px;padding:0 0 5px;border-bottom:1px solid #3a4147}
    .lk-color-sample-section[data-lk-samples="uploaded"]>.lk-color-section-title{background:#ffd400;color:#111;padding:5px 10px;border:0;border-radius:4px}
    .lk-color-section-empty{font:12px/1.6 'Segoe UI',Arial,sans-serif;color:#9da9b2;padding:10px 0;margin:0}
    .lk-color-gallery-content{min-width:0;width:100%;padding:0 12px;box-sizing:border-box}
    .operator-panel .panel-content .panel-algorithms-content.active-color-tab__imitation{overflow-y:auto!important}
    .lk-ai-symbol{display:inline-flex;vertical-align:middle;color:#6cddff;margin-right:6px;flex:0 0 auto}
    .ai-imitation-color .algo-block { flex-direction:column!important;margin:4px 0!important;padding:8px 10px!important;box-sizing:border-box; }
    .ai-imitation-color .algo-block > .ai-sky-sliders { width:100%!important;flex:0 0 auto!important;min-width:0; }
    .lk-color-mask-controls { display:flex;flex-direction:column;width:100%;box-sizing:border-box; }
    .lk-color-mask-button-row { display:flex;justify-content:flex-end;padding:5px 0 2px; }
    .lk-color-mask-button { font:600 13px 'Segoe UI',Arial,sans-serif;background:#06bff0;color:#062a33;border:1px solid #06bff0;border-radius:5px;padding:6px 14px;cursor:pointer; }
    .lk-color-mask-button:hover { background:#53d8f7; }
    .lk-color-mask-button:focus-visible,.lk-color-mask-close:focus-visible { outline:2px solid #81e1f6;outline-offset:2px; }
    .lk-color-mask-target:empty { display:none; }
    .lk-color-mask-panel { width:100%;margin-top:10px;background:#24282d;border:1px solid #455461;border-radius:7px;box-sizing:border-box;color:#ecf1f5;font:13px/1.5 'Segoe UI',Arial,sans-serif; }
    .lk-color-mask-header { display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;border-bottom:1px solid #3c4852;font-size:14px;font-weight:700; }
    .lk-color-mask-close { width:26px;height:26px;padding:0;border:0;border-radius:4px;color:#d5e3ed;background:#36414b;cursor:pointer;font:20px/24px 'Segoe UI',Arial,sans-serif; }
    .lk-color-mask-content { padding:12px;box-sizing:border-box; }
    .lk-color-mask-panel .ai-slider-base { display:grid!important;grid-template-columns:minmax(0,1fr) 42px;column-gap:10px;row-gap:6px;height:auto!important;min-height:55px;padding:8px 0!important;box-sizing:border-box;align-items:center; }
    .lk-color-mask-panel .ai-slider-base-head-label { grid-column:1 / -1;flex:0 0 auto!important;min-width:0!important;width:auto!important;line-height:20px!important;font-size:13px!important;opacity:1!important;white-space:normal!important; }
    .lk-color-mask-panel .ai-slider-base-head-label-name { font-size:13px!important;line-height:20px!important;color:#e6edf3;white-space:normal!important;word-break:normal!important;overflow:visible!important;text-overflow:clip!important; }
    .lk-color-mask-panel .ai-slider-base-input { grid-column:1;width:100%!important;min-width:0!important;margin:0!important; }
    .lk-color-mask-panel .ai-slider-base-head-input { grid-column:2;width:42px!important;min-width:42px!important;font-size:13px!important;box-sizing:border-box; }
    .lk-color-mask-panel .ai-slider-multi-slider-list { padding:0!important;width:100%;box-sizing:border-box; }
    .lk-color-mask-panel .ai-localized-sliders { width:100%;min-width:0; }
    .lk-color-mask-panel .ai-localized-tab-item { font-size:13px!important;line-height:24px; }
    .lk-color-mask-panel .clolor-bg { font-size:14px;font-weight:600;margin-top:12px;padding-top:12px;border-top:1px solid #45515b; }
    .lk-color-remove-sample { display:block;width:100%;min-width:120px;border:0;border-radius:6px;padding:12px 16px;background:#303337;color:#ffcccc;font:600 13px 'Segoe UI',Arial,sans-serif;cursor:pointer;text-align:left; }
    .lk-color-remove-sample:hover { background:#4a3438; }
    .lk-color-remove-sample:disabled { opacity:.5;cursor:default; }
  `;
  document.head.appendChild(viewerStyle);
  const CHANGED = 'lk-native-color-references-changed-v1';
  const REFRESHED = 'lk-native-color-references-refreshed-v1';
  const host = document.createElement('div');
  host.id = 'lk-native-color-reference-toolbar-v1';
  Object.assign(host.style, { margin: '9px 12px 12px', width: 'calc(100% - 24px)', position: 'relative', zIndex: '2' });
  const root = host.attachShadow({ mode: 'open' });
  /* LK_MAGIC_COLOR_UPLOAD_GOLD_V1 */
  root.innerHTML = `<style>
    :host{font:12px 'Segoe UI',Arial,sans-serif;color:#dae8f2;display:block}*{box-sizing:border-box}.add{font:600 13px 'Segoe UI',Arial,sans-serif;display:block;width:100%;padding:8px 10px;cursor:pointer;border-radius:5px;border:1px solid #e5c16b;background:#e5c16b;color:#111112;white-space:nowrap}.add:hover{background:#f1d78f}.add:disabled{opacity:.45;cursor:default}.add:focus-visible{outline:2px solid #f1d78f;outline-offset:2px}.note{font-size:11px;color:#a4b6c3;line-height:1.5;margin:7px 1px 0}.note.error{color:#ffbaa6}.note.success{color:#a4dac7}
  </style><button class="add" id="add" type="button">Tải Ảnh Mẫu</button><p class="note" id="message" role="status" aria-live="polite">Chọn ảnh JPG/PNG trên máy để dùng làm mẫu màu.</p>`;
  const $ = id => root.getElementById(id);
  let busy = false, requestNumber = 0;
  function note(text, type) { $('message').textContent = text; $('message').className = 'note' + (type ? ' ' + type : ''); }
  function setBusy(value) { busy = value; $('add').disabled = value; }
  function refresh(selectId) {
    const requestId = 'lk-color-' + Date.now() + '-' + (++requestNumber);
    return new Promise(resolve => {
      const finished = result => { clearTimeout(timer); window.removeEventListener(REFRESHED, onRefreshed); resolve(result); };
      const onRefreshed = event => { if (event.detail && event.detail.requestId === requestId) finished(event.detail); };
      const timer = setTimeout(() => finished({ ok: false, error: 'Ảnh mẫu đã lưu. Chuyển sang Màu Sắc/Ánh Sáng rồi quay lại Face Màu Mẫu để đọc lại danh sách.' }), 7000);
      window.addEventListener(REFRESHED, onRefreshed);
      window.dispatchEvent(new CustomEvent(CHANGED, { detail: { requestId, selectId: selectId || '' } }));
    });
  }
  async function importImage() {
    if (busy) return;
    if (!window.LKNativeColorReferences) { note('Chưa kết nối được phần chọn ảnh. Hãy đóng và mở lại LK magic sau khi cập nhật.', 'error'); return; }
    setBusy(true); note('Chọn ảnh mẫu màu trên máy…');
    try {
      const response = await window.LKNativeColorReferences.importImages({ single: true });
      if (response.canceled) { note('Đã hủy chọn ảnh.'); return; }
      if (!response.ok) throw new Error(response.error || 'Không thêm được ảnh mẫu.');
      if (!response.imported) throw new Error(response.errors?.[0]?.error || 'Chưa thêm được ảnh mẫu.');
      note('Đã lưu ảnh mẫu. Đang cập nhật danh sách…');
      if (!host.isConnected) { note('Ảnh mẫu đã lưu. Quay lại Face Màu Mẫu và chọn ảnh mẫu vừa tải.'); return; }
      const selected = response.importedItems?.[0];
      const updated = await refresh(selected ? selected.id : '');
      if (!updated.ok) throw new Error(updated.error);
      note(updated.selected ? 'Mẫu đã chọn và tự đặt vào khung tham khảo. Bấm Mặt Nạ để chỉnh từng vùng.' : 'Ảnh mẫu đã lưu. Bấm vào mẫu để Face màu.', 'success');
    } catch (error) { note(error.message || 'Không thêm được ảnh mẫu.', 'error'); }
    finally { setBusy(false); }
  }
  $('add').addEventListener('click', importImage);
  window.addEventListener('lk-native-color-references-removed-v1', () => note('Đã xoá ảnh mẫu.', 'success'));
  // An optional older LK add-on may insert this shortcut outside the compiled
  // renderer. Match the complete caption only; keep the independent Face màu tab.
  const normalize = text => String(text || '').normalize('NFC').replace(/\s+/g, ' ').trim().toLocaleLowerCase('vi');
  function removeOldShortcut() {
    for (const button of document.querySelectorAll('button,[role="button"],a,.lk-ai-color-reference-button,.algorithms-list-wrapper .algorithms-row-group-label')) {
      if (normalize(button.textContent) === 'ai màu sắc theo mẫu') {
        button.hidden = true; button.style.setProperty('display', 'none', 'important');
        button.setAttribute('aria-hidden', 'true');
      }
      if (button.matches('.algorithms-row-group-label') && normalize(button.textContent) === 'ai màu sắc') {
        const group = button.closest('.algorithms-row-group');
        if (group) { group.hidden = true; group.style.setProperty('display', 'none', 'important'); }
      }
    }
  }
  let pending = false;
  function attach() {
    pending = false;
    removeOldShortcut();
    const panel = document.querySelector('.ai-imitation-color');
    if (!panel) { if (host.isConnected) host.remove(); return; }
    if (host.parentElement !== panel) panel.prepend(host);
  }
  function schedule() { if (!pending) { pending = true; requestAnimationFrame(attach); } }
  function mount() { new MutationObserver(schedule).observe(document.getElementById('app') || document.body, { childList: true, subtree: true }); attach(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, { once: true }); else mount();
})();
