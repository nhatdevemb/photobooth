/** Reproducible local runtime; downloaded executables are verified before extraction/use. */
import { readFile, writeFile, mkdir, access, readdir, cp, copyFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { patchQr } from './patch-qr.mjs';

const desktop = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const runtime = path.join(desktop, 'runtime');
const root = path.resolve(desktop, '../..');
const cache = path.join(desktop, '.cache');
await mkdir(cache, { recursive: true });
await mkdir(runtime, { recursive: true });
const lock = JSON.parse(await readFile(path.join(desktop, 'runtime-lock.json'), 'utf8'));
async function get(url) {
    const response = await fetch(url, { signal: AbortSignal.timeout(180000) });
    if (!response.ok) throw new Error(`Download ${response.status}: ${url}`);
    return Buffer.from(await response.arrayBuffer());
}
async function exists(name) { return access(name).then(() => true).catch(() => false); }
for (const [name, entry] of Object.entries(lock)) {
    const zip = path.join(runtime, name + '.zip');
    let bytes = await exists(zip) ? await readFile(zip) : await get(entry.url);
    if (createHash(entry.algorithm).update(bytes).digest('hex') !== entry.hash) throw new Error(`Checksum failed: ${name}`);
    await writeFile(zip, bytes);
    const target = path.join(runtime, name === 'qr' ? 'qr-src' : name);
    await mkdir(target, { recursive: true });
    const unpack = spawnSync('tar.exe', ['-xf', zip, '-C', target], { windowsHide: true, stdio: 'inherit' });
    if (unpack.error || unpack.status !== 0) throw unpack.error || new Error('Extraction failed: ' + name);
}
const python = path.join(runtime, 'python/python.exe');
for (const name of ['vcruntime140.dll', 'vcruntime140_1.dll']) await copyFile(path.join(runtime, 'python', name), path.join(runtime, 'php', name));
await writeFile(path.join(runtime, 'python/python312._pth'), 'python312.zip\n.\nLib/site-packages\n../../../python\n../../app/custom/python\nimport site\n');
const qrFolder = (await readdir(path.join(runtime, 'qr-src'))).find((name) => name.startsWith('phpqrcode-'));
await cp(path.join(runtime, 'qr-src', qrFolder), path.join(runtime, 'phpqrcode'), { recursive: true });
await patchQr(path.join(runtime, 'phpqrcode'));

function execute(executable, args, extra = {}) {
    const result = spawnSync(executable, args, { cwd: root, windowsHide: true, stdio: 'inherit', env: { ...process.env, TEMP: cache, TMP: cache, MPLCONFIGDIR: path.join(cache, 'matplotlib'), PIP_CACHE_DIR: path.join(cache, 'pip') }, ...extra });
    if (result.error) throw result.error;
    if (result.status !== 0) throw new Error(`${path.basename(executable)} failed (${result.status})`);
}
// Bootstrap pip from a pinned wheel rather than executing an unpinned get-pip script.
const pipVersion = '26.2.2';
const pipMeta = await fetch(`https://pypi.org/pypi/pip/${pipVersion}/json`).then((r) => r.json());
const wheel = pipMeta.urls.find((entry) => entry.filename.endsWith('.whl'));
const wheelBytes = await get(wheel.url);
if (createHash('sha256').update(wheelBytes).digest('hex') !== wheel.digests.sha256) throw new Error('pip checksum failed');
const pipZip = path.join(runtime, 'pip.whl.zip');
await writeFile(pipZip, wheelBytes);
await mkdir(path.join(runtime, 'python/Lib/site-packages'), { recursive: true });
const pipUnpack = spawnSync('tar.exe', ['-xf', pipZip, '-C', path.join(runtime, 'python/Lib/site-packages')], { windowsHide: true, stdio: 'inherit' });
if (pipUnpack.error || pipUnpack.status !== 0) throw pipUnpack.error || new Error('pip extraction failed');
const requirementsLock = path.join(desktop, 'requirements-windows.lock');
const locked = await exists(requirementsLock);
const report = path.join(runtime, 'python-packages.json');
execute(python, ['-m', 'pip', '--isolated', 'install', '--no-cache-dir', '--disable-pip-version-check', '--only-binary=:all:', '--report', report,
    ...(locked ? ['--require-hashes', '-r', requirementsLock] : ['-r', 'custom/requirements.lock', '-r', 'custom/requirements-beauty.lock'])]);
if (!locked) {
    const packages = JSON.parse(await readFile(report, 'utf8')).install;
    await writeFile(requirementsLock, '# Windows x64 Python 3.12 wheels, generated from pip --report.\n' + packages.map((item) => `${item.metadata.name}==${item.metadata.version} --hash=sha256:${item.download_info.archive_info.hashes.sha256}`).sort().join('\n') + '\n');
}
execute(python, ['-c', 'import PIL,numpy,cv2,mediapipe;print("Image runtime ready")']);
const composer = path.join(runtime, 'composer.phar');
if (!await exists(composer)) {
    const version = '2.11.2';
    const url = `https://getcomposer.org/download/${version}/composer.phar`;
    const bytes = await get(url);
    const expected = (await get(url + '.sha256sum')).toString().trim().split(/\s+/)[0];
    if (createHash('sha256').update(bytes).digest('hex') !== expected) throw new Error('Composer checksum failed');
    await writeFile(composer, bytes);
}
const php = path.join(runtime, 'php/php.exe');
const extensionDir = path.join(runtime, 'php/ext').replaceAll('\\', '/');
const ini = path.join(runtime, 'php/php.ini');
const cert = path.join(runtime, 'cacert.pem');
await writeFile(cert, await get('https://curl.se/ca/cacert.pem'));
await writeFile(ini, `extension_dir="${extensionDir}"\nsys_temp_dir="${cache.replaceAll('\\', '/')}"\n` +
    ['pdo_sqlite','sqlite3','gd','mbstring','openssl','curl','zip','fileinfo'].map((name) => `extension=${name}`).join('\n') +
    `\nopenssl.cafile="${cert.replaceAll('\\', '/')}"\ncurl.cainfo="${cert.replaceAll('\\', '/')}"\n`);
execute(php, [composer, 'install', '--working-dir=custom', '--no-interaction', '--prefer-dist'], { env: { ...process.env, COMPOSER_HOME: path.join(cache, 'composer'), TEMP: cache, TMP: cache } });
console.log('Runtime verified and ready. Install digiCamControl separately before connecting R10.');
