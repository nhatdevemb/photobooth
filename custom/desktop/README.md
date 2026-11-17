# Photobooth Windows với digiCamControl

Bản thử 0.1.0 chạy Operator Edition trong Electron, dùng PHP/Python/Caddy đóng gói sẵn. Camera thật giao tiếp với digiCamControl đã cài trên Windows. Máy in luôn dùng mock trong bản này.

## Cài và mở app

1. Chạy `dist/Photobooth-Setup-0.1.0-x64.exe` từ Explorer, cài cho tài khoản Windows hiện tại và mở shortcut **Photobooth Operator**. Không cần cài Electron, PHP, Python, Node, Canon SDK hoặc Visual Studio Build Tools để dùng app.
2. Lần đầu chọn **Ảnh mô phỏng để thử app**, chọn IP LAN của laptop và hai cổng khác nhau (mặc định operator 8080, guest 8081). Tạo Admin với mật khẩu ít nhất 12 ký tự.
3. Bấm **Khởi động Photobooth**, đăng nhập và chạy luồng Filter → Bố cục → Template → Chụp → Xem lại → QR. Lệnh in là mô phỏng, không ra giấy.
4. Dữ liệu lưu ở `%LOCALAPPDATA%\PhotoboothOperator\data`, tách khỏi thư mục cài. Nâng cấp bằng bộ cài mới giữ dữ liệu; gỡ app không tự xóa ảnh. Menu **Photobooth → Cấu hình / khởi động lại** quay về thiết lập; tài khoản đã tạo không bị đặt lại mật khẩu.

Bộ cài thử chưa ký chứng thư phát hành. Windows có thể hiện cảnh báo nhà phát hành chưa xác định. Chỉ dùng artifact do chính lần build này tạo và đối chiếu SHA-256 trong `dist/SHA256SUMS.txt`.

## Kết nối R10 khi có thiết bị

1. Cài digiCamControl stable 2.1.7 từ [trang dự án](https://www.digicamcontrol.com/download). Dùng ứng dụng chính, không dùng gói Virtual Webcam thay thế.
2. Bật R10 ở chế độ ảnh tĩnh, chọn JPEG và AF, nối cáp USB truyền dữ liệu. Trong USB app selection chọn **Photo Import/Remote Control**. Đóng EOS Utility nếu đang giữ camera.
3. Trong digiCamControl tạo/chọn session dành riêng cho Photobooth; tắt auto-export/resize/chuyển đổi ảnh. Bật **Webserver**, **Allow interaction**, tắt **Allow public access**; cổng mặc định 5513.
4. Trong app chọn **Canon R10 qua digiCamControl**. Khi chỉ có một camera có thể để trống serial; nếu có nhiều camera phải nhập đúng serial. App chỉ mở digiCamControl khi API chưa phản hồi và đường dẫn `CameraControl.exe` hợp lệ; không đóng instance đã mở sẵn.
5. Tại bước chụp, bấm **Bật live view R10** rồi thử một ảnh và retake. Không bấm chụp trên DCC/camera trong lúc app đang chạy một job.

Nếu timeout/mất USB, kiểm tra tình trạng thiết bị và tạo thao tác chụp mới. App không tự gửi lại màn trập khi không biết lệnh trước đã chụp hay chưa; retake lỗi giữ ảnh cũ. JPEG trong DATA_DIR được giữ nguyên byte trước khi áp Beauty/LUT và ghép template.

R10 thật, tốc độ live view và autofocus vẫn cần nghiệm thu trên thiết bị. Việc bộ kiểm thử DCC giả lập đạt không xác nhận các khả năng này trên camera thật.

## Wi-Fi và cổng mạng

- Operator chỉ bind loopback `127.0.0.1`; không mở cổng này cho khách. Cổng guest mặc định 8081 chỉ phục vụ trang QR/tải ảnh cuối.
- Chọn đúng IP LAN trong thiết lập. Điện thoại phải cùng Wi-Fi, không bật client isolation. Nếu firewall chặn, cho phép `caddy.exe` nhận TCP ở cổng guest trên **Private network** bằng Windows Defender Firewall.
- DCC API không có cơ chế auth tương đương API operator. Chặn inbound cổng 5513 từ các dải LAN/IPv6 LAN bằng firewall. **Tắt Allow public access không đồng nghĩa chặn LAN.** Giữ loopback cho app và thử từ điện thoại để xác nhận cổng DCC không truy cập được.
- Khi đổi IP/cổng guest, QR đã phát hành chứa địa chỉ cũ. Giữ IP cố định trong sự kiện; không đổi cấu hình giữa phiên.

## Build từ source

Cần Windows x64, Node >=22.12 và Git. Windows 10 22H2 đã có `tar.exe` dùng để giải nén runtime. Các lệnh chạy từ thư mục `custom/desktop`:

```text
npm ci --ignore-scripts
npm run prepare:runtime
node scripts/fetch-electron.mjs
npm test
npm run dist
```

`prepare:runtime` tải PHP/Python/Caddy và QR theo `runtime-lock.json`, kiểm checksum, cài wheel theo `requirements-windows.lock`, cài Composer dependencies. Runtime/cache không được commit. `dist` chỉ stage tài nguyên ứng dụng và runtime, không copy `.env`, tài khoản, SQLite hoặc ảnh sự kiện. Python/QR/Composer/Caddy/Electron giữ license đi kèm.

Build wrapper gọi npm bằng Node + argv array vì shell/PowerShell trên một số máy Windows có lỗi CET hoặc quoting; không cần sửa shell toàn hệ thống. Nếu `npm ci` bỏ qua install scripts, lệnh `fetch-electron.mjs` tải Electron và đối chiếu checksum do package Electron cung cấp.

## Kiểm thử và chẩn đoán

- `npm test`: kiểm tra cấu hình và HTTP E2E với FastCGI/worker/pipeline thật, DCC giả lập, đủ bốn layout, retake, chống gửi lệnh trùng, QR được giải mã và guest isolation. Có thư mục dữ liệu tiếng Việt.
- `npm run test:soak`: 30 phiên qua DCC HTTP giả lập, một retake mỗi phiên, render/finalize/QR/đóng phiên; máy in thật không tham gia.
- `npm run test:electron`: mở app từ source bằng Playwright; `npm run test:packaged`: thử executable trong `dist/win-unpacked`. Hai bài này có thể hiện cửa sổ ngắn để chụp screenshot và dùng DATA_DIR tạm.
- Chạy PHPUnit/Pytest bằng PHP/Python trong `runtime/`; đặt `PYTHON_BIN` là đường dẫn tuyệt đối tới Python đóng gói. Xem kết quả cụ thể ở [báo cáo Windows](../../plan/windows-test-report.md).
- Maintenance chạy tự động sau năm phút và mỗi giờ; incoming DCC cũ được dọn theo retention khi camera không bị khóa, giữ nguyên ảnh đã publish và biên nhận chống chụp trùng.
- Log vận hành: `data/logs/app.jsonl` và `data/logs/desktop-runtime.log`. Không gửi `desktop-secret.json`, mật khẩu hoặc ảnh khách khi chia sẻ log.
- Nếu chạy trong sandbox Codex báo Electron không đọc được install directory, thử bản cài bằng Explorer trong tài khoản Windows bình thường. Giữ sandbox Electron bật; không dùng `--no-sandbox` để phát hành. Lỗi quyền của môi trường chạy thử không phải thiếu Electron.

Chưa nghiệm thu Windows 11 trên máy sạch, firewall LAN thực tế, R10 vật lý, in thật hoặc ca chạy bốn giờ. Bộ cài hiện là bản thử để kiểm chứng phần mềm trước khi vận hành sự kiện.
