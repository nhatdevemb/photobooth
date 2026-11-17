"use strict";

const fs = require("fs");
const path = require("path");

let CN_MAP = {};
let CN_ENTRIES = [];

function loadDictionary() {
  try {
    const dictPath = path.join(__dirname, "runtime_hook_dict.json");
    if (!fs.existsSync(dictPath)) return;
    const parsed = JSON.parse(fs.readFileSync(dictPath, "utf8"));
    if (!parsed || typeof parsed !== "object") return;
    CN_MAP = parsed;
    CN_ENTRIES = Object.entries(parsed)
      .filter(([key, value]) => key && !key.startsWith("_") && typeof value === "string")
      .sort(([a], [b]) => b.length - a.length);
  } catch (error) {
    CN_MAP = {};
    CN_ENTRIES = [];
  }
}

function patchCnStrings(data) {
  if (data === null || data === undefined) return data;

  if (typeof data === "string") {
    if (!/[\u3400-\u9FFF]/.test(data)) return data;

    let result = data;
    for (const [cn, vi] of CN_ENTRIES) {
      if (!result.includes(cn)) continue;
      const escaped = cn.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
      result = result.replace(new RegExp(escaped, "g"), vi);
    }
    return result;
  }

  if (Array.isArray(data)) {
    return data.map((item) => patchCnStrings(item));
  }

  if (typeof data === "object") {
    const out = {};
    for (const [key, value] of Object.entries(data)) {
      out[key] = patchCnStrings(value);
    }
    return out;
  }

  return data;
}

loadDictionary();

module.exports = {
  patch: patchCnStrings,
  dict: CN_MAP
};
