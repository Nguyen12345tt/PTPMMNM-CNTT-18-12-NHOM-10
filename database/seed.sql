-- ============================================================================
-- DỮ LIỆU MẪU CHUẨN THEO MỤC 5 ĐỀ TÀI 18
-- Quy mô: 6 Giảng viên, 6 Lớp học, 5 Phòng học, 20 Khung giờ, 20 Buổi học
-- Có sẵn: Lịch bận bắt buộc (HARD_BUSY), Mong muốn ưu tiên (SOFT_AVOID/PREFER),
--         Các buổi học đã khóa cố định (is_locked = 1) và xung đột tài nguyên.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE schedule_assignments;
TRUNCATE TABLE schedule_versions;
TRUNCATE TABLE lessons;
TRUNCATE TABLE unavailability;
TRUNCATE TABLE users;
TRUNCATE TABLE slots;
TRUNCATE TABLE rooms;
TRUNCATE TABLE classes;
TRUNCATE TABLE teachers;
TRUNCATE TABLE faculties;

-- 0. Danh mục các Khoa đào tạo trong trường (faculties) - Có thể chọn sẵn hoặc tự thêm mới
INSERT INTO faculties (id, code, name) VALUES
(1, 'CNTT', 'Khoa Công nghệ thông tin'),
(2, 'KT',   'Khoa Kinh tế & Quản trị kinh doanh'),
(3, 'TCNH', 'Khoa Tài chính - Ngân hàng'),
(4, 'YD',   'Khoa Y - Dược'),
(5, 'NN',   'Khoa Ngoại ngữ'),
(6, 'TT',   'Khoa Truyền thông');

-- 1. 6 Giảng viên (teachers)
INSERT INTO teachers (id, code, name, department, email, max_lessons_per_day) VALUES
(1, 'GV01', 'TS. Nguyễn Văn An', 'Khoa Công nghệ thông tin', 'annv@cntt.edu.vn', 2),
(2, 'GV02', 'ThS. Trần Thị Bình', 'Khoa Công nghệ thông tin', 'binhtt@cntt.edu.vn', 2),
(3, 'GV03', 'PGS.TS. Lê Hoàng Cường', 'Khoa Công nghệ thông tin', 'cuonglh@cntt.edu.vn', 3),
(4, 'GV04', 'ThS. Phạm Minh Dũng', 'Khoa Công nghệ thông tin', 'dungpm@cntt.edu.vn', 2),
(5, 'GV05', 'TS. Hoàng Thu Hà', 'Khoa Kinh tế & Quản trị kinh doanh', 'haht@cntt.edu.vn', 2),
(6, 'GV06', 'ThS. Vũ Quốc Khánh', 'Khoa Tài chính - Ngân hàng', 'khanhvq@cntt.edu.vn', 2);

-- 2. 6 Lớp học (classes) - Tự động tính giờ học theo số đầu của lớp (18-09, 18-10, 18-12 -> 7h20/13h20; 19-09, 19-10 -> 7h10/13h10)
INSERT INTO classes (id, code, name, cohort, student_count, major) VALUES
(1, 'CNTT 18-12', 'Lớp CNTT 18-12', '18', 35, 'Khoa Công nghệ thông tin'),
(2, 'CNTT 18-09', 'Lớp CNTT 18-09', '18', 42, 'Khoa Công nghệ thông tin'),
(3, 'CNTT 19-09', 'Lớp CNTT 19-09', '19', 50, 'Khoa Công nghệ thông tin'),
(4, 'KT 18-10',   'Lớp KT 18-10',   '18', 60, 'Khoa Kinh tế & Quản trị kinh doanh'),
(5, 'CNTT 19-10', 'Lớp CNTT 19-10', '19', 75, 'Khoa Công nghệ thông tin'),
(6, 'TCNH 18-11', 'Lớp TCNH 18-11', '18', 85, 'Khoa Tài chính - Ngân hàng');

-- 3. 5 Phòng học (rooms)
-- Gồm 3 phòng Lý thuyết (LT: 45, 65, 90 chỗ) và 2 phòng Thực hành máy tính (TH: 40, 55 chỗ)
INSERT INTO rooms (id, code, name, capacity, room_type, building) VALUES
(1, 'P101', 'Phòng Lý thuyết 101', 45, 'LT', 'Tòa A1'),
(2, 'P102', 'Phòng Lý thuyết 102', 65, 'LT', 'Tòa A1'),
(3, 'P201', 'Giảng đường lớn 201', 90, 'LT', 'Tòa A2'),
(4, 'LAB01', 'Phòng máy Thực hành 01', 40, 'TH', 'Tòa B1'),
(5, 'LAB02', 'Phòng máy Thực hành 02', 55, 'TH', 'Tòa B1');

-- 4. 20 Khung giờ (slots) - Mỗi ngày CHỈ CÓ 2 CA: Ca 1 (Buổi Sáng) và Ca 2 (Buổi Chiều)
-- 20 khung giờ trải trên 2 tuần học (Tuần 1 & Tuần 2, Thứ 2 -> Thứ 6, 2 ca/ngày)
INSERT INTO slots (id, code, week_no, day_of_week, period_of_day, start_time, end_time, label) VALUES
(1,  'T1-T2-S', 1, 2, 1, '07:20 / 07:10', '11:20 / 11:10', 'Tuần 1 - Thứ 2 - Ca Sáng'),
(2,  'T1-T2-C', 1, 2, 2, '13:20 / 13:10', '17:20 / 17:10', 'Tuần 1 - Thứ 2 - Ca Chiều'),
(3,  'T1-T3-S', 1, 3, 1, '07:20 / 07:10', '11:20 / 11:10', 'Tuần 1 - Thứ 3 - Ca Sáng'),
(4,  'T1-T3-C', 1, 3, 2, '13:20 / 13:10', '17:20 / 17:10', 'Tuần 1 - Thứ 3 - Ca Chiều'),
(5,  'T1-T4-S', 1, 4, 1, '07:20 / 07:10', '11:20 / 11:10', 'Tuần 1 - Thứ 4 - Ca Sáng'),
(6,  'T1-T4-C', 1, 4, 2, '13:20 / 13:10', '17:20 / 17:10', 'Tuần 1 - Thứ 4 - Ca Chiều'),
(7,  'T1-T5-S', 1, 5, 1, '07:20 / 07:10', '11:20 / 11:10', 'Tuần 1 - Thứ 5 - Ca Sáng'),
(8,  'T1-T5-C', 1, 5, 2, '13:20 / 13:10', '17:20 / 17:10', 'Tuần 1 - Thứ 5 - Ca Chiều'),
(9,  'T1-T6-S', 1, 6, 1, '07:20 / 07:10', '11:20 / 11:10', 'Tuần 1 - Thứ 6 - Ca Sáng'),
(10, 'T1-T6-C', 1, 6, 2, '13:20 / 13:10', '17:20 / 17:10', 'Tuần 1 - Thứ 6 - Ca Chiều'),
(11, 'T2-T2-S', 2, 2, 1, '07:20 / 07:10', '11:20 / 11:10', 'Tuần 2 - Thứ 2 - Ca Sáng'),
(12, 'T2-T2-C', 2, 2, 2, '13:20 / 13:10', '17:20 / 17:10', 'Tuần 2 - Thứ 2 - Ca Chiều'),
(13, 'T2-T3-S', 2, 3, 1, '07:20 / 07:10', '11:20 / 11:10', 'Tuần 2 - Thứ 3 - Ca Sáng'),
(14, 'T2-T3-C', 2, 3, 2, '13:20 / 13:10', '17:20 / 17:10', 'Tuần 2 - Thứ 3 - Ca Chiều'),
(15, 'T2-T4-S', 2, 4, 1, '07:20 / 07:10', '11:20 / 11:10', 'Tuần 2 - Thứ 4 - Ca Sáng'),
(16, 'T2-T4-C', 2, 4, 2, '13:20 / 13:10', '17:20 / 17:10', 'Tuần 2 - Thứ 4 - Ca Chiều'),
(17, 'T2-T5-S', 2, 5, 1, '07:20 / 07:10', '11:20 / 11:10', 'Tuần 2 - Thứ 5 - Ca Sáng'),
(18, 'T2-T5-C', 2, 5, 2, '13:20 / 13:10', '17:20 / 17:10', 'Tuần 2 - Thứ 5 - Ca Chiều'),
(19, 'T2-T6-S', 2, 6, 1, '07:20 / 07:10', '11:20 / 11:10', 'Tuần 2 - Thứ 6 - Ca Sáng'),
(20, 'T2-T6-C', 2, 6, 2, '13:20 / 13:10', '17:20 / 17:10', 'Tuần 2 - Thứ 6 - Ca Chiều');

-- 5. Lịch bận bắt buộc (HARD_BUSY) và Mong muốn ưu tiên (SOFT_AVOID, SOFT_PREFER)
INSERT INTO unavailability (teacher_id, slot_id, constraint_type, reason) VALUES
-- GV01: Bận sáng Thứ 2 (Ca 2), không thích dạy chiều muộn Thứ 2 (Ca 4), ưu tiên sáng Thứ 3
(1, 2,  'HARD_BUSY',   'Họp Hội đồng Khoa định kỳ sáng Thứ 2'),
(1, 4,  'SOFT_AVOID',  'Mong muốn hạn chế dạy Ca 4 cuối ngày Thứ 2'),
(1, 5,  'SOFT_PREFER', 'Ưu tiên dạy Ca 1 sáng Thứ 3'),

-- GV02: Bận từ Ca 3 Thứ 2 đến hết tuần -> chỉ rảnh Ca 1, Ca 2 Thứ 2
(2, 3,  'HARD_BUSY',   'Phụ trách phòng Lab nghiên cứu'),
(2, 4,  'HARD_BUSY',   'Hướng dẫn nghiên cứu sinh chiều Thứ 2'),
(2, 5,  'HARD_BUSY',   'Giảng dạy sau đại học tại cơ sở 2'),
(2, 6,  'HARD_BUSY',   'Giảng dạy sau đại học tại cơ sở 2'),
(2, 7,  'HARD_BUSY',   'Họp chuyên môn bộ môn HTTT'),
(2, 8,  'HARD_BUSY',   'Họp chuyên môn bộ môn HTTT'),
(2, 9,  'HARD_BUSY',   'Lịch công tác dự án chuyển đổi số'),
(2, 10, 'HARD_BUSY',   'Lịch công tác dự án chuyển đổi số'),
(2, 11, 'HARD_BUSY',   'Lịch công tác dự án chuyển đổi số'),
(2, 12, 'HARD_BUSY',   'Lịch công tác dự án chuyển đổi số'),
(2, 13, 'HARD_BUSY',   'Lịch trực cố vấn học tập'),
(2, 14, 'HARD_BUSY',   'Lịch trực cố vấn học tập'),
(2, 15, 'HARD_BUSY',   'Lịch hội thảo khoa học'),
(2, 16, 'HARD_BUSY',   'Lịch hội thảo khoa học'),
(2, 17, 'HARD_BUSY',   'Lịch chấm khóa luận'),
(2, 18, 'HARD_BUSY',   'Lịch chấm khóa luận'),
(2, 19, 'HARD_BUSY',   'Lịch chấm khóa luận'),
(2, 20, 'HARD_BUSY',   'Lịch chấm khóa luận'),

-- GV03: Chỉ rảnh Thứ 2 (Ca 1, 2, 3, 4), bận từ Thứ 3 đến Thứ 6
(3, 5,  'HARD_BUSY',   'Họp Ban chủ nhiệm Khoa'),
(3, 6,  'HARD_BUSY',   'Họp Ban chủ nhiệm Khoa'),
(3, 7,  'HARD_BUSY',   'Công tác Viện KHCN'),
(3, 8,  'HARD_BUSY',   'Công tác Viện KHCN'),
(3, 9,  'HARD_BUSY',   'Giảng dạy cao học'),
(3, 10, 'HARD_BUSY',   'Giảng dạy cao học'),
(3, 11, 'HARD_BUSY',   'Giảng dạy cao học'),
(3, 12, 'HARD_BUSY',   'Giảng dạy cao học'),
(3, 13, 'HARD_BUSY',   'Hội đồng thẩm định chương trình'),
(3, 14, 'HARD_BUSY',   'Hội đồng thẩm định chương trình'),
(3, 15, 'HARD_BUSY',   'Hội đồng thẩm định chương trình'),
(3, 16, 'HARD_BUSY',   'Hội đồng thẩm định chương trình'),
(3, 17, 'HARD_BUSY',   'Lịch nghiên cứu khoa học'),
(3, 18, 'HARD_BUSY',   'Lịch nghiên cứu khoa học'),
(3, 19, 'HARD_BUSY',   'Lịch nghiên cứu khoa học'),
(3, 20, 'HARD_BUSY',   'Lịch nghiên cứu khoa học'),

-- GV04: Một số mong muốn ưu tiên (rảnh tất cả các ca nhưng không ưu tiên Ca 1, Ca 4 Thứ 2)
(4, 1,  'SOFT_AVOID',  'Không ưu tiên dạy Ca 1 sáng sớm Thứ 2'),
(4, 4,  'SOFT_AVOID',  'Không ưu tiên dạy Ca 4 chiều muộn Thứ 2'),
(4, 9,  'SOFT_PREFER', 'Ưu tiên dạy sáng Thứ 4'),

-- GV05: Lịch bận chiều Thứ 4 và mong muốn ưu tiên
(5, 11, 'HARD_BUSY',   'Seminar nhóm nghiên cứu AI'),
(5, 12, 'HARD_BUSY',   'Seminar nhóm nghiên cứu AI'),
(5, 16, 'SOFT_AVOID',  'Không ưu tiên Ca 4 Thứ 5'),

-- GV06: Lịch bận rất chặt để kiểm thử tình huống nghẽn cổ chai (chỉ rảnh T2-C2)
(6, 1,  'HARD_BUSY',   'Lịch công tác doanh nghiệp'),
(6, 3,  'HARD_BUSY',   'Lịch công tác doanh nghiệp'),
(6, 4,  'HARD_BUSY',   'Lịch công tác doanh nghiệp'),
(6, 5,  'HARD_BUSY',   'Lịch công tác doanh nghiệp'),
(6, 6,  'HARD_BUSY',   'Lịch công tác doanh nghiệp'),
(6, 7,  'HARD_BUSY',   'Lịch công tác doanh nghiệp'),
(6, 8,  'HARD_BUSY',   'Lịch công tác doanh nghiệp'),
(6, 9,  'HARD_BUSY',   'Lịch công tác doanh nghiệp'),
(6, 10, 'HARD_BUSY',   'Lịch công tác doanh nghiệp'),
(6, 11, 'HARD_BUSY',   'Lịch công tác doanh nghiệp'),
(6, 12, 'HARD_BUSY',   'Lịch công tác doanh nghiệp'),
(6, 13, 'HARD_BUSY',   'Lịch công tác doanh nghiệp'),
(6, 14, 'HARD_BUSY',   'Lịch công tác doanh nghiệp'),
(6, 15, 'HARD_BUSY',   'Lịch công tác doanh nghiệp'),
(6, 16, 'HARD_BUSY',   'Lịch công tác doanh nghiệp'),
(6, 17, 'HARD_BUSY',   'Lịch công tác doanh nghiệp'),
(6, 18, 'HARD_BUSY',   'Lịch công tác doanh nghiệp'),
(6, 19, 'HARD_BUSY',   'Lịch công tác doanh nghiệp'),
(6, 20, 'HARD_BUSY',   'Lịch công tác doanh nghiệp');

-- 6. 20 Buổi học (lessons)
-- + BH01, BH02, BH03 là 3 buổi ĐÃ KHÓA CỐ ĐỊNH (is_locked = 1).
-- + BH05 (TH 42 SV của GV04) nếu xếp tuần tự BASIC sẽ chiếm mất (T2-C1, LAB02),
--   làm BH14 (của GV02 chỉ rảnh duy nhất T2-C1 và cần LAB02) không xếp được!
-- + BH09, BH10 (LT 70 SV của GV04, GV05) nếu xếp tuần tự BASIC sẽ chiếm mất P201 ở T2-C1 và T2-C2,
--   làm BH11, BH12, BH13 (của GV03 chỉ rảnh Thứ 2 và cần P201) bị rớt mất 2 buổi!
-- + Ngược lại, thuật toán IMPROVED (MRV + Best-Fit + Swap Repair) nhận diện BH11..BH14 có miền giá trị cực hẹp
--   nên xếp trước vào Thứ 2, đồng thời đẩy BH05, BH09, BH10 sang các ngày rảnh tiếp theo,
--   giúp xếp thành công 19/20 buổi và giảm mạnh điểm phạt vi phạm ưu tiên!
INSERT INTO lessons (id, code, subject_name, teacher_id, class_id, student_count, required_room_type, is_locked, locked_slot_id, locked_room_id, notes) VALUES
-- 3 Buổi học đã khóa cố định (Locked Lessons)
(1,  'BH01', 'Phát triển PM mã nguồn mở (LT - Khóa)', 1, 1, 35, 'LT', 1, 1, 1, 'Buổi học cố định đầu tuần tại P101 - Ca 1 Thứ 2'),
(2,  'BH02', 'Thực hành CSDL phân tán (TH - Khóa)',   2, 3, 50, 'TH', 1, 2, 5, 'Buổi thực hành cố định tại LAB02 - Ca 2 Thứ 2'),
(3,  'BH03', 'Nhập môn Trí tuệ nhân tạo (LT - Khóa)', 5, 5, 75, 'LT', 1, 3, 3, 'Giảng đường lớn P201 đã khóa Ca 3 Thứ 2'),

-- Các buổi học không khóa nhưng nằm đầu danh sách
(4,  'BH04', 'Lập trình Web với PHP (LT - Lớp 02)',    1, 2, 42, 'LT', 0, NULL, NULL, 'Có thể học P101, P102 hoặc P201'),
(5,  'BH05', 'Thực hành Lập trình Web (TH - Lớp 02)',  4, 2, 42, 'TH', 0, NULL, NULL, 'Sĩ số 42 cần LAB02 (55 chỗ) - GV04 rảnh cả tuần'),
(6,  'BH06', 'Cấu trúc dữ liệu & GT (LT - Lớp 01)',    5, 1, 35, 'LT', 0, NULL, NULL, 'Lớp 35 SV, ưu tiên phòng vừa sức chứa'),
(7,  'BH07', 'Mạng máy tính căn bản (LT - Lớp 01)',    4, 1, 35, 'LT', 0, NULL, NULL, 'Lớp 35 SV, tránh chiếm phòng lớn'),
(8,  'BH08', 'Thực hành Mạng máy tính (TH - Lớp 01)',  4, 1, 35, 'TH', 0, NULL, NULL, 'Lớp 35 SV vừa khít LAB01 (40 chỗ)'),
(9,  'BH09', 'Công nghệ phần mềm (LT - Ghép nhóm)',    4, 4, 70, 'LT', 0, NULL, NULL, 'Lớp ghép 70 SV cần P201 - GV04 rảnh cả tuần'),
(10, 'BH10', 'Hệ quản trị CSDL MySQL (LT - Ghép)',     5, 4, 70, 'LT', 0, NULL, NULL, 'Lớp ghép 70 SV cần P201 - GV05 rảnh nhiều ngày'),

-- Các buổi có ràng buộc rất chặt về phòng lớn (P201 - 90 chỗ) và khung giờ rảnh của GV03 (chỉ rảnh Thứ 2)
(11, 'BH11', 'Kiến trúc máy tính (LT - Lớp lớn 05)',   3, 5, 75, 'LT', 0, NULL, NULL, 'Bắt buộc phòng P201 (90 chỗ) - GV03 chỉ rảnh Thứ 2'),
(12, 'BH12', 'An toàn hệ thống (LT - Lớp lớn 06)',     3, 6, 85, 'LT', 0, NULL, NULL, 'Bắt buộc phòng P201 (90 chỗ) - GV03 chỉ rảnh Thứ 2'),
(13, 'BH13', 'Phân tích thiết kế HTTT (LT - Lớp 06)',  3, 6, 85, 'LT', 0, NULL, NULL, 'Bắt buộc phòng P201 (90 chỗ) - GV03 chỉ rảnh Thứ 2'),

-- Buổi thực hành lớp 50 SV của GV02 (chỉ còn rảnh T2-C1 và bắt buộc phòng LAB02 55 chỗ)
(14, 'BH14', 'Thực hành Phân tích HTTT (TH - Lớp 03)', 2, 3, 50, 'TH', 0, NULL, NULL, 'Bắt buộc LAB02 (55 chỗ) tại T2-C1 vì GV02 bận các ca khác'),

-- Các buổi học còn lại phân bổ trong tuần
(15, 'BH15', 'Học máy thống kê (LT - Lớp 04)',         5, 4, 60, 'LT', 0, NULL, NULL, 'Cần phòng LT >= 60 chỗ (P102 hoặc P201)'),
(16, 'BH16', 'Thực hành Học máy (TH - Lớp 01)',        5, 1, 35, 'TH', 0, NULL, NULL, 'Cần phòng TH >= 35 chỗ'),
(17, 'BH17', 'Quản trị mạng Linux (TH - Lớp 01)',      4, 1, 35, 'TH', 0, NULL, NULL, 'Cần phòng TH >= 35 chỗ'),
(18, 'BH18', 'Kiểm thử phần mềm (LT - Lớp 03)',        1, 3, 50, 'LT', 0, NULL, NULL, 'Cần phòng LT >= 50 chỗ'),
(19, 'BH19', 'Thực hành Kiểm thử PM (TH - Lớp 03)',    1, 3, 50, 'TH', 0, NULL, NULL, 'Cần phòng LAB02 (55 chỗ)'),

-- Buổi học số 20 có xung đột nghẽn cổ chai (GV06 chỉ rảnh duy nhất T2-C2 nhưng cần LAB02 đã bị BH02 khóa)
(20, 'BH20', 'Chuyên đề DevOps nâng cao (TH - Lớp 04)', 6, 4, 52, 'TH', 0, NULL, NULL, 'Nút cổ chai: GV06 chỉ rảnh T2-C2 nhưng LAB02 đã khóa cho BH02');

-- 7. Tài khoản người dùng do Nhà trường cấp sẵn theo 3 nhóm phân quyền (Mật khẩu mặc định: 123456)
-- Hash Bcrypt của '123456': $2y$12$FgZyAFRtM.Bjseurz7n/Y.YB2zZYhDW01vt4K9MZgiRFyK2wZgfFG
INSERT INTO users (id, username, password_hash, full_name, email, role, teacher_id, class_id) VALUES
(1,  'admin', '$2y$12$FgZyAFRtM.Bjseurz7n/Y.YB2zZYhDW01vt4K9MZgiRFyK2wZgfFG', 'Phòng Đào tạo (Người xếp lịch)', 'daotao@dainam.edu.vn', 'scheduler', NULL, NULL),
(2,  'gv01',  '$2y$12$FgZyAFRtM.Bjseurz7n/Y.YB2zZYhDW01vt4K9MZgiRFyK2wZgfFG', 'TS. Nguyễn Văn An',              'annv@cntt.edu.vn',     'teacher',   1,    NULL),
(3,  'gv02',  '$2y$12$FgZyAFRtM.Bjseurz7n/Y.YB2zZYhDW01vt4K9MZgiRFyK2wZgfFG', 'ThS. Trần Thị Bình',             'binhtt@cntt.edu.vn',   'teacher',   2,    NULL),
(4,  'gv03',  '$2y$12$FgZyAFRtM.Bjseurz7n/Y.YB2zZYhDW01vt4K9MZgiRFyK2wZgfFG', 'PGS.TS. Lê Hoàng Cường',         'cuonglh@cntt.edu.vn',  'teacher',   3,    NULL),
(5,  'gv04',  '$2y$12$FgZyAFRtM.Bjseurz7n/Y.YB2zZYhDW01vt4K9MZgiRFyK2wZgfFG', 'ThS. Phạm Minh Dũng',            'dungpm@cntt.edu.vn',   'teacher',   4,    NULL),
(6,  'gv05',  '$2y$12$FgZyAFRtM.Bjseurz7n/Y.YB2zZYhDW01vt4K9MZgiRFyK2wZgfFG', 'TS. Hoàng Thu Hà',               'haht@cntt.edu.vn',     'teacher',   5,    NULL),
(7,  'gv06',  '$2y$12$FgZyAFRtM.Bjseurz7n/Y.YB2zZYhDW01vt4K9MZgiRFyK2wZgfFG', 'ThS. Vũ Quốc Khánh',             'khanhvq@cntt.edu.vn',  'teacher',   6,    NULL),
(8,  'sv01',  '$2y$12$FgZyAFRtM.Bjseurz7n/Y.YB2zZYhDW01vt4K9MZgiRFyK2wZgfFG', 'Sinh viên Lớp CNTT 18-12',   '1812@sv.dainam.edu.vn','student',  NULL, 1),
(9,  'sv02',  '$2y$12$FgZyAFRtM.Bjseurz7n/Y.YB2zZYhDW01vt4K9MZgiRFyK2wZgfFG', 'Sinh viên Lớp CNTT 18-09',   '1809@sv.dainam.edu.vn','student',  NULL, 2),
(10, 'sv03',  '$2y$12$FgZyAFRtM.Bjseurz7n/Y.YB2zZYhDW01vt4K9MZgiRFyK2wZgfFG', 'Sinh viên Lớp CNTT 19-09',   '1909@sv.dainam.edu.vn','student',  NULL, 3),
(11, 'sv04',  '$2y$12$FgZyAFRtM.Bjseurz7n/Y.YB2zZYhDW01vt4K9MZgiRFyK2wZgfFG', 'Sinh viên Lớp KT 18-10',     '1810@sv.dainam.edu.vn','student',  NULL, 4),
(12, 'sv05',  '$2y$12$FgZyAFRtM.Bjseurz7n/Y.YB2zZYhDW01vt4K9MZgiRFyK2wZgfFG', 'Sinh viên Lớp CNTT 19-10',   '1910@sv.dainam.edu.vn','student',  NULL, 5),
(13, 'sv06',  '$2y$12$FgZyAFRtM.Bjseurz7n/Y.YB2zZYhDW01vt4K9MZgiRFyK2wZgfFG', 'Sinh viên Lớp TCNH 18-11',   '1811@sv.dainam.edu.vn','student',  NULL, 6);

SET FOREIGN_KEY_CHECKS = 1;
