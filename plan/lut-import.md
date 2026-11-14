# Import LUT qua web

## Sử dụng

Đăng nhập Admin → **Quản lý filter** → chọn 1–30 file `.cube` → **Nhập các file đã chọn**. Giao diện gửi từng file riêng, báo kết quả từng dòng; file lỗi hoặc trùng không làm mất các file thành công. Phiên đang chụp và catalog lựa chọn chưa bị thay đổi khi mới nhập nháp.

Chọn **Xem thử / chỉnh nháp**, đặt tên và chỉnh cường độ 0–100%. Mẫu mới mặc định 80%; bốn filter đi kèm giữ 100%. Giao diện tự lưu và dựng lại ảnh mẫu khi đổi tên/cường độ; nút lưu cho phép thử lại nếu tải ảnh lỗi. Chỉ kích hoạt khi ảnh mẫu đúng cấu hình mới nhất đã tải thành công. Các thay đổi liên tục được gửi tuần tự và preview cũ không mở khóa nút kích hoạt.

Sau khi kích hoạt, filter xuất hiện ở bước Filter; tìm bằng tên và chọn như các filter có sẵn. Filter, phiên bản, hash và cường độ được lưu trong cấu hình phiên và khóa với các lựa chọn khác khi nhận capture đầu tiên, kể cả capture thất bại. Retake/reopen/reload không đổi cấu hình này.

**Tắt filter** chỉ bỏ khỏi lựa chọn mới. Phiên đã chọn bản đó tiếp tục dùng được. Muốn đổi một filter đã xuất bản, chọn **Tạo phiên bản nháp**, chỉnh cường độ hoặc chọn file thay thế, xem thử rồi kích hoạt. Phiên bản mới tăng patch tự động: 1.0.0 → 1.0.1. Mỗi filter chỉ có một bản active và một draft đang chỉnh; kích hoạt bản mới tắt bản active trước đó. Có thể xem thử và kích hoạt lại bản disabled. File/metadata đã xuất bản không sửa trực tiếp. Bốn filter đi kèm được giữ nguyên, không tắt hoặc sửa trong mục quản trị.

Ảnh trước/sau là ảnh mẫu AI đóng gói cục bộ. LUT được áp như creative filter lên ảnh RGB đã chuẩn hóa sRGB trong pipeline hiện tại; tính năng này không chuyển Log hoặc cam kết khớp cấu hình màu của phần mềm video. Cần duyệt màu trên ảnh thực tế của photobooth trước khi vận hành.

## File được hỗ trợ

- CUBE 3D dạng văn bản UTF-8 (chấp nhận BOM), tối đa 5 MiB/file.
- `LUT_3D_SIZE 33`, 35.937 dòng dữ liệu, thứ tự Red thay đổi nhanh nhất, domain mặc định hoặc khai báo 0–1.
- Các giá trị phải hữu hạn, trong giới hạn parser 0–1; không NaN/Infinity, thiếu/thừa dòng hoặc header domain trùng.
- Tên file không chứa đường dẫn, ký tự điều khiển hoặc phần mở rộng thực thi. Tên hiển thị lấy TITLE, thiếu TITLE thì lấy tên file; Admin sửa được, tối đa 100 ký tự.
- Không nhận ZIP, URL, LUT 1D hoặc size 13/17/65. Không hỗ trợ quy trình chuyển đổi Log trong đợt này. Đuôi `.cube` không tự xác nhận đúng màu đầu vào; parser chỉ kiểm tra cấu trúc và phạm vi dữ liệu.

Hai archive DAEDAL đã dùng kiểm thử thực tế gồm 12 LUT Wedding và 8 LUT Film & Mono. Tất cả đọc được bằng parser size 33/domain 0–1. File chỉ được tải vào vùng tạm phục vụ kiểm thử, không cài sẵn vào dữ liệu vận hành hoặc đóng gói lại trong source.

## Dữ liệu, render và backup

Migration `003_lut_catalog.sql` thêm catalog và các trường `lut_version`, `lut_intensity`, `lut_sha256` cho session. Phiên cũ ánh xạ preset đi kèm v1.0.0, cường độ 100%, giữ ID/ảnh/render/final/print/share hiện có. Các migration trước không đổi.

LUT nhập nằm ngoài webroot tại `DATA_DIR/luts/{id}/{version}`. File khởi tạo là `filter.cube`; thay file trong draft tạo tên theo SHA-256, không ghi đè byte cũ. Preview cũng mang tên theo hash để backup snapshot không bị hỏng khi draft đang cập nhật. Staging `.import-*` không nằm trong backup. Metadata/trạng thái/revision nằm trong SQLite. Không xóa thủ công các version đã có phiên tham chiếu.

Ảnh được chuẩn hóa sRGB rồi áp LUT và blend theo cường độ trên RGB trước crop. Công thức: `original × (1 − amount/100) + graded × amount/100`. Sau đó ghép background/overlay và xuất JPEG sRGB 300 DPI. Preview Admin dùng cùng hàm grading với render in. Artwork không đi qua LUT. Snapshot job và input signature ghi version/hash/cường độ; finalize kiểm tra cấu hình đã ghim trước khi sao chép JPEG nguyên byte.

Backup/restore nay bao gồm `luts/` cùng database, ảnh và template. Giữ cùng phiên bản source cho các preset/artwork đi kèm. Trước nâng cấp máy có dữ liệu: ngừng nhận phiên, chờ content job hoàn tất, dừng dịch vụ, backup, triển khai source, chạy `php custom/bin/migrate.php`, rồi khởi động lại. Không rollback source cũ trực tiếp trên schema đã nâng cấp.

## API

Cookie đăng nhập, Origin cùng site và CSRF giữ nguyên. POST cần Idempotency-Key; replay trả response đã lưu, khác nội dung cùng key trả 409. Admin mutations có revision của bản LUT; revision của session dùng riêng.

| Route | Hành vi |
| --- | --- |
| GET `/custom/api/presets` | Bốn preset đi kèm và các bản nhập đang active, có name/version/intensity/sample_url; không trả đường dẫn private |
| GET `/custom/api/admin/luts` | Admin xem mọi version và trạng thái |
| POST `/custom/api/admin/luts/import` | multipart/form-data, trường file; tạo draft v1.0.0; file trùng SHA trả 409 LUT_ALREADY_IMPORTED |
| POST `/custom/api/admin/luts/{id}/{version}/draft` | JSON revision/name/intensity, hoặc multipart cùng các trường đó và file thay thế; chỉ chỉnh draft |
| POST `/custom/api/admin/luts/{id}/{version}/versions` | JSON revision; tạo draft kế tiếp từ bản đã xuất bản |
| POST `/custom/api/admin/luts/{id}/{version}/activate` | JSON revision + preview_sha256; xác nhận đúng tài nguyên preview hiện tại |
| POST `/custom/api/admin/luts/{id}/{version}/disable` | JSON revision; bỏ khỏi lựa chọn mới |
| GET `/custom/api/catalog/luts/{id}/{version}/preview.jpg?sha=...` | JPEG có xác thực/no-store; draft chỉ Admin, disabled vẫn đọc được; hash cũ trả 409 PREVIEW_STALE |

Configuration nhận thêm `lut_version`. Session snapshot trả `lut` (metadata/sample_url đã ghim), `lut_version`, `lut_intensity`, `lut_sha256`. Operator không tự đặt cường độ ngoài chính sách preset. Payload render không được thay version/hash/intensity. Chi tiết schema tại `custom/openapi.yaml`.

## Cấu hình và chạy kiểm thử

PHP cần pdo_sqlite, gd, mbstring và các extension hiện có; Python dùng requirements.lock. Apache deploy đã đặt upload_max_filesize=5M, post_max_size=32M (giữ đủ cho template ZIP 30 MiB). Khi chạy PHP development server:

```bash
PHOTOBOOTH_LISTENER_MODE=operator php -d upload_max_filesize=5M -d post_max_size=32M -S 127.0.0.1:8080 custom/public/index.php
```

Kiểm thử thường không tải LUT từ mạng và không cần thư viện bên ngoài:

```bash
bash custom/bin/ci-check.sh
(cd custom && npm run test:browser && npm run test:browser:unknown)
php custom/bin/soak-test.php
bash custom/bin/docker-smoke.sh
```

Để kiểm thử UI với 20 file DAEDAL đã tải/giải nén vào một thư mục riêng (20 `.cube` ở gốc thư mục), chạy thêm:

```bash
cd custom
PB_DAEDAL_LUT_DIR=/duong-dan/20-file-cube npm run test:browser
```

Ca này nhập, xem thử, kích hoạt và reload đủ 20 file trong DATA_DIR tạm của Playwright rồi dọn dữ liệu khi kết thúc. Screenshot nằm trong test-results. Không bật ca có dữ liệu DAEDAL mặc định trong CI để tránh phụ thuộc mạng hoặc phân phối lại gói bên ngoài. Kết quả thực tế và giới hạn Docker/phần cứng ghi trong `plan/test-report.md`.
