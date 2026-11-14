# Beauty Retouch Engine — Development Plan

**Dự án:** Photobooth Operator Edition  
**Nền tảng:** Ubuntu / Python / PHP / JavaScript  
**Camera:** Canon EOS R10
**Printer:** Canon SELPHY CP1500  
**Công nghệ:** MediaPipe, OpenCV, NumPy, Pillow  

## 1. Yêu cầu chức năng

| ID | Chức năng | Ưu tiên |
|---|---|---|
| BEAUTY-01 | Bật/tắt Beauty Retouch | P0 |
| BEAUTY-02 | Nhận diện 1–4 khuôn mặt | P0 |
| BEAUTY-03 | Tạo mask vùng da | P0 |
| BEAUTY-04 | Làm mịn da — Skin Smoothing | P0 |
| BEAUTY-05 | Làm sáng da — Skin Brightening | P0 |
| BEAUTY-06 | Làm đều màu da — Skin Tone Evening | P0 |
| BEAUTY-07 | Giữ chi tiết mắt, môi, tóc và lông mày | P0 |
| BEAUTY-08 | Tích hợp Beauty trước LUT | P0 |
| BEAUTY-09 | Lưu Beauty Settings theo session | P0 |
| BEAUTY-10 | So sánh trước/sau | P1 |
| BEAUTY-11 | Giảm mụn — Blemish Reduction | P1 |
| BEAUTY-12 | Beauty Live Preview | P2 |
| BEAUTY-13 | Face Reshape / Makeup | Ngoài MVP |

MVP chỉ chỉnh màu và kết cấu da, không thay đổi hình dạng khuôn mặt.

## 2. Kiến trúc xử lý

Pipeline mục tiêu:

1. Nhận JPEG Canon R50.
2. Chuẩn hóa EXIF Orientation và ICC về sRGB.
3. MediaPipe Face Landmarker nhận diện các khuôn mặt.
4. MediaPipe SelfieMulticlass phân vùng da, tóc, quần áo và nền.
5. Tạo skin mask, loại trừ mắt, môi, lông mày và các vùng cần bảo vệ.
6. OpenCV thực hiện Skin Smoothing.
7. OpenCV thực hiện Skin Brightening và Skin Tone Evening.
8. Hòa trộn kết quả với ảnh gốc theo skin mask.
9. Áp LUT `.cube` đã chọn.
10. Ghép bố cục, template và xuất ảnh để xem trước/in/QR.

**Nguyên tắc:** Không ghi đè ảnh gốc; không áp Beauty lên template, chữ hoặc đường viền trang trí.

## 3. Module cần phát triển

Đề xuất cấu trúc trong `custom/python/beauty/`:

| Module | Nhiệm vụ |
|---|---|
| `config.py` | Cấu hình, preset, giới hạn tham số |
| `face_detector.py` | MediaPipe Face Landmarker, nhiều khuôn mặt |
| `skin_mask.py` | SelfieMulticlass, mask vùng da, bảo vệ mắt/môi |
| `skin_smoothing.py` | Edge-aware smoothing, bảo toàn chi tiết |
| `skin_brightening.py` | Tăng sáng cục bộ trong Lab |
| `skin_tone.py` | Giảm không đồng đều màu da |
| `processor.py` | Điều phối toàn bộ Beauty pipeline |
| `benchmark.py` | Đo tốc độ xử lý và bộ nhớ |

Các file hiện có cần đánh giá và cập nhật:

- `custom/python/render_pipeline.py`: tích hợp Beauty trước bước áp LUT.
- `custom/src/Jobs/WorkerRunner.php`: chuyển cấu hình Beauty tới Python.
- `custom/src/Config/AppConfig.php`: cấu hình model và giá trị mặc định.
- `custom/src/Http/HttpKernel.php`: xác thực cấu hình session/API khi cần.
- `custom/public/assets/js/operator.js`: bổ sung chọn preset Beauty.

Cách lưu session sẽ được quyết định sau khi kiểm tra schema và API thực tế. Không tự tạo migration nếu hệ thống đã có cơ chế cấu hình phiên phù hợp.

## 4. Kế hoạch thực hiện

### Sprint 1 — Chuẩn bị môi trường và thử nghiệm MediaPipe

**Thời lượng:** 2 ngày công

- Kiểm tra Python environment và dependency hiện có.
- Tạo virtual environment riêng cho Beauty.
- Tích hợp Face Landmarker và SelfieMulticlass.
- Tải, quản lý phiên bản và kiểm tra checksum model.
- Viết chương trình nhận JPEG và xuất ảnh đánh dấu khuôn mặt.
- Kiểm tra 1, 2 và 4 khuôn mặt.

**Kết quả:** Có thể nhận diện khuôn mặt và phân vùng da trên Ubuntu, hoàn toàn offline sau khi chuẩn bị model.

### Sprint 2 — Phát triển Skin Mask Engine

**Thời lượng:** 2–3 ngày công

- Tạo mask từ lớp `face-skin`.
- Giao vùng mask với các khuôn mặt được phát hiện.
- Loại trừ mắt, môi, lông mày và các chi tiết nhạy cảm.
- Làm mềm ranh giới mask bằng Gaussian feathering.
- Xuất debug image cho từng bước: landmarks, raw mask và final mask.
- Kiểm thử mặt nghiêng, đeo kính và tóc mái.

**Kết quả:** Có mask chất lượng đủ tốt để chỉ tác động lên da mặt.

### Sprint 3 — Skin Smoothing và Brightening

**Thời lượng:** 3–4 ngày công

- Thử nghiệm Bilateral Filter và các phương pháp giữ biên.
- Bổ sung kỹ thuật bảo toàn/phục hồi kết cấu da.
- Làm sáng riêng vùng da trong không gian Lab.
- Giới hạn thay đổi ở vùng sáng mạnh để tránh cháy da.
- Phát triển chức năng làm đều màu da mức nhẹ.
- Hỗ trợ preset Off, Natural, Soft, Korean Glow.
- So sánh ảnh gốc và ảnh đã làm đẹp.

**Kết quả:** Da sáng và mịn hơn nhưng không xuất hiện hiệu ứng da nhựa, mất chi tiết hoặc đổi màu da quá mức.

### Sprint 4 — Tích hợp Photobooth Pipeline

**Thời lượng:** 2–3 ngày công

- Tích hợp `BeautyProcessor` vào `render_pipeline.py`.
- Beauty chạy sau chuẩn hóa sRGB và trước LUT.
- Cấu hình Beauty được lưu và khóa theo session.
- Bổ sung thông tin Beauty version, config và model hash vào render signature.
- Hỗ trợ chụp ba ảnh và chụp lại riêng từng ảnh.
- Kiểm tra preview, finalize, QR sharing và in thủ công.
- Đảm bảo lỗi Beauty không làm mất JPEG gốc hoặc làm hỏng phiên.

**Kết quả:** Photobooth có thể tự động retouch ảnh từ Canon R50 mà không ảnh hưởng các chức năng đang hoạt động.

### Sprint 5 — Operator UI

**Thời lượng:** 2 ngày công

Bổ sung Beauty Settings vào màn hình **Chọn Filter**:

- Công tắc Beauty On/Off.
- Preset Natural, Soft, Korean Glow.
- Cấu hình nâng cao cho operator: Smooth, Brighten, Tone.
- Hiển thị preview sau khi chụp bằng chính kết quả render.
- Không cho thay đổi cấu hình đã khóa giữa một session đang chụp.

**Kết quả:** Người vận hành có thể chọn preset trước khi bắt đầu phiên mà không cần sử dụng command line.

### Sprint 6 — Tối ưu hiệu năng và kiểm thử

**Thời lượng:** 3–6 ngày công

- Nạp model một lần cho render job hoặc worker sống lâu.
- Chạy nhận diện trên ảnh thu nhỏ.
- Áp smoothing theo vùng ROI thay vì toàn bộ ảnh 24 MP.
- Benchmark CPU, RAM và thời gian xử lý.
- Kiểm tra nhiều tông da và điều kiện ánh sáng.
- So sánh ảnh JPEG và ảnh in SELPHY CP1500.
- Kiểm tra khi model lỗi, thiếu model hoặc hệ thống không có Internet.
- Chạy regression test toàn bộ chu trình chụp/in.

**Kết quả:** Engine đạt tiêu chí ổn định và chất lượng trước khi đưa vào booth thực tế.

## 5. Beauty Presets đề xuất

| Preset | Smooth | Brighten | Tone |
|---|---:|---:|---:|
| Off | 0% | 0% | 0% |
| Natural | 20% | 5% | 10% |
| Soft | 40% | 10% | 15% |
| Korean Glow | 55% | 15% | 20% |

Các giá trị là mức điều khiển ban đầu cần hiệu chuẩn theo ảnh thật, không phải lượng thay đổi pixel trực tiếp.

## 6. Yêu cầu hiệu năng

**MVP chạy CPU-only:**

- Mục tiêu xử lý ba ảnh trong khoảng 15 giây sau khi model đã được nạp.
- Số khuôn mặt mục tiêu: 1–4 người/ảnh.
- Không làm giảm độ phân giải JPEG đầu ra.
- Không làm chậm luồng chụp ảnh do xử lý trên luồng camera chính.
- Không gây tăng bộ nhớ không giới hạn khi chụp nhiều phiên liên tục.

Đây là các mục tiêu kỹ thuật cần đo trên laptop thực tế. Nếu không đạt, tối ưu ROI, độ phân giải inference và mô hình worker trước khi cân nhắc GPU.

## 7. Test cases nghiệm thu

| Test ID | Kiểm thử | Kết quả |
|---|---|---|
| BT-01 | Tắt Beauty | Ảnh không bị thay đổi do Beauty |
| BT-02 | Một khuôn mặt | Da được xử lý đúng |
| BT-03 | Bốn khuôn mặt | Xử lý đúng tất cả trong giới hạn cấu hình |
| BT-04 | Không có khuôn mặt | Bỏ qua Beauty, không crash |
| BT-05 | Đeo kính | Không làm mờ mắt và kính |
| BT-06 | Tóc che mặt | Không làm mịn tóc |
| BT-07 | Da sáng/da tối | Bảo toàn tông da tự nhiên |
| BT-08 | Smooth mức cao | Không bị hiệu ứng da nhựa nghiêm trọng |
| BT-09 | Beauty + LUT | Màu đúng, không áp filter lên template |
| BT-10 | Chụp lại ảnh số 2 | Render lại đúng ảnh số 2 |
| BT-11 | Ảnh JPEG xoay EXIF | Nhận diện đúng vị trí mặt |
| BT-12 | Mất model / lỗi AI | Không mất ảnh gốc; báo lỗi hợp lý |
| BT-13 | Không có Internet | Vẫn hoạt động |
| BT-14 | 30 phiên liên tục | Không treo worker, không rò rỉ bộ nhớ |
| BT-15 | Preview so với bản in | Màu sắc và hiệu ứng tương đồng |

## 8. Giai đoạn V2 — Beauty Real-time

Chỉ thực hiện sau khi V1 được nghiệm thu.

- Nhận live preview từ Canon R50 qua hệ thống capture hiện có.
- Xử lý landmarks và skin mask trên ảnh preview độ phân giải thấp.
- Dùng WebGL shader hoặc GPU pipeline để làm mịn và tăng sáng.
- Theo dõi khuôn mặt giữa các lần cập nhật mask.
- Không chạy full-resolution Python retouch trên từng frame preview.
- Đảm bảo preset preview tương ứng với preset xử lý JPEG sau chụp.

**Mục tiêu nghiên cứu:** preview 15–30 FPS tùy phần cứng, không được xem là cam kết trước khi benchmark.

## 9. Nguyên tắc an toàn và bảo trì

- Giữ nguyên toàn bộ file JPEG gốc.
- Xử lý hình ảnh trên máy, không gửi ảnh lên cloud.
- Kiểm tra giấy phép thư viện và từng model trước khi phân phối.
- Khóa phiên bản Python dependency và lưu checksum model.
- Có tùy chọn tắt Beauty bằng cấu hình hệ thống.
- Không sửa cơ chế điều khiển camera, xử lý LUT hoặc in nếu không cần thiết.
- Không chạy lại script tạo LUT preset có khả năng ghi đè manifest hiện tại.
- Có unit test, integration test và benchmark riêng cho Beauty Engine.

## 10. Điều kiện hoàn thành MVP

Beauty Retouch được coi là hoàn thành khi:

1. Operator có thể bật Beauty và chọn preset trước khi chụp.
2. Cả ba ảnh JPEG được tự động xử lý Beauty.
3. Da sáng và mịn tự nhiên; giữ được chi tiết khuôn mặt.
4. LUT DAEDAL và các LUT khác tiếp tục hoạt động bình thường.
5. Chụp lại từng ảnh không làm sai phiên hoặc render cache.
6. Ảnh preview, file QR và bản in sử dụng đúng kết quả cuối.
7. Engine chạy offline, không mất ảnh khi AI gặp lỗi.
8. Hiệu năng đạt mức nghiệm thu trên laptop thực tế.

**Thứ tự ưu tiên triển khai:**

Face Detection → Skin Mask → Skin Smoothing → Skin Brightening → LUT Integration → Operator UI → QA → Live Preview.