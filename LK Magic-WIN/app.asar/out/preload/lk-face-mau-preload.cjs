'use strict';
const { contextBridge, ipcRenderer } = require('electron');
contextBridge.exposeInMainWorld('LKFaceMau', Object.freeze({
  chooseFolder: () => ipcRenderer.invoke('lk-face-mau:v1:choose-folder'),
  saveJpeg: args => ipcRenderer.invoke('lk-face-mau:v1:write-jpeg', args),
  openFolder: token => ipcRenderer.invoke('lk-face-mau:v1:open-folder', { token })
}));
