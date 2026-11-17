"use strict";
const electron = require("electron");
const node_path = require("node:path");
const IMAGE_EXT = [
  "jpg",
  "png",
  "jpeg",
  "crw",
  "cr2",
  "cr3",
  "kdc",
  "mrw",
  "nef",
  "orf",
  "dng",
  "ptx",
  "pef",
  "arw",
  // 'x3f',
  "rw2",
  "raf",
  "raw",
  "nrw",
  "iiq",
  "erf",
  "k25",
  "dcr",
  "mef",
  "sr2",
  "srf",
  "x3f",
  "rwl",
  "3fr",
  "srw",
  // 'heic',
  "tiff",
  "fff"
  // 备注： 加了Định dạng， 要确认下là否làraw， 如果làraw要在rawList也加一下
];
const PAN_IMAGE_EXT = [
  "jpe",
  "cur",
  "tif",
  "tiff",
  "ico",
  "jpg",
  "jpeg",
  "bmp",
  "png",
  "heic",
  "heif",
  "avci",
  "livp",
  "cr2",
  "arw",
  "nef",
  "dng"
];
const VIDEO_EXT = ["mp4", "mov", "avi"];
[.../* @__PURE__ */ new Set([...IMAGE_EXT, ...PAN_IMAGE_EXT, ...VIDEO_EXT])];
const listener = /* @__PURE__ */ new Map();
function addEventListener(channel, callback) {
  const count = electron.ipcRenderer.listenerCount(channel);
  const key = channel + count;
  listener.set(key, callback);
  electron.ipcRenderer.on(channel, callback);
  return key;
}
function on(channel, callback) {
  electron.ipcRenderer.on(channel, (event, data, ...args) => {
    try {
      callback(data, ...args);
    } catch (e) {
    }
  });
}
function once(channel, callback) {
  electron.ipcRenderer.once(channel, (event, data) => {
    try {
      callback(data);
    } catch (e) {
    }
  });
}
function removeAllListeners(channel) {
  electron.ipcRenderer.removeAllListeners(channel);
}
function removeListener(channel, fn) {
  electron.ipcRenderer.removeListener(channel, fn);
}
function removeListenerByKey(channel, fn) {
  electron.ipcRenderer.removeListener(channel, listener.get(fn));
  listener.delete(fn);
}
function sendStatLog(logs) {
  electron.ipcRenderer.send("log:stat-log", logs);
}
function clearInitTask() {
  electron.ipcRenderer.send("common:clear-task");
}
function queryDatabaseStatus() {
  return electron.ipcRenderer.invoke("common:query-database-status");
}
function forceStopVacuumDB() {
  electron.ipcRenderer.invoke("common:force_stop_vacuum_db");
}
function sendStatDebugLog(type, logs) {
  electron.ipcRenderer.send("log:stat-debug-log", type, logs);
}
async function getAppVersion() {
  const result = await electron.ipcRenderer.invoke("system:get-app-version");
  return result;
}
async function getAppChannelName() {
  const appChannelName = await electron.ipcRenderer.invoke("system:get-app-channel-name");
  return appChannelName;
}
async function getRendererEnd(key) {
  const result = await electron.ipcRenderer.invoke("system:get-renderer-end", key);
  return result;
}
async function deleteFiles(paths) {
  const result = await electron.ipcRenderer.invoke("system:deleteFile", paths);
  return result;
}
function browserJump(url, params, login) {
  electron.ipcRenderer.send("system:browser-jump", url, params, login);
}
async function queryExperiencePlan() {
  const result = await electron.ipcRenderer.invoke("system:is-open-at-user-plan");
  return result;
}
function setExperiencePlan(status) {
  return electron.ipcRenderer.invoke("system:set-user-plan-setting", status);
}
function getAppNameAndLogo(data) {
  return electron.ipcRenderer.invoke("system:get-apaas-user-data", data);
}
function openBrowser(url) {
  electron.ipcRenderer.send("window:open-browser", url);
}
async function getAppBase() {
  const result = await electron.ipcRenderer.invoke("system:get-app-base");
  return result;
}
async function getModelVersion() {
  const result = await electron.ipcRenderer.invoke("system:get-model-version");
  return result;
}
async function getAppSysteminformation() {
  const result = await electron.ipcRenderer.invoke("system:get-app-systeminformation");
  return result;
}
function saveLogToLocal(str) {
  electron.ipcRenderer.send("log:save-log-to-local", str);
}
function isSupportExt(name) {
  const extension = name.split(".").pop()?.toLowerCase();
  return !!extension && IMAGE_EXT.includes(extension);
}
const platform = {
  isMacOS: process.platform === "darwin",
  isWindows: process.platform === "win32",
  isLinux: process.platform === "linux",
  value: process.platform,
  arch: process.arch
};
const env = {
  isDev: process.env.NODE_ENV === "development"
};
const pathSep = node_path.sep;
async function getLoginStatus() {
  const result = await electron.ipcRenderer.invoke("system:get-login-status");
  return result;
}
function sendQuitChoice(choice) {
  electron.ipcRenderer.send("system:quit-choice", { choice });
}
exports.addEventListener = addEventListener;
exports.browserJump = browserJump;
exports.clearInitTask = clearInitTask;
exports.deleteFiles = deleteFiles;
exports.env = env;
exports.forceStopVacuumDB = forceStopVacuumDB;
exports.getAppBase = getAppBase;
exports.getAppChannelName = getAppChannelName;
exports.getAppNameAndLogo = getAppNameAndLogo;
exports.getAppSysteminformation = getAppSysteminformation;
exports.getAppVersion = getAppVersion;
exports.getLoginStatus = getLoginStatus;
exports.getModelVersion = getModelVersion;
exports.getRendererEnd = getRendererEnd;
exports.isSupportExt = isSupportExt;
exports.on = on;
exports.once = once;
exports.openBrowser = openBrowser;
exports.pathSep = pathSep;
exports.platform = platform;
exports.queryDatabaseStatus = queryDatabaseStatus;
exports.queryExperiencePlan = queryExperiencePlan;
exports.removeAllListeners = removeAllListeners;
exports.removeListener = removeListener;
exports.removeListenerByKey = removeListenerByKey;
exports.saveLogToLocal = saveLogToLocal;
exports.sendQuitChoice = sendQuitChoice;
exports.sendStatDebugLog = sendStatDebugLog;
exports.sendStatLog = sendStatLog;
exports.setExperiencePlan = setExperiencePlan;
