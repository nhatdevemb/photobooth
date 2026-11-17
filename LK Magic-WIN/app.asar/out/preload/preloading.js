"use strict";
const electron = require("electron");
const common = require("./chunks/common-CoNahaFE.js");
require("node:path");
function closeLoginPage() {
  electron.ipcRenderer.send("system:close-login-page");
}
function loadLoginPage() {
  electron.ipcRenderer.send("system:load-login-page");
}
function loadMainWindow() {
  electron.ipcRenderer.send("system:load-main-window");
}
function closePreloadingWindow() {
  electron.ipcRenderer.send("system:close-preloading-window");
}
function checkHardwareSpecs() {
  electron.ipcRenderer.send("system:check-hardware-specs");
}
function initEnterprise(cid) {
  return electron.ipcRenderer.invoke("system:verify-enterprise", cid);
}
electron.contextBridge.exposeInMainWorld("CommonUtils", {
  on: common.on,
  once: common.once,
  removeAllListeners: common.removeAllListeners,
  addEventListener: common.addEventListener,
  removeListenerByKey: common.removeListenerByKey,
  sendStatLog: common.sendStatLog,
  sendStatDebugLog: common.sendStatDebugLog,
  getAppVersion: common.getAppVersion,
  getAppChannelName: common.getAppChannelName,
  queryExperiencePlan: common.queryExperiencePlan,
  setExperiencePlan: common.setExperiencePlan,
  getAppNameAndLogo: common.getAppNameAndLogo,
  openBrowser: common.openBrowser,
  getAppBase: common.getAppBase,
  getAppSysteminformation: common.getAppSysteminformation,
  pathSep: common.pathSep,
  platform: common.platform,
  getLoginStatus: common.getLoginStatus,
  initEnterprise
});
electron.contextBridge.exposeInMainWorld("PreloadingUtils", {
  closeLoginPage,
  loadLoginPage,
  loadMainWindow,
  closePreloadingWindow,
  checkHardwareSpecs
});
