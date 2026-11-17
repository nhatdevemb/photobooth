import test from 'node:test';
import assert from 'node:assert/strict';
import { validateSettings, publicSettings } from '../lib/settings.mjs';

test('private LAN and distinct ports are required', () => {
    for (const lanIp of ['127.0.0.1', '0.0.0.0', '8.8.8.8', '192.168.1.999', 'evil.example']) assert.throws(() => validateSettings({ lanIp }));
    assert.throws(() => validateSettings({ lanIp: '192.168.1.10', guestPort: 8080 }));
    assert.equal(validateSettings({ lanIp: '172.16.1.2' }).cameraMode, 'digicamcontrol');
});

test('settings never persist credentials', () => {
    const settings = validateSettings({ lanIp: '10.0.0.2', username: 'admin', password: 'PrivatePassword123!' });
    assert.equal(publicSettings(settings).password, undefined);
    assert.equal(publicSettings(settings).username, undefined);
    assert.equal(publicSettings(settings).lanIp, '10.0.0.2');
    assert.throws(() => validateSettings({ lanIp: '10.0.0.2', password: 'short' }));
});
