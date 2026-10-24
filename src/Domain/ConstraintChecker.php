<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * Bộ kiểm tra ràng buộc (ConstraintChecker) - Tự hiện thực 100% bởi nhóm.
 *
 * Phân biệt rõ:
 * 1. Điều kiện bắt buộc (Hard Constraints): Vi phạm = tuyệt đối không được xếp.
 *    - Không trùng giảng viên trong cùng khung giờ.
 *    - Không trùng lớp học trong cùng khung giờ.
 *    - Không trùng phòng học trong cùng khung giờ.
 *    - Sức chứa phòng >= sĩ số buổi học.
 *    - Loại phòng đúng yêu cầu của buổi học (LT / TH / TN).
 *    - Giảng viên không nằm trong khung giờ bận bắt buộc (HARD_BUSY).
 *    - Buổi học đã khóa (is_locked = 1) không được thay đổi khung giờ và phòng đã khóa.
 *
 * 2. Mong muốn ưu tiên (Soft Constraints): Không cấm xếp nhưng tính điểm phạt (Soft Penalty)
 *    để thuật toán cải thiện tối ưu hóa chất lượng lịch:
 *    - Tránh khung giờ giảng viên không ưu tiên (SOFT_AVOID: +15đ).
 *    - Khuyến khích khung giờ giảng viên mong muốn (SOFT_PREFER: -4đ phạt).
 *    - Hạn chế xếp vào Ca 4 cuối ngày (period = 4: +4đ).
 *    - Tránh lãng phí phòng quá lớn so với sĩ số (+4đ khi dư >= 25 chỗ, +8đ khi dư >= 45 chỗ).
 *    - Tránh vượt số ca dạy khuyến nghị/ngày của giảng viên (+12đ) hoặc quá tải ca học/ngày của lớp (+8đ).
 */
class ConstraintChecker
{
    public const VALID_ROOM_TYPES = ['LT', 'TH', 'TN'];
    public const VALID_CONSTRAINT_TYPES = ['HARD_BUSY', 'SOFT_AVOID', 'SOFT_PREFER'];
    public const VALID_USER_ROLES = ['scheduler', 'teacher', 'student'];

    /**
     * Tự động nhận diện khóa từ số đầu của mã lớp (VD: "18-09", "18-12", "CNTT 18-12" -> "18"; "19-09" -> "19").
     */
    public static function extractCohortNumber(string $classCodeOrCohort): string
    {
        if (preg_match('/(\d{2})/', $classCodeOrCohort, $m)) {
            return $m[1];
        }
        return '18';
    }

    /**
     * Trả về giờ học thực tế theo số đầu của lớp (18-09, 18-10 -> 18; 19-09 -> 19) và Ca học trong ngày:
     * Mỗi ngày chỉ có 2 Ca:
     * - Đầu 18 (18-09, 18-10, 18-12...): Sáng 07:20 - 11:20 | Chiều 13:20 - 17:20
     * - Đầu 19 (19-09, 19-10...):        Sáng 07:10 - 11:10 | Chiều 13:10 - 17:10
     * - Đầu 17 (17-01...):               Sáng 07:00 - 11:00 | Chiều 13:00 - 17:00
     * - Đầu 20 (20-01...):               Sáng 07:30 - 11:30 | Chiều 13:30 - 17:30
     */
    public static function formatCohortTime(string $classCodeOrCohort, int $periodOfDay): string
    {
        $num = self::extractCohortNumber($classCodeOrCohort);
        $isMorning = ($periodOfDay === 1);
        return match ($num) {
            '17'    => $isMorning ? '07:00 - 11:00' : '13:00 - 17:00',
            '19'    => $isMorning ? '07:10 - 11:10' : '13:10 - 17:10',
            '20'    => $isMorning ? '07:30 - 11:30' : '13:30 - 17:30',
            default => $isMorning ? '07:20 - 11:20' : '13:20 - 17:20',
        };
    }

    /**
     * Kiểm tra tính hợp lệ của dữ liệu đầu vào khi thêm/cập nhật từng thực thể.
     * Chặn các giá trị sai trước khi lưu hoặc trước khi xếp lịch (Tiêu chí Dữ liệu & An toàn).
     */
    public function validateEntityInput(string $entityType, array $data, array $dataset = []): array
    {
        $errors = [];

        switch ($entityType) {
            case 'faculty':
                $code = trim((string)($data['code'] ?? ''));
                $name = trim((string)($data['name'] ?? ''));
                if ($code === '' || $name === '') {
                    $errors[] = 'Mã khoa và tên khoa không được để trống (VD: CNTT - Khoa Công nghệ thông tin).';
                }
                break;

            case 'user_register':
                $username = trim((string)($data['username'] ?? ''));
                $fullName = trim((string)($data['full_name'] ?? ''));
                $password = (string)($data['password'] ?? '');
                $passwordConfirm = (string)($data['password_confirm'] ?? '');
                $role = (string)($data['role'] ?? 'student');
                $teacherId = (int)($data['teacher_id'] ?? 0);
                $classId = (int)($data['class_id'] ?? 0);

                if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
                    $errors[] = 'Tên đăng nhập chỉ gồm chữ cái, chữ số hoặc dấu gạch dưới (từ 3 đến 30 ký tự).';
                }
                if ($fullName === '') {
                    $errors[] = 'Họ và tên người dùng không được để trống.';
                }
                if (strlen($password) < 6) {
                    $errors[] = 'Mật khẩu phải có ít nhất 6 ký tự.';
                }
                if ($password !== $passwordConfirm) {
                    $errors[] = 'Mật khẩu xác nhận không khớp.';
                }
                if (!in_array($role, self::VALID_USER_ROLES, true)) {
                    $errors[] = 'Vai trò phân quyền không hợp lệ.';
                }
                if ($role === 'teacher' && $teacherId <= 0) {
                    $errors[] = 'Tài khoản Giảng viên bắt buộc phải liên kết với một Mã giảng viên trong danh mục.';
                }
                if ($role === 'student' && $classId <= 0) {
                    $errors[] = 'Tài khoản Sinh viên / Lớp học bắt buộc phải chọn Lớp học trực thuộc.';
                }
                break;

            case 'room':
                $code = trim((string)($data['code'] ?? ''));
                $name = trim((string)($data['name'] ?? ''));
                $capacity = (int)($data['capacity'] ?? 0);
                $roomType = (string)($data['room_type'] ?? '');
                if ($code === '' || $name === '') {
                    $errors[] = 'Mã phòng và tên phòng không được để trống.';
                }
                if ($capacity <= 0 || $capacity > 500) {
                    $errors[] = 'Sức chứa phòng phải là số nguyên dương (1 - 500).';
                }
                if (!in_array($roomType, self::VALID_ROOM_TYPES, true)) {
                    $errors[] = 'Loại phòng không hợp lệ (chỉ chấp nhận LT, TH, TN).';
                }
                break;

            case 'teacher':
                $code = trim((string)($data['code'] ?? ''));
                $name = trim((string)($data['name'] ?? ''));
                $maxPerDay = (int)($data['max_lessons_per_day'] ?? 2);
                if ($code === '' || $name === '') {
                    $errors[] = 'Mã giảng viên và họ tên không được để trống.';
                }
                if ($maxPerDay < 1 || $maxPerDay > 4) {
                    $errors[] = 'Số ca dạy mong muốn tối đa mỗi ngày phải từ 1 đến 4.';
                }
                break;

            case 'class':
                $code = trim((string)($data['code'] ?? ''));
                $name = trim((string)($data['name'] ?? ''));
                $studentCount = (int)($data['student_count'] ?? 0);
                if ($code === '' || $name === '') {
                    $errors[] = 'Mã lớp và tên lớp không được để trống.';
                }
                if ($studentCount <= 0 || $studentCount > 500) {
                    $errors[] = 'Sĩ số lớp phải là số nguyên dương (1 - 500).';
                }
                break;

            case 'unavailability':
                $teacherId = (int)($data['teacher_id'] ?? 0);
                $slotId = (int)($data['slot_id'] ?? 0);
                $type = (string)($data['constraint_type'] ?? 'HARD_BUSY');
                if ($teacherId <= 0 || $slotId <= 0) {
                    $errors[] = 'Phải chọn giảng viên và khung giờ hợp lệ.';
                }
                if (!in_array($type, self::VALID_CONSTRAINT_TYPES, true)) {
                    $errors[] = 'Loại ràng buộc lịch bận/ưu tiên không hợp lệ.';
                }
                // Nếu thêm HARD_BUSY vào đúng khung giờ mà giảng viên đã có buổi học KHÓA CỐ ĐỊNH -> chặn!
                if ($type === 'HARD_BUSY' && !empty($dataset['lessons'])) {
                    foreach ($dataset['lessons'] as $lesson) {
                        if ((int)$lesson['is_locked'] === 1
                            && (int)$lesson['teacher_id'] === $teacherId
                            && (int)$lesson['locked_slot_id'] === $slotId
                        ) {
                            $errors[] = "Không thể đặt lịch bận bắt buộc (HARD_BUSY) tại khung giờ này vì giảng viên đang có buổi học đã khóa cố định ({$lesson['code']}).";
                        }
                    }
                }
                break;

            case 'lesson':
                $code = trim((string)($data['code'] ?? ''));
                $subject = trim((string)($data['subject_name'] ?? ''));
                $teacherId = (int)($data['teacher_id'] ?? 0);
                $classId = (int)($data['class_id'] ?? 0);
                $studentCount = (int)($data['student_count'] ?? 0);
                $roomType = (string)($data['required_room_type'] ?? '');
                $isLocked = (int)($data['is_locked'] ?? 0);
                $lockedSlotId = !empty($data['locked_slot_id']) ? (int)$data['locked_slot_id'] : null;
                $lockedRoomId = !empty($data['locked_room_id']) ? (int)$data['locked_room_id'] : null;
                $editingId = !empty($data['id']) ? (int)$data['id'] : null;

                if ($code === '' || $subject === '') {
                    $errors[] = 'Mã buổi học và tên học phần không được để trống.';
                }
                if ($teacherId <= 0 || $classId <= 0) {
                    $errors[] = 'Phải chọn giảng viên và lớp học hợp lệ.';
                }
                if ($studentCount <= 0 || $studentCount > 500) {
                    $errors[] = 'Sĩ số buổi học phải là số nguyên dương (1 - 500).';
                }
                if (!in_array($roomType, self::VALID_ROOM_TYPES, true)) {
                    $errors[] = 'Loại phòng yêu cầu không hợp lệ (chỉ chấp nhận LT, TH, TN).';
                }

                // Kiểm tra nghiêm ngặt khi khóa buổi học (is_locked = 1)
                if ($isLocked === 1) {
                    if ($lockedSlotId === null || $lockedRoomId === null) {
                        $errors[] = 'Khi khóa buổi học cố định, bắt buộc phải chọn rõ Khung giờ khóa và Phòng học khóa.';
                    } elseif (!empty($dataset)) {
                        $roomsById = $this->indexById($dataset['rooms'] ?? []);
                        $slotsById = $this->indexById($dataset['slots'] ?? []);
                        if (!isset($roomsById[$lockedRoomId]) || !isset($slotsById[$lockedSlotId])) {
                            $errors[] = 'Khung giờ khóa hoặc phòng học khóa không tồn tại.';
                        } else {
                            $room = $roomsById[$lockedRoomId];
                            $slot = $slotsById[$lockedSlotId];
                            if ($room['room_type'] !== $roomType) {
                                $errors[] = "Không thể khóa buổi học loại {$roomType} vào phòng {$room['code']} (loại {$room['room_type']}).";
                            }
                            if ((int)$room['capacity'] < $studentCount) {
                                $errors[] = "Không thể khóa buổi học ({$studentCount} SV) vào phòng {$room['code']} có sức chứa nhỏ hơn ({$room['capacity']} chỗ).";
                            }
                            // Kiểm tra lịch bận bắt buộc của giảng viên tại khung giờ khóa
                            foreach ($dataset['unavailability'] ?? [] as $u) {
                                if ((int)$u['teacher_id'] === $teacherId
                                    && (int)$u['slot_id'] === $lockedSlotId
                                    && $u['constraint_type'] === 'HARD_BUSY'
                                ) {
                                    $errors[] = "Không thể khóa buổi học vào khung giờ {$slot['code']} vì giảng viên bận bắt buộc ({$u['reason']}).";
                                }
                            }
                            // Kiểm tra xung đột với các buổi học đã khóa khác
                            foreach ($dataset['lessons'] ?? [] as $other) {
                                if ($editingId !== null && (int)$other['id'] === $editingId) {
                                    continue;
                                }
                                if ((int)$other['is_locked'] === 1 && (int)$other['locked_slot_id'] === $lockedSlotId) {
                                    if ((int)$other['teacher_id'] === $teacherId) {
                                        $errors[] = "Trùng giảng viên ở khung giờ khóa {$slot['code']} với buổi đã khóa {$other['code']}.";
                                    }
                                    if ((int)$other['class_id'] === $classId) {
                                        $errors[] = "Trùng lớp học ở khung giờ khóa {$slot['code']} với buổi đã khóa {$other['code']}.";
                                    }
                                    if ((int)$other['locked_room_id'] === $lockedRoomId) {
                                        $errors[] = "Trùng phòng {$room['code']} ở khung giờ khóa {$slot['code']} với buổi đã khóa {$other['code']}.";
                                    }
                                }
                            }
                        }
                    }
                }
                break;
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Kiểm tra toàn bộ dữ liệu trước khi chạy xếp lịch (Chức năng 2).
     * Phân biệt rõ:
     * - Điều kiện bắt buộc (Hard Constraints): lỗi dữ liệu, xung đột khóa cứng, cảnh báo nút cổ chai.
     * - Mong muốn ưu tiên (Soft Constraints): danh sách ưu tiên/hạn chế của giảng viên và lớp.
     */
    public function validateDataset(array $dataset): array
    {
        $hardErrors = [];
        $bottleneckWarnings = [];
        $softConstraintsSummary = [];

        $teachersById = $this->indexById($dataset['teachers'] ?? []);
        $classesById = $this->indexById($dataset['classes'] ?? []);
        $roomsById = $this->indexById($dataset['rooms'] ?? []);
        $slotsById = $this->indexById($dataset['slots'] ?? []);
        $lessons = $dataset['lessons'] ?? [];
        $unavailability = $dataset['unavailability'] ?? [];

        // Map lịch bận HARD_BUSY theo teacher_id -> [slot_id => reason]
        $hardBusyMap = [];
        foreach ($unavailability as $u) {
            $tId = (int)$u['teacher_id'];
            $sId = (int)$u['slot_id'];
            $tName = $teachersById[$tId]['name'] ?? "GV#$tId";
            $sCode = $slotsById[$sId]['code'] ?? "Slot#$sId";

            if ($u['constraint_type'] === 'HARD_BUSY') {
                $hardBusyMap[$tId][$sId] = $u['reason'];
            } elseif ($u['constraint_type'] === 'SOFT_AVOID') {
                $softConstraintsSummary[] = [
                    'type' => 'SOFT_AVOID',
                    'weight' => '+15 điểm phạt',
                    'target' => $tName,
                    'slot' => $sCode,
                    'detail' => "Giảng viên mong muốn tránh dạy tại {$sCode} ({$u['reason']})",
                ];
            } elseif ($u['constraint_type'] === 'SOFT_PREFER') {
                $softConstraintsSummary[] = [
                    'type' => 'SOFT_PREFER',
                    'weight' => '-4 điểm phạt (Ưu tiên)',
                    'target' => $tName,
                    'slot' => $sCode,
                    'detail' => "Giảng viên ưu tiên dạy tại {$sCode} ({$u['reason']})",
                ];
            }
        }

        // Bổ sung các quy tắc ưu tiên mềm toàn cục
        $softConstraintsSummary[] = [
            'type' => 'ROOM_WASTE',
            'weight' => '+4 đến +8 điểm phạt',
            'target' => 'Tất cả phòng học',
            'slot' => 'Mọi khung giờ',
            'detail' => 'Ưu tiên chọn phòng có sức chứa sát sĩ số lớp (phạt khi dư >= 25 chỗ) để giữ phòng lớn cho lớp đông.',
        ];
        $softConstraintsSummary[] = [
            'type' => 'LATE_PERIOD',
            'weight' => '+4 điểm phạt',
            'target' => 'Tất cả buổi học',
            'slot' => 'Ca 2 Chiều Thứ 6',
            'detail' => 'Hạn chế xếp vào Ca Chiều cuối tuần (Thứ 6) nếu còn khung giờ sớm hơn hợp lệ.',
        ];
        $softConstraintsSummary[] = [
            'type' => 'DAILY_LOAD',
            'weight' => '+8 đến +12 điểm phạt',
            'target' => 'Giảng viên & Lớp học',
            'slot' => 'Theo ngày (2 ca Sáng/Chiều)',
            'detail' => 'Hạn chế xếp vượt số ca khuyến nghị/ngày của giảng viên hoặc xếp kín cả 2 ca Sáng & Chiều liên tiếp trong cùng ngày.',
        ];

        // 1. Kiểm tra các buổi đã khóa (is_locked = 1)
        $lockedAssignments = [];
        foreach ($lessons as $lesson) {
            $lId = (int)$lesson['id'];
            $lCode = $lesson['code'];
            $tId = (int)$lesson['teacher_id'];
            $cId = (int)$lesson['class_id'];
            $studentCount = (int)$lesson['student_count'];
            $reqType = $lesson['required_room_type'];

            if ($studentCount <= 0) {
                $hardErrors[] = "[{$lCode}] Sĩ số buổi học không hợp lệ ({$studentCount}).";
            }

            // Kiểm tra có ít nhất 1 phòng trong toàn trường đáp ứng được loại phòng & sức chứa không
            $compatibleRooms = [];
            foreach ($roomsById as $rId => $room) {
                if ($room['room_type'] === $reqType && (int)$room['capacity'] >= $studentCount) {
                    $compatibleRooms[$rId] = $room;
                }
            }
            if (empty($compatibleRooms)) {
                $hardErrors[] = "[{$lCode}] Không có phòng học nào trong trường thuộc loại '{$reqType}' đạt sức chứa >= {$studentCount} SV.";
            }

            if ((int)$lesson['is_locked'] === 1) {
                $slotId = (int)($lesson['locked_slot_id'] ?? 0);
                $roomId = (int)($lesson['locked_room_id'] ?? 0);
                if (!isset($slotsById[$slotId]) || !isset($roomsById[$roomId])) {
                    $hardErrors[] = "[{$lCode}] Buổi học đã khóa nhưng thiếu khung giờ hoặc phòng học hợp lệ.";
                    continue;
                }
                $room = $roomsById[$roomId];
                $slot = $slotsById[$slotId];

                if ($room['room_type'] !== $reqType) {
                    $hardErrors[] = "[{$lCode}] Buổi khóa yêu cầu phòng loại {$reqType} nhưng đang khóa vào phòng {$room['code']} (loại {$room['room_type']}).";
                }
                if ((int)$room['capacity'] < $studentCount) {
                    $hardErrors[] = "[{$lCode}] Buổi khóa có sĩ số {$studentCount} SV vượt quá sức chứa phòng khóa {$room['code']} ({$room['capacity']} chỗ).";
                }
                if (isset($hardBusyMap[$tId][$slotId])) {
                    $hardErrors[] = "[{$lCode}] Buổi khóa tại {$slot['code']} trùng vào lịch bận bắt buộc của giảng viên ({$hardBusyMap[$tId][$slotId]}).";
                }

                // Kiểm tra trùng với buổi đã khóa khác
                foreach ($lockedAssignments as $prevLessonId => $prev) {
                    if ($prev['slot_id'] === $slotId) {
                        $prevCode = $prev['code'];
                        if ($prev['teacher_id'] === $tId) {
                            $hardErrors[] = "[{$lCode}] Trùng giảng viên tại {$slot['code']} với buổi đã khóa [{$prevCode}].";
                        }
                        if ($prev['class_id'] === $cId) {
                            $hardErrors[] = "[{$lCode}] Trùng lớp học tại {$slot['code']} với buổi đã khóa [{$prevCode}].";
                        }
                        if ($prev['room_id'] === $roomId) {
                            $hardErrors[] = "[{$lCode}] Trùng phòng {$room['code']} tại {$slot['code']} với buổi đã khóa [{$prevCode}].";
                        }
                    }
                }

                $lockedAssignments[$lId] = [
                    'lesson_id' => $lId,
                    'code' => $lCode,
                    'slot_id' => $slotId,
                    'room_id' => $roomId,
                    'teacher_id' => $tId,
                    'class_id' => $cId,
                    'is_locked' => 1,
                ];
            }
        }

        // 2. Phân tích miền giá trị hợp lệ ban đầu (Domain Analysis) cho từng buổi chưa khóa
        $lessonDomainCounts = [];
        foreach ($lessons as $lesson) {
            $lId = (int)$lesson['id'];
            if ((int)$lesson['is_locked'] === 1) {
                $lessonDomainCounts[$lId] = 1;
                continue;
            }
            $validPairs = 0;
            foreach ($slotsById as $sId => $slot) {
                foreach ($roomsById as $rId => $room) {
                    $check = $this->checkHardConstraintsForPlacement(
                        $lesson,
                        $slot,
                        $room,
                        $lockedAssignments,
                        $dataset,
                        $lId
                    );
                    if ($check['valid']) {
                        $validPairs++;
                    }
                }
            }
            $lessonDomainCounts[$lId] = $validPairs;
            if ($validPairs === 0) {
                $reason = $this->diagnoseUnscheduledReason($lesson, $lockedAssignments, $dataset);
                $bottleneckWarnings[] = "[{$lesson['code']} - {$lesson['subject_name']}] Không còn cặp (Khung giờ, Phòng) khả thi ngay từ đầu: {$reason}";
            } elseif ($validPairs <= 3) {
                $bottleneckWarnings[] = "[{$lesson['code']} - {$lesson['subject_name']}] Miền lựa chọn rất hẹp (chỉ có {$validPairs} phương án khả thi) — cần thuật toán MRV ưu tiên xếp sớm.";
            }
        }

        return [
            'is_ready_to_schedule' => empty($hardErrors),
            'hard_errors' => $hardErrors,
            'bottleneck_warnings' => $bottleneckWarnings,
            'soft_constraints_summary' => $softConstraintsSummary,
            'lesson_domain_counts' => $lessonDomainCounts,
            'stats' => [
                'total_lessons' => count($lessons),
                'locked_lessons' => count($lockedAssignments),
                'unlocked_lessons' => count($lessons) - count($lockedAssignments),
                'total_teachers' => count($teachersById),
                'total_classes' => count($classesById),
                'total_rooms' => count($roomsById),
                'total_slots' => count($slotsById),
                'hard_busy_count' => array_sum(array_map('count', $hardBusyMap)),
                'soft_pref_count' => count($unavailability) - array_sum(array_map('count', $hardBusyMap)),
            ],
        ];
    }

    /**
     * Kiểm tra toàn bộ Điều kiện bắt buộc (Hard Constraints) khi thử xếp $lesson vào ($slot, $room).
     *
     * @param array $lesson Thông tin buổi học cần xếp
     * @param array $slot Thông tin khung giờ đích
     * @param array $room Thông tin phòng học đích
     * @param array $currentAssignments Danh sách các buổi đã được gán [lesson_id => ['lesson_id', 'slot_id', 'room_id', ...]]
     * @param array $dataset Toàn bộ dữ liệu (teachers, classes, rooms, slots, lessons, unavailability)
     * @param int|null $ignoreLessonId Bỏ qua buổi học này khi kiểm tra trùng (dùng khi thử di chuyển 1 buổi đã xếp)
     */
    public function checkHardConstraintsForPlacement(
        array $lesson,
        array $slot,
        array $room,
        array $currentAssignments,
        array $dataset,
        ?int $ignoreLessonId = null
    ): array {
        $violations = [];
        $lessonId = (int)$lesson['id'];
        $slotId = (int)$slot['id'];
        $roomId = (int)$room['id'];
        $teacherId = (int)$lesson['teacher_id'];
        $classId = (int)$lesson['class_id'];
        $studentCount = (int)$lesson['student_count'];
        $reqRoomType = (string)$lesson['required_room_type'];

        // 1. Kiểm tra khóa cố định (Locked Lesson Immutability)
        if ((int)$lesson['is_locked'] === 1) {
            $lockedSlotId = (int)($lesson['locked_slot_id'] ?? 0);
            $lockedRoomId = (int)($lesson['locked_room_id'] ?? 0);
            if ($slotId !== $lockedSlotId || $roomId !== $lockedRoomId) {
                $violations[] = [
                    'code' => 'LOCKED_IMMUTABLE',
                    'message' => "Buổi học {$lesson['code']} đã bị khóa cố định, không được thay đổi khung giờ hoặc phòng học.",
                ];
            }
        }

        // 2. Kiểm tra loại phòng (Room Type Match)
        if ($room['room_type'] !== $reqRoomType) {
            $violations[] = [
                'code' => 'ROOM_TYPE_MISMATCH',
                'message' => "Phòng {$room['code']} thuộc loại {$room['room_type']}, không khớp loại phòng yêu cầu ({$reqRoomType}).",
            ];
        }

        // 3. Kiểm tra sức chứa phòng (Room Capacity >= Student Count)
        if ((int)$room['capacity'] < $studentCount) {
            $violations[] = [
                'code' => 'ROOM_CAPACITY_INSUFFICIENT',
                'message' => "Phòng {$room['code']} có sức chứa {$room['capacity']} chỗ, nhỏ hơn sĩ số buổi học ({$studentCount} SV).",
            ];
        }

        // 4. Kiểm tra lịch bận bắt buộc của giảng viên (Teacher Unavailability - HARD_BUSY)
        foreach ($dataset['unavailability'] ?? [] as $u) {
            if ((int)$u['teacher_id'] === $teacherId
                && (int)$u['slot_id'] === $slotId
                && $u['constraint_type'] === 'HARD_BUSY'
            ) {
                $violations[] = [
                    'code' => 'TEACHER_BUSY',
                    'message' => "Giảng viên bận bắt buộc tại {$slot['code']} ({$u['reason']}).",
                ];
                break;
            }
        }

        // 5, 6, 7. Kiểm tra không trùng Giảng viên, không trùng Lớp, không trùng Phòng trong cùng khung giờ
        $lessonsById = $this->indexById($dataset['lessons'] ?? []);
        foreach ($currentAssignments as $assignedLessonId => $assignment) {
            $assignedLessonId = (int)($assignment['lesson_id'] ?? $assignedLessonId);
            if ($assignedLessonId === $lessonId || ($ignoreLessonId !== null && $assignedLessonId === $ignoreLessonId)) {
                continue;
            }
            if ((int)$assignment['slot_id'] !== $slotId) {
                continue;
            }

            $otherLesson = $lessonsById[$assignedLessonId] ?? null;
            $otherCode = $otherLesson['code'] ?? ("BH#" . $assignedLessonId);

            // Trùng phòng
            if ((int)$assignment['room_id'] === $roomId) {
                $violations[] = [
                    'code' => 'ROOM_CONFLICT',
                    'message' => "Phòng {$room['code']} đã được xếp cho buổi học {$otherCode} tại khung giờ {$slot['code']}.",
                ];
            }

            if ($otherLesson !== null) {
                // Trùng giảng viên
                if ((int)$otherLesson['teacher_id'] === $teacherId) {
                    $violations[] = [
                        'code' => 'TEACHER_CONFLICT',
                        'message' => "Giảng viên trùng lịch dạy với buổi học {$otherCode} tại khung giờ {$slot['code']}.",
                    ];
                }
                // Trùng lớp học
                if ((int)$otherLesson['class_id'] === $classId) {
                    $violations[] = [
                        'code' => 'CLASS_CONFLICT',
                        'message' => "Lớp học trùng lịch với buổi học {$otherCode} tại khung giờ {$slot['code']}.",
                    ];
                }
            }
        }

        return [
            'valid' => empty($violations),
            'violations' => $violations,
        ];
    }

    /**
     * Đánh giá mức độ vi phạm Mong muốn ưu tiên (Soft Constraints) khi gán $lesson vào ($slot, $room).
     * Trả về điểm phạt (penalty >= 0) và chi tiết các mong muốn bị vi phạm / được đáp ứng.
     */
    public function evaluateSoftConstraintsForPlacement(
        array $lesson,
        array $slot,
        array $room,
        array $currentAssignments,
        array $dataset,
        ?int $ignoreLessonId = null
    ): array {
        $penalty = 0;
        $notes = [];

        $lessonId = (int)$lesson['id'];
        $slotId = (int)$slot['id'];
        $teacherId = (int)$lesson['teacher_id'];
        $classId = (int)$lesson['class_id'];
        $studentCount = (int)$lesson['student_count'];
        $weekNo = (int)($slot['week_no'] ?? 1);
        $dayOfWeek = (int)$slot['day_of_week'];
        $periodOfDay = (int)$slot['period_of_day'];

        $hasSoftPrefer = false;

        // 1. Kiểm tra đăng ký SOFT_AVOID / SOFT_PREFER của giảng viên
        foreach ($dataset['unavailability'] ?? [] as $u) {
            if ((int)$u['teacher_id'] === $teacherId && (int)$u['slot_id'] === $slotId) {
                if ($u['constraint_type'] === 'SOFT_AVOID') {
                    $penalty += 15;
                    $notes[] = "Vi phạm ưu tiên GV (+15đ): {$u['reason']}";
                } elseif ($u['constraint_type'] === 'SOFT_PREFER') {
                    $hasSoftPrefer = true;
                    $penalty -= 4;
                    $notes[] = "Đáp ứng khung giờ mong muốn của GV (-4đ phạt)";
                }
            }
        }

        // 2. Hạn chế Ca Chiều Thứ 6 cuối tuần (nếu không phải khung giờ giảng viên thích)
        if ($dayOfWeek === 6 && $periodOfDay === 2 && !$hasSoftPrefer) {
            $penalty += 4;
            $notes[] = "Xếp vào Ca Chiều Thứ 6 cuối tuần (+4đ)";
        }

        // 3. Lãng phí sức chứa phòng học (ưu tiên phòng vừa vặn sĩ số)
        $seatSlack = (int)$room['capacity'] - $studentCount;
        if ($seatSlack >= 45) {
            $penalty += 8;
            $notes[] = "Dư thừa sức chứa lớn (+8đ: dư {$seatSlack} chỗ)";
        } elseif ($seatSlack >= 25) {
            $penalty += 4;
            $notes[] = "Dư thừa sức chứa phòng (+4đ: dư {$seatSlack} chỗ)";
        }

        // 4. Kiểm tra mật độ dạy trong cùng ngày (cùng Tuần + Thứ) của giảng viên và lớp
        $teachersById = $this->indexById($dataset['teachers'] ?? []);
        $slotsById = $this->indexById($dataset['slots'] ?? []);
        $lessonsById = $this->indexById($dataset['lessons'] ?? []);

        $maxTeacherPerDay = (int)($teachersById[$teacherId]['max_lessons_per_day'] ?? 2);
        $teacherLessonsSameDay = 1; // tính cả buổi đang xét
        $classLessonsSameDay = 1;

        foreach ($currentAssignments as $assignedLessonId => $assignment) {
            $assignedLessonId = (int)($assignment['lesson_id'] ?? $assignedLessonId);
            if ($assignedLessonId === $lessonId || ($ignoreLessonId !== null && $assignedLessonId === $ignoreLessonId)) {
                continue;
            }
            $otherSlot = $slotsById[(int)$assignment['slot_id']] ?? null;
            $otherLesson = $lessonsById[$assignedLessonId] ?? null;
            if ($otherSlot === null || $otherLesson === null) {
                continue;
            }
            if ((int)($otherSlot['week_no'] ?? 1) === $weekNo && (int)$otherSlot['day_of_week'] === $dayOfWeek) {
                if ((int)$otherLesson['teacher_id'] === $teacherId) {
                    $teacherLessonsSameDay++;
                }
                if ((int)$otherLesson['class_id'] === $classId) {
                    $classLessonsSameDay++;
                }
            }
        }

        if ($teacherLessonsSameDay > $maxTeacherPerDay) {
            $penalty += 12;
            $notes[] = "Vượt số ca khuyến nghị/ngày của GV ({$teacherLessonsSameDay}/{$maxTeacherPerDay} ca, +12đ)";
        }
        if ($classLessonsSameDay > 2) {
            $penalty += 8;
            $notes[] = "Lớp học quá 2 ca trong cùng ngày ({$classLessonsSameDay} ca, +8đ)";
        }

        if ($penalty < 0) {
            $penalty = 0;
        }

        return [
            'penalty' => $penalty,
            'seat_slack' => $seatSlack,
            'notes' => $notes,
            'notes_text' => empty($notes) ? 'Tối ưu (0 vi phạm ưu tiên)' : implode('; ', $notes),
        ];
    }

    /**
     * Đánh giá toàn bộ một phương án lịch thực tế (dùng cho Kiểm thử & So sánh 2 phương án).
     * Tính toán trực tiếp từ danh sách phân công thực tế:
     * - Số buổi xếp được / chưa xếp được
     * - Số vi phạm bắt buộc (Hard Violations)
     * - Tổng điểm phạt vi phạm ưu tiên (Soft Penalty Score)
     */
    public function auditScheduleAssignments(array $assignments, array $dataset): array
    {
        $lessonsById = $this->indexById($dataset['lessons'] ?? []);
        $slotsById = $this->indexById($dataset['slots'] ?? []);
        $roomsById = $this->indexById($dataset['rooms'] ?? []);

        $indexedAssignments = [];
        foreach ($assignments as $a) {
            $indexedAssignments[(int)$a['lesson_id']] = $a;
        }

        $hardViolationCount = 0;
        $hardViolationDetails = [];
        $totalSoftPenalty = 0;
        $assignmentAudits = [];

        foreach ($indexedAssignments as $lessonId => $a) {
            $lesson = $lessonsById[$lessonId] ?? null;
            $slot = $slotsById[(int)$a['slot_id']] ?? null;
            $room = $roomsById[(int)$a['room_id']] ?? null;

            if ($lesson === null || $slot === null || $room === null) {
                $hardViolationCount++;
                $hardViolationDetails[] = "Bản ghi phân công cho buổi ID={$lessonId} tham chiếu tới khung giờ/phòng không hợp lệ.";
                continue;
            }

            $hardCheck = $this->checkHardConstraintsForPlacement(
                $lesson,
                $slot,
                $room,
                $indexedAssignments,
                $dataset,
                $lessonId
            );

            if (!$hardCheck['valid']) {
                foreach ($hardCheck['violations'] as $v) {
                    $hardViolationCount++;
                    $hardViolationDetails[] = "[{$lesson['code']}] {$v['message']}";
                }
            }

            $softCheck = $this->evaluateSoftConstraintsForPlacement(
                $lesson,
                $slot,
                $room,
                $indexedAssignments,
                $dataset,
                $lessonId
            );
            $totalSoftPenalty += $softCheck['penalty'];

            $assignmentAudits[$lessonId] = [
                'hard_valid' => $hardCheck['valid'],
                'hard_violations' => $hardCheck['violations'],
                'soft_penalty' => $softCheck['penalty'],
                'soft_notes' => $softCheck['notes_text'],
            ];
        }

        // Kiểm tra xem có buổi đã khóa nào bị bỏ sót hoặc bị đổi vị trí không
        foreach ($lessonsById as $lessonId => $lesson) {
            if ((int)$lesson['is_locked'] === 1) {
                if (!isset($indexedAssignments[$lessonId])) {
                    $hardViolationCount++;
                    $hardViolationDetails[] = "Buổi học đã khóa [{$lesson['code']}] bị bỏ sót khỏi phương án lịch.";
                } else {
                    $assigned = $indexedAssignments[$lessonId];
                    if ((int)$assigned['slot_id'] !== (int)$lesson['locked_slot_id']
                        || (int)$assigned['room_id'] !== (int)$lesson['locked_room_id']
                    ) {
                        $hardViolationCount++;
                        $hardViolationDetails[] = "Buổi học đã khóa [{$lesson['code']}] bị thay đổi sai khác so với vị trí khóa.";
                    }
                }
            }
        }

        $totalLessons = count($lessonsById);
        $scheduledCount = count($indexedAssignments);
        $unscheduledLessons = [];
        foreach ($lessonsById as $lessonId => $lesson) {
            if (!isset($indexedAssignments[$lessonId])) {
                $unscheduledLessons[] = [
                    'lesson_id' => $lessonId,
                    'code' => $lesson['code'],
                    'subject_name' => $lesson['subject_name'],
                    'teacher_id' => (int)$lesson['teacher_id'],
                    'class_id' => (int)$lesson['class_id'],
                    'student_count' => (int)$lesson['student_count'],
                    'required_room_type' => $lesson['required_room_type'],
                    'reason' => $this->diagnoseUnscheduledReason($lesson, $indexedAssignments, $dataset),
                ];
            }
        }

        return [
            'total_lessons' => $totalLessons,
            'scheduled_count' => $scheduledCount,
            'unscheduled_count' => count($unscheduledLessons),
            'hard_violations' => $hardViolationCount,
            'hard_violation_details' => $hardViolationDetails,
            'soft_penalty_score' => $totalSoftPenalty,
            'unscheduled_lessons' => $unscheduledLessons,
            'assignment_audits' => $assignmentAudits,
        ];
    }

    /**
     * Phân tích cụ thể lý do tại sao 1 buổi học không thể xếp vào bất kỳ cặp (slot, room) nào
     * trong phương án hiện tại (Phục vụ Chức năng 4 & Yêu cầu kỹ thuật 2).
     */
    public function diagnoseUnscheduledReason(array $lesson, array $currentAssignments, array $dataset): string
    {
        $slots = $dataset['slots'] ?? [];
        $rooms = $dataset['rooms'] ?? [];
        $lessonsById = $this->indexById($dataset['lessons'] ?? []);

        $studentCount = (int)$lesson['student_count'];
        $reqType = (string)$lesson['required_room_type'];

        // Lọc các phòng thỏa mãn cả loại phòng và sức chứa
        $suitableRooms = [];
        foreach ($rooms as $r) {
            if ($r['room_type'] === $reqType && (int)$r['capacity'] >= $studentCount) {
                $suitableRooms[(int)$r['id']] = $r;
            }
        }

        if (empty($suitableRooms)) {
            return "Không có phòng học nào thuộc loại '{$reqType}' có sức chứa >= {$studentCount} SV.";
        }

        $busySlotsCount = 0;
        $teacherConflictSlots = 0;
        $classConflictSlots = 0;
        $roomFullSlots = [];

        foreach ($slots as $slot) {
            $slotId = (int)$slot['id'];

            // Kiểm tra GV bận bắt buộc ở slot này
            $isTeacherBusy = false;
            foreach ($dataset['unavailability'] ?? [] as $u) {
                if ((int)$u['teacher_id'] === (int)$lesson['teacher_id']
                    && (int)$u['slot_id'] === $slotId
                    && $u['constraint_type'] === 'HARD_BUSY'
                ) {
                    $isTeacherBusy = true;
                    break;
                }
            }
            if ($isTeacherBusy) {
                $busySlotsCount++;
                continue;
            }

            // Kiểm tra trùng GV hoặc Lớp ở slot này
            $hasTeacherConflict = false;
            $hasClassConflict = false;
            foreach ($currentAssignments as $assignedLessonId => $a) {
                $assignedLessonId = (int)($a['lesson_id'] ?? $assignedLessonId);
                if ($assignedLessonId === (int)$lesson['id'] || (int)$a['slot_id'] !== $slotId) {
                    continue;
                }
                $other = $lessonsById[$assignedLessonId] ?? null;
                if ($other !== null) {
                    if ((int)$other['teacher_id'] === (int)$lesson['teacher_id']) {
                        $hasTeacherConflict = true;
                    }
                    if ((int)$other['class_id'] === (int)$lesson['class_id']) {
                        $hasClassConflict = true;
                    }
                }
            }

            if ($hasTeacherConflict) {
                $teacherConflictSlots++;
                continue;
            }
            if ($hasClassConflict) {
                $classConflictSlots++;
                continue;
            }

            // Nếu GV và Lớp đều rảnh ở slot này, kiểm tra tại sao tất cả các phòng phù hợp đều bị chiếm
            $occupiers = [];
            foreach ($suitableRooms as $rId => $r) {
                foreach ($currentAssignments as $assignedLessonId => $a) {
                    $assignedLessonId = (int)($a['lesson_id'] ?? $assignedLessonId);
                    if ((int)$a['slot_id'] === $slotId && (int)$a['room_id'] === $rId) {
                        $other = $lessonsById[$assignedLessonId] ?? null;
                        $lockTag = ((int)($other['is_locked'] ?? 0) === 1) ? ' [Đã khóa]' : '';
                        $occupiers[] = "{$r['code']} bị chiếm bởi " . ($other['code'] ?? "BH#$assignedLessonId") . $lockTag;
                    }
                }
            }
            if (!empty($occupiers)) {
                $roomFullSlots[] = "{$slot['code']} (" . implode(', ', $occupiers) . ")";
            }
        }

        $suitableRoomCodes = implode(', ', array_column($suitableRooms, 'code'));
        $parts = [];
        $parts[] = "Cần phòng loại {$reqType} >= {$studentCount} chỗ ({$suitableRoomCodes})";
        if ($busySlotsCount > 0) {
            $parts[] = "Giảng viên bận {$busySlotsCount}/" . count($slots) . " khung giờ";
        }
        if ($teacherConflictSlots > 0) {
            $parts[] = "Trùng lịch dạy GV ở {$teacherConflictSlots} khung giờ";
        }
        if ($classConflictSlots > 0) {
            $parts[] = "Trùng lịch học của lớp ở {$classConflictSlots} khung giờ";
        }
        if (!empty($roomFullSlots)) {
            $parts[] = "Các khung giờ rảnh còn lại đều hết phòng phù hợp: " . implode('; ', array_slice($roomFullSlots, 0, 4));
        }

        return implode(' | ', $parts) . '.';
    }

    private function indexById(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            if (isset($row['id'])) {
                $map[(int)$row['id']] = $row;
            }
        }
        return $map;
    }
}
