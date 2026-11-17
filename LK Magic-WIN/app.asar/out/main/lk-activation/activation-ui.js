'use strict';
const api = window.lkActivation;
const $ = id => document.getElementById(id);
let hasMachine = false, busy = false;
function status(message, kind = '') { $('status').textContent = message; $('status').className = kind; }
function updateButtons() { $('activate').disabled = busy || !hasMachine || !$('key').value.trim(); $('copy').disabled = busy || !hasMachine; $('choose').disabled = busy; }
async function showState(retry = false) {
  busy = true;updateButtons();status('Đang đọc mã máy…');
  try {
    const result = await (retry ? api.retry() : api.state());
    hasMachine = result.ok && !!result.machineCode;
    $('machine').value = result.machineCode || '';
    $('retry').hidden = hasMachine;
    status(hasMachine ? 'Nhập key được cấp cho mã máy ở trên để xuất ảnh.' : result.message || 'Chưa đọc được mã máy.', hasMachine ? '' : 'error');
  } catch { status('Chưa đọc được mã máy. Vui lòng thử lại.', 'error'); $('retry').hidden = false; }
  finally { busy = false;updateButtons(); }
}
$('key').addEventListener('input', updateButtons);
$('retry').addEventListener('click', () => showState(true));
$('copy').addEventListener('click', async () => { try { const r = await api.copy(); status(r.ok ? 'Đã sao chép mã máy.' : r.message, r.ok ? 'success' : 'error'); } catch { status('Chưa sao chép được. Bạn có thể chọn và sao chép mã bằng Ctrl+C.', 'error'); } });
$('choose').addEventListener('click', async () => {
  try { const r = await api.choose();if(r.ok){$('key').value=r.token;status('Đã chọn file key. Bấm Kích Hoạt KEY.');updateButtons();}else if(!r.cancelled)status(r.message,'error'); }
  catch { status('Chưa mở được file key.', 'error'); }
});
$('activate').addEventListener('click', async () => {
  busy=true;updateButtons();status('Đang kiểm tra key…');
  try { const r=await api.activate($('key').value.trim());status(r.ok?'Đã kích hoạt vĩnh viễn. Bạn có thể xuất ảnh.':r.message,r.ok?'success':'error'); }
  catch { status('Chưa kích hoạt được. Vui lòng thử lại.', 'error'); }
  finally { busy=false;updateButtons(); }
});
$('support').addEventListener('click', () => api.support().catch(()=>status('Chưa mở được liên hệ. Zalo: 0985062345.','error')));
$('quit').addEventListener('click', () => api.quit());
showState();
