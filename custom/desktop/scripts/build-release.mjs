import { createHash } from 'node:crypto';
import { createReadStream } from 'node:fs';
import { access, mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const desktop = fileURLToPath(new URL('../', import.meta.url));
const { version } = JSON.parse(await readFile(path.join(desktop, 'package.json'), 'utf8'));
const cache = path.join(desktop, '.cache');
const output = path.resolve(desktop, process.env.PB_BUILD_OUTPUT || path.join('dist', version));
const installer = 'Photobooth-Setup-' + version + '-x64.exe';

const requirements = [
    ['node_modules/electron-builder/package.json', 'npm.cmd ci --ignore-scripts'],
    ['node_modules/electron/dist/electron.exe', 'node scripts/fetch-electron.mjs'],
    ['runtime/php/php.exe', 'npm.cmd run prepare:runtime'],
    ['runtime/python/python.exe', 'npm.cmd run prepare:runtime'],
    ['runtime/caddy/caddy.exe', 'npm.cmd run prepare:runtime'],
    ['runtime/phpqrcode', 'npm.cmd run prepare:runtime'],
    ['../vendor/autoload.php', 'npm.cmd run prepare:runtime'],
];

try {
    if (process.platform !== 'win32' || process.arch !== 'x64') {
        throw new Error('Hãy chạy bằng Node.js x64 trên Windows.');
    }
    for (const [file, command] of requirements) {
        try {
            await access(path.resolve(desktop, file));
        } catch {
            throw new Error('Thiếu ' + file + '. Chạy ' + command + ' trong custom/desktop rồi thử lại.');
        }
    }

    await mkdir(cache, { recursive: true });
    process.chdir(desktop);
    process.env.PB_BUILD_OUTPUT = output;
    process.env.ELECTRON_BUILDER_CACHE ||= path.join(cache, 'electron-builder');
    process.env.TEMP = cache;
    process.env.TMP = cache;

    await import('./stage.mjs');
    await import('./build-windows.mjs');

    const file = path.join(output, installer);
    const sha256 = createHash('sha256');
    for await (const chunk of createReadStream(file)) {
        sha256.update(chunk);
    }
    const checksum = path.join(output, 'SHA256SUMS.txt');
    await writeFile(checksum, sha256.digest('hex') + '  ' + installer + '\n');
    console.log('Bộ cài ' + file);
    console.log('SHA-256 ' + checksum);
} catch (error) {
    console.error('Build Windows thất bại. ' + error.message);
    process.exitCode = 1;
}
