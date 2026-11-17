const { contextBridge, ipcRenderer } = require('electron');
contextBridge.exposeInMainWorld('boothSetup', {
    info: () => ipcRenderer.invoke('setup:info'),
    launch: (settings) => ipcRenderer.invoke('setup:launch', settings),
    onStatus: (callback) => ipcRenderer.on('setup:status', (_event, text) => callback(text)),
});
