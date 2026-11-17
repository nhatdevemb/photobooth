/** DCC owns the USB camera. Only authenticated PHP APIs are exposed to the renderer. */
export class DccCamera {
    constructor(image, badge, status, api, paused) {
        Object.assign(this, { image, badge, status, api, paused });
        this.active = false;
        this.generation = 0;
        this.objectUrl = null;
    }

    async start() {
        await this.api('POST', '/camera/preview/start', {});
        this.active = true;
        this.status.textContent = 'Live view R10 · JPEG gốc được nhận riêng khi chụp. Beauty xử lý sau chụp.';
        this.badge.textContent = '● Canon qua digiCamControl';
        const generation = ++this.generation;
        this.loop(generation);
    }

    async stop() {
        if (!this.active) return;
        this.active = false;
        this.generation++;
        this.controller?.abort();
        this.image.hidden = true;
        if (this.objectUrl) URL.revokeObjectURL(this.objectUrl);
        this.objectUrl = null;
        this.badge.textContent = 'Live view đã tắt';
        try { await this.api('POST', '/camera/preview/stop', {}); }
        catch (error) { this.status.textContent = error.message; }
    }

    async loop(generation) {
        while (this.active && generation === this.generation) {
            if (this.paused() || document.hidden) {
                await new Promise((resolve) => setTimeout(resolve, 100));
                continue;
            }
            const started = performance.now();
            try {
                this.controller = new AbortController();
                const timeout = setTimeout(() => this.controller.abort(), 3000);
                let response;
                try { response = await fetch('/custom/api/camera/preview.jpg', { credentials: 'same-origin', cache: 'no-store', signal: this.controller.signal }); }
                finally { clearTimeout(timeout); }
                if (response.status === 409) {
                    await new Promise((resolve) => setTimeout(resolve, 100));
                    continue;
                }
                if (!response.ok) {
                    const body = await response.json();
                    throw new Error(body.error?.message || 'Mất kết nối live view.');
                }
                const blob = await response.blob();
                if (!this.active || generation !== this.generation) break;
                const next = URL.createObjectURL(blob);
                const old = this.objectUrl;
                this.objectUrl = next;
                this.image.src = next;
                this.image.hidden = false;
                if (old) URL.revokeObjectURL(old);
            } catch (error) {
                if (!this.active || generation !== this.generation) break;
                this.status.textContent = `${error.message} Bấm Bật live view R10 để thử lại.`;
                await this.stop();
                break;
            }
            await new Promise((resolve) => setTimeout(resolve, Math.max(0, 100 - (performance.now() - started))));
        }
    }
}
