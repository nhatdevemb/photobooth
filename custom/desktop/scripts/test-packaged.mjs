import { spawn } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const desktop = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const child = spawn(process.execPath, ['--test', 'tests/electron-smoke.test.mjs'], { cwd: desktop, stdio: 'inherit', windowsHide: true, env: { ...process.env, PB_TEST_PACKAGED_EXE: path.join(desktop, 'dist/win-unpacked/Photobooth Operator.exe') } });
child.on('error', (error) => { console.error(error); process.exitCode = 1; });
child.on('exit', (code) => { process.exitCode = code ?? 1; });
