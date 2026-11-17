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
