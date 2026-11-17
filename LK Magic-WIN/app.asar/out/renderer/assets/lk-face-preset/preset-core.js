/* LK magic: measurements and native-parameter search. No substitute image renderer. */
(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.LKFacePresetCore = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';
  const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
  const specs = [
    ['exposure', 130005, 'Phơi sáng', -5, 5, .01, .65],
    ['temperature', 130002, 'Ấm / lạnh', -100, 100, 1, 22],
    ['tint', 130003, 'Sắc xanh / hồng', -100, 100, 1, 18],
    ['contrast', 130010, 'Tương phản', -100, 100, 1, 22],
    ['highlight', 130006, 'Vùng sáng', -100, 100, 1, 25],
    ['shadow', 130008, 'Vùng tối', -100, 100, 1, 25],
    ['white', 130007, 'Trắng', -100, 100, 1, 18],
    ['black', 130009, 'Đen', -100, 100, 1, 18],
    ['brightness', 130011, 'Độ sáng', -100, 100, 1, 18],
    ['saturation', 130014, 'Độ bão hòa', -100, 100, 1, 18],
    ['vibrance', 130013, 'Độ bão hòa tự nhiên', -100, 100, 1, 18],
    ['aiTone', 220073, 'AI tông màu / ánh sáng', 0, 100, 1, 25],
    ['aiWhiteBalance', 220074, 'AI cân bằng trắng', 0, 100, 1, 25],
    ['dehaze', 130016, 'Khử sương mù', 0, 100, 1, 12]
  ].map(([key, id, label, min, max, step, stride]) => Object.freeze({ key, id, label, min, max, step, stride, ai: key.startsWith('ai') }));
  const byKey = new Map(specs.map(s => [s.key, s]));
  const regions = Object.freeze({ skin: { label: 'Da', mask: 'whole_skin', weight: .7 }, hair: { label: 'Tóc', mask: 'hair', weight: .2 }, clothes: { label: 'Quần áo', mask: 'cloth', weight: .1 } });
  const linear = Float64Array.from({ length: 256 }, (_, i) => { const v = i / 255; return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4; });
  function lab(r, g, b) {
    const f = v => v > .008856451679 ? Math.cbrt(v) : 7.787037037 * v + 16 / 116;
    const R = linear[r], G = linear[g], B = linear[b];
    const x = f((.4124564 * R + .3575761 * G + .1804375 * B) / .95047);
    const y = f(.2126729 * R + .7151522 * G + .0721750 * B);
    const z = f((.0193339 * R + .1191920 * G + .9503041 * B) / 1.08883);
    return [116 * y - 16, 500 * (x - y), 200 * (y - z)];
  }
  function rect(value) {
    if (!value || !['x', 'y', 'w', 'h'].every(k => Number.isFinite(value[k]))) throw new Error('Vùng đo chưa hợp lệ.');
    if (value.x < 0 || value.y < 0 || value.w <= 0 || value.h <= 0 || value.x + value.w > 1.00001 || value.y + value.h > 1.00001) throw new Error('Vùng đo nằm ngoài ảnh.');
    return { x: value.x, y: value.y, w: value.w, h: value.h };
  }
  function percentile(a, q) { const p = (a.length - 1) * q, i = Math.floor(p), t = p - i; return a[i] * (1 - t) + a[Math.min(i + 1, a.length - 1)] * t; }
  function analyze(frame, area) {
    const { width, height, data } = frame, a = rect(area);
    if (!Number.isInteger(width) || !Number.isInteger(height) || width < 1 || height < 1 || !data || data.length !== width * height * 4) throw new Error('Ảnh đo màu chưa hợp lệ.');
    const x0 = Math.floor(a.x * width), y0 = Math.floor(a.y * height), x1 = Math.min(width, Math.ceil((a.x + a.w) * width)), y1 = Math.min(height, Math.ceil((a.y + a.h) * height));
    if (x1 - x0 < 8 || y1 - y0 < 8) throw new Error('Vùng đo quá nhỏ. Hãy khoanh vùng rộng hơn.');
    const stride = Math.max(1, Math.ceil(Math.sqrt((x1 - x0) * (y1 - y0) / 5000)));
    const channels = [[], [], []]; let clipped = 0, count = 0;
    for (let y = y0; y < y1; y += stride) for (let x = x0; x < x1; x += stride) {
      const p = (y * width + x) * 4;
      if (data[p + 3] < 230) continue;
      const v = lab(data[p], data[p + 1], data[p + 2]);
      for (let c = 0; c < 3; c++) channels[c].push(v[c]);
      if (Math.min(data[p], data[p + 1], data[p + 2]) <= 1 || Math.max(data[p], data[p + 1], data[p + 2]) >= 254) clipped++;
      count++;
    }
    if (count < 48) throw new Error('Vùng đo chưa có đủ điểm ảnh rõ.');
    const means = [], stds = [], quantiles = [];
    for (const values of channels) {
      values.sort((a, b) => a - b);
      const lo = percentile(values, .05), hi = percentile(values, .95), win = values.map(v => clamp(v, lo, hi));
      const mean = win.reduce((a, b) => a + b, 0) / count;
      means.push(mean); stds.push(Math.sqrt(win.reduce((a, v) => a + (v - mean) ** 2, 0) / count));
      quantiles.push([.1, .25, .5, .75, .9].map(q => percentile(values, q)));
    }
    return { means, stds, quantiles, clipped: clipped / count, samples: count };
  }
  function validateStats(s) {
    if (!s || !Array.isArray(s.means) || s.means.length !== 3 || !s.means.every(Number.isFinite) || !Array.isArray(s.quantiles) || s.quantiles.length !== 3 || s.quantiles.some(q => !Array.isArray(q) || q.length !== 5 || !q.every(Number.isFinite)) || !Number.isFinite(s.clipped)) throw new Error('Dữ liệu đo màu không hợp lệ.');
    return s;
  }
  function targets(reference, source, clothesMode) {
    const out = {};
    for (const k of Object.keys(regions)) if (reference[k] && source[k]) {
      const goal = JSON.parse(JSON.stringify(validateStats(reference[k])));
      if (k === 'clothes' && clothesMode !== 'sample') {
        for (const c of [1, 2]) { goal.means[c] = source[k].means[c]; goal.quantiles[c] = source[k].quantiles[c].slice(); }
      }
      out[k] = goal;
    }
    if (!out.skin) throw new Error('Hãy khoanh vùng da trên cả ảnh mẫu và ảnh cần chỉnh.');
    return out;
  }
  function measure(stats, goal) {
    let sum = 0, weight = 0; const details = {};
    for (const [k, target] of Object.entries(goal)) {
      const s = validateStats(stats[k]), t = validateStats(target);
      const tone = s.quantiles[0].reduce((v, x, i) => v + (x - t.quantiles[0][i]) ** 2, 0) / 5;
      const chroma = (s.means[1] - t.means[1]) ** 2 + (s.means[2] - t.means[2]) ** 2;
      const spread = ((s.quantiles[0][4] - s.quantiles[0][0]) - (t.quantiles[0][4] - t.quantiles[0][0])) ** 2;
      const penalty = Math.max(0, s.clipped - Math.max(t.clipped, .015)) ** 2 * 900;
      details[k] = Math.sqrt(.5 * tone + .5 * chroma + .04 * spread + penalty);
      sum += regions[k].weight * details[k] ** 2; weight += regions[k].weight;
    }
    return { loss: Math.sqrt(sum / weight), details };
  }
  function dimensions(keys, ai, clothesMode) {
    const result = specs.filter(s => !s.ai || ai).map(s => ({ ...s, scope: 'global', name: 'global.' + s.key }));
    for (const region of keys) {
      if (!regions[region]) throw new Error('Vùng chủ thể không được hỗ trợ.');
      for (const key of ['exposure', 'temperature', 'tint', 'contrast', 'shadow', 'saturation']) {
        if (region === 'clothes' && clothesMode !== 'sample' && ['temperature', 'tint'].includes(key)) continue;
        const s = byKey.get(key);
        result.push({ ...s, min: key === 'exposure' ? -3 : s.min, max: key === 'exposure' ? 3 : s.max, stride: s.stride * .7, scope: region, name: region + '.' + key });
      }
    }
    return result;
  }
  function cleanVector(value) {
    if (!value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).length > 40) throw new Error('Bộ thông số không hợp lệ.');
    const out = {};
    for (const [name, v] of Object.entries(value)) {
      const [scope, key, extra] = name.split('.'), s = byKey.get(key);
      if (extra || !s || !(scope === 'global' || regions[scope]) || scope !== 'global' && !['exposure', 'temperature', 'tint', 'contrast', 'shadow', 'saturation'].includes(key)) throw new Error('Có thông số chưa được hỗ trợ.');
      if (typeof v !== 'number' || !Number.isFinite(v) || v < s.min || v > s.max || scope !== 'global' && key === 'exposure' && Math.abs(v) > 3) throw new Error('Thông số nằm ngoài phạm vi của LK magic.');
      const rounded = Math.round(v / s.step) * s.step;
      if (Math.abs(v - rounded) > .000001) throw new Error('Thông số không đúng bước điều chỉnh.');
      if (Math.abs(v) > .0000001) out[name] = Number(v.toFixed(4));
    }
    return Object.fromEntries(Object.entries(out).sort(([a], [b]) => a.localeCompare(b)));
  }
  function toNative(vector) {
    const v = cleanVector(vector), color = { params: [], partial_params: [] };
    for (const scope of ['global', ...Object.keys(regions)]) {
      const params = Object.entries(v).filter(([name]) => name.split('.')[0] === scope).map(([name, value]) => ({ id: byKey.get(name.split('.')[1]).id, algorithm: { value } }));
      if (scope === 'global') color.params = params;
      else if (params.length) color.partial_params.push({ layer_type: 0, classids: [], mask_types: [regions[scope].mask], mask_types_names: [regions[scope].label], name: 'Face màu · ' + regions[scope].label, visible: true, params });
    }
    return { sex_retoucher_beauty_params: [], sex_retoucher_color_params: color };
  }
  async function optimize(options) {
    const { evaluate, reference, source, onProgress = () => {}, canceled = () => false } = options;
    const goal = targets(reference, source, options.clothesMode), dims = dimensions(options.local === false ? [] : Object.keys(goal), options.ai !== false, options.clothesMode);
    const budget = clamp(Math.round(options.budget || 220), 30, 500), cache = new Map(); let calls = 0, best = null, firstPreview = null, firstLoss = null, previewChanged = false;
    async function trial(vector) {
      const v = cleanVector(vector), key = JSON.stringify(v);
      if (cache.has(key)) return cache.get(key);
      if (calls >= budget || canceled()) return null;
      const output = await evaluate(v); calls++;
      const measured = measure(output.stats, goal);
      if (calls === 1) { firstPreview = output.image; firstLoss = measured.loss; }
      else if (output.image !== firstPreview) previewChanged = true;
      if (options.requireChangingPreview && calls >= 10 && !previewChanged && firstLoss > 1) throw new Error('Ảnh thử không thay đổi khi đổi thông số. Chưa thể xác nhận luồng dựng ảnh này; hãy lưu chẩn đoán để kiểm tra.');
      const result = { vector: v, loss: measured.loss, details: measured.details, image: output.image };
      cache.set(key, result);
      if (!best || result.loss < best.loss - .00001) {
        if (best) best.image = null;
        best = result;
      } else result.image = null;
      await onProgress({ calls, budget, best, current: result });
      return result;
    }
    const baseline = await trial({});
    if (!baseline) throw new Error('Chưa có ảnh thử để bắt đầu.');
    for (const factor of [1, .45, .18, .075, .03]) {
      if (calls >= budget || canceled() || best.loss < .4) break;
      for (const d of dims) {
        if (calls >= budget || canceled()) break;
        const center = { ...best.vector }, original = center[d.name] || 0;
        const stride = Math.max(d.step, Math.round(d.stride * factor / d.step) * d.step);
        for (const direction of [-1, 1]) {
          if (calls >= budget || canceled()) break;
          const candidate = clamp(Math.round((original + direction * stride) / d.step) * d.step, d.min, d.max);
          if (Math.abs(candidate - original) < d.step * .5) continue;
          await trial({ ...center, [d.name]: Number(candidate.toFixed(4)) });
        }
      }
    }
    return { best, baseline, calls, canceled: canceled(), goal, dimensions: dims.length };
  }
  return Object.freeze({ version: '0.2.0-beta.1', specs, regions, analyze, rect, targets, measure, dimensions, cleanVector, toNative, optimize });
});
