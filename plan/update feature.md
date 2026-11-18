# Đề Xuất Tính Năng Triển Khai Thực Tế — Photobooth Operator Edition

Tài liệu lưu lại các tính năng mở rộng cho triển khai sự kiện thực tế (gồm hạng mục **5**, **8**, **Nhập tên lên Template Frame theo 3 chủ đề**, và **Giới hạn số lượng ảnh & Đếm ảnh đã in tại màn in ảnh**), đối chiếu trực tiếp với kiến trúc hiện có trong `custom/`.

---

## Nguyên tắc kiến trúc cốt lõi: Cấu hình trước phiên — Khóa trong phiên

Toàn bộ các thiết lập sự kiện, chủ đề, nội dung chữ trên frame, giới hạn số lượng ảnh và branding được gom vào một **Màn hình Cài đặt (`Setting`)** (truy cập trước khi mở phiên chụp, phân quyền `admin`).

- **Trước khi vào phiên (`Pre-Session Setting`)**:
  - Người vận hành/Admin thiết lập một lần cho cả buổi sự kiện: chủ đề (`Đám cưới` / `Sinh nhật` / `Khác`), tên/chữ điền sẵn lên frame, giới hạn tổng số lượng ảnh được in, branding trang QR, bật/tắt tải ảnh lẻ & GIF.
  - Có nút **Lưu cấu hình** và **Xem trước mẫu** ngay trong màn hình Setting; chỉ cho phép thay đổi khi không có phiên chụp đang hoạt động (hoặc áp dụng cho các phiên tạo mới sau đó).
- **Trong phiên chụp (`Session Runtime — Read-Only Config`)**:
  - Khi gọi `POST /custom/api/sessions`, hệ thống **chụp snapshot toàn bộ cấu hình hiện hành từ Setting** vào phiên mới (`sessions`).
  - Trong suốt phiên chụp **không hiển thị và không cho phép cấu hình lại** tên trên frame, giới hạn số lượng ảnh, branding hay chính sách tải GIF/ảnh lẻ — giúp hàng chờ chạy nhanh, ổn định và tránh thao tác nhầm.

---

## Kiến trúc Màn hình Cài đặt (`Setting` — Bổ sung mới)

### Hiện trạng trong mã nguồn
- Thanh điều hướng [`custom/public/index.php`](../custom/public/index.php#L218-L226) hiện mới có: `Bàn chụp`, `Lịch sử`, `Quản lý template`, `Quản lý filter`, `Trạng thái hệ thống`, `Đăng xuất`.
- Màn hình [`custom/desktop/setup.html`](../custom/desktop/setup.html) chỉ cấu hình phần cứng/mạng khi khởi động (camera, IP LAN, port, DCC exe, tài khoản Admin).
- Chưa có màn hình **Cài đặt sự kiện (`#settings-view`)** trong ứng dụng web.

### Thiết kế bổ sung
- Thêm nút **Cài đặt (`#btn-nav-settings`)** trên `#auth-user-bar` (chỉ hiện với role `admin`, khóa sửa khi `active_session` đang mở hoặc cảnh báo chỉ áp dụng từ phiên kế tiếp).
- Lưu cấu hình tập trung trong SQLite (bảng `event_settings` + `print_quota`), trả qua API `GET /custom/api/admin/settings` và `POST /custom/api/admin/settings`.

---

## Tính năng 5: Đổi thứ tự ảnh giữa các ô & Tải ảnh lẻ / GIF qua QR

### Mục tiêu
Tăng trải nghiệm ảnh đầu ra cho khách tại sự kiện: cho phép sắp xếp lại ảnh vừa chụp vào các ô bố cục (nếu được bật) và cung cấp thêm ảnh đơn/GIF qua QR.

### Cấu hình tại màn hình Setting (trước phiên)
- Bật/tắt: **Cho phép đổi vị trí ảnh giữa các ô sau khi chụp**.
- Bật/tắt: **Kèm ảnh lẻ đã chỉnh màu/beauty trên trang QR** (`allow_single_download`).
- Bật/tắt: **Tự động tạo GIF / Boomerang trên trang QR** (`allow_gif`, cấu hình tốc độ khung hình / kích thước mặc định `800–1080 px`).

### Hành vi trong phiên chụp
- Nhân viên/khách không cấu hình lại việc có tạo GIF hay kèm ảnh lẻ không — hệ thống thực hiện tự động theo snapshot của phiên khi render/finalize.
- Riêng thao tác kéo/hoán đổi vị trí giữa các tấm ảnh vừa chụp (`slot_order`, chỉ đổi thứ tự hiển thị ở Bước 3/4 nếu được bật trong Setting, không sửa file ảnh gốc trong `photos`).
- Trang `/share/{token}` hiển thị đúng các thành phần đã được bật trong Setting (ảnh ghép chính, danh sách ảnh lẻ, ảnh GIF).

### Điểm chạm kỹ thuật dự kiến
- Database migration mới: cột `slot_order` (JSON) trên `sessions`; bảng/cột lưu `share_assets` (ảnh lẻ, GIF) gắn với `render_id` / `final_asset_id`.
- Backend & Pipeline: [`custom/python/render_pipeline.py`](../custom/python/render_pipeline.py), [`custom/src/Session/SessionService.php`](../custom/src/Session/SessionService.php), [`custom/src/Jobs/WorkerRunner.php`](../custom/src/Jobs/WorkerRunner.php), [`custom/src/Share/ShareService.php`](../custom/src/Share/ShareService.php), [`custom/src/Http/HttpKernel.php`](../custom/src/Http/HttpKernel.php).

---

## Tính năng 8: Tùy biến Branding sự kiện, Phân loại Template & Thống kê / Export cuối buổi

### Mục tiêu
Cấu hình nhanh nhận diện sự kiện tại Setting trước khi chạy, thu gọn danh sách template theo chủ đề sự kiện, và tổng kết / bàn giao toàn bộ ảnh cuối ngày.

### Cấu hình tại màn hình Setting (trước phiên)
- **Branding trang QR & giao diện**:
  - Tên sự kiện / tiêu đề trang tải ảnh (`/share/{token}`).
  - Upload logo / banner nội bộ và chọn màu chủ đạo.
  - Thời hạn QR (`SHARE_TTL_HOURS`) và lời nhắn chân trang cho khách.
- **Khóa/lọc chủ đề Template cho sự kiện**:
  - Chọn chủ đề hoạt động cho sự kiện (`Tất cả` hoặc cố định 1 chủ đề: `Đám cưới`, `Sinh nhật`, `Khác`), giúp vào phiên chụp chỉ hiển thị đúng bộ khung phù hợp.

### Hành vi trong phiên chụp & Sau buổi
- Trong phiên chụp: chỉ hiển thị đúng bộ template thuộc chủ đề đã chọn ở Setting (không đổi branding hay chủ đề trong phiên); trang `/share/{token}` tự động render theo branding đã cấu hình.
- Ngoài phiên (tab **Lịch sử / Thống kê**):
  - Xem tổng kết theo ngày/sự kiện: số phiên (`FINALIZED`, `CLOSED`, `CANCELLED`), tổng ảnh chụp, tổng bản in (`COMPLETED`, `FAILED`, `UNKNOWN`, `reprint`).
  - Xuất báo cáo CSV và tạo gói ZIP toàn bộ ảnh cuối (`final_assets`, tùy chọn kèm ảnh gốc) để bàn giao sau sự kiện.

### Điểm chạm kỹ thuật dự kiến
- Database migration mới: bảng `event_settings` và index thống kê theo `created_at`.
- Backend & Frontend: `EventSettingsService`, [`custom/src/Share/ShareService.php`](../custom/src/Share/ShareService.php), [`custom/src/Catalog/TemplateCatalog.php`](../custom/src/Catalog/TemplateCatalog.php), [`custom/src/Http/HttpKernel.php`](../custom/src/Http/HttpKernel.php), [`custom/public/assets/js/operator.js`](../custom/public/assets/js/operator.js).

---

## Tính năng bổ sung 1: Nhập tên & chữ tùy chỉnh lên Template Frame theo 3 chủ đề

### Mục tiêu
Cho phép Admin nhập tên/lời chúc/ngày sự kiện **một lần tại màn hình Setting trước khi chụp** theo đúng chủ đề sự kiện (`Đám cưới`, `Sinh nhật`, `Khác`); khi vào phiên chụp, hệ thống tự động điền sẵn nội dung này vào mọi frame được chọn mà không cần gõ lại.

### Hiện trạng trong mã nguồn
- Bộ 26 mẫu bundled trong [`custom/templates/bundled/`](../custom/templates/bundled) gồm 5 mẫu `wedding-*`, 5 mẫu `birthday-*` và 16 mẫu khác (`minimal-*`, `cute-*`, `floral-*`, `party-*`, `vertical-three-v1`).
- Các mẫu cưới/sinh nhật trong [`custom/python/generate_event_templates.py`](../custom/python/generate_event_templates.py) hiện vẽ cứng chữ mẫu vào `overlay.png`.
- Whitelist trong [`TemplateCatalog.php`](../custom/src/Catalog/TemplateCatalog.php#L263) chưa có `category` và `text_slots`.
- Đã có sẵn font tiếng Việt đóng gói trong [`custom/public/assets/fonts/`](../custom/public/assets/fonts) (`BeVietnamPro-*`, `BoothSans-*`, `PlusJakartaSans-Variable.ttf`).

### Cấu hình tại màn hình Setting (trước phiên)
1. **Chọn chủ đề frame (`category`: `wedding` | `birthday` | `other`) và nhập nội dung chuẩn hóa**:
   - **Đám cưới (`wedding`)**:
     - `primary_text`: Tên Cô dâu & Chú rể (VD: `Minh & Lan`).
     - `secondary_text`: Ngày cưới / Lời đề từ (VD: `18 · 11 · 2026` hoặc `Save the Date`).
   - **Sinh nhật (`birthday`)**:
     - `primary_text`: Tên nhân vật chính (VD: `Bé Na` / `Minh Anh`).
     - `secondary_text`: Tuổi / Lời chúc ngắn / Ngày (VD: `Happy 25th Birthday`).
   - **Khác (`other`)**:
     - `primary_text`: Tên sự kiện / Nhóm / Thương hiệu (VD: `Year End Party 2026`).
     - `secondary_text`: Slogan / Ngày tháng (tùy chọn, bỏ trống thì không vẽ).
2. **Xem trước ngay trên Setting**:
   - Chọn mẫu đại diện thuộc chủ đề đó để xem trước bố cục chữ ngay tại màn hình Setting trước khi bấm lưu.

### Hành vi trong phiên chụp (không nhập/sửa lại)
- Phiên mới tự động nhận snapshot `template_texts` từ `event_settings` lúc khởi tạo phiên.
- Khi chọn bất kỳ template nào ở Bước 4 (có khai báo `text_slots`), cả `paintSample()` (HTML5 Canvas) lẫn [`render_pipeline.py`](../custom/python/render_pipeline.py) (Pillow `2x` + auto-fit `max_width` + Unicode tiếng Việt) đều tự động điền đúng nội dung đã cài vào đúng tọa độ, font, màu sắc của từng frame.
- Trong phiên chụp **không hiện form sửa text** (nếu không có text hoặc template chưa khai báo `text_slots` thì giữ nguyên artwork gốc).

### Điểm chạm kỹ thuật dự kiến
- Catalog & Template generator: thêm `category` và `text_slots` vào `template.json`, [`TemplateCatalog.php`](../custom/src/Catalog/TemplateCatalog.php), [`generate_event_templates.py`](../custom/python/generate_event_templates.py) (tạo vùng trống thay chữ trên `overlay.png`, giữ `default_text` làm fallback).
- Render & Session: lưu snapshot `template_texts` vào `sessions`, đưa vào `input_signature` của [`render_pipeline.py`](../custom/python/render_pipeline.py).

---

## Tính năng bổ sung 2: Giới hạn số lượng ảnh & Đếm ảnh đã in tại màn In ảnh

### Mục tiêu
Không chia gói phức tạp; chỉ cho phép nhập trực tiếp **giới hạn tổng số lượng ảnh** tại màn hình Setting trước khi vận hành, và hiển thị rõ ràng **đã in bao nhiêu ảnh** ngay trên màn hình In ảnh (Bước 5).

### Hiện trạng trong mã nguồn
- [`PrintService.php`](../custom/src/Printer/PrintService.php) hiện khóa cứng `copies = 1` mỗi lệnh in ([`openapi.yaml`](../custom/openapi.yaml#L749), [`operator.js`](../custom/public/assets/js/operator.js#L644)) và chỉ lưu danh sách lệnh in riêng của từng phiên (`#session-prints-list`), chưa có tổng giới hạn số lượng ảnh và chưa hiển thị bộ đếm tổng số ảnh đã in ở giao diện Bước 5 ([`custom/public/index.php`](../custom/public/index.php#L260-L261)).

### Cấu hình tại màn hình Setting (trước phiên)
1. **Nhập số lượng ảnh giới hạn (`print_limit`)**:
   - Nhập một con số tổng số ảnh được phép in (VD: `100`, `300`, `500`), hoặc tích chọn **Không giới hạn** (`null` / `0`).
   - Xem bộ đếm hiện tại ngay tại Setting (`Đã in: 84 / 300 ảnh`, còn lại `216 ảnh`), có nút nạp thêm số lượng hoặc đặt lại bộ đếm về `0` khi sang sự kiện mới (chỉ Admin).

### Hành vi trong phiên chụp & tại màn In ảnh (Bước 5)
- **Hiển thị trực tiếp trên màn hình In ảnh (`#review-panel` & `#modal-print-confirm`)**:
  - Hiện rõ bộ đếm theo thời gian thực: **`Đã in: 84 / 300 ảnh · Còn lại: 216 ảnh`** (hoặc `Đã in: 84 ảnh` nếu đang chọn không giới hạn).
- **Cho phép nhập số lượng bản in (`copies`) ngay tại trang In ảnh**:
  - Gỡ bỏ giới hạn cứng `copies = 1` ở [`PrintService.php`](../custom/src/Printer/PrintService.php#L84-L90), [`openapi.yaml`](../custom/openapi.yaml#L749) và UI Bước 5 (đặc biệt [`CupsPrintAdapter.php`](../custom/src/Printer/CupsPrintAdapter.php#L53-L54) đã truyền sẵn tham số `-n <copies>`).
  - Thêm ô nhập / nút `-` `+` số lượng bản in (VD: `1–10` bản, mặc định `1`) ngay ở khung **▣ In ảnh** và hiển thị lại trong modal xác nhận in (`Xác nhận in · N bản`).
- **Thực thi giới hạn & cảnh báo**:
  - Kiểm tra trước khi tạo lệnh in: nếu `printed_count + copies > print_limit` thì chặn (`PRINT_QUOTA_EXCEEDED`) và báo số bản tối đa còn có thể in.
  - Khi còn ≤ 10 ảnh (hoặc ≤ 10%) hiện cảnh báo màu vàng ngay tại khung **▣ In ảnh**.
  - Khi chạm ngưỡng (`printed_count >= print_limit`), tự khóa nút in, yêu cầu Admin vào Setting tăng số lượng nếu muốn in tiếp.
- **Quy tắc an toàn**: Chỉ cộng `+copies` vào `printed_count` khi lệnh in thực sự hoàn tất (`COMPLETED` hoặc Admin đối chiếu `UNKNOWN` thành `observed_completed`); lệnh in lỗi (`FAILED`) tuyệt đối không trừ lượt.

### Điểm chạm kỹ thuật dự kiến
- Database migration mới: lưu `print_limit` và `printed_count` (trong `event_settings` / `print_quota`).
- Backend & Contract: [`PrintService.php`](../custom/src/Printer/PrintService.php), [`MockPrintAdapter.php`](../custom/src/Printer/MockPrintAdapter.php), [`openapi.yaml`](../custom/openapi.yaml), [`SessionService.php`](../custom/src/Session/SessionService.php), [`HealthService.php`](../custom/src/Maintenance/HealthService.php), [`HttpKernel.php`](../custom/src/Http/HttpKernel.php).
- Frontend: màn hình Setting mới + ô nhập **Số lượng bản in (`copies`)** và badge bộ đếm **`Đã in`** trên card **▣ In ảnh** / modal xác nhận in trong [`custom/public/index.php`](../custom/public/index.php#L260-L273) và [`custom/public/assets/js/operator.js`](../custom/public/assets/js/operator.js).
