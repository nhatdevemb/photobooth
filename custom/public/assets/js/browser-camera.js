/** Camera belongs to the device opening the operator UI. No microphone is requested. */
export function parseCubeLut(text)
{
    let size = 33;
    const rows = [];
    for (const raw of text.split(/\r?\n/)) {
        const line = raw.trim();
        if (!line || line.startsWith('#')) continue;
        const upper = line.toUpperCase();
        if (upper.startsWith('TITLE') || upper.startsWith('DOMAIN_MIN') || upper.startsWith('DOMAIN_MAX')) continue;
        if (upper.startsWith('LUT_3D_SIZE')) {
            const parsed = Number(line.split(/\s+/)[1]);
            if (parsed >= 2 && parsed <= 128) size = parsed;
            continue;
        }
        const parts = line.split(/\s+/);
        if (parts.length === 3) {
            const r = Number(parts[0]), g = Number(parts[1]), b = Number(parts[2]);
            if (Number.isFinite(r) && Number.isFinite(g) && Number.isFinite(b)) rows.push(r, g, b);
        }
    }
    const total = size * size * size;
    if (rows.length < total * 3) return null;
    const rgba = new Uint8Array(total * 4);
    for (let i = 0, j = 0, k = 0; i < total; i++, j += 3, k += 4) {
        rgba[k] = Math.round(Math.min(1, Math.max(0, rows[j])) * 255);
        rgba[k + 1] = Math.round(Math.min(1, Math.max(0, rows[j + 1])) * 255);
        rgba[k + 2] = Math.round(Math.min(1, Math.max(0, rows[j + 2])) * 255);
        rgba[k + 3] = 255;
    }
    return { size, rgba };
}

export class LutCanvasRenderer
{
    constructor(canvas)
    {
        this.canvas = canvas;
        this.ctx2d = null;
        this.gl = null;
        this.currentLut = null;
        this.initWebGL();
    }

    initWebGL()
    {
        if (!this.canvas) return;
        try {
            const gl = this.canvas.getContext('webgl2', { premultipliedAlpha: false, preserveDrawingBuffer: true });
            if (!gl) return;
            const vs = gl.createShader(gl.VERTEX_SHADER);
            gl.shaderSource(vs, `#version 300 es
                in vec2 a_pos;
                out vec2 v_uv;
                void main() {
                    v_uv = vec2(a_pos.x * 0.5 + 0.5, 0.5 - a_pos.y * 0.5);
                    gl_Position = vec4(a_pos, 0.0, 1.0);
                }`);
            gl.compileShader(vs);
            const fs = gl.createShader(gl.FRAGMENT_SHADER);
            gl.shaderSource(fs, `#version 300 es
                precision mediump float;
                precision mediump sampler3D;
                in vec2 v_uv;
                uniform sampler2D u_frame;
                uniform sampler3D u_lut;
                uniform float u_intensity;
                uniform float u_size;
                out vec4 outColor;
                void main() {
                    vec4 raw = texture(u_frame, v_uv);
                    vec3 scale = vec3((u_size - 1.0) / u_size);
                    vec3 offset = vec3(0.5 / u_size);
                    vec3 graded = texture(u_lut, clamp(raw.rgb, 0.0, 1.0) * scale + offset).rgb;
                    outColor = vec4(mix(raw.rgb, graded, u_intensity), raw.a);
                }`);
            gl.compileShader(fs);
            if (!gl.getShaderParameter(vs, gl.COMPILE_STATUS) || !gl.getShaderParameter(fs, gl.COMPILE_STATUS)) return;
            const prog = gl.createProgram();
            gl.attachShader(prog, vs);
            gl.attachShader(prog, fs);
            gl.linkProgram(prog);
            if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) return;
            gl.useProgram(prog);
            const buf = gl.createBuffer();
            gl.bindBuffer(gl.ARRAY_BUFFER, buf);
            gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]), gl.STATIC_DRAW);
            const loc = gl.getAttribLocation(prog, 'a_pos');
            gl.enableVertexAttribArray(loc);
            gl.vertexAttribPointer(loc, 2, gl.FLOAT, false, 0, 0);
            this.frameTex = gl.createTexture();
            gl.activeTexture(gl.TEXTURE0);
            gl.bindTexture(gl.TEXTURE_2D, this.frameTex);
            gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
            gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
            gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
            gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
            this.lutTex = gl.createTexture();
            gl.activeTexture(gl.TEXTURE1);
            gl.bindTexture(gl.TEXTURE_3D, this.lutTex);
            gl.texParameteri(gl.TEXTURE_3D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
            gl.texParameteri(gl.TEXTURE_3D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
            gl.texParameteri(gl.TEXTURE_3D, gl.TEXTURE_WRAP_R, gl.CLAMP_TO_EDGE);
            gl.texParameteri(gl.TEXTURE_3D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
            gl.texParameteri(gl.TEXTURE_3D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
            gl.uniform1i(gl.getUniformLocation(prog, 'u_frame'), 0);
            gl.uniform1i(gl.getUniformLocation(prog, 'u_lut'), 1);
            this.uIntensity = gl.getUniformLocation(prog, 'u_intensity');
            this.uSize = gl.getUniformLocation(prog, 'u_size');
            this.gl = gl;
        } catch {
            this.gl = null;
        }
    }

    render(source, lut, intensity = 1)
    {
        if (!this.canvas || !lut) return false;
        const srcW = source.videoWidth || source.naturalWidth || source.width || 0;
        const srcH = source.videoHeight || source.naturalHeight || source.height || 0;
        if (!srcW || !srcH) return false;
        const alpha = Math.min(1, Math.max(0, intensity));
        if (this.gl) {
            const gl = this.gl;
            const scale = Math.min(1, 960 / Math.max(srcW, srcH));
            const w = Math.max(1, Math.round(srcW * scale)), h = Math.max(1, Math.round(srcH * scale));
            if (this.canvas.width !== w || this.canvas.height !== h) {
                this.canvas.width = w;
                this.canvas.height = h;
                gl.viewport(0, 0, w, h);
            }
            if (this.currentLut !== lut) {
                this.currentLut = lut;
                gl.activeTexture(gl.TEXTURE1);
                gl.bindTexture(gl.TEXTURE_3D, this.lutTex);
                gl.texImage3D(gl.TEXTURE_3D, 0, gl.RGBA8, lut.size, lut.size, lut.size, 0, gl.RGBA, gl.UNSIGNED_BYTE, lut.rgba);
            }
            gl.activeTexture(gl.TEXTURE0);
            gl.bindTexture(gl.TEXTURE_2D, this.frameTex);
            gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, source);
            gl.uniform1f(this.uIntensity, alpha);
            gl.uniform1f(this.uSize, lut.size);
            gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
            return true;
        }
        if (!this.ctx2d) this.ctx2d = this.canvas.getContext('2d', { willReadFrequently: true });
        if (!this.ctx2d) return false;
        const scale = Math.min(1, 360 / Math.max(srcW, srcH));
        const w = Math.max(1, Math.round(srcW * scale)), h = Math.max(1, Math.round(srcH * scale));
        if (this.canvas.width !== w || this.canvas.height !== h) {
            this.canvas.width = w;
            this.canvas.height = h;
        }
        this.ctx2d.drawImage(source, 0, 0, w, h);
        const frame = this.ctx2d.getImageData(0, 0, w, h);
        const data = frame.data, rgba = lut.rgba, n = lut.size, maxIdx = n - 1, scaleIdx = maxIdx / 255;
        for (let i = 0; i < data.length; i += 4) {
            const r0 = data[i], g0 = data[i + 1], b0 = data[i + 2];
            const ri = Math.round(r0 * scaleIdx), gi = Math.round(g0 * scaleIdx), bi = Math.round(b0 * scaleIdx);
            const idx = ((bi * n + gi) * n + ri) * 4;
            data[i] = Math.round(r0 + (rgba[idx] - r0) * alpha);
            data[i + 1] = Math.round(g0 + (rgba[idx + 1] - g0) * alpha);
            data[i + 2] = Math.round(b0 + (rgba[idx + 2] - b0) * alpha);
        }
        this.ctx2d.putImageData(frame, 0, 0);
        return true;
    }
}

export class BrowserCamera
{
    constructor(video, status, badge, devices, onResize, mirrorBtn = null, lutCanvas = null)
    {
        this.video = video;
        this.status = status;
        this.badge = badge;
        this.devices = devices;
        this.mirrorBtn = mirrorBtn;
        this.lutCanvas = lutCanvas;
        this.lutRenderer = lutCanvas ? new LutCanvasRenderer(lutCanvas) : null;
        this.lutData = null;
        this.lutIntensity = 100;
        this.lutName = 'Gốc';
        this.rafId = 0;
        this.stream = null;
        this.facing = 'user';
        this.mirrored = true;
        this.video.addEventListener('resize', onResize);
        this.syncMirror();
    }

    ready()
    {
        return Boolean(this.stream?.getVideoTracks().some((track) => track.readyState === 'live' && !track.muted)) && this.video.readyState >= 2 && this.video.videoWidth > 0;
    }

    setFilter(lutData = null, intensity = 100, name = 'Gốc')
    {
        this.lutData = lutData;
        this.lutIntensity = intensity;
        this.lutName = name || 'Gốc';
        this.syncMirror();
    }

    syncMirror()
    {
        this.video.classList.toggle('mirrored', this.mirrored);
        if (this.lutCanvas) this.lutCanvas.classList.toggle('mirrored', this.mirrored);
        if (this.mirrorBtn) {
            this.mirrorBtn.setAttribute('aria-pressed', String(this.mirrored));
            this.mirrorBtn.textContent = `Lật gương: ${this.mirrored ? 'Bật' : 'Tắt'}`;
            this.mirrorBtn.classList.toggle('outline', this.mirrored);
        }
        if (this.ready()) {
            const track = this.stream.getVideoTracks()[0];
            const filterLabel = this.lutData && this.lutIntensity > 0 ? `Filter: ${this.lutName} (${this.lutIntensity}%)` : 'Filter: Gốc';
            this.status.textContent = `${track.label || 'Camera'} · ${this.video.videoWidth} × ${this.video.videoHeight} · ${this.mirrored ? 'Đang lật gương' : 'Không lật gương'} · ${filterLabel}.`;
            this.updateLutLoop();
        } else if (this.lutCanvas && this.stream) {
            this.lutCanvas.hidden = true;
        }
    }

    updateLutLoop()
    {
        if (this.rafId) {
            cancelAnimationFrame(this.rafId);
            this.rafId = 0;
        }
        if (!this.lutCanvas || !this.lutRenderer || !this.ready() || !this.lutData || this.lutIntensity <= 0) {
            if (this.lutCanvas && this.stream) this.lutCanvas.hidden = true;
            return;
        }
        const tick = () => {
            if (!this.ready() || !this.lutData || this.lutIntensity <= 0) {
                if (this.lutCanvas) this.lutCanvas.hidden = true;
                this.rafId = 0;
                return;
            }
            if (this.lutRenderer.render(this.video, this.lutData, this.lutIntensity / 100)) {
                this.lutCanvas.hidden = false;
            }
            this.rafId = requestAnimationFrame(tick);
        };
        tick();
    }

    toggleMirror()
    {
        this.mirrored = !this.mirrored;
        this.syncMirror();
    }

    stop(message = 'Camera đã tắt. Bật camera để tiếp tục chụp.')
    {
        if (this.rafId) {
            cancelAnimationFrame(this.rafId);
            this.rafId = 0;
        }
        this.stream?.getTracks().forEach((track) => track.stop());
        this.stream = null;
        this.video.srcObject = null;
        this.video.hidden = true;
        if (this.lutCanvas) this.lutCanvas.hidden = true;
        this.badge.textContent = 'Camera chưa bật';
        this.status.textContent = message;
    }

    async open(deviceId = '', switchFacing = false)
    {
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
            throw new Error('Camera cần localhost trên laptop hoặc HTTPS được tin cậy trên điện thoại. Địa chỉ HTTP qua Wi-Fi không mở được camera.');
        }
        if (switchFacing) {
            this.facing = this.facing === 'user' ? 'environment' : 'user';
            this.mirrored = this.facing === 'user';
        }
        this.stop('Đang xin quyền và kết nối camera…');
        try {
            const video = { width: { ideal: 1920 }, height: { ideal: 1080 } };
            if (deviceId) video.deviceId = { exact: deviceId };
            else video.facingMode = { ideal: this.facing };
            this.stream = await navigator.mediaDevices.getUserMedia({ video, audio: false });
            this.video.srcObject = this.stream;
            this.video.hidden = false;
            await this.video.play();
            // Wait for an actual frame, including on iOS where metadata may precede pixels.
            const deadline = Date.now() + 10000;
            while (!this.ready()) {
                if (Date.now() > deadline) throw new Error('Camera chưa gửi được hình ảnh. Hãy tắt rồi bật lại camera.');
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
            const track = this.stream.getVideoTracks()[0];
            if (track.getSettings().facingMode) {
                this.facing = track.getSettings().facingMode;
                if (switchFacing) this.mirrored = this.facing === 'user';
            }
            track.addEventListener('ended', () => {
                if (this.stream?.getVideoTracks().includes(track)) this.stop('Camera mất kết nối. Bật lại camera trước khi chụp.');
            });
            this.badge.textContent = '● Camera trực tiếp';
            this.syncMirror();
            try {
                const cameras = (await navigator.mediaDevices.enumerateDevices()).filter((item) => item.kind === 'videoinput');
                this.devices.replaceChildren(new Option('Camera mặc định', ''), ...cameras.map((item, index) => new Option(item.label || `Camera ${index + 1}`, item.deviceId)));
                this.devices.value = track.getSettings().deviceId || '';
            } catch { /* Preview and capture still work if the browser cannot list devices. */ }
        } catch (error) {
            const messages = {
                NotAllowedError: 'Chưa có quyền camera. Cho phép camera trong trình duyệt và kiểm tra HTTPS, rồi bật lại.',
                NotFoundError: 'Không tìm thấy camera trên thiết bị này.',
                NotReadableError: 'Không mở được camera. Kiểm tra ứng dụng khác đang dùng camera hoặc kết nối thiết bị.',
                OverconstrainedError: 'Camera đã chọn không khả dụng. Hãy chọn camera mặc định.',
                AbortError: 'Kết nối camera bị ngắt. Hãy bật lại camera.',
            };
            const message = messages[error.name] || error.message || 'Không mở được camera.';
            this.stop(message);
            throw new Error(message);
        }
    }

    async photo()
    {
        if (!this.ready() || document.hidden) throw new Error('Camera chưa sẵn sàng. Giữ màn hình chụp đang mở và bật camera trước.');
        const canvas = document.createElement('canvas');
        const scale = Math.min(1, 2560 / Math.max(this.video.videoWidth, this.video.videoHeight));
        canvas.width = Math.round(this.video.videoWidth * scale);
        canvas.height = Math.round(this.video.videoHeight * scale);
        const context = canvas.getContext('2d');
        // Keep the full source frame (mirrored if preview is mirrored); the print pipeline center-crops per layout slot.
        if (this.mirrored) {
            context.translate(canvas.width, 0);
            context.scale(-1, 1);
        }
        context.drawImage(this.video, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.95));
        if (!blob || blob.type !== 'image/jpeg') throw new Error('Trình duyệt không xuất được ảnh JPEG. Hãy thử chụp lại.');
        if (blob.size > 5 * 1024 * 1024) throw new Error('Ảnh camera vượt quá 5 MiB. Hãy chọn camera có độ phân giải thấp hơn.');
        return blob;
    }
}
