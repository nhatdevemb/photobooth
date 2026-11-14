# Danh Mục Kiểm Tra Tính Năng (Feature Checklist) — Photobooth v4.5.1 vs. Operator Edition

Tài liệu này tổng hợp toàn bộ các tính năng hiện có trong mã nguồn dự án, phân định rõ ràng:
- **[OPEN-SOURCE GỐC (`v4.5.1`)]**: Tính năng có sẵn từ dự án mã nguồn mở [`PhotoboothProject/photobooth` `v4.5.1`](https://github.com/PhotoboothProject/photobooth/tree/v4.5.1) (nằm ở thư mục gốc `api/`, `lib/`, `src/`, `vendor/`).
- **[PHÁT TRIỂN MỚI (`Operator Edition`)]**: Tính năng được thiết kế và lập trình mới hoàn toàn trong thư mục [`custom/`](../custom/) để phục vụ mô hình vận hành sự kiện chuyên nghiệp có nhân viên hỗ trợ.

---

## 1. Bảng So Sánh Tổng Quan: Bản Open-Source Gốc vs. Bản Phát Triển Mới

| Nhóm chức năng | Bản Open-Source Gốc (`v4.5.1`) | Bản Phát Triển Mới (`Operator Edition` - `custom/`) |
|---|---|---|
| **Mô hình vận hành** | Khách tự bấm trên màn hình cảm ứng (Self-service kiosk), không có khái niệm phiên (session) chặt chẽ. | Nhân viên vận hành điều khiển (Operator-assisted), quản lý từng phiên khách hàng bằng State Machine & khóa `revision`. |
| **Chụp lại (Retake)** | Nếu hỏng 1 tấm trong chuỗi ghép (collage), thường phải chụp lại từ đầu cả bộ. | Cho phép **Chụp lại (Retake) từng khung lẻ (#1, #2 hoặc #3)**; giữ nguyên các khung đã đẹp và bảo toàn ảnh cũ nếu lần chụp lại bị lỗi. |
| **Bộ lọc màu (Color Filter)** | Hiệu ứng CSS/GD đơn giản (`lib/filter.php`) hoặc hiệu ứng WebGL trình duyệt (`vendor/Seriously`). | **Engine 3D LUT `.cube` chuyên nghiệp bằng Python (`NumPy` + `Pillow`)** với nội suy ba chiều (trilinear interpolation) và chuẩn hóa màu sRGB ICC 300 DPI. |
| **Cơ sở dữ liệu** | Lưu tên file phẳng trong tệp text/JSON (`data/`), dễ hỏng khi mất điện hoặc2 tab cùng ghi. | **SQLite WAL Mode (`001_initial_schema.sql`)** với giao dịch ACID, khóa phiên duy nhất, bảng `idempotency_records` và `audit_logs`. |
| **Kiểm soát In ấn** | Gửi lệnh in trực tiếp qua [`api/print.php`](../api/print.php), có thể tự đóng dấu/cắt lại ảnh và dễ in trùng khi bấm đúp. | **State Machine in độc lập (`PrintService`)**: luôn khóa `1 bản/lệnh`, kiểm tra SHA-256 trước khi in, có trạng thái **`UNKNOWN`** chống tự động in lặp gây tốn giấy/mực. |
| **Chia sẻ QR qua LAN** | Dùng chung 1 cổng web với giao diện chính; khách quét QR có thể truy cập vào các đường dẫn khác của photobooth. | **Tách 2 cổng mạng độc lập (Dual-Listener)**: Cổng khách (`8081`) chỉ mở đúng `/share/{token}`, chặn `403` toàn bộ API và trang quản trị; token ký HMAC-SHA256 có hạn 24h & thu hồi tức thì. |
| **Xử lý nền & Chống treo** | Gọi lệnh chụp/ghép trực tiếp trong HTTP request của PHP (dễ timeout hoặc xung đột thiết bị USB). | **Single Worker Daemon (`bin/worker.php`)** có khóa đơn thể `flock`, nhịp tim (heartbeat) và tự động thu hồi job treo khi khởi động lại. |

---

## 2. Checklist Chi Tiết Các Tính Năng Hiện Có

### A. Tính năng thuộc Mã nguồn mở gốc `[OPEN-SOURCE GỐC - v4.5.1]`

Các thành phần này nằm ngoài thư mục `custom/`, được giữ nguyên ở commit `a8841409ed8d0db485191269d6c302505b67f64f`:

- [x] **[OPEN-SOURCE GỐC] Thư viện tạo mã QR Offline ([`vendor/phpqrcode/qrlib.php`](../vendor/phpqrcode/qrlib.php)):**
  - *Trạng thái trong dự án:* **Đang tái sử dụng trực tiếp** bởi [`ShareService.php`](../custom/src/Share/ShareService.php) để sinh mã QR chuẩn ISO/IEC 18004 (PNG/SVG) 100% offline không cần Internet.
- [x] **[OPEN-SOURCE GỐC] Điều khiển máy ảnh truyền thống ([`api/takePic.php`](../api/takePic.php), [`api/previewCamera.php`](../api/previewCamera.php), [`api/cameracontrol.py`](../api/cameracontrol.py)):**
  - *Trạng thái trong dự án:* Giữ nguyên làm **tài liệu tham chiếu lệnh `gphoto2`** cho [`Gphoto2CameraAdapter.php`](../custom/src/Camera/Gphoto2CameraAdapter.php) và dùng cho chế độ demo kiosk gốc nếu cần.
- [x] **[OPEN-SOURCE GỐC] Ghép ảnh & Bộ lọc GD cơ bản ([`lib/collage.php`](../lib/collage.php), [`lib/filter.php`](../lib/filter.php), [`api/applyEffects.php`](../api/applyEffects.php)):**
  - *Trạng thái trong dự án:* Giữ nguyên trong nhánh gốc; luồng Operator Edition **thay thế hoàn toàn** bằng pipeline Python 3D LUT để đảm bảo chất lượng in 300 DPI.
- [x] **[OPEN-SOURCE GỐC] Giao diện Kiosk tự phục vụ, Gallery PhotoSwipe & Gửi Email ([`index.php`](../index.php), [`gallery.php`](../gallery.php), [`vendor/PhotoSwipe`](../vendor/PhotoSwipe), [`vendor/PHPMailer`](../vendor/PHPMailer)):**
  - *Trạng thái trong dự án:* Giữ nguyên của bản gốc; không bật trên cổng sự kiện để đảm bảo nguyên tắc 100% offline và bảo mật ảnh khách hàng.
- [x] **[OPEN-SOURCE GỐC] In trực tiếp qua [`api/print.php`](../api/print.php):**
  - *Trạng thái trong dự án:* **Không sử dụng cho ảnh đã duyệt** (theo ADR-001) vì `api/print.php` của bản gốc có thể can thiệp lại vào bức ảnh trước khi in.

---

### B. Tính năng Mới Phát Triển Thêm `[PHÁT TRIỂN MỚI - Operator Edition]`

Toàn bộ các tính năng dưới đây được xây dựng mới 100% trong thư mục [`custom/`](../custom/):

#### 1. Quản lý Phiên Chụp & Đồng bộ Trạng thái (`custom/src/Session/`)
- [x] **[PHÁT TRIỂN MỚI] Máy trạng thái phiên chặt chẽ (`Session State Machine`):** Quản lý vòng đời `NEW` → `CAPTURING` → `CAPTURED` → `RENDERING` → `REVIEW` → `FINALIZED` → `CLOSED` / `CANCELLED` / `EXPIRED` ([`SessionService.php`](../custom/src/Session/SessionService.php)).
- [x] **[PHÁT TRIỂN MỚI] Ràng buộc 1 phiên hoạt động duy nhất:** Ngăn tạo nhầm nhiều phiên cùng lúc trên bàn vận hành (`409 ACTIVE_SESSION_EXISTS`).
- [x] **[PHÁT TRIỂN MỚI] Khóa tương tranh lạc quan (`Optimistic Concurrency Revision`):** Mọi thao tác sửa đổi đều kiểm tra `revision`; tự động từ chối nếu mở 2 tab thao tác đè nhau (`409 REVISION_CONFLICT` - `IT-02`).
- [x] **[PHÁT TRIỂN MỚI] Chống nhấn đúp & Retry mạng (`Idempotency-Key`):** Lưu vết từng yêu cầu trong bảng `idempotency_records` ([`IdempotencyService.php`](../custom/src/Session/IdempotencyService.php)), trả về đúng kết quả cũ nếu mạng chập chờn gửi lại request (`IT-03`).
- [x] **[PHÁT TRIỂN MỚI] Chống mất trạng thái khi F5 Reload trình duyệt (`IT-01`):** Tự động khôi phục đúng phiên đang mở và tiến trình job đang chạy qua `GET /custom/api/sessions/active`.

#### 2. Chụp 3 Khung Hình, Chụp Lại (Retake) & Điều Phối Thiết Bị (`custom/src/Camera/`, `custom/src/Jobs/`)
- [x] **[PHÁT TRIỂN MỚI] Chụp 3 khung hình độc lập (`Slot 1`, `Slot 2`, `Slot 3`) & Chế độ `Auto 3-Shot`:** Hỗ trợ chụp tự động liên tiếp 3 khung hoặc chụp lẻ từng khung trống.
- [x] **[PHÁT TRIỂN MỚI] Chụp lại (Retake) từng khung lẻ có bảo toàn phiên bản (`v1`, `v2`, ...):**
  - Cho phép chụp lại bất kỳ khung nào trong 3 khung mà không ảnh hưởng 2 khung còn lại.
  - Nếu quá trình chụp lại bị lỗi máy ảnh, **tự động giữ nguyên bức ảnh cũ của khung đó và bản ghép hợp lệ trước đó** (`IT-05`).
  - Khi chụp lại thành công, tự động đánh dấu bản ghép cũ hết hiệu lực (`valid = 0`) để bắt buộc ghép lại trước khi khóa ảnh cuối (`TC-10`).
- [x] **[PHÁT TRIỂN MỚI] Tiến trình Worker đơn thể (`Single Worker Daemon`):**
  - Chạy nền qua [`custom/bin/worker.php`](../custom/bin/worker.php) với khóa `flock` chống chạy trùng 2 worker (`IT-15`).
  - Có cơ chế nhịp tim (`worker_state`) và tự động thu hồi/rollback các job bị treo nếu máy tính tắt đột ngột (`IT-07`).
- [x] **[PHÁT TRIỂN MỚI] Bộ mô phỏng Camera (`MockCameraAdapter`) & Adapter DSLR thật (`Gphoto2CameraAdapter`):**
  - [`MockCameraAdapter.php`](../custom/src/Camera/MockCameraAdapter.php): Cho phép chạy thử và kiểm thử tự động toàn bộ phần mềm không cần máy ảnh, hỗ trợ giả lập lỗi timeout/ngắt kết nối.
  - [`Gphoto2CameraAdapter.php`](../custom/src/Camera/Gphoto2CameraAdapter.php): Giao tiếp với máy ảnh DSLR qua `gphoto2` CLI (sẵn sàng cho nghiệm thu phần cứng H1).

#### 3. Pipeline Xử Lý Ảnh Python, 3D LUT `.cube` & Ghép Khổ In 300 DPI (`custom/python/`, `custom/presets/`)
- [x] **[PHÁT TRIỂN MỚI] Trình đọc & Nội suy ba chiều 3D LUT `.cube` kích thước `33×33×33`:**
  - Viết bằng Python + NumPy vector hóa tại [`cube_lut.py`](../custom/python/cube_lut.py), kiểm tra chặt chẽ cấu trúc file `.cube`, `DOMAIN_MIN`/`DOMAIN_MAX` và giá trị ngoài miền `[0..1]`.
- [x] **[PHÁT TRIỂN MỚI] 4 Bộ lọc màu chuẩn kèm mẫu xem trước (`custom/presets/`):**
  - `Original` (Màu gốc chuẩn sRGB)
  - `Korean Soft` ([`korean-soft.cube`](../custom/presets/korean-soft.cube) — Tông sáng mịn kiểu studio Hàn Quốc)
  - `Film 400` ([`film-400.cube`](../custom/presets/film-400.cube) — Tương phản phim analog cổ điển)
  - `Vintage Fade` ([`vintage-fade.cube`](../custom/presets/vintage-fade.cube) — Màu ấm hoài cổ)
- [x] **[PHÁT TRIỂN MỚI] Chuẩn hóa EXIF, Hồ sơ màu ICC sRGB & Ghép 3 ảnh dọc (`1181 × 1748 px` @ 300 DPI):**
  - Thực hiện tại [`render_pipeline.py`](../custom/python/render_pipeline.py) theo mẫu [`vertical-three-v1.json`](../custom/templates/vertical-three-v1.json) (viền trắng 35 px, khoảng cách 24 px, center-crop 3:2 không méo hình).
  - Tự động xoay đúng chiều ảnh theo cờ EXIF Orientation (`TC-27`), chuyển đổi ICC profile về sRGB chuẩn và xuất kết quả **tất định (deterministic SHA-256)** (`TC-09`).
  - Bảo vệ chống ảnh hỏng (truncated JPEG) và chống tấn công tràn bộ nhớ (decompression bomb > 50 MiB hoặc > 60 MP) (`TC-29`).
- [x] **[PHÁT TRIỂN MỚI] Duyệt & Khóa ảnh cuối bất biến (`Finalize`) + Mở lại phiên (`Reopen`):**
  - Khi bấm `Finalize`, sao chép nguyên tử (byte-for-byte copy, không nén lại JPEG) từ bản render sang `final_assets` và kiểm tra mã băm SHA-256 (`TC-11`).
  - Cho phép `Reopen` phiên đã khóa để đổi màu/chụp lại mà vẫn lưu giữ lịch sử các bản final đã in trước đó (`TC-12`).

#### 4. Quản Lý Lệnh In Độc Lập & Đối Chiếu Trạng Thái `UNKNOWN` (`custom/src/Printer/`)
- [x] **[PHÁT TRIỂN MỚI] State Machine In tách rời khỏi trạng thái phiên:**
  - Vòng đời lệnh in: `PENDING` → `SUBMITTING` → `QUEUED` → `PRINTING` → `COMPLETED` / `FAILED` / `UNKNOWN` ([`PrintService.php`](../custom/src/Printer/PrintService.php)).
- [x] **[PHÁT TRIỂN MỚI] Khóa cứng `copies = 1` & Tách biệt `print` (in lần đầu) / `reprint` (in lại):**
  - Chặn mọi yêu cầu gửi số bản in khác 1 (`422 INVALID_COPIES`), bắt buộc xác nhận qua hộp thoại trước khi in (`TC-13`, `TC-14`).
- [x] **[PHÁT TRIỂN MỚI] Cơ chế `SUBMITTING` + Trạng thái `UNKNOWN` chống in lặp tốn giấy/mực:**
  - Ghi trạng thái `SUBMITTING` xuống đĩa trước khi gọi máy in; nếu mất kết nối hoặc timeout sau thời điểm này, hệ thống chuyển lệnh in sang **`UNKNOWN`** và **tuyệt đối không tự động gửi in lại** (`IT-09`, `IT-10`).
- [x] **[PHÁT TRIỂN MỚI] Công cụ Đối chiếu lệnh in `UNKNOWN` dành cho Admin:**
  - Cho phép Admin xác nhận kết quả kiểm tra khay giấy thực tế (`observed_completed` hoặc `confirmed_not_printed`) kèm ghi chú bắt buộc.

#### 5. Chia Sẻ Ảnh Qua Mã QR Nội Bộ & Cô Lập 2 Cổng Mạng (`custom/src/Share/`, `custom/public/guest.php`)
- [x] **[PHÁT TRIỂN MỚI] Kiến trúc 2 cổng mạng tách biệt (`Dual-Listener Isolation`):**
  - **Cổng Operator (`127.0.0.1:8080` - [`custom/public/index.php`](../custom/public/index.php)):** Chỉ lắng nghe nội bộ trên máy trạm cho nhân viên.
  - **Cổng Guest LAN (`0.0.0.0:8081` - [`custom/public/guest.php`](../custom/public/guest.php)):** Mở cho điện thoại khách qua Wi-Fi sự kiện; **chỉ cho phép truy cập `/share/{token}` và `/share/{token}/download`**, chặn toàn bộ `/custom/api/*`, `/admin/*`, `/` với lỗi `403 FORBIDDEN` (`TC-18`, `IT-13`).
- [x] **[PHÁT TRIỂN MỚI] Token chia sẻ ký HMAC-SHA256 & Không lưu token thô trong DB:**
  - Chỉ lưu mã băm `SHA-256` (`token_hash`) trong SQLite; tự động hết hạn sau 24 giờ (`TC-16`) và hỗ trợ thu hồi tức thì (`Revoke` - `TC-17`).
- [x] **[PHÁT TRIỂN MỚI] Trang tải ảnh khách hàng Tiếng Việt 100% Offline:**
  - Không dùng bất kỳ font hay script CDN ngoài nào (`IT-17`), kiểm tra SHA-256 của file ảnh trước khi trả về cho điện thoại khách (`IT-11`).

#### 6. Bảo Mật, Vận Hành, Sao Lưu & Giao Diện Tiếng Việt (`custom/src/Auth/`, `custom/src/Maintenance/`, `custom/public/`)
- [x] **[PHÁT TRIỂN MỚI] Xác thực Session Cookie, CSRF Token, Kiểm tra Origin & Phân quyền RBAC (`operator` / `admin`):**
  - Mật khẩu băm `password_hash`, giới hạn đăng nhập sai (rate-limiting 5 lần/15 phút), tự động hết hạn phiên đăng nhập sau 8 giờ không tương tác ([`AuthService.php`](../custom/src/Auth/AuthService.php)).
- [x] **[PHÁT TRIỂN MỚI] Giao diện Bàn Vận Hành Tiếng Việt ([`index.php`](../custom/public/index.php), [`operator.js`](../custom/public/assets/js/operator.js), [`operator.css`](../custom/public/assets/css/operator.css)):**
  - Thanh biểu ngữ luôn hiển thị rõ chế độ **`CHẾ ĐỘ MÔ PHỎNG (MOCK)`** (`IMP-030`).
  - Thanh theo dõi sức khỏe hệ thống thời gian thực (`Worker`, `Máy ảnh`, `Máy in`, `Ổ đĩa trống`, cảnh báo lệnh in `UNKNOWN`).
  - Đầy đủ màn hình Bàn vận hành, Hộp thoại xác nhận in, Hộp thoại QR, Lịch sử phiên chụp có phân trang và chức năng Xóa phiên 2 pha dành cho Admin (`IT-18`).
- [x] **[PHÁT TRIỂN MỚI] Bảo vệ dung lượng ổ đĩa (`Disk Space Guard < 2 GiB`) & Dọn dẹp lưu trữ tự động (`Retention`):**
  - Tự động chặn chụp/render/in mới với mã `507 DISK_LOW` khi ổ đĩa còn dưới 2 GiB (`TC-30`).
  - Tự động xóa phiên quá hạn 7 ngày nhưng **giữ lại các phiên đang có lệnh in `UNKNOWN` chưa đối chiếu** (`IT-16`).
- [x] **[PHÁT TRIỂN MỚI] Công cụ Sao lưu nóng (`backup.php`) & Phục hồi có kiểm tra SHA-256 (`restore.php`):**
  - Sao lưu SQLite an toàn bằng `VACUUM INTO` kèm toàn bộ file ảnh và `backup-manifest.json`.
  - Khi phục hồi, tự động kiểm tra SHA-256 từng file và chuyển các lệnh in đang dở sang trạng thái an toàn để **không bao giờ tự động in lại các lệnh cũ** (`IT-14`).
- [x] **[PHÁT TRIỂN MỚI] Bộ Kiểm thử Tự động, Benchmark & Soak Test 30 Phiên:**
  - 14 bài test Python (`test_pipeline.py`), 181 assertions PHPUnit (`OperatorWorkflowTest.php`), Live E2E (`run-e2e.php`), Benchmark (`benchmark-render.php`) và Soak Test 30 phiên liên tiếp (`soak-test.php`).

