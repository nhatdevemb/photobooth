/** DCC owns the USB camera. Only authenticated PHP APIs are exposed to the renderer. */
export class DccCamera {
    constructor(image, badge, status, api) {
        Object.assign(this, { image, badge, status, api });
        this.active = false;
        this.generation = 0;
        this.objectUrl = null;
    }

    async start() {
        if (this.stopping) await this.stopping;
        if (this.active) return;
        if (this.starting) return this.starting;
        const generation = ++this.generation;
        this.starting = (async () => {
            await this.api('POST', '/camera/preview/start', {});
            if (generation !== this.generation) return;
            this.active = true;
            this.status.textContent = 'Live view R10 bật cả lúc đếm ngược. JPEG gốc được nhận riêng khi chụp.';
            this.badge.textContent = '● Canon qua digiCamControl';
            this.loop(generation);
        })();
        try { await this.starting; }
        finally { this.starting = null; }
    }

    async stop() {
        if (this.stopping) return this.stopping;
        if (!this.active && !this.starting) return;
        this.active = false;
        this.generation++;
        this.controller?.abort();
        this.image.hidden = true;
        if (this.objectUrl) URL.revokeObjectURL(this.objectUrl);
        this.objectUrl = null;
        this.badge.textContent = 'Live view đã tắt';
        this.stopping = (async () => {
            // Finish any pending start before sending the stop command.
            await this.starting?.catch(() => {});
            await this.api('POST', '/camera/preview/stop', {});
        })().catch((error) => { this.status.textContent = error.message; });
        try { await this.stopping; }
        finally { this.stopping = null; }
    }

    async loop(generation) {
        let failures = 0;
        while (this.active && generation === this.generation) {
            if (document.hidden) {
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
                if ([502, 503, 504].includes(response.status)) {
                    await response.body?.cancel();
                    throw new Error('Liveview đang chờ dịch vụ hoặc máy ảnh.');
                }
                if (response.status === 409) {
                    const body = await response.json();
                    if (body.error?.code !== 'CAMERA_BUSY') {
                        this.status.textContent = body.error?.message || 'Camera đã thay đổi. Bật lại live view.';
                        await this.stop();
                        break;
                    }
                    await new Promise((resolve) => setTimeout(resolve, 100));
                    continue;
                }
                if (!response.ok) {
                    const body = await response.json().catch(() => ({}));
                    this.status.textContent = body.error?.message || 'Bấm Bật live view R10 để kết nối lại.';
                    await this.stop();
                    break;
                }
                const blob = await response.blob();
                if (!this.active || generation !== this.generation) break;
                const next = URL.createObjectURL(blob);
                const old = this.objectUrl;
                this.objectUrl = next;
                this.image.src = next;
                this.image.hidden = false;
                if (old) URL.revokeObjectURL(old);
                if (failures) this.status.textContent = 'Live view R10 đã kết nối lại.';
                failures = 0;
            } catch {
                if (!this.active || generation !== this.generation) break;
                failures++;
                this.status.textContent = 'Live view R10 đang kết nối lại…';
                await new Promise((resolve) => setTimeout(resolve, Math.min(2000, failures * 250)));
                continue;
            }
            await new Promise((resolve) => setTimeout(resolve, Math.max(0, 100 - (performance.now() - started))));
        }
    }
}
