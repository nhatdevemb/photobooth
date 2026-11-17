import { test, expect } from 'playwright/test';
import { login, setup, style, review, cleanup } from './helpers.mjs';
test.afterEach(cleanup);

test('four-shot wizard persists locked choices and produces the approved print', async ({ page }, info) => {
    await login(page);
    await setup(page, 'grid-four');
    await page.locator('#btn-auto-capture-all').click();
    await expect(page.locator('.slot-thumb-box img')).toHaveCount(4);
    await expect(page.locator('#btn-next')).toBeEnabled();
    await page.reload();
    await expect(page.locator('.slot-thumb-box img')).toHaveCount(4);
    await expect(page.locator('[data-step="1"]')).toBeDisabled();
    for (const size of [{ width: 1366, height: 768 }, { width: 1920, height: 1080 }]) {
        await page.setViewportSize(size);
        await expect(page.locator('#btn-next')).toBeEnabled();
        expect(await page.locator('#btn-next').evaluate((button) => button.getBoundingClientRect().bottom <= innerHeight)).toBe(true);
        await page.screenshot({ path: info.outputPath(`capture-${size.width}.png`), fullPage: true });
    }
    await style(page);
    await expect(page.locator('#btn-finalize-session')).toBeEnabled();
    for (const size of [{ width: 1366, height: 768 }, { width: 1920, height: 1080 }]) {
        await page.setViewportSize(size);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.screenshot({ path: info.outputPath(`grid-four-${size.width}.png`), fullPage: true });
    }
    await page.locator('#btn-finalize-session').click();
    await expect(page.locator('#modal-qr-img')).toBeVisible();
    await page.locator('#btn-open-print-modal').click();
    await expect(page.locator('#modal-print-confirm')).toBeVisible();
    await page.locator('#btn-confirm-print-submit').click();
    await expect(page.locator('#session-prints-list')).toContainText('COMPLETED');
    await page.locator('#btn-close-session').click();
    await expect(page.locator('#btn-new-session')).toBeVisible();
});

for (const layout of ['single', 'strip-three', 'postcard-three']) {
    test(`wizard completes layout ${layout} at the target resolutions`, async ({ page }, info) => {
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await login(page);
        await review(page, layout);
        for (const size of [{ width: 1366, height: 768 }, { width: 1920, height: 1080 }]) {
            await page.setViewportSize(size);
            await expect(page.locator('#btn-finalize-session')).toBeEnabled();
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
            await page.screenshot({ path: info.outputPath(`${layout}-${size.width}.png`), fullPage: true });
        }
        await page.locator('#btn-finalize-session').click();
        await expect(page.locator('#modal-qr-img')).toBeVisible();
        await page.locator('#btn-close-session').click();
        await expect(page.locator('#btn-new-session')).toBeVisible();
        expect(errors).toEqual([]);
    });
}

test('admin imports a ZIP, previews actual artwork and activates it for selection', async ({ page }, info) => {
    const { execFileSync } = await import('node:child_process');
    const { resolve } = await import('node:path');
    const packagePath = info.outputPath('browser-template.zip');
    const source = resolve(import.meta.dirname, '../../templates/bundled/cute-single');
    execFileSync('python3', ['-c', `import json, pathlib, sys, zipfile
source=pathlib.Path(sys.argv[1])
meta=json.loads((source/'template.json').read_text())
meta.update(id='browser-custom-single',name='Mẫu browser tùy chỉnh',style='browser-custom')
meta.pop('hashes',None)
with zipfile.ZipFile(sys.argv[2],'w',zipfile.ZIP_DEFLATED) as z:
    z.writestr('template.json',json.dumps(meta))
    for name in ('overlay.png','background.png','preview.jpg'): z.write(source/name,name)
`, source, packagePath]);
    await login(page, true);
    await page.locator('#btn-nav-admin').click();
    await page.locator('#template-zip').setInputFiles(packagePath);
    await page.locator('#btn-import-template').click();
    const card = page.locator('.admin-template').filter({ hasText: 'Mẫu browser tùy chỉnh' });
    await expect(card).toContainText('draft');
    await card.getByRole('button', { name: 'Xem thử / kích hoạt' }).click();
    await expect(page.locator('#btn-activate-template')).toBeEnabled();
    await page.locator('#btn-activate-template').click();
    await expect(card).toContainText('active');
    await page.locator('#btn-nav-workspace').click();
    await page.locator('#btn-new-session').click();
    for (const size of [{ width: 1366, height: 768 }, { width: 1920, height: 1080 }]) {
        await page.setViewportSize(size);
        await expect(page.locator('#live-preview-img')).toBeVisible();
        expect(await page.locator('#btn-next').evaluate((button) => button.getBoundingClientRect().bottom <= innerHeight)).toBe(true);
        await page.screenshot({ path: info.outputPath(`filter-${size.width}.png`), fullPage: true });
    }
    await page.locator('[data-layout-id="single"]').click();
    await page.locator('#btn-next').click();
    await page.locator('#btn-auto-capture-all').click();
    await expect(page.locator('.slot-thumb-box img')).toHaveCount(1);
    await page.locator('#btn-next').click();
    await page.locator('#btn-next').click();
    await page.locator('[data-template-id="browser-custom-single"]').click();
    await expect(page.locator('[data-template-id="browser-custom-single"]')).toHaveAttribute('aria-pressed', 'true');
});
