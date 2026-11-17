import { build, Platform, Arch } from 'electron-builder';
import { createRequire } from 'node:module';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';
import { access } from 'node:fs/promises';

// electron-builder 26's Windows collector invokes npm.cmd through a shell. Call the
// actual npm JS entry point with an argv array instead: works with spaces in paths,
// avoids PowerShell/CET requirements, and does not weaken the application sandbox.
const require = createRequire(import.meta.url);
const { NpmNodeModulesCollector } = require('app-builder-lib/out/node-module-collector/npmNodeModulesCollector.js');
const execute = promisify(execFile);
const npmCli = process.env.npm_execpath || path.join(path.dirname(process.execPath), 'node_modules/npm/bin/npm-cli.js');
await access(npmCli);
if (process.platform === 'win32') {
    NpmNodeModulesCollector.prototype.getDependenciesTree = async function () {
        let stdout;
        try {
            ({ stdout } = await execute(process.execPath, [npmCli, ...this.getArgs()], { cwd: this.rootDir, windowsHide: true, maxBuffer: 16 * 1024 * 1024, env: { ...process.env, npm_config_cache: path.join(process.cwd(), '.cache/npm') } }));
        } catch (error) {
            if (error.code !== 1 || !error.stdout?.trim()) throw error;
            stdout = error.stdout;
        }
        return this.parseDependenciesTree(stdout);
    };
}
await build({ targets: Platform.WINDOWS.createTarget(['nsis'], Arch.x64) });
