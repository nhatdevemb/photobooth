"""Exercise the built Apache image, not PHP's development server."""
import hashlib
import http.cookiejar
import json
import os
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

operator = os.environ['PB_OP_URL']
guest = os.environ['PB_GUEST_URL']
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
csrf = ''


def call(path, body=None):
    headers = {'Origin': operator}
    if body is not None:
        headers.update({'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'Idempotency-Key': str(uuid.uuid4())})
    request = urllib.request.Request(operator + path, data=None if body is None else json.dumps(body).encode(), headers=headers)
    with opener.open(request, timeout=15) as response:
        return json.load(response)


def wait_job(job_id):
    for _ in range(150):
        job = call('/custom/api/jobs/' + job_id)
        if job['status'] == 'SUCCEEDED':
            return
        assert job['status'] != 'FAILED', job
        time.sleep(0.1)
    raise AssertionError('Worker job timeout')


def upload_lut():
    from pathlib import Path
    boundary = 'pb-lut-' + uuid.uuid4().hex
    source = Path(__file__).resolve().parents[2] / 'presets/film-400.cube'
    content = b'# apache LUT smoke\n' + source.read_bytes()
    body = (f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="Smoke.cube"\r\nContent-Type: text/plain\r\n\r\n'.encode() + content + f'\r\n--{boundary}--\r\n'.encode())
    request = urllib.request.Request(operator + '/custom/api/admin/luts/import', data=body, headers={'Origin':operator,'X-CSRF-Token':csrf,'Idempotency-Key':str(uuid.uuid4()),'Content-Type':'multipart/form-data; boundary='+boundary})
    with opener.open(request,timeout=30) as response:
        draft = json.load(response)
    with opener.open(operator + draft['sample_url'],timeout=15) as response:
        assert response.headers.get_content_type() == 'image/jpeg'
    return call('/custom/api/admin/luts/' + draft['id'] + '/1.0.0/activate', {'revision':draft['revision'],'preview_sha256':draft['preview_sha256']})


csrf = call('/custom/api/auth/login', {'username': 'smokeadmin', 'password': 'SmokeOnlyPass123!'})['csrf_token']
lut = upload_lut()
session = call('/custom/api/sessions', {})
sid = session['id']
assert len(call('/custom/api/layouts')['items']) == 4
assert len(call('/custom/api/templates')['items']) == 16
session = call(f'/custom/api/sessions/{sid}/configuration', {'revision':session['revision'], 'layout_id':'grid-four', 'template_id':'cute-grid-four', 'lut_id':lut['id'], 'ui_step':4})
with opener.open(operator + session['template']['overlay_url'], timeout=15) as response:
    assert response.headers.get_content_type() == 'image/png'
for slot in range(1, session['capture_count'] + 1):
    result = call(f'/custom/api/sessions/{sid}/slots/{slot}/capture', {'revision': session['revision']})
    wait_job(result['job_id'])
    session = call('/custom/api/sessions/' + sid)
render = call(f'/custom/api/sessions/{sid}/render', {'revision': session['revision']})
wait_job(render['job_id'])
session = call('/custom/api/sessions/' + sid)
final = call(f'/custom/api/sessions/{sid}/finalize', {'revision': session['revision'], 'render_id': session['current_render']['id']})
share = call(f'/custom/api/sessions/{sid}/shares', {'revision': final['revision'], 'final_asset_id': final['final_asset_id']})
path = urllib.parse.urlparse(share['url']).path
with urllib.request.urlopen(guest + path + '/download', timeout=15) as response:
    assert hashlib.sha256(response.read()).hexdigest() == final['sha256']
for forbidden in ('/', '/custom/api/health', '/api/takePic.php', '/admin/', '/private/data',
                  '/assets/js/operator.js', '/assets/mock-preview/frame-1.jpg', '/index.php', '/guest.php'):
    try:
        urllib.request.urlopen(guest + forbidden, timeout=15)
        raise AssertionError('Guest accepted ' + forbidden)
    except urllib.error.HTTPError as error:
        assert error.code == 403, (forbidden, error.code)
pr = call(f'/custom/api/sessions/{sid}/prints', {'revision': share['revision'], 'final_asset_id': final['final_asset_id'], 'copies': 1, 'action': 'print'})
for _ in range(150):
    status = call('/custom/api/prints/' + pr['print_request_id'])['status']
    if status == 'COMPLETED':
        break
    assert status not in ('FAILED', 'UNKNOWN'), status
    time.sleep(0.1)
else:
    raise AssertionError('Mock print timeout')
health = call('/custom/api/health')
assert health['worker']['status'] == 'healthy', health
print('Apache smoke PASS: LUT multipart import/preview/activate, catalog/templates, 4-slot render/finalize/print, guest hash and isolation')
