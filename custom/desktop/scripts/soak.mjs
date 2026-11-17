import { spawn } from 'node:child_process';
const child = spawn(process.execPath, ['--test', 'tests/runtime-http.test.mjs'], { stdio: 'inherit', windowsHide: true, env: { ...process.env, PB_SOAK_SESSIONS: '30' } });
child.on('error', (error) => { console.error(error); process.exitCode = 1; });
child.on('exit', (code) => { process.exitCode = code ?? 1; });
