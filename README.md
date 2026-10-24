# Đề tài 18: Hệ thống Xếp Thời Khóa Biểu Theo Ràng Buộc Giảng Viên, Phòng Học và Lớp Học

**Học phần:** Phát triển phần mềm mã nguồn mở  
**Nhóm thực hiện:** Nhóm 11 (`PTPMMNM-CNTT-18-12-NHOM-11`)  
**Công nghệ:** PHP 8.x & MySQL 8.x/9.x (PDO)  
**Giấy phép:** [MIT License](LICENSE)

---

## 1. Thành viên Nhóm 11 và Phân công Chức năng

Dự án được phân chia đều các nhóm chức năng và tầng kỹ thuật cho 3 thành viên:

### 1.1. Nguyễn Thành Nguyên
- **Thiết kế Cơ sở dữ liệu và Tầng Truy xuất Dữ liệu (`database/schema.sql`, `database/seed.sql`, `config/database.php`, `src/Repository/TimetableRepository.php`):**
  - Thiết kế cấu trúc các bảng dữ liệu: Khoa đào tạo (`faculties`), Tài khoản người dùng (`users`), Giảng viên (`teachers`), Lớp học (`classes`), Phòng học (`rooms`), Khung giờ 2 ca Sáng/Chiều (`slots`), Buổi học (`lessons`), Lịch bận/ưu tiên (`teacher_unavailability`), Phương án thời khóa biểu (`schedule_versions`) và Chi tiết phân công lịch (`schedule_assignments`).
  - Xây dựng các truy vấn PDO Prepared Statements phục vụ thêm, sửa, xóa và đồng bộ dữ liệu đào tạo.
- **Chức năng 1 - Quản lý Dữ liệu Đào tạo, Khoa, Lớp học, Sĩ số, Mã Sinh viên và Lịch bận (`src/Controller/TimetableController.php`, `public/index.php`):**
  - Quản lý danh mục Khoa đào tạo, Lớp học, Sĩ số sinh viên (kèm đồng bộ sĩ số sang buổi học), Phòng học (`LT`, `TH`, `TN`), Giảng viên, Lịch bận bắt buộc (`HARD_BUSY`), Mong muốn ưu tiên (`SOFT_AVOID`, `SOFT_PREFER`) và Khung giờ học tự động theo đầu số lớp (`18-*`: Sáng `07:20 - 11:20`, Chiều `13:20 - 17:20`; `19-*`: Sáng `07:10 - 11:10`, Chiều `13:10 - 17:10`).
  - Xây dựng chức năng Đăng nhập, Phân quyền 3 vai trò (`scheduler`, `teacher`, `student`) và Quản lý cấp phát Mã sinh viên gắn với từng lớp học.

### 1.2. Nguyễn Hồng Sơn
- **Chức năng 2 - Kiểm tra Dữ liệu và Phân loại Ràng buộc (`src/Domain/ConstraintChecker.php`):**
  - Kiểm tra hợp lệ dữ liệu đầu vào và chặn các giá trị sai trước khi lưu hoặc xếp lịch.
  - Tự hiện thực bộ kiểm tra 7 Điều kiện bắt buộc (Hard Constraints): Bảo toàn buổi học đã khóa (`is_locked = 1`), đúng loại phòng (`LT`, `TH`, `TN`), sức chứa phòng lớn hơn hoặc bằng sĩ số lớp, giảng viên không bận cứng (`HARD_BUSY`), không trùng giảng viên, không trùng lớp học và không trùng phòng học trong cùng một khung giờ.
  - Tự hiện thực bộ tính điểm phạt cho 5 Mong muốn ưu tiên (Soft Constraints) và phân tích miền lựa chọn khả thi (Domain Analysis) của từng buổi học.
- **Chức năng 3 - Tạo Thời khóa biểu bằng Hai Cách xếp và Giữ nguyên Buổi đã khóa (`src/Domain/SchedulerEngine.php`):**
  - Hiện thực Cách xếp cơ bản (`BASIC` - Sequential First-Fit Greedy).
  - Hiện thực Cách xếp cải thiện (`IMPROVED` - Dynamic MRV kết hợp Best-Fit chọn phòng sát sĩ số, hoán đổi vị trí Swap Repair và tối ưu cục bộ Local Search), bảo toàn 100% các buổi học đã khóa cố định.

### 1.3. Lò Xuân Trường
- **Chức năng 4 - Đổi lịch Thủ công, Kiểm tra Ảnh hưởng và Báo cáo Buổi chưa xếp (`src/Domain/SchedulerEngine.php`, `src/Controller/TimetableController.php`, `public/index.php`):**
  - Xây dựng công cụ thử chuyển một buổi học sang cặp `(Khung giờ, Phòng học)` mới, kiểm tra ngay các vi phạm điều kiện bắt buộc và tính mức thay đổi điểm phạt ưu tiên trước khi áp dụng.
  - Gợi ý danh sách các vị trí hợp lệ còn trống cho từng buổi học và chẩn đoán nguyên nhân chi tiết đối với các buổi học chưa xếp được (không giấu buổi học và không kết luận bài toán vô nghiệm khi có một phần bị kẹt).
- **Chức năng 5 - Lưu trữ, So sánh Hai Phương án, Xuất lịch và Kiểm thử Tự động (`src/Domain/SchedulerEngine.php`, `public/index.php`, `public/assets/style.css`, `tests/run_tests.php`):**
  - Lưu trữ các phương án thời khóa biểu kèm mã băm dữ liệu (`dataset_hash`), đối chiếu trực tiếp hai phương án Cơ bản và Cải thiện trên cùng bộ dữ liệu và điều kiện ràng buộc.
  - Xây dựng giao diện bảng Thời khóa biểu tuần cho Sinh viên, Giảng viên, banner dọc Quản trị đào tạo cho Admin, chức năng xuất lịch ra CSV, JSON, Bản in HTML và bộ 24 kiểm thử tự động.

---

## 2. Tài khoản Đăng nhập Mẫu

Tất cả tài khoản mẫu sử dụng mật khẩu chung: `123456`

| Tài khoản (`username`) | Mật khẩu | Vai trò (`role`) | Mô tả quyền hạn |
|---|---|---|---|
| `admin` | `123456` | `scheduler` | Quản trị đào tạo (Toàn quyền quản lý Lớp, Sĩ số, Khoa, Môn học, Mã SV, Giảng viên, Phòng học, Xếp lịch) |
| `gv01` | `123456` | `teacher` | Giảng viên `GV01` - TS. Nguyễn Văn An (Xem lịch dạy, đăng ký lịch bận cá nhân) |
| `gv02` | `123456` | `teacher` | Giảng viên `GV02` - ThS. Trần Thị Bình (Xem lịch dạy, đăng ký lịch bận cá nhân) |
| `sv01` | `123456` | `student` | Sinh viên Lớp `CNTT 18-12` (Chỉ xem thời khóa biểu của lớp `CNTT 18-12`) |
| `sv02` | `123456` | `student` | Sinh viên Lớp `CNTT 18-09` (Chỉ xem thời khóa biểu của lớp `CNTT 18-09`) |
| `sv03` | `123456` | `student` | Sinh viên Lớp `CNTT 19-09` (Chỉ xem thời khóa biểu của lớp `CNTT 19-09`) |

---

## 3. Cấu trúc Thư mục Mã nguồn

- `config/database.php`: Quản lý kết nối PDO MySQL và tự động nạp cấu trúc bảng, dữ liệu mẫu.
- `database/schema.sql`: Định nghĩa các bảng cơ sở dữ liệu MySQL.
- `database/seed.sql`: Dữ liệu mẫu chuẩn gồm 20 buổi học, 6 giảng viên, 6 lớp học, 6 khoa, 5 phòng học, 20 khung giờ (2 tuần, mỗi ngày 2 ca Sáng và Chiều).
- `src/Domain/ConstraintChecker.php`: Lớp kiểm tra điều kiện bắt buộc, đánh giá điểm phạt ưu tiên và chuẩn hóa giờ học theo đầu số lớp.
- `src/Domain/SchedulerEngine.php`: Lớp thuật toán xếp lịch Cơ bản (`BASIC`), Cải thiện (`IMPROVED`), kiểm tra ảnh hưởng đổi lịch và so sánh hai phương án.
- `src/Repository/TimetableRepository.php`: Lớp truy xuất cơ sở dữ liệu bằng PDO.
- `src/Controller/TimetableController.php`: Lớp điều phối yêu cầu người dùng, xác thực phân quyền và xuất dữ liệu CSV, JSON, Bản in.
- `public/index.php` và `public/assets/style.css`: Giao diện hệ thống theo từng phân quyền.
- `tests/run_tests.php`: Bộ kiểm thử tự động kiểm chứng toàn bộ các phép thử bắt buộc của đề tài.

---

## 4. Hướng dẫn Cài đặt, Chạy Hệ thống và Kiểm thử

### 4.1. Chạy nhanh bằng Script tự động
```bash
./scripts/run_server.sh 8080
```
Lệnh trên sẽ tự động khởi động MySQL cục bộ (nếu chưa chạy), nạp `database/schema.sql` và `database/seed.sql`, tạo sẵn hai phương án Cơ bản và Cải thiện, đồng thời mở máy chủ web tại địa chỉ `http://localhost:8080`.

### 4.2. Chạy thủ công với MySQL cấu hình riêng
1. Sao chép tệp cấu hình môi trường:
   ```bash
   cp .env.example .env
   ```
2. Chỉnh sửa thông số kết nối `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` trong tệp `.env`.
3. Khởi tạo cơ sở dữ liệu và dữ liệu mẫu:
   ```bash
   php -d extension=pdo_mysql -d extension=mysqli scripts/init_db.php
   ```
4. Khởi chạy máy chủ web PHP:
   ```bash
   php -d extension=pdo_mysql -d extension=mysqli -S localhost:8080 -t public
   ```

### 4.3. Chạy bộ kiểm thử tự động
```bash
php -d extension=pdo_mysql -d extension=mysqli tests/run_tests.php
```
