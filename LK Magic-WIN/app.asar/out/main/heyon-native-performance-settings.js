"use strict";

const SETTINGS = [
  ["export_speed", "3"],
  // This override was measured slower on the source hardware. Clear a value
  // left by older commercial builds instead of forcing it on every launch.
  ["compatibility_mode_acceleration", ""],
  ["gpu_enabled", "1"]
];

function applyNativePerformanceSettings(ffi, logger, env) {
  const trace = (message) => {
    if (String(env.HEYON_PERFORMANCE_LOG || "") === "1") {
      console.error(`[heyon-performance] ${message}`);
    }
  };
  trace(`gate=${String(env.HEYON_NATIVE_PERFORMANCE || "")}`);
  if (String(env.HEYON_NATIVE_PERFORMANCE || "") !== "1") return false;
  if (!ffi || typeof ffi.aipic_set_setting !== "function") return false;
  try {
    const results = SETTINGS.map(([key, value]) => ({
      key,
      result: ffi.aipic_set_setting(true, "local_info", key, value)
    }));
    trace(`pre-init=${JSON.stringify(results)}`);
    return results.every((entry) => entry.result === 0);
  } catch (error) {
    trace(`pre-init failed=${error.message}`);
    return false;
  }
}

module.exports = { applyNativePerformanceSettings };
