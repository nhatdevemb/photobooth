"use strict";
const electron = require("electron");
const common = require("./chunks/common-CoNahaFE.js");
require("node:path");
async function isOpenAtLogin() {
  const result = await electron.ipcRenderer.invoke("system:is-open-at-login");
  return result;
}
function setAutoLaunch(status) {
  electron.ipcRenderer.send("system:set-auto-launch", status);
}
function setFileCacheClearDay(day) {
  electron.ipcRenderer.send("system:set-cache-auth-clear-day", day);
}
function getFileCacheClearDay() {
  return electron.ipcRenderer.invoke("cache:get-cache-auth-clear-day");
}
function setLocalSetting(key, value) {
  electron.ipcRenderer.send("system:set-setting", key, value);
}
function getLocalSetting(key) {
  return electron.ipcRenderer.invoke("system:get-setting", key);
}
function getLocalComplexSetting(key) {
  return electron.ipcRenderer.invoke("system:get-complex-setting", key);
}
function setPannelInfo(option, value) {
  electron.ipcRenderer.send("system:set-pannel-info", option, value);
}
function getPannelInfo(option) {
  return electron.ipcRenderer.invoke("system:get-pannel-info", option);
}
function getRecommendLocalSetting(key) {
  return electron.ipcRenderer.invoke("system:get-recommend-setting", key);
}
function getDiskInfo() {
  return electron.ipcRenderer.invoke("system:get-disk-info");
}
function clearLocalSetting() {
  electron.ipcRenderer.send("file:clear-cache");
}
function setWinHide() {
  electron.ipcRenderer.send("set:windows-hide");
}
function setChange(data) {
  electron.ipcRenderer.send("set:change:from-set", data);
}
electron.contextBridge.exposeInMainWorld("CommonUtils", {
  on: common.on,
  once: common.once,
  addEventListener: common.addEventListener,
  removeListenerByKey: common.removeListenerByKey,
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
  getModelVersion: common.getModelVersion,
  setLocalSetting,
  setChange,
  getLocalSetting,
  getLocalComplexSetting,
  setPannelInfo,
  getPannelInfo,
  getRecommendLocalSetting,
  clearLocalSetting,
  pathSep: common.pathSep,
  platform: common.platform,
  getLoginStatus: common.getLoginStatus,
  getDiskInfo
});
electron.contextBridge.exposeInMainWorld("SetUtils", {
  isOpenAtLogin,
  setAutoLaunch,
  setExperiencePlan: common.setExperiencePlan,
  setFileCacheClearDay,
  getFileCacheClearDay,
  setWinHide
});
