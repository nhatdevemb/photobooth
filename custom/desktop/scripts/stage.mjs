import { cp, mkdir, rm, readFile, writeFile, access } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const desktop = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const root = path.resolve(desktop, '../..');
const target = path.join(desktop, 'staging/app');
await access(path.join(root, 'custom/vendor/autoload.php'));
// Only this fixed staging path is replaced; user data and runtime folders are separate.
if (!target.startsWith(desktop + path.sep)) throw new Error('Invalid staging path');
await rm(target, { recursive: true, force: true });
await mkdir(target, { recursive: true });
for (const directory of ['src', 'public', 'python', 'models', 'presets', 'layouts', 'templates', 'migrations', 'bin', 'vendor']) {
    await cp(path.join(root, 'custom', directory), path.join(target, 'custom', directory), { recursive: true, filter: (source) => !/(?:^|[\\/])(?:__pycache__|\.pytest_cache)(?:[\\/]|$)|\.pyc$/.test(source) });
}
for (const file of ['composer.json', 'composer.lock', 'upstream.json']) await cp(path.join(root, 'custom', file), path.join(target, 'custom', file));
await cp(path.join(desktop, 'runtime/phpqrcode'), path.join(target, 'vendor/phpqrcode'), { recursive: true });
await cp(path.join(root, 'LICENSE'), path.join(target, 'LICENSE'));
const manifest = JSON.parse(await readFile(path.join(desktop, 'runtime-lock.json'), 'utf8'));
await writeFile(path.join(target, 'runtime-manifest.json'), JSON.stringify(manifest, null, 2));
console.log('Staged application sources; event data and credentials excluded.');
