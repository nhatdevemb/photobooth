/** Local-only operator booth. Server revisions, pinned configuration and jobs are authoritative. */
import { BrowserCamera, parseCubeLut, LutCanvasRenderer } from './browser-camera.js';
import { DccCamera } from './dcc-camera.js';
const $ = (id) => document.getElementById(id);
const browserMode = document.body.dataset.camera === 'browser';
const dccMode = document.body.dataset.camera === 'digicamcontrol';
const browserCamera = new BrowserCamera($('webcam-video'), $('webcam-status'), $('camera-badge'), $('webcam-device'), updateCropGuide, $('btn-webcam-mirror'), $('webcam-lut-canvas'));
const lutCache = new Map();
const frameLayerCache = new Map();
let thumbLutCanvas = null, thumbLutRenderer = null;
const state = {
    user: null, csrf: null, session: null, step: 1, view: 'workspace', busy: false,
    settings: null, eventPreviews: [], copies: 1,
    layouts: [], templates: [], presets: [], share: null, previewKey: null,
    selectionPreviewKey: null, previewReady: false, renderError: '', job: null, history: [], cursor: null,
    detail: null, print: null, resolve: null, templatePreview: null, sampleGeneration: 0,
    cameraAttempted: false, cameraStarting: null, lutEditor: null, lutQuery: '', captureSlot: null,
};
const dccCamera = dccMode ? new DccCamera($('live-preview-img'), $('camera-badge'), $('dcc-status'), api) : null;
const labels = { NEW: 'Chưa chụp', CAPTURING: 'Đang chụp', CAPTURED: 'Đã chụp', RENDERING: 'Đang ghép ảnh', REVIEW: 'Chờ duyệt', FINALIZED: 'Đã duyệt', CLOSED: 'Đã hoàn tất', CANCELLED: 'Đã hủy' };
const stepTitles = { 1: 'Chọn bố cục ảnh', 3: 'Chọn filter yêu thích', 4: 'Chọn template yêu thích' };
const descriptions = { 1: 'Chọn số lượng và vị trí ảnh trước khi chụp.', 3: 'Chọn màu và làm đẹp cho bộ ảnh vừa chụp.', 4: 'Chọn khung trang trí cho bộ ảnh của bạn.' };
const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
const code = (id) => `PB-${id.slice(0, 8).toUpperCase()}`;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function uuid()
{
    if (crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
        const value = crypto.getRandomValues(new Uint8Array(1))[0] & 15;
        return (char === 'x' ? value : (value & 3) | 8).toString(16);
    });
}

function toast(message, error = false)
{
    const item = document.createElement('div');
    item.className = `toast${error ? ' error' : ''}`;
    item.textContent = message;
    $('toast-container').append(item);
    setTimeout(() => item.remove(), 5000);
}

async function api(method, path, data = null, key = null)
{
    const headers = { Accept: 'application/json' };
    const options = { method, headers, credentials: 'same-origin' };
    if (method !== 'GET') {
        headers['X-CSRF-Token'] = state.csrf || '';
        headers['Idempotency-Key'] = key || uuid();
    }
    if (data instanceof FormData) {
        options.body = data;
    } else if (data instanceof File) {
        headers['Content-Type'] = 'application/zip';
        options.body = data;
    } else if (data !== null) {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(data);
    }
    // A network retry reuses the same request and key, including for print and restart.
    let response;
    const attempts = method === 'GET' ? 5 : 2;
    for (let attempt = 0; attempt < attempts; attempt++) {
        try {
            response = await fetch(`/custom/api${path}`, options);
        } catch (error) {
            if (attempt === attempts - 1) throw error;
            await sleep(200 * (attempt + 1));
            continue;
        }
        if (method === 'GET' && [502, 503, 504].includes(response.status) && attempt < attempts - 1) {
            await response.body?.cancel();
            await sleep(200 * (attempt + 1));
            continue;
        }
        break;
    }
    const result = await response.json().catch(() => {
        throw Object.assign(new Error('Dịch vụ đang gián đoạn. Tải lại phiên để kiểm tra kết quả thao tác.'), { status: response.status });
    });
    if (!response.ok) {
        const error = new Error(result.error?.message || `Lỗi HTTP ${response.status}`);
        error.status = response.status;
        error.code = result.error?.code;
        throw error;
    }
    return result;
}

async function run(action)
{
    if (state.busy) return;
    state.busy = true;
    render();
    try {
        await action();
    } catch (error) {
        toast(error.message || 'Không kết nối được hệ thống.', true);
        if (error.status === 401) {
            signOut();
        } else if (state.session) {
            try { await loadSession(state.session.id); } catch { /* Preserve the error message. */ }
        }
    } finally {
        state.busy = false;
        state.job = null;
        render();
    }
}

async function loadSession(id, restoreStep = false)
{
    const session = await api('GET', `/sessions/${id}`);
    if (state.session?.id !== id) state.share = null;
    if (state.session?.id !== id) state.copies = 1;
    state.session = session;
    if (restoreStep) state.step = session.ui_step;
    return session;
}

async function enter()
{
    const [presets, layouts, active] = await Promise.all([
        api('GET', '/presets'), api('GET', '/layouts'), api('GET', '/sessions/active'),
    ]);
    state.presets = presets.presets;
    state.layouts = layouts.items;
    state.session = active.active_session;
    await loadSessionTemplates();
    state.step = state.session?.ui_step || 1;
    state.view = 'workspace';
    render();
    await health();
    if (state.session?.active_job) {
        const jobId = state.session.active_job.id;
        if (state.busy) await waitJob(jobId);
        else await run(() => waitJob(jobId));
    }
}

async function loadSessionTemplates()
{
    frameLayerCache.clear();
    state.copies = 1;
    state.templates = state.session ? (await api('GET', '/templates?session_id=' + state.session.id)).items : [];
}

function signOut()
{
    closeLutEditor();
    state.user = null;
    state.csrf = null;
    state.session = null;
    state.share = null;
    state.previewKey = null;
    state.selectionPreviewKey = null;
    $('selection-preview').dataset.generation = '';
    state.print = null;
    state.settings = null;
    frameLayerCache.clear();
    document.querySelectorAll('.modal-backdrop').forEach((element) => { element.hidden = true; });
    render();
}

function layoutFor(session = state.session)
{
    return state.layouts.find((item) => item.id === session?.layout_id);
}

function templateFor(session = state.session)
{
    return session?.template || state.templates.find((item) => item.id === session?.template_id && item.version === session.template_version);
}

function allCaptured()
{
    return state.session && state.session.slots.length === state.session.capture_count && state.session.slots.every((slot) => slot.photo);
}

function printAction(session, finalId)
{
    return session.state === 'CLOSED' || session.print_summary.requests.some((request) =>
        request.final_asset_id === finalId && request.action === 'print' &&
        (request.status !== 'FAILED' || request.resolution_outcome === 'observed_completed')
    ) ? 'reprint' : 'print';
}

function liveViewVisible()
{
    return Boolean(state.user && state.session) && state.view === 'workspace' && state.step === 2;
}

function syncLiveView()
{
    const visible = liveViewVisible();
    $('liveview-panel').hidden = !visible;
    const previewVisible = Boolean(state.user && state.session) && state.view === 'workspace' && [3, 4].includes(state.step);
    $('selection-preview-panel').hidden = !previewVisible;
    $('workspace-stage').classList.toggle('single-column-stage', !visible && !previewVisible);
    if (!visible) {
        state.cameraAttempted = false;
        if (browserMode && browserCamera.stream) browserCamera.stop();
        dccCamera?.stop();
        return;
    }
    if (document.body.dataset.mock === 'true' && !$('live-preview-img').hasAttribute('src')) {
        $('live-preview-img').src = '/custom/assets/mock-preview/frame-1.jpg';
    }
    if (state.cameraAttempted || state.cameraStarting || (!browserMode && !dccMode)) return;
    state.cameraAttempted = true;
    state.cameraStarting = (browserMode ? browserCamera.open($('webcam-device').value) : dccCamera.start())
        .then(() => {
            if (!liveViewVisible()) {
                if (browserMode) browserCamera.stop();
                else dccCamera.stop();
                return;
            }
            syncCaptureLut(state.session);
            updateCropGuide();
        })
        .catch((error) => { (dccMode ? $('dcc-status') : $('webcam-status')).textContent = error.message + ' Bấm Bật camera để thử lại.'; })
        .finally(() => { state.cameraStarting = null; render(); });
}

function render()
{
    const loggedIn = Boolean(state.user);
    const session = state.session;
    if (session?.configuration_locked && state.step === 1) state.step = 2;
    if (session && !allCaptured() && state.step > 2) state.step = session.configuration_locked ? 2 : 1;
    syncLiveView();
    $('login-view').hidden = loggedIn;
    $('auth-user-bar').hidden = !loggedIn;
    for (const view of ['workspace', 'history', 'admin', 'luts', 'settings']) $(view + '-view').hidden = !loggedIn || state.view !== view;
    $('wizard-nav').hidden = !loggedIn || state.view !== 'workspace' || !state.session;
    if (!loggedIn) return;
    $('current-user-display').textContent = state.user.username;
    $('current-role-badge').textContent = state.user.role === 'admin' ? 'Quản trị viên' : 'Người vận hành';
    $('btn-nav-admin').hidden = state.user.role !== 'admin';
    $('btn-nav-luts').hidden = state.user.role !== 'admin';
    $('btn-nav-settings').hidden = state.user.role !== 'admin';
    $('btn-nav-settings').disabled = Boolean(session);
    $('session-event-name').textContent = session?.event?.name || '';
    $('session-event-name').hidden = !session?.event?.name;
    $('event-settings-fields').disabled = state.busy || Boolean(session) || state.settings?.locked !== false;
    $('settings-lock').hidden = !session && !state.settings?.locked;
    $('btn-import-luts').disabled = state.busy;
    updateLutButtons();
    $('empty-session').hidden = Boolean(session);
    $('btn-restart-session').hidden = !session;
    $('btn-restart-session').disabled = state.busy || Boolean(session?.active_job);
    $('session-code-display').textContent = session ? code(session.id) : 'Chưa có phiên đang mở';
    $('session-state-badge').textContent = session ? `${labels[session.state]} · rev ${session.revision}` : '';
    $('configuration-summary').hidden = !session?.configuration_locked;
    if (session?.configuration_locked) {
        const beautyDesc = session.beauty ? (session.beauty.enabled ? `Làm đẹp: ${session.beauty.preset}` : 'Làm đẹp: Tắt') : '';
        $('configuration-summary').textContent = `🔒 Bố cục: ${layoutFor()?.name} · ${session.capture_count} ảnh. Filter: ${session.lut.name} (${session.lut_intensity}%) · ${templateFor()?.name} · ${beautyDesc}. Chọn filter và template sau khi chụp đủ ảnh.`;
    }
    for (const id of ['selection-panel', 'capture-panel', 'review-panel', 'wizard-footer']) $(id).hidden = true;
    if (!session) {
        syncCaptureLut(null);
        $('btn-new-session').disabled = state.busy;
        return;
    }
    const final = ['FINALIZED', 'CLOSED'].includes(session.state);
    document.querySelectorAll('[data-step]').forEach((button) => {
        const step = Number(button.dataset.step);
        button.disabled = state.busy || (final ? step !== 5 : (step === 1 && session.configuration_locked) || (step >= 3 && !allCaptured()));
        if (step === state.step) button.setAttribute('aria-current', 'step');
        else button.removeAttribute('aria-current');
    });
    $('wizard-footer').hidden = state.step === 5;
    $('wizard-progress').textContent = state.step + ' / 5';
    $('btn-back').disabled = state.busy || state.step === 1 || (state.step === 2 && session.configuration_locked);
    $('btn-next').disabled = state.busy || (state.step === 2 && !allCaptured());
    $('btn-next').textContent = ({ 1: 'Sang chụp ảnh →', 2: 'Chọn filter →', 3: 'Chọn template →', 4: 'Xem ảnh ghép →' })[state.step] || 'Tiếp tục →';
    if ([1, 3, 4].includes(state.step)) renderSelection();
    else if (state.step === 2) renderCapture();
    else renderReview();
    syncCaptureLut(session);
    updateCropGuide();
}

function renderSelection()
{
    $('selection-panel').hidden = false;
    $('selection-title').textContent = `✦ ${stepTitles[state.step]}`;
    $('selection-description').textContent = descriptions[state.step];
    $('configuration-note').textContent = state.step === 1 ? 'Bố cục được giữ cố định khi bắt đầu chụp.' : 'Ảnh gốc được giữ nguyên. Lựa chọn áp dụng cho bản ảnh ghép mới.';
    const session = state.session;
    $('filter-search-wrap').hidden = state.step !== 3;
    if (document.activeElement !== $('filter-search')) $('filter-search').value = state.lutQuery;
    let items;
    if (state.step === 3) {
        const available = [...state.presets];
        if (!available.some((item) => item.id === session.selected_lut && item.version === session.lut_version)) available.unshift(session.lut);
        items = available.filter((item) => item.name.toLocaleLowerCase('vi').includes(state.lutQuery.toLocaleLowerCase('vi'))).map((preset) => ({ ...preset, image:preset.sample_url, attr:'data-lut-id', detail:`${preset.intensity}%${preset.readonly ? '' : ` · v${preset.version}`}`, selected:preset.id === session.selected_lut && preset.version === session.lut_version }));
    } else if (state.step === 1) {
        items = state.layouts.filter((layout) => layout.id === session.layout_id || state.templates.some((template) => template.layout_id === layout.id)).map((layout) => ({ id: layout.id, name: layout.name, detail: `${layout.capture_count} ảnh`, image: layout.thumbnail, attr: 'data-layout-id', selected: layout.id === session.layout_id }));
    } else {
        items = state.templates.filter((item) => item.layout_id === session.layout_id).map((item) => ({ ...item, image: item.preview_url, detail: item.text_slots ? '' : 'Khung cố định', attr: 'data-template-id', selected: item.id === session.template_id && item.version === session.template_version }));
    }
    $('selection-cards').className = `selection-cards ${state.step === 1 ? 'layout-cards' : state.step === 4 ? 'template-cards' : 'filter-cards'}`;
    $('selection-cards').innerHTML = items.map((item) => `<button type="button" class="choice-card ${item.selected ? 'selected' : ''}" ${item.attr}="${esc(item.id)}" ${item.version ? `data-version="${esc(item.version)}"` : ''} ${item.style ? `data-style="${esc(item.style)}"` : ''} aria-pressed="${Boolean(item.selected)}" ${state.busy ? 'disabled' : ''}><div class="choice-image"><img src="${esc(item.image)}" alt=""><span class="choice-check" aria-hidden="true">✓</span></div><strong>${esc(item.name)}</strong>${item.detail ? `<small>${esc(item.detail)}</small>` : ''}</button>`).join('');
    $('selection-cards').querySelectorAll('button').forEach((button) => button.addEventListener('click', () => run(async () => {
        const update = button.dataset.lutId ? { lut_id: button.dataset.lutId, lut_version:button.dataset.version } : button.dataset.layoutId ? { layout_id: button.dataset.layoutId } : { template_id: button.dataset.templateId, template_version: button.dataset.version };
        await configure(update);
    })));
    const beautyBox = $('beauty-settings');
    if (beautyBox) {
        beautyBox.hidden = state.step !== 3;
        if (state.step === 3 && session.beauty) {
            const currentPreset = session.beauty.preset || 'natural';
            document.querySelectorAll('.beauty-preset-btn').forEach((btn) => {
                const p = btn.dataset.beautyPreset;
                btn.classList.toggle('active', p === currentPreset);
                btn.disabled = state.busy;
            });
            if ($('val-smooth')) $('val-smooth').textContent = `${session.beauty.smooth}%`;
            if ($('range-smooth')) {
                $('range-smooth').value = session.beauty.smooth;
                $('range-smooth').disabled = state.busy;
            }
            if ($('val-brighten')) $('val-brighten').textContent = `${session.beauty.brighten}%`;
            if ($('range-brighten')) {
                $('range-brighten').value = session.beauty.brighten;
                $('range-brighten').disabled = state.busy;
            }
            if ($('val-tone')) $('val-tone').textContent = `${session.beauty.tone}%`;
            if ($('range-tone')) {
                $('range-tone').value = session.beauty.tone;
                $('range-tone').disabled = state.busy;
            }
        }
    }
    if (!$('selection-preview-panel').hidden) renderCapturedPreview(session);
}

function renderCapturedPreview(session)
{
    const layout = layoutFor(session);
    const template = templateFor(session);
    const key = JSON.stringify([
        session.id, layout?.id, template?.id, template?.version,
        session.selected_lut, session.lut_version, session.lut_intensity,
        session.slots.map((slot) => slot.photo.id),
    ]);
    if (state.selectionPreviewKey === key) return;
    state.selectionPreviewKey = key;
    const preview = $('selection-preview');
    const status = $('selection-preview-status');
    preview.hidden = true;
    status.hidden = false;
    status.textContent = 'Đang ghép ảnh xem trước…';
    paintSample(preview, layout, template, session.lut, session)
        .then((painted) => {
            if (!painted || state.selectionPreviewKey !== key) return;
            preview.hidden = false;
            status.hidden = true;
        })
        .catch(() => {
            if (state.selectionPreviewKey !== key) return;
            state.selectionPreviewKey = null;
            status.textContent = 'Không tải được ảnh xem trước. Hãy thử chọn lại màu hoặc khung.';
        });
}

async function configure(update)
{
    const updated = await api('POST', `/sessions/${state.session.id}/configuration`, { revision: state.session.revision, ...update });
    state.session = updated;
    if (updated.template_fallback) toast('Không có mẫu cùng phong cách. Đã chọn mẫu mặc định cho bố cục mới.');
}

function loadImage(url)
{
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error('Không tải được tài nguyên ảnh.'));
        image.src = url;
    });
}

function previewLettering(template, session, frame)
{
    const text = session?.event?.frame || frame;
    if (!template.text_slots || !text || !Object.values(text).some(Boolean)) return null;
    const key = session ? `${session.id}:${template.id}:${template.version}` : null;
    if (key && frameLayerCache.has(key)) return frameLayerCache.get(key);
    const request = { template_id: template.id, template_version: template.version, ...(session ? { session_id: session.id } : { frame }) };
    const pending = api('POST', '/frame-text/preview', request)
        .then((result) => result.url ? loadImage(result.url) : null)
        .catch((error) => { if (key) frameLayerCache.delete(key); throw error; });
    if (key) frameLayerCache.set(key, pending);
    return pending;
}

async function paintSample(canvas, layout, template, lut = 'original', session = null, frame = null)
{
    if (!layout || !template) throw new Error('Chưa có template tương thích.');
    const generation = String(++state.sampleGeneration);
    canvas.dataset.generation = generation;
    const preset = typeof lut === 'string' ? state.presets.find((item) => item.id === lut) : lut;
    const captured = canvas === $('selection-preview');
    const [photos, overlay, background, cube, lettering] = await Promise.all([
        captured ? Promise.all(session.slots.map((slot) => loadImage(slot.photo.url))) : loadImage(preset.sample_url).then((photo) => layout.slots.map(() => photo)),
        loadImage(template.overlay_url),
        template.background_url ? loadImage(template.background_url) : Promise.resolve(null),
        captured ? getCubeLut(session) : Promise.resolve(null),
        previewLettering(template, session, frame),
    ]);
    if (canvas.dataset.generation !== generation) return false;
    if (cube) {
        const filterCanvas = document.createElement('canvas');
        const filter = new LutCanvasRenderer(filterCanvas);
        for (let index = 0; index < photos.length; index++) {
            if (filter.render(photos[index], cube, session.lut_intensity / 100)) {
                const copy = document.createElement('canvas');
                copy.width = filterCanvas.width; copy.height = filterCanvas.height;
                copy.getContext('2d').drawImage(filterCanvas, 0, 0);
                photos[index] = copy;
            }
        }
        // This short-lived renderer is only for the paper preview.
        filter.gl?.getExtension('WEBGL_lose_context')?.loseContext();
    }
    const context = canvas.getContext('2d');
    context.fillStyle = 'white';
    context.fillRect(0, 0, 1181, 1748);
    if (background) context.drawImage(background, 0, 0, 1181, 1748);
    for (const [index, slot] of layout.slots.entries()) {
        const photo = photos[index];
        const scale = Math.max(slot.width / photo.width, slot.height / photo.height);
        const width = slot.width / scale, height = slot.height / scale;
        context.drawImage(photo, (photo.width - width) / 2, (photo.height - height) / 2, width, height, slot.x, slot.y, slot.width, slot.height);
    }
    context.drawImage(overlay, 0, 0, 1181, 1748);
    if (lettering) context.drawImage(lettering, 0, 0, 1181, 1748);
    return true;
}

function renderCapture()
{
    $('capture-panel').hidden = false;
    const session = state.session;
    $('capture-title').textContent = `Chụp ${session.capture_count} ảnh liên tiếp`;
    $('capture-progress').textContent = `${session.accepted_slots} / ${session.capture_count} ảnh${session.configuration_locked ? ' · Bố cục đã khóa' : ''}`;
    $('slots-container').innerHTML = session.slots.map((slot) => `<div class="slot-card"><div class="slot-thumb-box">${slot.photo ? `<img src="${esc(slot.photo.url)}" data-raw-src="${esc(slot.photo.url)}" alt="Ảnh ${slot.slot}">` : '<span aria-hidden="true">◎</span>'}</div><div><strong>Ảnh ${slot.slot}</strong><small>${slot.photo ? `Đã chụp · v${slot.photo.version}` : 'Chưa chụp'}</small><button type="button" class="btn-slot-action" data-slot="${slot.slot}" ${state.busy ? 'disabled' : ''}>${slot.photo ? 'Chụp lại ảnh này' : 'Chụp khung này'}</button></div></div>`).join('');
    $('slots-container').querySelectorAll('[data-slot]').forEach((button) => button.addEventListener('click', () => run(() => capture(Number(button.dataset.slot)))));
    $('btn-auto-capture-all').disabled = state.busy || allCaptured();
    $('btn-auto-capture-all').textContent = session.accepted_slots ? 'Chụp các ảnh còn lại' : 'Bắt đầu chụp';
    $('countdown-overlay').hidden = !state.job;
    if (state.job) {
        $('countdown-number').textContent = state.job.phase?.startsWith('countdown-') ? state.job.phase.split('-')[1] : '…';
        $('countdown-label').textContent = state.job.phase?.startsWith('countdown') ? 'Sẵn sàng tạo dáng nhé!' : 'Đang nhận ảnh…';
    }
    for (const id of ['btn-webcam-enable', 'btn-webcam-switch', 'btn-webcam-mirror', 'btn-webcam-stop', 'webcam-device']) $(id).disabled = state.busy;
    for (const id of ['btn-dcc-enable', 'btn-dcc-stop']) $(id).disabled = state.busy;
    updateCropGuide();
}

async function getCubeLut(session)
{
    if (!session || !session.selected_lut || session.selected_lut === 'original' || !session.lut_intensity) return null;
    const key = `${session.selected_lut}/${session.lut_version}/${session.lut?.sha256 || ''}`;
    if (lutCache.has(key)) return lutCache.get(key);
    const promise = fetch(`/custom/api/catalog/luts/${encodeURIComponent(session.selected_lut)}/${encodeURIComponent(session.lut_version)}/filter.cube`, { credentials: 'same-origin' })
        .then((res) => (res.ok ? res.text() : ''))
        .then((text) => (text ? parseCubeLut(text) : null))
        .catch(() => null);
    lutCache.set(key, promise);
    return promise;
}

async function syncCaptureLut(session = state.session)
{
    if (!liveViewVisible()) { $('webcam-lut-canvas').hidden = true; return; }
    const lutData = await getCubeLut(session);
    if (state.session !== session || !liveViewVisible()) return;
    browserCamera.setFilter(lutData, session.lut_intensity, session.lut?.name || session.selected_lut);
    const lutCanvas = $('webcam-lut-canvas');
    if (!browserMode && lutCanvas) {
        lutCanvas.classList.remove('mirrored');
        const mockImg = $('live-preview-img');
        if (lutData && session.lut_intensity > 0 && mockImg && !mockImg.hidden && mockImg.complete && mockImg.naturalWidth > 0) {
            if (browserCamera.lutRenderer?.render(mockImg, lutData, session.lut_intensity / 100)) {
                lutCanvas.hidden = false;
            }
        } else {
            lutCanvas.hidden = true;
        }
    }
    if (lutData && session.lut_intensity > 0) {
        if (!thumbLutCanvas) {
            thumbLutCanvas = document.createElement('canvas');
            thumbLutRenderer = new LutCanvasRenderer(thumbLutCanvas);
        }
        const tag = `${session.selected_lut}:${session.lut_version}:${session.lut_intensity}`;
        $('slots-container').querySelectorAll('img[data-raw-src]').forEach((img) => {
            const applyThumb = () => {
                if (img.dataset.lutApplied === tag || !img.naturalWidth) return;
                if (thumbLutRenderer.render(img, lutData, session.lut_intensity / 100)) {
                    img.dataset.lutApplied = tag;
                    img.src = thumbLutCanvas.toDataURL('image/jpeg', 0.9);
                }
            };
            if (img.complete && img.naturalWidth > 0) applyThumb();
            else img.addEventListener('load', applyThumb, { once: true });
        });
    }
}

function updateCropGuide()
{
    fitBrowserPreview();
    const empty = state.captureSlot || state.session?.slots.find((slot) => !slot.photo)?.slot || 1;
    const slot = layoutFor()?.slots.find((item) => item.slot === empty);
    if (slot) {
        const ratio = slot.width / slot.height;
        const source = browserMode ? $('webcam-video') : $('live-preview-img');
        const sourceWidth = source.videoWidth || source.naturalWidth || 2;
        const sourceHeight = source.videoHeight || source.naturalHeight || 1;
        const box = $('crop-guide').parentElement;
        if (!box.clientWidth || !box.clientHeight) return;
        const scale = Math.min(box.clientWidth / sourceWidth, box.clientHeight / sourceHeight);
        const width = sourceWidth * scale, height = sourceHeight * scale;
        const cropWidth = Math.min(width, height * ratio);
        const cropHeight = Math.min(height, width / ratio);
        $('crop-guide').style.width = `${cropWidth / box.clientWidth * 100}%`;
        $('crop-guide').style.height = `${cropHeight / box.clientHeight * 100}%`;
    }
}

function fitBrowserPreview()
{
    if (!liveViewVisible() || $('workspace-view').hidden) return;
    const box = $('crop-guide').parentElement;
    if (window.innerWidth <= 800) {
        box.style.removeProperty('--camera-preview-height');
        return;
    }
    const column = box.parentElement;
    if (!column.clientWidth) return;
    const footer = $('wizard-footer');
    const footerStyle = getComputedStyle(footer);
    const workspaceStyle = getComputedStyle($('workspace-view'));
    const boxStyle = getComputedStyle(box);
    const borders = parseFloat(boxStyle.borderTopWidth) + parseFloat(boxStyle.borderBottomWidth);
    const contentWidth = column.clientWidth - parseFloat(boxStyle.borderLeftWidth) - parseFloat(boxStyle.borderRightWidth);
    const source = browserMode ? $('webcam-video') : $('live-preview-img');
    const sourceWidth = source.videoWidth || source.naturalWidth;
    const sourceHeight = source.videoHeight || source.naturalHeight;
    const ratio = sourceWidth && sourceHeight ? sourceWidth / sourceHeight : 16 / 9;
    const fullHeight = contentWidth / ratio + borders;
    const controlsHeight = column.offsetHeight - box.offsetHeight;
    const footerHeight = footer.offsetHeight + parseFloat(footerStyle.marginTop) + parseFloat(footerStyle.marginBottom);
    const panelTop = $('liveview-panel').getBoundingClientRect().top;
    const available = window.innerHeight - panelTop - controlsHeight - footerHeight - parseFloat(workspaceStyle.paddingBottom) - 8;
    // Short or zoomed viewports may scroll; keep a useful viewfinder size.
    const height = Math.max(Math.min(320, fullHeight), Math.min(fullHeight, available));
    box.style.setProperty('--camera-preview-height', `${Math.floor(height)}px`);
}

async function waitJob(id)
{
    const sessionId = state.session.id;
    const limit = state.session.active_job?.type === 'render' ? 800 : 240;
    for (let attempt = 0; attempt < limit; attempt++) {
        let job;
        try {
            job = await api('GET', `/jobs/${id}`);
        } catch (error) {
            if (!(error instanceof TypeError) && ![502, 503, 504].includes(error.status)) throw error;
            await sleep(500);
            continue;
        }
        state.job = job;
        render();
        if (job.status === 'SUCCEEDED' || job.status === 'FAILED') {
            await loadSession(sessionId);
            state.job = null;
            if (job.status === 'FAILED') {
                state.renderError = job.error?.message || 'Tác vụ thất bại.';
                throw new Error(state.renderError);
            }
            return job;
        }
        await sleep(250);
    }
    throw new Error('Chưa nhận được kết quả worker. Tải lại trang để tiếp tục theo dõi, không tự chụp lại.');
}

async function capture(slot)
{
    const source = state.session.slots.find((item) => item.slot === slot);
    const operation = source.photo ? 'retake' : 'capture';
    const sessionId = state.session.id;
    const revision = state.session.revision;
    let payload = { revision };
    state.captureSlot = slot;
    try {
        if (browserMode) {
            if (!browserCamera.ready()) throw new Error('Bật camera và cho phép truy cập trước khi chụp.');
            for (let remaining = Number(document.body.dataset.countdown || 3); remaining > 0; remaining--) {
                if (!browserCamera.ready() || document.hidden) throw new Error('Camera bị ngắt hoặc màn hình đã ẩn. Bật lại camera rồi chụp lại.');
                state.job = { phase: `countdown-${remaining}` };
                render();
                await sleep(1000);
            }
            const blob = await browserCamera.photo();
            payload = new FormData();
            payload.append('revision', String(revision));
            payload.append('file', blob, 'camera.jpg');
        }
    } finally {
        state.job = null;
        state.captureSlot = null;
    }
    const job = await api('POST', `/sessions/${sessionId}/slots/${slot}/${operation}`, payload);
    await loadSession(state.session.id);
    await waitJob(job.job_id);
}

async function renderCollage(retryLkMagic = false)
{
    state.renderError = '';
    state.previewReady = false;
    state.previewKey = null;
    const job = await api('POST', `/sessions/${state.session.id}/render`, { revision: state.session.revision, retry_lkmagic: retryLkMagic });
    await loadSession(state.session.id);
    await waitJob(job.job_id);
}

function renderReview()
{
    $('review-panel').hidden = false;
    const session = state.session;
    const final = session.current_final;
    const renderData = session.current_render;
    const isFinal = session.state === 'FINALIZED' && final;
    const preview = isFinal ? final : renderData;
    const url = isFinal ? final.url : renderData?.preview_url;
    const key = preview ? `${session.id}:${preview.id}:${isFinal ? 'final' : 'review'}` : null;
    if (key !== state.previewKey) {
        state.previewKey = key;
        state.previewReady = false;
        $('collage-preview-img').hidden = true;
        $('review-placeholder').hidden = false;
        if (url) {
            loadImage(url).then((image) => {
                if (state.previewKey !== key) return;
                $('collage-preview-img').src = image.src;
                $('collage-preview-img').hidden = false;
                $('review-placeholder').hidden = true;
                state.previewReady = true;
                updateApproval();
            }).catch(() => {
                if (state.previewKey !== key) return;
                state.renderError = 'Không tải được bản xem trước. Hãy tải lại trang trước khi duyệt.';
                updateApproval();
            });
        }
    }
    $('post-finalize-actions').hidden = !isFinal;
    $('btn-close-session').hidden = !isFinal;
    $('btn-review-retake').disabled = state.busy || session.print_summary.has_active || session.print_summary.has_unknown;
    $('btn-retry-render').hidden = Boolean(preview) || Boolean(session.active_job);
    $('btn-retry-render').disabled = state.busy;
    $('btn-retry-lkmagic').hidden = session.state !== 'REVIEW' || renderData?.beauty_mode !== 'fallback';
    $('btn-retry-lkmagic').disabled = state.busy;
    updatePrintControls(session);
    $('btn-finalize-session').hidden = Boolean(isFinal);
    $('btn-close-session').disabled = state.busy;
    renderShare();
    renderPrints(session);
    updateApproval();
}

function updateApproval()
{
    const session = state.session;
    $('btn-finalize-session').disabled = state.busy || !state.previewReady || session?.state !== 'REVIEW' || !session.current_render || Boolean(session.active_job);
    $('review-error').hidden = !state.renderError;
    $('review-error').textContent = state.renderError;
    $('render-status-text').textContent = session?.state === 'FINALIZED' ? 'Ảnh đã duyệt. In và QR dùng đúng ảnh này.' : state.renderError || (state.previewReady ? 'Kiểm tra ảnh rồi nhấn Duyệt ảnh và tạo QR.' : 'Đang chờ bản xem trước…');
    if (session?.current_render?.beauty_mode === 'fallback') $('render-status-text').textContent += ' Bộ ảnh này dùng Beauty dự phòng (MediaPipe).';
    if (!session?.current_render && session?.active_job && session.beauty?.enabled) {
        const progress = state.job?.beauty_progress;
        $('render-status-text').textContent = progress
            ? progress.done === progress.total ? 'Đang ghép ảnh…' : 'Đang làm đẹp ' + progress.done + '/' + progress.total + ' ảnh…'
            : 'Đang làm đẹp và ghép ảnh…';
    }
    if (session?.current_render?.beauty_warnings?.length) $('render-status-text').textContent += ' ⚠ Beauty gặp lỗi ở một số ảnh; đã giữ ảnh nguồn và tiếp tục ghép. Kiểm tra kỹ trước khi duyệt.';
}

async function issueShare(session = state.session)
{
    state.share = await api('POST', `/sessions/${session.id}/shares`, { revision: session.revision, final_asset_id: session.current_final.id });
    await loadSession(session.id);
    renderShare();
}

function renderShare()
{
    const share = state.share;
    $('modal-qr-img').hidden = !share;
    $('qr-placeholder').hidden = Boolean(share);
    $('btn-revoke-qr').hidden = !share;
    $('modal-qr-url').textContent = share?.url || '';
    $('modal-qr-expires').textContent = share ? `Hết hạn: ${new Date(share.expires_at).toLocaleString('vi-VN')}` : '';
    $('modal-qr-open-link').hidden = !share;
    if (share) {
        $('modal-qr-img').src = share.qr_data_uri;
        $('modal-qr-open-link').href = new URL(share.url).pathname;
    }
}

function quotaLabel(quota)
{
    if (!quota) return '';
    const mode = quota.mode === 'mock' ? 'In mô phỏng · ' : '';
    const total = quota.limit === null ? quota.printed : quota.printed + '/' + quota.limit;
    const pending = quota.reserved ? ' · Đang xử lý/đối chiếu ' + quota.reserved : '';
    const remaining = quota.remaining === null ? '' : ' · Còn có thể in ' + quota.remaining;
    return mode + 'Đã in ' + total + ' bản' + pending + remaining;
}

function maxCopies(quota)
{
    return Math.min(10, quota?.remaining ?? 10);
}

function validCopies(copies, quota)
{
    return Number.isInteger(copies) && copies >= 1 && copies <= maxCopies(quota);
}

function updatePrintControls(session)
{
    const quota = session.print_quota;
    const max = maxCopies(quota);
    const copies = state.copies;
    const counter = $('print-quota');
    counter.textContent = quotaLabel(quota);
    counter.classList.toggle('alert', quota?.remaining != null && (quota.remaining <= 10 || quota.remaining <= quota.limit * 0.1));
    const input = $('print-copies');
    input.max = Math.max(1, max);
    input.disabled = state.busy || !max;
    if (document.activeElement !== input) input.value = copies || '';
    $('print-minus').disabled = input.disabled || copies <= 1;
    $('print-plus').disabled = input.disabled || copies >= max;
    const final = session.current_final;
    const action = final && printAction(session, final.id) === 'reprint' ? 'In lại' : 'In ảnh';
    $('btn-open-print-modal').textContent = max ? action : 'Hết lượt in';
    $('btn-open-print-modal').disabled = input.disabled || !validCopies(copies, quota);
}

function renderPrints(session)
{
    $('session-prints-list').innerHTML = session.print_summary.requests.length ? session.print_summary.requests.map((item) => `<div class="print-item"><span>${item.action === 'print' ? 'In' : 'In lại'} · ${item.copies} bản · <strong>${esc(item.status)}</strong>${item.status === 'COMPLETED' && item.completed_copies < item.copies ? ' · Hoàn tất ' + item.completed_copies + ' bản' : ''}</span>${item.status === 'UNKNOWN' && state.user.role === 'admin' ? `<button type="button" class="btn-resolve-print" data-print="${item.id}">Đối chiếu UNKNOWN</button>` : ''}</div>`).join('') : '<p class="muted">Chưa có lệnh in.</p>';
    $('session-prints-list').querySelectorAll('[data-print]').forEach((button) => button.addEventListener('click', () => openResolve(session, button.dataset.print)));
}

function updatePrintModal()
{
    const context = state.print;
    if (!context) return;
    const quota = context.session.print_quota;
    $('modal-print-quota').textContent = quotaLabel(quota);
    $('modal-print-copies').max = Math.max(1, maxCopies(quota));
    $('modal-print-action-label').textContent = context.action === 'reprint' ? 'In lại' : 'In lần đầu';
    $('modal-print-title').textContent = 'Xác nhận in' + (context.copies ? ' ' + context.copies + ' bản' : '');
    $('btn-confirm-print-submit').disabled = !validCopies(context.copies, quota) || state.busy;
}

function openPrint(session = state.session, asset = session?.current_final, fromHistory = false)
{
    if (!session || !asset || !['FINALIZED', 'CLOSED'].includes(session.state)) return;
    const copies = fromHistory ? 1 : state.copies;
    state.print = { session, asset, key: uuid(), action: fromHistory ? 'reprint' : printAction(session, asset.id), copies };
    $('modal-print-thumb').src = asset.url;
    $('modal-print-session-code').textContent = code(session.id);
    $('modal-print-copies').value = copies;
    $('modal-print-copies-wrap').hidden = !fromHistory;
    $('modal-print-unknown-warning').hidden = !session.print_summary.has_unknown;
    $('modal-print-confirm').hidden = false;
    updatePrintModal();
}

async function submitPrint()
{
    const context = state.print;
    if (!context || !validCopies(context.copies, context.session.print_quota)) return;
    state.print = null;
    $('modal-print-confirm').hidden = true;
    const result = await api('POST', '/sessions/' + context.session.id + '/prints', {
        revision: context.session.revision, final_asset_id: context.asset.id,
        copies: context.copies, action: context.action,
    }, context.key);
    // Refresh as soon as the reservation is accepted. The worker may still be printing.
    if (state.session?.id === context.session.id) {
        await loadSession(context.session.id);
        state.copies = 1;
    }
    if (state.view === 'history') await loadHistory();
    toast('Đã gửi lệnh ' + (result.mode === 'mock' ? 'in mô phỏng.' : 'in.'));
}

function updateResolveCopies()
{
    $('resolve-copies-wrap').hidden = $('resolve-outcome').value !== 'observed_completed';
}

function openResolve(session, id)
{
    const request = session.print_summary.requests.find((item) => item.id === id);
    state.resolve = { session, id };
    $('resolve-print-id-display').textContent = id;
    $('resolve-note').value = '';
    $('resolve-outcome').value = 'observed_completed';
    $('resolve-copies').max = request.copies;
    $('resolve-copies').value = request.copies;
    updateResolveCopies();
    $('modal-resolve-unknown').hidden = false;
}

async function loadHistory(append = false)
{
    const result = await api('GET', `/sessions?limit=20${append && state.cursor ? `&cursor=${encodeURIComponent(state.cursor)}` : ''}`);
    state.history = append ? [...state.history, ...result.items] : result.items;
    state.cursor = result.next_cursor;
    $('btn-history-load-more').hidden = !state.cursor;
    $('history-table-body').innerHTML = state.history.map((session) => `<tr><td>${code(session.id)}</td><td>${esc(labels[session.state])}</td><td>${esc(layoutFor(session)?.name || session.layout_id)}</td><td>${session.accepted_slots}/${session.capture_count}</td><td>Đã in: ${session.print_summary.requests.filter((item) => item.status === 'COMPLETED').length}${session.print_summary.has_unknown ? ' · UNKNOWN' : ''}</td><td><button class="btn-history-detail" data-session="${session.id}" type="button">Chi tiết</button></td></tr>`).join('');
    $('history-table-body').querySelectorAll('[data-session]').forEach((button) => button.addEventListener('click', () => run(() => openDetail(button.dataset.session))));
}

async function openDetail(id)
{
    const session = await api('GET', `/sessions/${id}`);
    state.detail = session;
    $('history-detail-title').textContent = `${code(id)} · ${labels[session.state]}`;
    $('modal-history-body').innerHTML = session.final_assets.length ? `<label>Ảnh đã duyệt<select id="history-final-select">${session.final_assets.map((asset) => `<option value="${asset.id}">${esc(new Date(asset.created_at).toLocaleString('vi-VN'))} · ${asset.id.slice(0, 8)}</option>`).join('')}</select></label><img id="history-final-preview" class="print-thumb" src="${esc(session.final_assets[0].url)}" alt="Ảnh cuối trong lịch sử">` : '<p>Phiên chưa có ảnh cuối.</p>';
    const select = $('history-final-select');
    if (select) select.addEventListener('change', () => { $('history-final-preview').src = session.final_assets.find((item) => item.id === select.value).url; });
    const unresolved = session.print_summary.requests.filter((item) => item.status === 'UNKNOWN');
    if (state.user.role === 'admin') {
        for (const request of unresolved) {
            const button = document.createElement('button');
            button.textContent = 'Đối chiếu UNKNOWN';
            button.addEventListener('click', () => openResolve(session, request.id));
            $('modal-history-body').append(button);
        }
    }
    $('btn-detail-reprint').hidden = !session.final_assets.length || !['FINALIZED','CLOSED'].includes(session.state);
    $('btn-detail-share').hidden = $('btn-detail-reprint').hidden;
    $('btn-detail-admin-delete').hidden = state.user.role !== 'admin';
    $('modal-history-detail').hidden = false;
}

function settingsFrame()
{
    return Object.fromEntries(['name', 'date', 'wish'].map((field) => [field, $('event-frame-' + field).value.trim()]));
}

function updateEventPreviews()
{
    const personalized = Object.values(settingsFrame()).some(Boolean);
    const category = $('event-category').value;
    const selector = $('event-preview-template');
    const previous = selector.value;
    state.eventPreviews = state.settingsCatalog[personalized ? 1 : 0]
        .filter((item) => category === 'all' || item.category === category);
    selector.replaceChildren(...state.eventPreviews.map((item) => new Option(item.name + (item.text_slots ? '' : ' · Khung cố định'), item.id)));
    if (state.eventPreviews.some((item) => item.id === previous)) selector.value = previous;
    $('btn-event-preview').disabled = !state.eventPreviews.length;
    $('event-frame-preview').hidden = true;
}

function updateEventLimit()
{
    const unlimited = $('event-no-limit').checked;
    const limit = $('event-print-limit');
    limit.disabled = unlimited;
    limit.required = !unlimited;
}

function showBrandImage(kind, url)
{
    const img = $('event-' + kind + '-preview');
    img.hidden = !url;
    if (url) img.src = url;
    else img.removeAttribute('src');
}

function applySettings(settings)
{
    state.settings = settings;
    const config = settings.configuration;
    for (const field of ['name', 'footer', 'color', 'category']) $('event-' + field).value = config[field];
    for (const field of ['name', 'date', 'wish']) $('event-frame-' + field).value = config.frame[field];
    $('event-ttl').value = config.qr_ttl_hours;
    $('event-no-limit').checked = settings.quota.limit === null;
    $('event-print-limit').value = settings.quota.limit ?? '';
    $('event-quota').textContent = quotaLabel(settings.quota);
    for (const kind of ['logo', 'banner']) {
        $('event-' + kind).value = '';
        $('event-' + kind + '-clear').checked = false;
        showBrandImage(kind, settings[kind + '_url']);
    }
    updateEventLimit();
    updateEventPreviews();
}

async function loadSettings()
{
    const [settings, classic, personalized] = await Promise.all([
        api('GET', '/admin/settings'),
        api('GET', '/templates?category=all'),
        api('GET', '/templates?category=all&personalized=1'),
    ]);
    state.settingsCatalog = [classic.items, personalized.items];
    applySettings(settings);
    state.view = 'settings';
}

function readBrandFile(file)
{
    if (file.size > 512 * 1024 || !['image/png', 'image/jpeg'].includes(file.type)) {
        throw new Error('Logo và banner phải là PNG hoặc JPEG, tối đa 512 KiB.');
    }
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = () => reject(new Error('Không đọc được ảnh sự kiện.'));
        reader.readAsDataURL(file);
    });
}

async function saveSettings(newEvent)
{
    const settings = state.settings;
    const body = {
        id: settings.id, revision: settings.revision, new_event: newEvent,
        name: $('event-name').value, footer: $('event-footer').value, color: $('event-color').value,
        category: $('event-category').value, qr_ttl_hours: $('event-ttl').valueAsNumber, frame: settingsFrame(),
        print_limit: $('event-no-limit').checked ? null : $('event-print-limit').valueAsNumber,
    };
    for (const kind of ['logo', 'banner']) {
        const file = $('event-' + kind).files[0];
        if ($('event-' + kind + '-clear').checked) body[kind] = null;
        else if (file) body[kind] = await readBrandFile(file);
    }
    try {
        applySettings(await api('POST', '/admin/settings', body));
        toast(newEvent ? 'Đã tạo sự kiện mới.' : 'Đã lưu cài đặt sự kiện.');
    } catch (error) {
        if (error.code === 'EVENT_SETTINGS_LOCKED') state.settings.locked = true;
        throw error;
    }
}

async function loadAdmin()
{
    const result = await api('GET', '/admin/templates');
    $('admin-template-list').innerHTML = result.items.map((item, index) => `<article class="panel admin-template"><img src="${esc(item.preview_url)}" alt=""><h2>${esc(item.name)}</h2><p>${esc(layoutFor({ layout_id: item.layout_id })?.name)} · ${esc(item.version)} · ${esc(item.status)}</p><button type="button" data-preview="${index}">Xem thử / kích hoạt</button>${item.status === 'active' ? `<button type="button" data-disable="${index}">Tắt mẫu</button>` : ''}</article>`).join('');
    $('admin-template-list').querySelectorAll('[data-preview]').forEach((button) => button.addEventListener('click', () => run(async () => {
        const item = result.items[Number(button.dataset.preview)];
        state.templatePreview = item;
        $('template-preview-title').textContent = `${item.name} · ${item.layout_id} · ${item.version}`;
        $('modal-template-preview').hidden = false;
        $('btn-activate-template').disabled = true;
        const painted = await paintSample($('admin-preview'), layoutFor({ layout_id: item.layout_id }), item);
        $('btn-activate-template').disabled = !painted;
    })));
    $('admin-template-list').querySelectorAll('[data-disable]').forEach((button) => button.addEventListener('click', () => run(async () => {
        const item = result.items[Number(button.dataset.disable)];
        await api('POST', `/admin/templates/${item.id}/${item.version}/disable`, {});
        await loadAdmin();
        state.templates = (await api('GET', '/templates')).items;
    })));
}

const beautyLabels = {
    lkmagic_native: 'LK Magic native',
    mediapipe: 'MediaPipe',
    fallback: 'MediaPipe dự phòng',
    off: 'Beauty tắt',
    error: 'Beauty lỗi',
    unknown: 'Chưa xác định',
};

function beautyHealth(result)
{
    const beauty = result.beauty_engine;
    const labels = { ready: 'Sẵn sàng', starting: 'Đang khởi động', unavailable: 'Không kết nối được' };
    const dateTime = (value) => value ? new Date(value).toLocaleString('vi-VN') : '';
    const bridge = beauty.bridge;
    $('health-lkmagic').textContent = 'LK Magic: ' + labels[bridge.status] + ' · ' + dateTime(bridge.checked_at);
    const check = beauty.diagnostic;
    diagnosticPending = ['queued', 'running'].includes(check?.status);
    const checkLabel = {
        queued: 'Đang chờ bộ xử lý',
        running: 'Đang chạy',
        passed: 'Đạt',
        failed: 'Thất bại',
    };
    $('health-beauty-test').textContent = 'Ảnh thử: ' + (checkLabel[check?.status] || 'Chưa chạy')
        + (check?.completed_at ? ' · ' + dateTime(check.completed_at) + ' · ' + (check.duration_ms / 1000).toFixed(1) + ' giây' : '')
        + (check?.error ? ' · ' + check.error : '');
    $('btn-beauty-test').disabled = diagnosticPending || diagnosticRequest;
    const last = beauty.last_render;
    $('health-last-beauty').textContent = 'Lần xuất gần nhất: ' + (last
        ? beautyLabels[last.mode] + ' · ' + dateTime(last.completed_at)
        : 'Chưa có lần xuất');
}

let diagnosticPending = false;
let diagnosticRequest = false;

async function health()
{
    if (!state.user) return;
    try {
        const result = await api('GET', '/health');
        $('health-summary').textContent = `Worker: ${result.worker.status} · Camera: ${result.camera.camera_state} · Máy in: ${result.printer.queue_state} · Trống: ${result.disk.free_gib} GiB`;
        beautyHealth(result);
        $('health-warnings').replaceChildren(...result.warnings.map((warning) => { const p = document.createElement('p'); p.textContent = warning; return p; }));
    } catch (error) {
        $('health-summary').textContent = error.message;
        if (error.status === 401) signOut();
    }
}

$('login-form').addEventListener('submit', (event) => {
    event.preventDefault();
    run(async () => {
        const result = await api('POST', '/auth/login', { username: $('login-username').value, password: $('login-password').value });
        state.user = result.user;
        state.csrf = result.csrf_token;
        $('login-password').value = '';
        await enter();
    });
});
$('btn-logout').addEventListener('click', () => run(async () => { await api('POST', '/auth/logout', {}); signOut(); }));
$('btn-new-session').addEventListener('click', () => run(async () => {
    state.session = await api('POST', '/sessions', {});
    await loadSessionTemplates();
    state.step = 1;
    state.lutQuery = '';
    state.share = null;
    state.renderError = '';
}));
$('btn-restart-session').addEventListener('click', () => {
    if (!confirm('Bắt đầu phiên mới? Bộ ảnh hiện tại sẽ được giữ trong lịch sử; các lựa chọn mới chỉ áp dụng cho phiên mới.')) return;
    run(async () => {
        state.session = await api('POST', `/sessions/${state.session.id}/restart`, { revision: state.session.revision, confirm: true });
        await loadSessionTemplates();
        state.step = 1;
        state.lutQuery = '';
        state.share = null;
        state.previewKey = null;
        state.renderError = '';
    });
});
async function goStep(next)
{
    if (!state.session || next === state.step) return;
    if (next === 1 && state.session.configuration_locked) return;
    if (next >= 3 && !allCaptured()) return;
    if (next < 5) await configure({ ui_step: next });
    state.step = next;
    render();
    if (next === 5 && !state.session.current_render) await renderCollage();
}
$('btn-next').addEventListener('click', () => run(() => goStep(state.step + 1)));
$('btn-back').addEventListener('click', () => run(() => goStep(state.step - 1)));
document.querySelectorAll('[data-step]').forEach((button) => button.addEventListener('click', () => run(() => goStep(Number(button.dataset.step)))));
$('btn-auto-capture-all').addEventListener('click', () => run(async () => {
    for (const slot of state.session.slots.map((item) => item.slot)) {
        if (!state.session.slots.find((item) => item.slot === slot).photo) await capture(slot);
    }
}));
$('btn-retry-lkmagic').addEventListener('click', () => run(() => renderCollage(true)));
$('btn-retry-render').addEventListener('click', () => run(renderCollage));
$('btn-finalize-session').addEventListener('click', () => {
    if (!state.previewReady || state.session?.state !== 'REVIEW' || state.busy) return;
    run(async () => {
        await api('POST', `/sessions/${state.session.id}/finalize`, { revision: state.session.revision, render_id: state.session.current_render.id });
        await loadSession(state.session.id);
        await issueShare();
    });
});
$('btn-review-retake').addEventListener('click', () => run(async () => {
    if (state.session.state === 'FINALIZED') {
        if (!confirm('Chụp lại sẽ tạo ảnh cuối mới sau khi duyệt. Ảnh và QR cũ vẫn được giữ.')) return;
        await api('POST', `/sessions/${state.session.id}/reopen`, { revision: state.session.revision });
        await loadSession(state.session.id);
    }
    state.step = 2;
    state.share = null;
}));
$('btn-close-session').addEventListener('click', () => run(async () => {
    await api('POST', `/sessions/${state.session.id}/close`, { revision: state.session.revision });
    state.session = null;
    state.share = null;
}));
$('print-copies').addEventListener('input', () => {
    state.copies = $('print-copies').valueAsNumber;
    updatePrintControls(state.session);
});
for (const [id, delta] of [['print-minus', -1], ['print-plus', 1]]) {
    $(id).addEventListener('click', () => {
        state.copies = Math.max(1, Math.min(maxCopies(state.session.print_quota), (state.copies || 1) + delta));
        updatePrintControls(state.session);
    });
}
$('modal-print-copies').addEventListener('input', () => {
    if (!state.print) return;
    state.print.copies = $('modal-print-copies').valueAsNumber;
    updatePrintModal();
});
$('resolve-outcome').addEventListener('change', updateResolveCopies);
$('btn-open-print-modal').addEventListener('click', () => openPrint());
$('btn-confirm-print-submit').addEventListener('click', () => run(submitPrint));
$('btn-open-qr-modal').addEventListener('click', () => run(() => issueShare()));
$('btn-revoke-qr').addEventListener('click', () => run(async () => {
    await api('POST', `/shares/${state.share.share_id}/revoke`, { revision: state.session.revision });
    state.share = null;
    await loadSession(state.session.id);
}));
$('btn-resolve-unknown-submit').addEventListener('click', () => run(async () => {
    const context = state.resolve;
    const note = $('resolve-note').value.trim();
    if (!note) throw new Error('Vui lòng nhập ghi chú đối chiếu.');
    const completed = $('resolve-outcome').value === 'observed_completed';
    if (completed && !$('resolve-copies').reportValidity()) return;
    await api('POST', '/prints/' + context.id + '/resolve', {
        revision: context.session.revision, outcome: $('resolve-outcome').value, note,
        completed_copies: completed ? $('resolve-copies').valueAsNumber : 0,
    });
    $('modal-resolve-unknown').hidden = true;
    if (state.session?.id === context.session.id) await loadSession(context.session.id);
    if (state.view === 'history') { await loadHistory(); await openDetail(context.session.id); }
}));
$('btn-nav-workspace').addEventListener('click', () => { state.view = 'workspace'; render(); });
$('btn-nav-history').addEventListener('click', () => run(async () => { state.view = 'history'; await loadHistory(); }));
$('btn-nav-admin').addEventListener('click', () => run(async () => { state.view = 'admin'; await loadAdmin(); }));
$('btn-nav-luts').addEventListener('click', () => run(async () => { state.view = 'luts'; await loadLuts(); }));
$('filter-search').addEventListener('input', () => { state.lutQuery = $('filter-search').value; renderSelection(); });
$('btn-beauty-test').addEventListener('click', async () => {
    if (diagnosticRequest || diagnosticPending) return;
    diagnosticRequest = true;
    $('btn-beauty-test').disabled = true;
    try {
        await api('POST', '/health/beauty-test', {});
    } catch (error) {
        toast(error.message, true);
    } finally {
        diagnosticRequest = false;
        await health();
        $('btn-beauty-test').disabled = diagnosticPending;
    }
});
$('btn-nav-settings').addEventListener('click', () => run(loadSettings));
$('event-no-limit').addEventListener('change', updateEventLimit);
$('event-category').addEventListener('change', updateEventPreviews);
$('event-preview-template').addEventListener('change', () => { $('event-frame-preview').hidden = true; });
for (const field of ['name', 'date', 'wish']) $('event-frame-' + field).addEventListener('change', updateEventPreviews);
$('btn-event-preview').addEventListener('click', () => run(async () => {
    const template = state.eventPreviews[$('event-preview-template').selectedIndex];
    if (!template) throw new Error('Chủ đề chưa có template đang hoạt động.');
    const canvas = $('event-frame-preview');
    await paintSample(canvas, state.layouts.find((layout) => layout.id === template.layout_id), template, 'original', null, settingsFrame());
    canvas.hidden = false;
}));
$('event-settings-form').addEventListener('submit', (event) => {
    event.preventDefault();
    const newEvent = event.submitter?.id === 'btn-event-new';
    if (newEvent && !confirm('Tạo sự kiện mới với cấu hình đang nhập và bộ đếm từ 0?')) return;
    run(() => saveSettings(newEvent));
});
for (const kind of ['logo', 'banner']) {
    $('event-' + kind).addEventListener('change', async (event) => {
        const file = event.target.files[0];
        if (!file) return;
        try {
            const url = await readBrandFile(file);
            $('event-' + kind + '-clear').checked = false;
            showBrandImage(kind, url);
        } catch (error) {
            event.target.value = '';
            showBrandImage(kind, state.settings[kind + '_url']);
            toast(error.message, true);
        }
    });
    $('event-' + kind + '-clear').addEventListener('change', (event) => {
        $('event-' + kind).value = '';
        showBrandImage(kind, event.target.checked ? null : state.settings[kind + '_url']);
    });
}
$('btn-nav-health').addEventListener('click', () => { $('health-panel').hidden = !$('health-panel').hidden; health(); });
document.querySelectorAll('.beauty-preset-btn').forEach((button) => {
    button.addEventListener('click', () => run(async () => {
        if (!state.session) return;
        const preset = button.dataset.beautyPreset;
        await configure({ beauty_preset: preset });
    }));
});
if ($('range-smooth')) {
    $('range-smooth').addEventListener('input', () => {
        $('val-smooth').textContent = `${$('range-smooth').value}%`;
    });
    $('range-smooth').addEventListener('change', () => run(async () => {
        if (!state.session) return;
        await configure({ beauty_smooth: Number($('range-smooth').value) });
    }));
}
if ($('range-brighten')) {
    $('range-brighten').addEventListener('input', () => {
        $('val-brighten').textContent = `${$('range-brighten').value}%`;
    });
    $('range-brighten').addEventListener('change', () => run(async () => {
        if (!state.session) return;
        await configure({ beauty_brighten: Number($('range-brighten').value) });
    }));
}
if ($('range-tone')) {
    $('range-tone').addEventListener('input', () => {
        $('val-tone').textContent = `${$('range-tone').value}%`;
    });
    $('range-tone').addEventListener('change', () => run(async () => {
        if (!state.session) return;
        await configure({ beauty_tone: Number($('range-tone').value) });
    }));
}
$('btn-refresh-history').addEventListener('click', () => run(() => loadHistory()));
$('btn-history-load-more').addEventListener('click', () => run(() => loadHistory(true)));
$('btn-detail-reprint').addEventListener('click', () => {
    const asset = state.detail.final_assets.find((item) => item.id === $('history-final-select').value);
    $('modal-history-detail').hidden = true;
    openPrint(state.detail, asset, true);
});
$('btn-detail-share').addEventListener('click', () => run(async () => {
    const session = state.detail;
    const assetId = $('history-final-select').value;
    const share = await api('POST', `/sessions/${session.id}/shares`, { revision: session.revision, final_asset_id: assetId });
    state.detail = await api('GET', `/sessions/${session.id}`);
    if (state.session?.id === session.id) await loadSession(session.id);
    const old = $('history-qr');
    if (old) old.remove();
    const box = document.createElement('div');
    box.id = 'history-qr';
    const image = document.createElement('img');
    image.src = share.qr_data_uri;
    image.alt = 'QR tải ảnh ghép trong lịch sử';
    image.className = 'print-thumb';
    const link = document.createElement('a');
    link.href = new URL(share.url).pathname;
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = 'Mở ảnh đã chia sẻ';
    box.append(image, link);
    $('modal-history-body').append(box);
}));
$('btn-detail-admin-delete').addEventListener('click', () => {
    if (!confirm('Xóa phiên này? Các mã QR sẽ được thu hồi.')) return;
    run(async () => {
        await api('POST', `/sessions/${state.detail.id}/delete`, { revision: state.detail.revision, confirm: true });
        $('modal-history-detail').hidden = true;
        if (state.session?.id === state.detail.id) state.session = null;
        await loadHistory();
    });
});
$('btn-import-template').addEventListener('click', () => run(async () => {
    const file = $('template-zip').files[0];
    if (!file || file.size > 30 * 1024 * 1024) throw new Error('Chọn tệp ZIP tối đa 30 MiB.');
    await api('POST', '/admin/templates/import', file);
    $('template-zip').value = '';
    await loadAdmin();
    toast('Đã nhập bản nháp. Xem thử trước khi kích hoạt.');
}));
$('btn-activate-template').addEventListener('click', () => run(async () => {
    const item = state.templatePreview;
    if (!item || $('btn-activate-template').disabled) return;
    await api('POST', `/admin/templates/${item.id}/${item.version}/activate`, {});
    $('modal-template-preview').hidden = true;
    state.templates = (await api('GET', '/templates')).items;
    await loadAdmin();
    toast('Template đã sẵn sàng cho phiên mới.');
}));
document.querySelectorAll('[data-close]').forEach((button) => button.addEventListener('click', () => {
    $(button.dataset.close).hidden = true;
    if (button.dataset.close === 'modal-print-confirm') state.print = null;
    if (button.dataset.close === 'modal-lut-editor') closeLutEditor();
}));

async function loadLuts()
{
    const result = await api('GET', '/admin/luts');
    $('admin-lut-list').innerHTML = result.items.map((item, index) => `<article class="panel admin-template lut-card" data-lut-key="${esc(item.id)}/${esc(item.version)}" data-status="${esc(item.status)}"><img src="${esc(item.sample_url)}" alt="Ảnh mẫu filter ${esc(item.name)}"><h2>${esc(item.name)}</h2><p>v${esc(item.version)} · ${item.intensity}% · ${esc(item.status)}</p><button type="button" data-lut-edit="${index}">Xem thử${item.status === 'draft' ? ' / chỉnh nháp' : ''}</button>${item.readonly ? '<p class="muted">Filter đi kèm · giữ nguyên</p>' : `${item.status !== 'draft' ? `<button type="button" data-lut-version="${index}">Tạo phiên bản nháp</button>` : ''}${item.status === 'active' ? `<button type="button" data-lut-disable="${index}">Tắt filter</button>` : ''}`}</article>`).join('');
    $('admin-lut-list').querySelectorAll('[data-lut-edit]').forEach((button) => button.addEventListener('click', () => run(async () => {
        const selected = result.items[Number(button.dataset.lutEdit)];
        const latest = (await api('GET', '/admin/luts')).items.find((item) => item.id === selected.id && item.version === selected.version);
        openLutEditor(latest);
    })));
    $('admin-lut-list').querySelectorAll('[data-lut-version]').forEach((button) => button.addEventListener('click', () => run(async () => {
        const item = result.items[Number(button.dataset.lutVersion)];
        const draft = await api('POST', `/admin/luts/${item.id}/${item.version}/versions`, { revision:item.revision });
        const records = await loadLuts();
        openLutEditor(records.find((item) => item.id === draft.id && item.version === draft.version));
    })));
    $('admin-lut-list').querySelectorAll('[data-lut-disable]').forEach((button) => button.addEventListener('click', () => run(async () => {
        const item = result.items[Number(button.dataset.lutDisable)];
        await api('POST', `/admin/luts/${item.id}/${item.version}/disable`, { revision:item.revision });
        state.presets = (await api('GET', '/presets')).presets;
        await loadLuts();
        toast('Đã tắt filter cho lựa chọn mới. Phiên đã ghim vẫn giữ nguyên.');
    })));
    return result.items;
}

function closeLutEditor()
{
    if (state.lutEditor) clearTimeout(state.lutEditor.timer);
    state.lutEditor = null;
    $('modal-lut-editor').hidden = true;
}

function openLutEditor(item)
{
    closeLutEditor();
    const editor = { item, dirty:false, busy:false, ready:false, timer:null, changes:0 };
    state.lutEditor = editor;
    $('modal-lut-editor').hidden = false;
    $('lut-editor-title').textContent = item.name;
    $('lut-editor-meta').textContent = `v${item.version} · ${item.status} · LUT 3D size 33`;
    $('lut-name').value = item.name;
    $('lut-intensity').value = item.intensity;
    $('lut-amount').textContent = `${item.intensity}%`;
    $('lut-replacement').value = '';
    const editable = item.status === 'draft' && !item.readonly;
    $('lut-name').disabled = !editable;
    $('lut-intensity').disabled = !editable;
    $('lut-replacement-wrap').hidden = !editable;
    showLutPreview(editor);
}

function updateLutButtons()
{
    const editor = state.lutEditor;
    if (!editor) return;
    $('btn-save-lut').hidden = editor.item.status !== 'draft';
    $('btn-save-lut').disabled = editor.busy;
    $('btn-activate-lut').hidden = editor.item.readonly || editor.item.status === 'active';
    $('btn-activate-lut').disabled = state.busy || editor.busy || editor.dirty || !editor.ready;
}

function showLutPreview(editor)
{
    if (state.lutEditor !== editor) return;
    const item = editor.item;
    editor.ready = false;
    $('lut-preview-state').textContent = `Đang tải ảnh mẫu ${item.intensity}%…`;
    const image = new Image();
    image.id = 'lut-after';
    image.alt = `Ảnh mẫu ${item.name}, cường độ ${item.intensity}%`;
    $('lut-after').replaceWith(image);
    image.onload = () => {
        if (state.lutEditor !== editor || editor.item.revision !== item.revision || editor.dirty || $('lut-after') !== image) return;
        editor.ready = true;
        $('lut-preview-state').textContent = `Đã tải đúng ảnh mẫu ${item.intensity}% · v${item.version}.`;
        updateLutButtons();
    };
    image.onerror = () => {
        if (state.lutEditor !== editor || $('lut-after') !== image) return;
        editor.ready = false;
        $('lut-preview-state').textContent = 'Không tải được ảnh mẫu. Hãy thử tải lại trước khi kích hoạt.';
        updateLutButtons();
    };
    image.src = item.sample_url;
    updateLutButtons();
}

function markLutDirty()
{
    const editor = state.lutEditor;
    if (!editor || editor.item.status !== 'draft') return;
    editor.dirty = true;
    editor.ready = false;
    editor.changes++;
    $('lut-amount').textContent = `${$('lut-intensity').value}%`;
    $('lut-preview-state').textContent = 'Đang chờ cập nhật ảnh mẫu…';
    updateLutButtons();
    clearTimeout(editor.timer);
    editor.timer = setTimeout(() => saveLutPreview(editor), 400);
}

async function saveLutPreview(editor)
{
    if (!editor || state.lutEditor !== editor || editor.busy) return;
    clearTimeout(editor.timer);
    if (!editor.dirty) { showLutPreview(editor); return; }
    editor.busy = true;
    updateLutButtons();
    try {
        while (state.lutEditor === editor && editor.dirty) {
            const name = $('lut-name').value.trim();
            const intensity = Number($('lut-intensity').value);
            const replacement = $('lut-replacement').files[0];
            if (!name) throw new Error('Vui lòng đặt tên filter.');
            if (replacement && (!replacement.name.toLowerCase().endsWith('.cube') || replacement.size > 5 * 1024 * 1024)) throw new Error('Chọn file .cube tối đa 5 MiB.');
            const payload = replacement ? new FormData() : { revision:editor.item.revision, name, intensity };
            if (replacement) {
                payload.set('revision', String(editor.item.revision));
                payload.set('name', name);
                payload.set('intensity', String(intensity));
                payload.set('file', replacement);
            }
            editor.dirty = false;
            const item = editor.item;
            const updated = await api('POST', `/admin/luts/${item.id}/${item.version}/draft`, payload);
            if (state.lutEditor !== editor) return;
            editor.item = updated;
            if (replacement && $('lut-replacement').files[0] === replacement) $('lut-replacement').value = '';
            if (!editor.dirty) showLutPreview(editor);
            await loadLuts();
        }
    } catch (error) {
        if (state.lutEditor !== editor) return;
        editor.dirty = true;
        editor.ready = false;
        $('lut-preview-state').textContent = error.message;
        toast(error.message, true);
        if (error.status === 401) signOut();
    } finally {
        editor.busy = false;
        if (state.lutEditor === editor) updateLutButtons();
    }
}

$('btn-import-luts').addEventListener('click', () => run(async () => {
    const files = [...$('lut-files').files];
    if (!files.length || files.length > 30) throw new Error('Chọn từ 1 đến 30 file .cube mỗi lượt.');
    $('lut-import-results').replaceChildren();
    for (const file of files) {
        const row = document.createElement('li');
        row.textContent = `${file.name} — Đang nhập…`;
        $('lut-import-results').append(row);
        try {
            if (!file.name.toLowerCase().endsWith('.cube') || file.size > 5 * 1024 * 1024) throw new Error('Chọn file .cube tối đa 5 MiB.');
            const form = new FormData();
            form.set('file', file);
            const draft = await api('POST', '/admin/luts/import', form);
            row.textContent = `${file.name} — Đã nhập nháp: ${draft.name}`;
        } catch (error) {
            row.textContent = `${file.name} — ${error.message}`;
            row.className = 'import-error';
            if (error.status === 401) throw error;
        }
    }
    $('lut-files').value = '';
    await loadLuts();
}));
$('lut-name').addEventListener('input', markLutDirty);
$('lut-intensity').addEventListener('input', markLutDirty);
$('lut-replacement').addEventListener('change', markLutDirty);
$('btn-save-lut').addEventListener('click', () => saveLutPreview(state.lutEditor));
$('btn-activate-lut').addEventListener('click', () => run(async () => {
    const editor = state.lutEditor;
    if (!editor || editor.dirty || editor.busy || !editor.ready) return;
    const item = editor.item;
    await api('POST', `/admin/luts/${item.id}/${item.version}/activate`, { revision:item.revision, preview_sha256:item.preview_sha256 });
    closeLutEditor();
    state.presets = (await api('GET', '/presets')).presets;
    await loadLuts();
    toast('Filter đã sẵn sàng cho phiên mới.');
}));

async function checkAuthOnBoot()
{
    try {
        const result = await api('GET', '/auth/me');
        state.user = result.user;
        state.csrf = result.csrf_token;
        await enter();
    } catch { signOut(); }
}
setInterval(health, 5000);
let refreshingEvent = false;
setInterval(async () => {
    if (!state.user || state.busy || refreshingEvent) return;
    const current = state.session;
    const print = $('modal-print-confirm').hidden ? null : state.print;
    const target = print?.session || (state.view === 'workspace' && state.step === 5 && current?.current_final ? current : null);
    const settings = state.view === 'settings' ? state.settings : null;
    if (!target && !settings) return;
    refreshingEvent = true;
    try {
        if (settings) {
            const result = await api('GET', '/admin/settings?status=1');
            if (settings !== state.settings || state.busy) return;
            settings.locked = result.locked;
            $('event-quota').textContent = quotaLabel(result.quota);
            render();
        } else {
            const result = await api('GET', '/sessions/' + target.id);
            if (state.busy) return;
            if (current?.id === result.id && state.session?.id === result.id && state.session.revision <= result.revision) {
                state.session = result;
                if (state.step === 5) {
                    updatePrintControls(result);
                    if ($('modal-resolve-unknown').hidden) renderPrints(result);
                }
            }
            if (print && state.print === print) {
                print.session = result;
                if (print.action === 'print') print.action = printAction(result, print.asset.id);
                updatePrintModal();
            }
        }
    } catch { /* The next refresh retries without interrupting the operator. */ }
    finally { refreshingEvent = false; }
}, 1800);
let frame = 1;
$('live-preview-img')?.addEventListener('load', () => {
    if (!browserMode && liveViewVisible()) { syncCaptureLut(state.session); updateCropGuide(); }
});
setInterval(() => {
    if (document.body.dataset.mock === 'true' && liveViewVisible()) {
        frame = frame % 3 + 1;
        $('live-preview-img').src = `/custom/assets/mock-preview/frame-${frame}.jpg`;
    }
}, 1200);
checkAuthOnBoot();

$('btn-webcam-enable').addEventListener('click', () => run(() => browserCamera.open($('webcam-device').value)));
$('btn-webcam-switch').addEventListener('click', () => run(() => browserCamera.open('', true)));
$('btn-webcam-mirror').addEventListener('click', () => browserCamera.toggleMirror());
$('webcam-device').addEventListener('change', () => run(() => browserCamera.open($('webcam-device').value)));
$('btn-webcam-stop').addEventListener('click', () => browserCamera.stop());
window.addEventListener('pagehide', () => browserCamera.stop());
document.getElementById('btn-dcc-enable').addEventListener('click', () => run(() => dccCamera?.start()));
document.getElementById('btn-dcc-stop').addEventListener('click', () => run(() => dccCamera?.stop()));
window.addEventListener('pagehide', () => dccCamera?.stop());
window.addEventListener('resize', updateCropGuide);
document.fonts.ready.then(updateCropGuide);
