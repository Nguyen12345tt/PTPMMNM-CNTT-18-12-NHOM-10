<?php
declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Lớp Truy xuất Dữ liệu (TimetableRepository) - Tách biệt hoàn toàn truy vấn MySQL
 * khỏi tầng xử lý thuật toán (Domain) và tầng giao diện (View/Controller).
 */
class TimetableRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Tải toàn bộ bộ dữ liệu hiện tại phục vụ kiểm tra ràng buộc và chạy thuật toán.
     */
    public function loadFullDataset(): array
    {
        return [
            'faculties' => $this->getFaculties(),
            'teachers' => $this->getTeachers(),
            'classes' => $this->getClasses(),
            'rooms' => $this->getRooms(),
            'slots' => $this->getSlots(),
            'unavailability' => $this->getUnavailability(),
            'lessons' => $this->getLessons(),
            'users' => $this->getUsers(),
        ];
    }

    public function getFaculties(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM faculties ORDER BY id ASC");
        return $stmt->fetchAll();
    }

    public function createFaculty(array $data): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO faculties (code, name) VALUES (:code, :name)");
        $stmt->execute([
            'code' => strtoupper(trim((string)$data['code'])),
            'name' => trim((string)$data['name']),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateFaculty(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare("UPDATE faculties SET code = :code, name = :name WHERE id = :id");
        $stmt->execute([
            'id' => $id,
            'code' => strtoupper(trim((string)$data['code'])),
            'name' => trim((string)$data['name']),
        ]);
    }

    public function deleteFaculty(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM faculties WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    public function getTeachers(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM teachers ORDER BY id ASC");
        return $stmt->fetchAll();
    }

    public function getClasses(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM classes ORDER BY id ASC");
        return $stmt->fetchAll();
    }

    public function getRooms(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM rooms ORDER BY id ASC");
        return $stmt->fetchAll();
    }

    public function getSlots(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM slots ORDER BY week_no ASC, day_of_week ASC, period_of_day ASC, id ASC");
        return $stmt->fetchAll();
    }

    public function getUnavailability(): array
    {
        $sql = "SELECT u.*, t.code AS teacher_code, t.name AS teacher_name,
                       s.code AS slot_code, s.label AS slot_label
                FROM unavailability u
                JOIN teachers t ON u.teacher_id = t.id
                JOIN slots s ON u.slot_id = s.id
                ORDER BY u.teacher_id ASC, s.week_no ASC, s.day_of_week ASC, s.period_of_day ASC";
        return $this->pdo->query($sql)->fetchAll();
    }

    public function getLessons(): array
    {
        $sql = "SELECT l.*,
                       t.code AS teacher_code, t.name AS teacher_name,
                       c.code AS class_code, c.name AS class_name, c.cohort AS class_cohort,
                       s.code AS locked_slot_code, s.label AS locked_slot_label,
                       r.code AS locked_room_code, r.name AS locked_room_name
                FROM lessons l
                JOIN teachers t ON l.teacher_id = t.id
                JOIN classes c ON l.class_id = c.id
                LEFT JOIN slots s ON l.locked_slot_id = s.id
                LEFT JOIN rooms r ON l.locked_room_id = r.id
                ORDER BY l.id ASC";
        return $this->pdo->query($sql)->fetchAll();
    }

    public function getLessonById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM lessons WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ==================== QUẢN LÝ TÀI KHOẢN & PHÂN QUYỀN (USERS) ====================
    public function findUserByUsername(string $username): ?array
    {
        $sql = "SELECT u.*, t.code AS teacher_code, t.name AS teacher_name,
                       c.code AS class_code, c.name AS class_name
                FROM users u
                LEFT JOIN teachers t ON u.teacher_id = t.id
                LEFT JOIN classes c ON u.class_id = c.id
                WHERE u.username = :username
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['username' => trim($username)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findUserById(int $id): ?array
    {
        $sql = "SELECT u.*, t.code AS teacher_code, t.name AS teacher_name,
                       c.code AS class_code, c.name AS class_name
                FROM users u
                LEFT JOIN teachers t ON u.teacher_id = t.id
                LEFT JOIN classes c ON u.class_id = c.id
                WHERE u.id = :id
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getUsers(): array
    {
        $sql = "SELECT u.id, u.username, u.full_name, u.email, u.role, u.teacher_id, u.class_id, u.created_at,
                       t.code AS teacher_code, t.name AS teacher_name,
                       c.code AS class_code, c.name AS class_name, c.major AS class_major
                FROM users u
                LEFT JOIN teachers t ON u.teacher_id = t.id
                LEFT JOIN classes c ON u.class_id = c.id
                ORDER BY u.id ASC";
        return $this->pdo->query($sql)->fetchAll();
    }

    public function createUser(array $data): int
    {
        $role = (string)($data['role'] ?? 'student');
        $teacherId = ($role === 'teacher' && !empty($data['teacher_id'])) ? (int)$data['teacher_id'] : null;
        $classId = ($role === 'student' && !empty($data['class_id'])) ? (int)$data['class_id'] : null;

        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, password_hash, full_name, email, role, teacher_id, class_id)
             VALUES (:username, :password_hash, :full_name, :email, :role, :teacher_id, :class_id)"
        );
        $stmt->execute([
            'username' => trim((string)$data['username']),
            'password_hash' => (string)$data['password_hash'],
            'full_name' => trim((string)$data['full_name']),
            'email' => trim((string)($data['email'] ?? '')),
            'role' => $role,
            'teacher_id' => $teacherId,
            'class_id' => $classId,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateUser(int $id, array $data): void
    {
        $role = (string)($data['role'] ?? 'student');
        $teacherId = ($role === 'teacher' && !empty($data['teacher_id'])) ? (int)$data['teacher_id'] : null;
        $classId = ($role === 'student' && !empty($data['class_id'])) ? (int)$data['class_id'] : null;

        if (!empty($data['password_hash'])) {
            $stmt = $this->pdo->prepare(
                "UPDATE users
                 SET username = :username, password_hash = :password_hash, full_name = :full_name,
                     email = :email, role = :role, teacher_id = :teacher_id, class_id = :class_id
                 WHERE id = :id"
            );
            $stmt->execute([
                'id' => $id,
                'username' => trim((string)$data['username']),
                'password_hash' => (string)$data['password_hash'],
                'full_name' => trim((string)$data['full_name']),
                'email' => trim((string)($data['email'] ?? '')),
                'role' => $role,
                'teacher_id' => $teacherId,
                'class_id' => $classId,
            ]);
        } else {
            $stmt = $this->pdo->prepare(
                "UPDATE users
                 SET username = :username, full_name = :full_name,
                     email = :email, role = :role, teacher_id = :teacher_id, class_id = :class_id
                 WHERE id = :id"
            );
            $stmt->execute([
                'id' => $id,
                'username' => trim((string)$data['username']),
                'full_name' => trim((string)$data['full_name']),
                'email' => trim((string)($data['email'] ?? '')),
                'role' => $role,
                'teacher_id' => $teacherId,
                'class_id' => $classId,
            ]);
        }
    }

    public function deleteUser(int $id): void
    {
        if ($id === 1) {
            return; // Không xóa tài khoản admin gốc
        }
        $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    // ==================== CRUD GIẢNG VIÊN ====================
    public function createTeacher(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO teachers (code, name, department, email, max_lessons_per_day)
             VALUES (:code, :name, :department, :email, :max_per_day)"
        );
        $stmt->execute([
            'code' => trim((string)$data['code']),
            'name' => trim((string)$data['name']),
            'department' => trim((string)($data['department'] ?? 'Công nghệ thông tin')),
            'email' => trim((string)($data['email'] ?? '')),
            'max_per_day' => (int)($data['max_lessons_per_day'] ?? 2),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function deleteTeacher(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM teachers WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    public function updateTeacher(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE teachers
             SET code = :code, name = :name, department = :department, max_lessons_per_day = :max_per_day
             WHERE id = :id"
        );
        $stmt->execute([
            'id' => $id,
            'code' => trim((string)$data['code']),
            'name' => trim((string)$data['name']),
            'department' => trim((string)($data['department'] ?? 'Công nghệ thông tin')),
            'max_per_day' => (int)($data['max_lessons_per_day'] ?? 2),
        ]);
    }

    // ==================== CRUD LỚP HỌC ====================
    public function createClass(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO classes (code, name, cohort, student_count, major)
             VALUES (:code, :name, :cohort, :student_count, :major)"
        );
        $stmt->execute([
            'code' => trim((string)$data['code']),
            'name' => trim((string)$data['name']),
            'cohort' => strtoupper(trim((string)($data['cohort'] ?? 'K18'))),
            'student_count' => (int)$data['student_count'],
            'major' => trim((string)($data['major'] ?? 'Khoa Công nghệ thông tin')),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateClass(int $id, array $data, bool $syncLessons = false): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE classes
             SET code = :code, name = :name, cohort = :cohort, student_count = :student_count, major = :major
             WHERE id = :id"
        );
        $stmt->execute([
            'id' => $id,
            'code' => trim((string)$data['code']),
            'name' => trim((string)$data['name']),
            'cohort' => strtoupper(trim((string)($data['cohort'] ?? 'K18'))),
            'student_count' => (int)$data['student_count'],
            'major' => trim((string)($data['major'] ?? 'Khoa Công nghệ thông tin')),
        ]);

        if ($syncLessons) {
            $syncStmt = $this->pdo->prepare("UPDATE lessons SET student_count = :student_count WHERE class_id = :class_id");
            $syncStmt->execute([
                'student_count' => (int)$data['student_count'],
                'class_id' => $id,
            ]);
        }
    }

    public function deleteClass(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM classes WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    // ==================== CRUD PHÒNG HỌC ====================
    public function createRoom(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO rooms (code, name, capacity, room_type, building)
             VALUES (:code, :name, :capacity, :room_type, :building)"
        );
        $stmt->execute([
            'code' => trim((string)$data['code']),
            'name' => trim((string)$data['name']),
            'capacity' => (int)$data['capacity'],
            'room_type' => (string)$data['room_type'],
            'building' => trim((string)($data['building'] ?? 'Tòa A1')),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateRoom(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE rooms
             SET code = :code, name = :name, capacity = :capacity, room_type = :room_type, building = :building
             WHERE id = :id"
        );
        $stmt->execute([
            'id' => $id,
            'code' => trim((string)$data['code']),
            'name' => trim((string)$data['name']),
            'capacity' => (int)$data['capacity'],
            'room_type' => (string)$data['room_type'],
            'building' => trim((string)($data['building'] ?? 'Tòa A1')),
        ]);
    }

    public function deleteRoom(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM rooms WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    // ==================== CRUD LỊCH BẬN & ƯU TIÊN ====================
    public function saveUnavailability(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO unavailability (teacher_id, slot_id, constraint_type, reason)
             VALUES (:teacher_id, :slot_id, :constraint_type, :reason)
             ON DUPLICATE KEY UPDATE constraint_type = VALUES(constraint_type), reason = VALUES(reason)"
        );
        $stmt->execute([
            'teacher_id' => (int)$data['teacher_id'],
            'slot_id' => (int)$data['slot_id'],
            'constraint_type' => (string)$data['constraint_type'],
            'reason' => trim((string)($data['reason'] ?? 'Đăng ký lịch bận / ưu tiên')),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function deleteUnavailability(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM unavailability WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    // ==================== CRUD BUỔI HỌC & KHÓA BUỔI HỌC ====================
    public function createLesson(array $data): int
    {
        $isLocked = (int)($data['is_locked'] ?? 0) === 1 ? 1 : 0;
        $lockedSlotId = $isLocked && !empty($data['locked_slot_id']) ? (int)$data['locked_slot_id'] : null;
        $lockedRoomId = $isLocked && !empty($data['locked_room_id']) ? (int)$data['locked_room_id'] : null;

        $stmt = $this->pdo->prepare(
            "INSERT INTO lessons (code, subject_name, teacher_id, class_id, student_count, required_room_type, is_locked, locked_slot_id, locked_room_id, notes)
             VALUES (:code, :subject_name, :teacher_id, :class_id, :student_count, :required_room_type, :is_locked, :locked_slot_id, :locked_room_id, :notes)"
        );
        $stmt->execute([
            'code' => trim((string)$data['code']),
            'subject_name' => trim((string)$data['subject_name']),
            'teacher_id' => (int)$data['teacher_id'],
            'class_id' => (int)$data['class_id'],
            'student_count' => (int)$data['student_count'],
            'required_room_type' => (string)$data['required_room_type'],
            'is_locked' => $isLocked,
            'locked_slot_id' => $lockedSlotId,
            'locked_room_id' => $lockedRoomId,
            'notes' => trim((string)($data['notes'] ?? '')),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateLesson(int $id, array $data): void
    {
        $isLocked = (int)($data['is_locked'] ?? 0) === 1 ? 1 : 0;
        $lockedSlotId = $isLocked && !empty($data['locked_slot_id']) ? (int)$data['locked_slot_id'] : null;
        $lockedRoomId = $isLocked && !empty($data['locked_room_id']) ? (int)$data['locked_room_id'] : null;

        $stmt = $this->pdo->prepare(
            "UPDATE lessons
             SET code = :code,
                 subject_name = :subject_name,
                 teacher_id = :teacher_id,
                 class_id = :class_id,
                 student_count = :student_count,
                 required_room_type = :required_room_type,
                 is_locked = :is_locked,
                 locked_slot_id = :locked_slot_id,
                 locked_room_id = :locked_room_id,
                 notes = :notes
             WHERE id = :id"
        );
        $stmt->execute([
            'id' => $id,
            'code' => trim((string)$data['code']),
            'subject_name' => trim((string)$data['subject_name']),
            'teacher_id' => (int)$data['teacher_id'],
            'class_id' => (int)$data['class_id'],
            'student_count' => (int)$data['student_count'],
            'required_room_type' => (string)$data['required_room_type'],
            'is_locked' => $isLocked,
            'locked_slot_id' => $lockedSlotId,
            'locked_room_id' => $lockedRoomId,
            'notes' => trim((string)($data['notes'] ?? '')),
        ]);
    }

    public function toggleLessonLock(int $lessonId, bool $lock, ?int $slotId = null, ?int $roomId = null): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE lessons
             SET is_locked = :is_locked,
                 locked_slot_id = :locked_slot_id,
                 locked_room_id = :locked_room_id
             WHERE id = :id"
        );
        $stmt->execute([
            'id' => $lessonId,
            'is_locked' => $lock ? 1 : 0,
            'locked_slot_id' => $lock ? $slotId : null,
            'locked_room_id' => $lock ? $roomId : null,
        ]);
    }

    public function deleteLesson(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM lessons WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    // ==================== QUẢN LÝ PHƯƠNG ÁN THỜI KHÓA BIỂU (VERSIONS) ====================
    public function saveScheduleVersion(
        string $versionName,
        string $description,
        array $scheduleResult,
        array $dataset,
        bool $setActive = true
    ): int {
        $this->pdo->beginTransaction();
        try {
            if ($setActive) {
                $this->pdo->exec("UPDATE schedule_versions SET is_active = 0");
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO schedule_versions (
                    version_name, algorithm_type, description,
                    total_lessons, scheduled_count, unscheduled_count,
                    hard_violations, soft_penalty_score,
                    dataset_hash, constraint_snapshot_json, unscheduled_details_json, is_active
                ) VALUES (
                    :version_name, :algorithm_type, :description,
                    :total_lessons, :scheduled_count, :unscheduled_count,
                    :hard_violations, :soft_penalty_score,
                    :dataset_hash, :constraint_snapshot_json, :unscheduled_details_json, :is_active
                )"
            );

            $stmt->execute([
                'version_name' => $versionName,
                'algorithm_type' => $scheduleResult['algorithm_type'],
                'description' => $description,
                'total_lessons' => (int)$scheduleResult['total_lessons'],
                'scheduled_count' => (int)$scheduleResult['scheduled_count'],
                'unscheduled_count' => (int)$scheduleResult['unscheduled_count'],
                'hard_violations' => (int)$scheduleResult['hard_violations'],
                'soft_penalty_score' => (int)$scheduleResult['soft_penalty_score'],
                'dataset_hash' => (string)$scheduleResult['dataset_hash'],
                'constraint_snapshot_json' => json_encode($dataset, JSON_UNESCAPED_UNICODE),
                'unscheduled_details_json' => json_encode($scheduleResult['unscheduled_lessons'], JSON_UNESCAPED_UNICODE),
                'is_active' => $setActive ? 1 : 0,
            ]);

            $versionId = (int)$this->pdo->lastInsertId();

            $insertAssign = $this->pdo->prepare(
                "INSERT INTO schedule_assignments (version_id, lesson_id, slot_id, room_id, is_locked, soft_penalty, soft_notes)
                 VALUES (:version_id, :lesson_id, :slot_id, :room_id, :is_locked, :soft_penalty, :soft_notes)"
            );

            foreach ($scheduleResult['assignments'] as $a) {
                $insertAssign->execute([
                    'version_id' => $versionId,
                    'lesson_id' => (int)$a['lesson_id'],
                    'slot_id' => (int)$a['slot_id'],
                    'room_id' => (int)$a['room_id'],
                    'is_locked' => (int)$a['is_locked'],
                    'soft_penalty' => (int)($a['soft_penalty'] ?? 0),
                    'soft_notes' => (string)($a['soft_notes'] ?? ''),
                ]);
            }

            $this->pdo->commit();
            return $versionId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function getScheduleVersions(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM schedule_versions ORDER BY id DESC");
        return $stmt->fetchAll();
    }

    public function getScheduleVersionById(int $versionId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM schedule_versions WHERE id = :id");
        $stmt->execute(['id' => $versionId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getActiveScheduleVersion(): ?array
    {
        $stmt = $this->pdo->query("SELECT * FROM schedule_versions WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
        $stmt = $this->pdo->query("SELECT * FROM schedule_versions ORDER BY id DESC LIMIT 1");
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function setActiveVersion(int $versionId): void
    {
        $this->pdo->exec("UPDATE schedule_versions SET is_active = 0");
        $stmt = $this->pdo->prepare("UPDATE schedule_versions SET is_active = 1 WHERE id = :id");
        $stmt->execute(['id' => $versionId]);
    }

    public function deleteVersion(int $versionId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM schedule_versions WHERE id = :id");
        $stmt->execute(['id' => $versionId]);
    }

    public function getAssignmentsByVersion(int $versionId): array
    {
        $sql = "SELECT sa.*,
                       l.code AS lesson_code, l.subject_name, l.student_count, l.required_room_type,
                       l.teacher_id, l.class_id, l.is_locked AS current_lesson_locked,
                       t.code AS teacher_code, t.name AS teacher_name,
                       c.code AS class_code, c.name AS class_name, c.cohort AS class_cohort,
                       s.code AS slot_code, s.label AS slot_label, s.week_no, s.day_of_week, s.period_of_day, s.start_time, s.end_time,
                       r.code AS room_code, r.name AS room_name, r.capacity AS room_capacity, r.room_type
                FROM schedule_assignments sa
                JOIN lessons l ON sa.lesson_id = l.id
                JOIN teachers t ON l.teacher_id = t.id
                JOIN classes c ON l.class_id = c.id
                JOIN slots s ON sa.slot_id = s.id
                JOIN rooms r ON sa.room_id = r.id
                WHERE sa.version_id = :version_id
                ORDER BY s.week_no ASC, s.day_of_week ASC, s.period_of_day ASC, r.code ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['version_id' => $versionId]);
        return $stmt->fetchAll();
    }

    /**
     * Cập nhật vị trí 1 buổi học trong phương án hiện tại (sau khi đã qua kiểm tra điều kiện bắt buộc)
     * và đồng bộ lại thống kê của phương án (scheduled_count, unscheduled_count, soft_penalty_score).
     */
    public function applyManualAssignment(
        int $versionId,
        int $lessonId,
        int $slotId,
        int $roomId,
        int $softPenalty,
        string $softNotes,
        array $auditAfter
    ): void {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO schedule_assignments (version_id, lesson_id, slot_id, room_id, is_locked, soft_penalty, soft_notes)
                 VALUES (:version_id, :lesson_id, :slot_id, :room_id, 0, :soft_penalty, :soft_notes)
                 ON DUPLICATE KEY UPDATE
                    slot_id = VALUES(slot_id),
                    room_id = VALUES(room_id),
                    soft_penalty = VALUES(soft_penalty),
                    soft_notes = VALUES(soft_notes)"
            );
            $stmt->execute([
                'version_id' => $versionId,
                'lesson_id' => $lessonId,
                'slot_id' => $slotId,
                'room_id' => $roomId,
                'soft_penalty' => $softPenalty,
                'soft_notes' => $softNotes,
            ]);

            $updateVer = $this->pdo->prepare(
                "UPDATE schedule_versions
                 SET scheduled_count = :scheduled_count,
                     unscheduled_count = :unscheduled_count,
                     hard_violations = :hard_violations,
                     soft_penalty_score = :soft_penalty_score,
                     unscheduled_details_json = :unscheduled_json
                 WHERE id = :id"
            );
            $updateVer->execute([
                'id' => $versionId,
                'scheduled_count' => (int)$auditAfter['scheduled_count'],
                'unscheduled_count' => (int)$auditAfter['unscheduled_count'],
                'hard_violations' => (int)$auditAfter['hard_violations'],
                'soft_penalty_score' => (int)$auditAfter['soft_penalty_score'],
                'unscheduled_json' => json_encode($auditAfter['unscheduled_lessons'], JSON_UNESCAPED_UNICODE),
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
