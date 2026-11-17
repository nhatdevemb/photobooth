'use strict';
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const {execFile} = require('node:child_process');

const APP = 'LKmagic';
const MACHINE_PATTERN = /^LKM1-[A-F0-9]{64}$/;
const MAX_KEY_LENGTH = 8192;
function normalizeMachineCode(value) {
  const compact = String(value || '').toUpperCase().replace(/[\s-]/g, '');
  if (!/^LKM1[A-F0-9]{64}$/.test(compact)) throw new Error('Mã máy không đúng định dạng.');
  return 'LKM1-' + compact.slice(4);
}
function fingerprint(identity) {
  const guid = String(identity.machineGuid || '').trim().toUpperCase();
  const uuid = String(identity.uuid || '').trim().toUpperCase();
  const valid = /^[A-F0-9]{8}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{12}$/;
  if (![guid, uuid].every(value => valid.test(value) && !/^(0|-)+$/.test(value) && !/^(F|-)+$/.test(value)))
    throw new Error('Chưa đọc được mã Windows hoặc mã phần cứng hợp lệ. Vui lòng thử lại.');
  const digest = crypto.createHash('sha256').update('LKmagic/device/v1\0' + guid + '\0' + uuid, 'utf8').digest('hex').toUpperCase();
  return 'LKM1-' + digest;
}
function readMachineCode(run = execFile) {
  if (process.platform !== 'win32' && run === execFile) return Promise.reject(new Error('Bản kích hoạt này dùng cho Windows.'));
  const systemRoot = process.env.SystemRoot || 'C:\\Windows';
  const native = path.win32.join(systemRoot, 'Sysnative', 'WindowsPowerShell', 'v1.0', 'powershell.exe');
  const regular = path.win32.join(systemRoot, 'System32', 'WindowsPowerShell', 'v1.0', 'powershell.exe');
  const executable = fs.existsSync(native) ? native : regular;
  // Fixed read-only command: no user input is interpolated or evaluated.
  const command = "$ErrorActionPreference='Stop'; $ProgressPreference='SilentlyContinue'; " +
    "$base=[Microsoft.Win32.RegistryKey]::OpenBaseKey([Microsoft.Win32.RegistryHive]::LocalMachine,[Microsoft.Win32.RegistryView]::Registry64); " +
    "try {$key=$base.OpenSubKey('SOFTWARE\\Microsoft\\Cryptography'); try {$guid=[string]$key.GetValue('MachineGuid')} finally {if($key){$key.Dispose()}}} finally {$base.Dispose()}; " +
    "$uuid=[string](Get-CimInstance -ClassName Win32_ComputerSystemProduct -ErrorAction Stop | Select-Object -First 1 -ExpandProperty UUID); " +
    "[Console]::OutputEncoding=[Text.UTF8Encoding]::new($false); @{machineGuid=$guid;uuid=$uuid}|ConvertTo-Json -Compress";
  return new Promise((resolve, reject) => {
    run(executable, ['-NoLogo', '-NoProfile', '-NonInteractive', '-Command', command],
      {windowsHide: true, timeout: 10000, maxBuffer: 16384, encoding: 'utf8'}, (error, stdout) => {
        if (error) { reject(new Error('Không đọc được mã máy. Kiểm tra Windows PowerShell/WMI rồi bấm Thử lại.')); return; }
        try { resolve(fingerprint(JSON.parse(String(stdout).replace(/^\uFEFF/, '').trim()))); }
        catch (error) { reject(new Error('Mã phần cứng chưa hợp lệ. Vui lòng thử lại hoặc liên hệ người cấp key.')); }
      });
  });
}
function base64url(buffer) { return Buffer.from(buffer).toString('base64').replace(/=/g, '').replace(/\+/g, '-').replace(/\//g, '_'); }
function decodeBase64url(text, max) {
  if (typeof text !== 'string' || !/^[A-Za-z0-9_-]+$/.test(text) || text.length > max) throw new Error('Key không đúng định dạng.');
  const result = Buffer.from(text.replace(/-/g, '+').replace(/_/g, '/'), 'base64');
  if (base64url(result) !== text) throw new Error('Key không đúng định dạng.');
  return result;
}
function validateIssuer(config) {
  if (!config || config.schema !== 'lk-magic-offline-issuer-v1' || !config.publicJwk || config.publicJwk.kty !== 'RSA')
    throw new Error('Thiếu khoá kiểm tra của chủ app.');
  const {n, e} = config.publicJwk;
  if (decodeBase64url(n, 600).length !== 384 || e !== 'AQAB') throw new Error('Khoá kiểm tra phải là RSA 3072 bit.');
  const kid = crypto.createHash('sha256').update('LKmagic/RSA3072/v1|' + n + '|' + e, 'utf8').digest('hex');
  if (config.kid !== kid) throw new Error('Khoá kiểm tra không khớp.');
  // Never accept private JWK fields supplied in a public configuration.
  if (Object.keys(config.publicJwk).some(key => !['kty','n','e'].includes(key))) throw new Error('Tệp công khai có trường không hợp lệ.');
  const key = crypto.createPublicKey({key: {kty: 'RSA', n, e}, format: 'jwk'});
  if (key.asymmetricKeyType !== 'rsa' || key.asymmetricKeyDetails?.modulusLength !== 3072) throw new Error('Khoá kiểm tra phải là RSA 3072 bit.');
  return {kid, key};
}
function verifyLicense(input, machineCode, issuerConfig) {
  try {
    const issuer = validateIssuer(issuerConfig);
    const machine = normalizeMachineCode(machineCode);
    if (typeof input !== 'string' || input.length > MAX_KEY_LENGTH) throw new Error('Key quá dài hoặc không hợp lệ.');
    const token = input.trim();
    const parts = token.split('.');
    if (parts.length !== 3 || parts[0] !== 'LK1') throw new Error('Key không đúng định dạng.');
    const data = decodeBase64url(parts[1], 4096), signature = decodeBase64url(parts[2], 600);
    if (signature.length !== 384 || !crypto.verify('sha256', data, {key: issuer.key, padding: crypto.constants.RSA_PKCS1_PADDING}, signature))
      throw new Error('Key không do chủ app cấp hoặc đã bị thay đổi.');
    const license = JSON.parse(data.toString('utf8'));
    const fields = ['v','app','issuer','machine','licenseId','kind','seats','issuedAt','customer'];
    if (!license || Array.isArray(license) || Object.keys(license).some(key => !fields.includes(key)) ||
        license.v !== 1 || license.app !== APP || license.issuer !== issuer.kid ||
        license.kind !== 'perpetual' || license.seats !== 1 || !MACHINE_PATTERN.test(license.machine) ||
        !/^[a-f0-9]{32}$/.test(license.licenseId) || !/^\d{4}-\d{2}-\d{2}T/.test(license.issuedAt || '') ||
        typeof license.customer !== 'string' || license.customer.length > 120)
      throw new Error('Thông tin giấy phép không hợp lệ.');
    if (license.machine !== machine) throw new Error('Key này được cấp cho máy khác. Hãy gửi mã máy hiện tại để được cấp đúng key.');
    return {ok: true, token, license};
  } catch (error) { return {ok: false, message: error.message || 'Key không hợp lệ.'}; }
}
function saveLicense(file, token) {
  fs.mkdirSync(path.dirname(file), {recursive: true});
  const temporary = file + '.' + crypto.randomBytes(8).toString('hex') + '.tmp';
  try {
    fs.writeFileSync(temporary, token + '\n', {encoding: 'utf8', flag: 'wx', mode: 0o600});
    fs.renameSync(temporary, file);
  } finally { try { fs.unlinkSync(temporary); } catch {} }
}
function readSavedLicense(file) {
  try { if (fs.statSync(file).size > MAX_KEY_LENGTH + 8) return ''; return fs.readFileSync(file, 'utf8'); }
  catch { return ''; }
}
module.exports = {normalizeMachineCode, fingerprint, readMachineCode, validateIssuer, verifyLicense, saveLicense, readSavedLicense, MAX_KEY_LENGTH};
