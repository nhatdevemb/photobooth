/* LK magic Face màu 0.1.0. Local statistical colour transfer; no network or AI inference. */
(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.LKFaceColorCore = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';
  const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
  const linear = new Float64Array(256);
  for (let i = 0; i < 256; i++) {
    const v = i / 255;
    linear[i] = v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
  }
  function f(v) { return v > 0.008856451679 ? Math.cbrt(v) : 7.787037037 * v + 16 / 116; }
  function invf(v) { const v3 = v * v * v; return v3 > 0.008856451679 ? v3 : (v - 16 / 116) / 7.787037037; }
  function toLab(r, g, b, out) {
    const R = linear[r], G = linear[g], B = linear[b];
    const x = f((0.4124564 * R + 0.3575761 * G + 0.1804375 * B) / 0.95047);
    const y = f(0.2126729 * R + 0.7151522 * G + 0.0721750 * B);
    const z = f((0.0193339 * R + 0.1191920 * G + 0.9503041 * B) / 1.08883);
    out[0] = 116 * y - 16; out[1] = 500 * (x - y); out[2] = 200 * (y - z);
    return out;
  }
  function gamma(v) { return 255 * (v <= 0.0031308 ? 12.92 * v : 1.055 * Math.pow(v, 1 / 2.4) - 0.055); }
  function fromLab(L, a, b, out) {
    const fy = (L + 16) / 116;
    const x = 0.95047 * invf(fy + a / 500), y = invf(fy), z = 1.08883 * invf(fy - b / 200);
    out[0] = clamp(gamma(3.2404542 * x - 1.5371385 * y - 0.4985314 * z), 0, 255);
    out[1] = clamp(gamma(-0.969266 * x + 1.8760108 * y + 0.041556 * z), 0, 255);
    out[2] = clamp(gamma(0.0556434 * x - 0.2040259 * y + 1.0572252 * z), 0, 255);
    return out;
  }
  function percentile(sorted, q) {
    const pos = (sorted.length - 1) * q, a = Math.floor(pos), t = pos - a;
    return sorted[a] * (1 - t) + sorted[Math.min(a + 1, sorted.length - 1)] * t;
  }
  function analyze(data) {
    if (!data || !data.length || data.length % 4) throw new Error('Dữ liệu ảnh không hợp lệ.');
    const channels = [[], [], []], lab = [0, 0, 0];
    const step = Math.max(1, Math.floor(data.length / 4 / 80000));
    for (let p = 0; p < data.length; p += 4 * step) {
      if (data[p + 3] < 230) continue;
      toLab(data[p], data[p + 1], data[p + 2], lab);
      for (let c = 0; c < 3; c++) channels[c].push(lab[c]);
    }
    if (!channels[0].length) throw new Error('Ảnh không có vùng màu đủ rõ để phân tích.');
    const means = [], stds = [], quantiles = [];
    for (let c = 0; c < 3; c++) {
      const values = channels[c].sort((a, b) => a - b);
      // Winsorised moments stop a few clipped lights/shadows dominating a photograph.
      const low = percentile(values, 0.02), high = percentile(values, 0.98);
      let sum = 0, squares = 0;
      for (const x of values) { const v = clamp(x, low, high); sum += v; squares += v * v; }
      const mean = sum / values.length;
      means.push(mean); stds.push(Math.sqrt(Math.max(0, squares / values.length - mean * mean)));
      quantiles.push([0.02, 0.15, 0.35, 0.5, 0.65, 0.85, 0.98].map(q => percentile(values, q)));
    }
    return { means, stds, quantiles, samples: channels[0].length };
  }
  function makePlan(source, reference, options) {
    const o = Object.assign({ amount: 70, color: 80, tone: 45, contrast: 45, skin: true }, options || {});
    const checked = {};
    for (const k of ['amount', 'color', 'tone', 'contrast']) {
      if (!Number.isFinite(Number(o[k]))) throw new Error('Thông số màu không hợp lệ.');
      checked[k] = clamp(Number(o[k]), 0, 100) / 100;
    }
    checked.skin = !!o.skin;
    const scales = source.stds.map((s, i) => {
      if (s < (i === 0 ? 2 : 1.2)) return 1;
      return clamp(reference.stds[i] / s, i === 0 ? 0.7 : 0.65, i === 0 ? 1.4 : 1.5);
    });
    const x = source.quantiles[0].slice(), y = reference.quantiles[0].slice();
    // A continuous, monotone curve with bounded local contrast. Anchoring each
    // segment independently would create visible jumps in smooth gradients.
    y[3] = x[3] + clamp(y[3] - x[3], -22, 22);
    for (let i = 4; i < x.length; i++) { const dx = Math.max(0, x[i] - x[i - 1]); y[i] = clamp(y[i], y[i - 1] + 0.5 * dx, y[i - 1] + 1.7 * dx); }
    for (let i = 2; i >= 0; i--) { const dx = Math.max(0, x[i + 1] - x[i]); y[i] = clamp(y[i], y[i + 1] - 1.7 * dx, y[i + 1] - 0.5 * dx); }
    return { source, reference, options: checked, scales, toneCurve: { x, y } };
  }
  function toneMap(value, curve) {
    const s = curve.x, r = curve.y;
    let i = 0;
    while (i < s.length - 2 && value > s[i + 1]) i++;
    const range = s[i + 1] - s[i];
    if (range < 0.000001) return value + clamp(r[i] - s[i], -22, 22);
    const slope = (r[i + 1] - r[i]) / range;
    return value + clamp(r[i] + (value - s[i]) * slope - value, -22, 22);
  }
  function skinWeight(r, g, b) {
    // Broad chroma heuristic only. This is not a face/skin segmentation model.
    const cb = 128 - 0.168736 * r - 0.331264 * g + 0.5 * b;
    const cr = 128 + 0.5 * r - 0.418688 * g - 0.081312 * b;
    const distance = Math.pow((cb - 108) / 26, 2) + Math.pow((cr - 153) / 29, 2);
    return Math.exp(-1.2 * distance) * clamp((r - b + 20) / 55, 0, 1);
  }
  function transformRange(input, output, plan, start, end) {
    const lab = [0, 0, 0], rgb = [0, 0, 0], o = plan.options;
    const from = start == null ? 0 : start, to = end == null ? input.length : end;
    if (o.amount === 0 || (o.color === 0 && o.tone === 0 && o.contrast === 0)) {
      output.set(input.subarray(from, to), from); return;
    }
    for (let p = from; p < to; p += 4) {
      const R = input[p], G = input[p + 1], B = input[p + 2];
      if (!input[p + 3]) { output.set(input.subarray(p, p + 4), p); continue; }
      toLab(R, G, B, lab);
      const s = plan.source, r = plan.reference;
      const colourMix = o.color * (o.skin ? 1 - 0.6 * skinWeight(R, G, B) : 1);
      const newL = toneMap(lab[0], plan.toneCurve);
      const contrastL = s.means[0] + (lab[0] - s.means[0]) * plan.scales[0];
      const L = clamp(lab[0] + o.tone * (newL - lab[0]) + 0.35 * o.contrast * (contrastL - lab[0]), 0, 100);
      const aTarget = r.means[1] + (lab[1] - s.means[1]) * (1 + o.contrast * (plan.scales[1] - 1));
      const bTarget = r.means[2] + (lab[2] - s.means[2]) * (1 + o.contrast * (plan.scales[2] - 1));
      const a = lab[1] + colourMix * clamp(aTarget - lab[1], -24, 24);
      const b = lab[2] + colourMix * clamp(bTarget - lab[2], -24, 24);
      fromLab(L, a, b, rgb);
      for (let c = 0; c < 3; c++) output[p + c] = Math.round(input[p + c] + o.amount * (rgb[c] - input[p + c]));
      output[p + 3] = input[p + 3];
    }
  }
  return { analyze, makePlan, transformRange, toLab, fromLab, version: '0.1.0' };
});
