import { defineConfig } from 'playwright/test';

const port = Number(process.env.PB_BROWSER_PORT || 18080);
export default defineConfig({
    testDir: './tests/browser',
    testMatch: process.env.PB_BROWSER_CAMERA === 'browser' ? '**/webcam.spec.mjs' : '**/*.spec.mjs',
    testIgnore: process.env.PB_BROWSER_CAMERA === 'browser' ? [] : ['**/webcam.spec.mjs'],
    workers: 1,
    outputDir: process.env.PB_BROWSER_CAMERA === 'browser' ? 'test-results/camera' : process.env.PB_BROWSER_PRINTER_SCENARIO ? 'test-results/unknown' : 'test-results/normal',
    timeout: 60000,
    expect: { timeout: 15000 },
    use: {
        baseURL: `http://127.0.0.1:${port}`,
        browserName: 'chromium',
        launchOptions: { args: process.env.PB_BROWSER_CAMERA === 'browser' ? ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'] : [] },
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    webServer: {
        command: 'node tests/browser/server.mjs',
        url: `http://127.0.0.1:${port}`,
        reuseExistingServer: false,
        timeout: 30000,
    },
});
