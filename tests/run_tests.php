<?php
declare(strict_types=1);

/**
 * BỘ KIỂM THỬ TỰ ĐỘNG & TÍCH HỢP (ĐỀ TÀI 18 - NHÓM 10)
 * Đối chiếu đầy đủ 5 phép thử bắt buộc tại Mục 6 của đề tài + Kiểm thử dữ liệu, an toàn & hồi quy.
 *
 * Cách chạy:
 *   php -d extension=pdo_mysql -d extension=mysqli tests/run_tests.php
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/src/Domain/ConstraintChecker.php';
require_once dirname(__DIR__) . '/src/Domain/SchedulerEngine.php';
require_once dirname(__DIR__) . '/src/Repository/TimetableRepository.php';
require_once dirname(__DIR__) . '/src/Controller/TimetableController.php';

use App\Config\Database;
use App\Controller\TimetableController;
use App\Domain\ConstraintChecker;
use App\Domain\SchedulerEngine;
use App\Repository\TimetableRepository;

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $testName, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "✅ [PASS] {$testName}" . ($detail !== '' ? " — {$detail}" : "") . "\n";
    } else {
        $failed++;
        echo "❌ [FAIL] {$testName}" . ($detail !== '' ? " — {$detail}" : "") . "\n";
    }
}

echo "============================================================================\n";
echo " BỘ KIỂM THỬ TỰ ĐỘNG ĐỀ TÀI 18: HỆ THỐNG XẾP THỜI KHÓA BIỂU THEO RÀNG BUỘC\n";
echo "============================================================================\n\n";

// Khởi tạo lại CSDL mẫu chuẩn trước khi chạy bộ kiểm thử
Database::resetDatabase();
$pdo = Database::getConnection();
$repo = new TimetableRepository($pdo);
$checker = new ConstraintChecker();
$engine = new SchedulerEngine($checker);
$dataset = $repo->loadFullDataset();

echo "--- KIỂM TRA QUY MÔ DỮ LIỆU MẪU CHUẨN (MỤC 5) ---\n";
assertTest(count($dataset['lessons']) === 20, 'Quy mô 20 buổi học', 'Thực tế: ' . count($dataset['lessons']));
assertTest(count($dataset['teachers']) === 6, 'Quy mô 6 giảng viên', 'Thực tế: ' . count($dataset['teachers']));
assertTest(count($dataset['rooms']) === 5, 'Quy mô 5 phòng học', 'Thực tế: ' . count($dataset['rooms']));
assertTest(count($dataset['slots']) === 20, 'Quy mô 20 khung giờ', 'Thực tế: ' . count($dataset['slots']));

// Chạy cả 2 thuật toán trên cùng dữ liệu chuẩn
$resBasic = $engine->scheduleBasic($dataset);
$resImproved = $engine->scheduleImproved($dataset);

echo "\n--- PHÉP THỬ BẮT BUỘC 1: KHÔNG TRÙNG GIẢNG VIÊN, LỚP HỌC VÀ PHÒNG HỌC TRONG CÙNG KHUNG GIỜ ---\n";
foreach (['BASIC' => $resBasic, 'IMPROVED' => $resImproved] as $algo => $res) {
    $seenTeacherSlot = [];
    $seenClassSlot = [];
    $seenRoomSlot = [];
    $lessonsById = [];
    foreach ($dataset['lessons'] as $l) {
        $lessonsById[(int)$l['id']] = $l;
    }
    $noConflict = true;
    foreach ($res['assignments'] as $a) {
        $l = $lessonsById[(int)$a['lesson_id']];
        $slotId = (int)$a['slot_id'];
        $roomId = (int)$a['room_id'];
        $tKey = $l['teacher_id'] . '_' . $slotId;
        $cKey = $l['class_id'] . '_' . $slotId;
        $rKey = $roomId . '_' . $slotId;

        if (isset($seenTeacherSlot[$tKey]) || isset($seenClassSlot[$cKey]) || isset($seenRoomSlot[$rKey])) {
            $noConflict = false;
        }
        $seenTeacherSlot[$tKey] = true;
        $seenClassSlot[$cKey] = true;
        $seenRoomSlot[$rKey] = true;
    }
    assertTest(
        $noConflict && $res['hard_violations'] === 0,
        "Phép thử 1 ({$algo}): Không trùng GV, Lớp, Phòng trên toàn bộ {$res['scheduled_count']} buổi đã xếp",
        "Hard violations = {$res['hard_violations']}"
    );
}

// Kiểm tra trực tiếp hàm checkHardConstraintsForPlacement chặn trùng GV, Lớp, Phòng
$sampleLessonA = $dataset['lessons'][0]; // BH01: GV01, Lớp 1, đang ở Slot 1, Room 1
$slot1 = $dataset['slots'][0];
$room1 = $dataset['rooms'][0];
$room2 = $dataset['rooms'][1];
$baseAssignments = [
    1 => ['lesson_id' => 1, 'slot_id' => 1, 'room_id' => 1, 'is_locked' => 1],
];

// Tạo buổi giả định cùng GV01 nhưng khác lớp, khác phòng tại Slot 1
$sameTeacherLesson = $dataset['lessons'][3]; // BH04: GV01, Lớp 2
$checkTeacherConflict = $checker->checkHardConstraintsForPlacement($sameTeacherLesson, $slot1, $room2, $baseAssignments, $dataset);
assertTest(
    !$checkTeacherConflict['valid'] && in_array('TEACHER_CONFLICT', array_column($checkTeacherConflict['violations'], 'code'), true),
    'Phép thử 1b: Phát hiện và loại bỏ hai buổi cùng Giảng viên trong cùng khung giờ'
);

// Tạo buổi giả định cùng Lớp 1 nhưng khác GV, khác phòng tại Slot 1
$sameClassLesson = $dataset['lessons'][5]; // BH06: GV05, Lớp 1
$checkClassConflict = $checker->checkHardConstraintsForPlacement($sameClassLesson, $slot1, $room2, $baseAssignments, $dataset);
assertTest(
    !$checkClassConflict['valid'] && in_array('CLASS_CONFLICT', array_column($checkClassConflict['violations'], 'code'), true),
    'Phép thử 1c: Phát hiện và loại bỏ hai buổi cùng Lớp học trong cùng khung giờ'
);

// Tạo buổi giả định khác GV, khác Lớp nhưng cùng Phòng 1 tại Slot 1
$sameRoomLesson = $dataset['lessons'][14]; // BH15: GV05, Lớp 4, sửa tạm sĩ số 40 để chỉ test trùng phòng
$sameRoomLesson['student_count'] = 40;
$checkRoomConflict = $checker->checkHardConstraintsForPlacement($sameRoomLesson, $slot1, $room1, $baseAssignments, $dataset);
assertTest(
    !$checkRoomConflict['valid'] && in_array('ROOM_CONFLICT', array_column($checkRoomConflict['violations'], 'code'), true),
    'Phép thử 1d: Phát hiện và loại bỏ hai buổi dùng trùng Phòng học trong cùng khung giờ'
);

echo "\n--- PHÉP THỬ BẮT BUỘC 2: PHÒNG NHỎ HƠN SĨ SỐ, SAI LOẠI HOẶC GIẢNG VIÊN BẬN ĐỀU BỊ LOẠI ---\n";
// 2a. Phòng nhỏ hơn sĩ số: BH12 (85 SV, LT) thử xếp vào P101 (45 chỗ, LT) tại Slot 1
$lessonLarge = $dataset['lessons'][11]; // BH12: 85 SV, LT
$checkSmallRoom = $checker->checkHardConstraintsForPlacement($lessonLarge, $slot1, $room1, [], $dataset);
assertTest(
    !$checkSmallRoom['valid'] && in_array('ROOM_CAPACITY_INSUFFICIENT', array_column($checkSmallRoom['violations'], 'code'), true),
    'Phép thử 2a: Loại bỏ phòng có sức chứa (45 chỗ) nhỏ hơn sĩ số lớp (85 SV)'
);

// 2b. Sai loại phòng: BH05 (TH - Thực hành) thử xếp vào P102 (LT - Lý thuyết)
$lessonLab = $dataset['lessons'][4]; // BH05: yêu cầu TH
$checkWrongType = $checker->checkHardConstraintsForPlacement($lessonLab, $slot1, $room2, [], $dataset);
assertTest(
    !$checkWrongType['valid'] && in_array('ROOM_TYPE_MISMATCH', array_column($checkWrongType['violations'], 'code'), true),
    'Phép thử 2b: Loại bỏ phòng sai loại (Buổi TH xếp vào phòng LT bị chặn)'
);

// 2c. Thời điểm giảng viên bận: BH04 (GV01) thử xếp vào Slot 2 (T2-C2, GV01 có lịch HARD_BUSY)
$slot2 = $dataset['slots'][1];
$checkTeacherBusy = $checker->checkHardConstraintsForPlacement($sameTeacherLesson, $slot2, $room1, [], $dataset);
assertTest(
    !$checkTeacherBusy['valid'] && in_array('TEACHER_BUSY', array_column($checkTeacherBusy['violations'], 'code'), true),
    'Phép thử 2c: Loại bỏ khung giờ giảng viên có lịch bận bắt buộc (HARD_BUSY)'
);

echo "\n--- PHÉP THỬ BẮT BUỘC 3: XẾP LẠI KHÔNG THAY ĐỔI NHỮNG BUỔI ĐÃ KHÓA ---\n";
$lockedBefore = [];
foreach ($dataset['lessons'] as $l) {
    if ((int)$l['is_locked'] === 1) {
        $lockedBefore[(int)$l['id']] = [
            'slot_id' => (int)$l['locked_slot_id'],
            'room_id' => (int)$l['locked_room_id'],
        ];
    }
}

// Kiểm tra trên cả BASIC và IMPROVED, kể cả sau khi khóa thêm BH04 và chạy lại
$repo->toggleLessonLock(4, true, 3, 1); // Khóa thêm BH04 tại Slot 3, Room 1
$lockedBefore[4] = ['slot_id' => 3, 'room_id' => 1];
$datasetWith4Locked = $repo->loadFullDataset();

$rerunBasic = $engine->scheduleBasic($datasetWith4Locked);
$rerunImproved = $engine->scheduleImproved($datasetWith4Locked);

foreach (['BASIC' => $rerunBasic, 'IMPROVED' => $rerunImproved] as $algo => $res) {
    $map = [];
    foreach ($res['assignments'] as $a) {
        $map[(int)$a['lesson_id']] = $a;
    }
    $allLockedIntact = true;
    foreach ($lockedBefore as $lId => $expected) {
        if (!isset($map[$lId])
            || (int)$map[$lId]['slot_id'] !== $expected['slot_id']
            || (int)$map[$lId]['room_id'] !== $expected['room_id']
        ) {
            $allLockedIntact = false;
        }
    }
    assertTest(
        $allLockedIntact,
        "Phép thử 3 ({$algo}): Xếp lại giữ nguyên 100% vị trí của toàn bộ " . count($lockedBefore) . " buổi học đã khóa"
    );
}
// Mở khóa lại BH04 để trả về trạng thái chuẩn
$repo->toggleLessonLock(4, false);

echo "\n--- PHÉP THỬ BẮT BUỘC 4: DỮ LIỆU GÂY BẾ TẮC TRẢ DANH SÁCH CHƯA XẾP, KHÔNG GIẤU BUỔI HỌC HOẶC BÁO LỊCH ĐẦY ĐỦ ---\n";
assertTest(
    $resBasic['status_code'] === 'PARTIAL_SCHEDULE'
    && $resBasic['unscheduled_count'] === 4
    && ($resBasic['scheduled_count'] + $resBasic['unscheduled_count'] === 20)
    && count($resBasic['unscheduled_lessons']) === 4,
    'Phép thử 4a (BASIC): Trả về đúng 4 buổi chưa xếp kèm nguyên nhân, tổng (Đã xếp + Chưa xếp) = 20, không báo lịch đầy đủ giả',
    'Chưa xếp: ' . implode(', ', array_column($resBasic['unscheduled_lessons'], 'code'))
);

assertTest(
    $resImproved['status_code'] === 'PARTIAL_SCHEDULE'
    && $resImproved['status_code'] !== 'INFEASIBLE'
    && $resImproved['unscheduled_count'] === 1
    && ($resImproved['scheduled_count'] + $resImproved['unscheduled_count'] === 20)
    && $resImproved['unscheduled_lessons'][0]['code'] === 'BH20'
    && !empty($resImproved['unscheduled_lessons'][0]['reason']),
    'Phép thử 4b (IMPROVED): Trả về đúng buổi bế tắc BH20 kèm chẩn đoán nguyên nhân, không giấu buổi học và không kết luận vô nghiệm',
    'Nguyên nhân BH20: ' . $resImproved['unscheduled_lessons'][0]['reason']
);

echo "\n--- PHÉP THỬ BẮT BUỘC 5: HAI PHƯƠNG ÁN ĐƯỢC SO SÁNH TRÊN CÙNG DỮ LIỆU & ĐIỀU KIỆN; SỐ LIỆU TÍNH TỪ LỊCH THỰC TẾ ---\n";
$idBasic = $repo->saveScheduleVersion('PA Cơ bản Test', 'Test Basic', $resBasic, $dataset, false);
$idImproved = $repo->saveScheduleVersion('PA Cải thiện Test', 'Test Improved', $resImproved, $dataset, true);

$verA = $repo->getScheduleVersionById($idBasic);
$verB = $repo->getScheduleVersionById($idImproved);
$assignA = $repo->getAssignmentsByVersion($idBasic);
$assignB = $repo->getAssignmentsByVersion($idImproved);

$comparison = $engine->compareTwoVersions($verA, $assignA, $verB, $assignB, $dataset);
assertTest(
    $comparison['same_dataset'] === true
    && $comparison['locked_preserved_all'] === true
    && $comparison['metrics_a']['scheduled_count'] === count($assignA)
    && $comparison['metrics_b']['scheduled_count'] === count($assignB)
    && $comparison['metrics_b']['scheduled_count'] > $comparison['metrics_a']['scheduled_count']
    && $comparison['metrics_b']['soft_penalty_score'] < $comparison['metrics_a']['soft_penalty_score']
    && $comparison['winner'] === 'B',
    'Phép thử 5: So sánh 2 phương án trên cùng dataset_hash; số buổi xếp được (19 vs 16) và điểm phạt (4đ vs 253đ) tính từ lịch thực tế',
    $comparison['recommendation']
);

echo "\n--- PHÉP THỬ BỔ SUNG: AN TOÀN DỮ LIỆU, KIỂM TRA ẢNH HƯỞNG ĐỔI LỊCH & HỒI QUY TÌNH HUỐNG 2 ---\n";
// Phép thử 6: Chặn giá trị sai trước khi xếp (sĩ số <= 0, khóa buổi học vào phòng sai loại)
$badLesson = [
    'code' => 'BH_ERR',
    'subject_name' => 'Buổi học lỗi',
    'teacher_id' => 1,
    'class_id' => 1,
    'student_count' => -10,
    'required_room_type' => 'TH',
    'is_locked' => 1,
    'locked_slot_id' => 1,
    'locked_room_id' => 1, // P101 là phòng LT, không phải TH
];
$badValidation = $checker->validateEntityInput('lesson', $badLesson, $dataset);
assertTest(
    !$badValidation['valid'] && count($badValidation['errors']) >= 2,
    'Phép thử 6 (An toàn dữ liệu): Chặn dữ liệu sai (sĩ số âm, khóa sai loại phòng, trùng buổi khóa) trước khi lưu/xếp'
);

// Phép thử 7: Kiểm tra ảnh hưởng khi thử đổi một buổi đã khóa (phải bị chặn)
$impactLockedMove = $engine->checkManualMoveImpact(1, 5, 1, $assignB, $dataset);
assertTest(
    !$impactLockedMove['can_apply'] && in_array('LOCKED_IMMUTABLE', array_column($impactLockedMove['hard_violations'], 'code'), true),
    'Phép thử 7 (Bảo vệ buổi khóa khi điều chỉnh): Chặn thao tác đổi lịch thủ công đối với buổi học đã khóa cố định (BH01)'
);

// Phép thử 8: Hồi quy Tình huống 2 (Điều chỉnh yêu cầu BH20 xuống 38 SV -> Thuật toán Cải thiện xếp đủ 20/20 buổi)
$bh20 = $repo->getLessonById(20);
$bh20['student_count'] = 38;
$repo->updateLesson(20, $bh20);
$datasetScenario2 = $repo->loadFullDataset();
$resScenario2Improved = $engine->scheduleImproved($datasetScenario2);
assertTest(
    $resScenario2Improved['scheduled_count'] === 20 && $resScenario2Improved['unscheduled_count'] === 0 && $resScenario2Improved['hard_violations'] === 0,
    'Phép thử 8 (Tình huống 2 - Hồi quy): Sau khi điều chỉnh yêu cầu nút cổ chai BH20, cách xếp cải thiện xếp thành công 20/20 buổi (0 vi phạm bắt buộc)'
);

echo "\n--- PHÉP THỬ ĐĂNG NHẬP & PHÂN QUYỀN TÀI KHOẢN NHÀ TRƯỜNG CẤP (RBAC) ---\n";
$controller = new TimetableController($repo, $checker, $engine);

// Phép thử 9: Đăng nhập tài khoản mẫu Admin (scheduler), Giảng viên (teacher) và Sinh viên (student)
$loginAdmin = $controller->authenticate('admin', '123456', 'scheduler');
assertTest(
    $loginAdmin['success'] === true && $controller->isSchedulerRole(),
    'Phép thử 9a (Đăng nhập Đào tạo): Xác thực mật khẩu Bcrypt và cấp quyền Người xếp lịch (scheduler)'
);

$loginWrongPw = $controller->authenticate('admin', 'wrong_password', 'scheduler');
assertTest(
    $loginWrongPw['success'] === false,
    'Phép thử 9b (Bảo mật đăng nhập): Chặn đăng nhập khi sai mật khẩu'
);

// Phép thử 10: Kiểm tra khớp vai trò (Dropdown Phân quyền) và tài khoản Nhà trường cấp cho GV / SV
$loginRoleMismatch = $controller->authenticate('sv01', '123456', 'teacher');
assertTest(
    $loginRoleMismatch['success'] === false,
    'Phép thử 10a (Kiểm tra chọn đúng phân quyền): Chặn tài khoản Sinh viên (sv01) đăng nhập dưới vai trò Giảng viên'
);

$loginStudent = $controller->authenticate('sv01', '123456', 'student');
assertTest(
    $loginStudent['success'] === true && $controller->isStudentRole() && !$controller->isSchedulerRole(),
    'Phép thử 10b (Phân quyền Sinh viên): Đăng nhập thành công tài khoản nhà trường cấp sv01 (chỉ xem lịch lớp CNTT18-01)'
);

// Khôi phục lại dữ liệu chuẩn ban đầu và 2 phương án mặc định
Database::resetDatabase();
$datasetClean = $repo->loadFullDataset();
$repo->saveScheduleVersion('Phương án 1 - Cách xếp Cơ bản (First-Fit)', 'Chạy tuần tự từng buổi học trên bộ dữ liệu gốc (20 buổi, 3 buổi khóa)', $engine->scheduleBasic($datasetClean), $datasetClean, false);
$repo->saveScheduleVersion('Phương án 2 - Cách xếp Cải thiện (MRV + Best-Fit)', 'Ưu tiên buổi khó (MRV), chọn phòng sát sĩ số và tối ưu điểm phạt mềm trên cùng dữ liệu gốc', $engine->scheduleImproved($datasetClean), $datasetClean, true);

echo "\n============================================================================\n";
echo " TỔNG KẾT KIỂM THỬ: {$passed} ĐẠT (PASSED) / {$failed} THẤT BẠI (FAILED)\n";
echo "============================================================================\n";

exit($failed > 0 ? 1 : 0);
