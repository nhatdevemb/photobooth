import { test, expect } from 'playwright/test';
import { login, setup, style, cleanup } from './helpers.mjs';

test.afterEach(cleanup);

test('camera preview fills its column on laptop, desktop and short viewports', async ({ page }, info) => {
    await login(page, true);
    await setup(page, 'postcard-three');
    await page.locator('#btn-webcam-enable').click();
    await expect(page.locator('#camera-badge')).toContainText('trực tiếp');
    for (const size of [{ width: 1366, height: 768 }, { width: 1920, height: 1080 }, { width: 1825, height: 838 }, { width: 1216, height: 559 }]) {
        await page.setViewportSize(size);
        const measure = () => page.evaluate(() => {
            const box = document.querySelector('.live-preview-box').getBoundingClientRect();
            const column = document.querySelector('.camera-column').getBoundingClientRect();
            const video = document.getElementById('webcam-video');
            const scale = Math.min(video.clientWidth / video.videoWidth, video.clientHeight / video.videoHeight);
            const footer = document.getElementById('wizard-footer').getBoundingClientRect();
            return { widthFraction: box.width / column.width, visibleVideoFraction: video.videoWidth * scale / column.width, overflow: document.documentElement.scrollWidth > innerWidth, box: { top:box.top, height:box.height, width:box.width }, columnHeight:column.height, footerBottom:footer.bottom, viewport:innerHeight, source:[video.videoWidth,video.videoHeight] };
        });
        await expect.poll(async () => (await measure()).visibleVideoFraction).toBeGreaterThanOrEqual(0.7);
        const metrics = await measure();
        await page.screenshot({ path: info.outputPath(`preview-${size.width}x${size.height}.png`), fullPage: true });
        expect(metrics.overflow).toBe(false);
        expect(metrics.widthFraction).toBeGreaterThanOrEqual(0.95);
        expect(metrics.visibleVideoFraction, JSON.stringify(metrics)).toBeGreaterThanOrEqual(0.7);
        if (size.height >= 768) {
            expect(await page.locator('#btn-next').evaluate((button) => button.getBoundingClientRect().bottom <= innerHeight)).toBe(true);
        }
    }
});

for (const [layout, count] of [['single', 1], ['strip-three', 3], ['postcard-three', 3], ['grid-four', 4]]) {
    test(`browser camera captures, reloads, retakes and finalizes ${layout}`, async ({ page }, info) => {
        await login(page);
        await setup(page, layout);
        await expect(page.locator('.sim-banner')).toContainText('laptop / điện thoại');
        await page.locator('#btn-webcam-enable').click();
        await expect(page.locator('#camera-badge')).toContainText('trực tiếp');
        expect(await page.locator('#webcam-video').evaluate((video) => video.srcObject.getAudioTracks().length)).toBe(0);
        if (layout === 'single') {
            for (const size of [{ width: 1366, height: 768 }, { width: 1920, height: 1080 }]) {
                await page.setViewportSize(size);
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
                expect(await page.locator('#btn-auto-capture-all').evaluate((button) => button.getBoundingClientRect().bottom <= innerHeight)).toBe(true);
                expect(await page.locator('#btn-next').evaluate((button) => button.getBoundingClientRect().bottom <= innerHeight)).toBe(true);
                await page.screenshot({ path: info.outputPath(`camera-${size.width}.png`), fullPage: true });
            }
        }
        await page.locator('#btn-auto-capture-all').click();
        await expect(page.locator('.slot-thumb-box img')).toHaveCount(count);
        await expect(page.locator('#btn-next')).toBeEnabled();
        if (layout === 'single' || layout === 'grid-four') {
            for (const size of [{ width: 1366, height: 768 }, { width: 1920, height: 1080 }]) {
                await page.setViewportSize(size);
                expect(await page.locator('#btn-next').evaluate((button) => button.getBoundingClientRect().bottom <= innerHeight)).toBe(true);
            }
        }
        let session = (await (await page.request.get('/custom/api/sessions/active')).json()).active_session;
        expect(session.configuration_locked).toBe(true);
        expect(session.slots[0].photo.width).toBeGreaterThanOrEqual(640);
        const oldPhoto = session.slots[0].photo.id;
        await page.reload();
        await expect(page.locator('.slot-thumb-box img')).toHaveCount(count);
        await expect(page.locator('#webcam-video')).toBeVisible();
        await page.waitForTimeout(500);
        session = (await (await page.request.get('/custom/api/sessions/active')).json()).active_session;
        expect(session.slots[0].photo.id).toBe(oldPhoto);
        await page.locator('#btn-webcam-enable').click();
        await expect(page.locator('#camera-badge')).toContainText('trực tiếp');
        await page.locator('[data-slot="1"]').click();
        await expect(page.locator('.slot-card').first()).toContainText('v2');
        await expect(page.locator('#btn-next')).toBeEnabled();
        await page.locator('#btn-next').click();
        await expect(page.locator('#webcam-video')).toBeHidden();
        await expect.poll(() => page.locator('#webcam-video').evaluate((video) => video.srcObject)).toBeNull();
        await expect(page.locator('#selection-preview')).toBeVisible();
        await page.locator('#btn-next').click();
        await expect(page.locator('#selection-preview')).toBeVisible();
        await page.locator('#btn-next').click();
        await expect(page.locator('#btn-finalize-session')).toBeEnabled();
        await page.locator('#btn-finalize-session').click();
        await expect(page.locator('#modal-qr-img')).toBeVisible();
        await page.locator('#btn-open-print-modal').click();
        await page.locator('#btn-confirm-print-submit').click();
        await expect(page.locator('#session-prints-list')).toContainText('COMPLETED');
        await page.locator('#btn-close-session').click();
        await expect(page.locator('#btn-new-session')).toBeVisible();
    });
}

test('phone viewport can switch camera, stop and capture a JPEG with an accurate crop guide', async ({ page }, info) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await setup(page, 'single');
    await page.locator('#btn-webcam-enable').click();
    await expect(page.locator('#camera-badge')).toContainText('trực tiếp');
    await expect(page.locator('#webcam-status')).toContainText('Filter: Gốc');
    await expect(page.locator('#btn-webcam-mirror')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('#webcam-video')).toHaveClass(/mirrored/);
    await expect(page.locator('#webcam-lut-canvas')).toHaveClass(/mirrored/);
    await page.locator('#btn-webcam-mirror').click();
    await expect(page.locator('#btn-webcam-mirror')).toHaveAttribute('aria-pressed', 'false');
    await expect(page.locator('#webcam-video')).not.toHaveClass(/mirrored/);
    await expect(page.locator('#webcam-lut-canvas')).not.toHaveClass(/mirrored/);
    await page.locator('#btn-webcam-mirror').click();
    await expect(page.locator('#btn-webcam-mirror')).toHaveAttribute('aria-pressed', 'true');
    await page.locator('#btn-webcam-switch').click();
    await expect(page.locator('#camera-badge')).toContainText('trực tiếp');
    await expect(page.locator('#btn-webcam-switch')).toBeEnabled();
    const dimensions = await page.evaluate(() => {
        const guide = document.getElementById('crop-guide').getBoundingClientRect();
        return { ratio: guide.width / guide.height, overflow: document.documentElement.scrollWidth > innerWidth };
    });
    expect(dimensions.overflow).toBe(false);
    expect(dimensions.ratio).toBeCloseTo(1111 / 1678, 2);
    await page.screenshot({ path: info.outputPath('phone-camera.png'), fullPage: true });
    await page.locator('#btn-webcam-stop').click();
    await expect(page.locator('#webcam-video')).toBeHidden();
    await page.locator('#btn-auto-capture-all').click();
    await expect(page.locator('.toast.error')).toContainText('Bật camera');
    expect((await (await page.request.get('/custom/api/sessions/active')).json()).active_session.accepted_slots).toBe(0);
    await page.locator('#btn-webcam-enable').click();
    await expect(page.locator('#camera-badge')).toContainText('trực tiếp');
    await page.locator('#btn-auto-capture-all').click();
    await expect(page.locator('.slot-thumb-box img')).toHaveCount(1);
});

test('permission errors are actionable and never substitute mock photos', async ({ page }) => {
    await login(page);
    await setup(page, 'single');
    await page.locator('#btn-webcam-stop').click();
    await page.evaluate(() => {
        navigator.mediaDevices.getUserMedia = async () => { throw new DOMException('denied', 'NotAllowedError'); };
    });
    await page.locator('#btn-webcam-enable').click();
    await expect(page.locator('#webcam-status')).toContainText('Chưa có quyền camera');
    await expect(page.locator('#webcam-video')).toBeHidden();
    await page.locator('#btn-auto-capture-all').click();
    await expect(page.locator('#btn-auto-capture-all')).toBeEnabled();
    const session = (await (await page.request.get('/custom/api/sessions/active')).json()).active_session;
    expect(session.configuration_locked).toBe(false);
    expect(session.active_job).toBeNull();
    expect(session.accepted_slots).toBe(0);
});

test('camera disconnect during countdown creates no capture job', async ({ page }) => {
    await login(page);
    await setup(page, 'single');
    await page.locator('#btn-webcam-enable').click();
    await expect(page.locator('#camera-badge')).toContainText('trực tiếp');
    await page.evaluate(() => { document.body.dataset.countdown = '3'; });
    await page.locator('#btn-auto-capture-all').click();
    await expect(page.locator('#countdown-overlay')).toBeVisible();
    await page.locator('#webcam-video').evaluate((video) => {
        const track = video.srcObject.getVideoTracks()[0];
        track.stop();
        track.dispatchEvent(new Event('ended'));
    });
    await expect(page.locator('#btn-auto-capture-all')).toBeEnabled();
    await expect(page.locator('#webcam-status')).toContainText('mất kết nối');
    const session = (await (await page.request.get('/custom/api/sessions/active')).json()).active_session;
    expect(session.accepted_slots).toBe(0);
    expect(session.active_job).toBeNull();
});

test('configuration changed from another tab during countdown rejects the stale frame', async ({ page }) => {
    await login(page);
    await setup(page, 'single');
    await page.locator('#btn-webcam-enable').click();
    await expect(page.locator('#camera-badge')).toContainText('trực tiếp');
    await page.evaluate(() => { document.body.dataset.countdown = '3'; });
    const auth = await (await page.request.get('/custom/api/auth/me')).json();
    const session = (await (await page.request.get('/custom/api/sessions/active')).json()).active_session;
    const responsePromise = page.waitForResponse((response) => response.url().endsWith('/slots/1/capture') && response.request().method() === 'POST');
    await page.locator('#btn-auto-capture-all').click();
    await expect(page.locator('#countdown-overlay')).toBeVisible();
    const configuration = await page.request.post(`/custom/api/sessions/${session.id}/configuration`, {
        headers: { 'X-CSRF-Token': auth.csrf_token, 'Idempotency-Key': crypto.randomUUID() },
        data: { revision: session.revision, lut_id: 'original' },
    });
    expect(configuration.status()).toBe(200);
    const response = await responsePromise;
    expect(response.status()).toBe(409);
    expect((await response.json()).error.code).toBe('REVISION_CONFLICT');
    await expect(page.locator('#btn-auto-capture-all')).toBeEnabled();
    const updated = (await (await page.request.get('/custom/api/sessions/active')).json()).active_session;
    expect(updated.accepted_slots).toBe(0);
    expect(updated.selected_lut).toBe('original');
    expect(updated.active_job).toBeNull();
});
