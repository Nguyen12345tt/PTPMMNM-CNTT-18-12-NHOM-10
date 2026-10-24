# Đề tài 18: Hệ thống Xếp Thời Khóa Biểu Theo Ràng Buộc Giảng Viên, Phòng Học và Lớp Học

**Học phần:** Phát triển phần mềm mã nguồn mở  
**Nhóm thực hiện:** Nhóm 11 (`PTPMMNM-CNTT-18-12-NHOM-11`)  
**Công nghệ:** PHP 8.x & MySQL 8.x/9.x (PDO)  
**Giấy phép:** [MIT License](LICENSE)

---

## 1. Bối cảnh, Người sử dụng và Phạm vi chức năng

### 1.1. Bối cảnh và người sử dụng
Người phụ trách đào tạo cần bố trí buổi học vào phòng học và khung thời gian phù hợp. Hệ thống tiếp nhận lịch bận của giảng viên, sĩ số sinh viên, loại phòng yêu cầu (`LT`, `TH`, `TN`) và các buổi học đã cố định (`is_locked = 1`); tự động tạo các phương án thời khóa biểu và hỗ trợ kiểm tra ảnh hưởng khi điều chỉnh thủ công.

Hệ thống trang bị đầy đủ chức năng **Đăng ký, Đăng nhập (mã hóa mật khẩu chuẩn `Bcrypt` trong bảng `users`)** và **Phân quyền 3 vai trò người sử dụng (RBAC)**:
1. **Người xếp lịch (Người phụ trách đào tạo - `scheduler`):** Toàn quyền quản lý dữ liệu, kiểm tra điều kiện, chạy thuật toán xếp lịch, khóa/mở khóa buổi học, đổi lịch thủ công, lưu/so sánh phương án và xuất lịch.
2. **Giảng viên (`teacher`):** Tự động lọc xem và xuất lịch giảng dạy liên quan của mình, được phép đăng ký lịch bận/mong muốn ưu tiên cá nhân (mọi thao tác sửa dữ liệu đào tạo hoặc chạy lại lịch đều bị chặn `403 Forbidden`).
3. **Sinh viên / Lớp học (`student`):** Tự động lọc xem và xuất lịch học của lớp mình.

#### Danh sách Tài khoản Mẫu khởi tạo sẵn (Mật khẩu chung: `123456`)
| Tên đăng nhập (`username`) | Mật khẩu | Vai trò (`role`) | Đối tượng liên kết |
|---|---|---|---|
| `admin` | `123456` | `scheduler` | Cán bộ Phụ trách Đào tạo (Toàn quyền) |
| `gv01` | `123456` | `teacher` | Giảng viên `GV01` - TS. Nguyễn Văn An |
| `gv02` | `123456` | `teacher` | Giảng viên `GV02` - ThS. Trần Thị Bình |
| `sv01` | `123456` | `student` | Đại diện Lớp `CNTT18-01` |
| `sv03` | `123456` | `student` | Đại diện Lớp `CNTT18-03` |

### 1.2. Năm nhóm chức năng đã hoàn thành
| STT | Nhóm chức năng | Mô tả hiện thực |
|---|---|---|
| **CN1** | Quản lý dữ liệu & lịch bận | Quản lý đầy đủ 20 buổi học (`lessons`), 6 giảng viên (`teachers`), 6 lớp học (`classes`), 5 phòng học (`rooms` kèm sức chứa & loại phòng `LT`/`TH`/`TN`), 20 khung giờ (`slots`) và lịch bận/mong muốn ưu tiên (`unavailability`). |
| **CN2** | Kiểm tra dữ liệu & phân loại ràng buộc | Chặn giá trị sai trước khi lưu/xếp; phân biệt rõ **7 Điều kiện bắt buộc (Hard Constraints)** và **5 Mong muốn ưu tiên (Soft Constraints)**; phân tích miền giá trị khả thi (Domain Analysis) cho từng buổi học. |
| **CN3** | Tạo lịch bằng 2 cách xếp & giữ buổi khóa | Hiện thực **Cách xếp cơ bản (`BASIC` - Sequential First-Fit)** và **Cách xếp cải thiện (`IMPROVED` - Dynamic MRV + Best-Fit + Swap Repair + Local Search)**; bảo toàn 100% các buổi đã khóa (`is_locked = 1`). |
| **CN4** | Đổi lịch, kiểm tra ảnh hưởng & báo buổi chưa xếp | Hỗ trợ thử chuyển buổi học sang `(Khung giờ, Phòng)` mới, báo cáo ngay vi phạm bắt buộc và biến thiên điểm phạt ưu tiên ($\Delta$ Soft Penalty); liệt kê rõ các buổi chưa xếp được kèm nguyên nhân chi tiết. |
| **CN5** | Lưu, so sánh phương án & xuất lịch | Lưu các phương án kèm snapshot dữ liệu (`dataset_hash` & `constraint_snapshot_json`); so sánh trực tiếp 2 phương án từ lịch thực tế; xuất thời khóa biểu theo Lớp học hoặc Giảng viên ra **CSV (Excel UTF-8)**, **JSON** và **Bản in HTML/PDF**. |

---

## 2. Kiến trúc mã nguồn & Nguồn gốc phát triển

Dự án tuân thủ kiến trúc phân tầng, tách biệt hoàn toàn phần xử lý thuật toán khỏi giao diện và lưu trữ CSDL:

```text
PTPMMNM-CNTT-18-12-NHOM-11/
├── config/
│   └── database.php                 # Quản lý kết nối PDO MySQL & tự động khởi tạo schema/seed
├── database/
│   ├── schema.sql                   # Cấu trúc 8 bảng CSDL MySQL chuẩn 3NF
│   └── seed.sql                     # Dữ liệu mẫu chuẩn: 20 buổi, 6 GV, 6 lớp, 5 phòng, 20 khung giờ
├── public/
│   ├── index.php                    # Giao diện người dùng 5 phân hệ (View)
│   └── assets/style.css             # Giao diện bảng lưới TKB và biểu mẫu
├── scripts/
│   ├── start_local_mysql.sh         # Khởi chạy MySQL cục bộ (nếu máy chưa cấu hình MySQL hệ thống)
│   ├── init_db.php                  # Khởi tạo dữ liệu mẫu & tạo sẵn 2 phương án đối chiếu
│   └── run_server.sh                # Lệnh chạy trọn gói hệ thống chỉ với 1 bước
├── src/
│   ├── Domain/
│   │   ├── ConstraintChecker.php    # [Tự hiện thực 100%] Bộ kiểm tra ràng buộc cứng & tính điểm phạt mềm
│   │   └── SchedulerEngine.php      # [Tự hiện thực 100%] Thuật toán xếp lịch Cơ bản, Cải thiện & So sánh
│   ├── Repository/
│   │   └── TimetableRepository.php  # Tầng truy xuất dữ liệu MySQL bằng PDO Prepared Statements
│   └── Controller/
│       └── TimetableController.php  # Điều phối yêu cầu, kiểm tra phân quyền & xuất lịch CSV/JSON/Print
└── tests/
    └── run_tests.php                # Bộ 20 kiểm thử tự động (bao gồm đầy đủ 5 phép thử bắt buộc Mục 6)
```

* **Mã nhóm tự phát triển 100%:** Toàn bộ logic kiểm tra ràng buộc (`ConstraintChecker`), hai thuật toán chọn phòng & khung giờ (`SchedulerEngine`), tầng truy vấn CSDL (`TimetableRepository`), bộ điều khiển (`TimetableController`), giao diện và bộ kiểm thử tự động (`tests/run_tests.php`).
* **Thư viện / Tài nguyên sử dụng:** Chỉ sử dụng thư viện chuẩn `PDO` (`ext-pdo_mysql`) tích hợp sẵn của PHP 8.x để kết nối MySQL, không phụ thuộc bất kỳ thư viện giải bài toán lập lịch bên ngoài nào.

---

## 3. Giải thích Hai Yêu cầu Kỹ thuật Trọng tâm

### 3.1. Yêu cầu kỹ thuật 1: Tự hiện thực cách chọn phòng và thời gian (`ConstraintChecker` & `SchedulerEngine`)
Mỗi khi xét gán một buổi học $L$ vào cặp $(S, R)$ gồm khung giờ $S$ và phòng học $R$, phương thức `checkHardConstraintsForPlacement()` kiểm tra nghiêm ngặt **7 điều kiện bắt buộc**:
1. **Bảo toàn khóa (`LOCKED_IMMUTABLE`):** Nếu $L.\text{is\_locked} = 1$, chỉ chấp nhận đúng $(L.\text{locked\_slot\_id}, L.\text{locked\_room\_id})$.
2. **Đúng loại phòng (`ROOM_TYPE_MISMATCH`):** $R.\text{room\_type} = L.\text{required\_room\_type}$ (`LT`, `TH`, `TN`).
3. **Đủ sức chứa (`ROOM_CAPACITY_INSUFFICIENT`):** $R.\text{capacity} \ge L.\text{student\_count}$.
4. **Không trúng giờ giảng viên bận (`TEACHER_BUSY`):** Giảng viên $L.\text{teacher\_id}$ không có bản ghi `HARD_BUSY` tại khung giờ $S$.
5. **Không trùng giảng viên (`TEACHER_CONFLICT`):** Không có buổi học nào khác đã xếp tại khung giờ $S$ có cùng `teacher_id`.
6. **Không trùng lớp học (`CLASS_CONFLICT`):** Không có buổi học nào khác đã xếp tại khung giờ $S$ có cùng `class_id`.
7. **Không trùng phòng học (`ROOM_CONFLICT`):** Phòng $R$ chưa được gán cho buổi học nào khác tại khung giờ $S$.

### 3.2. Yêu cầu kỹ thuật 2: So sánh hai cách xếp trên cùng dữ liệu & Xử lý bế tắc
1. **Cách xếp cơ bản (`BASIC` - Sequential First-Fit):**
   - Cố định trước toàn bộ các buổi đã khóa (`is_locked = 1`).
   - Duyệt tuần tự các buổi chưa khóa theo `id` tăng dần, chọn cặp `(slot, room)` đầu tiên hợp lệ.
   - *Nhược điểm:* Các buổi lớp nhỏ hoặc giảng viên rảnh cả tuần nằm ở đầu danh sách dễ chiếm mất phòng lớn (`P201`, `LAB02`) tại các khung giờ đầu tuần, khiến các buổi có ràng buộc ngặt nghèo ở cuối danh sách (`BH12`, `BH13`, `BH14`) bị hết phòng. Trên bộ dữ liệu chuẩn, `BASIC` xếp được **16/20 buổi** (còn 4 buổi chưa xếp, điểm phạt ưu tiên: **253đ**).
2. **Cách xếp cải thiện (`IMPROVED` - Dynamic MRV + Best-Fit Lookahead + Swap Repair + Local Search):**
   - Cố định trước toàn bộ các buổi đã khóa (`is_locked = 1`).
   - **Dynamic MRV (Minimum Remaining Values):** Ở mỗi bước, tính số cặp `(slot, room)` hợp lệ cho mọi buổi chưa xếp; ưu tiên xếp buổi có miền lựa chọn hẹp nhất trước (như các buổi của `GV02`, `GV03` chỉ rảnh Thứ 2 và cần phòng lớn).
   - **Best-Fit & Look-ahead Soft Score:** Chọn phòng có sức chứa sát sĩ số nhất (tránh lãng phí phòng lớn), ít làm mất phương án của các buổi chưa xếp khác nhất, và có điểm phạt ưu tiên thấp nhất.
   - **Augmenting Swap Repair & Local Search:** Tự động dịch chuyển các buổi *không khóa* để nhường chỗ cho buổi bị kẹt và giảm điểm phạt ưu tiên.
   - *Kết quả trên cùng dữ liệu chuẩn:* `IMPROVED` xếp được **19/20 buổi** (tăng +3 buổi so với `BASIC`, điểm phạt ưu tiên giảm từ **253đ xuống còn 4đ**). Riêng buổi `BH20` bị kẹt do xung đột với buổi đã khóa `BH02`, hệ thống trả về trạng thái `PARTIAL_SCHEDULE` kèm chẩn đoán nguyên nhân chi tiết, **không giấu buổi học và không kết luận bài toán vô nghiệm**.

---

## 4. Hướng dẫn Cài đặt, Khởi tạo Dữ liệu, Chạy và Kiểm thử

### Cách 1: Chạy nhanh trọn gói bằng Script tự động (Khuyên dùng)
Chỉ cần máy có cài đặt `php` (kèm module `pdo_mysql`) và `mysql`/`mysqld`:
```bash
./scripts/run_server.sh 8080
```
Script sẽ tự động:
1. Khởi chạy MySQL cục bộ (nếu chưa chạy) và tạo CSDL `timetable_db`.
2. Nạp cấu trúc bảng từ `database/schema.sql` và dữ liệu mẫu chuẩn từ `database/seed.sql`.
3. Chạy sẵn 2 phương án Cơ bản & Cải thiện và mở máy chủ web tại `http://localhost:8080`.

### Cách 2: Cấu hình với MySQL hệ thống tùy chỉnh
1. Sao chép tệp cấu hình mẫu:
   ```bash
   cp .env.example .env
   ```
2. Cập nhật `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` trong `.env`.
3. Khởi tạo CSDL và dữ liệu mẫu:
   ```bash
   php -d extension=pdo_mysql -d extension=mysqli scripts/init_db.php
   ```
4. Khởi chạy máy chủ web PHP:
   ```bash
   php -d extension=pdo_mysql -d extension=mysqli -S localhost:8080 -t public
   ```

### Chạy bộ kiểm thử tự động (20 Test Cases bao phủ 5 phép thử bắt buộc Mục 6)
```bash
php -d extension=pdo_mysql -d extension=mysqli tests/run_tests.php
```

---

## 5. Hướng dẫn Trình diễn Hai Tình huống Sử dụng (Khi Bảo vệ BTL)

### Tình huống 1: Nhập dữ liệu → Kiểm tra điều kiện → Chạy xếp → Xem lịch và các buổi chưa xếp
1. Mở giao diện tại `http://localhost:8080`, nhấn nút **"🔄 Tình huống 1: Khôi phục dữ liệu gốc & Chạy 2 phương án"** trên thanh kịch bản (hoặc vào Tab 1 để xem/thêm dữ liệu 20 buổi học, 6 GV, 5 phòng, 20 khung giờ).
2. Chuyển sang **Tab 2 (Kiểm tra Dữ liệu & Ràng buộc)** để xem bảng phân loại **7 Điều kiện bắt buộc** vs **5 Mong muốn ưu tiên** và bảng phân tích miền giá trị (Domain Analysis).
3. Chuyển sang **Tab 3 (Tạo Lịch & Xem TKB)**, chọn chạy **Cách xếp Cơ bản** hoặc **Cách xếp Cải thiện**, quan sát lưới Thời khóa biểu trực quan (các buổi khóa có biểu tượng 🔒 màu vàng) và bảng **Danh sách buổi học chưa xếp được** kèm nguyên nhân cụ thể ở cuối trang.

### Tình huống 2: Khóa một số buổi → Thay yêu cầu → Chạy lại → So sánh hai phương án → Xuất lịch
1. Tại lưới Thời khóa biểu ở **Tab 3**, nhấn nút **"🔒 Khóa buổi này"** trên một buổi học (ví dụ `BH04`), sau đó vào **Tab 1** điều chỉnh sĩ số nhóm thực hành của buổi nút cổ chai `BH20` từ `52 SV` xuống `38 SV` (vừa phòng `LAB01`), hoặc nhấn nút nhanh **"⚡ Tình huống 2: Khóa thêm BH04 + Đổi yêu cầu BH20 & So sánh"** trên thanh kịch bản.
2. Hệ thống tự động chạy lại cả 2 thuật toán trên bộ dữ liệu mới (giữ nguyên toàn bộ các buổi đã khóa) và chuyển tới **Tab 5 (Lưu, So sánh Phương án & Xuất lịch)**.
3. Quan sát bảng đối chiếu: Cách cải thiện xếp thành công **20/20 buổi (100%)**, vượt trội hoàn toàn so với Cách cơ bản (**17/20 buổi**).
4. Cuộn xuống mục **Xuất Thời Khóa Biểu**, chọn lọc theo **Lớp học** hoặc **Giảng viên** và xuất ra **Bản in HTML/PDF** hoặc **File Excel CSV**.
