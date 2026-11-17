'use strict';
const { contextBridge, ipcRenderer } = require('electron');
contextBridge.exposeInMainWorld('LKNativeColorReferences', Object.freeze({
  list: () => ipcRenderer.invoke('lk-native-color-references:v1:list'),
  importImages: options => ipcRenderer.invoke('lk-native-color-references:v1:import', { single: !!(options && options.single) }),
  hide: id => ipcRenderer.invoke('lk-native-color-references:v1:hide', { id }),
  remove: id => ipcRenderer.invoke('lk-native-color-references:v1:remove', { id })
}));
