# Bố cục, template và giao diện năm bước

## Vận hành và nâng cấp trên Ubuntu

Giữ PHP + SQLite + worker + Python hiện có. Chọn filter, bố cục, template trước khi chụp. Capture đầu tiên khóa cả ba lựa chọn trong cùng transaction enqueue; thất bại vẫn khóa. Retake/reopen không đổi cấu hình. Restart hủy hoặc đóng phiên cũ rồi tạo phiên mới trong một transaction; không cắt ngang capture/render và không xóa ảnh, final, QR hoặc lệnh in cũ.

Trước khi nâng cấp máy có dữ liệu: dừng nhận phiên mới, chờ capture/render hoàn tất, dừng worker/web, sao lưu bằng `php custom/bin/backup.php --target=/duong-dan/backup`, triển khai source và chạy `php custom/bin/migrate.php`, rồi khởi động lại dịch vụ. Không sửa migration 001. Migration 002 giữ ID, ảnh, hash, renders, finals, shares và prints; ánh xạ phiên cũ vào `postcard-three@1.0.0`. Phiên có ảnh hoặc capture job cũ được khóa. Không rollback source cũ trên database đã có slot 4; muốn rollback phải khôi phục trọn backup với source tương ứng.

Backup gồm SQLite, `sessions/`, `prints/receipts/` và `templates/` (bỏ staging `.import-*`). Metadata active/draft/disabled nằm trong database. Bundled artwork đi theo bản source triển khai; giữ đúng bản source cùng backup. Restore vẫn không tự gửi lại lệnh in dở dang.

## Thư viện bố cục v1.0.0

Tất cả dùng canvas 1181 × 1748 px, sRGB, 300 DPI, khổ danh định 100 × 148 mm. `x/y/width/height` là pixel. Không kéo giãn ảnh; center-crop theo tỷ lệ từng ô.

| ID | Số ảnh | Các ô (x, y, width, height) |
| --- | ---: | --- |
| single | 1 | (35, 35, 1111, 1678) |
| strip-three | 3 | (319, 35/602/1169, 543, 543) |
| postcard-three | 3 | (35, 35, 1111, 543), (35, 602, 1111, 543), (35, 1169, 1111, 544) |
| grid-four | 4 | x=35/602, y=35/886; widths=543/544, heights=827 |

Định nghĩa tại `custom/layouts/*.json`. Template chỉ chọn layout/version có sẵn, không khai báo lại hình học. 16 mẫu bundled nằm trong `custom/templates/bundled/`: minimal (Tối giản), party, floral (Hoa), cute × bốn bố cục. ID minimal của postcard giữ `vertical-three-v1` để tương thích phiên cũ.

## Định dạng gói ZIP

Một ZIP tương ứng một layout và một phiên bản. Đặt file trực tiếp ở gốc ZIP, không bọc thêm thư mục:

```text
template.json
overlay.png
preview.jpg
background.png     # tùy chọn
```

Ví dụ `template.json`:

```json
{
    "id": "spring-single",
    "name": "Mùa xuân",
    "version": "1.0.0",
    "style": "spring",
    "layout_id": "single",
    "layout_version": "1.0.0",
    "overlay": "overlay.png",
    "preview": "preview.jpg",
    "background": "background.png"
}
```

- ID/style: chữ thường ASCII, số hoặc dấu `-`, tối đa 64 ký tự, bắt đầu bằng chữ/số. Version gồm ba số, mỗi số 1–3 chữ số, ví dụ `1.0.0`. Tên hiển thị tối đa 100 ký tự.
- `style` là tùy chọn, mặc định bằng ID. Dùng cùng style cho các biến thể khác layout để hệ thống giữ phong cách khi đổi bố cục.
- `overlay.png`: PNG RGBA thực, đúng 1181 × 1748 px. Vùng ảnh cần alpha trong suốt. Artwork có thể đè lên mép ảnh, nên chừa khuôn mặt và vùng ảnh quan trọng.
- `background.png`: PNG đúng kích thước canvas; bỏ cả file lẫn trường background nếu không dùng. Mặc định nền trắng.
- `preview.jpg`: JPEG hợp lệ, cạnh dài tối đa 1000 px. Nên sinh bằng cùng pipeline với ảnh mẫu để thumbnail phản ánh khung thật. Admin còn xem bản ghép canvas từ chính background/overlay và layout trước khi kích hoạt.
- Không dùng URL ngoài, SVG, font/script/executable hoặc đường dẫn con. Server chỉ chấp nhận bốn tên file trên, từ chối symlink, entry trùng tên, traversal, sai định dạng/kích thước, JSON lỗi và version đã tồn tại. Giới hạn ZIP 30 MiB, giải nén 60 MiB, tối đa 20 entry; JSON tối đa 64 KiB.

Tạo gói trong thư mục chứa bốn file:

```bash
zip spring-single-1.0.0.zip template.json overlay.png preview.jpg background.png
```

Đổi artwork phải tăng version, không sửa trực tiếp file đã nhập. Gói nằm ngoài webroot tại `DATA_DIR/templates/{id}/{version}`; server tính SHA-256 tài nguyên khi nhập và kiểm tra lại khi dùng. Phiên ghim ID/version. Mẫu disabled không có trong catalog lựa chọn mới nhưng còn phục vụ phiên đã ghim; không xóa thư mục mẫu đang được tham chiếu.

## Admin và API

Đăng nhập admin → Quản lý template → chọn ZIP → Nhập nháp → Xem thử / kích hoạt → xem bản ghép → Kích hoạt. Tắt một version bằng nút Tắt. Operator không được import, thay trạng thái hoặc tải tài nguyên draft. Tài nguyên chỉ đi qua API có xác thực trên operator listener; guest không truy cập catalog.

API dùng cookie đăng nhập; mutations cần CSRF, Origin cùng site và UUID `Idempotency-Key` như các thao tác hiện có. Danh sách route và payload tại `custom/openapi.yaml`:

- GET `/custom/api/layouts`, GET `/custom/api/templates?layout_id=single`.
- POST `/custom/api/sessions/{id}/configuration`: revision + các lựa chọn tùy chọn + ui_step 1–4. Trả snapshot đầy đủ, `template_fallback` khi phải đổi phong cách. `CONFIGURATION_LOCKED` hoặc `SESSION_BUSY` trả 409.
- POST `/custom/api/sessions/{id}/restart`: revision + confirm=true. Replay trả cùng phiên mới.
- GET `/custom/api/admin/templates`, POST `/custom/api/admin/templates/import` (body là bytes ZIP, Content-Type application/zip), POST `/custom/api/admin/templates/{id}/{version}/activate` hoặc `/disable`.
- GET `/custom/api/catalog/templates/{id}/{version}/{overlay.png|background.png|preview.jpg}`; draft chỉ admin, disabled vẫn cho operator đã đăng nhập. GET `/custom/api/catalog/layouts/{id}/{version}/preview.jpg`.

Render dùng ảnh nguồn, LUT và snapshot layout/template đã ghim: nền → ảnh áp LUT → overlay → JPEG sRGB. Chữ/logo/trang trí không qua LUT. Signature bao gồm layout và hash artwork. Finalize chỉ nhận render hiện hành đúng cấu hình và ảnh nguồn; UI chỉ gửi finalize sau khi đúng preview tải thành công. Chép nguyên byte sang final, không re-encode.

## Tài nguyên và tái tạo

`custom/python/generate_template_catalog.py` tạo artwork hình học bằng Pillow và thumbnail bằng pipeline in. Chỉ chạy lại trước khi phát hành hoặc tăng version khi thay đổi gói đã phát hành. Không sửa bundled gói trên máy đang vận hành vì sẽ phá tính bất biến của phiên đã ghim.

Ảnh mẫu cục bộ `custom/public/assets/samples/source-portrait.jpg` được tạo bằng built-in imagegen; các ảnh filter được sinh từ cùng ảnh này qua LUT thật. Prompt: “Natural studio photobooth portrait of two fictional Vietnamese young adult friends, waist up, faces close in center, plain lavender backdrop, cream and pale blue clothing, neutral soft daylight, natural skin texture; generous crop margins, no text/logos/watermark.” Đây là ảnh mẫu AI, không phải ảnh khách. Mock capture dùng bộ ảnh kiểm thử slot 1–4 riêng.

Giao diện đóng gói font DejaVu Sans (BoothSans) và giấy phép trong `custom/public/assets/fonts/`, không phụ thuộc CDN. Mock có banner và khung ngắm ghi rõ mô phỏng. Live view thiết bị thật, phần cứng Canon, nhiều bản in, tải ảnh gốc, layout khác và designer kéo thả nằm ngoài đợt này.
