# Hướng dẫn Đóng góp & Cộng tác (CONTRIBUTING.md)

Tài liệu này quy định quy trình làm việc nhóm, quản lý mã nguồn, tạo Issue, gửi Pull Request (PR) và rà soát mã (Code Review) cho dự án **Đề tài 18: Xây dựng hệ thống xếp thời khóa biểu theo ràng buộc giảng viên, phòng học và lớp học (Nhóm 10)**.

---

## 1. Nguyên tắc thiết kế & An toàn thông tin
1. **Tách biệt tầng xử lý:**
   - Mọi thuật toán xếp lịch và kiểm tra ràng buộc đặt tại `src/Domain/` (`ConstraintChecker.php`, `SchedulerEngine.php`), không phụ thuộc vào HTML hay câu lệnh SQL.
   - Mọi truy vấn MySQL đặt tại `src/Repository/TimetableRepository.php`, sử dụng `PDO` với Prepared Statements.
   - Xử lý luồng người dùng và phân quyền đặt tại `src/Controller/TimetableController.php`.
2. **An toàn dữ liệu:**
   - Tuyệt đối không đưa mật khẩu thật hoặc tệp `.env` / `.local` lên kho lưu trữ Git (chỉ cập nhật `.env.example`).
   - Chỉ sử dụng dữ liệu giả lập phục vụ đào tạo và kiểm thử.

---

## 2. Quy trình làm việc với Git (Git Workflow)
1. **Tạo Issue:** Trước khi thực hiện một tính năng hoặc sửa lỗi, tạo một Issue mô tả rõ:
   - Nhóm chức năng liên quan (CN1 -> CN5) hoặc Phép thử bắt buộc (Phép thử 1 -> 5).
   - Tiêu chí nghiệm thu (Acceptance Criteria).
2. **Tạo nhánh (Branch):**
   - `feat/cn1-quan-ly-du-lieu`
   - `feat/cn2-kiem-tra-rang-buoc`
   - `feat/cn3-thuat-toan-xep-lich`
   - `feat/cn4-doi-lich-kiem-tra-anh-huong`
   - `feat/cn5-so-sanh-va-xuat-lich`
   - `fix/ten-loi-can-sua`
3. **Chạy kiểm thử trước khi Commit:**
   ```bash
   php -d extension=pdo_mysql -d extension=mysqli tests/run_tests.php
   ```
   Tất cả 20 kiểm thử tự động (bao gồm 5 phép thử bắt buộc ở Mục 6) phải đạt `PASS` trước khi tạo Pull Request.
4. **Gửi Pull Request & Code Review:**
   - Ít nhất 1 thành viên khác trong nhóm kiểm tra mã nguồn, đối chiếu với yêu cầu đề tài và xác nhận kết quả chạy thử nghiệm trước khi gộp (`merge`) vào nhánh `main`.
