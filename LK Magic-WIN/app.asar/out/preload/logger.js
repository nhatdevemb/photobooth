"use strict";
Object.defineProperty(exports, Symbol.toStringTag, { value: "Module" });
const electron = require("electron");
const common = require("./chunks/common-CoNahaFE.js");
require("node:path");
async function zipLoggerFiles() {
  const result = await electron.ipcRenderer.invoke("system:zlib-app-logger");
  return result;
}
electron.contextBridge.exposeInMainWorld("CommonUtils", {
  on: common.on,
  once: common.once,
  removeAllListeners: common.removeAllListeners,
  sendStatLog: common.sendStatLog,
  sendStatDebugLog: common.sendStatDebugLog,
  getAppVersion: common.getAppVersion,
  getAppChannelName: common.getAppChannelName,
  queryExperiencePlan: common.queryExperiencePlan,
  setExperiencePlan: common.setExperiencePlan,
  getAppNameAndLogo: common.getAppNameAndLogo,
  openBrowser: common.openBrowser,
  getAppBase: common.getAppBase,
  pathSep: common.pathSep,
  platform: common.platform,
  getAppSysteminformation: common.getAppSysteminformation,
  getLoginStatus: common.getLoginStatus
});
electron.contextBridge.exposeInMainWorld("LoggerUtils", {
  zipLoggerFiles
});
exports.zipLoggerFiles = zipLoggerFiles;
