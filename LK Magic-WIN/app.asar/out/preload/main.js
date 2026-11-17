"use strict";
const electron = require("electron");
const common = require("./chunks/common-CoNahaFE.js");
require("node:path");
function testLoadFileBase64() {
  return electron.ipcRenderer.invoke("test:load-file-base64");
}
function testLoadFileBlob() {
  electron.ipcRenderer.send("test:load-file-blob");
}
function testSetOpenglCanvas(status) {
  electron.ipcRenderer.send("test:init-opengl-canvas", status);
}
function testGetFilePath(filename) {
  return electron.ipcRenderer.invoke("test:get-file-path", filename);
}
function testStringIpcTime(str) {
  return electron.ipcRenderer.invoke("test:string-ipc-time", str);
}
async function getAppPath(name) {
  return await electron.ipcRenderer.invoke("system:get-app-path", name);
}
async function getApaasProjectId() {
  const result = await electron.ipcRenderer.invoke("project:get-apaas-project-id");
  return result;
}
function createApaasProjectAndImport() {
  electron.ipcRenderer.send("project:create-apaas-project-and-import");
}
async function getApaasParams() {
  const result = await electron.ipcRenderer.invoke("system:get-apaas-params");
  return result;
}
async function getUserData() {
  const result = await electron.ipcRenderer.invoke("system:get-user-data");
  return result;
}
function queryUserSetting(arg) {
  return electron.ipcRenderer.invoke("system:query-user-setting", arg);
}
function loadFolder(defaultPath) {
  return electron.ipcRenderer.invoke("file:choose-folder", defaultPath);
}
function getFolderStat(path) {
  return electron.ipcRenderer.invoke("file:folder-stat", path);
}
function addProject(data) {
  electron.ipcRenderer.send("project:add-project", data);
}
function getProjectList(data) {
  electron.ipcRenderer.send("project:get-project-list", data);
}
async function createClassifyShare(data) {
  return await electron.ipcRenderer.invoke("project:get_qr_code", data);
}
function addFilterImageExportProject(name) {
  electron.ipcRenderer.send("project_filter-img:add-export-project", name);
}
async function getWorkspaceById(id) {
  const res = await electron.ipcRenderer.invoke("project:get-project-by-id", id);
  return res;
}
function deleteProject(data) {
  electron.ipcRenderer.send("project:delete-project", data);
}
function modifyProject(data) {
  electron.ipcRenderer.send("project:modify-project", data);
}
async function chooseFile(data) {
  return await electron.ipcRenderer.invoke("file:choose-file", data);
}
async function selectImagePath(data) {
  return await electron.ipcRenderer.invoke("file:select-image-path", data || {});
}
function getPathForFile(file) {
  if (!file) return "";
  try {
    if (electron.webUtils && typeof electron.webUtils.getPathForFile === "function") {
      return electron.webUtils.getPathForFile(file) || "";
    }
  } catch (_) {}
  return typeof file.path === "string" ? file.path : "";
}
async function importFile(data) {
  return await electron.ipcRenderer.invoke("file:import-file", data);
}
function importFilterImageFile(data) {
  electron.ipcRenderer.send("file_filter-img:import-filter-img-file", data);
}
async function importImageFile(data) {
  return await electron.ipcRenderer.invoke("rongtu:choose-aicreate-file", data);
}
async function exportImageFile(data) {
  return await electron.ipcRenderer.invoke("rongtu:upload-aicreate-file", data);
}
async function delUserSticker(data) {
  return electron.ipcRenderer.invoke("rongtu:del-user-sticker", data);
}
async function getUserSticker(data) {
  return electron.ipcRenderer.invoke("rongtu:get-user-sticker", data);
}
async function getSubSticker(data) {
  return electron.ipcRenderer.invoke("rongtu:get-sub-sticker", data);
}
async function getPubSticker(data) {
  return electron.ipcRenderer.invoke("rongtu:get-pub-sticker", data);
}
async function allowCreate(data) {
  return electron.ipcRenderer.invoke("rongtu:allow-create", data);
}
async function imgCreate(data) {
  return electron.ipcRenderer.invoke("rongtu:imgcreate", data);
}
async function imgReCreate(data) {
  return electron.ipcRenderer.invoke("rongtu:imgrecreate", data);
}
async function batchRecorddetail(data) {
  return electron.ipcRenderer.invoke("rongtu:batch-record", data);
}
async function batchRecordCanvas(data) {
  return electron.ipcRenderer.invoke("rongtu:canvas-storage", data);
}
async function getFileMetaByID(data) {
  return electron.ipcRenderer.invoke("rongtu:get-file-meta", data);
}
async function dragChooseFolder(args, data) {
  return await electron.ipcRenderer.invoke("file:drag-choose-folder", args, data);
}
async function importChooseFolder(args) {
  return await electron.ipcRenderer.invoke("file:import-choose-folder", args);
}
function batchRefreshThumbnailByPath(items) {
  electron.ipcRenderer.send("file:batch-refresh-thumbnail-by-path", items);
}
function showPathItemInFolder(path) {
  electron.ipcRenderer.send("file:show-item-in-folder", path);
}
function openFilePath(path) {
  electron.ipcRenderer.send("file:open-path", path);
}
function insertPresetGroup(params) {
  electron.ipcRenderer.send("preset:insert-preset-group", params);
}
function getPresetGroup() {
  electron.ipcRenderer.send("preset:get-preset-group");
}
function renamePresetGroup(params) {
  electron.ipcRenderer.send("preset:rename-preset-group", JSON.stringify(params));
}
function deletePresetGroup(data) {
  electron.ipcRenderer.send("preset:delete-preset-group", data);
}
function insertPresetItem(data) {
  electron.ipcRenderer.send("preset:insert-preset-item", data);
}
function changePresetItem(data) {
  electron.ipcRenderer.send("preset:change-preset-item", data);
}
function getPresetItem(data) {
  electron.ipcRenderer.send("preset:get-preset-item", data);
}
async function getPersonItemsByIds(data) {
  return await electron.ipcRenderer.invoke("person:get-person-items-by-ids", data);
}
async function deletePersonItemsByIds(data) {
  return await electron.ipcRenderer.invoke("person:delete-person-items-by-ids", data);
}
function deletePresetItem(data) {
  electron.ipcRenderer.send("preset:delete-preset-item", data);
}
function presetGenToken(data) {
  return electron.ipcRenderer.invoke("preset:share-token", data);
}
function queryPanDirCount(data) {
  return electron.ipcRenderer.invoke("import:query-pan-dir-count", data);
}
function queryPanDirCountStatus(data) {
  return electron.ipcRenderer.invoke("import:query-pan-dir-count-status", data);
}
function presetParseToken(data) {
  return electron.ipcRenderer.invoke("preset:parse-token", data);
}
function getPresetTokenConfig(data) {
  return electron.ipcRenderer.invoke("preset:get-preset-token-config", data);
}
function presetTokenImport(data) {
  return electron.ipcRenderer.invoke("preset:token-import", data);
}
function getServerPresetItem(data) {
  return electron.ipcRenderer.invoke("preset:get-server-preset-list", data);
}
function getServerPresetAll(data) {
  return electron.ipcRenderer.invoke("preset:get-server-preset-all", data);
}
function addServerPresetItem(data) {
  return electron.ipcRenderer.invoke("preset:add-server-preset", data);
}
function updateServerPresetItem(data) {
  return electron.ipcRenderer.invoke("preset:update-server-preset", data);
}
function getServerGroupList(data) {
  return electron.ipcRenderer.invoke("preset:get-server-preset-group", data);
}
function addServerGroupList(data) {
  return electron.ipcRenderer.invoke("preset:add-server-preset-group", data);
}
function updateServerGroupList(data) {
  return electron.ipcRenderer.invoke("preset:update-server-preset-group", data);
}
async function getFileList(data, sort = "") {
  return electron.ipcRenderer.invoke("file:get-file-list", data, sort);
}
async function getMultiFileList(data) {
  return electron.ipcRenderer.invoke("file:get-multi-file-list", data);
}
function getFilterImageList(data) {
  electron.ipcRenderer.send("file:get-filter-image-list", data);
}
function modifyFilterImageItem(data, type) {
  electron.ipcRenderer.send("file:modify-filter-image-item", data, type);
}
function getFileCache(params) {
  return electron.ipcRenderer.invoke("file:get-file-cache", params);
}
function modifyFileItem(data, args) {
  electron.ipcRenderer.send("file:modify-file-item", data, args);
}
function modifyFileItemMark(data, args) {
  electron.ipcRenderer.send("file:modify-file-item-mark", data, args);
}
function getRGBA(data) {
  electron.ipcRenderer.send("file:get-img-RGBA", data);
}
function insertFileCache(params) {
  return electron.ipcRenderer.invoke("file:insert-file-cache", params);
}
function pastedFileCache(ids, newParam, other) {
  return electron.ipcRenderer.invoke("file:pasted-file-cache", ids, newParam, other);
}
function clearFileCache(ids) {
  return electron.ipcRenderer.invoke("file:clear-file-cache", ids);
}
function undoFileCache(id) {
  electron.ipcRenderer.send("file:undo-file-cache", id);
}
function redoFileCache(id) {
  electron.ipcRenderer.send("file:redo-file-cache", id);
}
function deleteFile(params) {
  electron.ipcRenderer.send("file:delete-file", params);
}
function addBackground() {
  electron.ipcRenderer.send("file:add-image-background");
}
function getBackgroundInfo() {
  electron.ipcRenderer.send("file:get-image-background");
}
function delBackgroundInfo(del_image_list) {
  electron.ipcRenderer.send("file:del-image-background", del_image_list);
}
async function getExportTickets(args) {
  return await electron.ipcRenderer.invoke("export:get-export-tickets", args);
}
async function getImitationInfo(args) {
  return await electron.ipcRenderer.invoke("file:get-imitation-info", args);
}
async function delImitationInfo(args) {
  return await electron.ipcRenderer.invoke("file:del-imitation-info", args);
}
function addMaterials(args) {
  return electron.ipcRenderer.invoke("file:add-materials", args);
}
function addMaterialsDirect(args) {
  return electron.ipcRenderer.invoke("file:add-materials-direct", args);
}
function uploadMaterials(args) {
  return electron.ipcRenderer.invoke("file:upload-materials", args);
}
function selectMaterials(args) {
  return electron.ipcRenderer.invoke("file:select-materials", args);
}
function getFileDetection(data) {
  electron.ipcRenderer.send("file:get-file-detection", data);
}
function modifyFileDetection(data) {
  electron.ipcRenderer.send("file:modify-file-detection", data);
}
function syncModifyFileDetection(data) {
  return electron.ipcRenderer.invoke("file:sync-modify-file-detection", data);
}
function modifyFileDetectionFeedback(data) {
  electron.ipcRenderer.send("file:modify-file-detection-feedback", data);
}
function clearFileThumbnail() {
  electron.ipcRenderer.send("file:clear-cache");
}
function getFileExifInfo(path) {
  electron.ipcRenderer.send("file:get-img-exif-info", { path });
}
function getFilterImageExifInfo(data) {
  electron.ipcRenderer.send("file:get-filter-img-exif-info", data);
}
function getGenerateThumbnail(data) {
  electron.ipcRenderer.send("file:get-generate-thumbnail", data);
}
async function isLocalFile(data) {
  const result = await electron.ipcRenderer.invoke("file:is-local-file", data);
  return result;
}
async function batchCheckLocalFile(data) {
  const result = await electron.ipcRenderer.invoke("file:batch-check-local-file", data);
  return result;
}
async function relinkLocalFile(data) {
  const result = await electron.ipcRenderer.invoke("file:relink-local-file", data);
  return result;
}
async function generateFileThumbnail(data) {
  const result = await electron.ipcRenderer.invoke("file:generate-file-thumbnail", data);
  return result;
}
function getFileBase64(data) {
  electron.ipcRenderer.send("file:get-file-base64", data);
}
function windowChangeSize(type) {
  electron.ipcRenderer.send("window:change-size", type);
}
function beforeQuit() {
  electron.ipcRenderer.send("system:before-quit");
}
function windowIsMaximized() {
  return electron.ipcRenderer.invoke("window:is-maximized");
}
function getAlgorithmsItem(data) {
  electron.ipcRenderer.send("beauty:select-algorithms-item", data);
}
function logoutLogin() {
  electron.ipcRenderer.send("system:logout-login");
}
function getPanList(data) {
  return electron.ipcRenderer.invoke("system:get-pan-list", data);
}
function getPickProjectList(data) {
  return electron.ipcRenderer.invoke("project:get-pick-project-list", data);
}
function getPickProjectSearchList(data) {
  return electron.ipcRenderer.invoke("project:get-pick-search-list", data);
}
function getPickProjectInfo(data) {
  return electron.ipcRenderer.invoke("project:get-pick-project-info", data);
}
function createPickProject(args) {
  return electron.ipcRenderer.invoke("project:create-pick-project", args);
}
function textRiskCheck(args) {
  return electron.ipcRenderer.invoke("project:text-risk-check", args);
}
function deletePickProject(args) {
  return electron.ipcRenderer.invoke("project:delete-pick-project", args);
}
function setPickProjectInfo(args) {
  return electron.ipcRenderer.invoke("project:set-pick-project-info", args);
}
function setPickPhotoset(args) {
  return electron.ipcRenderer.invoke("project:set-pick-photo-set", args);
}
function getPickMpQrCode(args) {
  return electron.ipcRenderer.invoke("project:get-pick-mp-qrcode", args);
}
function uploadImageOcr(args) {
  return electron.ipcRenderer.invoke("project:upload-image-ocr", args);
}
function uploadWaterImg(args) {
  return electron.ipcRenderer.invoke("project:upload-mp-waterimg", args);
}
function getInviteInfo(args) {
  return electron.ipcRenderer.invoke("project:get-invite-info", args);
}
function getMyIncome(args) {
  return electron.ipcRenderer.invoke("project:get-my-income", args);
}
function queryApplyInfo(args) {
  return electron.ipcRenderer.invoke("project:query-apply-info", args);
}
function getUserCombo(args) {
  return electron.ipcRenderer.invoke("project:get-user-combo", args);
}
function submitApplyInfo(args) {
  return electron.ipcRenderer.invoke("project:submit-apply-info", args);
}
function getOrderInfo(args) {
  return electron.ipcRenderer.invoke("project:get-order-info", args);
}
function refundOrder(args) {
  return electron.ipcRenderer.invoke("project:refund-order", args);
}
function getRefundStatus(args) {
  return electron.ipcRenderer.invoke("project:get-refund-status", args);
}
function createSharedCloudSpace(args) {
  return electron.ipcRenderer.invoke("project:create-shared-cloud-sapce", args);
}
function modifySharedCloudSpace(args) {
  return electron.ipcRenderer.invoke("project:modify-shared-cloud-sapce", args);
}
function getCloudSpaceProductList(args) {
  return electron.ipcRenderer.invoke("project:cloud-space-product-list", args);
}
function getCanTryComboSharedSpace(args) {
  return electron.ipcRenderer.invoke("project:cantrycombo-shared-cloud-sapce", args);
}
function tryComboSharedSpace(args) {
  return electron.ipcRenderer.invoke("project:trycombo-shared-cloud-sapce", args);
}
function getSharedCloudSpace() {
  return electron.ipcRenderer.invoke("project:get-shared-cloud-sapce");
}
function createSharedCloudSpaceLink(args) {
  return electron.ipcRenderer.invoke("project:create-shared-cloud-sapce-link", args);
}
function deleteSharedSpaceMember(args) {
  return electron.ipcRenderer.invoke("cloud-space:delete-shared-space-member", args);
}
function getAmisConfig(args) {
  return electron.ipcRenderer.invoke("cloud-space:get-amis-config", args);
}
function getOperatorPanelData() {
  return electron.ipcRenderer.invoke("system:get-operator-panel");
}
function getCouponConsumeAmis() {
  return electron.ipcRenderer.invoke("system:get-coupon-consume");
}
function getOrderListAmis() {
  return electron.ipcRenderer.invoke("system:get-orderlist-amis");
}
function getHeaderDropdownData() {
  return electron.ipcRenderer.invoke("system:get-header-dropdown-data");
}
function getProjectBannerData() {
  return electron.ipcRenderer.invoke("system:get-project-banner-data");
}
function getHeaderHotActivity() {
  return electron.ipcRenderer.invoke("system:get-header-hot-activity");
}
function getHeaderCrowdPack() {
  return electron.ipcRenderer.invoke("system:get-header-hot-crowd_pack");
}
function addImportPanTask(data) {
  return electron.ipcRenderer.invoke("import:pan-task", data);
}
function addImportUploadPanTask(data) {
  return electron.ipcRenderer.invoke("import:upload-pan-task", data);
}
function queryImportPanTasks(data) {
  return electron.ipcRenderer.invoke("import:query-pan-tasks", data);
}
function queryTaskCount(data) {
  return electron.ipcRenderer.invoke("import:query-task-count", data);
}
function controlPanTasks(data) {
  return electron.ipcRenderer.invoke("import:control-tasks", data);
}
function controlPanTasksByFiles(data) {
  return electron.ipcRenderer.invoke("import:control-tasks-by-files", data);
}
function getEnterpriseGroupList(data) {
  return electron.ipcRenderer.invoke("system:get-enterprise-group-list", data);
}
function verifyShareLinkPwd(data) {
  return electron.ipcRenderer.invoke("pan-share-import:verify-pwd", data);
}
function queryShareLinkInfo(data) {
  return electron.ipcRenderer.invoke("pan-share-import:query-info", data);
}
function queryShareLinkCaptcha(data) {
  return electron.ipcRenderer.invoke("pan-share-import:query-captcha", data);
}
function queryShareLinkList(data) {
  return electron.ipcRenderer.invoke("pan-share-import:query-list", data);
}
function createShareLinkUploadTask(data) {
  return electron.ipcRenderer.invoke("pan-share-import:create-upload-task", data);
}
function panShareTransfer(data) {
  return electron.ipcRenderer.invoke("pan-share-import:share-transfer", data);
}
function panShareTransferStatus(data) {
  return electron.ipcRenderer.invoke("pan-share-import:share-transfer-status", data);
}
function setSignKey(data) {
  return electron.ipcRenderer.invoke("system:set-sign-key", data);
}
async function getRevisionVoucher(data) {
  const result = await electron.ipcRenderer.invoke("system:get-revision-voucher", data);
  return result;
}
async function getPersonalPortrait() {
  const result = await electron.ipcRenderer.invoke("system:get-personal-portrait");
  return result;
}
async function getBanInfo(data) {
  const result = await electron.ipcRenderer.invoke("system:query-ban-info", data);
  return result;
}
function quickReport(args) {
  electron.ipcRenderer.send("quick:report-data", args);
}
function quickgetConf(args) {
  electron.ipcRenderer.send("quick:get-conf", args);
}
async function getUserPickupDetails(data) {
  const result = await electron.ipcRenderer.invoke("system:query-user-10-vouchers", data);
  return result;
}
async function getFreeAlgorithmConfig() {
  const result = await electron.ipcRenderer.invoke("system:get-free-algorithm-config");
  return result;
}
async function getTenVouchers(data) {
  const result = await electron.ipcRenderer.invoke("system:get-ten-vouchers", data);
  return result;
}
function imageExportRetouch(data) {
  electron.ipcRenderer.send("beauty:aipic-export-retouch-image", data);
}
function imageExportDetect(data) {
  electron.ipcRenderer.send("beauty:aipic-export-detect-image", data);
}
function addExportTask(data) {
  return electron.ipcRenderer.invoke("export:add-export-task", data);
}
function exportSoftware(data) {
  return electron.ipcRenderer.invoke("export:add-export-software", data);
}
function exportElectApplication() {
  return electron.ipcRenderer.invoke("export:select-application");
}
function exportToFilter(data) {
  return electron.ipcRenderer.invoke("export:to-fliter", data);
}
function exportToClassify(data) {
  return electron.ipcRenderer.invoke("export:to-classify", data);
}
function exportToSelect(data) {
  return electron.ipcRenderer.invoke("export:to-select", data);
}
function exportToSelectCloud(data) {
  return electron.ipcRenderer.invoke("export:to-select-cloud", data);
}
function exportToAIPhoto(data) {
  return electron.ipcRenderer.invoke("export:to-aiphoto", data);
}
function getExportAllProgress() {
  electron.ipcRenderer.send("export:get-export-all-progress");
}
function exprotTaskPause(data) {
  electron.ipcRenderer.send("export:task-pause", data);
}
function queryExportHistory() {
  electron.ipcRenderer.send("export:get-history-list");
}
function exprotTaskStart(data) {
  electron.ipcRenderer.send("export:task-start", data);
}
function exprotTaskRestart(data) {
  electron.ipcRenderer.send("export:task-restart", data);
}
function queryEnterpriseList(data) {
  return electron.ipcRenderer.invoke("system:get-enterprise-list", data);
}
function switchEnterprise(data) {
  return electron.ipcRenderer.invoke("system:switch-enterprise", data);
}
function deleteHistory(data) {
  electron.ipcRenderer.send("export:delete-task", data);
}
function readDragFileList() {
  electron.ipcRenderer.send("file:read-drag-file-list");
}
function handleExtJsonCache() {
  electron.ipcRenderer.send("file:ext-json");
}
function modifyHistory(data) {
  electron.ipcRenderer.send("history:modify-history", data);
}
function storeShareLink(data) {
  return electron.ipcRenderer.invoke("export:store-share", data);
}
function openSetWin({ menu = 0 }) {
  electron.ipcRenderer.send("window:open-set-win", { menu });
}
async function getFaultReporting(args) {
  return await electron.ipcRenderer.invoke("system:get-fault-reporting", args);
}
async function getPhoneAuthorized(data) {
  const result = await electron.ipcRenderer.invoke("system:get-phone-authorized", data);
  return result;
}
function fetchTicketNum(data) {
  return electron.ipcRenderer.invoke("system:query-user-ticket", data);
}
function fetchOrderList(data) {
  return electron.ipcRenderer.invoke("system:query-user-order", data);
}
function setContactData(arg) {
  return electron.ipcRenderer.invoke("system:set-contact-data", arg);
}
function postContactForm(arg) {
  return electron.ipcRenderer.invoke("system:post-contact-form", arg);
}
function createShareLink(arg) {
  return electron.ipcRenderer.invoke("export:create-share-link", arg);
}
function getExportErrorDetail(arg) {
  return electron.ipcRenderer.invoke("export:get-error-detail", arg);
}
function sendPhoneCode(arg, query) {
  return electron.ipcRenderer.invoke("system:send-phone-code", arg, query);
}
function getContactAmisData() {
  return electron.ipcRenderer.invoke("system:get-contact-amis-data");
}
function getAmisData(arg) {
  return electron.ipcRenderer.invoke("system:get-amis-data", arg);
}
async function setPhoneAuthorized(data) {
  const result = await electron.ipcRenderer.invoke("system:set-phone-authorized", data);
  return result;
}
function getNetworkStatus() {
  return electron.ipcRenderer.invoke("system:get-network-status");
}
function setSplitView(params) {
  return electron.ipcRenderer.invoke("canvas:set-split-view", params);
}
function updateReferenceView(params) {
  return electron.ipcRenderer.invoke("canvas:update-reference-view", params);
}
function setReferenceByAISimulation(params) {
  return electron.ipcRenderer.invoke("canvas:set-reference-by-aisimulation", params);
}
function systemPrompt(data) {
  electron.ipcRenderer.send("system:show-system-prompt", data);
}
function preheatPicPreview(ids) {
  electron.ipcRenderer.send("file:preheat-pic-preview", ids);
}
async function switchCurrentProjectInfo(info) {
  const result = await electron.ipcRenderer.invoke("current:switch-project-info", info);
  return result;
}
async function switchCurrentCanvasInfo(info) {
  const result = await electron.ipcRenderer.invoke("current:switch-canvas-info", info);
  return result;
}
function updateListThumbnail(id, thumbnail = "") {
  electron.ipcRenderer.send("file:update-list-thumbnail", id, thumbnail);
}
function nativeCanvasChangeSize(params) {
  electron.ipcRenderer.send("nativecanvas:change-size", params);
}
function nativeCanvasClearCanvas() {
  electron.ipcRenderer.send("nativecanvas:clear-canvas");
}
function nativeCanvasSwitchImage(params) {
  electron.ipcRenderer.send("nativecanvas:switch-image", params);
}
function nativeCanvasMouseHandle(type, data) {
  electron.ipcRenderer.send("nativecanvas:window-mouse-handle", type, data);
}
function nativeCanvasRetouchImage(data) {
  electron.ipcRenderer.send("nativecanvas:retouch-image", data);
}
function nativeCanvasZoom(data) {
  electron.ipcRenderer.send("nativecanvas:canvas-zoom", data);
}
function nativeCanvasRetouchOperation(data) {
  electron.ipcRenderer.send("nativecanvas:retouch-operation", data);
}
function autoUpdateCheckUpdate() {
  electron.ipcRenderer.send("auto-update:check-update");
}
function autoUpdateBeginDownload() {
  electron.ipcRenderer.send("auto-update:begin-download");
}
function autoUpdateQuitInstall() {
  electron.ipcRenderer.send("auto-update:quit-install");
}
function downloadFile(filePath) {
  electron.ipcRenderer.send("download-file", filePath);
}
async function getHardwareSpecs() {
  return await electron.ipcRenderer.invoke("system:get-hardware-specs");
}
async function getDiskSpaceForSystemDrive() {
  return await electron.ipcRenderer.invoke("system:get-disk-space");
}
async function createBgRetouchTask(params) {
  return await electron.ipcRenderer.invoke("retouch:bg-retouch-task", params);
}
async function liquidBegin(params) {
  return await electron.ipcRenderer.invoke("retouch:liquid-begin", params);
}
async function liquidCancel(params) {
  return await electron.ipcRenderer.invoke("retouch:liquid-cancel", params);
}
async function liquidConfirm(params) {
  return await electron.ipcRenderer.invoke("retouch:liquid-confirm", params);
}
async function liquidHistory(params) {
  return await electron.ipcRenderer.invoke("retouch:liquid-history", params);
}
async function deleteBgRetouchTask(params) {
  return await electron.ipcRenderer.invoke("retouch:delete-bg-task", params);
}
async function getOperateHistory(params) {
  return await electron.ipcRenderer.invoke("retouch:operate-history-list", params);
}
function setLocalSetting(key, value) {
  electron.ipcRenderer.send("system:set-setting", key, value);
}
function setChange(data) {
  electron.ipcRenderer.send("set:change:from-main", data);
}
function getLocalSetting(key) {
  return electron.ipcRenderer.invoke("system:get-setting", key);
}
function getLocalComplexSetting(key) {
  return electron.ipcRenderer.invoke("system:get-complex-setting", key);
}
function getPath(name) {
  return electron.ipcRenderer.invoke("system:get-path", name);
}
function saveImg({ info, data }) {
  return electron.ipcRenderer.invoke("system:save-img", { info, data });
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
async function getHistogramData(fileid) {
  return await electron.ipcRenderer.invoke("system:get-file-histogram-data", fileid);
}
async function getColorHistogram(params) {
  return await electron.ipcRenderer.invoke("system:get-color-histogram", params);
}
async function getCurveData(data) {
  return await electron.ipcRenderer.invoke("system:get-curve-data", data);
}
function startImageHandle(data) {
  return electron.ipcRenderer.invoke("status-handle:start", data);
}
function restartImageHandle(data) {
  return electron.ipcRenderer.invoke("status-handle:restart", data);
}
function stopImageHandle(data) {
  return electron.ipcRenderer.invoke("status-handle:stop", data);
}
function pauseImageHandle(data) {
  return electron.ipcRenderer.invoke("status-handle:pause", data);
}
function resumeImageHandle(data) {
  return electron.ipcRenderer.invoke("status-handle:resume", data);
}
function initStatusCbHandle(data) {
  electron.ipcRenderer.send("status-init:set-status-callback", data);
}
function addVirtualPerson(data) {
  electron.ipcRenderer.send("aipic_add_virtual_person", data);
}
function modifyPerson(data) {
  electron.ipcRenderer.send("aipic_modify_person", data);
}
function mergePerson(data) {
  electron.ipcRenderer.send("aipic_merge_person", data);
}
function getFaceList(id) {
  electron.ipcRenderer.send("person:get-face-list", id);
}
function selectImageGetAllList(params) {
  return electron.ipcRenderer.invoke("select-image:get-all-list", params);
}
function selectImageGetDeliveredList(params) {
  return electron.ipcRenderer.invoke("select-image:get-delivered-list", params);
}
function copySelectImgFile(data) {
  const params = {
    ...data,
    filelist: JSON.stringify(data.filelist)
  };
  return electron.ipcRenderer.invoke("select-image:copy-select-img-file", params);
}
function moveCloudFile(data) {
  const params = {
    ...data,
    filelist: JSON.stringify(data.filelist)
  };
  return electron.ipcRenderer.invoke("select-image:move-select-img-file", params);
}
function activateInviteCode(data) {
  return electron.ipcRenderer.invoke("select-image:activate-invite-code", data);
}
function getShorturlinfo(data) {
  return electron.ipcRenderer.invoke("select-image:get-shortur-linfo", data);
}
function getSelectshoppingList() {
  return electron.ipcRenderer.invoke("select-image:get-shopping-list");
}
function addSelectshoppingList(params) {
  return electron.ipcRenderer.invoke("select-image:add-shopping-list", params);
}
function modifySelectshoppingList(params) {
  return electron.ipcRenderer.invoke("select-image:modify-shopping-list", params);
}
function deleteSelectshoppingList(params) {
  return electron.ipcRenderer.invoke("select-image:delete-shopping-list", params);
}
function dragSelectshoppingList(params) {
  return electron.ipcRenderer.invoke("select-image:drag-sort", params);
}
function addSelectshoppingToProject(params) {
  return electron.ipcRenderer.invoke("select-image:add-product-to-project", params);
}
function deleteSelectshoppingFromProject(params) {
  return electron.ipcRenderer.invoke("select-image:delete-product-from-project", params);
}
function modifySelectshoppingFromProject(params) {
  return electron.ipcRenderer.invoke("select-image:modify-product-from-project", params);
}
function selectImageGetSelectedList(params) {
  return electron.ipcRenderer.invoke("select-image:get-selected-list", params);
}
function getSelectUnreadCount(params) {
  return electron.ipcRenderer.invoke("select-image:get-unread-count", params);
}
function setProjectRead(params) {
  return electron.ipcRenderer.invoke("select-image:read-project", params);
}
function getIncomeUnread(params) {
  return electron.ipcRenderer.invoke("select-image:get-income-unread-count", params);
}
function setIncomeRead(params) {
  return electron.ipcRenderer.invoke("select-image:set-income-read", params);
}
function getImageMeta(params) {
  return electron.ipcRenderer.invoke("select-image:get-image-meta", params);
}
function deleteImage(params) {
  return electron.ipcRenderer.invoke("select-image:delete-image", params);
}
function deleteFileSpac(params) {
  return electron.ipcRenderer.invoke("spac-image:delete-image", params);
}
function selectImageSwitchState(params) {
  return electron.ipcRenderer.invoke("select-image:switch-state", params);
}
function addNewFolder(params) {
  return electron.ipcRenderer.invoke("spac-image:add-new-folder", params);
}
function getPanelJson() {
  return electron.ipcRenderer.invoke("panel:get-alog-config-data");
}
function getPersonName(id) {
  electron.ipcRenderer.send("person:get-person-name", id);
}
function listenChannelPort() {
  window.onmessage = function(e) {
    if (e.data === "trans-port") {
      const port = e.ports[0];
      electron.ipcRenderer.postMessage("mainprocess:trans-port", null, [port]);
    }
  };
}
function matchSource(data) {
  return electron.ipcRenderer.invoke("select-image:match-source", data);
}
listenChannelPort();
function detectSomething(data) {
  return electron.ipcRenderer.invoke("retouch:detect-something", data);
}
function detectSomethingBinary(data) {
  return electron.ipcRenderer.invoke("retouch:detect-something-binary", data);
}
function queryExportFileById(exportId) {
  return electron.ipcRenderer.invoke("export:query-exportFile", exportId);
}
function querySoftPath(exportId) {
  return electron.ipcRenderer.invoke("export:query-softpath", exportId);
}
function uploadToCloudSpace(params) {
  return electron.ipcRenderer.invoke("cloud-space:upload", params);
}
function downloadFromCloudSpace(params) {
  return electron.ipcRenderer.invoke("cloud-space:download", params);
}
function getPersonQuota() {
  return electron.ipcRenderer.invoke("cloud-space:get-person-quota", {
    clienttype: "0",
    app_id: "250528",
    web: 1,
    channel: "chunlei"
  });
}
function getNetdiskFileList(params) {
  return electron.ipcRenderer.invoke("cloud-space:get-file-list", params);
}
function getNetdiskFileSearchList(params) {
  return electron.ipcRenderer.invoke("cloud-space:get-file-search-list", params);
}
function renameCloudFile(params) {
  return electron.ipcRenderer.invoke("cloud-space:rename-file", params);
}
function getAsyncTaskResult(params) {
  return electron.ipcRenderer.invoke("cloud-space:get-async-task-result", params);
}
function copyNetdiskToSpace(data) {
  const params = {
    ...data,
    file_list: JSON.stringify(data.file_list)
  };
  return electron.ipcRenderer.invoke("cloud-space:copy-netdisk-to-space", params);
}
function copyNetdiskToNextdisk(data) {
  const params = {
    ...data,
    filelist: JSON.stringify(data.filelist)
  };
  return electron.ipcRenderer.invoke("cloud-space:copy-netdisk-to-netdisk", params);
}
function cloudSpaceQueryExportFiles(params) {
  return electron.ipcRenderer.invoke("cloud-space:query-export-files", params);
}
function getSharedSpaceImageListBySpaceId(args) {
  return electron.ipcRenderer.invoke("cloud-space:get-img-list", args);
}
function createRongTuProject(args) {
  return electron.ipcRenderer.invoke("project:create-rongtu-project", args);
}
function getRongtuProjList(args) {
  return electron.ipcRenderer.invoke("project:get-rongtu-list", args);
}
function getRongtuSearchList(args) {
  return electron.ipcRenderer.invoke("project:get-rongtu-search-list", args);
}
function delRongtuProj(args) {
  return electron.ipcRenderer.invoke("project:del-rongtu-proj", args);
}
function setRongtuProj(args) {
  return electron.ipcRenderer.invoke("project:set-rongtu-proj", args);
}
function getRongtuOriginImgList(args) {
  return electron.ipcRenderer.invoke("project:get-rongtu-origin-img-list", args);
}
function getRongTuResultList(args) {
  return electron.ipcRenderer.invoke("rongtu:get-result-list", args);
}
function deleteRongtuOriginImg(args) {
  return electron.ipcRenderer.invoke("rongtu:delete-origin-list", args);
}
function deleteRongtuResultImg(args) {
  return electron.ipcRenderer.invoke("rongtu:delete-result-list", args);
}
function manageRongtuResultImg(args) {
  return electron.ipcRenderer.invoke("rongtu:manage-result-img", args);
}
async function getUserDataPath() {
  try {
    const path = electron.ipcRenderer.invoke("get-user-data-path");
    return path;
  } catch (error) {
    return "";
  }
}
function statSystemInfo() {
  return electron.ipcRenderer.invoke("system:stat-system-info");
}
function getRongtuInfo(args) {
  return electron.ipcRenderer.invoke("aipic_get_rongtu_info_by_worksapce_id", args);
}
function controlRongtuItem(args) {
  return electron.ipcRenderer.invoke("aipic_import_task_control_by_picexport_id", args);
}
function getFixedAipicImage(args) {
  return electron.ipcRenderer.invoke("aipic-get-image", args);
}
function getAiCreativeFileInfo(args) {
  return electron.ipcRenderer.invoke("aipic_get_ai_creative_file_info", args);
}
function setAiCreativeFileInfo(args) {
  return electron.ipcRenderer.invoke("aipic_set_creative_file_status", args);
}
function updateCreativeImage(args) {
  return electron.ipcRenderer.invoke("aipic_update_creative_image", args);
}
function getLocalPathByBase64(args) {
  return electron.ipcRenderer.invoke("aipic_set_creative_get_local_mask_path", args);
}
function downloadDlinkToLocal(args) {
  return electron.ipcRenderer.invoke("aipic_download_creative_dlink", args);
}
function getRecycleFileList(params) {
  return electron.ipcRenderer.invoke("cloud-space:recycle-list", params);
}
function deleteRecycleFiles(params) {
  return electron.ipcRenderer.invoke("cloud-space:recycle-delete", params);
}
function restoreRecycleFiles(params) {
  return electron.ipcRenderer.invoke("cloud-space:recycle-restore", params);
}
function clearRecycle(params) {
  return electron.ipcRenderer.invoke("cloud-space:recycle-clear", params);
}
function aipicStartCamera(params) {
  return electron.ipcRenderer.invoke("aipic_start_session", params);
}
function aipicStopCamera() {
  return electron.ipcRenderer.invoke("aipic_stop_session");
}
function aipicSetSessionConfig(workspaceid, param) {
  return electron.ipcRenderer.invoke("aipic_set_session_config", workspaceid, param);
}
function aipicGetSessionConfig(workspaceid) {
  return electron.ipcRenderer.invoke("aipic_get_session_config", workspaceid);
}
function aipicCameraConnect(params) {
  return electron.ipcRenderer.invoke("aipic_camera_connect", params);
}
function aipicCameraDisconnect(params) {
  return electron.ipcRenderer.invoke("aipic_camera_disconnect", params);
}
function aipicOnlineShootingSetting(key, value) {
  return electron.ipcRenderer.invoke("aipic_online_shooting_setting", key, value);
}
function aipicGetFileinfoSession(workspaceid) {
  return electron.ipcRenderer.invoke("aipic_get_fileinfo_session", workspaceid);
}
function setPannelInfo(option, value) {
  electron.ipcRenderer.send("system:set-pannel-info", option, value);
}
function getPannelInfo(option) {
  return electron.ipcRenderer.invoke("system:get-pannel-info", option);
}
function handleCloudSpaceMsgRead(args) {
  return electron.ipcRenderer.invoke("cloud-space:msgRead", args);
}
function handleCloudSpaceMsgHasnew(args) {
  return electron.ipcRenderer.invoke("cloud-space:msgHasnew", args);
}
function switchAlgoLock(params) {
  return electron.ipcRenderer.invoke("operator:switch-algo-lock", params);
}
function handleCloudSpaceMsgList(args) {
  return electron.ipcRenderer.invoke("cloud-space:msgList", args);
}
electron.contextBridge.exposeInMainWorld("CommonUtils", {
  on: common.on,
  once: common.once,
  removeListener: common.removeListener,
  removeAllListeners: common.removeAllListeners,
  addEventListener: common.addEventListener,
  removeListenerByKey: common.removeListenerByKey,
  sendStatLog: common.sendStatLog,
  sendStatDebugLog: common.sendStatDebugLog,
  clearInitTask: common.clearInitTask,
  queryDatabaseStatus: common.queryDatabaseStatus,
  forceStopVacuumDB: common.forceStopVacuumDB,
  getAppVersion: common.getAppVersion,
  getAppChannelName: common.getAppChannelName,
  getRendererEnd: common.getRendererEnd,
  deleteFiles: common.deleteFiles,
  browserJump: common.browserJump,
  pathSep: common.pathSep,
  env: common.env,
  getAppPath,
  platform: common.platform,
  queryExperiencePlan: common.queryExperiencePlan,
  setExperiencePlan: common.setExperiencePlan,
  getAppBase: common.getAppBase,
  getModelVersion: common.getModelVersion,
  saveLogToLocal: common.saveLogToLocal,
  isSupportExt: common.isSupportExt,
  getAppNameAndLogo: common.getAppNameAndLogo,
  openBrowser: common.openBrowser,
  downloadFile,
  getLoginStatus: common.getLoginStatus,
  getRecommendLocalSetting,
  getDiskInfo,
  clearLocalSetting,
  setLocalSetting,
  setChange,
  getLocalSetting,
  getPath,
  statSystemInfo,
  sendQuitChoice: common.sendQuitChoice,
  setPannelInfo,
  getPannelInfo,
  getLocalComplexSetting
});
electron.contextBridge.exposeInMainWorld("MainUtils", {
  on: common.on,
  once: common.once,
  removeListener: common.removeListener,
  addEventListener: common.addEventListener,
  removeListenerByKey: common.removeListenerByKey,
  removeAllListeners: common.removeAllListeners,
  saveImg,
  getUserData,
  loadFolder,
  getFolderStat,
  chooseFile,
  selectImagePath,
  getPathForFile,
  importFile,
  importFilterImageFile,
  importImageFile,
  exportImageFile,
  getUserSticker,
  delUserSticker,
  getSubSticker,
  getPubSticker,
  allowCreate,
  imgCreate,
  imgReCreate,
  batchRecorddetail,
  batchRecordCanvas,
  copyNetdiskToNextdisk,
  getFileMetaByID,
  getFileList,
  getMultiFileList,
  getFilterImageList,
  modifyFilterImageItem,
  modifyFileItem,
  modifyFileItemMark,
  windowChangeSize,
  imageExportRetouch,
  createClassifyShare,
  windowIsMaximized,
  beforeQuit,
  deleteFile,
  getFileExifInfo,
  getFilterImageExifInfo,
  getGenerateThumbnail,
  isLocalFile,
  batchCheckLocalFile,
  relinkLocalFile,
  generateFileThumbnail,
  quickReport,
  quickgetConf,
  undoFileCache,
  redoFileCache,
  insertPresetGroup,
  getPresetGroup,
  renamePresetGroup,
  deletePresetGroup,
  getRGBA,
  insertPresetItem,
  getPresetItem,
  getPersonItemsByIds,
  deletePersonItemsByIds,
  verifyShareLinkPwd,
  // Netdisk外链相关
  queryShareLinkInfo,
  queryShareLinkCaptcha,
  queryShareLinkList,
  createShareLinkUploadTask,
  panShareTransfer,
  panShareTransferStatus,
  setSignKey,
  addProject,
  getPanList,
  getPickProjectList,
  // Add the new function here
  getPickProjectSearchList,
  getPickProjectInfo,
  textRiskCheck,
  createPickProject,
  // Add createPickProject
  deletePickProject,
  // Add deletePickProject
  setPickProjectInfo,
  // Add setPickProjectInfo
  setPickPhotoset,
  // 设为Gợi ý、Hủy bỏGợi ý
  getPickMpQrCode,
  // Lấy小程序Mã QR
  uploadImageOcr,
  uploadWaterImg,
  getInviteInfo,
  // Lấymã mời邀请信息
  getMyIncome,
  queryApplyInfo,
  getUserCombo,
  submitApplyInfo,
  getOrderInfo,
  refundOrder,
  getRefundStatus,
  createSharedCloudSpace,
  modifySharedCloudSpace,
  getCloudSpaceProductList,
  getCanTryComboSharedSpace,
  tryComboSharedSpace,
  getSharedCloudSpace,
  createSharedCloudSpaceLink,
  deleteSharedSpaceMember,
  getAmisConfig,
  getOperatorPanelData,
  getCouponConsumeAmis,
  getOrderListAmis,
  getHeaderDropdownData,
  addImportPanTask,
  addImportUploadPanTask,
  queryImportPanTasks,
  queryTaskCount,
  controlPanTasks,
  controlPanTasksByFiles,
  getEnterpriseGroupList,
  getProjectList,
  addFilterImageExportProject,
  getWorkspaceById,
  deleteProject,
  modifyProject,
  getAlgorithmsItem,
  getNetworkStatus,
  setSplitView,
  updateReferenceView,
  setReferenceByAISimulation,
  systemPrompt,
  presetParseToken,
  presetGenToken,
  presetTokenImport,
  getServerGroupList,
  queryEnterpriseList,
  switchEnterprise,
  imageExportDetect,
  getServerPresetItem,
  getServerPresetAll,
  addServerPresetItem,
  updateServerGroupList,
  addServerGroupList,
  updateServerPresetItem,
  getRevisionVoucher,
  getPersonalPortrait,
  getBanInfo,
  getUserPickupDetails,
  getFreeAlgorithmConfig,
  getTenVouchers,
  dragChooseFolder,
  importChooseFolder,
  batchRefreshThumbnailByPath,
  modifyHistory,
  storeShareLink,
  deleteHistory,
  showPathItemInFolder,
  openFilePath,
  logoutLogin,
  getFileCache,
  insertFileCache,
  pastedFileCache,
  clearFileCache,
  readDragFileList,
  handleExtJsonCache,
  openSetWin,
  getFileBase64,
  addExportTask,
  exportSoftware,
  exportElectApplication,
  exportToFilter,
  exportToClassify,
  exportToSelect,
  exportToSelectCloud,
  exportToAIPhoto,
  fetchTicketNum,
  fetchOrderList,
  deletePresetItem,
  changePresetItem,
  exprotTaskPause,
  queryExportHistory,
  exprotTaskStart,
  exprotTaskRestart,
  getFileDetection,
  modifyFileDetection,
  syncModifyFileDetection,
  modifyFileDetectionFeedback,
  clearFileThumbnail,
  getApaasProjectId,
  createApaasProjectAndImport,
  getApaasParams,
  getExportAllProgress,
  getFaultReporting,
  preheatPicPreview,
  createBgRetouchTask,
  liquidBegin,
  liquidCancel,
  liquidConfirm,
  liquidHistory,
  deleteBgRetouchTask,
  getOperateHistory,
  switchCurrentProjectInfo,
  switchCurrentCanvasInfo,
  updateListThumbnail,
  nativeCanvasChangeSize,
  nativeCanvasClearCanvas,
  nativeCanvasSwitchImage,
  nativeCanvasMouseHandle,
  nativeCanvasRetouchImage,
  nativeCanvasZoom,
  nativeCanvasRetouchOperation,
  getPhoneAuthorized,
  setPhoneAuthorized,
  queryUserSetting,
  setContactData,
  postContactForm,
  createShareLink,
  sendPhoneCode,
  getContactAmisData,
  getAmisData,
  autoUpdateCheckUpdate,
  autoUpdateBeginDownload,
  autoUpdateQuitInstall,
  getPresetTokenConfig,
  addBackground,
  getBackgroundInfo,
  getExportTickets,
  getImitationInfo,
  delImitationInfo,
  addMaterials,
  addMaterialsDirect,
  uploadMaterials,
  selectMaterials,
  delBackgroundInfo,
  getProjectBannerData,
  getHeaderHotActivity,
  getHeaderCrowdPack,
  getHardwareSpecs,
  getDiskSpaceForSystemDrive,
  getExportErrorDetail,
  getHistogramData,
  getColorHistogram,
  getCurveData,
  queryPanDirCount,
  queryPanDirCountStatus,
  startImageHandle,
  restartImageHandle,
  stopImageHandle,
  pauseImageHandle,
  resumeImageHandle,
  initStatusCbHandle,
  detectSomething,
  detectSomethingBinary,
  addVirtualPerson,
  modifyPerson,
  mergePerson,
  getFaceList,
  selectImageGetAllList,
  selectImageGetDeliveredList,
  selectImageGetSelectedList,
  getSelectshoppingList,
  addSelectshoppingList,
  modifySelectshoppingList,
  deleteSelectshoppingList,
  dragSelectshoppingList,
  addSelectshoppingToProject,
  deleteSelectshoppingFromProject,
  modifySelectshoppingFromProject,
  getSelectUnreadCount,
  setProjectRead,
  getIncomeUnread,
  setIncomeRead,
  getImageMeta,
  deleteImage,
  deleteFileSpac,
  selectImageSwitchState,
  addNewFolder,
  getPersonName,
  getPanelJson,
  matchSource,
  querySoftPath,
  queryExportFileById,
  uploadToCloudSpace,
  downloadFromCloudSpace,
  getPersonQuota,
  // 云空间-LấyKhông gian cá nhân
  getNetdiskFileList,
  // 云空间-LấyNetdisk列表
  getNetdiskFileSearchList,
  // 云空间-搜索Netdisk列表
  renameCloudFile,
  // 云空间-tệpĐổi tên
  getAsyncTaskResult,
  // 云空间-Lấy异步任务结果
  copyNetdiskToSpace,
  getRecycleFileList,
  deleteRecycleFiles,
  restoreRecycleFiles,
  clearRecycle,
  copySelectImgFile,
  moveCloudFile,
  activateInviteCode,
  getShorturlinfo,
  cloudSpaceQueryExportFiles,
  getSharedSpaceImageListBySpaceId,
  createRongTuProject,
  getRongtuProjList,
  getRongtuSearchList,
  delRongtuProj,
  setRongtuProj,
  getRongtuOriginImgList,
  getRongTuResultList,
  deleteRongtuOriginImg,
  deleteRongtuResultImg,
  manageRongtuResultImg,
  getUserDataPath,
  getRongtuInfo,
  controlRongtuItem,
  getFixedAipicImage,
  getAiCreativeFileInfo,
  setAiCreativeFileInfo,
  updateCreativeImage,
  getLocalPathByBase64,
  downloadDlinkToLocal,
  aipicStartCamera,
  aipicStopCamera,
  aipicSetSessionConfig,
  aipicGetSessionConfig,
  aipicCameraConnect,
  aipicCameraDisconnect,
  aipicOnlineShootingSetting,
  aipicGetFileinfoSession,
  handleCloudSpaceMsgHasnew,
  handleCloudSpaceMsgRead,
  handleCloudSpaceMsgList,
  // 算子锁定开关
  switchAlgoLock
});
electron.contextBridge.exposeInMainWorld("FilterImageUtils", { detectSomething });
electron.contextBridge.exposeInMainWorld("TestUtils", {
  testLoadFileBase64,
  testLoadFileBlob,
  testSetOpenglCanvas,
  testGetFilePath,
  testStringIpcTime
});


// LK_MAGIC_EXPORT_TRIAL_PRELOAD_V8
electron.contextBridge.exposeInMainWorld('lkMagicKey', Object.freeze({
  state: () => electron.ipcRenderer.invoke('lk-export:state'),
  activate: () => electron.ipcRenderer.invoke('lk-export:activate'),
  onChange: callback => {
    if (typeof callback !== 'function') throw new TypeError('Callback required');
    const listener = (_event, value) => callback({ready: !!value?.ready, activated: value?.activated === true});
    electron.ipcRenderer.on('lk-export:changed', listener);
    return () => electron.ipcRenderer.removeListener('lk-export:changed', listener);
  },
}));

// LK_NATIVE_COLOR_REFS_PRELOAD_V1_BEGIN
try { require('./lk-native-color-references.cjs'); }
catch (error) { console.error('[LK Ảnh mẫu cá nhân] Không khởi tạo được kết nối:', error && error.message); }
// LK_NATIVE_COLOR_REFS_PRELOAD_V1_END
