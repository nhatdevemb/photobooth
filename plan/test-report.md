# Báo cáo kiểm thử — Photobooth, import LUT và camera trình duyệt

Ngày kiểm chứng: 10/11/2026 (UTC+07:00). Upstream giữ v4.5.1, SHA `a8841409ed8d0db485191269d6c302505b67f64f`. Kết quả dưới đây bao gồm đợt bổ sung bố cục/template đợt import LUT qua web và đợt camera laptop/điện thoại qua trình duyệt.

## Môi trường đã chạy

Ubuntu 22.04.5, PHP 8.1.2, Python 3.10.12, Pillow 12.3.0, NumPy 2.2.6, PHPUnit 10.5.66, Node.js 22.23.2, Playwright 1.64.1/Chromium. Camera/máy in dùng mock; SQLite, pipeline ảnh, worker, HTTP, Apache và trình duyệt chạy thật.

Docker/CI nhắm Ubuntu 24.04, PHP 8.3 và Python 3.12. Chưa có bằng chứng CI từ xa hoặc Docker runtime tại máy này. Không dùng mock để đánh dấu đạt phần cứng.

## Kết quả

| Bộ kiểm thử | Kết quả thực tế |
| --- | --- |
| CI cục bộ (`bash custom/bin/ci-check.sh`) | PASS: PHP syntax, JS syntax, baseline, Python, PHPUnit, HTTP E2E, kiểm tra secrets tracked |
| Python pipeline | PASS: 32 test; gồm đủ 16 gói artwork, LUT hash và cường độ 0/80/100% |
| PHP backend/recovery/migration | PASS: 19 test, 438 assertions |
| HTTP E2E + worker daemon | PASS: auth/CSRF, session/revision/idempotency, capture/retake, render/finalize/in một bản, QR và guest isolation, revoke/delete |
| Browser bình thường | PASS: 11 test với UI/API/worker thật: 10 regression thường + 1 ca dùng 20 file DAEDAL thực tế qua PB_DAEDAL_LUT_DIR |
| Browser camera | PASS: 9 test bằng camera giả Chromium; bốn layout, reload/retake/QR/in mock, viewport điện thoại, đổi/tắt camera, lỗi quyền, ngắt camera và stale revision trong countdown |
| Camera test launcher HTTP/HTTPS | PASS: dữ liệu riêng, auth, cookie Secure qua HTTPS, upload multipart/replay, worker và byte ảnh gốc; certificate chỉ được trust trong client smoke |
| Browser UNKNOWN | PASS: 1 test; mock post-submit timeout, admin resolve không tự gửi in |
| Giao diện tại 1366 × 768 và 1920 × 1080 | PASS: hoàn tất cả bốn layout, không tràn ngang; nút tiếp tục màn filter và capture bốn ảnh nằm trong viewport; có screenshot |
| Apache host hai listener | PASS: config deploy chuyển vào thư mục/cổng tạm; upload multipart LUT, preview/kích hoạt, catalog + overlay có auth, capture bốn ảnh dùng LUT nhập, render, finalize, print, guest download đúng hash và chặn đường dẫn ngoài allowlist |
| Docker Compose syntax | PASS: `docker compose -f custom/deploy/docker-compose.yml config --no-env-resolution --quiet` |
| Docker image build/runtime | CHƯA KIỂM CHỨNG: Docker socket trả permission denied. Không coi Apache host là Docker PASS |
| Soak 30 phiên + backup/restore | PASS: 30 CLOSED qua cả bốn layout, 81 slot active, 93 ảnh gồm 12 retake, 30 final, 30 QR tải được, đúng 35 receipts (30 in + 5 in lại), 0 ảnh hỏng/slot thiếu; khôi phục 227/227 tệp và 3 LUT nhập; 12 phiên dùng LUT nhập ở cường độ 25/65/90% |
| Render benchmark | PASS: 8 lượt legacy postcard qua bốn LUT; p50 849 ms, p95 870 ms, max 871 ms. Không dùng số này làm benchmark đủ 16 artwork |
| OpenAPI/shell/whitespace | PASS: parse YAML, kiểm tra reference parameter, `bash -n`, `node --check`, `git diff --check` |

Số liệu soak/benchmark tại `plan/soak-report.json` và `plan/benchmark-report.json`. Lượt soak cuối dùng cấu hình khóa, snapshot artwork, phiên bản/hash/cường độ LUT và backup tài nguyên LUT nhập.

## Những điều được kiểm chứng

### Dữ liệu và API

- `LegacyMigrationTest`: tạo database theo migration 001 với ảnh/slot, render, final, print COMPLETED và token hợp lệ rồi chạy 002 và 003; phiên cũ giữ LUT v1.0.0/100% và hash; revision, photo IDs, hash final, tải token và trạng thái in giữ nguyên; `PRAGMA foreign_key_check` rỗng. Không chỉnh migration 001.
- `testEveryLayoutCapturesRendersAndFinalizesWithPinnedArtwork`: đủ 1/3/3/4 ảnh, từ chối slot ngoài capture_count, retake, giữ filter/template, từ chối render đổi LUT; final SHA-256 trùng render, đóng phiên thành công cho cả bốn layout.
- `testWizardGridConfigurationLocksOnFailedCaptureAndRestartReplays`: stale tab nhận REVISION_CONFLICT; replay configuration/capture không tạo ý định mới; restart trong lúc capture nhận SESSION_BUSY; capture thất bại vẫn khóa; thay cấu hình nhận CONFIGURATION_LOCKED; replay restart trả cùng phiên mới.
- `testLayoutSwitchKeepsStyleOrReportsMinimalFallback`: giữ Cute khi đổi sang single; khi biến thể Cute của grid bị tắt thì chọn Tối giản và trả template_fallback.
- `testAdminTemplateImportValidationActivationAndPinnedDisabledVersion`: operator bị từ chối; ZIP lỗi, traversal, file thực thi, URL ngoài, layout lạ, PNG sai định dạng/kích thước, symlink, >30 MiB ZIP, >60 MiB giải nén, quá entry và trùng version bị từ chối; draft không hiện cho operator, asset draft chỉ admin; activate/disable, phiên ghim mẫu disabled vẫn render; backup/restore giữ tài nguyên, trạng thái disabled và hash render; phiên mới không chọn được mẫu đã tắt.

### Ảnh và giao diện

- 16 gói được render kiểm tra số ô, vị trí, ảnh có vạch mép và crop so với `ImageOps.fit` độc lập, alpha RGBA, màu artwork opaque, kích thước, DPI/ICC, preview tương ứng JPEG in, hash deterministic và ảnh nguồn giữ nguyên.
- Overlay được ghép sau LUT; thay hash LUT hoặc sửa overlay bị phát hiện. Signature chứa hình học layout và hash background/overlay.
- Browser đi đủ năm bước với từng layout. Chụp chỉ chạy sau thao tác chủ động; reload không thêm ảnh. Lựa chọn khóa, retake giữ cấu hình, restart tạo phiên mới.
- Chặn request preview làm nút duyệt bị khóa; reload sau khi phục hồi mạng tiếp tục được. Duyệt rồi mới tạo QR/in; tải qua guest trùng byte final. Lịch sử in lại/tạo QR không thay phiên đang hoạt động.
- Admin nhập ZIP thật qua UI, xem bản ghép từ tài nguyên draft rồi kích hoạt; mẫu xuất hiện ở lựa chọn tương thích.
- Screenshot tại `custom/test-results/normal/`; trace/screenshot lỗi giữ trong `custom/test-results/`. Font, ảnh mẫu và artwork đều đóng gói cục bộ. Ảnh chân dung minh họa được tạo bằng imagegen, ghi rõ là ảnh mẫu; camera mock vẫn dùng fixture kiểm thử riêng.

### Import LUT và bảo toàn phiên bản

- `testLutImportDraftPreviewActivationAndIdempotency`: upload CUBE thành draft 80%, replay trả cùng bản, preview qua endpoint có auth, chỉnh tên/cường độ làm preview đổi, kích hoạt mới đưa vào catalog public.
- `testImportedLutVersionIsPinnedAcrossActivationRetakeAndRestore`: phiên ghim 80%/v1.0.0; Admin tạo v1.0.1, thay file và mức 20%, kích hoạt mới không đổi cấu hình cũ. Payload render đổi version bị từ chối. Render/finalize bản đã tắt vẫn thành công; retake/reopen giữ LUT, QR cũ tải đúng hash. Restore giữ file/metadata cũ và cường độ phiên.
- `testLutUploadSecurityStalePreviewAndImmutablePublishedVersion`: kiểm tra RBAC/CSRF, traversal/URL/phần mở rộng thực thi, UTF-8 lỗi, quá 5 MiB, sai grid/domain, thừa dòng, trùng file; tài nguyên draft bị chặn với operator/guest; preview/revision cũ và file bị sửa không thể kích hoạt; bản active không chỉnh trực tiếp, bản disabled không chọn cho phiên mới.
- Browser nhập batch tốt/lỗi/trùng, sửa tên và mức 65%, tìm filter mới rồi đi đủ capture/render/finalize/QR/print. Reload giữ filter đã khóa. Chặn ảnh preview làm activation bị khóa. Tạo phiên bản mới và thay file bằng multipart thật; thay mức 20→90 trong lúc request cũ đang chờ chỉ mở activation sau preview 90%.
- Ca DAEDAL tải nguồn vào thư mục tạm, không cài vào thư viện vận hành: upload đủ 12 Wedding + 8 Film & Mono, xem preview và kích hoạt từng file, reload còn đủ 20 filter; tìm kiếm và danh sách cuộn giữ nút tiếp tục trong viewport ở cả hai kích thước. Test thường không phụ thuộc mạng hoặc gói này.
- File/preview draft được lưu theo hash, không ghi đè byte snapshot cũ; backup/restore bao gồm luts/. Bốn preset đi kèm giữ nguyên. Pipeline vẫn áp creative LUT lên RGB đã chuẩn hóa sRGB theo quyết định sản phẩm; không nghiệm thu khớp Rec.709 trong phần mềm video.

### Các bảo vệ cũ còn qua regression

Revoke trong content job không tăng revision; lỗi commit sau publish đưa orphan vào quarantine và worker vẫn retake tiếp được; retention kiểm tra lại reprint vừa commit và giữ dữ liệu có UNKNOWN; restore không tự gửi lại print. Login, role/CSRF, token/revoke, integrity và guest isolation vẫn qua bộ kiểm thử.

## Giới hạn còn lại

Docker image chưa build/chạy được tại host do quyền socket; workflow và `custom/bin/docker-smoke.sh` đã có để chạy ở môi trường có daemon. H1–H3/HW-01…HW-05 tiếp tục PENDING HARDWARE: Canon R50/liveview/USB/flash, SELPHY CP1500/driver/màu/bản giấy, điện thoại LAN và rehearsal trên máy sự kiện. Windows packaging chưa nằm trong đợt này.

Không nghiệm thu nhiều bản mỗi request, tải ảnh nguồn, layout ngoài bốn mẫu, designer kéo thả hoặc thiết bị mới. Nâng cấp máy có dữ liệu theo `plan/template-packages.md` và `plan/lut-import.md`; các test chạy bằng DATA_DIR tạm, không thay dữ liệu sự kiện hiện có.

## Chạy lại

```bash
bash custom/bin/ci-check.sh
(cd custom && npm ci && npx playwright install --with-deps chromium)
(cd custom && npm run test:browser && npm run test:browser:unknown)
php custom/bin/soak-test.php
php custom/bin/benchmark-render.php
bash custom/bin/docker-smoke.sh
```

Ca DAEDAL chạy thêm bằng `(cd custom && PB_DAEDAL_LUT_DIR=/duong-dan/20-file-cube npm run test:browser)`. Dữ liệu nguồn chỉ nằm trong vùng tạm khi kiểm chứng và không được đóng gói trong repository.

Docker smoke tạo/dọn image/container/volume riêng `pb-smoke-*`. CI đã cấu hình chạy các bộ trên và upload browser artifacts khi thất bại; cấu hình workflow không đồng nghĩa CI đã chạy thành công từ xa.


## Camera laptop/điện thoại qua trình duyệt — 10/11/2026

`CAMERA_ADAPTER=browser` được bổ sung, không cần migration. Capture/retake nhận multipart JPEG tối đa 5 MiB và revision. Server staging ảnh dưới pending của job trong transaction enqueue/khóa, hash nội dung có trong idempotency và payload. Worker kiểm tra JPEG rồi đối chiếu hash trước khi publish ảnh gốc; lỗi retake giữ ảnh cũ. Countdown chạy ở client trước upload, worker không countdown lần hai. Lỗi quyền/mất camera trước upload không gửi capture request. Reload không mở camera hoặc chụp tự động.

Lượt kiểm chứng camera chạy lại 32 Python, 19 PHPUnit/438 assertions, HTTP E2E, 10 browser regression và 1 UNKNOWN; 8 ca camera dùng video giả Chromium và smoke HTTP/HTTPS chạy mới. Ca 20 DAEDAL, Apache host, soak/backup và benchmark trong các hàng trên là bằng chứng của đợt LUT trước, không chạy lại trong đợt camera. Docker và webcam/Android/iOS vật lý chưa được nghiệm thu.

Công cụ `custom/bin/camera-test.py` khởi chạy PHP/worker riêng, dữ liệu ngoài project, máy in mock và tài khoản test với mật khẩu ngẫu nhiên. HTTPS tùy chọn dùng certificate do người test cung cấp; không tự thay trust store. Hướng dẫn chi tiết và lệnh kiểm thử ở [webcam-testing.md](webcam-testing.md). Screenshot camera tại `custom/test-results/camera/`.


## Sửa preview camera bị nhỏ — 10/11/2026

Tái hiện bằng Playwright: khung preview chỉ chiếm 32% cột trái ở 1366 × 768 do giới hạn chiều cao `100vh - 560px` kết hợp aspect-ratio 4:3 làm co cả chiều ngang. Bản sửa đặt khung rộng hết cột, tính chiều cao từ khoảng trống sau toolbar/camera controls/footer và giữ mức tối thiểu hữu dụng trên cửa sổ thấp. Video vẫn object-fit contain; vùng crop tính theo video thật, giữ tỷ lệ ảnh nguồn. Hàng camera controls được thu gọn; bố cục desktop chia lại cột cho preview và slots.

Kiểm chứng mới: 9 browser camera tests và 10 browser regression tests. Test preview đo chiều rộng khung ít nhất 95% cột và phần video nhìn thấy ít nhất 70% cột tại 1366 × 768, 1920 × 1080, 1825 × 838, 1216 × 559; hai kích thước mục tiêu giữ nút tiếp tục trong viewport. Luồng mobile 390 × 844, đủ bốn layout, retake/reload/QR/in mock tiếp tục qua. Screenshot trong `custom/test-results/camera/webcam-camera-preview-fill-f257d-desktop-and-short-viewports/`.


## HTTP localhost và HTTPS LAN đồng thời — 10/11/2026

Launcher có `--http-port` (mặc định 8080 khi có certificate); PHP operator bind loopback ở cổng này, HTTPS LAN dùng cổng `--port` và proxy vào cùng PHP. Một database, tài khoản và worker; mỗi origin đăng nhập riêng. Trình duyệt laptop tự mở HTTP local, QR giữ base URL HTTPS LAN.

Smoke đã chạy: HTTP-only và HTTP+HTTPS đồng thời, đăng nhập hai phía, capture HTTPS rồi capture HTTP, theo dõi cùng phiên/job qua giao thức còn lại, replay capture liên giao thức không tạo job mới, byte ảnh gốc giữ nguyên, cookie Secure qua HTTPS và cả hai listener dừng khi kết thúc. Startup guard qua duplicate worker, cổng HTTP hoặc HTTPS bận và kiểm tra mật khẩu không bị thay đổi khi khởi chạy bị từ chối. CLI từ chối cổng trùng/sai phạm vi và `--http-port` không có TLS. Kiểm tra này dùng dữ liệu tạm và certificate chỉ được trust trong client test; không nghiệm thu camera vật lý.
