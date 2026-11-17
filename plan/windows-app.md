# Kế hoạch Photobooth Windows với Canon R10 và digiCamControl

Ngày cập nhật: 17/11/2026 (Asia/Saigon). Trạng thái: đã triển khai bản thử Windows 0.1.1; đã thử một ảnh R10 thật và live view trong đếm ngược, còn chờ nghiệm thu độ bền. Xem [báo cáo kiểm thử](windows-test-report.md) và [hướng dẫn sử dụng/build](../custom/desktop/README.md).

Cập nhật luồng theo yêu cầu 17/11: Bố cục → Chụp → Filter/Beauty → Template → Duyệt/QR. Live view giữ bật trên bàn chụp, kể cả đếm ngược. Bố cục khóa sau lần chụp đầu; filter và template được đổi sau khi đủ ảnh, bản render cũ bị vô hiệu hóa khi đổi. Các đoạn mô tả ghim toàn bộ cấu hình trước chụp bên dưới là quyết định của bản trước và đã được thay bằng quy tắc này.

## 1. Mục tiêu và quyết định

Xây dựng ứng dụng Windows cho máy booth cá nhân, dùng Canon EOS R10 qua USB. App điều khiển digiCamControl để xem live view, lấy nét, chụp và nhận JPEG gốc, sau đó dùng Operator Edition hiện có để xử lý Beauty/LUT, ghép template và tạo QR tải ảnh qua LAN.

Phương án này thay thế phần tích hợp Canon EDSDK trực tiếp trong kế hoạch Windows trước đó. Tài liệu này là kế hoạch áp dụng cho Windows và R10; các mốc Linux/gphoto2/CUPS trong planning baseline tiếp tục mô tả nhánh Linux.

- Windows 10 22H2 và Windows 11 x64; một máy booth và một R10.
- Live view, autofocus trước khi chụp và retake từng ô; phơi sáng/cân bằng trắng chỉnh trên R10.
- Camera ở chế độ ảnh tĩnh, JPEG; giữ đủ bốn layout, template và LUT hiện có.
- Máy in dùng mock, hiển thị rõ mô phỏng; in thật nằm ngoài bản đầu.
- Hoạt động offline sau cài đặt; cập nhật thủ công, giữ dữ liệu giữa các phiên bản.
- Chưa làm Beauty live view, cấp phép thương mại, cloud, điều khiển nhiều camera hoặc ARM.

## 2. Kiến trúc và công cụ

Luồng chính: Electron → Operator API → worker → DigiCamControlCameraAdapter → digiCamControl → USB → R10. JPEG nhận về đi qua Asset Store và pipeline Python hiện có; khách chỉ tải ảnh cuối đã duyệt.

| Thành phần | Lựa chọn |
| --- | --- |
| Desktop | Electron với context isolation, sandbox, tắt Node integration trong trang |
| Bộ cài | electron-builder/NSIS offline, cài theo tài khoản Windows |
| Web server | Caddy 2 và bốn tiến trình PHP FastCGI, không dùng PHP development server để phát hành |
| Backend | PHP 8.3 x64 NTS, Composer dependencies đóng gói kèm |
| Pipeline ảnh | Python 3.12 x64, Pillow, NumPy, MediaPipe, OpenCV và model cục bộ |
| Camera | digiCamControl stable 2.1.7, adapter PHP giao tiếp HTTP local |
| Quản lý runtime | Supervisor Python dùng ctypes/Windows Job Objects; quản lý server, PHP và worker |
| Dữ liệu | `%LOCALAPPDATA%\PhotoboothOperator\data`, tách khỏi thư mục cài app |

Không cần người dùng xin hoặc tải Canon EDSDK. Không xây C++ camera bridge. Supervisor dùng Python để bản này không cần MSVC/CMake/Visual Studio Build Tools; các dependency native dùng wheel/runtime đã build sẵn, khóa phiên bản và checksum.

digiCamControl là phần mềm phụ thuộc được cài riêng bằng bộ cài chính thức; bản đầu không trích DLL của nó hoặc đóng gói lại thành thư viện riêng. Setup wizard gợi ý đường dẫn exe và kiểm tra cấu hình; lúc khởi động kiểm tra API. Phiên bản DCC và kết nối R10 cần xác nhận khi nghiệm thu thiết bị. Các runtime còn lại được đóng gói với app, không yêu cầu người vận hành tự cài PHP/Python/Node/Docker.

Operator chỉ nghe `127.0.0.1:8080`; guest nghe `0.0.0.0:8081` và chỉ phục vụ các route chia sẻ. Mặc định DCC API là `http://127.0.0.1:5513`. Cổng bận phải báo lỗi và cho chỉnh cấu hình, không chiếm cổng của chương trình khác.

## 3. Giao tiếp camera và xử lý lỗi

### Cấu hình và API

- Thêm `CAMERA_ADAPTER=digicamcontrol`, `DCC_BASE_URL=http://127.0.0.1:5513`, `DCC_EXECUTABLE`, `DCC_CAMERA_ID` và `PYTHON_BIN`. DCC URL chỉ nhận loopback, không nhận host LAN/public. Giá trị capture/render timeout dùng cấu hình hiện có.
- Thêm `DigiCamControlCameraAdapter` theo `CameraAdapterInterface`; mode trả về là `digicamcontrol`. Không sửa schema phiên chỉ để đổi adapter.
- Giữ capture/retake API, revision, idempotency và worker queue hiện có; chỉ worker được gửi lệnh chụp.
- Thêm `GET /custom/api/camera`, `POST /custom/api/camera/preview/start`, `POST /custom/api/camera/preview/stop`, `GET /custom/api/camera/preview.jpg`. GET cần đăng nhập; POST kiểm tra CSRF/Origin. Không chuyển tiếp tùy ý lệnh DCC do client cung cấp.
- Backend gọi các lệnh SLC để chọn camera bằng serial/name, đặt `session.folder`, `session.filenametemplate`, trigger capture và theo dõi `lastcaptured`/session. Bật/tắt preview dùng `LiveViewWnd_Show`/`LiveViewWnd_Hide`; frame lấy từ `liveview.jpg`.
- Dùng đường dẫn JPEG đầy đủ trong thư mục capture dành riêng hoặc endpoint `/image/<name>`; không dùng `preview.jpg`, thumbnail hay frame live view làm ảnh gốc.

### Một job nhận đúng một JPEG

1. Worker giữ khóa singleton và xác nhận R10 đã chọn còn kết nối. Countdown chỉ chạy một lần theo luồng worker hiện có.
2. Tạo thư mục incoming riêng theo job ID dưới DATA_DIR; đặt DCC session folder và filename stem theo job, kiểm tra các thiết lập trước khi chụp. Không dùng thư mục ảnh cá nhân làm incoming.
3. Ghi trạng thái SUBMITTING vào biên nhận capture cục bộ trước khi gửi một lệnh chụp có autofocus. Không bật retry HTTP cho lệnh này. Nếu autofocus không khả dụng/thất bại, trả lỗi; không âm thầm chụp không nét.
4. Chờ tệp thuộc đúng folder/stem và camera đã chọn. `lastcaptured` chỉ là tín hiệu; không lấy ảnh mới nhất toàn cục làm kết quả. Đợi file hết bị ghi/khóa, kích thước ổn định và validation JPEG thành công trong deadline.
5. Sao chép JPEG byte-for-byte vào pending của job, kiểm tra hash rồi publish qua Asset Store hiện có. Không chỉnh sửa hoặc xóa JPEG đã publish.
6. Nếu xuất hiện nhiều JPEG không phân biệt được, hết thời gian, mất USB hoặc DCC chết, fail job thay vì đoán ảnh. Retake lỗi giữ slot và render cũ. File đến muộn được cách ly, không chuyển sang job tiếp theo.

DCC không cung cấp idempotency theo job ID trong giao diện được tài liệu hóa. Việc chống chụp trùng nằm ở idempotency API, một worker, biên nhận capture và quy tắc không tự retry sau SUBMITTING. Khi crash trong capture, giữ cách phục hồi hiện có: báo job thất bại, không tự kích màn trập lần nữa.

### Live view và quyền sở hữu thiết bị

- UI lấy frame mới nhất, mục tiêu 10 FPS trên máy booth, tối đa một request frame đang chờ; không tích hàng đợi frame cũ.
- Mọi start/stop preview và capture đi qua cùng bộ điều phối thiết bị; thao tác preview không được thay đổi session folder trong lúc capture.
- Giữ polling trong đếm ngược và qua các bước trên bàn chụp. Chỉ trả CAMERA_BUSY khi capture thực sự giữ khóa; giữ khung cuối và tiếp tục khi có frame mới. Rời bàn chụp/đăng xuất/đóng app thì ngừng preview.
- Trong thời gian vận hành, DCC session dành riêng cho Photobooth; không chụp thủ công trên DCC/camera hoặc chạy EOS Utility đồng thời. Khi phát hiện ảnh ngoài luồng, báo lỗi cần operator xử lý.
- DCC do app mở được theo dõi và đóng theo vòng đời app; instance đã mở sẵn chỉ kết nối sau kiểm tra camera/session, không bị app tự đóng. Không tự kết nối lại và chụp tiếp sau lỗi.
- Tắt Allow public access trong DCC. Thiết lập này không có nghĩa là chặn truy cập LAN. Chặn kết nối từ LAN tới cổng DCC bằng cấu hình bind loopback nếu bản cài hỗ trợ, nếu không dùng firewall và kiểm thử từ điện thoại. Chỉ guest port được mở trên mạng Private.

## 4. Các mốc thực hiện

| Mốc | Công việc | Điều kiện hoàn tất |
| --- | --- | --- |
| W0 | Cài DCC stable chính thức; nhận diện R10; thử live view, autofocus, chụp và tải JPEG qua giao diện local | R10 thật đạt các khả năng cần dùng; ghi phiên bản DCC/firmware và kết quả, không đánh dấu mock thay phần cứng |
| W1 | Chạy backend/Python trên Windows; thêm PYTHON_BIN, hoàn thiện Beauty dependencies/lazy import/model checksum, chuẩn hóa đường dẫn/khóa/ghi file | Luồng mock, migration, pipeline, backup/restore và regression Windows đạt; Linux không bị hồi quy |
| W2 | Viết DCC client, adapter, điều phối preview/capture, biên nhận và ghép tệp với job; bổ sung API camera | Capture/retake trên R10 đúng ô, mất phản hồi không chụp lại, JPEG gốc giữ nguyên |
| W3 | Electron, Caddy/FastCGI, supervisor Python, single instance, setup wizard, health và log | Mở app bằng shortcut; tạo Admin, chọn camera/IP LAN; không cần terminal để vận hành |
| W4 | Bộ cài offline, nâng cấp/backup, firewall guest/DCC, QR thật, hướng dẫn và soak test | Cài sạch và vận hành đầy đủ trên laptop booth, đạt các bài nghiệm thu bên dưới |

W0 là gate cho tích hợp R10. Trong lúc chờ máy ảnh, có thể thực hiện W1 và kiểm thử DCC client với HTTP fake; không dùng chúng để kết luận phần cứng đạt. Nếu bản stable không đạt live view/AF/JPEG, ghi đúng tính năng thất bại và đánh giá lại trước khi chuyển bản khác hoặc đổi phương án.

### Vòng đời app và dữ liệu

- Lần đầu mở: kiểm tra DCC/runtime/cổng, yêu cầu tạo Admin, chọn R10 và IP LAN; sinh signing key riêng. UI luôn ghi máy in mô phỏng.
- Chỉ mở một app/worker trên cùng DATA_DIR. Kiểm tra migration trước khi phục vụ; tự chạy migration dưới khóa khởi động, backup trước khi nâng cấp.
- Supervisor quản lý runtime bằng Windows Job Objects và theo dõi pipe với Electron; Electron chết thì dừng các tiến trình do app sở hữu, không để worker tiếp tục chụp.
- Khi đóng lúc có job, cho chọn đợi hoặc buộc đóng. Buộc đóng giữ quy tắc phục hồi capture không tự retry; render được retry trong giới hạn hiện có.
- Không diệt PID cũ lấy từ SQLite. Lỗi dịch vụ khóa thao tác chụp, hiển thị nguyên nhân và nút khởi động lại dịch vụ.
- Cập nhật thủ công giữ SQLite, ảnh, template/LUT đã nhập và signing key; gỡ app giữ dữ liệu mặc định. Không làm updater tự động.
- Bảo đảm thư viện QR thật nằm trong gói; không tạo hình giống QR nếu thiếu dependency. Tải/in dựa trên final đã duyệt và kiểm tra hash.

## 5. Kiểm thử và nghiệm thu

| Nhóm | Ca kiểm thử và điều kiện đạt |
| --- | --- |
| DCC client | HTTP fake cho camera không có/bận, AF lỗi, phản hồi chậm, timeout sau trigger, file đến muộn/đang ghi/hỏng, lastcaptured cũ và nhiều candidate; chỉ một trigger cho mỗi job |
| R10 thật | Ít nhất 100 lần chụp gồm retake và đủ bốn layout; không gán sai ô, lấy thumbnail hoặc tạo chụp trùng khi replay/reload |
| Live view | Trung bình tối thiểu 10 FPS trong 10 phút trên laptop booth; UI vẫn phản hồi, guest download vẫn hoạt động; lưu số đo thực tế |
| Lỗi phần cứng | Rút/cắm USB, camera tắt/bận, không lấy nét, DCC/worker/app crash trước và sau trigger, capture thủ công ngoài luồng; giữ ảnh đã commit và không tự chụp lại |
| Cài sạch | Windows 10 22H2 và Windows 11 x64 không có runtime dev; DCC là prerequisite riêng; thử username/path tiếng Việt, dấu cách, quyền người dùng thường và cổng bận |
| Offline và LAN | Ngắt Internet vẫn chụp/Beauty/LUT/render được; điện thoại giải mã QR thật, tải đúng hash final; chặn API operator và cổng DCC từ LAN |
| Pipeline và dữ liệu | Beauty Off không phụ thuộc model AI; thiếu model có cảnh báo và giữ ảnh gốc; đúng EXIF/sRGB/DPI/kích thước, không đổi byte final khi tải; backup/restore và nâng cấp giữ hash |
| Độ bền | Ít nhất 30 phiên và bốn giờ vận hành; không treo worker, thiếu ảnh hoặc tăng bộ nhớ liên tục; test trong DATA_DIR tạm |
| Regression | Chạy lại pytest, PHPUnit, HTTP E2E và Playwright; thêm cấu hình test Windows không phụ thuộc shell Bash; kiểm tra Linux hiện có |

Bàn giao gồm bộ cài Photobooth, liên kết/prerequisite DCC, checksum và manifest runtime, hướng dẫn cấu hình R10/DCC/Wi-Fi, hướng dẫn phục hồi và báo cáo nghiệm thu có kết quả thật. Trạng thái camera vẫn là PENDING HARDWARE cho đến khi W0/W2 và bộ nghiệm thu R10 đạt.

## 6. Nguồn và hướng dẫn cài đặt

- [Trang tải digiCamControl chính thức](https://www.digicamcontrol.com/download): stable 2.1.7; cài bằng bộ cài dự án cung cấp, không dùng gói Virtual Webcam thay cho ứng dụng chính.
- [Changelog digiCamControl](https://www.digicamcontrol.com/updates/changelog.html): công bố hỗ trợ Canon EOS R10 từ 2.1.6.
- [DCC Web Interface](https://www.digicamcontrol.com/doc/userguide/web): capture, liveview.jpg, SLC session folder/filename, session.json và lastcaptured.
- [DCC Settings](https://digicamcontrol.com/doc/userguide/settings): bật web server/interaction, tắt public access và tắt auto-export/resize ảnh cho session Photobooth.
- [Hướng dẫn USB Canon R10](https://cam.start.canon/en/C006/manual/html/UG-09_Set-up_0260.html): chọn Photo Import/Remote Control.
- [Windows Job Objects](https://learn.microsoft.com/en-us/windows/win32/procthread/job-objects): quản lý vòng đời các tiến trình con thuộc app.

Thiết lập ban đầu: cài DCC stable, đặt R10 ở chế độ ảnh tĩnh/JPEG và USB Photo Import/Remote Control, kết nối cáp dữ liệu, bật DCC Webserver và Allow interaction, tắt Allow public access, rồi kiểm chứng W0. Không cần Canon SDK hoặc Visual Studio Build Tools theo phương án này.
