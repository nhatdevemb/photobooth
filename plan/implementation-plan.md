# Kế hoạch triển khai chi tiết — Photobooth Operator Edition

- Phiên bản tài liệu: 1.1.
- Ngày lập: 09/11/2026, múi giờ UTC+07:00.
- Trạng thái: kế hoạch triển khai; chưa có mã ứng dụng hoặc kết quả nghiệm thu trong thư mục hiện tại.
- Mốc bàn giao đầu tiên: MVP chạy mô phỏng, không cần Canon R50, đèn flash hoặc máy in thật.
- Đối tượng sử dụng tài liệu: lập trình viên triển khai, người kiểm thử và người vận hành.

## 1. Mục tiêu và các quyết định nền

### 1.1. Kết quả cần đạt

Người vận hành hoàn thành được toàn bộ quy trình:

1. Đăng nhập và kiểm tra trạng thái hệ thống.
2. Tạo phiên mới, chụp ba ảnh vào ba slot.
3. Chụp lại bất kỳ slot nào mà không làm thay đổi hai slot còn lại.
4. Chọn Original, Korean Soft, Film 400 hoặc Vintage Fade.
5. Xem ba ảnh ghép dọc, duyệt chính xác ảnh sẽ được in và tải.
6. Chủ động xác nhận in; theo dõi kết quả; chủ động in lại khi cần.
7. Hiển thị QR để khách tải ảnh cuối trong LAN.
8. Đóng phiên, tạo phiên tiếp theo và tìm lại ảnh trong lịch sử.

Ở mốc đầu, camera và máy in đều được mô phỏng. Pipeline LUT, collage, lưu dữ liệu, đăng nhập, QR và tải JPEG phải chạy thật. Một bản mô phỏng không được đánh dấu là đã nghiệm thu phần cứng.

### 1.2. Những lựa chọn người dùng đã xác nhận

| Nội dung | Quyết định |
| --- | --- |
| Mốc triển khai đầu tiên | MVP hoàn chỉnh bằng thiết bị mô phỏng |
| Tình trạng thiết bị | Chưa có đủ phần cứng để nghiệm thu |
| Hướng phát triển | Mở rộng fork Photobooth v4 |
| Ngôn ngữ giao diện | Tiếng Việt; tên trong mã và API dùng tiếng Anh |
| Bố cục | Giấy 100 × 148 mm đặt dọc, ba ảnh xếp từ trên xuống |
| Vai trò thao tác | Người vận hành điều khiển capture, retake, chọn LUT và in |

### 1.3. Các mặc định kỹ thuật được chọn cho triển khai

Các mục dưới đây là mặc định của kế hoạch, không phải thông số đã được đo trên thiết bị:

| Nội dung | Mặc định |
| --- | --- |
| Môi trường thiết bị thật | Ubuntu 24.04 LTS |
| Backend và worker | PHP 8.3 |
| Xử lý ảnh | Python 3.12, Pillow và NumPy |
| Metadata và hàng đợi | SQLite trên ổ đĩa cục bộ |
| Giao diện | HTML/CSS, JavaScript ES modules, không thêm framework SPA |
| Công cụ build frontend | Node.js 22; khóa dependency sau khi kiểm tra build upstream |
| Môi trường mô phỏng | Docker Compose; Apache/PHP và một worker CLI |
| Số camera / queue in | Một camera, một queue, một phiên đang thao tác |
| Countdown | 3 giây trước mỗi lần capture/retake |
| Số bản in | Một bản cho mỗi yêu cầu; in lại là yêu cầu riêng |
| Bộ lọc mặc định | Original; một lựa chọn áp dụng cho cả ba ảnh |
| Thời hạn QR | 24 giờ từ lúc phát hành, không vượt hạn giữ phiên |
| Giữ dữ liệu | 7 ngày kể từ lúc đóng hoặc hủy phiên |
| Dung lượng thấp | Chặn capture/render/in mới khi dung lượng trống dưới 2 GiB |

Không đưa vào MVP: thanh toán, gallery Internet, AI beauty, nhiều camera, nhiều printer queue, trình sửa template, thay LUT tùy ý từ trình duyệt, hoặc cam kết tự đọc chính xác giấy/mực còn lại.

## 2. Nền mã và quan hệ với bộ tài liệu cũ

### 2.1. Cố định upstream

- Repository nguồn: [PhotoboothProject/photobooth](https://github.com/PhotoboothProject/photobooth).
- Baseline: [v4.5.1](https://github.com/PhotoboothProject/photobooth/tree/v4.5.1).
- Commit đã kiểm tra trong bước lập kế hoạch: a8841409ed8d0db485191269d6c302505b67f64f.
- Nhập mã ứng dụng vào thư mục photobooth/ cạnh thư mục photobooth_plan/ hiện có.
- Ghi URL nguồn, tag, SHA, LICENSE và các thay đổi tích hợp vào manifest của ứng dụng.
- Khởi tạo fork phát triển cục bộ; việc tạo repository hoặc fork trên GitHub không phải điều kiện để chạy MVP.
- Không tự chuyển sang nhánh dev hoặc tự nâng phiên bản upstream khi cài đặt.

### 2.2. Những điểm cần sửa khi bắt đầu M0

Tài liệu architecture.md hiện trộn một số đường dẫn của nhánh phát triển với baseline v4. Bản triển khai phải dựa trên mã của commit đã pin:

| Nội dung | Điểm tham chiếu đúng ở v4.5.1 |
| --- | --- |
| Capture | api/takePic.php |
| Preview | api/previewCamera.php và api/cameracontrol.py |
| Collage | lib/collage.php và lib/collageConfig.php |
| Effects | api/applyEffects.php, lib/applyEffects.php và lib/filter.php |
| In upstream | api/print.php |
| Mặc định lệnh thiết bị | lib/config.php |

Không dùng api/capture.php, src/Collage.php, src/Enum/ImageFilterEnum.php hoặc src/Utility/ImageUtility.php làm điểm tích hợp cho baseline này.

Khi triển khai M0, đồng bộ requirements.md, architecture.md, roadmap.md và test-cases.md với tài liệu này, đặc biệt về trạng thái in độc lập, mốc mô phỏng và mốc phần cứng. Hiện tại chỉ bổ sung file kế hoạch này; các tài liệu nền vẫn được giữ nguyên để đối chiếu.

Nếu có khác biệt, áp dụng các quyết định trong tài liệu này cho phần triển khai mới. Các yêu cầu nghiệp vụ gốc không được đề cập thay đổi vẫn giữ hiệu lực.

### 2.3. Quy tắc tái sử dụng

- Giữ mã upstream để chạy demo, đối chiếu cấu hình, giấy phép và hành vi thiết bị.
- Lớp mở rộng tự quản lý session, SQLite, revision, LUT, ảnh cuối và idempotency.
- Không gọi endpoint upstream bằng HTTP nội bộ để tạo chuỗi thao tác nghiệp vụ.
- Không include nguyên endpoint PHP như một thư viện: endpoint có thể đọc request toàn cục, ghi tệp hoặc kết thúc tiến trình.
- Tách logic cần tái sử dụng thành adapter nhỏ, có kiểm thử và đầu vào rõ ràng.
- Không dùng đường in upstream cho ảnh đã finalize: endpoint này có thể xoay, thêm khung, QR, chữ hoặc crop ảnh trước khi gửi in.

## 3. Kiến trúc triển khai

### 3.1. Sơ đồ thành phần

    Trình duyệt người vận hành
              |
       Operator API PHP
              |
       Session Service
          /        \
      SQLite      Asset Store riêng
         |
      Job Queue
         |
    Worker PHP CLI
      |        |         |
    Camera   Renderer   Printer
    Adapter  Python     Adapter
      |                   |
    Mock / gphoto2      Mock / CUPS

    Điện thoại khách -> Share Gateway -> JPEG cuối đã khóa

### 3.2. Phân chia module

| Module | Trách nhiệm | Không được làm |
| --- | --- | --- |
| HTTP/API | Auth, CSRF, validate, revision, enqueue, phản hồi | Chờ capture hoặc render dài trong request |
| Session Service | State machine, slot, phiên bản, finalize, close | Điều khiển thiết bị trực tiếp |
| Asset Store | Ghi nguyên tử, hash, đối soát tệp, cấp quyền đọc | Phục vụ thư mục dữ liệu công khai |
| Worker | Claim job, heartbeat, gọi adapter, lưu kết quả | Gửi lại lệnh in có kết quả không rõ |
| Camera Adapter | Preview, capture, health, quyền sở hữu camera | Thay ảnh hiện hành trước khi capture thành công |
| Renderer | EXIF, sRGB, LUT, crop, collage, preview | Sửa ảnh gốc hoặc tự gửi in |
| Print Service | Idempotency, job trạng thái, in lại | Render lại hoặc tự hiệu chỉnh màu |
| Share Service | Token, viewer, download, thu hồi | Cho khách truy cập API vận hành |
| Maintenance | Retention, health, backup, log | Xóa phiên còn tác vụ hoặc yêu cầu in chưa giải quyết |

### 3.3. Cấu trúc mã dự kiến

Các đường dẫn sau nằm trong photobooth/:

    custom/
      public/             Front controller và tài nguyên UI được phép phục vụ
      src/
        Auth/
        Session/
        Storage/
        Jobs/
        Camera/
        Image/
        Printer/
        Share/
        Maintenance/
      bin/                Worker, migration, tài khoản, backup, maintenance
      python/             Pipeline ảnh và parser .cube
      presets/            LUT và metadata có phiên bản
      templates/          Template JSON cố định của MVP
      migrations/         Migration SQLite đánh số
      tests/              PHPUnit, pytest và Playwright
      deploy/             Apache, Docker và systemd mẫu
      composer.json       Dependency PHP riêng của lớp mở rộng
      requirements.lock   Dependency Python đã khóa
      upstream.json       Baseline và kiểm kê thay đổi tích hợp

Dữ liệu runtime ở /var/lib/photobooth-operator trong máy thật hoặc volume tương ứng trong Docker; không nằm dưới webroot. Không đưa ảnh khách, database, mật khẩu và log runtime vào Git.

### 3.4. Môi trường chạy

- Hai service web và worker dùng cùng một image và cùng phiên bản dependency.
- Web xử lý API/UI; worker chạy một instance để tuần tự hóa thao tác thiết bị.
- Bật foreign keys, WAL, busy timeout 5 giây và synchronous FULL cho từng kết nối SQLite.
- Dùng migration có bảng schema_migrations; sao lưu trước khi nâng schema.
- Dependency build và thư viện UI được đóng gói để runtime hoạt động khi không có WAN.
- Upstream demo chạy trong profile phát triển riêng; không xuất các endpoint đó lên listener khách.
- M0 phải smoke-test PHP 8.3 và Node.js 22 với baseline. Sửa lỗi tương thích nhỏ bằng patch riêng có kiểm thử; không thay baseline hoặc kiến trúc ngầm.

## 4. Dữ liệu và tính bất biến

### 4.1. Bảng chính

| Bảng | Nội dung tối thiểu | Ràng buộc chính |
| --- | --- | --- |
| users | id, username, password_hash, role, disabled_at | username duy nhất; operator hoặc admin |
| sessions | id, state, revision, selected_lut, template_id, current_render_id, current_final_id, active_job_id, created_by, timestamps | revision tăng; chỉ một phiên chưa đóng/hủy |
| session_slots | session_id, slot, current_photo_id | khóa chính (session_id, slot); slot từ 1 đến 3 |
| photos | id, session_id, slot, version, path, sha256, width, height, created_at | duy nhất (session_id, slot, version); không cập nhật byte ảnh |
| jobs | id, session_id, type, status, accepted_revision, payload, result, error, worker_id, heartbeat, timestamps | một tác vụ thay đổi nội dung đang hoạt động cho mỗi phiên |
| renders | id, session_id, source_photo_ids, input_signature, lut_version, template_version, pipeline_version, image_path, preview_path, sha256, valid | lưu snapshot đầu vào; không suy ra từ slot hiện tại |
| final_assets | id, session_id, render_id, path, sha256, created_at | tham chiếu ảnh đã duyệt; không ghi đè |
| print_requests | id, session_id, final_asset_id, copies, action, status, adapter_job_id, submitted_at, error | luôn trỏ final_asset; copies bằng 1 trong MVP |
| share_tokens | id, token_hash, final_asset_id, expires_at, revoked_at | không lưu token thô; chỉ trỏ một ảnh cuối |
| idempotency_records | actor_id, operation, key, payload_hash, resource_id, response | duy nhất (actor_id, operation, key) |
| audit_events | id, actor_id, session_id, job_id, action, outcome, safe_metadata, created_at | ghi cùng transaction khi thay đổi metadata |

UUID dùng cho các ID nghiệp vụ. Timestamp lưu UTC; UI chuyển sang múi giờ cấu hình, mặc định UTC+07:00.

### 4.2. Cấu trúc tài sản runtime

    sessions/{session_id}/
      originals/slot-1/v1.jpg
      originals/slot-2/v1.jpg
      originals/slot-2/v2.jpg
      originals/slot-3/v1.jpg
      rendered/{render_id}/print.jpg
      rendered/{render_id}/preview.jpg
      finalized/{final_asset_id}.jpg
      pending/{job_id}/...

- Chỉ server tạo đường dẫn; request không được truyền filename hoặc đường dẫn filesystem.
- Capture ghi vào pending, kiểm tra JPEG và hash, rồi publish sang vị trí immutable.
- Chỉ sau khi publish tệp thành công mới cập nhật current_photo_id trong transaction.
- Retake thất bại giữ nguyên ảnh đang được chọn và bản render còn hợp lệ trước đó.
- Publish render/final bằng tệp tạm trên cùng filesystem, flush/fsync và rename nguyên tử; flush thư mục khi cần cho độ bền sau mất điện.
- Finalize sao chép byte từ render chất lượng in, không re-encode; hash final phải bằng hash render đã duyệt.
- Khi khởi động, đối soát tệp pending/orphan trước khi nhận job; tệp chưa được database tham chiếu không được hiện trong UI như ảnh hợp lệ.

### 4.3. Revision và khóa

1. Client gửi revision đã đọc trong mỗi lệnh thay đổi phiên.
2. API kiểm tra revision, state, active_job và điều kiện nghiệp vụ trong transaction ngắn.
3. Nếu hợp lệ, ghi job/thay đổi, tăng revision đúng một lần và trả revision mới.
4. Job ghi accepted_revision; khi hoàn thành phải còn sở hữu đúng active_job_id và revision đó.
5. Job hoàn thành không tăng revision lần nữa. Trong thời gian này, lệnh thay đổi nội dung khác bị trả SESSION_BUSY.
6. Tiến độ, heartbeat và kết quả truy vấn trạng thái in không làm tăng revision phiên.
7. Tạo yêu cầu in, tạo/thu hồi token, finalize, reopen, close và cancel đều là thay đổi có kiểm tra revision.

Replay cùng idempotency key và payload trả lại resource cũ trước khi kiểm tra revision hiện tại; vì vậy retry request đã thành công không bị nhầm thành thao tác mới.

Không giữ transaction SQLite trong lúc chờ thiết bị hoặc Python. Dùng khóa instance worker và khóa camera ở mức hệ điều hành; transaction claim job ngăn hai worker cùng nhận một job.

## 5. State machine và quy trình nghiệp vụ

### 5.1. Trạng thái phiên

| Trạng thái | Ý nghĩa | Hành động hợp lệ |
| --- | --- | --- |
| NEW | Chưa có ảnh được nhận | Capture slot rỗng; hủy |
| CAPTURING | Một capture/retake đang chờ hoặc chạy | Đọc trạng thái; không bắt đầu thao tác nội dung khác |
| CAPTURED | Có từ một đến ba slot; số slot được trả riêng | Capture slot rỗng, retake slot đã có, render nếu đủ 3, hủy |
| RENDERING | Đã enqueue hoặc đang render | Đọc trạng thái; chờ kết quả |
| REVIEW | Render hiện hành hợp lệ để duyệt | Đổi LUT/render lại, retake, finalize hoặc hủy |
| FINALIZED | Đã khóa ảnh cuối hiện hành | In, QR, mở lại chỉnh sửa, đóng phiên |
| CLOSED | Phiên đã kết thúc | Xem lịch sử, tải, chia sẻ và in lại ảnh cuối đã có |
| CANCELLED | Đã hủy phiên | Xem nội bộ trong thời gian giữ dữ liệu; không phát sinh capture/render/in mới |

- Sau một capture thành công, chuyển CAPTURED; trả accepted_slots để phân biệt 1/3, 2/3, 3/3.
- Render chỉ nhận khi đủ ba slot và không có tác vụ nội dung khác.
- Render thành công chuyển REVIEW; thất bại trả về trạng thái hợp lệ trước tác vụ và hiện lỗi.
- Khi đổi LUT, lưu lựa chọn mới và làm mất hiệu lực render hiện tại ngay khi nhận lệnh; render lỗi không được duyệt nhầm ảnh của LUT cũ.
- Finalize chỉ nhận render_id hiện hành, còn valid, có đủ tệp/hash và đúng snapshot đã chọn.
- Reopen chỉ từ FINALIZED khi không có yêu cầu in chưa giải quyết; chuyển CAPTURED, bỏ current_render/current_final và giữ toàn bộ tài sản cũ.
- Token cũ tiếp tục trỏ đúng ảnh cuối cũ đến khi hết hạn hoặc bị thu hồi. Final mới dùng token mới; không âm thầm đổi nội dung một QR đã phát.
- Close chỉ từ FINALIZED khi không có capture/render đang chạy. Không bắt buộc phải in; có thể đóng phiên trong khi theo dõi một yêu cầu in riêng.
- Cancel từ NEW, CAPTURED hoặc REVIEW khi không có tác vụ đang chạy. Không hủy công việc thiết bị đang chạy bằng cách chỉ đổi state trong database.
- CLOSED không mở lại ở MVP; nếu cần phiên chụp mới thì tạo session mới.

### 5.2. Trạng thái tác vụ và yêu cầu in

- Job nội dung: QUEUED → RUNNING → SUCCEEDED hoặc FAILED.
- Print request: PENDING → SUBMITTING → QUEUED → PRINTING → COMPLETED.
- Kết quả kết thúc khác: FAILED hoặc UNKNOWN.
- Trạng thái hiển thị dựa trên adapter; CUPS không báo PRINTING thì UI không được tự khẳng định máy đang in.
- COMPLETED từ CUPS có nghĩa queue báo hoàn thành; không thay thế nghiệm thu bản giấy.
- UNKNOWN là có thể đã gửi nhưng không đủ bằng chứng. UI yêu cầu kiểm tra queue/bản giấy trước khi chủ động in lại.
- Cho phép đóng phiên khi print đang theo dõi; bản in và ảnh liên quan được bảo vệ khỏi retention đến khi xử lý xong.
- Không có nút in lại tự động, retry in tự động hoặc tự in khi finalize.

### 5.3. Thao tác từ UI

1. Màn hình chính tải active session nếu có; refresh trang không tự tạo phiên.
2. Nút Chụp 3 ảnh thực hiện lần lượt slot 1, 2, 3; đợi job trước thành công rồi gửi job tiếp theo.
3. Có nút chụp cho slot trống để tiếp tục phiên sau lỗi hoặc refresh.
4. Countdown là pha của job camera, phát tiến độ cho UI; refresh trang không kích hoạt thêm capture.
5. Chụp lại có nút riêng trên từng slot; không xóa ảnh hiện hành trước khi kết quả mới hợp lệ.
6. Chọn bộ lọc và dựng preview; chỉ bật Duyệt ảnh khi render hợp lệ đã tải xong.
7. Hộp thoại In hiển thị thumbnail của final, mã phiên và một bản; xác nhận mới tạo print request.
8. Sau finalize có thể tạo QR và đóng phiên mà không in.

## 6. Hợp đồng API

### 6.1. Quy tắc chung

- API JSON đặt dưới /custom/api; tài liệu OpenAPI là đầu ra bắt buộc của M1 và cập nhật theo từng mốc.
- Tất cả endpoint nghiệp vụ cần cookie đăng nhập; thao tác thay đổi cần X-CSRF-Token.
- Các POST nghiệp vụ cần Idempotency-Key, kể cả tạo session, capture, render, finalize, print và tạo token.
- Khóa idempotency là UUID do UI tạo cho một ý định thao tác. Retry mạng dùng lại key; thao tác chủ động mới tạo key mới.
- Ghi record idempotency và job/resource trong cùng transaction. Cùng key khác payload trả IDEMPOTENCY_CONFLICT.
- Record giữ đến khi xóa session; tạo session được gắn với session tạo ra để cùng chính sách dọn dữ liệu.
- API không nhận lệnh shell, filename, đường dẫn lưu hoặc địa chỉ queue tùy ý từ operator.
- Dữ liệu lỗi có code, message tiếng Việt và request_id; không gửi stack trace hoặc đường dẫn riêng cho client.

### 6.2. Endpoint

Các đường dẫn trong bảng, trừ /share, đều nối sau /custom/api.

| Method | Đường dẫn | Đầu vào chính | Kết quả |
| --- | --- | --- | --- |
| POST | /auth/login | username, password; kiểm tra Origin cùng site | Cookie đăng nhập và CSRF token |
| GET | /auth/me | Cookie | User, role, CSRF token |
| POST | /auth/logout | CSRF | Hủy session đăng nhập |
| POST | /sessions | Idempotency-Key | 201: session NEW, revision 1, ba slot |
| GET | /sessions/active | Không | Active session hoặc null |
| GET | /sessions | cursor, limit tối đa 50 | Danh sách lịch sử phân trang |
| GET | /sessions/{id} | ID | State, revision, slot, render, final, job, print summary |
| POST | /sessions/{id}/slots/{slot}/capture | revision | 202: job_id, revision mới |
| POST | /sessions/{id}/slots/{slot}/retake | revision | 202: job_id, revision mới |
| POST | /sessions/{id}/render | revision, lut_id, template_id | 202: job_id, snapshot đầu vào |
| POST | /sessions/{id}/finalize | revision, render_id | 201: final_asset_id, SHA-256 |
| POST | /sessions/{id}/reopen | revision | State CAPTURED, revision mới |
| POST | /sessions/{id}/close | revision | State CLOSED, retention deadline |
| POST | /sessions/{id}/cancel | revision | State CANCELLED, retention deadline |
| GET | /jobs/{id} | ID | Type, status, phase, progress, result hoặc error |
| GET | /assets/{id} | ID, variant được cho phép | JPEG/preview theo quyền đăng nhập |
| POST | /sessions/{id}/prints | revision, final_asset_id, copies=1, action=print/reprint | 202: print_request_id |
| GET | /prints/{id} | ID | Status, adapter_job_id, reason và timestamps |
| POST | /prints/{id}/resolve | admin, revision, outcome, note | Ghi nhận operator đã đối chiếu UNKNOWN |
| POST | /sessions/{id}/shares | revision, final_asset_id | 201: URL, QR, expires_at |
| POST | /shares/{id}/revoke | revision | Thu hồi ngay token |
| POST | /sessions/{id}/delete | admin, revision, xác nhận | 202: thu hồi token và xếp tác vụ xóa |
| GET | /health | Cookie | Chế độ, worker, camera, preview, printer, disk |
| GET | /share/{token} | Token | Viewer công khai chỉ cho một ảnh cuối |
| GET | /share/{token}/download | Token | JPEG cuối đúng byte, attachment |

MVP chưa có API cấu hình nâng cao hoặc sửa LUT. Admin cấu hình qua tệp môi trường/CLI và restart có kiểm soát.

### 6.3. Ví dụ giao tiếp

Yêu cầu retake:

    POST /custom/api/sessions/{session_id}/slots/2/retake
    Content-Type: application/json
    X-CSRF-Token: ...
    Idempotency-Key: {uuid-cua-y-dinh-retake}

    {"revision": 7}

Phản hồi:

    HTTP 202
    {
      "session_id": "...",
      "revision": 8,
      "state": "CAPTURING",
      "job_id": "..."
    }

UI poll job mỗi 500 ms khi tab đang hiển thị; giảm còn 2 giây khi tab ẩn. Kết thúc job thì tải lại session; health poll mỗi 5 giây. Không cần WebSocket ở MVP.

### 6.4. Mã lỗi

| HTTP | Mã tiêu biểu | Cách xử lý trên UI |
| --- | --- | --- |
| 401 | AUTH_REQUIRED | Chuyển về đăng nhập, giữ ID phiên đang xem |
| 403 | FORBIDDEN, CSRF_INVALID | Hiện lỗi quyền; không tự gửi lại mutation |
| 404 | NOT_FOUND | Hiện tài nguyên không tồn tại/không được phép thấy |
| 409 | REVISION_CONFLICT, SESSION_BUSY, ACTIVE_SESSION_EXISTS, IDEMPOTENCY_CONFLICT | Tải lại dữ liệu; không tự đổi key để lặp thao tác |
| 422 | INVALID_SLOT, MISSING_PHOTOS, INVALID_STATE, INVALID_LUT, INVALID_COPIES | Nêu trường cần sửa |
| 429 | LOGIN_RATE_LIMITED | Chờ theo Retry-After |
| 503 | WORKER_UNAVAILABLE, DEVICE_UNAVAILABLE, DISK_LOW | Giữ phiên, hướng dẫn phục hồi |
| 500 | INTERNAL_ERROR | Hiện request_id để tra log |

Token sai, hết hạn hoặc bị thu hồi trả cùng thông báo công khai, không tiết lộ session hoặc đường dẫn tệp.

## 7. Camera, ảnh và thiết bị mô phỏng

### 7.1. Hợp đồng camera

CameraAdapter có startPreview(), stopPreview(), capture(destination, deadline), health() và disconnect(). destination chỉ do Asset Store cấp; kết quả gồm đường dẫn tạm, kích thước ảnh, hash và metadata an toàn.

MVP dùng MockCameraAdapter:

- Ảnh mẫu JPEG có quyền sử dụng rõ ràng, không dùng ảnh cá nhân.
- Bộ fixture kỹ thuật được tạo tự động: số slot, màu nhận diện, hướng EXIF, ảnh hỏng và ảnh lớn.
- Danh sách ảnh theo thứ tự xác định; seed cố định cho CI.
- Preview mô phỏng là chuỗi frame dựng sẵn hiển thị bằng UI, không cần webcam hoặc quyền browser camera.
- Capture có độ trễ cấu hình, mặc định 500 ms sau countdown; mỗi retake có ảnh/phiên bản phân biệt được.
- Kịch bản lỗi bật qua cấu hình test: timeout, disconnect, JPEG hỏng, lưu tệp thất bại và hết dung lượng.
- Kịch bản giả lập không được bật từ API công khai hoặc tham số URL khách.

### 7.2. Pipeline ảnh

1. Xác thực JPEG thật, giới hạn 50 MiB và 60 megapixel; từ chối ảnh hỏng trước khi cập nhật slot.
2. Giữ nguyên ảnh gốc, tạo bản xử lý và áp dụng EXIF orientation.
3. Chuyển ICC hợp lệ sang sRGB; nếu không có ICC thì giả định sRGB và ghi audit. Nếu ICC khai báo nhưng hỏng thì trả lỗi, không im lặng gắn nhãn.
4. Original bỏ qua bước LUT; ba preset còn lại dùng .cube RGB 3D, size 33, domain 0..1, nội suy trilinear vector hóa bằng NumPy.
5. Áp dụng LUT lên ảnh trước khi crop/thu nhỏ vào collage; không dùng vòng lặp Python theo từng pixel.
6. Center-crop từng ảnh vào vùng slot và resize bằng bộ lọc chất lượng cao.
7. Ghép đúng thứ tự slot 1, 2, 3; gắn profile sRGB cho JPEG kết quả.
8. Xuất print.jpg chất lượng 95, subsampling 0, kích thước 1181 × 1748 px, metadata DPI 300.
9. Sinh preview.jpg từ print.jpg, cạnh dài tối đa 1000 px; không chạy lại LUT cho preview.
10. Loại EXIF/GPS khỏi ảnh cuối được chia sẻ; giữ ICC và thông tin DPI cần thiết.

Template đầu tiên có ID vertical-three-v1:

- Canvas 1181 × 1748 px, nền trắng.
- Viền 35 px mỗi cạnh, tương đương xấp xỉ 3 mm.
- Khoảng cách hai hàng liên tiếp 24 px, tương đương xấp xỉ 2 mm.
- Slot 1: x=35, y=35, width=1111, height=543.
- Slot 2: x=35, y=602, width=1111, height=543.
- Slot 3: x=35, y=1169, width=1111, height=544.
- Không thêm logo, chữ, ngày tháng hoặc QR lên ảnh trong MVP.

Ba preset màu phải có version, checksum, mô tả biến đổi và contact sheet. Tên Korean Soft/Film 400/Vintage Fade là tên preset của dự án; không cam kết mô phỏng chính xác một phim hoặc sản phẩm thương mại.

Chữ ký render gồm ba hash ảnh gốc theo thứ tự, hash LUT, template, thông số encoder và phiên bản pipeline. Tính quyết định được kiểm tra trong cùng môi trường dependency đã khóa.

### 7.3. Hợp đồng gọi Python

- Worker gọi một tiến trình Python cho một render bằng argv, không đi qua shell.
- Đầu vào là manifest JSON trong vùng dữ liệu riêng, chứa ba đường dẫn đã được server xác thực và cấu hình đã chọn.
- Đầu ra là JSON với image_path, preview_path, dimensions, SHA-256, pipeline_version và thời gian xử lý.
- Log đi stderr; stdout chỉ dành cho JSON kết quả. Exit code khác 0 là render thất bại.
- Không trả nguyên stderr cho browser; chuyển sang mã lỗi nghiệp vụ và lưu log theo job_id.

### 7.4. Timeout và retry

| Thao tác | Giới hạn mặc định | Retry |
| --- | --- | --- |
| Countdown | 3 giây | Không |
| Capture sau countdown | 15 giây | Không tự chụp lại vì có thể đã nhả màn trập |
| Render | 30 giây | Tối đa 1 lần tự động từ snapshot bất biến |
| Gửi in | 10 giây | Không tự gửi lại nếu có thể đã submit |
| Truy vấn trạng thái in | 5 giây mỗi lần | Poll 2 giây, backoff tối đa 10 giây |
| Worker heartbeat | 2 giây | Health báo stale sau 10 giây |
| SQLite chờ khóa | 5 giây | Trả lỗi bận; không giữ request vô hạn |

Vượt timeout thì dừng đúng process group của tác vụ, thu hồi quyền sở hữu trước khi nhận tác vụ kế tiếp. Không dùng kill theo tên tiến trình toàn hệ thống.

## 8. In và bảo vệ khỏi gửi trùng

### 8.1. Adapter và mô phỏng

PrintAdapter có submit(finalAsset, requestId), query(adapterJobId) và health().

MockPrintAdapter phải:

- Tạo biên nhận theo print_request_id, chứa final_asset_id, hash, copies và timestamp.
- Dùng tạo tệp độc quyền/ghi nguyên tử để cùng request không tạo hai biên nhận.
- Lưu trạng thái qua restart; không chỉ giữ queue trong RAM.
- Có kịch bản hoàn thành, lỗi trước submit, timeout sau submit và mất trạng thái queue.
- Luôn báo mock rõ ràng trong API, UI, log và báo cáo nghiệm thu.

### 8.2. Trình tự gửi in

1. API kiểm tra final thuộc session, còn tồn tại và request action hợp lệ.
2. Với action=print, final phải là bản hiện hành của phiên FINALIZED và chưa có lần in đầu đã được tiếp nhận cho asset đó.
3. Với action=reprint, cho phép chọn final cũ trong lịch sử; UI luôn hiển thị ảnh và cảnh báo nếu còn request UNKNOWN.
4. Tạo print_request PENDING cùng idempotency record trong một transaction.
5. Worker kiểm tra lại SHA-256 ngay trước submit; sai hash thì FAILED và không gọi adapter.
6. Ghi SUBMITTING bền vững trước khi thực thi lệnh có tác dụng phụ.
7. Submit một lần, nhận adapter_job_id, lưu QUEUED và chỉ poll từ đó.
8. Không nhận được bằng chứng chắc chắn sau khi đã bắt đầu submit thì UNKNOWN.

Không hứa bảo đảm exactly-once giữa SQLite và CUPS vì không có transaction chung. Mục tiêu là loại gửi trùng do click/retry và không tự lặp khi kết quả mơ hồ.

Admin giải quyết UNKNOWN bằng ghi chú và kết quả observed_completed hoặc confirmed_not_printed. Giữ nguyên dấu vết UNKNOWN trong audit; việc xác nhận chưa in không tự tạo job mới.

### 8.3. Phần CUPS ở mốc thiết bị

- Queue name là cấu hình tin cậy do admin đặt, không nhận từ request operator.
- Gọi lệnh bằng argv, giới hạn thời gian; gắn request ID vào job title để hỗ trợ đối chiếu.
- Ưu tiên truy vấn queue hiện tại và lịch sử job theo ID; không tìm thấy job không đồng nghĩa đã in xong.
- CUPS đã nhận nhưng mất job ID chỉ được liên kết lại khi có bằng chứng duy nhất, đủ rõ; nếu không vẫn UNKNOWN.
- Chọn media/orientation/margins theo capability thực tế của CP1500; không mặc định borderless.
- Không thêm màu, chữ, crop hoặc khung ở bước in. Dùng đúng JPEG được finalize.

## 9. Đăng nhập, LAN QR và vòng đời dữ liệu

### 9.1. Quyền và tài khoản

| Vai trò | Quyền |
| --- | --- |
| Guest có token | Xem và tải đúng final mà token cấp |
| Operator | Tạo/chụp/retake/render/finalize/đóng phiên, in/in lại, lịch sử, chia sẻ, health |
| Admin | Các quyền operator; quản lý tài khoản qua CLI, xóa dữ liệu, xử lý UNKNOWN và maintenance |

- Không có mật khẩu mặc định được commit; tạo admin đầu bằng CLI.
- Hash mật khẩu bằng password_hash; cookie HttpOnly, SameSite=Lax và Secure khi HTTPS.
- Regenerate session ID khi đăng nhập; hết phiên sau 8 giờ không hoạt động.
- Chặn brute force: 5 lần đăng nhập sai trong 15 phút cho mỗi cặp username/IP, sau đó trả 429 trong 15 phút.
- Kiểm tra Origin cho login và mutation; không bật CORS wildcard.
- Đăng nhập lại không tự phát lại lệnh chụp/in trước đó.

### 9.2. Tách mặt truy cập

- Operator listener mặc định chỉ bind loopback; có thể dùng trực tiếp trên máy hoặc SSH tunnel.
- Guest listener bind địa chỉ LAN đã cấu hình và chỉ phục vụ /share cùng tài nguyên tĩnh của viewer.
- Listener guest từ chối /custom/api, /admin, /api upstream và mọi đường dẫn dữ liệu riêng.
- Không suy ra host QR từ Host header của request. SHARE_BASE_URL phải là URL cấu hình, có IP/hostname LAN mà điện thoại truy cập được.
- Cấu hình QR từ chối localhost, 127.0.0.0/8, ::1 và địa chỉ wildcard.
- HTTP chỉ là mặc định demo trong LAN kiểm soát; HTTPS và cấu hình tin cậy chứng chỉ là điều kiện triển khai nếu LAN không đáng tin cậy.
- Trang share không tải font, script, analytics hoặc QR từ dịch vụ Internet.

### 9.3. Token chia sẻ

- Token gắn cố định với một final_asset_id; không cấp quyền xem originals hoặc liệt kê phiên.
- Dạng URL token gồm share ID ngẫu nhiên và secret không dự đoán được.
- Sinh secret bằng HMAC-SHA256 từ SHARE_SIGNING_KEY và share ID ngẫu nhiên; lưu token_hash, không lưu token thô.
- SHARE_SIGNING_KEY là secret runtime riêng, không commit; cho phép tạo lại cùng URL khi replay API mà không lưu plaintext token trong idempotency response.
- Download, viewer và thumbnail đều xác thực token, expiry và revoke.
- Viewer đặt Referrer-Policy: no-referrer và Cache-Control: no-store; không ghi token trong access log.
- Response API token cũng không được ghi trong log hoặc idempotency record dưới dạng plaintext.
- Thay signing key phải đi kèm thu hồi token cũ và hướng dẫn phát hành lại QR.

### 9.4. Retention, xóa và backup

- Khi đóng/hủy phiên, đặt expires_at sau 7 ngày; phiên đang mở không tự bị xóa theo lịch.
- Token mới mặc định hết hạn sau 24 giờ, luôn chặn ở deadline của session nếu đã đóng.
- Khi đóng phiên, rút ngắn token hiện có nếu token vượt deadline giữ dữ liệu.
- Maintenance chạy mỗi giờ; không xóa phiên có capture/render còn sống hoặc print request PENDING/SUBMITTING/QUEUED/PRINTING/UNKNOWN chưa giải quyết.
- Nếu cleanup bị giữ lại do print chưa giải quyết, health phải hiện cảnh báo; không âm thầm bỏ bảo vệ.
- Xóa thủ công cần admin xác nhận và không có công việc đang hoạt động/chưa giải quyết; token bị thu hồi ngay khi nhận yêu cầu hợp lệ.
- Xóa theo hai pha: đánh dấu tombstone, ẩn khỏi UI và thu hồi quyền đọc; sau đó worker xóa tệp và hoàn tất metadata. Restart có thể tiếp tục công việc xóa.
- Giữ audit tối thiểu không chứa ảnh/token trong 30 ngày; idempotency record không được trỏ sang resource của phiên khác sau cleanup.
- Backup dùng cơ chế backup SQLite nhất quán và copy asset khi đã tạm ngừng nhận mutation; không chỉ copy tệp .sqlite trong lúc đang ghi WAL.
- Hướng dẫn backup nêu rõ có chứa ảnh cá nhân và secret; bản backup cũng phải có chính sách hết hạn và quyền truy cập.
- Restore trên volume sạch, kiểm tra hash, thu hồi token nếu không khôi phục được signing key, và đối soát in trước khi mở lại worker.

## 10. Phục hồi, health và vận hành

### 10.1. Ma trận phục hồi

| Tình huống | Hành vi bắt buộc |
| --- | --- |
| Request mất phản hồi sau enqueue | Replay key cũ trả cùng resource; UI tiếp tục poll |
| Refresh hoặc đóng browser lúc capture | Worker tiếp tục; trang tải lại tìm active session/job |
| Worker chết lúc capture | Chờ process cũ thực sự dừng; job FAILED, giữ slot cũ, không tự chụp lại |
| Capture tạo tệp nhưng chưa commit metadata | Đối soát pending; không chọn làm ảnh hiện hành nếu thiếu bằng chứng commit |
| Worker chết lúc render | Thu hồi tiến trình, retry tối đa một lần từ snapshot; không nhận output của attempt cũ |
| Crash sau publish render/final nhưng trước commit | Orphan bị cách ly/dọn; không công bố như asset hợp lệ |
| File final bị mất hoặc sửa | Chặn download/in của asset đó; health và audit báo lỗi toàn vẹn |
| Crash khi print còn PENDING, chưa SUBMITTING | Có thể nhận lại job vì chưa bắt đầu tác dụng phụ |
| Crash khi print đã SUBMITTING | Đối chiếu bằng chứng adapter; thiếu bằng chứng thì UNKNOWN |
| Crash sau khi đã lưu adapter_job_id | Chỉ query job đó; không submit lại |
| Queue không truy cập được | Hiện lỗi; giữ ID; không kết luận đã hoàn thành |
| Database bận hoặc storage lỗi | Rollback, giữ ảnh trước đó; không để slot trỏ tệp chưa hoàn chỉnh |
| Phiên đang mở gặp disk thấp | Chặn công việc tạo dữ liệu/in mới; vẫn xem, đóng và bảo trì được |
| Cleanup bị crash | Tiếp tục tombstone/delete job khi khởi động |

Startup phải kiểm tra migration, quyền tệp, kết nối database, worker singleton và các job dang dở trước khi nhận mutation mới.

Job camera/render mới được finalize kết quả khi active_job_id và accepted_revision còn khớp. Job in không dùng điều kiện revision phiên lúc hoàn tất, vì phiên có thể đã đóng; job in chỉ cập nhật print_request đã được nhận và final bất biến của nó.

Worker dùng vòng lặp tick: submit in xong thì trả về vòng lặp để nhận job tiếp; truy vấn queue theo lịch, không chờ máy nhả giấy mới cho phép capture/render của phiên tiếp theo. Việc một camera/render đang chạy có thể trì hoãn poll in ngắn hạn nhưng không đổi trạng thái in thành hoàn thành giả.

Retry render chỉ dành cho lỗi tiến trình tạm thời; đầu vào không hợp lệ, LUT lỗi, thiếu tệp và disk thấp không được retry tự động. Mỗi attempt có vùng pending riêng; output từ attempt bị thu hồi không được publish.

### 10.2. Health và nhật ký

Health trả tối thiểu:

- Mode mock hoặc hardware; phiên bản ứng dụng và upstream SHA.
- Database/migration status; worker heartbeat và số job đang chờ.
- Camera connected/unknown/error; preview available/paused/error.
- Printer adapter, queue state, số request đang theo dõi và UNKNOWN.
- Dung lượng trống, tình trạng đọc/ghi dữ liệu, cleanup bị giữ lại.

Ghi JSON log có timestamp, severity, request_id, session_id, job_id, print_request_id khi có. Tách mã lỗi cho capture timeout, render fail, hash mismatch, worker stale, printer unknown và disk low.

Rotate log theo ngày, giữ 14 ngày, trần tổng 200 MiB. Không log mật khẩu, cookie, CSRF, token QR, dữ liệu ảnh hoặc toàn bộ request body.

### 10.3. Cấu hình

Các giá trị phải có tài liệu và kiểm tra lúc startup:

| Nhóm | Khóa tiêu biểu |
| --- | --- |
| Runtime | APP_ENV, APP_TIMEZONE, DATA_DIR, DATABASE_PATH |
| Network | OPERATOR_BIND, GUEST_BIND, SHARE_BASE_URL, TRUSTED_HOSTS |
| Thiết bị | CAMERA_ADAPTER, PRINTER_ADAPTER, PRINTER_QUEUE |
| Ảnh | DEFAULT_LUT, DEFAULT_TEMPLATE, MAX_JPEG_BYTES, MAX_IMAGE_PIXELS |
| Vòng đời | SHARE_TTL_HOURS, SESSION_RETENTION_DAYS, DISK_LOW_BYTES |
| Secret | SHARE_SIGNING_KEY, session secret nếu thư viện cần |
| Timeout | CAPTURE_TIMEOUT_SECONDS, RENDER_TIMEOUT_SECONDS, PRINT_SUBMIT_TIMEOUT_SECONDS |

Không cho phép adapter rơi về mock âm thầm khi cấu hình hardware thất bại. Thiết bị không sẵn sàng phải báo lỗi.

## 11. Lộ trình chi tiết và công việc bàn giao

### 11.1. Ước lượng và phụ thuộc

Ước lượng theo một lập trình viên toàn thời gian, đã có môi trường phát triển cơ bản; không tính chờ phần cứng, mua vật tư hoặc phản hồi duyệt màu.

| Mốc | Nội dung | Phụ thuộc | Ngày công |
| --- | --- | --- | --- |
| M0 | Baseline, môi trường, CI và tài liệu tích hợp | Không | 2–3 |
| M1 | Auth nền, dữ liệu, session, worker và camera mock | M0 | 4–6 |
| M2 | LUT, collage, preview và finalize | M1 | 4–6 |
| M3 | Print mock, QR, quyền và maintenance | M1; phần final từ M2 | 4–6 |
| M4 | UI tiếng Việt, E2E, soak và gói demo | M2, M3 | 5–8 |
| Tổng MVP mô phỏng | Toàn bộ luồng mô phỏng hoạt động | M0–M4 | 19–29 |
| H1–H3 | Camera/flash, CUPS và nghiệm thu máy thật | Có thiết bị; MVP mô phỏng đạt | 7–12 |
| Tổng dự kiến cả hai mốc | Chưa tính rủi ro tương thích thiết bị | Các điều kiện trên | 26–41 |

Đây là ước lượng nỗ lực, không phải cam kết ngày hoàn thành. Giữ tổng 26–41 ngày công của roadmap gốc nhưng phân lại theo mốc bàn giao mô phỏng và phần cứng.

### 11.2. M0 — Chuẩn bị nền dự án

- [ ] IMP-001: Nhập upstream đúng SHA, giữ license/submodule cần thiết; tạo upstream manifest và hướng dẫn cập nhật có kiểm soát.
- [ ] IMP-002: Tạo skeleton custom, cấu hình riêng, data volume ngoài webroot và tệp môi trường mẫu không chứa secret.
- [ ] IMP-003: Tạo Docker Compose cho web/worker, profile demo upstream riêng; kiểm tra PHP 8.3 và Node.js 22.
- [ ] IMP-004: Khóa dependency PHP/Python/Node; tạo fixture ảnh cơ bản và công cụ chạy kiểm thử.
- [ ] IMP-005: CI lint PHP/JS/Python, kiểm tra định dạng ở chế độ không sửa, kiểm thử smoke và lưu artifact lỗi.
- [ ] IMP-006: Đồng bộ tài liệu nền và ghi ADR ngắn cho fork, worker, state machine, print UNKNOWN và listener LAN.

Đầu ra: môi trường từ checkout sạch chạy được; demo upstream và extension health hoạt động; không có dữ liệu runtime trong Git.

Điều kiện ra mốc: CI xanh; baseline SHA khớp; không có endpoint upstream bị mở trên listener khách; mọi thay đổi tương thích được ghi riêng.

### 11.3. M1 — Phiên, worker và camera mô phỏng

- [ ] IMP-007: Migration database, ràng buộc slot/phiên hoạt động, transaction repository và đường dẫn Asset Store.
- [ ] IMP-008: CLI tạo admin, đăng nhập/logout, cookie, CSRF, role và rate limit.
- [ ] IMP-009: API session, revision, idempotency và OpenAPI ban đầu.
- [ ] IMP-010: Worker claim/heartbeat, khóa singleton, timeout/process ownership và đối soát startup.
- [ ] IMP-011: CameraAdapter, MockCameraAdapter, countdown và kịch bản lỗi.
- [ ] IMP-012: Capture/retake immutable, publish nguyên tử, giữ slot khi lỗi và audit.
- [ ] IMP-013: Kiểm thử ba slot, không có slot thứ tư, hai tab, request trùng và worker crash.

Đầu ra: API có thể tạo phiên, chụp 3 ảnh, retake slot 2 và phục hồi sau restart mà không cần UI hoàn chỉnh.

Điều kiện ra mốc: TC-01 đến TC-06, phần auth/path trong TC-16/17 và các ca concurrency liên quan đạt; ảnh slot 1/3 không thay đổi sau retake slot 2.

### 11.4. M2 — Pipeline ảnh và duyệt kết quả

- [ ] IMP-014: Parser .cube size 33, kiểm tra domain/kích thước; bộ test identity, channel ramp và malformed LUT.
- [ ] IMP-015: Chuẩn hóa EXIF/ICC trên bản xử lý; bảo vệ ảnh gốc; giới hạn ảnh đầu vào.
- [ ] IMP-016: Tạo ba preset có version/license, contact sheet và ảnh mẫu đầu ra.
- [ ] IMP-017: Template vertical-three-v1, center crop, encoding cố định và kiểm tra thứ tự slot.
- [ ] IMP-018: Worker render, input signature, preview từ print.jpg và xử lý attempt thất bại.
- [ ] IMP-019: Finalize, hash, reopen và giữ bản final cũ; không re-encode khi finalize.
- [ ] IMP-020: Benchmark render, lưu thông số CPU/RAM và dependency cùng kết quả.

Đầu ra: từ ba ảnh mock tạo được JPEG 1181 × 1748 và preview; finalize có hash và tài sản bất biến.

Điều kiện ra mốc: TC-07 đến TC-11 đạt; Original không thay đổi màu ngoài sai số codec; render cũ không thể được duyệt sau khi thay nguồn/LUT.

### 11.5. M3 — In mô phỏng, chia sẻ và maintenance

- [ ] IMP-021: Print Service, PrintAdapter, mock receipt bền vững và state machine in riêng.
- [ ] IMP-022: Idempotency cho submit, kiểm tra hash, hành động reprint, UNKNOWN và admin resolve.
- [ ] IMP-023: Tách listener operator/guest, token/QR nội bộ, viewer và download cùng JPEG cuối.
- [ ] IMP-024: Hạn token, revoke, retention, xóa hai pha và bảo vệ asset có print đang chờ.
- [ ] IMP-025: Health, structured log, rotation, CLI backup/restore và tài liệu secret.
- [ ] IMP-026: Kiểm thử replay qua restart, lỗi sau submit, truy cập guest và vòng đời dữ liệu.

Đầu ra: toàn bộ backend có thể chạy luồng đầy đủ qua API; tải ảnh từ điện thoại trong LAN; không có lệnh in thật trong mode mock.

Điều kiện ra mốc: TC-12 đến TC-19, TC-27 và TC-29 đạt trong phạm vi mô phỏng; bộ thử guest listener chặn API/upstream/private data.

### 11.6. M4 — Giao diện và bàn giao mô phỏng

- [ ] IMP-027: Màn hình login, active session, preview, ba slot, countdown, retake và thông báo lỗi tiếng Việt.
- [ ] IMP-028: Chọn bộ lọc, chờ render, duyệt final và hộp xác nhận in/in lại.
- [ ] IMP-029: QR/viewer, lịch sử, xem final cũ và quyền thao tác operator/admin.
- [ ] IMP-030: Health panel, cảnh báo UNKNOWN/disk/worker, nhãn MÔ PHỎNG luôn nhìn thấy.
- [ ] IMP-031: Playwright E2E luồng chính và luồng lỗi; test reload, hai tab và retry mạng.
- [ ] IMP-032: Soak 30 phiên, benchmark, backup/restore rehearsal và test restart.
- [ ] IMP-033: README cài/chạy, demo script, troubleshooting, báo cáo test và danh sách hạn chế.

Đầu ra: người vận hành dùng UI thực hiện toàn bộ quy trình mà không dùng terminal, trừ bước cài đặt/tạo tài khoản ban đầu.

Điều kiện ra mốc: toàn bộ P0 không yêu cầu phần cứng đạt; không có lỗi làm mất ảnh, gửi in ngoài ý muốn hoặc lộ ảnh qua guest listener.

### 11.7. H1–H3 — Phần cứng và vận hành thực tế

Các việc dưới đây được thiết kế sẵn giao diện nhưng không phải điều kiện bàn giao MVP mô phỏng:

| Mốc | Công việc | Ngày công | Điều kiện nghiệm thu |
| --- | --- | --- | --- |
| H1 | Gphoto2 adapter, liveview, stop/capture/resume, USB reconnect; R50, trigger và AD600BM | 3–5 | HW-01/02/03 và TC-20/21/22/30 phần camera |
| H2 | CUPS adapter, discovery capability, media/margins, queue status, màu in và disconnect | 2–4 | HW-04 và TC-23/24/30 phần printer |
| H3 | Systemd, kiosk/autostart, guest LAN, reboot, soak trên bộ máy thật và hướng dẫn vận hành | 2–3 | HW-05 và TC-19/25/26 trên môi trường đích |

Gate phần cứng:

- R50: auto-detect, 30 JPEG đúng độ phân giải/hướng, không treo USB.
- Liveview: 15 phút liên tục, sau đó 10 capture, preview phục hồi mỗi lần.
- Flash: xác nhận đúng model/trigger/adapter, thử đồng bộ an toàn 30 lần và ghi nhận số lần không nổ.
- CP1500: in đúng tệp finalize, duyệt khổ, thứ tự, viền/crop và màu; lưu cấu hình driver/queue cùng mẫu in.
- LAN: điện thoại tải JPEG khi ngắt WAN; không vào được API điều khiển hoặc admin.
- Không tự khẳng định R50/AD600BM/CP1500 tương thích trước khi có bằng chứng trên thiết bị.

## 12. Kế hoạch kiểm thử và truy vết

### 12.1. Công cụ và dữ liệu

- PHPUnit: state machine, repository, API, auth, revision, idempotency, adapter và recovery.
- pytest: parser LUT, EXIF/ICC, crop, template, codec, hash và giới hạn đầu vào.
- Playwright: thao tác UI, reload, hai tab, lỗi mạng, xác nhận in và QR viewer.
- Bộ fixture phiên bản hóa: ảnh gắn số slot, lưới màu, EXIF xoay, JPEG lỗi và .cube lỗi.
- Dùng clock giả cho token/retention và barrier/fault injection cho crash; không dựa vào sleep dài để tạo lỗi ngẫu nhiên.
- SQLite integration test dùng tệp thật tạm thời để kiểm tra transaction/concurrency/WAL; không chỉ dùng database in-memory.
- Lỗi sau submit phải được chèn ở ranh giới adapter, để chứng minh request không bị tự submit lần hai.

### 12.2. Ánh xạ 30 test hiện có

| Ca trong test-cases.md | Mốc | Bằng chứng |
| --- | --- | --- |
| TC-01…06 | M1 | API/repository tests, ảnh và hash từng slot |
| TC-07…11 | M2 | Pixel/dimension/hash tests, contact sheet và immutable asset |
| TC-12…15 | M3 | Print mock receipts, idempotency và crash trace |
| TC-16…17 | M1 + M3 | Auth/CSRF, path traversal và guest listener tests |
| TC-18 | M3 | Clock giả: hết hạn và revoke |
| TC-19 | M3/M4; lặp H3 | Tải đúng hash qua LAN không WAN |
| TC-20…22 | H1 | Log thiết bị, cấu hình và kết quả capture/flash |
| TC-23…24 | H2 | Queue job, final hash và mẫu in được duyệt |
| TC-25 | M4 mô phỏng; lặp H3 | Crash/restart tự động; mất điện vật lý ở mốc hardware |
| TC-26 | M4 mô phỏng; lặp H3 | Báo cáo 30 phiên, thiếu ảnh và in trùng bằng 0 |
| TC-27 | M3/M4 | Disk-low injection; không có final dang dở |
| TC-28 | M3 | MVP chỉ nhận copies=1; số khác bị từ chối; UI nhiều bản để sau |
| TC-29 | M3 | Cleanup/expiry/delete/restart tests |
| TC-30 | M1/M3 giả lập; lặp H1/H2 | Mock disconnect và thử cáp/queue thật |

Không đánh dấu TC-20…24 PASS bằng mock. TC-25 và TC-26 phải ghi rõ môi trường; crash process không phải bằng chứng đã thử mất điện máy thật.

### 12.3. Ca bổ sung bắt buộc

| ID | Kịch bản | Kết quả mong đợi |
| --- | --- | --- |
| IT-01 | Hai tab retake cùng revision | Một yêu cầu được nhận; yêu cầu còn lại 409 |
| IT-02 | Replay cùng key sau refresh/restart | Cùng resource, không tăng lần capture/in |
| IT-03 | Cùng key nhưng khác slot/payload | 409, không phát sinh job |
| IT-04 | Đổi LUT khi render cũ đã xong | Không finalize được preview cũ |
| IT-05 | Retake thất bại sau khi đã có 3 ảnh | Giữ ba ảnh cũ và không tạo tham chiếu hỏng |
| IT-06 | Reopen và finalize lần hai | Hai final khác ID; QR cũ vẫn trả đúng final cũ |
| IT-07 | Close khi chưa từng in | Phiên đóng được; QR vẫn hợp lệ đến hạn |
| IT-08 | Close khi print đang chờ | Print tiếp tục được theo dõi; cleanup không xóa asset |
| IT-09 | Crash tại SUBMITTING | UNKNOWN hoặc query bằng chứng; không submit lại |
| IT-10 | CUPS/mock mất job history | Không suy ra COMPLETED từ not-found |
| IT-11 | Final bị sửa byte trên disk | In/download bị chặn, có lỗi toàn vẹn |
| IT-12 | URL có token hết hạn/revoke/sai | Không lộ ảnh hoặc metadata phiên |
| IT-13 | Guest thử API, upstream, private path | Bị từ chối dù biết đường dẫn |
| IT-14 | Restore backup rồi mở worker | Hash hợp lệ; in cũ không tự phát lại |
| IT-15 | Hai worker khởi động đồng thời | Chỉ một owner; không chạy cùng job |
| IT-16 | Cleanup bị ngắt giữa hai pha | Tiếp tục an toàn; token đã thu hồi không hoạt động lại |
| IT-17 | Preview/frame/final trong LAN offline | Không có request tới CDN hoặc dịch vụ ngoài |
| IT-18 | Mock/hardware cấu hình sai | Lỗi rõ ràng; không rơi về mock âm thầm |

### 12.4. Soak và hiệu năng

Soak mô phỏng bắt buộc:

- Chạy 30 phiên × 3 ảnh, retake ở ít nhất 10 phiên.
- Mỗi phiên render, finalize và in mock một lần; chọn 5 phiên để chủ động in lại.
- Với kịch bản trên phải có đúng 35 print receipts, không tính receipt của các test lỗi riêng.
- Kiểm tra đủ final, hash khớp, không có slot mất ảnh và không có token truy cập sai phiên.
- Chèn restart ở một tập kịch bản riêng để đếm kỳ vọng không bị lẫn với soak chuẩn.

Mục tiêu đo, không dùng thông số mock để cam kết thiết bị:

| Chỉ số | Mục tiêu |
| --- | --- |
| Phản hồi điều khiển UI | ≤ 0,5 giây, không tính tác vụ nền |
| Enqueue API trên máy đích | ≤ 0,5 giây ở tải một người vận hành |
| Render 3 ảnh + LUT | ≤ 10 giây trên cấu hình máy được ghi trong báo cáo |
| Capture thật sau shutter | JPEG sẵn sàng ≤ 8 giây; đo ở H1 |
| Preview thật | ≥ 10 fps; đo ở H1 |
| Printer ejection | Đo thực tế; không đặt SLA chưa kiểm chứng |

Nếu không đạt, ghi số đo, cấu hình và nút thắt trước khi tối ưu. Không giảm độ phân giải hoặc đổi preset âm thầm chỉ để qua benchmark.

## 13. Bàn giao, rollout và Definition of Done

### 13.1. Gói bàn giao MVP mô phỏng

- Mã nguồn gắn baseline upstream SHA và dependency lock.
- Docker Compose, tệp môi trường mẫu, migration, CLI tạo tài khoản và dữ liệu demo.
- UI tiếng Việt và nhãn mô phỏng; OpenAPI và mô tả state machine.
- Template, LUT có version, giấy phép/nguồn fixture và contact sheet.
- Test suite tự động; báo cáo 30 phiên; thông số benchmark và log lỗi đại diện.
- Hướng dẫn cài mới, chạy offline sau chuẩn bị dependency, backup/restore, reset demo và xử lý UNKNOWN.
- Danh sách phần chưa kiểm chứng trên thiết bị thật.

### 13.2. Trình tự rollout mô phỏng

1. Build image và chạy CI từ checkout sạch.
2. Tạo data volume, cấu hình URL LAN và signing key riêng.
3. Chạy migration, tạo admin; khởi động ở mode mock.
4. Chạy smoke một phiên đầy đủ và kiểm tra guest listener.
5. Chạy nghiệm thu UI, soak và restore rehearsal.
6. Gắn phiên bản bàn giao cùng báo cáo; khóa image/dependency dùng trong nghiệm thu.

Không public WAN hoặc triển khai dịch vụ cloud ở mốc này.

### 13.3. Rollout thiết bị thật và rollback

- Chuẩn bị staging trên đúng Ubuntu/máy vận hành, driver/queue và quyền USB.
- Cấu hình camera/printer adapter thật một cách tường minh; UI chuyển nhãn sang phần cứng thật.
- Chạy toàn bộ hardware gates, sau đó kiosk/autostart và rehearsal reboot.
- Trước nâng cấp: tạm ngừng nhận phiên mới, chờ job an toàn, giải quyết UNKNOWN cần thiết, backup dữ liệu/schema.
- Sau nâng cấp: kiểm tra migration, health, một phiên smoke và không có lệnh in cũ bị phát lại.
- Rollback ứng dụng về image trước; nếu schema không tương thích thì restore backup tương ứng trên volume riêng.
- Không rollback database cũ lên dữ liệu đã phát sinh mới mà không đối soát; không cho worker tự chạy lại print từ bản backup.

### 13.4. Definition of Done cho từng công việc

Một IMP được hoàn thành khi:

- Hành vi đúng hợp đồng, có lỗi nghiệp vụ và audit cần thiết.
- Có kiểm thử phù hợp với tác động, ưu tiên ranh giới dữ liệu/thiết bị và regression thực tế.
- Các kiểm tra liên quan đều đạt; không có test hardware bị báo PASS bằng mock.
- Tài liệu sử dụng và API được cập nhật cùng mã.
- Không có secret, ảnh khách hoặc dữ liệu runtime trong commit.
- Có bằng chứng gắn với test/mốc; thay đổi được rà soát trước khi gắn phiên bản bàn giao.

MVP mô phỏng hoàn tất khi M0–M4 đạt, tất cả P0 không cần thiết bị đã PASS và không còn lỗi mất ảnh, in ngoài ý muốn hoặc truy cập ảnh sai quyền. Nhãn production-ready chỉ được dùng sau H1–H3 và báo cáo thiết bị thật.

## 14. Rủi ro, giả định còn cần đo và nguồn tham chiếu

| Rủi ro/giả định | Cách xử lý | Mốc kiểm chứng |
| --- | --- | --- |
| Upstream cũ khác mã nhánh dev | Pin SHA, smoke-test, tích hợp qua adapter | M0 |
| PHP/Node mới gây lỗi build upstream | Patch tương thích nhỏ riêng; giữ baseline | M0 |
| Mất điện giữa file publish và DB commit | Ghi nguyên tử, fsync, đối soát startup | M1/M2; lặp H3 |
| R50 preview/capture tranh USB | Một owner, stop/capture/resume và timeout | H1 |
| Đồng bộ R50/trigger/AD600BM chưa được chứng minh | Thử đúng model, adapter và exposure an toàn | H1 |
| CP1500 media/borderless/queue khác dự kiến | Query capability, in mẫu, lưu cấu hình | H2 |
| Màu màn hình khác màu in | Một pipeline, sRGB, không xử lý lần hai, proof sheet | M2/H2 |
| Kết quả gửi in không rõ | UNKNOWN, không tự retry, operator đối chiếu | M3/H2 |
| Khách tải không được dù cùng Wi-Fi | Kiểm tra IP/DNS, client isolation và guest listener | M3/H3 |
| QR trỏ ảnh đã bị cleanup | Expiry bị chặn theo retention, token revoke và delete hai pha | M3 |
| Máy vận hành chưa xác định cấu hình | Ghi cấu hình cùng benchmark; không cam kết fps trước khi đo | M4/H1 |
| Print UNKNOWN giữ dữ liệu quá lâu | Cảnh báo health và quy trình admin resolve | M3/H3 |

Tài liệu tham chiếu nội bộ:

- [Tổng quan bộ kế hoạch](README.md).
- [Yêu cầu nghiệp vụ](requirements.md).
- [Kiến trúc nền](architecture.md).
- [Roadmap nền và mã DEV-001…014](roadmap.md).
- [30 ca kiểm thử gốc](test-cases.md).

Mã IMP trong tài liệu này chia nhỏ công việc triển khai; không thay ID TC/HW của tài liệu gốc. Mỗi PR hoặc bản bàn giao phải ghi IMP và TC/IT liên quan để truy vết.

Nguồn upstream đã dùng để lập kế hoạch:

- [Repository tại v4.5.1](https://github.com/PhotoboothProject/photobooth/tree/v4.5.1).
- [Capture v4.5.1](https://github.com/PhotoboothProject/photobooth/blob/v4.5.1/api/takePic.php).
- [Collage v4.5.1](https://github.com/PhotoboothProject/photobooth/blob/v4.5.1/lib/collage.php).
- [Đường in upstream cần tách khỏi final immutable](https://github.com/PhotoboothProject/photobooth/blob/v4.5.1/api/print.php).
- [Dockerfile upstream để tham chiếu môi trường](https://github.com/PhotoboothProject/photobooth/blob/v4.5.1/Dockerfile).

Các đường dẫn API/module và cấu hình mở rộng trong tài liệu là thiết kế sẽ triển khai, không phải endpoint đã tồn tại trong upstream.


