import { spawn } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const desktop = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const child = spawn(process.execPath, ['--test', 'tests/liveview-workflow.test.mjs'], { cwd: desktop, stdio: 'inherit', windowsHide: true, env: { ...process.env, PB_REAL_DCC: '0', PB_TEST_SESSION_CYCLE: '1', PB_TEST_PACKAGED_EXE: path.join(desktop, 'dist/win-unpacked/Photobooth Operator.exe') } });
child.on('error', (error) => { console.error(error); process.exitCode = 1; });
child.on('exit', (code) => { process.exitCode = code ?? 1; });
