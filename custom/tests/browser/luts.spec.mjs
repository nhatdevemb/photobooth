import { test, expect } from 'playwright/test';
import { readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { login, cleanup } from './helpers.mjs';

test.afterEach(cleanup);

test('admin imports a mixed batch, previews intensity and uses the activated filter through print', async ({ page }, info) => {
    await login(page, true);
    await page.locator('#btn-nav-luts').click();
    const cube = Buffer.concat([Buffer.from('# browser LUT fixture\n'), readFileSync(resolve(import.meta.dirname, '../../presets/film-400.cube'))]);
    await page.locator('#lut-files').setInputFiles([
        { name:'Portrait.cube', mimeType:'text/plain', buffer:cube },
        { name:'Broken.cube', mimeType:'text/plain', buffer:Buffer.from('invalid LUT') },
        { name:'Duplicate.cube', mimeType:'text/plain', buffer:cube },
    ]);
    await page.locator('#btn-import-luts').click();
    await expect(page.locator('#lut-import-results li')).toHaveCount(3);
    await expect(page.locator('#lut-import-results')).toContainText('Đã nhập nháp');
    await expect(page.locator('#lut-import-results')).toContainText('đã tồn tại');
    await expect(page.locator('#lut-import-results')).toContainText('không hợp lệ');
    const draft = page.locator('.lut-card[data-status="draft"]');
    await draft.locator('[data-lut-edit]').click();
    await expect(page.locator('#btn-activate-lut')).toBeEnabled();
    await page.locator('#lut-name').fill('Portrait Upload E2E');
    await page.locator('#lut-intensity').evaluate((input) => {
        input.value = '65';
        input.dispatchEvent(new Event('input', { bubbles:true }));
    });
    await expect(page.locator('#btn-activate-lut')).toBeEnabled();
    await expect(page.locator('#lut-preview-state')).toContainText('65%');
    await page.screenshot({ path:info.outputPath('lut-preview.png') });
    await page.locator('#btn-activate-lut').click();
    await expect(page.locator('#modal-lut-editor')).toBeHidden();
    const catalog = await (await page.request.get('/custom/api/presets')).json();
    const filter = catalog.presets.find((item) => item.name === 'Portrait Upload E2E');
    expect(filter.intensity).toBe(65);
    await page.locator('#btn-nav-workspace').click();
    await page.locator('#btn-new-session').click();
    for (const size of [{ width:1366,height:768 }, { width:1920,height:1080 }]) {
        await page.setViewportSize(size);
        expect(await page.locator('#btn-next').evaluate((button) => button.getBoundingClientRect().bottom <= innerHeight)).toBe(true);
    }
    await page.locator('[data-layout-id="single"]').click();
    await page.locator('#btn-next').click();
    await page.locator('#btn-auto-capture-all').click();
    await expect(page.locator('.slot-thumb-box img')).toHaveCount(1);
    await page.locator('#btn-next').click();
    await page.locator('#filter-search').fill('Portrait Upload');
    await expect(page.locator('#selection-cards [data-lut-id]')).toHaveCount(1);
    await page.locator(`[data-lut-id="${filter.id}"]`).click();

    await expect(page.locator('#configuration-summary')).toContainText('65%');
    await page.reload();
    await expect(page.locator('#configuration-summary')).toContainText('Portrait Upload E2E');
    await page.locator('#btn-next').click();
    await page.locator('#btn-next').click();
    await expect(page.locator('#btn-finalize-session')).toBeEnabled();
    await page.locator('#btn-finalize-session').click();
    await expect(page.locator('#modal-qr-img')).toBeVisible();
    await page.locator('#btn-open-print-modal').click();
    await page.locator('#btn-confirm-print-submit').click();
    await expect(page.locator('#session-prints-list')).toContainText('COMPLETED');
});

test('preview failure blocks activation and a replacement version waits for the latest intensity', async ({ page }) => {
    await login(page, true);
    await page.locator('#btn-nav-luts').click();
    const source = readFileSync(resolve(import.meta.dirname, '../../presets/korean-soft.cube'));
    await page.locator('#lut-files').setInputFiles({ name:'Version.cube', mimeType:'text/plain', buffer:Buffer.concat([Buffer.from('# version browser\n'),source]) });
    await page.locator('#btn-import-luts').click();
    await expect(page.locator('#lut-import-results')).toContainText('Đã nhập nháp');
    await page.route('**/custom/api/catalog/luts/**/preview.jpg*', (route) => route.abort());
    await page.locator('.lut-card[data-status="draft"] [data-lut-edit]').click();
    await expect(page.locator('#lut-preview-state')).toContainText('Không tải được');
    await expect(page.locator('#btn-activate-lut')).toBeDisabled();
    await page.unroute('**/custom/api/catalog/luts/**/preview.jpg*');
    await page.locator('#btn-save-lut').click();
    await expect(page.locator('#btn-activate-lut')).toBeEnabled();
    await page.locator('#btn-activate-lut').click();
    const records = (await (await page.request.get('/custom/api/admin/luts')).json()).items;
    const original = records.find((item) => !item.readonly && item.name === 'Korean Soft v1.0.0');
    const card = page.locator(`[data-lut-key="${original.id}/1.0.0"]`);
    await card.locator('[data-lut-version]').click();
    await expect(page.locator('#lut-editor-meta')).toContainText('1.0.1');
    await page.locator('#lut-replacement').setInputFiles(resolve(import.meta.dirname, '../../presets/film-400.cube'));
    await expect(page.locator('#btn-activate-lut')).toBeDisabled();
    await expect(page.locator('#btn-activate-lut')).toBeEnabled();
    await page.route('**/admin/luts/*/1.0.1/draft', async (route) => {
        await new Promise((done) => setTimeout(done, 500));
        await route.continue();
    });
    const request = page.waitForRequest((r) => r.url().endsWith('/1.0.1/draft'));
    await page.locator('#lut-intensity').evaluate((input) => { input.value='20'; input.dispatchEvent(new Event('input',{ bubbles:true })); });
    await request;
    await page.locator('#lut-intensity').evaluate((input) => { input.value='90'; input.dispatchEvent(new Event('input',{ bubbles:true })); });
    await expect(page.locator('#btn-activate-lut')).toBeDisabled();
    await expect(page.locator('#lut-preview-state')).toContainText('Đã tải đúng ảnh mẫu 90%');
    await expect(page.locator('#btn-activate-lut')).toBeEnabled();
    await page.locator('#btn-activate-lut').click();
    const active = (await (await page.request.get('/custom/api/presets')).json()).presets.find((item) => item.id === original.id);
    expect(active.version).toBe('1.0.1');
    expect(active.intensity).toBe(90);
    expect(active.sha256).not.toBe(original.sha256);
    await expect(card).toContainText('disabled');
});

if (process.env.PB_DAEDAL_LUT_DIR) {
    test('all 20 DAEDAL files import, preview, activate and survive reload through the web UI', async ({ page }, info) => {
        test.setTimeout(180000);
        const directory = process.env.PB_DAEDAL_LUT_DIR;
        const files = readdirSync(directory).filter((name) => name.endsWith('.cube')).map((name) => resolve(directory,name));
        expect(files).toHaveLength(20);
        await login(page,true);
        await page.locator('#btn-nav-luts').click();
        await page.locator('#lut-files').setInputFiles(files);
        await page.locator('#btn-import-luts').click();
        await expect(page.locator('#lut-import-results li')).toHaveCount(20, { timeout:90000 });
        await expect(page.locator('#btn-import-luts')).toBeEnabled({ timeout:90000 });
        expect(await page.locator('#lut-import-results .import-error').count()).toBe(0);
        const imported = (await (await page.request.get('/custom/api/admin/luts')).json()).items.filter((item) => item.name.startsWith('DAEDAL '));
        expect(imported).toHaveLength(20);
        for (const item of imported) {
            await page.locator(`[data-lut-key="${item.id}/1.0.0"] [data-lut-edit]`).click();
            await expect(page.locator('#btn-activate-lut')).toBeEnabled();
            await page.locator('#btn-activate-lut').click();
            await expect(page.locator('#modal-lut-editor')).toBeHidden();
        }
        await page.reload();
        await expect(page.locator('#btn-nav-luts')).toBeVisible();
        const active = (await (await page.request.get('/custom/api/presets')).json()).presets.filter((item) => item.name.startsWith('DAEDAL '));
        expect(active).toHaveLength(20);
        await page.locator('#btn-nav-workspace').click();
        await page.locator('#btn-new-session').click();
        await page.locator('[data-layout-id="single"]').click();
        await page.locator('#btn-next').click();
        await page.locator('#btn-auto-capture-all').click();
        await expect(page.locator('.slot-thumb-box img')).toHaveCount(1);
        await page.locator('#btn-next').click();
        await page.locator('#filter-search').fill('DAEDAL');
        await expect(page.locator('#selection-cards [data-lut-id]')).toHaveCount(20);
        await expect.poll(() => page.locator('#selection-preview').evaluate((canvas) => canvas.getContext('2d').getImageData(590,874,1,1).data[3])).toBe(255);
        expect(await page.locator('#selection-preview').evaluate((canvas) => Array.from(canvas.getContext('2d').getImageData(590,874,1,1).data).slice(0,3))).not.toEqual([255,255,255]);
        for (const size of [{width:1366,height:768},{width:1920,height:1080}]) {
            await page.setViewportSize(size);
            expect(await page.locator('#btn-next').evaluate((button) => button.getBoundingClientRect().bottom <= innerHeight)).toBe(true);
            await page.screenshot({ path:info.outputPath(`daedal-library-${size.width}.png`) });
        }
    });
}
