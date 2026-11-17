'use strict';
const { contextBridge, ipcRenderer } = require('electron');
const invoke = (method, args) => ipcRenderer.invoke('lk-face-preset:v1:' + method, args);
contextBridge.exposeInMainWorld('LKFacePreset', Object.freeze({
  status: () => invoke('status'),
  chooseReference: () => invoke('reference'),
  chooseSource: () => invoke('source'),
  render: args => invoke('render', args),
  close: token => invoke('close', { token }),
  save: args => invoke('save', args),
  diagnostics: () => invoke('diagnostics')
}));
