# Báo cáo kiểm thử Windows — bản thử 0.1.0

Ngày: 15/11/2026. Máy kiểm thử: Windows 10 22H2 x64 (19045.2965). Canon EOS R10 chưa kết nối, theo lựa chọn của người dùng; toàn bộ kiểm thử camera dưới đây dùng HTTP giả lập digiCamControl. Máy in dùng mock.

## Kết quả phần mềm

| Kiểm tra | Kết quả |
| --- | --- |
| PHPUnit với PHP 8.3.36 đóng gói | 29 bài đạt, 484 assertions, không cảnh báo deprecation |
| Pytest với Python 3.12.10 đóng gói | 45 bài đạt; xử lý ảnh, Beauty, LUT, template và metadata |
| Node settings và HTTP E2E | 3 bài đạt; Caddy/FastCGI, worker và pipeline thật; bốn layout, retake, CSRF/auth, guest isolation và thư mục dữ liệu tiếng Việt |
| 30 phiên qua DCC HTTP giả lập | Đạt trong khoảng 140 giây; 111 lệnh chụp, mỗi phiên một retake; render, duyệt, giải mã QR và tải ảnh đúng SHA-256; không phát lệnh chụp trùng khi replay |
| Executable đã đóng gói | Playwright: 1 bài đạt trên executable trong dist/win-unpacked; setup tạo Admin, mở Operator mock bằng runtime đóng gói, không lưu mật khẩu vào settings và đóng app |
| NSIS offline x64 | Build thành công; runtime PHP/Python/Caddy và Electron nằm trong gói, dữ liệu sự kiện không nằm trong artifact |

QR được giải mã độc lập bằng jsQR, không chỉ kiểm tra ảnh PNG tồn tại. Bộ kiểm thử PHP kiểm tra timeout sau trigger không tự chụp lại, JPEG giữ nguyên byte, ảnh ngoài luồng bị từ chối và maintenance không xóa incoming khi capture đang giữ khóa. Regression bao gồm backup/restore, kiểm tra hash và phục hồi trạng thái in.

Bản đóng gói dùng Electron sandbox và context isolation. Trong sandbox Codex, Electron từng báo không đọc được install directory và hiện lỗi breakpoint; kiểm thử GUI ngoài sandbox Codex mở được app. Không tắt sandbox Electron. Đây là kết quả trên máy hiện tại, chưa thay thế kiểm tra máy sạch.

## Những việc chưa nghiệm thu

- R10 thật: nhận diện, autofocus, JPEG gốc, live view/FPS, rút USB, ảnh đến muộn và ít nhất 100 lần chụp trên thiết bị.
- Chạy bộ cài trên Windows 10/11 sạch không có runtime phát triển; nâng cấp và gỡ cài thực tế. Hiện đã thử executable trong gói, chưa thực thi wizard NSIS.
- Điện thoại cùng Wi-Fi, firewall guest/DCC và tải QR qua LAN thực tế.
- Ca chạy bốn giờ và đo xu hướng RAM. Bài 30 phiên ở trên chạy nhanh, không được tính là soak bốn giờ.
- Máy in vật lý và regression trên Linux.

## Artifact và cách dùng

Bộ cài: `custom/desktop/dist/Photobooth-Setup-0.1.0-x64.exe`. Checksum: `custom/desktop/dist/SHA256SUMS.txt`. Bản thử chưa ký chứng thư nhà phát hành. Xem [hướng dẫn Windows](../custom/desktop/README.md) và [kế hoạch/nghiệm thu R10](windows-app.md).

Người vận hành không cần tự cài Electron, PHP, Python, Node hoặc Canon SDK. digiCamControl là prerequisite riêng khi dùng R10; chọn **Ảnh mô phỏng để thử app** khi chưa có máy ảnh kết nối.


## Cập nhật 17/11/2026 — bản 0.1.1

Luồng mới: **Bố cục → Chụp ảnh → Filter/Beauty → Template → Duyệt/QR**. Live view tự bật khi vào bàn chụp, giữ qua các bước và tiếp tục lấy frame trong đếm ngược. Khi đã chụp, bố cục cố định; filter/Beauty/template được đổi sau khi đủ ảnh. Đổi kiểu trang trí làm mất hiệu lực bản render cũ, không thay đổi JPEG gốc hoặc ảnh cuối lịch sử. Migration 005 chuyển bước đang lưu của phiên cũ sang luồng mới.

- PHPUnit: **31 bài đạt, 506 assertions** (PHP/Python đóng gói; engine MediaPipe để bài hồi quy không phụ thuộc app LK Magic bên ngoài).
- Playwright/Electron với DCC HTTP giả lập: kiểm tra có frame mới thực sự hiển thị tại từng nhịp 3, 2, 1; chụp một ảnh, chọn filter/template sau chụp, reload giữ bước, render và QR.
- **Canon EOS R10 thật qua digiCamControl**: probe nhận 10 JPEG live view có 10 SHA-256 khác nhau; bài giao diện hoàn tất với 351 phản hồi frame thành công và một yêu cầu chụp, nhận một ảnh rồi chọn filter/template, render, duyệt và QR. Live view vẫn hiển thị ở mỗi nhịp đếm và sau khi chụp. Đã khôi phục session.folder/filenametemplate trước kiểm thử và để live view bật.
- Phát hiện DCC thật có header HTTP trùng Content-Length khiến Node fetch từ chối; kiểm tra DCC đang chạy dùng PHP/cURL đóng gói giống adapter, tránh mở thêm instance do hiểu nhầm API không chạy. Chuẩn hóa dấu phân cách trong đường dẫn DCC trả về trước khi so khớp incoming.

Đây là kiểm thử chức năng một lần trên R10 thật. Vẫn chưa hoàn tất nghiệm thu 100 lần chụp, FPS 10 phút, mất USB, ca bốn giờ, máy sạch Windows 11 và firewall LAN thực tế. Máy ảnh có thể tạm ngừng cấp frame tại thời điểm màn trập/transfer; app tiếp tục sau khi khóa capture được giải phóng và yêu cầu DCC bật lại live view.

Bản executable 0.1.1 đóng gói cũng đạt cùng bài giao diện với DCC giả lập (227 frame, một yêu cầu chụp), kiểm tra nút chuyển bước vừa màn 1366 × 768 và không tràn ngang ở 390 × 844/1920 × 1080.

Bộ cài cập nhật: `custom/desktop/dist/Photobooth-Setup-0.1.1-x64.exe`; checksum ở `dist/SHA256SUMS.txt`.


## Cập nhật 18/11/2026 — đóng phiên và tạo phiên tiếp theo

Đã chạy `node scripts/test-packaged-session-cycle.mjs` trên executable 0.1.1 trong `dist/win-unpacked`. Kết quả: **đạt**, khoảng 42 giây, 283 frame live view và đúng hai lệnh chụp cho hai phiên.

Cả hai phiên đều đi qua chọn bố cục → chụp → chọn filter/Beauty → template → render → duyệt → QR/tải ảnh → in mô phỏng COMPLETED → đóng phiên. Sau phiên đầu, kiểm tra phiên mới có ID khác, trạng thái NEW/bước 1, bố cục chưa khóa, không có ảnh/render/final/job/lệnh in cũ, filter mặc định Original và có thể chụp tiếp. Live view vẫn cập nhật giữa hai phiên; QR của phiên trước tiếp tục tải đúng SHA-256 sau khi đóng phiên và sau khi hoàn tất phiên sau. QR cũ không xuất hiện trước khi duyệt ảnh của phiên mới.

Bài dùng DCC HTTP giả lập, máy in mock và Beauty Off khi render. Chưa dùng bài này để kết luận chu trình hai phiên với R10 thật, LK Magic hoặc máy in thật đạt. Không thay đổi mã sản phẩm/bộ cài để đạt bài test; chỉ bổ sung bài hồi quy và tài liệu.
