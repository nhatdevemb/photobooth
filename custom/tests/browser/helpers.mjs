import { expect } from 'playwright/test';

export async function login(page, admin = false)
{
    await page.goto('/');
    await page.locator('#login-username').fill(admin ? 'admin1' : 'operator1');
    await page.locator('#login-password').fill(admin ? 'AdminPass123!' : 'OperatorPass123!');
    await page.locator('#btn-login-submit').click();
    await expect(page.locator('#workspace-view')).toBeVisible();
}

export async function setup(page, layout = 'postcard-three')
{
    await page.locator('#btn-new-session').click();
    await expect(page.locator('[data-step="1"]')).toHaveAttribute('aria-current', 'step');
    await page.locator('[data-layout-id="' + layout + '"]').click();
    await page.locator('#btn-next').click();
    await expect(page.locator('[data-step="2"]')).toHaveAttribute('aria-current', 'step');
}

export async function style(page, lut = 'korean-soft')
{
    await page.locator('#btn-next').click();
    await page.locator('[data-lut-id="' + lut + '"]').click();
    await page.locator('#btn-next').click();
    await page.locator('[data-style="cute"]').click();
    await page.locator('#btn-next').click();
}

export async function review(page, layout = 'postcard-three', waitPreview = true)
{
    await setup(page, layout);
    await page.locator('#btn-auto-capture-all').click();
    await expect(page.locator('.slot-thumb-box img')).toHaveCount(layout === 'single' ? 1 : layout === 'grid-four' ? 4 : 3);
    await style(page);
    if (waitPreview) await expect(page.locator('#btn-finalize-session')).toBeEnabled();
}

export async function cleanup({ page })
{
    const loginResponse = await page.request.post('/custom/api/auth/login', { data: { username: 'operator1', password: 'OperatorPass123!' } });
    const auth = await loginResponse.json();
    let active = (await (await page.request.get('/custom/api/sessions/active')).json()).active_session;
    for (let attempt = 0; active?.active_job && attempt < 160; attempt++) {
        await new Promise((resolve) => setTimeout(resolve, 100));
        active = (await (await page.request.get('/custom/api/sessions/active')).json()).active_session;
    }
    if (active) {
        const operation = active.state === 'FINALIZED' ? 'close' : 'cancel';
        const response = await page.request.post(`/custom/api/sessions/${active.id}/${operation}`, {
            headers: { 'X-CSRF-Token': auth.csrf_token, 'Idempotency-Key': crypto.randomUUID() }, data: { revision: active.revision },
        });
        expect(response.ok()).toBeTruthy();
    }
}
