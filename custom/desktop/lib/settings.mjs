import { isIP } from 'node:net';

export function validateSettings(input) {
    const port = (value) => {
        const number = Number(value);
        if (!Number.isInteger(number) || number < 1024 || number > 65535) throw new Error('Cổng phải từ 1024 đến 65535.');
        return number;
    };
    const lanIp = String(input.lanIp || '').trim();
    if (isIP(lanIp) !== 4 || !(lanIp.startsWith('10.') || lanIp.startsWith('192.168.') || /^172\.(1[6-9]|2\d|3[01])\./.test(lanIp))) throw new Error('Chọn địa chỉ IPv4 LAN nội bộ của laptop.');
    const operatorPort = port(input.operatorPort || 8080);
    const guestPort = port(input.guestPort || 8081);
    const dccPort = port(input.dccPort || 5513);
    if (new Set([operatorPort, guestPort, dccPort]).size !== 3) throw new Error('Cổng operator, guest và digiCamControl phải khác nhau.');
    const cameraMode = input.cameraMode === 'mock' ? 'mock' : 'digicamcontrol';
    const cameraId = String(input.cameraId || '').trim();
    if (cameraId && !/^[A-Za-z0-9_-]{1,100}$/.test(cameraId)) throw new Error('Serial máy ảnh không hợp lệ.');
    const username = String(input.username || '').trim();
    const password = String(input.password || '');
    if (username && !/^[A-Za-z0-9_.-]{3,64}$/.test(username)) throw new Error('Tên Admin dùng 3–64 ký tự chữ/số, dấu chấm, gạch dưới hoặc gạch ngang.');
    if (password && password.length < 12) throw new Error('Mật khẩu Admin cần ít nhất 12 ký tự.');
    return { lanIp, operatorPort, guestPort, dccPort, cameraMode, cameraId, dccExecutable: String(input.dccExecutable || ''), lkMagicExecutable: String(input.lkMagicExecutable || '').trim().replace(/^"|"$/g, ''), username, password };
}

export function publicSettings(settings) {
    const { username, password, ...safe } = settings;
    return safe;
}
