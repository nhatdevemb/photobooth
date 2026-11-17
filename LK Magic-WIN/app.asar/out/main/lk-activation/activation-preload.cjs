'use strict';
const {contextBridge, ipcRenderer} = require('electron');
contextBridge.exposeInMainWorld('lkActivation', Object.freeze({
  state: () => ipcRenderer.invoke('lk-activation:state'),
  retry: () => ipcRenderer.invoke('lk-activation:retry'),
  copy: () => ipcRenderer.invoke('lk-activation:copy'),
  choose: () => ipcRenderer.invoke('lk-activation:choose'),
  activate: token => ipcRenderer.invoke('lk-activation:activate', typeof token === 'string' ? token : ''),
  support: () => ipcRenderer.invoke('lk-activation:support'),
  quit: () => ipcRenderer.invoke('lk-activation:quit'),
}));
