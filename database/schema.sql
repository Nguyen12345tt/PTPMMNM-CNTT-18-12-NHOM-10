-- ============================================================================
-- ĐỀ TÀI 18: HỆ THỐNG XẾP THỜI KHÓA BIỂU THEO RÀNG BUỘC
-- GIẢNG VIÊN, PHÒNG HỌC VÀ LỚP HỌC
-- Học phần: Phát triển phần mềm mã nguồn mở - Nhóm 10
-- CSDL: MySQL 8.0+ / 9.x (UTF8MB4)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS schedule_assignments;
DROP TABLE IF EXISTS schedule_versions;
DROP TABLE IF EXISTS lessons;
DROP TABLE IF EXISTS unavailability;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS slots;
DROP TABLE IF EXISTS rooms;
DROP TABLE IF EXISTS classes;
DROP TABLE IF EXISTS teachers;
DROP TABLE IF EXISTS faculties;

-- 0. Bảng Danh mục Khoa đào tạo (faculties)
CREATE TABLE faculties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1. Bảng Giảng viên (teachers)
CREATE TABLE teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    department VARCHAR(100) NOT NULL DEFAULT 'Công nghệ thông tin',
    email VARCHAR(120) DEFAULT NULL,
    max_lessons_per_day INT NOT NULL DEFAULT 2 COMMENT 'Mong muốn ưu tiên: số ca dạy tối đa mỗi ngày',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Bảng Lớp hành chính / Lớp học phần (classes)
CREATE TABLE classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    cohort VARCHAR(10) NOT NULL DEFAULT 'K18' COMMENT 'Khối/Khóa: K18 (Sáng 7h20, Chiều 13h20), K19 (Sáng 7h10, Chiều 13h10), K17 (Sáng 7h00, Chiều 13h00)',
    student_count INT NOT NULL CHECK (student_count > 0),
    major VARCHAR(100) NOT NULL DEFAULT 'Khoa Công nghệ thông tin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Bảng Phòng học (rooms)
CREATE TABLE rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    capacity INT NOT NULL CHECK (capacity > 0),
    room_type ENUM('LT', 'TH', 'TN') NOT NULL DEFAULT 'LT' COMMENT 'LT: Lý thuyết, TH: Thực hành máy tính, TN: Thí nghiệm',
    building VARCHAR(50) NOT NULL DEFAULT 'Tòa A1',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Bảng Khung giờ (slots) - 1 ngày chỉ có 2 Ca (Ca 1: Buổi Sáng, Ca 2: Buổi Chiều)
-- 20 khung giờ = 2 tuần học × 5 ngày (Thứ 2 -> Thứ 6) × 2 Ca/ngày (Ca Sáng & Ca Chiều)
CREATE TABLE slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    week_no TINYINT NOT NULL DEFAULT 1 COMMENT '1: Tuần 1, 2: Tuần 2',
    day_of_week TINYINT NOT NULL COMMENT '2: Thứ 2, 3: Thứ 3, 4: Thứ 4, 5: Thứ 5, 6: Thứ 6',
    period_of_day TINYINT NOT NULL COMMENT '1: Ca Sáng (7h20 K18 / 7h10 K19), 2: Ca Chiều (13h20 K18 / 13h10 K19)',
    start_time VARCHAR(50) NOT NULL,
    end_time VARCHAR(50) NOT NULL,
    label VARCHAR(150) NOT NULL,
    UNIQUE KEY uq_week_day_period (week_no, day_of_week, period_of_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Bảng Lịch bận & Mong muốn ưu tiên của giảng viên (unavailability)
-- Phân biệt rõ điều kiện bắt buộc (HARD_BUSY) và mong muốn ưu tiên (SOFT_AVOID, SOFT_PREFER)
CREATE TABLE unavailability (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    slot_id INT NOT NULL,
    constraint_type ENUM('HARD_BUSY', 'SOFT_AVOID', 'SOFT_PREFER') NOT NULL DEFAULT 'HARD_BUSY'
        COMMENT 'HARD_BUSY: Bận bắt buộc (cấm xếp), SOFT_AVOID: Không ưu tiên (phạt mềm), SOFT_PREFER: Khung giờ mong muốn (thưởng mềm)',
    reason VARCHAR(255) NOT NULL DEFAULT 'Lịch công tác / Cá nhân',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_teacher_slot (teacher_id, slot_id),
    CONSTRAINT fk_unavail_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    CONSTRAINT fk_unavail_slot FOREIGN KEY (slot_id) REFERENCES slots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Bảng Buổi học cần xếp lịch (lessons)
-- Mỗi buổi chiếm 1 khung giờ trong phần bắt buộc; hỗ trợ khóa cố định (is_locked)
CREATE TABLE lessons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    subject_name VARCHAR(150) NOT NULL,
    teacher_id INT NOT NULL,
    class_id INT NOT NULL,
    student_count INT NOT NULL CHECK (student_count > 0),
    required_room_type ENUM('LT', 'TH', 'TN') NOT NULL DEFAULT 'LT',
    is_locked TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1: Đã khóa cố định khung giờ & phòng, thuật toán không được đổi',
    locked_slot_id INT DEFAULT NULL,
    locked_room_id INT DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_lesson_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    CONSTRAINT fk_lesson_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_lesson_locked_slot FOREIGN KEY (locked_slot_id) REFERENCES slots(id) ON DELETE SET NULL,
    CONSTRAINT fk_lesson_locked_room FOREIGN KEY (locked_room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Bảng Phương án thời khóa biểu (schedule_versions)
CREATE TABLE schedule_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version_name VARCHAR(150) NOT NULL,
    algorithm_type ENUM('BASIC', 'IMPROVED', 'MANUAL') NOT NULL DEFAULT 'BASIC',
    description VARCHAR(255) DEFAULT NULL,
    total_lessons INT NOT NULL DEFAULT 0,
    scheduled_count INT NOT NULL DEFAULT 0,
    unscheduled_count INT NOT NULL DEFAULT 0,
    hard_violations INT NOT NULL DEFAULT 0,
    soft_penalty_score INT NOT NULL DEFAULT 0,
    dataset_hash VARCHAR(64) NOT NULL COMMENT 'Mã băm dữ liệu đầu vào để đối chiếu 2 phương án trên cùng dữ liệu',
    constraint_snapshot_json LONGTEXT NOT NULL COMMENT 'Snapshot dữ liệu buổi học, phòng, giảng viên, lịch bận tại thời điểm xếp',
    unscheduled_details_json LONGTEXT NOT NULL COMMENT 'Danh sách buổi chưa xếp được và nguyên nhân chi tiết',
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Bảng Chi tiết phân công trong từng phương án (schedule_assignments)
CREATE TABLE schedule_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version_id INT NOT NULL,
    lesson_id INT NOT NULL,
    slot_id INT NOT NULL,
    room_id INT NOT NULL,
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    soft_penalty INT NOT NULL DEFAULT 0,
    soft_notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_version_lesson (version_id, lesson_id),
    CONSTRAINT fk_assign_version FOREIGN KEY (version_id) REFERENCES schedule_versions(id) ON DELETE CASCADE,
    CONSTRAINT fk_assign_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
    CONSTRAINT fk_assign_slot FOREIGN KEY (slot_id) REFERENCES slots(id) ON DELETE CASCADE,
    CONSTRAINT fk_assign_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Bảng Tài khoản Người sử dụng & Phân quyền (users)
-- Hỗ trợ Đăng ký, Đăng nhập (mật khẩu băm Bcrypt) và Phân quyền 3 vai trò:
-- + scheduler: Người phụ trách đào tạo / Người xếp lịch (Toàn quyền)
-- + teacher:   Giảng viên (Xem/Xuất lịch dạy & Đăng ký lịch bận cá nhân)
-- + student:   Sinh viên / Lớp học (Xem & Xuất thời khóa biểu của lớp liên quan)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(120) DEFAULT NULL,
    role ENUM('scheduler', 'teacher', 'student') NOT NULL DEFAULT 'student',
    teacher_id INT DEFAULT NULL COMMENT 'Liên kết giảng viên nếu role = teacher',
    class_id INT DEFAULT NULL COMMENT 'Liên kết lớp học nếu role = student',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE SET NULL,
    CONSTRAINT fk_user_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
