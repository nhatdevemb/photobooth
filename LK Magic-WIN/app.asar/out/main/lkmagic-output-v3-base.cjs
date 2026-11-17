"use strict";

// Presentation/output only. No native bindings, Electron/IPC, account or license code.
// Constructing the manager does no IO. Jobs load lazily when exports are used.
const fs = require("node:fs");
const path = require("node:path");
const crypto = require("node:crypto");
const fsp = fs.promises;
const IMAGE_EXTENSIONS = new Set([".jpg", ".jpeg", ".png", ".tif", ".tiff", ".bmp", ".webp", ".heic", ".avif",
  ".crw", ".cr2", ".cr3", ".kdc", ".mrw", ".nef", ".orf", ".dng", ".ptx", ".pef", ".arw", ".rw2", ".raf", ".raw", ".nrw", ".iiq", ".erf", ".k25", ".dcr", ".mef", ".sr2", ".srf", ".x3f", ".rwl", ".3fr", ".srw", ".fff"]);
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

function portableBasename(value) {
  return String(value || "").replace(/\\/g, "/").split("/").pop();
}
function stem(value) {
  const name = portableBasename(value);
  const dot = name.lastIndexOf(".");
  return dot > 0 ? name.slice(0, dot) : name;
}
function safeName(value) {
  if (!value || value !== portableBasename(value) || /[<>:"/\\|?*\x00-\x1f]/.test(value) || /[ .]$/.test(value) || /^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i.test(value)) {
    throw new Error("Tên ảnh không hợp lệ trên Windows; giữ file tạm, không tự đổi tên.");
  }
  return value;
}
function within(root, file) {
  const relative = path.relative(path.resolve(root), path.resolve(file));
  return relative !== "" && relative !== ".." && !relative.startsWith(".." + path.sep) && !path.isAbsolute(relative);
}
function isLocalImageExport(params) {
  const options = params && params.exportList;
  return !!(options && String(options.location) === "0" &&
    !params.software && !params.match_path && !options.shared_spaceid &&
    !(params.faceitems && params.faceitems.length) &&
    Array.isArray(params.exportFile) && params.exportFile.length);
}

function describeSource(row, requestedId, findMetadata) {
  if (!row || typeof row.path !== "string" || !row.path.trim()) {
    throw new Error("Chưa lấy được đường dẫn ảnh gốc; không tạo tác vụ xuất.");
  }
  let source = row.path;
  let displayName = portableBasename(source);
  const aliases = new Set([stem(source).toLowerCase()]);
  if (row.name) aliases.add(stem(row.name).toLowerCase());
  const visited = new Set();
  // RAW -> converted JPEG -> ASCII native staging may form more than one hop.
  for (let depth = 0; depth < 16; depth++) {
    const key = String(source).toLowerCase();
    if (visited.has(key)) throw new Error("Metadata tên ảnh bị vòng lặp; không đoán tên ảnh gốc.");
    visited.add(key);
    const metadata = findMetadata(source);
    if (!metadata || !metadata.originalPath || String(metadata.originalPath).toLowerCase() === key) {
      // Native/hash stems must have their original provenance in the existing index.
      if (source === row.path && /^native-.+-[0-9a-f]{24}(?:\.[^.]+)?$/i.test(portableBasename(source))) {
        throw new Error("Thiếu metadata ảnh gốc của file native; hãy nhập lại ảnh trước khi xuất.");
      }
      if (metadata && metadata.originalName) displayName = metadata.originalName;
      return { fileid: String(requestedId), nativePath: row.path,
        originalPath: source, displayName: safeName(portableBasename(displayName)),
        aliases: [...aliases] };
    }
    aliases.add(stem(metadata.outputPath || source).toLowerCase());
    source = metadata.originalPath;
    displayName = metadata.originalName || portableBasename(source);
    aliases.add(stem(source).toLowerCase());
  }
  throw new Error("Metadata tên ảnh quá sâu; không đoán tên ảnh gốc.");
}

function createOutputManager({ stateRoot, stageRoot, findMetadata, log = () => {} }) {
  const jobs = new Map();
  const completing = new Map();
  let loaded = false;

  function save(job) {
    const root = path.resolve(stateRoot());
    fs.mkdirSync(root, { recursive: true });
    const target = path.join(root, job.uuid + ".json");
    const temporary = target + "." + crypto.randomUUID() + ".tmp";
    try {
      const fd = fs.openSync(temporary, "wx", 0o600);
      try {
        fs.writeFileSync(fd, JSON.stringify(job));
        fs.fsyncSync(fd);
      } finally { fs.closeSync(fd); }
      fs.renameSync(temporary, target);
    } finally { try { fs.unlinkSync(temporary); } catch (_) {} }
  }

  function validate(job) {
    if (!job || job.version !== 3 || !UUID.test(job.uuid) ||
      typeof job.destination !== "string" || !path.isAbsolute(job.destination) ||
      job.stage !== path.join(path.resolve(stageRoot()), job.uuid) ||
      !Array.isArray(job.sources) || !job.sources.length ||
      !job.published || typeof job.published !== "object") {
      throw new Error("Journal export V3 không hợp lệ; không sửa file người dùng.");
    }
    for (const source of job.sources) {
      if (!source || !source.fileid || !source.nativePath || !Array.isArray(source.aliases)) throw new Error("Journal nguồn ảnh không hợp lệ.");
      safeName(source.displayName);
    }
    for (const [nativePath, finalPath] of Object.entries(job.published)) {
      if (!within(job.stage, nativePath) || typeof finalPath !== "string" ||
        path.dirname(finalPath) !== job.destination) throw new Error("Journal export chứa đường dẫn ngoài tác vụ.");
      safeName(path.basename(finalPath));
    }
  }

  function load() {
    if (loaded) return;
    const root = path.resolve(stateRoot());
    if (!fs.existsSync(root)) { loaded = true; return; }
    for (const entry of fs.readdirSync(root, { withFileTypes: true })) {
      if (!entry.isFile() || !/^[0-9a-f-]+\.json$/.test(entry.name)) continue;
      const job = JSON.parse(fs.readFileSync(path.join(root, entry.name), "utf8"));
      validate(job);
      if (entry.name !== job.uuid + ".json") throw new Error("Journal export sai ID.");
      jobs.set(job.uuid, job);
    }
    loaded = true;
  }

  async function prepare(params, rows) {
    load();
    if (!isLocalImageExport(params)) return null;
    const destination = path.resolve(String(params.exportList.destpath || ""));
    if (!params.exportList.destpath || !(await fsp.stat(destination)).isDirectory()) {
      throw new Error("Vui lòng chọn thư mục xuất đã tồn tại.");
    }
    const sources = params.exportFile.map((item, index) => describeSource(rows[index], item.fileid, findMetadata));
    // Do not let a native batch overwrite two ASCII inputs with the same native
    // basename. Their original names may coincide only when native stems differ.
    const nativeStems = new Set();
    for (const source of sources) {
      const key = stem(source.nativePath).toLowerCase();
      if (nativeStems.has(key)) throw new Error("Các ảnh có cùng tên nội bộ. Hãy xuất từng nhóm riêng để tránh ghi đè trong engine.");
      nativeStems.add(key);
    }
    const root = path.resolve(stageRoot());
    fs.mkdirSync(root, { recursive: true });
    const uuid = crypto.randomUUID(); // Temporary folder only; never a visible image name.
    const job = { version: 3, uuid, taskId: null, destination,
      stage: path.join(root, uuid), sources, published: {}, complete: false };
    fs.mkdirSync(job.stage);
    save(job);
    jobs.set(uuid, job);
    return job;
  }

  function bind(job, taskId) {
    if (!job) return;
    const value = String(taskId);
    if (!/^\d+$/.test(value)) throw new Error("Engine trả ID export không hợp lệ.");
    if (job.taskId !== null && job.taskId !== value) throw new Error("ID tác vụ export không khớp.");
    job.taskId = value;
    save(job);
  }

  function find(taskId, nativeDestination) {
    load();
    const value = String(taskId);
    for (const job of jobs.values()) {
      if (job.taskId === value) return job;
    }
    if (typeof nativeDestination === "string") {
      for (const job of jobs.values()) {
        if (job.stage === path.resolve(nativeDestination) && job.taskId === null) {
          bind(job, taskId);
          return job;
        }
      }
    }
    return null;
  }

  function sourceFor(job, record) {
    const file = record.file_path;
    const nativeStem = stem(file).toLowerCase();
    const matches = job.sources.filter(item => item.aliases.includes(nativeStem));
    // Only a field already supplied by native export records, if present. The
    // generic row 'id' is an export-row ID and is deliberately not guessed.
    const fileId = record.file_id === undefined ? record.fileid : record.file_id;
    if (fileId !== undefined) {
      const byId = job.sources.find(item => item.fileid === String(fileId));
      if (byId && matches.includes(byId)) return byId;
    }
    if (matches.length === 1) return matches[0];
    throw new Error("Không ánh xạ được tên file xuất về ảnh gốc; giữ file tạm để kiểm tra.");
  }

  async function publish(job, record) {
    const nativePath = path.resolve(record.file_path);
    if (!within(job.stage, nativePath)) throw new Error("Engine trả file ngoài thư mục tạm của tác vụ.");
    if (job.published[nativePath]) return job.published[nativePath];
    const info = await fsp.lstat(nativePath);
    if (!info.isFile() || info.isSymbolicLink() || info.size === 0) throw new Error("File xuất chưa hoàn chỉnh; không công bố thành công.");
    // Resolve parent as well: an engine-produced symlink directory is not a valid staging output.
    if (!within(await fsp.realpath(job.stage), await fsp.realpath(nativePath))) throw new Error("File xuất qua symlink ngoài staging.");
    const extension = path.extname(nativePath);
    if (!IMAGE_EXTENSIONS.has(extension.toLowerCase())) throw new Error("Định dạng file xuất chưa được hỗ trợ bởi V3.");
    const source = sourceFor(job, record);
    const base = safeName(stem(source.displayName));
    for (let n = 1; n <= 10000; n++) {
      const name = safeName(base + (n === 1 ? "" : ` (${n})`) + extension);
      const target = path.join(job.destination, name);
      try {
        // No exists-then-copy race and no overwrite. COPYFILE_EXCL also rejects
        // existing symlinks. Suffixes are deterministic and only for collisions.
        await fsp.copyFile(nativePath, target, fs.constants.COPYFILE_EXCL);
      } catch (error) {
        if (error.code === "EEXIST") continue;
        throw error;
      }
      job.published[nativePath] = target;
      save(job);
      log("published", { taskId: job.taskId, filename: name });
      return target;
    }
    throw new Error("Quá nhiều file trùng tên trong thư mục xuất.");
  }

  async function complete(job, readNativeFiles, expectedCount) {
    const count = Number(expectedCount);
    const wanted = Number.isSafeInteger(count) && count >= 0 ? count : job.sources.length;
    if (job.complete && Object.keys(job.published).length === wanted) return;
    if (completing.has(job.uuid)) {
      await completing.get(job.uuid);
      return complete(job, readNativeFiles, expectedCount);
    }
    const action = (async () => {
      let files;
      const delays = [100, 250, 500];
      for (let attempt = 0; attempt <= delays.length; attempt++) {
        const records = await readNativeFiles(job.taskId);
        if (!Array.isArray(records)) throw new Error("Engine chưa trả danh sách ảnh xuất.");
        const seen = new Set();
        files = records.filter(record => record && typeof record.file_path === "string" &&
          IMAGE_EXTENSIONS.has(path.extname(record.file_path).toLowerCase())).filter(record => {
            const key = path.resolve(record.file_path);
            if (seen.has(key)) return false;
            seen.add(key); return true;
          });
        if (files.length === wanted) break;
        if (attempt === delays.length) throw new Error("Số ảnh export chưa khớp; không công bố thành công trước khi ghi đủ file.");
        // Native completion and its database row update may arrive in adjacent
        // turns. Retry this specific race for at most 850 ms, without blocking UI.
        await new Promise(resolve => setTimeout(resolve, delays[attempt]));
      }
      // Validate every name/path first, before publishing even the first image.
      for (const record of files) {
        if (!within(job.stage, record.file_path)) throw new Error("File export không thuộc staging V3.");
        sourceFor(job, record);
      }
      for (const record of files) await publish(job, record);
      job.complete = true;
      save(job);
    })();
    completing.set(job.uuid, action);
    try { await action; } finally { completing.delete(job.uuid); }
  }

  async function decorateEvent(data, readNativeFiles) {
    let event;
    try { event = JSON.parse(data); } catch (_) { return data; }
    const job = find(event.id, event.destpath);
    if (!job) return data;
    if (String(event.status) === "2" || String(event.status) === "3") {
      try {
        await complete(job, readNativeFiles, event.success_count);
      } catch (error) {
        log("publish-error", { taskId: job.taskId, message: error.message });
        // Same native schema/status enumeration. Keep a failed export visible
        // rather than tell the customer images were delivered when they weren't.
        event = { ...event, status: "3" };
        error.exportTaskId = job.taskId;
        error.exportEvent = JSON.stringify({ ...event, destpath: job.destination });
        throw error;
      }
    }
    return JSON.stringify({ ...event, destpath: job.destination });
  }

  async function decorateHistory(items, readNativeFiles) {
    const rows = JSON.parse(items || "[]");
    if (!Array.isArray(rows)) throw new Error("Danh sách export không hợp lệ.");
    for (const row of rows) {
      const job = find(row.id, row.destpath);
      if (!job) continue;
      if (["2", "3"].includes(String(row.status))) {
        try { await complete(job, readNativeFiles, row.succount); }
        catch (error) { row.status = "3"; log("publish-error", { taskId: job.taskId, message: error.message }); }
      }
      row.destpath = job.destination;
    }
    return JSON.stringify(rows);
  }

  function finalPaths(taskId, paths) {
    const job = find(taskId);
    if (!job) return paths;
    return paths.map(file => job.published[path.resolve(file)]).filter(Boolean);
  }

  return { prepare, bind, decorateEvent, decorateHistory, finalPaths };
}

module.exports = { createOutputManager, describeSource, isLocalImageExport, safeName };
