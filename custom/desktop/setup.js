const form = document.getElementById('setup');
const status = document.getElementById('status');
window.boothSetup.info().then(({ saved, ips, dccExecutable, lkMagicExecutable, data }) => {
    document.getElementById('ips').replaceChildren(...ips.map((ip) => Object.assign(document.createElement('option'), { value: ip })));
    for (const [key, value] of Object.entries({ lanIp: ips[0] || '', dccExecutable, lkMagicExecutable, ...saved })) if (form.elements.namedItem(key)) form.elements.namedItem(key).value = value;
    status.textContent = `Dữ liệu lưu tại ${data}`;
});
window.boothSetup.onStatus((text) => { status.textContent = text; });
form.addEventListener('submit', async (event) => {
    event.preventDefault();
    document.getElementById('launch').disabled = true;
    status.textContent = 'Đang kiểm tra và khởi động…';
    try {
        const result = await window.boothSetup.launch(Object.fromEntries(new FormData(form)));
        if (result.error) status.textContent = result.error;
        form.elements.password.value = '';
    } catch (error) { status.textContent = error.message; }
    finally { document.getElementById('launch').disabled = false; }
});
