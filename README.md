
# Photobooth — Operator Edition (Bản Vận Hành Sự Kiện)

> **Upstream Baseline:** Pinned to [`PhotoboothProject/photobooth` `v4.5.1`](https://github.com/PhotoboothProject/photobooth/tree/v4.5.1) (`a8841409ed8d0db485191269d6c302505b67f64f`). Toàn bộ phần mở rộng Operator Edition nằm độc lập trong thư mục [`custom/`](custom/) và tài liệu kế hoạch/kiến trúc tại [`plan/`](plan/).

**Bản thử Windows với Canon EOS R10:** đã có bộ cài 0.1.0, dùng digiCamControl làm cầu nối USB cho live view và chụp JPEG; không cần tự cài Electron hoặc lấy Canon EDSDK. Máy in dùng mô phỏng; R10 thật chưa nghiệm thu. Xem [hướng dẫn Windows](custom/desktop/README.md), [báo cáo kiểm thử](plan/windows-test-report.md) và [kế hoạch nghiệm thu](plan/windows-app.md).

## 1. Tổng quan Kiến trúc (Operator Edition)

Hệ thống **Photobooth Operator Edition** là giải pháp photobooth có nhân viên hỗ trợ (operator-assisted), hoạt động **100% offline trên mạng LAN nội bộ**, được thiết kế xoay quanh sự ổn định cấp độ sự kiện:

- **Quản lý phiên chặt chẽ (`Session State Machine` + Optimistic Concurrency `revision`):** Mỗi thời điểm chỉ có tối đa 1 phiên đang mở; mọi thao tác thay đổi trạng thái yêu cầu `revision` và `Idempotency-Key` để chống nhấn đúp, mở 2 tab hoặc rớt mạng retry.
- **Bốn bố cục, 1–4 ảnh & Chụp lại từng khung độc lập:** Giữ nguyên ảnh cũ và bản render hợp lệ nếu quá trình chụp lại gặp sự cố phần cứng (`IT-05`).
- **Xử lý ảnh màu chuẩn bằng Python (`Pillow` + `NumPy`):** Tự động xoay EXIF, center-crop theo từng ô, áp 3D LUT `.cube` (`Original`, `Korean Soft`, `Film 400`, `Vintage Fade`) lên ảnh nguồn rồi ghép nền và overlay. Thành phẩm **100 × 148 mm, 1181 × 1748 px, 300 DPI, sRGB**. Có 16 template ban đầu và nhập ZIP qua Admin.
- **Kiểm soát lệnh in độc lập & Trạng thái `UNKNOWN`:** Luôn cố định `copies = 1` cho mỗi lần xác nhận in; kiểm tra SHA-256 trước khi gửi in; ghi trạng thái `SUBMITTING` trước khi gọi adapter; nếu mất phản hồi sau khi gửi sẽ chuyển sang `UNKNOWN` và **tuyệt đối không tự động in lại** để tránh tốn giấy/mực, chờ Admin đối chiếu thực tế.
- **Cô lập 2 cổng mạng (Dual-Listener Isolation):**
  - **Operator Listener (`127.0.0.1:8080`):** Giao diện tiếng Việt dành cho nhân viên vận hành & Admin (`/`, `/custom/api/*`).
  - **Guest LAN Listener (`0.0.0.0:8081`):** Chỉ phục vụ duy nhất `GET /share/{token}` và `GET /share/{token}/download` để khách quét mã QR tải ảnh qua Wi-Fi sự kiện; chặn toàn bộ `/custom/api/*`, `/admin/*`, `/` với `403 FORBIDDEN`.

---

## 2. Hướng dẫn Cài đặt & Khởi chạy Nhanh (Chế độ Mô phỏng — Mock Mode)

Không cần máy ảnh DSLR hay máy in thật để chạy thử và kiểm thử toàn bộ phần mềm:

```bash
# 1. Cài đặt thư viện Python và Composer (trong thư mục custom/)
cd custom
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.lock
composer install

# 2. Tạo tệp cấu hình môi trường từ mẫu
cp .env.example .env

# Chỉnh .env: SHARE_BASE_URL là IP LAN của máy,
# SHARE_SIGNING_KEY lấy từ: openssl rand -hex 32

# 3. Chạy migration khởi tạo cơ sở dữ liệu SQLite (WAL mode)
php bin/migrate.php

# 4. Tạo tài khoản Nhân viên vận hành (operator) và Quản trị viên (admin)
php bin/manage-user.php create --username=operator1 --password="OperatorPass123!" --role=operator
php bin/manage-user.php create --username=admin --password="AdminPass123!" --role=admin

# Trở về thư mục gốc trước khi chạy các lệnh bên dưới
cd ..
```

### Khởi chạy 3 tiến trình dịch vụ (Terminal hoặc systemd):

Trong mỗi terminal chạy worker hoặc kiểm thử, kích hoạt Python bằng `source custom/.venv/bin/activate` từ thư mục gốc repository. Nếu chạy bằng systemd, đặt PATH chứa virtual environment trong cấu hình service. Docker đã cấu hình PATH này trong image.

```bash
# Tiến trình 1: Worker xử lý nền đơn thể (chụp ảnh, render LUT, gửi lệnh in)
php custom/bin/worker.php

# Tiến trình 2: Cổng giao diện Bàn Vận Hành (chỉ lắng nghe loopback 127.0.0.1:8080)
PHOTOBOOTH_LISTENER_MODE=operator php -d upload_max_filesize=5M -d post_max_size=32M -S 127.0.0.1:8080 custom/public/index.php

# Tiến trình 3: Cổng mạng khách tải ảnh QR (lắng nghe LAN 0.0.0.0:8081)
PHOTOBOOTH_LISTENER_MODE=guest php -S 0.0.0.0:8081 custom/public/guest.php
```

Mở trình duyệt tại **`http://127.0.0.1:8080`** và đăng nhập bằng tài khoản `operator1` hoặc `admin`.

### Chạy bằng Docker Compose trên Ubuntu

Từ thư mục gốc repository, tạo `custom/.env` từ mẫu nếu chưa có. Cấu hình `SHARE_BASE_URL` theo IP LAN thực tế và tạo `SHARE_SIGNING_KEY` riêng bằng `openssl rand -hex 32`. Không dùng mật khẩu ví dụ để vận hành sự kiện.

```bash
docker compose -f custom/deploy/docker-compose.yml up --build -d
# CLI ghi dữ liệu cùng UID với web và worker:
docker compose -f custom/deploy/docker-compose.yml exec --user www-data operator-web \
  php custom/bin/manage-user.php create --username=admin --password='<mat-khau-rieng>' --role=admin
```

Mở UI tại `http://127.0.0.1:8080`. Cổng 8080 chỉ publish trên loopback của host; cổng 8081 phục vụ khách trong LAN. Apache lắng nghe trên interface container để Docker chuyển tiếp kết nối được. Image tự cài PHP dependency từ `composer.lock` và Python dependency từ `requirements.lock`; không cần cài thư viện trên host. Worker chờ web hoàn tất migration và healthcheck trước khi chạy. Dữ liệu lưu trong named volume; cập nhật image không xóa volume.

Nếu thu hồi QR khi capture/retake/render đang chạy, API trả `409 SESSION_BUSY`: đợi job hoàn tất, tải revision mới rồi thử lại. Ảnh chưa commit sau lỗi capture được cách ly để lần retake tiếp theo không cần restart worker.

---

## 3. Kịch bản Demo Vận hành (Demo Script)

1. **Đăng nhập và tạo phiên:** Mở `http://127.0.0.1:8080`. Banner ghi rõ chế độ mock; health, lịch sử và quản trị nằm ngoài thanh năm bước.
2. **Filter → Bố cục → Template:** Chọn bốn filter, bốn bố cục (Toàn khung / Dải ảnh dọc / Postcard ba ảnh / Lưới 2×2), rồi mẫu tương thích. Ảnh chân dung trong phần lựa chọn là ảnh mẫu AI đóng gói cục bộ. Có thể quay lại đổi trước khi chụp; đổi bố cục giữ phong cách khi có biến thể tương ứng.
3. **Chụp ảnh:** Bấm **Bắt đầu chụp** để đếm ngược và chụp tuần tự 1–4 ảnh. Có thể chụp hoặc retake từng ô. Filter, bố cục và template khóa ngay khi nhận yêu cầu chụp đầu tiên, kể cả job thất bại. **Bắt đầu lại** cần xác nhận và tạo phiên mới. Reload phục hồi phiên và job, không tự chụp thêm.
4. **Xem lại:** Khi đủ ảnh, bấm **Xem ảnh ghép** để tự enqueue render. Nút **Duyệt ảnh và tạo QR** chỉ bật sau khi đúng preview tải thành công. Final được sao chép byte từ JPEG render đã duyệt; QR chỉ tải JPEG ghép cuối.
5. **In và hoàn tất:** Luôn xác nhận đúng **1 bản** trước khi gửi in. Nhấn **Hoàn tất** để đóng phiên. Retake sau finalize mở lại phiên với thiết kế giữ nguyên; final và QR đã phát hành tiếp tục trỏ ảnh cũ. In lại từ lịch sử không thay phiên đang mở.

Admin vào **Quản lý template**, nhập ZIP nháp, xem bản ghép với ảnh mẫu rồi kích hoạt. Tắt mẫu chỉ ngăn chọn cho phiên mới; phiên đã ghim vẫn render được. Xem [định dạng gói và quy trình nâng cấp](plan/template-packages.md).

Admin vào **Quản lý filter** để chọn tối đa 30 file `.cube` mỗi lượt (5 MiB/file). Mỗi file là một bản nháp, cường độ mặc định 80%; xem ảnh trước/sau rồi kích hoạt. Đổi file hoặc cường độ đã xuất bản bằng **Tạo phiên bản nháp**; phiên đã ghim giữ nguyên bản cũ. Bước Filter có tìm kiếm và danh sách cuộn. Xem [hướng dẫn import LUT](plan/lut-import.md).

Test bằng camera laptop: chạy `./start-camera.sh` để tự bật web/worker và mở `http://localhost:8090`, dùng tài khoản terminal in ra và bấm **Bật camera** tại bước 4. Công cụ dùng database test riêng và máy in mock; nhấn `Ctrl+C` để dừng. Điện thoại có thể mở giao diện qua HTTPS để dùng camera trước/sau; xem [hướng dẫn camera laptop/điện thoại và thiết lập HTTPS](plan/webcam-testing.md). Chế độ mới là `CAMERA_ADAPTER=browser`; webcam/điện thoại vật lý chưa được xác nhận bằng kiểm thử trên thiết bị thật.

Khi có certificate, cùng một lệnh có thể chạy cả HTTP localhost và HTTPS LAN: dùng `--port 8091 --http-port 8090` cùng `--lan-ip`, `--cert`, `--key`. Laptop mở `http://localhost:8090`, điện thoại mở HTTPS LAN ở cổng 8091; cả hai dùng chung phiên và worker.

---

## 4. Công cụ Quản trị CLI & Xử lý Sự cố

| Lệnh CLI | Chức năng |
|---|---|
| `php custom/bin/migrate.php` | Thực thi SQLite schema migrations (`001_initial_schema.sql`, `002_layouts_and_templates.sql`, `003_lut_catalog.sql`). |
| `php custom/bin/manage-user.php create --username=<u> --password=<p> --role=operator\|admin` | Tạo hoặc cập nhật mật khẩu/vai trò tài khoản. |
| `php custom/bin/manage-user.php revoke-all-shares` | Thu hồi toàn bộ mã QR đang hoạt động (khi đổi khóa ký `SHARE_SIGNING_KEY`). |
| `php custom/bin/worker.php [--once]` | Chạy daemon Worker xử lý nền (có khóa `flock` chống chạy trùng). |
| `php custom/bin/maintenance.php` | Dọn dẹp phiên quá hạn lưu trữ (`7 ngày`), thu hồi QR hết hạn (`24 giờ`), xoay vòng log. |
| `php custom/bin/backup.php --target=/path/to/backup` | Sao lưu nóng SQLite (`VACUUM INTO`) kèm ảnh, template và LUT nhập thêm, trạng thái kích hoạt trong SQLite và `backup-manifest.json` (SHA-256). |
| `php custom/bin/restore.php --source=/path/to/backup` | Kiểm tra SHA-256, phục hồi dữ liệu và chặn tự động in lại các lệnh in đang dở (`IT-14`). |

### Quy trình xử lý khi gặp lệnh in `UNKNOWN`
Khi mất kết nối máy in ngay sau khi gửi lệnh (`SUBMITTING`), hệ thống đánh dấu yêu cầu in là `UNKNOWN` và hiển thị cảnh báo đỏ trên thanh trạng thái:
1. Nhân viên kiểm tra khay giấy thực tế của máy in Canon SELPHY CP1500.
2. Đăng nhập quyền **Admin**, nhấn nút **`Đối chiếu UNKNOWN`** trên lệnh in đó:
   - Chọn `Đã ra giấy thành công (observed_completed)` nếu bản in đã ra giấy.
   - Chọn `Chưa ra giấy / Lỗi kẹt giấy (confirmed_not_printed)` nếu chưa ra giấy, sau đó mới nhấn **`In lại (Reprint 1 bản)`**.

---

## 5. Chạy Kiểm thử, Benchmark & Soak Test

```bash
# Chạy toàn bộ kiểm thử tự động (Syntax + Pytest + PHPUnit + Live E2E)
bash custom/bin/ci-check.sh

# Browser E2E thật (Node.js 22, Chromium)
(cd custom && npm ci && npx playwright install --with-deps chromium && npm run test:browser && npm run test:browser:unknown)

# Build từ source sạch và test hai listener Apache trong container
# Chỉ tạo/xóa container, image và volume riêng mang tên pb-smoke-...
bash custom/bin/docker-smoke.sh

# Chạy benchmark tốc độ ghép & áp 3D LUT (xuất báo cáo ra plan/benchmark-report.json)
php custom/bin/benchmark-render.php

# Chạy kiểm thử tải 30 phiên liên tiếp & diễn tập Sao lưu/Phục hồi (xuất ra plan/soak-report.json)
php custom/bin/soak-test.php
```

Playwright lưu trace/screenshot lỗi trong `custom/test-results/`. CI chạy thêm browser và Docker smoke; các bước này tách khỏi `ci-check.sh`.

Xem chi tiết kết quả kiểm thử tại [`plan/test-report.md`](plan/test-report.md).

---

## 6. Giới hạn Hiện tại & Lộ trình Tích hợp Phần cứng (`H1` – `H3`)

- Các adapter phần cứng thật ([`Gphoto2CameraAdapter.php`](custom/src/Camera/Gphoto2CameraAdapter.php) cho `H1` và [`CupsPrintAdapter.php`](custom/src/Printer/CupsPrintAdapter.php) cho `H2`) đã được lập trình sẵn theo đúng giao diện `CameraAdapterInterface` và `PrintAdapterInterface`.
- Các bài kiểm thử phụ thuộc thiết bị vật lý (`TC-20`..`TC-24`, `HW-01`..`HW-05`: DSLR thật qua cáp USB, máy in Canon SELPHY CP1500 thật, và Wi-Fi AP tại sự kiện) hiện được ghi nhận trung thực ở trạng thái **`PENDING HARDWARE`** trong [`plan/test-report.md`](plan/test-report.md) cho tới khi kết nối thiết bị thật.

---

# Photobooth v4 (Upstream Documentation)

![](resources/img/logo/banner.png)

A Photobooth web interface for Linux and Windows.

Photobooth was initially developped by Andre Rinas to use on a Raspberry Pi, you can find his source [here](https://github.com/andreknieriem/photobooth).  
In 2019 Andreas Blaesius picked up the work and continued to work on the source.  
With the help of the community Photobooth grew to a powerfull Photobooth software with a lot of features and possibilities.

_(The full changelog can be found on [https://photoboothproject.github.io](https://photoboothproject.github.io).)_

[![Chat on Telegram](https://img.shields.io/badge/Chat%20on-Telegram-blue.svg)](https://t.me/PhotoboothGroup)  

[![Translate on Crowdin](https://img.shields.io/badge/Traslate%20on-Crowdin-green.svg)](https://crowdin.com/project/photobooth)  

_Latest stable release:_  
[![Lint](https://github.com/PhotoboothProject/photobooth/workflows/Lint/badge.svg?branch=stable4)](https://github.com/PhotoboothProject/photobooth/actions?query=branch%3Astable4+workflow%3ALint)
[![gulp-sass](https://github.com/PhotoboothProject/photobooth/workflows/gulp-sass/badge.svg?branch=stable4)](https://github.com/PhotoboothProject/photobooth/actions?query=branch%3Astable4+workflow%3Agulp-sass)
[![Build](https://github.com/PhotoboothProject/photobooth/workflows/Build/badge.svg?branch=stable4)](https://github.com/PhotoboothProject/photobooth/actions?query=branch%3Astable4+workflow%3ABuild)

_Latest development version:_  
[![Lint](https://github.com/PhotoboothProject/photobooth/workflows/Lint/badge.svg?branch=dev)](https://github.com/PhotoboothProject/photobooth/actions?query=branch%3Adev+workflow%3ALint)
[![gulp-sass](https://github.com/PhotoboothProject/photobooth/workflows/gulp-sass/badge.svg?branch=dev)](https://github.com/PhotoboothProject/photobooth/actions?query=branch%3Adev+workflow%3Agulp-sass)
[![Build](https://github.com/PhotoboothProject/photobooth/workflows/Build/badge.svg?branch=dev)](https://github.com/PhotoboothProject/photobooth/actions?query=branch%3Adev+workflow%3ABuild)


## :camera: Screenshots

![](https://raw.githubusercontent.com/PhotoboothProject/PhotoboothProject.github.io/master/resources/img/start.png)


## :gear: Supported Platforms and Cameras

| Hardware-Platform  | Software-Platform                  | Supported Cameras                                                                                                                                                                     |
|--------------------|------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Raspberry Pi 3 / 4 / 5 | Raspberry Pi OS 64bit Bullseye / Bookworm     | [Camera Modules](https://www.raspberrypi.com/documentation/accessories/camera.html), [gphoto2 DSLR](http://www.gphoto.org/proj/libgphoto2/support.php), webcam _*2_ |
| Raspberry Pi 3 / 4 / 5 | Raspberry Pi OS 32bit Bullseye / Bookworm _*1_ | [Camera Modules](https://www.raspberrypi.com/documentation/accessories/camera.html), [gphoto2 DSLR](http://www.gphoto.org/proj/libgphoto2/support.php), webcam _*2_ |
| Generic PC         | Debian/Ubuntu                      | [gphoto2 DSLR](http://www.gphoto.org/proj/libgphoto2/support.php), webcam _*2_                                                                                      |
| Generic PC         | Windows                            | [digiCamControl](http://digicamcontrol.com/), webcam _*2_                                                                                                           |

_*1 On Raspberry Pi OS 32bit Bullseye you **must** add `arm_64bit=0` to your `/boot/config.txt` and reboot once before installing Photobooth._  
_The Raspberry Pi foundation uses a 64bit kernel while the system is 32bit. The Installation fails because the v4l2loopback module can't be compiled for a 32bit OS while using a 64bit kernel._

_*2 Capture from webcam is possible e.g. using [fswebcam](https://www.sanslogic.co.uk/fswebcam/), else it only works on access via [http://localhost](http://localhost)_


## :gear: Prerequisites

| Software          | Tested version         | Note
|-------------------|------------------------|-------------------------------------------------------------------------------------------------------------------------------------|
| Node.js           | v18                    | Currently only v18 is tested. Our installer will check your Node.js version and suggest an update/downgrade if needed. Versions below v18 aren't supported anymore. |
| npm               | >=v9.6.0               |                                                                                                                                     |
| php               | v7.4, v8.0, v8.1       |                                                                                                                                     |
| Webserver         |                        | Apache, Nginx or lighttpd is needed. By default Apache webserver is installed by our installer.                                     |


## :heart_eyes: Features

- Works on Windows and Linux.
- Images are processed with GD.
- Photobooth caches all generated QR-Codes, Thumbnails and Prints.
- Updated [PhotoSwipe Gallery](https://github.com/PhotoboothProject/PhotoSwipe)
- Standalone Gallery ([localhost/gallery.php](http://localhost/gallery.php)).
- Slideshow via Gallery or standalone Gallery at [localhost/slideshow](http://localhost/slideshow).
- Access login via [localhost/login](http://localhost/login) instead [localhost/login.php](http://localhost/login.php).
- Offline manual with settings explained at [localhost/manual](http://localhost/manual).
- Offline FAQ at [localhost/manual/faq.php](http://localhost/manual/faq.php).
- Disk usage page, access via admin panel or directly at [localhost/admin/diskusage.php](http://localhost/admin/diskusage.php).
- Settings can be changed via Admin Panel at ([localhost/admin](http://localhost/admin)):
  - Multi-language support:
    - [Translate on Crowdin](https://crowdin.com/project/photobooth)
  - Login to protect Admin Panel, Start page and/or Manual & FAQ can be enabled.
  - Hardware Button functionality, supports two separate modes of operation (select via admin panel):
    - **Button Mode**: Distinct hardware buttons can be connected to distinct GPIOs. Each button will trigger a separate functionality (i.e. take photo).
    - **Rotary Mode**: A rotary encoder connected to GPIOs will drive the input on the screen. This enables to use the rotary to scroll through the Photobooth UI buttons, and click to select actions.
  - Gallery:
    - Order pictures in gallery ascending oder descending by picture age.
    - Hide the gallery.
    - allow to adjust PhotoSwipe Gallery config via Adminpanel, also allow to use some PhotoSwipe functions and make more PhotoSwipe settings available (settings explained inside the manual):
      - Mouse click on image should close the gallery (enable/disable)
      - Close gallery if clicked outside of the image (enable/disable)
      - Close picture on page scroll (enable/disable)
      - Close gallery when dragging vertically and when image is not zoomed (enable/disable)
      - Show image counter (enable/disable)
      - Show PhotoSwipe fullscreen button (enable/disable)
      - Show PhotoSwipe zoom button (enable/disable)
      - PhotoSwipe history module (enable/disable)
      - Pinch to close gallery (enable/disable)
      - Toggle visibility of controls/buttons by tap (enable/disable)
      - allow to adjust PhotoSwipe background opacity (0-1)
      - Loop images (enable/disable)
      - Slide transition effect (enable/disable)
      - Swiping to change slides (enable/disable)
    - gallery: button to delete an image, enable by default
    - Choose thumbnail size:
      - XS = max 360px
      - S = max 540px
      - M = max 900px
      - L = max 1080px
      - XL = max 1260px"
  - Choose between date-formatted or random image names.
  - Choose an image filter after taking a picture.
  - QR-Code to allow downloading pictures from your Photobooth.
  - Pictures can be directly downloaded from the gallery.
  - Print feature:
    - Optional: Print a frame on your picture
    - Optional: Print text on your picture.
    - Optional: Print QR-Code on the right side of your picture.
    - Optional: Auto print function
    - Optional: allow to delay auto print
    - Optional: allow to adjust time "Started printing! Please wait..." is visible
    - Optional: allow to trigger print via defined key
    - Optional: options to show the print button independent (e.g. can be only visible on gallery)
  - Pictures can be sent via e-mail.
  - Different Live Preview options
  - Event specific (e.g. wedding, birthday) config to show a symbol (e.g. heart)
    between some text on the start page.
  - Chroma keying
  - Photo collage function: take pictures in a row with or without
    interruption and let it generate a collage out of it. Choose between different collage layouts!
  - Save pictures with a Polaroid effect.
  - Adjust take picture and print commands.
  - Adjust the style of Photobooth via admin panel.
- ... _And many more options to adjust and style Photobooth for your personal needs_

## :wrench: Installation & Troubleshooting

Please follow the installation instructions
[here](https://photoboothproject.github.io/INSTALL) to setup
Photobooth.

If you're having trouble or questions please take a look at our
[FAQ](https://photoboothproject.github.io/FAQ_MENU)
before opening a new issue.

For local testing and development, the docker setup can be used with `docker-compose up --build`.

### :mag: Changelog

Please take a look at the changelog available on [https://photoboothproject.github.io](https://photoboothproject.github.io).

### :warning: Security advice

Photobooth is not hardened against any kind of *targeted* attacks.
It uses user defined commands for tasks like taking photos and is allowed to replace its own files for easy updating.
Because of this it's not advised to operate Photobooth in an untrusted network and 
**you should absolutely not make Photobooth accessible through the internet without heavy modifications!**

### :copyright: License

Photobooth source is licensed under the MIT license.  
  
Once build, Photobooth incorporates several parts and optimizations that are covered by a different license which could apply to Photobooth as well.  
All dependencies include their respective LICENSE files.

### :tada: Donation

If you like our work and consider a donation, we have to tell you that we don't accept any money. We're happy about every contribution to this project and strive to make it better every day. Just get in touch with us on [Telegram](https://t.me/PhotoboothGroup) to say thank you or help us find ways to improve.

If you still want to donate money to make us happy: consider a donation to the [seal station Norddeich](https://seehundstation-norddeich.de) ([donate via Paypal](https://paypal.me/seehundstation)).

Thanks for reading!

### :mortar_board: Tutorial

[Raspberry Pi Weddingphotobooth (german)](https://www.andrerinas.de/tutorials/raspberry-pi-einen-dslr-weddingphotobooth-erstellen.html)  
[Raspberry Pi Fotobox für Hochzeiten und Geburtstage (German)](https://www.dennis-henss.de/2020/01/25/raspberry-pi-fotobox-fuer-hochzeiten-und-geburtstage)  
[Raspberry Pi Photobooth in a classic vintage plate camera](https://florianmuller.com/raspberry-pi-photobooth-in-a-classic-vintage-plate-camera)  

### :clap: Contributors and thanks to

- [dimsemenov](https://github.com/dimsemenov/photoswipe)
- [t0k4rt](https://github.com/t0k4rt/phpqrcode)
- [nihilor](https://github.com/nihilor/photobooth)
- [vrs01](https://github.com/vrs01)
- [F4bsi](https://github.com/F4bsi)
- [got-x](https://github.com/got-x)
- [RaphaelKunis](https://github.com/RaphaelKunis)
- [andi34](https://github.com/andi34)
- [Norman-Sch](https://github.com/Norman-Sch)
- [marcogracklauer](https://github.com/marcogracklauer)
- [dnks23](https://github.com/dnks23)
- [tobiashaas](https://github.com/tobiashaas)
- Martin Kaiser-Kaplaner
- [MoJou90](https://github.com/MoJou90)
- [Reinhard Reberning](https://www.reinhard-rebernig.at/website/websites/fotokasterl)
- [Steffen Musch](https://github.com/Nie-Oh)
- [flighter18](https://github.com/flighter18)
- [thymon13](https://github.com/thymon13)
- [vdubuk](https://github.com/vdubuk)
- [msmedien](https://github.com/msmedien)
- [sualko](https://github.com/sualko)
- [rawbertp](https://github.com/rawbertp)
- [jacques42](https://github.com/jacques42)
- [poldixd](https://github.com/poldixd)
- [TheVaan](https://github.com/TheVaan)
- [Andreas Remdt](https://andreasremdt.com)
- [philippselle](https://github.com/philippselle)
- [Natalie Stroud](https://github.com/stroudn1)
- [jarettrude](https://github.com/jarettrude)
- [alzo425](https://github.com/alzo425)
- [KH404](https://github.com/KH404)
- [joiyco](https://github.com/joiyco)
- [EccoB](https://github.com/EccoB)
- [couz74](https://github.com/couz74)
- [thatonedude3470](https://github.com/thatonedude3470)
- [Christian Tarne](https://github.com/Metropo)
- [DeNeD1](https://github.com/DeNeD1)
- [DIY89](https://github.com/DIY89)
- [mhellmeier](https://github.com/mhellmeier)
- [Uwe Pieper](https://github.com/up-87)
- [s-dinda](https://github.com/s-dinda)
- [Moarqi](https://github.com/Moarqi)
- [kreativmonkey](https://github.com/kreativmonkey)
- [Khaos66](https://github.com/Khaos66)
- [DJ DT-Sommer](https://dt-sommer.jimdofree.com)
- [ledsi](https://github.com/ledsi)
- [Spike-78](https://github.com/Spike-78)
- [Mathias Fiege](https://www.webpension.de/)

