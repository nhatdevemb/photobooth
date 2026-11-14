# Test bằng camera laptop hoặc điện thoại

`CAMERA_ADAPTER=browser` dùng camera trên **thiết bị đang mở giao diện operator**. Có xem trực tiếp, chọn camera, đổi trước/sau, countdown và retake. Không yêu cầu microphone. Muốn dùng camera điện thoại, mở giao diện trên chính điện thoại; bản này chưa truyền khung ngắm điện thoại sang màn hình laptop để điều khiển từ xa.

## 1. Test nhanh trên laptop Ubuntu

Cần PHP với các extension của project, Composer dependencies và Python dependencies như hướng dẫn README. Tại thư mục project:

Cách bật nhanh (tự mở trình duyệt, cổng 8090):

```bash
cd /home/ray/template/photobooth
./start-camera.sh
```

Script chạy được từ thư mục khác bằng đường dẫn đầy đủ `/home/ray/template/photobooth/start-camera.sh`. Giữ terminal mở để xem tài khoản và mật khẩu, nhấn `Ctrl+C` để dừng. Đổi cổng bằng `./start-camera.sh --port 8091`. Các tùy chọn `--data-dir`, `--lan-ip`, `--cert`, `--key`, `--http-port` được chuyển tiếp cho công cụ bên dưới. Khi có certificate, `--port` là cổng HTTPS và `--http-port` là cổng HTTP localhost (mặc định 8080); hai cổng phải khác nhau. Terminal không có desktop sẽ in URL để mở thủ công.

Nếu báo `WORKER_ALREADY_RUNNING`, phiên cũ vẫn dùng chung thư mục dữ liệu: quay lại terminal cũ, nhấn `Ctrl+C`, rồi chạy lệnh mới. Chỉ đổi cổng không tạo được worker thứ hai trên cùng dữ liệu. Script kiểm tra khóa trước khi đặt mật khẩu nên lần chạy trùng bị từ chối giữ nguyên tài khoản đang dùng. `PORT_IN_USE` nghĩa là cổng HTTPS đã có dịch vụ khác; dừng đúng dịch vụ đó hoặc chọn cổng khác. Khi khởi chạy lại thành công, mật khẩu ngẫu nhiên mới vẫn được in trong terminal.

Hoặc chạy công cụ Python trực tiếp (cổng mặc định 8080):

```bash
cd /home/ray/template/photobooth
python3 custom/bin/camera-test.py
```

Mở `http://localhost:8080` trên laptop có camera. Terminal in tài khoản `camera-test` và mật khẩu ngẫu nhiên mới mỗi lần chạy. Chọn filter → bố cục → template → tại bước Chụp ảnh bấm **Bật camera**, cho phép quyền truy cập rồi **Bắt đầu chụp**. Có thể chọn camera trong danh sách hoặc bấm **Đổi trước / sau**. Laptop chỉ có một camera có thể vẫn giữ camera đó khi đổi trước/sau.

Lệnh này tự khởi chạy worker, operator và guest listener; máy in luôn **mock**, không in giấy thật. Dữ liệu test mặc định ở `~/.cache/photobooth/camera-test`, giữ lại khi dừng để xem lịch sử. Không sửa `custom/.env` hoặc dùng database sự kiện hiện tại. `--data-dir /duong/dan/thu-muc-test` cho phép đổi nơi lưu; từ chối thư mục có dữ liệu không mang dấu nhận diện của công cụ. `Ctrl+C` dừng các tiến trình con. Nếu cổng 8080 bận, thêm `--port 8090`.

Khung ngắm hiển thị trực tiếp màu filter 3D LUT `.cube` theo thời gian thực (WebGL2 3D texture kèm fallback 2D CPU), mặc định lật gương cả khung ngắm lẫn ảnh chụp (có nút **Lật gương: Bật / Tắt** và tự động tắt lật gương khi chuyển sang camera sau); nét đứt thể hiện đúng vùng center-crop của ô đang chụp. Khi bấm chụp, trình duyệt vẫn xuất ảnh gốc từ luồng video thô (tối đa cạnh dài 2560 px, chất lượng 95%, tối đa 5 MiB) để gửi về server, giúp pipeline Python áp LUT chuẩn sRGB/300 DPI đúng 1 lần mà không bị áp chồng filter. Độ phân giải thực tế phụ thuộc camera và trình duyệt; webcam độ phân giải thấp có thể cho bản in kém nét hơn DSLR.

## 2. Test trên điện thoại qua Wi-Fi

Điện thoại phải cùng mạng với Ubuntu và truy cập được IP của máy Ubuntu. Camera trình duyệt yêu cầu **HTTPS được thiết bị tin cậy** khi dùng IP LAN; `http://192.168...` không đủ. `localhost` trên điện thoại là chính điện thoại. Xem [yêu cầu secure context của getUserMedia](https://developer.mozilla.org/en-US/docs/Web/API/MediaDevices/getUserMedia).

Dùng certificate được tin cậy sẵn của bạn, hoặc tạo certificate phát triển bằng [mkcert](https://github.com/FiloSottile/mkcert). Các lệnh dưới đây do người test chạy; công cụ không tự cài CA vào hệ thống hoặc điện thoại.

```bash
# Sau khi cài mkcert theo hướng dẫn chính thức:
mkcert -install
mkdir -p "$HOME/.cache/photobooth/certs"
# Thay bằng IPv4 Wi-Fi/Ethernet thật của Ubuntu (xem hostname -I).
PB_LAN_IP=192.168.1.50
mkcert -cert-file "$HOME/.cache/photobooth/certs/camera.pem" \
    -key-file "$HOME/.cache/photobooth/certs/camera-key.pem" \
    localhost 127.0.0.1 "$PB_LAN_IP"
chmod 600 "$HOME/.cache/photobooth/certs/camera-key.pem"
mkcert -CAROOT
```

Chép **chỉ `rootCA.pem`** từ thư mục mà lệnh cuối in ra sang điện thoại và cài làm CA tin cậy. Trên iOS cần cài profile và bật full trust theo [hướng dẫn mkcert](https://github.com/FiloSottile/mkcert#mobile-devices). Android có vị trí cài CA tùy thiết bị; kiểm tra trình duyệt thực sự tin cậy certificate. Giữ `rootCA-key.pem` và `camera-key.pem` trên máy Ubuntu, không gửi sang điện thoại. Nếu IP đổi, tạo lại certificate có SAN chứa IP mới.

```bash
python3 custom/bin/camera-test.py --lan-ip "$PB_LAN_IP" \
    --cert "$HOME/.cache/photobooth/certs/camera.pem" \
    --key "$HOME/.cache/photobooth/certs/camera-key.pem"
```

Mở `https://192.168.1.50:8443` trên điện thoại (đổi IP đúng như lệnh), đăng nhập bằng tài khoản terminal và bật camera ở bước 4. HTTPS listener cổng 8443 phục vụ LAN, proxy operator vào loopback và `/share/` sang guest listener; QR dùng cùng địa chỉ HTTPS. Nếu mạng chặn kết nối, cho phép cổng này trong firewall hoặc đổi mạng có thể kết nối giữa các thiết bị. Trình duyệt cần hết cảnh báo certificate trước khi thử bật camera.

### Chạy HTTP localhost và HTTPS LAN cùng lúc

Một lệnh khởi chạy cả hai địa chỉ, dùng chung database, tài khoản, phiên và một worker. HTTP chỉ bind `127.0.0.1`; HTTPS bind LAN. Trình duyệt laptop tự mở địa chỉ HTTP để test camera local. Mỗi thiết bị/địa chỉ đăng nhập riêng; QR vẫn trỏ vào HTTPS LAN.

Ví dụ với certificate đã tạo cho `192.168.101.9`: dừng lệnh cũ bằng `Ctrl+C`, rồi chạy:

```bash
/home/ray/template/photobooth/start-camera.sh \
    --lan-ip 192.168.101.9 --port 8091 --http-port 8090 \
    --cert "$HOME/.cache/photobooth/certs/camera.pem" \
    --key "$HOME/.cache/photobooth/certs/camera-key.pem"
```

- Laptop: `http://localhost:8090`.
- Điện thoại/thiết bị trong LAN: `https://192.168.101.9:8091` (cần trust CA).

Hai thiết bị cùng điều khiển một phiên đang hoạt động; revision/idempotency vẫn chặn thao tác cũ hoặc trùng. Tài khoản và mật khẩu mới được in trong terminal khi khởi chạy thành công. Không chạy hai lệnh riêng trên cùng thư mục dữ liệu.

Có thể dùng Chrome/Firefox trên laptop hoặc Chrome Android/Safari iOS để thử; việc tương thích từng camera/trình duyệt vật lý cần kiểm tra trên thiết bị của bạn. Nếu chỉ có ảnh đen hoặc báo bận, đóng ứng dụng khác đang giữ camera, kiểm tra quyền của hệ điều hành rồi tắt/bật lại camera. Giữ màn hình chụp đang mở trong lúc countdown; ảnh không tự chụp sau reload hoặc sau khi quay lại từ màn khác.

## 3. Dùng cùng cấu hình triển khai hiện tại

Đặt `CAMERA_ADAPTER=browser`, giữ `PRINTER_ADAPTER=mock` khi chưa muốn in thật, rồi restart **cả web và worker** với cùng cấu hình. Không thay mode giữa lúc có job. PHP cần `upload_max_filesize=5M`, `post_max_size=32M`; Apache mẫu và công cụ test đã đặt các giá trị này. Nếu có reverse proxy khác, đặt giới hạn request đủ cho JPEG 5 MiB và multipart. Auth, CSRF, revision và idempotency áp dụng như các lệnh chụp cũ.

Browser đếm ngược trước khi gửi JPEG. **Khi server nhận và chấp nhận yêu cầu capture đầu tiên**, enqueue và khóa cấu hình trong cùng transaction. Không có request capture nếu camera chưa được cấp quyền hoặc không xuất được ảnh. File đã nhận nhưng JPEG hỏng sẽ làm job thất bại và vẫn giữ khóa cấu hình; retake hỏng giữ ảnh cũ. Hai tab thay đổi cấu hình trong lúc countdown sẽ bị chặn bằng revision khi ảnh được gửi, không ghép với cấu hình khác một cách âm thầm. Retry mạng dùng lại JPEG và Idempotency-Key; cùng key nhưng khác nội dung bị từ chối. Reload chỉ khôi phục phiên/job; camera cần bật lại và không tự chụp thêm.

## 4. Kiểm thử đã chạy

Ngày 2026-11-10, Ubuntu/PHP 8.1/Python 3.10/Chromium:

- PHPUnit: 19 tests, 438 assertions; thêm upload có auth/CSRF, giới hạn, replay, stale revision, JPEG hỏng, hash bị thay đổi, khóa sau capture lỗi và bảo toàn ảnh khi retake lỗi.
- Python pipeline: 32 tests qua.
- Playwright camera: 9 tests qua; cả bốn layout chụp/reload/retake/render/finalize/QR/in mock; viewport 390 × 844 và kiểm tra nút chụp/tiếp tục ở 1366 × 768, 1920 × 1080; kiểm tra preview lớn ở 1825 × 838 và cửa sổ thấp 1216 × 559, đổi/tắt camera, crop guide, lỗi quyền, mất camera trong countdown và xung đột cấu hình từ tab khác.
- Playwright hồi quy: 10 tests thường và 1 UNKNOWN qua.
- HTTP E2E cũ qua; smoke `camera-launcher.py` qua cả HTTP và HTTPS với certificate được client test tin cậy, upload multipart, replay và kiểm tra byte ảnh gốc. Kiểm tra đồng thời HTTP localhost và HTTPS dùng chung phiên, capture qua cả hai giao thức, replay liên giao thức, cookie Secure của HTTPS và đóng cả hai listener khi dừng. Startup guard kiểm tra cả cổng HTTP/HTTPS bận và giữ mật khẩu khi từ chối khởi chạy.

Chromium dùng `--use-fake-device-for-media-stream` để có luồng video thử nghiệm ([WebRTC testing](https://webrtc.org/getting-started/testing)). **Chưa xác nhận webcam vật lý, Chrome Android hoặc Safari iOS thật**. HTTPS smoke không cài CA vào trust store hệ điều hành. Chưa chạy Docker hoặc phần cứng máy in trong đợt camera này.

Chạy lại:

```bash
bash custom/bin/ci-check.sh
python3 custom/tests/e2e/camera-launcher.py
cd custom
npm run test:browser:camera
npm run test:browser
npm run test:browser:unknown
```
