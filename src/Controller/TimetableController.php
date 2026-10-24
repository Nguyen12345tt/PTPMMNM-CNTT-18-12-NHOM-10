<?php
declare(strict_types=1);

namespace App\Controller;

use App\Config\Database;
use App\Domain\ConstraintChecker;
use App\Domain\SchedulerEngine;
use App\Repository\TimetableRepository;

/**
 * Bộ điều khiển trung tâm (TimetableController) - Xử lý Đăng ký, Đăng nhập,
 * Phân quyền RBAC (Người xếp lịch / Giảng viên / Sinh viên lớp học),
 * gọi tầng Domain/Repository và chuẩn bị dữ liệu cho View.
 */
class TimetableController
{
    private TimetableRepository $repo;
    private ConstraintChecker $checker;
    private SchedulerEngine $engine;

    public function __construct(TimetableRepository $repo, ConstraintChecker $checker, SchedulerEngine $engine)
    {
        $this->repo = $repo;
        $this->checker = $checker;
        $this->engine = $engine;
    }

    /**
     * Trả về thông tin tài khoản đang đăng nhập từ Session (đồng bộ với CSDL).
     */
    public function getCurrentUser(): ?array
    {
        if (empty($_SESSION['auth_user_id'])) {
            return null;
        }
        $user = $this->repo->findUserById((int)$_SESSION['auth_user_id']);
        if (!$user) {
            unset($_SESSION['auth_user_id']);
            return null;
        }
        unset($user['password_hash']);
        return $user;
    }

    public function isAuthenticated(): bool
    {
        return $this->getCurrentUser() !== null;
    }

    /**
     * Lấy vai trò hiện tại của người dùng đã đăng nhập:
     * - scheduler: Người phụ trách đào tạo / Người xếp lịch (Toàn quyền)
     * - teacher:   Giảng viên (Xem/Xuất lịch dạy & Cập nhật lịch bận cá nhân)
     * - student:   Sinh viên / Lớp học (Xem & Xuất lịch học của lớp liên quan)
     */
    public function getCurrentRole(): string
    {
        $user = $this->getCurrentUser();
        return $user['role'] ?? 'guest';
    }

    public function isSchedulerRole(): bool
    {
        return $this->getCurrentRole() === 'scheduler';
    }

    public function isTeacherRole(): bool
    {
        return $this->getCurrentRole() === 'teacher';
    }

    public function isStudentRole(): bool
    {
        return $this->getCurrentRole() === 'student';
    }

    /**
     * Xác thực đăng nhập bằng username, password và vai trò được chọn (role).
     */
    public function authenticate(string $username, string $password, ?string $expectedRole = null): array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return [
                'success' => false,
                'message' => 'Vui lòng nhập đầy đủ tài khoản và mật khẩu.',
            ];
        }

        $user = $this->repo->findUserByUsername($username);
        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            return [
                'success' => false,
                'message' => 'Tài khoản hoặc mật khẩu không chính xác.',
            ];
        }

        if ($expectedRole !== null && $expectedRole !== '' && $user['role'] !== $expectedRole) {
            return [
                'success' => false,
                'message' => 'Tài khoản "' . $username . '" thuộc nhóm "' . $this->getRoleLabel((string)$user['role']) . '", không khớp với vai trò "' . $this->getRoleLabel($expectedRole) . '" bạn đã chọn.',
            ];
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        $_SESSION['auth_user_id'] = (int)$user['id'];

        return [
            'success' => true,
            'user' => $user,
            'message' => "Đăng nhập thành công! Xin chào {$user['full_name']} (" . $this->getRoleLabel((string)$user['role']) . ").",
        ];
    }

    /**
     * Đăng ký tài khoản mới có kiểm tra hợp lệ và mã hóa mật khẩu Bcrypt.
     */
    public function registerUser(array $input, array $dataset = []): array
    {
        $val = $this->checker->validateEntityInput('user_register', $input, $dataset);
        if (!$val['valid']) {
            return [
                'success' => false,
                'message' => 'Thông tin đăng ký chưa hợp lệ:',
                'errors' => $val['errors'],
            ];
        }

        $username = trim((string)$input['username']);
        if ($this->repo->findUserByUsername($username) !== null) {
            return [
                'success' => false,
                'message' => 'Đăng ký thất bại:',
                'errors' => ["Tên đăng nhập '{$username}' đã tồn tại trong hệ thống."],
            ];
        }

        $passwordHash = password_hash((string)$input['password'], PASSWORD_BCRYPT);
        $userId = $this->repo->createUser([
            'username' => $username,
            'password_hash' => $passwordHash,
            'full_name' => trim((string)$input['full_name']),
            'email' => trim((string)($input['email'] ?? '')),
            'role' => (string)$input['role'],
            'teacher_id' => !empty($input['teacher_id']) ? (int)$input['teacher_id'] : null,
            'class_id' => !empty($input['class_id']) ? (int)$input['class_id'] : null,
        ]);

        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        $_SESSION['auth_user_id'] = $userId;

        return [
            'success' => true,
            'user_id' => $userId,
            'message' => "Đăng ký tài khoản '{$username}' thành công và đã tự động đăng nhập!",
            'errors' => [],
        ];
    }

    public function getRoleLabel(string $role): string
    {
        return match ($role) {
            'scheduler' => 'Người phụ trách đào tạo / Người xếp lịch',
            'teacher' => 'Giảng viên',
            'student' => 'Sinh viên / Lớp học',
            default => 'Khách (Chưa đăng nhập)',
        };
    }

    /**
     * Xử lý các hành động POST / GET Export và trả về thông báo trạng thái.
     */
    public function handleRequest(): array
    {
        $flash = [
            'type' => '',
            'message' => '',
            'details' => [],
            'impact_result' => null,
        ];

        // Xử lý đăng xuất qua GET hoặc POST
        if ((isset($_GET['action']) && $_GET['action'] === 'logout')
            || (isset($_POST['action']) && $_POST['action'] === 'logout')
        ) {
            unset($_SESSION['auth_user_id']);
            $flash['type'] = 'success';
            $flash['message'] = 'Bạn đã đăng xuất khỏi hệ thống.';
            return $flash;
        }

        $dataset = $this->repo->loadFullDataset();

        // Xử lý Đăng nhập & Đăng ký khi gửi form POST
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'login') {
                $username = (string)($_POST['username'] ?? '');
                $password = (string)($_POST['password'] ?? '');
                $loginRole = isset($_POST['login_role']) ? (string)$_POST['login_role'] : null;
                $auth = $this->authenticate($username, $password, $loginRole);
                $flash['type'] = $auth['success'] ? 'success' : 'error';
                $flash['message'] = $auth['message'];
                return $flash;
            }
        }

        // Bắt buộc phải đăng nhập trước khi sử dụng các chức năng bên trong
        $currentUser = $this->getCurrentUser();
        if ($currentUser === null) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                return [
                    'type' => 'error',
                    'message' => 'Vui lòng đăng nhập tài khoản trước khi thực hiện thao tác (401 Unauthorized).',
                    'details' => [],
                    'impact_result' => null,
                ];
            }
            return $flash;
        }

        // Xử lý xuất file lịch học (CSV / JSON / Bản in HTML) theo Lớp hoặc Giảng viên (Chức năng 5)
        if (isset($_GET['action']) && $_GET['action'] === 'export') {
            $this->handleExport();
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $flash;
        }

        $action = (string)($_POST['action'] ?? '');

        // Phân quyền đặc thù cho Giảng viên (role = teacher):
        // Giảng viên được phép cập nhật Lịch bận / Mong muốn ưu tiên của CHÍNH MÌNH,
        // nhưng không được phép sửa lịch bận của giảng viên khác hoặc thay đổi cấu trúc đào tạo.
        if ($this->isTeacherRole() && in_array($action, ['save_unavailability', 'delete_unavailability'], true)) {
            $myTeacherId = (int)($currentUser['teacher_id'] ?? 0);
            if ($action === 'save_unavailability') {
                $targetTeacherId = (int)($_POST['teacher_id'] ?? 0);
                if ($targetTeacherId !== $myTeacherId) {
                    return [
                        'type' => 'error',
                        'message' => 'Từ chối truy cập (403 Forbidden): Giảng viên chỉ được phép đăng ký lịch bận / mong muốn ưu tiên cho chính mình.',
                        'details' => [],
                        'impact_result' => null,
                    ];
                }
                $uData = [
                    'teacher_id' => $myTeacherId,
                    'slot_id' => (int)($_POST['slot_id'] ?? 0),
                    'constraint_type' => (string)($_POST['constraint_type'] ?? 'HARD_BUSY'),
                    'reason' => trim((string)($_POST['reason'] ?? 'Lịch cá nhân / Công tác')),
                ];
                $val = $this->checker->validateEntityInput('unavailability', $uData, $dataset);
                if (!$val['valid']) {
                    return [
                        'type' => 'error',
                        'message' => 'Không thể lưu lịch bận / ưu tiên:',
                        'details' => $val['errors'],
                        'impact_result' => null,
                    ];
                }
                $this->repo->saveUnavailability($uData);
                return [
                    'type' => 'success',
                    'message' => 'Đã cập nhật lịch bận / mong muốn ưu tiên cá nhân của giảng viên.',
                    'details' => [],
                    'impact_result' => null,
                ];
            }
        }

        // Kiểm tra quyền: Chỉ "Người xếp lịch" (scheduler) mới được phép thay đổi dữ liệu đào tạo hoặc chạy xếp lịch
        if (!$this->isSchedulerRole()) {
            return [
                'type' => 'error',
                'message' => 'Từ chối truy cập (403 Forbidden): Tài khoản ' . $this->getRoleLabel((string)$currentUser['role']) . ' chỉ có quyền xem và xuất thời khóa biểu liên quan, không được phép thay đổi dữ liệu đào tạo hoặc chạy xếp lịch.',
                'details' => [],
                'impact_result' => null,
            ];
        }

        try {
            switch ($action) {
                case 'reset_seed':
                    $currentUserId = (int)$currentUser['id'];
                    Database::resetDatabase();
                    $_SESSION['auth_user_id'] = 1; // Đặt lại về tài khoản admin sau khi reset seed
                    $dataset = $this->repo->loadFullDataset();
                    $resBasic = $this->engine->scheduleBasic($dataset);
                    $this->repo->saveScheduleVersion(
                        'Phương án 1 - Cách xếp Cơ bản (First-Fit)',
                        'Chạy tuần tự từng buổi học trên bộ dữ liệu gốc (20 buổi, 3 buổi khóa)',
                        $resBasic,
                        $dataset,
                        false
                    );
                    $resImproved = $this->engine->scheduleImproved($dataset);
                    $this->repo->saveScheduleVersion(
                        'Phương án 2 - Cách xếp Cải thiện (MRV + Best-Fit)',
                        'Ưu tiên buổi khó (MRV), chọn phòng sát sĩ số và tối ưu điểm phạt mềm trên cùng dữ liệu gốc',
                        $resImproved,
                        $dataset,
                        true
                    );
                    $flash['type'] = 'success';
                    $flash['message'] = 'Đã khôi phục bộ dữ liệu chuẩn (20 buổi học, 6 giảng viên, 5 phòng, 20 khung giờ) và khởi tạo 2 phương án Cơ bản & Cải thiện.';
                    break;

                case 'run_schedule':
                    $algo = (string)($_POST['algorithm_type'] ?? 'IMPROVED');
                    $customName = trim((string)($_POST['version_name'] ?? ''));

                    if ($algo === 'BOTH') {
                        $resBasic = $this->engine->scheduleBasic($dataset);
                        if (!$resBasic['success']) {
                            $flash['type'] = 'error';
                            $flash['message'] = $resBasic['status_message'];
                            $flash['details'] = $resBasic['validation']['hard_errors'] ?? [];
                            break;
                        }
                        $idBasic = $this->repo->saveScheduleVersion(
                            $customName !== '' ? ($customName . ' [Cơ bản]') : 'Phương án Cơ bản (First-Fit)',
                            'Tạo tự động bằng thuật toán tham lam tuần tự First-Fit',
                            $resBasic,
                            $dataset,
                            false
                        );

                        $resImproved = $this->engine->scheduleImproved($dataset);
                        $idImproved = $this->repo->saveScheduleVersion(
                            $customName !== '' ? ($customName . ' [Cải thiện]') : 'Phương án Cải thiện (MRV + Best-Fit)',
                            'Tạo tự động bằng thuật toán cải thiện MRV + Best-Fit + Swap Repair trên cùng dữ liệu',
                            $resImproved,
                            $dataset,
                            true
                        );

                        $_GET['compare_a'] = (string)$idBasic;
                        $_GET['compare_b'] = (string)$idImproved;
                        $flash['type'] = 'success';
                        $flash['message'] = "Đã chạy đồng thời cả 2 cách xếp trên cùng dữ liệu! Cách cơ bản xếp được {$resBasic['scheduled_count']}/{$resBasic['total_lessons']} buổi (phạt mềm: {$resBasic['soft_penalty_score']}đ); Cách cải thiện xếp được {$resImproved['scheduled_count']}/{$resImproved['total_lessons']} buổi (phạt mềm: {$resImproved['soft_penalty_score']}đ).";
                    } else {
                        $result = ($algo === 'BASIC')
                            ? $this->engine->scheduleBasic($dataset)
                            : $this->engine->scheduleImproved($dataset);

                        if (!$result['success']) {
                            $flash['type'] = 'error';
                            $flash['message'] = $result['status_message'];
                            $flash['details'] = $result['validation']['hard_errors'] ?? [];
                            break;
                        }

                        $defaultName = ($algo === 'BASIC')
                            ? 'Phương án Cơ bản (First-Fit) - ' . date('H:i:s')
                            : 'Phương án Cải thiện (MRV + Best-Fit) - ' . date('H:i:s');
                        $verName = $customName !== '' ? $customName : $defaultName;

                        $this->repo->saveScheduleVersion(
                            $verName,
                            $result['status_message'],
                            $result,
                            $dataset,
                            true
                        );
                        $flash['type'] = $result['unscheduled_count'] > 0 ? 'warning' : 'success';
                        $flash['message'] = $result['status_message'];
                    }
                    break;

                case 'add_lesson':
                case 'update_lesson':
                    $lessonData = [
                        'id' => !empty($_POST['lesson_id']) ? (int)$_POST['lesson_id'] : null,
                        'code' => trim((string)($_POST['code'] ?? '')),
                        'subject_name' => trim((string)($_POST['subject_name'] ?? '')),
                        'teacher_id' => (int)($_POST['teacher_id'] ?? 0),
                        'class_id' => (int)($_POST['class_id'] ?? 0),
                        'student_count' => (int)($_POST['student_count'] ?? 0),
                        'required_room_type' => (string)($_POST['required_room_type'] ?? 'LT'),
                        'is_locked' => isset($_POST['is_locked']) && (int)$_POST['is_locked'] === 1 ? 1 : 0,
                        'locked_slot_id' => !empty($_POST['locked_slot_id']) ? (int)$_POST['locked_slot_id'] : null,
                        'locked_room_id' => !empty($_POST['locked_room_id']) ? (int)$_POST['locked_room_id'] : null,
                        'notes' => trim((string)($_POST['notes'] ?? '')),
                    ];
                    $val = $this->checker->validateEntityInput('lesson', $lessonData, $dataset);
                    if (!$val['valid']) {
                        $flash['type'] = 'error';
                        $flash['message'] = 'Dữ liệu buổi học không hợp lệ (Đã chặn trước khi lưu):';
                        $flash['details'] = $val['errors'];
                        break;
                    }
                    if ($action === 'add_lesson') {
                        $this->repo->createLesson($lessonData);
                        $flash['type'] = 'success';
                        $flash['message'] = "Đã thêm buổi học [{$lessonData['code']}] thành công.";
                    } else {
                        $this->repo->updateLesson((int)$lessonData['id'], $lessonData);
                        $flash['type'] = 'success';
                        $flash['message'] = "Đã cập nhật buổi học [{$lessonData['code']}] thành công.";
                    }
                    break;

                case 'delete_lesson':
                    $id = (int)($_POST['lesson_id'] ?? 0);
                    $lesson = $this->repo->getLessonById($id);
                    if ($lesson && (int)$lesson['is_locked'] === 1) {
                        $flash['type'] = 'error';
                        $flash['message'] = "Không thể xóa buổi học [{$lesson['code']}] đang bị khóa cố định. Vui lòng mở khóa trước.";
                        break;
                    }
                    $this->repo->deleteLesson($id);
                    $flash['type'] = 'success';
                    $flash['message'] = 'Đã xóa buổi học.';
                    break;

                case 'lock_from_version':
                    $lessonId = (int)($_POST['lesson_id'] ?? 0);
                    $slotId = (int)($_POST['slot_id'] ?? 0);
                    $roomId = (int)($_POST['room_id'] ?? 0);
                    $lesson = $this->repo->getLessonById($lessonId);
                    if ($lesson) {
                        $checkData = array_merge($lesson, [
                            'is_locked' => 1,
                            'locked_slot_id' => $slotId,
                            'locked_room_id' => $roomId,
                        ]);
                        $val = $this->checker->validateEntityInput('lesson', $checkData, $dataset);
                        if (!$val['valid']) {
                            $flash['type'] = 'error';
                            $flash['message'] = 'Không thể khóa buổi học do vi phạm điều kiện bắt buộc:';
                            $flash['details'] = $val['errors'];
                        } else {
                            $this->repo->toggleLessonLock($lessonId, true, $slotId, $roomId);
                            $flash['type'] = 'success';
                            $flash['message'] = "Đã khóa cố định buổi học [{$lesson['code']}]. Các lần chạy xếp lại tiếp theo sẽ giữ nguyên vị trí này.";
                        }
                    }
                    break;

                case 'unlock_lesson':
                    $lessonId = (int)($_POST['lesson_id'] ?? 0);
                    $lesson = $this->repo->getLessonById($lessonId);
                    if ($lesson) {
                        $this->repo->toggleLessonLock($lessonId, false);
                        $flash['type'] = 'success';
                        $flash['message'] = "Đã mở khóa buổi học [{$lesson['code']}].";
                    }
                    break;

                case 'scenario2_quick_adjust':
                    $datasetNow = $this->repo->loadFullDataset();
                    foreach ($datasetNow['lessons'] as $l) {
                        if ($l['code'] === 'BH04' && (int)$l['is_locked'] === 0) {
                            $checkData = array_merge($l, [
                                'is_locked' => 1,
                                'locked_slot_id' => 3,
                                'locked_room_id' => 1,
                            ]);
                            $val = $this->checker->validateEntityInput('lesson', $checkData, $datasetNow);
                            if ($val['valid']) {
                                $this->repo->toggleLessonLock((int)$l['id'], true, 3, 1);
                            }
                        }
                        if ($l['code'] === 'BH20') {
                            $updated = $l;
                            $updated['student_count'] = 38;
                            $updated['notes'] = 'Đã điều chỉnh sĩ số nhóm thực hành xuống 38 SV (vừa phòng LAB01 tại T2-C2)';
                            $this->repo->updateLesson((int)$l['id'], $updated);
                        }
                    }
                    $datasetAfter = $this->repo->loadFullDataset();
                    $resBasic2 = $this->engine->scheduleBasic($datasetAfter);
                    $idB = $this->repo->saveScheduleVersion(
                        'Tình huống 2 - Phương án Cơ bản (Sau khi khóa & đổi yêu cầu)',
                        'Chạy lại cách xếp cơ bản sau khi khóa thêm BH04 và điều chỉnh sĩ số nhóm thực hành BH20',
                        $resBasic2,
                        $datasetAfter,
                        false
                    );
                    $resImp2 = $this->engine->scheduleImproved($datasetAfter);
                    $idI = $this->repo->saveScheduleVersion(
                        'Tình huống 2 - Phương án Cải thiện (Sau khi khóa & đổi yêu cầu)',
                        'Chạy lại cách xếp cải thiện sau khi khóa thêm BH04 và điều chỉnh sĩ số nhóm thực hành BH20 -> Xếp đủ 20/20 buổi!',
                        $resImp2,
                        $datasetAfter,
                        true
                    );
                    $_GET['compare_a'] = (string)$idB;
                    $_GET['compare_b'] = (string)$idI;
                    $flash['type'] = 'success';
                    $flash['message'] = "Đã thực hiện Tình huống 2: Khóa thêm buổi BH04 (tại T2-C3, P101), điều chỉnh sĩ số nhóm thực hành BH20 xuống 38 SV và chạy lại cả 2 thuật toán! Cách cơ bản xếp được {$resBasic2['scheduled_count']}/20 buổi; Cách cải thiện xếp được {$resImp2['scheduled_count']}/20 buổi.";
                    break;

                case 'add_faculty':
                case 'update_faculty':
                    $facData = [
                        'id' => !empty($_POST['faculty_id']) ? (int)$_POST['faculty_id'] : null,
                        'code' => strtoupper(trim((string)($_POST['code'] ?? ''))),
                        'name' => trim((string)($_POST['name'] ?? '')),
                    ];
                    $val = $this->checker->validateEntityInput('faculty', $facData, $dataset);
                    if (!$val['valid']) {
                        $flash['type'] = 'error';
                        $flash['message'] = 'Không thể lưu Khoa đào tạo:';
                        $flash['details'] = $val['errors'];
                        break;
                    }
                    if ($action === 'add_faculty') {
                        $this->repo->createFaculty($facData);
                        $flash['type'] = 'success';
                        $flash['message'] = "Đã thêm [{$facData['code']} - {$facData['name']}] vào danh mục Khoa của trường.";
                    } else {
                        $this->repo->updateFaculty((int)$facData['id'], $facData);
                        $flash['type'] = 'success';
                        $flash['message'] = "Đã cập nhật thông tin Khoa [{$facData['code']} - {$facData['name']}].";
                    }
                    break;

                case 'delete_faculty':
                    $facId = (int)($_POST['faculty_id'] ?? 0);
                    $this->repo->deleteFaculty($facId);
                    $flash['type'] = 'success';
                    $flash['message'] = 'Đã xóa Khoa khỏi danh mục.';
                    break;

                case 'add_room':
                case 'update_room':
                    $roomData = [
                        'id' => !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null,
                        'code' => trim((string)($_POST['code'] ?? '')),
                        'name' => trim((string)($_POST['name'] ?? '')),
                        'capacity' => (int)($_POST['capacity'] ?? 0),
                        'room_type' => (string)($_POST['room_type'] ?? 'LT'),
                        'building' => trim((string)($_POST['building'] ?? 'Tòa A1')),
                    ];
                    $val = $this->checker->validateEntityInput('room', $roomData, $dataset);
                    if (!$val['valid']) {
                        $flash['type'] = 'error';
                        $flash['message'] = 'Không thể lưu phòng học do dữ liệu không hợp lệ:';
                        $flash['details'] = $val['errors'];
                        break;
                    }
                    if ($action === 'add_room') {
                        $this->repo->createRoom($roomData);
                        $flash['type'] = 'success';
                        $flash['message'] = "Đã thêm phòng học [{$roomData['code']}] (Sức chứa: {$roomData['capacity']} chỗ, Loại: {$roomData['room_type']}).";
                    } else {
                        $this->repo->updateRoom((int)$roomData['id'], $roomData);
                        $flash['type'] = 'success';
                        $flash['message'] = "Đã cập nhật phòng học [{$roomData['code']}] (Sức chứa: {$roomData['capacity']} chỗ, Loại: {$roomData['room_type']}).";
                    }
                    break;

                case 'delete_room':
                    $roomId = (int)($_POST['room_id'] ?? 0);
                    $this->repo->deleteRoom($roomId);
                    $flash['type'] = 'success';
                    $flash['message'] = 'Đã xóa phòng học.';
                    break;

                case 'add_teacher':
                case 'update_teacher':
                    $teacherData = [
                        'id' => !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null,
                        'code' => trim((string)($_POST['code'] ?? '')),
                        'name' => trim((string)($_POST['name'] ?? '')),
                        'department' => trim((string)($_POST['department'] ?? 'Công nghệ thông tin')),
                        'email' => trim((string)($_POST['email'] ?? '')),
                        'max_lessons_per_day' => (int)($_POST['max_lessons_per_day'] ?? 2),
                    ];
                    $val = $this->checker->validateEntityInput('teacher', $teacherData, $dataset);
                    if (!$val['valid']) {
                        $flash['type'] = 'error';
                        $flash['message'] = 'Không thể lưu giảng viên:';
                        $flash['details'] = $val['errors'];
                        break;
                    }
                    if ($action === 'add_teacher') {
                        $this->repo->createTeacher($teacherData);
                        $flash['type'] = 'success';
                        $flash['message'] = "Đã thêm giảng viên [{$teacherData['code']}] thành công.";
                    } else {
                        $this->repo->updateTeacher((int)$teacherData['id'], $teacherData);
                        $flash['type'] = 'success';
                        $flash['message'] = "Đã cập nhật giảng viên [{$teacherData['code']}] thành công.";
                    }
                    break;

                case 'delete_teacher':
                    $teacherId = (int)($_POST['teacher_id'] ?? 0);
                    $this->repo->deleteTeacher($teacherId);
                    $flash['type'] = 'success';
                    $flash['message'] = 'Đã xóa giảng viên.';
                    break;

                case 'add_class':
                case 'update_class':
                    $codeVal = trim((string)($_POST['code'] ?? ''));
                    $classData = [
                        'id' => !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null,
                        'code' => $codeVal,
                        'name' => trim((string)($_POST['name'] ?? '')),
                        'cohort' => ConstraintChecker::extractCohortNumber($codeVal),
                        'student_count' => (int)($_POST['student_count'] ?? 0),
                        'major' => trim((string)($_POST['major'] ?? 'Khoa Công nghệ thông tin')),
                    ];
                    $val = $this->checker->validateEntityInput('class', $classData, $dataset);
                    if (!$val['valid']) {
                        $flash['type'] = 'error';
                        $flash['message'] = 'Không thể lưu lớp học (Sĩ số phải > 0):';
                        $flash['details'] = $val['errors'];
                        break;
                    }
                    if ($action === 'add_class') {
                        $this->repo->createClass($classData);
                        $flash['type'] = 'success';
                        $flash['message'] = "Đã thêm lớp học [{$classData['code']}] với sĩ số {$classData['student_count']} sinh viên.";
                    } else {
                        $syncLessons = !empty($_POST['sync_lessons']);
                        $this->repo->updateClass((int)$classData['id'], $classData, $syncLessons);
                        $syncMsg = $syncLessons ? ' (đã đồng bộ sĩ số sang các buổi học của lớp)' : '';
                        $flash['type'] = 'success';
                        $flash['message'] = "Đã cập nhật lớp học [{$classData['code']}] — Sĩ số: {$classData['student_count']} sinh viên{$syncMsg}.";
                    }
                    break;

                case 'delete_class':
                    $classId = (int)($_POST['class_id'] ?? 0);
                    $this->repo->deleteClass($classId);
                    $flash['type'] = 'success';
                    $flash['message'] = 'Đã xóa lớp học.';
                    break;

                case 'save_unavailability':
                    $uData = [
                        'teacher_id' => (int)($_POST['teacher_id'] ?? 0),
                        'slot_id' => (int)($_POST['slot_id'] ?? 0),
                        'constraint_type' => (string)($_POST['constraint_type'] ?? 'HARD_BUSY'),
                        'reason' => trim((string)($_POST['reason'] ?? 'Lịch cá nhân / Công tác')),
                    ];
                    $val = $this->checker->validateEntityInput('unavailability', $uData, $dataset);
                    if (!$val['valid']) {
                        $flash['type'] = 'error';
                        $flash['message'] = 'Không thể lưu lịch bận / ưu tiên:';
                        $flash['details'] = $val['errors'];
                        break;
                    }
                    $this->repo->saveUnavailability($uData);
                    $flash['type'] = 'success';
                    $flash['message'] = 'Đã cập nhật lịch bận / mong muốn ưu tiên của giảng viên.';
                    break;

                case 'delete_unavailability':
                    $id = (int)($_POST['unavailability_id'] ?? 0);
                    $this->repo->deleteUnavailability($id);
                    $flash['type'] = 'success';
                    $flash['message'] = 'Đã xóa mục lịch bận / ưu tiên.';
                    break;

                case 'add_user':
                case 'update_user':
                    $userId = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
                    $username = trim((string)($_POST['username'] ?? ''));
                    $fullName = trim((string)($_POST['full_name'] ?? ''));
                    $email = trim((string)($_POST['email'] ?? ''));
                    $role = (string)($_POST['role'] ?? 'student');
                    $classId = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
                    $teacherId = !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null;
                    $rawPassword = (string)($_POST['password'] ?? '');

                    if ($username === '' || $fullName === '') {
                        $flash['type'] = 'error';
                        $flash['message'] = 'Vui lòng nhập đầy đủ Mã sinh viên / Tài khoản và Họ tên.';
                        break;
                    }
                    if (!in_array($role, ['student', 'teacher', 'scheduler'], true)) {
                        $role = 'student';
                    }
                    if ($role === 'student' && !$classId) {
                        $flash['type'] = 'error';
                        $flash['message'] = 'Sinh viên bắt buộc phải được gán vào một Lớp học cụ thể.';
                        break;
                    }
                    if ($role === 'teacher' && !$teacherId) {
                        $flash['type'] = 'error';
                        $flash['message'] = 'Tài khoản Giảng viên phải liên kết với Mã giảng viên.';
                        break;
                    }

                    $existing = $this->repo->findUserByUsername($username);
                    if ($existing && ($action === 'add_user' || (int)$existing['id'] !== (int)$userId)) {
                        $flash['type'] = 'error';
                        $flash['message'] = "Mã sinh viên / Tài khoản [{$username}] đã tồn tại trong hệ thống.";
                        break;
                    }

                    if ($action === 'add_user') {
                        $pwToHash = $rawPassword !== '' ? $rawPassword : '123456';
                        $this->repo->createUser([
                            'username' => $username,
                            'password_hash' => password_hash($pwToHash, PASSWORD_BCRYPT),
                            'full_name' => $fullName,
                            'email' => $email,
                            'role' => $role,
                            'teacher_id' => $role === 'teacher' ? $teacherId : null,
                            'class_id' => $role === 'student' ? $classId : null,
                        ]);
                        $flash['type'] = 'success';
                        $flash['message'] = "Đã cấp mới tài khoản / Mã SV [{$username} - {$fullName}] thành công.";
                    } else {
                        $updPayload = [
                            'username' => $username,
                            'full_name' => $fullName,
                            'email' => $email,
                            'role' => $role,
                            'teacher_id' => $role === 'teacher' ? $teacherId : null,
                            'class_id' => $role === 'student' ? $classId : null,
                        ];
                        if ($rawPassword !== '') {
                            $updPayload['password_hash'] = password_hash($rawPassword, PASSWORD_BCRYPT);
                        }
                        $this->repo->updateUser((int)$userId, $updPayload);
                        $flash['type'] = 'success';
                        $flash['message'] = "Đã cập nhật thông tin Mã SV / Tài khoản [{$username} - {$fullName}].";
                    }
                    break;

                case 'delete_user':
                    $delUserId = (int)($_POST['user_id'] ?? 0);
                    if ($delUserId === (int)($currentUser['id'] ?? 0)) {
                        $flash['type'] = 'error';
                        $flash['message'] = 'Không thể xóa tài khoản Quản trị viên đang đăng nhập.';
                        break;
                    }
                    $this->repo->deleteUser($delUserId);
                    $flash['type'] = 'success';
                    $flash['message'] = 'Đã xóa tài khoản / Mã sinh viên khỏi hệ thống.';
                    break;

                case 'check_manual_move':
                case 'apply_manual_move':
                    $versionId = (int)($_POST['version_id'] ?? 0);
                    $lessonId = (int)($_POST['lesson_id'] ?? 0);
                    $targetSlotId = (int)($_POST['target_slot_id'] ?? 0);
                    $targetRoomId = (int)($_POST['target_room_id'] ?? 0);

                    $currentAssignments = $this->repo->getAssignmentsByVersion($versionId);
                    $impact = $this->engine->checkManualMoveImpact(
                        $lessonId,
                        $targetSlotId,
                        $targetRoomId,
                        $currentAssignments,
                        $dataset
                    );
                    $flash['impact_result'] = $impact;

                    if ($action === 'check_manual_move') {
                        $flash['type'] = $impact['can_apply'] ? 'success' : 'error';
                        $flash['message'] = $impact['summary'];
                    } else {
                        if (!$impact['can_apply']) {
                            $flash['type'] = 'error';
                            $flash['message'] = 'Không thể áp dụng đổi lịch vì vi phạm điều kiện bắt buộc: ' . $impact['summary'];
                        } else {
                            $indexed = [];
                            foreach ($currentAssignments as $a) {
                                $indexed[(int)$a['lesson_id']] = $a;
                            }
                            $indexed[$lessonId] = [
                                'lesson_id' => $lessonId,
                                'slot_id' => $targetSlotId,
                                'room_id' => $targetRoomId,
                                'is_locked' => 0,
                            ];
                            $auditAfter = $this->checker->auditScheduleAssignments($indexed, $dataset);
                            $lessonAudit = $auditAfter['assignment_audits'][$lessonId] ?? ['soft_penalty' => 0, 'soft_notes' => ''];

                            $this->repo->applyManualAssignment(
                                $versionId,
                                $lessonId,
                                $targetSlotId,
                                $targetRoomId,
                                (int)$lessonAudit['soft_penalty'],
                                (string)$lessonAudit['soft_notes'],
                                $auditAfter
                            );
                            $flash['type'] = 'success';
                            $flash['message'] = 'Đã cập nhật lịch học thành công! ' . $impact['summary'];
                        }
                    }
                    break;

                case 'activate_version':
                    $vId = (int)($_POST['version_id'] ?? 0);
                    $this->repo->setActiveVersion($vId);
                    $flash['type'] = 'success';
                    $flash['message'] = "Đã chọn Phương án #{$vId} làm phương án đang xét.";
                    break;
            }
        } catch (\Throwable $e) {
            $flash['type'] = 'error';
            $flash['message'] = 'Lỗi xử lý: ' . $e->getMessage();
        }

        return $flash;
    }

    /**
     * Khởi tạo sẵn 2 phương án mẫu nếu bảng schedule_versions đang trống.
     */
    public function ensureInitialVersionsExist(): void
    {
        $versions = $this->repo->getScheduleVersions();
        if (empty($versions)) {
            $dataset = $this->repo->loadFullDataset();
            $resBasic = $this->engine->scheduleBasic($dataset);
            if ($resBasic['success']) {
                $this->repo->saveScheduleVersion(
                    'Phương án 1 - Cách xếp Cơ bản (First-Fit)',
                    'Chạy tuần tự từng buổi học trên bộ dữ liệu gốc (20 buổi, 3 buổi khóa)',
                    $resBasic,
                    $dataset,
                    false
                );
            }
            $resImproved = $this->engine->scheduleImproved($dataset);
            if ($resImproved['success']) {
                $this->repo->saveScheduleVersion(
                    'Phương án 2 - Cách xếp Cải thiện (MRV + Best-Fit)',
                    'Ưu tiên buổi khó (MRV), chọn phòng sát sĩ số và tối ưu điểm phạt mềm trên cùng dữ liệu gốc',
                    $resImproved,
                    $dataset,
                    true
                );
            }
        }
    }

    /**
     * Xuất thời khóa biểu theo Lớp học hoặc Giảng viên (CSV / JSON / Print HTML).
     */
    private function handleExport(): void
    {
        $versionId = (int)($_GET['version_id'] ?? 0);
        $filterType = (string)($_GET['filter_type'] ?? 'all'); // all | teacher | class
        $filterId = (int)($_GET['filter_id'] ?? 0);
        $format = (string)($_GET['format'] ?? 'csv'); // csv | json | print

        // Sinh viên chỉ được xuất thời khóa biểu của đúng lớp mình đang theo học
        $currentUser = $this->getCurrentUser();
        if ($this->isStudentRole() && !empty($currentUser['class_id'])) {
            $filterType = 'class';
            $filterId = (int)$currentUser['class_id'];
        }

        $version = $versionId > 0 ? $this->repo->getScheduleVersionById($versionId) : $this->repo->getActiveScheduleVersion();
        if (!$version) {
            http_response_code(404);
            echo "Không tìm thấy phương án thời khóa biểu để xuất.";
            exit;
        }

        $assignments = $this->repo->getAssignmentsByVersion((int)$version['id']);
        $filtered = [];
        $filterTitle = 'Toàn bộ các lớp và giảng viên';

        foreach ($assignments as $a) {
            if ($filterType === 'teacher' && $filterId > 0) {
                if ((int)$a['teacher_id'] !== $filterId) {
                    continue;
                }
                $filterTitle = "Giảng viên: {$a['teacher_code']} - {$a['teacher_name']}";
            } elseif ($filterType === 'class' && $filterId > 0) {
                if ((int)$a['class_id'] !== $filterId) {
                    continue;
                }
                $filterTitle = "Lớp học: {$a['class_code']} - {$a['class_name']}";
            }
            $filtered[] = $a;
        }

        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="tkb_version_' . $version['id'] . '.json"');
            echo json_encode([
                'version' => [
                    'id' => (int)$version['id'],
                    'name' => $version['version_name'],
                    'algorithm' => $version['algorithm_type'],
                    'filter' => $filterTitle,
                    'scheduled_count' => (int)$version['scheduled_count'],
                    'unscheduled_count' => (int)$version['unscheduled_count'],
                    'soft_penalty_score' => (int)$version['soft_penalty_score'],
                ],
                'assignments' => $filtered,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="tkb_version_' . $version['id'] . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Mã buổi học',
                'Tên học phần',
                'Lớp học',
                'Giảng viên',
                'Sĩ số',
                'Loại phòng YC',
                'Khung giờ',
                'Chi tiết thời gian',
                'Phòng học',
                'Sức chứa phòng',
                'Trạng thái khóa',
                'Điểm phạt ưu tiên',
                'Ghi chú ưu tiên',
            ]);
            foreach ($filtered as $row) {
                $cohortTime = ConstraintChecker::formatCohortTime(
                    (string)$row['class_code'],
                    (int)$row['period_of_day']
                );
                fputcsv($out, [
                    $row['lesson_code'],
                    $row['subject_name'],
                    $row['class_code'] . ' - ' . $row['class_name'],
                    $row['teacher_code'] . ' - ' . $row['teacher_name'],
                    $row['student_count'],
                    $row['required_room_type'],
                    $row['slot_code'],
                    $row['slot_label'] . ' (' . $cohortTime . ')',
                    $row['room_code'] . ' (' . $row['room_type'] . ')',
                    $row['room_capacity'],
                    (int)$row['is_locked'] === 1 ? 'Đã khóa cố định' : 'Xếp tự động',
                    $row['soft_penalty'],
                    $row['soft_notes'],
                ]);
            }
            fclose($out);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html><html lang='vi'><head><meta charset='utf-8'>";
        echo "<title>Xuất Thời Khóa Biểu - " . htmlspecialchars($version['version_name']) . "</title>";
        echo "<style>
            body { font-family: Arial, sans-serif; margin: 24px; color: #1e293b; }
            h1 { font-size: 20px; margin-bottom: 4px; }
            .meta { color: #475569; margin-bottom: 16px; font-size: 14px; }
            table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 13px; }
            th, td { border: 1px solid #cbd5e1; padding: 8px 10px; text-align: left; }
            th { background: #f1f5f9; }
            .print-btn { padding: 8px 16px; background: #2563eb; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
            @media print { .no-print { display: none; } }
        </style></head><body>";
        echo "<div class='no-print' style='margin-bottom:16px;'>
                <button class='print-btn' onclick='window.print()'>In thời khóa biểu / Lưu PDF</button>
                <a href='index.php' style='margin-left:12px;'>Quay lại Thời khóa biểu</a>
              </div>";
        echo "<h1>BẢNG THỜI KHÓA BIỂU ĐÀO TẠO</h1>";
        echo "<div class='meta'>
                <strong>Phạm vi:</strong> " . htmlspecialchars($filterTitle) . "
              </div>";
        echo "<table><thead><tr>
                <th>STT</th><th>Ca học</th><th>Giờ học</th><th>Phòng học</th><th>Mã buổi</th><th>Học phần</th>
                <th>Lớp học</th><th>Giảng viên</th><th>Sĩ số / Sức chứa</th>
              </tr></thead><tbody>";
        if (empty($filtered)) {
            echo "<tr><td colspan='9'>Không có buổi học nào thuộc phạm vi lọc này.</td></tr>";
        } else {
            foreach ($filtered as $idx => $r) {
                $cohortTime = ConstraintChecker::formatCohortTime(
                    (string)$r['class_code'],
                    (int)$r['period_of_day']
                );
                echo "<tr>
                    <td>" . ($idx + 1) . "</td>
                    <td><strong>" . htmlspecialchars($r['slot_code']) . "</strong><br>" . htmlspecialchars($r['slot_label']) . "</td>
                    <td><strong>" . htmlspecialchars($cohortTime) . "</strong></td>
                    <td><strong>" . htmlspecialchars($r['room_code']) . "</strong> (" . htmlspecialchars($r['room_type']) . ")</td>
                    <td>" . htmlspecialchars($r['lesson_code']) . "</td>
                    <td>" . htmlspecialchars($r['subject_name']) . "</td>
                    <td>" . htmlspecialchars($r['class_code']) . "</td>
                    <td>" . htmlspecialchars($r['teacher_name']) . "</td>
                    <td>{$r['student_count']} / {$r['room_capacity']} chỗ</td>
                </tr>";
            }
        }
        echo "</tbody></table></body></html>";
        exit;
    }
}
