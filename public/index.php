<?php
declare(strict_types=1);

session_start();

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

try {
    $pdo = Database::getConnection();
    $connInfo = Database::getConnectionInfo();
    $repo = new TimetableRepository($pdo);
    $checker = new ConstraintChecker();
    $engine = new SchedulerEngine($checker);
    $controller = new TimetableController($repo, $checker, $engine);

    $controller->ensureInitialVersionsExist();
    $flash = $controller->handleRequest();
} catch (\Throwable $e) {
    http_response_code(200);
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><title>Chẩn đoán cấu hình hệ thống</title></head>';
    echo '<body style="font-family:sans-serif; background:#f2f5f8; padding:40px;"><div style="max-width:720px; margin:0 auto; background:#fff; border:1px solid #cbd5e1; border-top:4px solid #b91c1c; padding:24px; border-radius:4px;">';
    echo '<h2 style="margin-top:0; color:#b91c1c;">Thông báo chẩn đoán kết nối máy chủ</h2>';
    echo '<p><strong>Chi tiết lỗi:</strong> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><strong>Tệp:</strong> ' . htmlspecialchars($e->getFile() . ':' . $e->getLine(), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><strong>Extensions đã nạp:</strong> ' . htmlspecialchars(implode(', ', get_loaded_extensions()), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</div></body></html>';
    exit;
}

$currentUser = $controller->getCurrentUser();
$isAuthenticated = $controller->isAuthenticated();
$currentRole = $controller->getCurrentRole();
$isScheduler = $controller->isSchedulerRole();
$isTeacher = $controller->isTeacherRole();
$isStudent = $controller->isStudentRole();

$dataset = $repo->loadFullDataset();
$validation = $checker->validateDataset($dataset);
$versions = $repo->getScheduleVersions();

// Chọn phương án đang xem
$selectedVersionId = isset($_GET['version_id']) ? (int)$_GET['version_id'] : 0;
$activeVersion = $selectedVersionId > 0
    ? ($repo->getScheduleVersionById($selectedVersionId) ?? $repo->getActiveScheduleVersion())
    : $repo->getActiveScheduleVersion();

$activeAssignments = $activeVersion ? $repo->getAssignmentsByVersion((int)$activeVersion['id']) : [];
$activeAudit = $checker->auditScheduleAssignments($activeAssignments, $dataset);

$activeTab = $isStudent ? 'schedule' : (string)($_GET['tab'] ?? 'schedule');
if (!in_array($activeTab, ['data', 'validate', 'schedule', 'adjust', 'compare', 'requests', 'analytics'], true)) {
    $activeTab = 'schedule';
}

// Bộ lọc xem lịch: Sinh viên CHỈ xem đúng Thời khóa biểu của lớp mình đang học; Giảng viên mặc định lọc theo Giảng viên
if ($isStudent) {
    $viewFilterType = 'class';
    $viewFilterId = (int)($currentUser['class_id'] ?? 1);
} elseif (isset($_GET['filter_type'])) {
    $viewFilterType = (string)$_GET['filter_type'];
    $viewFilterId = (int)($_GET['filter_id'] ?? 0);
} elseif ($isTeacher && !empty($currentUser['teacher_id'])) {
    $viewFilterType = 'teacher';
    $viewFilterId = (int)$currentUser['teacher_id'];
} else {
    $viewFilterType = 'all';
    $viewFilterId = 0;
}

function h(mixed $val): string
{
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= !$isAuthenticated ? 'Đăng nhập hệ thống' : 'Đề tài 18 - Hệ thống Xếp Thời Khóa Biểu Theo Ràng Buộc (Nhóm 10)' ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="public/assets/style.css">
</head>
<body class="<?= !$isAuthenticated ? 'login-page-body' : '' ?>">

<?php if (!$isAuthenticated): ?>
    <!-- ==================== GIAO DIỆN ĐĂNG NHẬP HIỆN ĐẠI (SMART TIMETABLE PORTAL) ==================== -->
    <?php
    $selectedLoginRole = (string)($_POST['login_role'] ?? 'student');
    ?>
    <div class="auth-shell">
        <div class="dnu-login-card">
            <!-- Brand Header -->
            <div class="dnu-logo-wrap">
                <div class="modern-brand-badge">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="3" ry="3"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                        <path d="M8 14h.01"></path>
                        <path d="M12 14h.01"></path>
                        <path d="M16 14h.01"></path>
                        <path d="M8 18h.01"></path>
                        <path d="M12 18h.01"></path>
                    </svg>
                </div>
                <div class="dnu-logo-title">CỔNG THÔNG TIN ĐÀO TẠO</div>
                <div class="dnu-logo-sub">Hệ thống Quản lý &amp; Xếp Thời Khóa Biểu Học Kỳ</div>
            </div>

            <?php if ($flash['message'] !== ''): ?>
                <div class="alert alert-<?= h($flash['type'] ?: 'error') ?>" style="text-align:left; padding:10px 14px; font-size:13px; margin-bottom:16px;">
                    <strong><?= h($flash['message']) ?></strong>
                    <?php if (!empty($flash['details'])): ?>
                        <ul style="margin:4px 0 0 16px; padding:0;">
                            <?php foreach ($flash['details'] as $err): ?>
                                <li><?= h($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div id="dnu_login_panel">
                <h1 class="dnu-heading">Đăng nhập hệ thống</h1>

                <form method="post" action="index.php" id="dnu_login_form">
                    <input type="hidden" name="action" value="login">

                    <!-- 1. Chọn nhóm phân quyền (Sinh Viên / Giảng Viên / Phụ trách Đào tạo) -->
                    <div class="modern-field">
                        <label for="login_role" class="modern-field-label">Vai trò truy cập</label>
                        <div class="dnu-input-group">
                            <span class="dnu-input-addon" title="Phân quyền người dùng">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                </svg>
                            </span>
                            <select name="login_role" id="login_role" class="dnu-form-control" onchange="updateForgotLabel()">
                                <option value="student" <?= $selectedLoginRole === 'student' ? 'selected' : '' ?>>Sinh viên (Tra cứu lịch học)</option>
                                <option value="teacher" <?= $selectedLoginRole === 'teacher' ? 'selected' : '' ?>>Giảng viên (Lịch dạy &amp; Lịch bận)</option>
                                <option value="scheduler" <?= $selectedLoginRole === 'scheduler' ? 'selected' : '' ?>>Quản trị Đào tạo (Xếp thời khóa biểu)</option>
                            </select>
                        </div>
                    </div>

                    <!-- 2. Tài khoản -->
                    <div class="modern-field">
                        <label for="login_username" class="modern-field-label">Tài khoản / Mã định danh</label>
                        <div class="dnu-input-group">
                            <span class="dnu-input-addon" title="Tài khoản">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                            </span>
                            <input type="text" name="username" id="login_username" class="dnu-form-control"
                                   placeholder="Nhập mã SV, mã GV hoặc tài khoản..." value="<?= h($_POST['username'] ?? '') ?>" required>
                        </div>
                    </div>

                    <!-- 3. Mật khẩu + Nút con mắt ẩn/hiện -->
                    <div class="modern-field">
                        <label for="login_password" class="modern-field-label">Mật khẩu</label>
                        <div class="dnu-input-group has-right-addon">
                            <span class="dnu-input-addon" title="Mật khẩu">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                </svg>
                            </span>
                            <input type="password" name="password" id="login_password" class="dnu-form-control"
                                   placeholder="Nhập mật khẩu..." required>
                            <button type="button" class="dnu-input-addon-right" onclick="togglePasswordVisibility()" title="Hiện/Ẩn mật khẩu">
                                <svg id="eye_icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="dnu-btn-row">
                        <button type="submit" class="dnu-btn dnu-btn-login">
                            <span>Đăng nhập vào hệ thống</span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="5" y1="12" x2="19" y2="12"/>
                                <polyline points="12 5 19 12 12 19"/>
                            </svg>
                        </button>
                    </div>
                </form>

                <!-- Gợi ý điền nhanh tài khoản mẫu để kiểm thử -->
                <div class="dnu-demo-hint" id="demo_hint_box">
                    <div class="demo-hint-header">
                        <span>⚡ <strong>Tài khoản trải nghiệm nhanh</strong> (Mật khẩu: <code>123456</code>)</span>
                        <span id="forgot_pw_link" style="font-size:11px; color:#64748b;">Chạm để điền</span>
                    </div>
                    <div class="demo-chip-grid">
                        <button type="button" class="dnu-demo-chip" onclick="fillDemoLogin('scheduler', 'admin', '123456')">
                            <span class="chip-role">⚙️ Quản trị</span>
                            <span class="chip-code">admin</span>
                        </button>
                        <button type="button" class="dnu-demo-chip" onclick="fillDemoLogin('teacher', 'gv01', '123456')">
                            <span class="chip-role">👨‍🏫 Giảng viên</span>
                            <span class="chip-code">gv01</span>
                        </button>
                        <button type="button" class="dnu-demo-chip" onclick="fillDemoLogin('student', 'sv01', '123456')">
                            <span class="chip-role">🎓 SV 18-12</span>
                            <span class="chip-code">sv01</span>
                        </button>
                        <button type="button" class="dnu-demo-chip" onclick="fillDemoLogin('student', 'sv03', '123456')">
                            <span class="chip-role">🎓 SV 19-09</span>
                            <span class="chip-code">sv03</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function togglePasswordVisibility() {
        const pwInput = document.getElementById('login_password');
        pwInput.type = (pwInput.type === 'password') ? 'text' : 'password';
    }
    function updateForgotLabel() {}
    function fillDemoLogin(role, username, password) {
        document.getElementById('login_role').value = role;
        document.getElementById('login_username').value = username;
        document.getElementById('login_password').value = password;
    }
    </script>

<?php else: ?>
<?php
$allUsers = $dataset['users'] ?? [];
$studentAccounts = array_values(array_filter($allUsers, static fn(array $u): bool => ($u['role'] ?? '') === 'student'));
$activeSec = (string)($_GET['sec'] ?? '');
?>

<?php if ($isScheduler): ?>
<!-- ==================== BỐ CỤC QUẢN TRỊ VỚI BANNER DỌC BÊN TRÁI CÙNG (LEFT-MOST VERTICAL SIDEBAR) ==================== -->
<?php
$schedPct = $activeAudit['total_lessons'] > 0
    ? round(($activeAudit['scheduled_count'] * 100) / $activeAudit['total_lessons'], 1)
    : 0;
$allRescheduleRequests = $dataset['reschedule_requests'] ?? [];
$pendingReqCount = count(array_filter(
    $allRescheduleRequests,
    static fn(array $r): bool => ($r['status'] ?? '') === 'PENDING'
));
?>
<div class="admin-layout-wrapper">
    <!-- Banner dọc cố định bên trái cùng -->
    <aside class="admin-vertical-banner">
        <div class="admin-vbanner-brand">
            <div class="admin-brand-icon">🏛️</div>
            <div>
                <div class="admin-vbanner-logo">PHÒNG ĐÀO TẠO</div>
                <div class="admin-vbanner-sub">HỆ THỐNG XẾP THỜI KHÓA BIỂU</div>
            </div>
        </div>

        <nav class="admin-vbanner-list">
            <div class="vbanner-group-label">DANH MỤC QUẢN LÝ ĐÀO TẠO</div>
            <a href="index.php?tab=schedule"
               class="admin-vbanner-item <?= $activeTab === 'schedule' ? 'active' : '' ?>">
                <span class="vbanner-item-left"><span class="vbanner-icon">📅</span> Thời khóa biểu</span>
            </a>
            <a href="index.php?tab=data&sec=classes"
               class="admin-vbanner-item <?= ($activeTab === 'data' && $activeSec === 'classes') ? 'active' : '' ?>">
                <span class="vbanner-item-left"><span class="vbanner-icon">🏫</span> Lớp học</span>
                <span class="vbanner-badge"><?= count($dataset['classes'] ?? []) ?></span>
            </a>
            <a href="index.php?tab=data&sec=siso"
               class="admin-vbanner-item <?= ($activeTab === 'data' && $activeSec === 'siso') ? 'active' : '' ?>">
                <span class="vbanner-item-left"><span class="vbanner-icon">👥</span> Sĩ số</span>
            </a>
            <a href="index.php?tab=data&sec=faculties"
               class="admin-vbanner-item <?= ($activeTab === 'data' && $activeSec === 'faculties') ? 'active' : '' ?>">
                <span class="vbanner-item-left"><span class="vbanner-icon">🏛️</span> Khoa đào tạo</span>
                <span class="vbanner-badge"><?= count($dataset['faculties'] ?? []) ?></span>
            </a>
            <a href="index.php?tab=data&sec=lessons"
               class="admin-vbanner-item <?= ($activeTab === 'data' && ($activeSec === 'lessons' || $activeSec === '')) ? 'active' : '' ?>">
                <span class="vbanner-item-left"><span class="vbanner-icon">📚</span> Học phần / Môn học</span>
                <span class="vbanner-badge"><?= count($dataset['lessons'] ?? []) ?></span>
            </a>
            <a href="index.php?tab=data&sec=students"
               class="admin-vbanner-item <?= ($activeTab === 'data' && $activeSec === 'students') ? 'active' : '' ?>">
                <span class="vbanner-item-left"><span class="vbanner-icon">🎓</span> Danh sách Sinh viên</span>
                <span class="vbanner-badge"><?= count($allUsers) ?></span>
            </a>
            <a href="index.php?tab=data&sec=teachers"
               class="admin-vbanner-item <?= ($activeTab === 'data' && $activeSec === 'teachers') ? 'active' : '' ?>">
                <span class="vbanner-item-left"><span class="vbanner-icon">👨‍🏫</span> Giảng viên</span>
                <span class="vbanner-badge"><?= count($dataset['teachers'] ?? []) ?></span>
            </a>
            <a href="index.php?tab=data&sec=unavailability"
               class="admin-vbanner-item <?= ($activeTab === 'data' && $activeSec === 'unavailability') ? 'active' : '' ?>">
                <span class="vbanner-item-left"><span class="vbanner-icon">🗓️</span> Lịch bận GV</span>
                <span class="vbanner-badge"><?= count($dataset['unavailability'] ?? []) ?></span>
            </a>
            <a href="index.php?tab=data&sec=rooms"
               class="admin-vbanner-item <?= ($activeTab === 'data' && $activeSec === 'rooms') ? 'active' : '' ?>">
                <span class="vbanner-item-left"><span class="vbanner-icon">🏢</span> Phòng học</span>
                <span class="vbanner-badge"><?= count($dataset['rooms'] ?? []) ?></span>
            </a>
            <a href="index.php?tab=data&sec=slots"
               class="admin-vbanner-item <?= ($activeTab === 'data' && $activeSec === 'slots') ? 'active' : '' ?>">
                <span class="vbanner-item-left"><span class="vbanner-icon">🕒</span> Khung giờ học</span>
                <span class="vbanner-badge"><?= count($dataset['slots'] ?? []) ?></span>
            </a>

            <div class="vbanner-group-label" style="margin-top:10px;">NGHIỆP VỤ XẾP LỊCH HỌC KỲ</div>
            <a href="index.php?tab=requests"
               class="admin-vbanner-item <?= $activeTab === 'requests' ? 'active' : '' ?>">
                <span class="vbanner-item-left"><span class="vbanner-icon">📩</span> Duyệt đổi lịch GV</span>
                <span class="vbanner-badge" style="<?= $pendingReqCount > 0 ? 'background:#c92a2a; color:#fff;' : '' ?>"><?= $pendingReqCount ?></span>
            </a>
            <a href="index.php?tab=analytics"
               class="admin-vbanner-item <?= $activeTab === 'analytics' ? 'active' : '' ?>">
                <span class="vbanner-item-left"><span class="vbanner-icon">📈</span> Thống kê &amp; Nhập CSV</span>
            </a>
            <a href="index.php?tab=validate"
               class="admin-vbanner-item <?= $activeTab === 'validate' ? 'active' : '' ?>">
                <span class="vbanner-item-left"><span class="vbanner-icon">🛡️</span> Kiểm tra Ràng buộc</span>
            </a>
            <a href="index.php?tab=adjust"
               class="admin-vbanner-item <?= $activeTab === 'adjust' ? 'active' : '' ?>">
                <span class="vbanner-item-left"><span class="vbanner-icon">🔧</span> Điều chỉnh lịch học</span>
            </a>
            <a href="index.php?tab=compare"
               class="admin-vbanner-item <?= $activeTab === 'compare' ? 'active' : '' ?>">
                <span class="vbanner-item-left"><span class="vbanner-icon">📊</span> Đối sánh &amp; Xuất biểu</span>
            </a>
        </nav>

        <div class="admin-vbanner-footer">
            <div class="vbanner-quick-scenarios">
                <form method="post" action="index.php?tab=schedule" style="margin:0;">
                    <input type="hidden" name="action" value="reset_seed">
                    <button type="submit" class="vbanner-btn" title="Khôi phục dữ liệu chuẩn & tạo lại 2 phương án">🔄 Kịch bản 1</button>
                </form>
                <form method="post" action="index.php?tab=compare" style="margin:0;">
                    <input type="hidden" name="action" value="scenario2_quick_adjust">
                    <button type="submit" class="vbanner-btn vbanner-btn-accent" title="Khóa thêm BH04, đổi sĩ số BH20 & chạy so sánh">⚡ Kịch bản 2</button>
                </form>
            </div>
            <div class="admin-vbanner-user">
                <span>👤 <?= h($currentUser['full_name']) ?></span>
                <span class="vbanner-role-tag">GIÁO VỤ</span>
            </div>
            <a href="index.php?action=logout" class="vbanner-btn vbanner-logout">🚪 Đăng xuất hệ thống</a>
        </div>
    </aside>

    <!-- Khung nội dung chính bên phải -->
    <main class="admin-main-content">
        <div class="edu-admin-topbar">
            <div class="edu-topbar-left">
                <div class="edu-topbar-title">CỔNG QUẢN LÝ ĐÀO TẠO &amp; XẾP THỜI KHÓA BIỂU TỰ ĐỘNG</div>
                <div class="edu-topbar-sub">Học kỳ I — Năm học 2026–2027 • Phương án đang hiển thị: <strong><?= h($activeVersion['version_name'] ?? 'Chưa chọn') ?></strong></div>
            </div>
            <div class="edu-topbar-right">
                <span class="edu-status-item">Đã xếp: <strong><?= (int)$activeAudit['scheduled_count'] ?>/<?= (int)$activeAudit['total_lessons'] ?> học phần (<?= $schedPct ?>%)</strong></span>
                <span class="edu-status-item">Vi phạm cứng: <strong style="color:<?= $activeAudit['hard_violations'] === 0 ? '#15803d' : '#b91c1c' ?>;"><?= (int)$activeAudit['hard_violations'] ?></strong></span>
                <span class="edu-status-item">Điểm phạt mềm: <strong><?= (int)$activeAudit['soft_penalty_score'] ?>đ</strong></span>
            </div>
        </div>
<?php else: ?>
<?php
$studentCohortTimeS = ConstraintChecker::formatCohortTime((string)($currentUser['class_code'] ?? '18-12'), 1);
$studentCohortTimeC = ConstraintChecker::formatCohortTime((string)($currentUser['class_code'] ?? '18-12'), 2);
$avatarInitial = mb_strtoupper(mb_substr(trim((string)($currentUser['full_name'] ?? 'U')), 0, 1, 'UTF-8'), 'UTF-8');
?>
<header class="portal-header <?= $isStudent ? 'student-theme' : 'teacher-theme' ?>">
    <div class="portal-header-inner">
        <div class="portal-identity">
            <div class="portal-avatar"><?= h($avatarInitial) ?></div>
            <div>
                <div class="portal-eyebrow">
                    <?= $isStudent ? 'CỔNG THÔNG TIN ĐÀO TẠO • TRA CỨU THỜI KHÓA BIỂU SINH VIÊN' : 'CỔNG THÔNG TIN GIẢNG VIÊN • QUẢN LÝ LỊCH GIẢNG DẠY HỌC KỲ' ?>
                </div>
                <h1 class="portal-title"><?= h($currentUser['full_name'] ?? '') ?></h1>
                <div class="portal-meta-pills">
                    <?php if ($isStudent): ?>
                        <span class="portal-pill">Mã SV: <strong><?= h($currentUser['username'] ?? '') ?></strong></span>
                        <span class="portal-pill">Lớp hành chính: <strong><?= h($currentUser['class_code'] ?? '') ?></strong></span>
                        <?php if (!empty($currentUser['class_major'])): ?>
                            <span class="portal-pill"><?= h($currentUser['class_major']) ?></span>
                        <?php endif; ?>
                        <span class="portal-pill">Khung giờ: Sáng <?= h($studentCohortTimeS) ?> | Chiều <?= h($studentCohortTimeC) ?></span>
                    <?php else: ?>
                        <span class="portal-pill">Mã GV: <strong><?= h($currentUser['teacher_code'] ?? $currentUser['username']) ?></strong></span>
                        <?php
                        $myTeacherObj = null;
                        foreach ($dataset['teachers'] as $tObj) {
                            if ((int)$tObj['id'] === (int)($currentUser['teacher_id'] ?? 0)) {
                                $myTeacherObj = $tObj;
                                break;
                            }
                        }
                        ?>
                        <?php if ($myTeacherObj): ?>
                            <span class="portal-pill"><?= h($myTeacherObj['department']) ?></span>
                            <span class="portal-pill">Định mức: <strong><?= (int)$myTeacherObj['max_lessons_per_day'] ?> ca/ngày</strong></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="portal-actions">
            <?php if ($isTeacher): ?>
                <nav class="portal-nav-pills">
                    <a href="index.php?tab=schedule" class="portal-nav-link <?= $activeTab === 'schedule' ? 'active' : '' ?>">
                        📅 Lịch giảng dạy
                    </a>
                    <a href="index.php?tab=data" class="portal-nav-link <?= $activeTab === 'data' ? 'active' : '' ?>">
                        🗓️ Đăng ký Lịch bận &amp; Nguyện vọng
                    </a>
                    <a href="index.php?tab=compare" class="portal-nav-link <?= $activeTab === 'compare' ? 'active' : '' ?>">
                        📤 In / Xuất lịch dạy
                    </a>
                </nav>
            <?php endif; ?>
            <a href="index.php?action=logout" class="portal-logout-btn">Đăng xuất</a>
        </div>
    </div>
</header>

<main class="container">
<?php endif; ?>
    <?php if ($flash['message'] !== ''): ?>
        <div class="alert alert-<?= h($flash['type'] ?: 'success') ?>">
            <strong><?= h($flash['message']) ?></strong>
            <?php if (!empty($flash['details'])): ?>
                <ul style="margin:6px 0 0 18px;">
                    <?php foreach ($flash['details'] as $err): ?>
                        <li><?= h($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>
        <!-- ==================== ĐÃ ĐĂNG NHẬP: HIỂN THỊ HỆ THỐNG THEO PHÂN QUYỀN ==================== -->

        <?php if ($activeTab === 'data' && $isTeacher): ?>
            <!-- ==================== GIAO DIỆN QUẢN LÝ LỊCH BẬN & NGUYỆN VỌNG RIÊNG CHO GIẢNG VIÊN ==================== -->
            <?php
            $myTid = (int)($currentUser['teacher_id'] ?? 0);
            $myUnavailBySlot = [];
            $myUnavailList = [];
            foreach ($dataset['unavailability'] as $uItem) {
                if ((int)$uItem['teacher_id'] === $myTid) {
                    $myUnavailBySlot[(int)$uItem['slot_id']] = $uItem;
                    $myUnavailList[] = $uItem;
                }
            }
            $myTeachingBySlot = [];
            foreach ($activeAssignments as $aItem) {
                if ((int)$aItem['teacher_id'] === $myTid) {
                    $myTeachingBySlot[(int)$aItem['slot_id']] = $aItem;
                }
            }
            $slotMatrix = [];
            foreach ($dataset['slots'] as $sItem) {
                $wNo = (int)($sItem['week_no'] ?? 1);
                $pNo = (int)$sItem['period_of_day'];
                $dNo = (int)$sItem['day_of_week'];
                $slotMatrix[$wNo][$pNo][$dNo] = $sItem;
            }
            ?>
            <div class="teacher-studio-grid">
                <!-- Cột trái: Ma trận 28 khung giờ trực quan của Giảng viên (Thứ 2 -> Chủ nhật) -->
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2>🗓️ Ma trận Trạng thái 28 Khung giờ Cá nhân (Thứ 2 – Chủ nhật)</h2>
                            <p style="margin:4px 0 0; font-size:12.5px; color:#64748b;">
                                Bấm vào bất kỳ ô khung giờ nào bên dưới để chọn nhanh vào biểu mẫu đăng ký bên phải.
                            </p>
                        </div>
                        <div class="matrix-legend">
                            <span class="legend-dot dot-teaching">📚 Đang có lịch dạy</span>
                            <span class="legend-dot dot-busy">🔴 Bận cứng</span>
                            <span class="legend-dot dot-prefer">🟢 Ưu tiên</span>
                            <span class="legend-dot dot-avoid">🟡 Hạn chế</span>
                        </div>
                    </div>

                    <?php foreach ([1 => 'Tuần 1 (19/10 - 25/10/2026)', 2 => 'Tuần 2 (26/10 - 01/11/2026)'] as $wNo => $wTitle): ?>
                        <div style="font-weight:700; font-size:13px; color:#1e293b; margin: <?= $wNo > 1 ? '16px' : '0' ?> 0 8px;">
                            <?= h($wTitle) ?>
                        </div>
                        <div class="avail-matrix-wrap" style="overflow-x:auto;">
                            <table class="avail-matrix-table">
                                <thead>
                                    <tr>
                                        <th>Ca</th>
                                        <th>Thứ 2</th>
                                        <th>Thứ 3</th>
                                        <th>Thứ 4</th>
                                        <th>Thứ 5</th>
                                        <th>Thứ 6</th>
                                        <th>Thứ 7</th>
                                        <th>Chủ nhật</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ([1 => '☀️ Sáng', 2 => '🌤️ Chiều'] as $pNo => $pLabel): ?>
                                    <tr>
                                        <td style="font-weight:700; text-align:center; background:#f8fafc; width:82px;"><?= $pLabel ?></td>
                                        <?php for ($dNo = 2; $dNo <= 8; $dNo++):
                                            $sCell = $slotMatrix[$wNo][$pNo][$dNo] ?? null;
                                            $sId = $sCell ? (int)$sCell['id'] : 0;
                                            $uCell = $myUnavailBySlot[$sId] ?? null;
                                            $tCell = $myTeachingBySlot[$sId] ?? null;
                                            $cellClass = 'slot-free';
                                            $statusText = '⚪ Trống';
                                            if ($uCell) {
                                                if ($uCell['constraint_type'] === 'HARD_BUSY') {
                                                    $cellClass = 'slot-busy';
                                                    $statusText = '🔴 Bận cứng';
                                                } elseif ($uCell['constraint_type'] === 'SOFT_PREFER') {
                                                    $cellClass = 'slot-prefer';
                                                    $statusText = '🟢 Ưu tiên (-4đ)';
                                                } else {
                                                    $cellClass = 'slot-avoid';
                                                    $statusText = '🟡 Hạn chế (+15đ)';
                                                }
                                            }
                                        ?>
                                        <td class="avail-cell <?= $cellClass ?>"
                                            onclick="pickTeacherSlot(<?= $sId ?>, '<?= h($uCell['constraint_type'] ?? 'HARD_BUSY') ?>', '<?= h($uCell['reason'] ?? '') ?>')">
                                            <div class="avail-slot-code"><?= h($sCell['code'] ?? '') ?></div>
                                            <div class="avail-slot-status"><?= $statusText ?></div>
                                            <?php if ($tCell): ?>
                                                <div class="avail-teaching-pill">
                                                    📚 <?= h($tCell['class_code']) ?> (P.<?= h($tCell['room_code']) ?>)
                                                </div>
                                            <?php elseif ($uCell): ?>
                                                <div class="avail-reason-text"><?= h($uCell['reason']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <?php endfor; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Cột phải: Form đăng ký & Danh sách lịch bận của riêng Giảng viên -->
                <div>
                    <div class="card">
                        <div class="card-header">
                            <h2>✍️ Đăng ký / Cập nhật Lịch bận &amp; Nguyện vọng</h2>
                        </div>
                        <form method="post" action="index.php?tab=data">
                            <input type="hidden" name="action" value="save_unavailability">
                            <input type="hidden" name="teacher_id" value="<?= $myTid ?>">
                            <div class="form-group" style="margin-bottom:12px;">
                                <label>1. Chọn Khung giờ (hoặc chạm vào ô trên ma trận)</label>
                                <select name="slot_id" id="teacher_slot_select">
                                    <?php foreach ($dataset['slots'] as $s): ?>
                                        <option value="<?= (int)$s['id'] ?>"><?= h($s['code'] . ' — ' . $s['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom:12px;">
                                <label>2. Loại đăng ký</label>
                                <select name="constraint_type" id="teacher_type_select">
                                    <option value="HARD_BUSY">🔴 HARD_BUSY — Bận bắt buộc (Tuyệt đối không xếp lịch)</option>
                                    <option value="SOFT_PREFER">🟢 SOFT_PREFER — Khung giờ mong muốn dạy (Được ưu tiên -4đ)</option>
                                    <option value="SOFT_AVOID">🟡 SOFT_AVOID — Mong muốn hạn chế dạy (Cộng phạt mềm +15đ)</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom:14px;">
                                <label>3. Lý do / Ghi chú công tác</label>
                                <input type="text" name="reason" id="teacher_reason_input" placeholder="VD: Họp Hội đồng Khoa, Hướng dẫn NCS, Lịch cá nhân..." required>
                            </div>
                            <button type="submit" class="btn btn-primary" style="width:100%;">💾 Lưu đăng ký vào hệ thống</button>
                        </form>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3>📋 Danh sách đã đăng ký của tôi (<?= count($myUnavailList) ?> khung giờ)</h3>
                        </div>
                        <div style="max-height:330px; overflow-y:auto;">
                            <table>
                                <thead>
                                    <tr><th>Khung giờ</th><th>Phân loại</th><th>Lý do</th><th>Xóa</th></tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($myUnavailList)): ?>
                                        <tr><td colspan="4" style="text-align:center; color:#64748b;">Chưa có đăng ký lịch bận nào.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($myUnavailList as $u): ?>
                                        <tr>
                                            <td><strong><?= h($u['slot_code']) ?></strong></td>
                                            <td>
                                                <?php if ($u['constraint_type'] === 'HARD_BUSY'): ?>
                                                    <span class="badge badge-hard">🔴 Bận cứng</span>
                                                <?php elseif ($u['constraint_type'] === 'SOFT_AVOID'): ?>
                                                    <span class="badge badge-soft-avoid">🟡 Hạn chế</span>
                                                <?php else: ?>
                                                    <span class="badge badge-soft-prefer">🟢 Ưu tiên</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= h($u['reason']) ?></td>
                                            <td>
                                                <form method="post" action="index.php?tab=data" style="display:inline;">
                                                    <input type="hidden" name="action" value="delete_unavailability">
                                                    <input type="hidden" name="unavailability_id" value="<?= (int)$u['id'] ?>">
                                                    <button type="submit" class="btn btn-outline btn-sm">✕</button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <script>
            function pickTeacherSlot(slotId, cType, reason) {
                const sEl = document.getElementById('teacher_slot_select');
                const tEl = document.getElementById('teacher_type_select');
                const rEl = document.getElementById('teacher_reason_input');
                if (sEl && slotId) sEl.value = slotId;
                if (tEl && cType) tEl.value = cType;
                if (rEl) {
                    rEl.value = reason || '';
                    rEl.focus();
                }
            }
            </script>

        <?php elseif ($activeTab === 'data'): ?>
            <!-- ==================== QUẢN LÝ DỮ LIỆU THEO TỪNG DANH MỤC TRÊN BANNER DỌC ==================== -->
            <datalist id="faculty_datalist">
                <?php foreach ($dataset['faculties'] ?? [] as $f): ?>
                    <option value="<?= h($f['name']) ?>"><?= h($f['code'] . ' - ' . $f['name']) ?></option>
                <?php endforeach; ?>
            </datalist>

            <?php if ($activeSec === '' || $activeSec === 'lessons'): ?>
            <div class="card" id="sec-lessons">
                <div class="card-header">
                    <h2>📚 Môn học</h2>
                </div>

                <?php if ($isScheduler): ?>
                <details style="margin-bottom: 16px; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid #cbd5e1;" open>
                    <summary style="cursor:pointer; font-weight:600; color:#1d4ed8;">➕ Thêm / Chỉnh sửa Môn học</summary>
                    <form method="post" action="index.php?tab=data&sec=lessons" style="margin-top:12px;">
                        <input type="hidden" name="action" value="add_lesson" id="lesson_form_action">
                        <input type="hidden" name="lesson_id" value="" id="lesson_form_id">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Mã buổi học *</label>
                                <input type="text" name="code" id="lf_code" placeholder="VD: BH21" required>
                            </div>
                            <div class="form-group">
                                <label>Tên học phần *</label>
                                <input type="text" name="subject_name" id="lf_subject" placeholder="VD: Phát triển ứng dụng Web" required>
                            </div>
                            <div class="form-group">
                                <label>Giảng viên *</label>
                                <select name="teacher_id" id="lf_teacher">
                                    <?php foreach ($dataset['teachers'] as $t): ?>
                                        <option value="<?= (int)$t['id'] ?>"><?= h($t['code'] . ' - ' . $t['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Lớp học *</label>
                                <select name="class_id" id="lf_class" onchange="syncClassStudentCount()">
                                    <?php foreach ($dataset['classes'] as $c): ?>
                                        <option value="<?= (int)$c['id'] ?>" data-students="<?= (int)$c['student_count'] ?>">
                                            <?= h($c['code'] . ' (' . $c['student_count'] . ' SV)') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Sĩ số *</label>
                                <input type="number" name="student_count" id="lf_count" value="<?= (int)($dataset['classes'][0]['student_count'] ?? 35) ?>" min="1" max="500" required>
                            </div>
                            <div class="form-group">
                                <label>Loại phòng *</label>
                                <select name="required_room_type" id="lf_rtype">
                                    <option value="LT">LT (Phòng Lý thuyết)</option>
                                    <option value="TH">TH (Phòng Thực hành máy tính)</option>
                                    <option value="TN">TN (Phòng Thí nghiệm)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Khóa cố định?</label>
                                <select name="is_locked" id="lf_locked">
                                    <option value="0">0 - Không khóa</option>
                                    <option value="1">1 - Khóa cố định</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Khung giờ khóa</label>
                                <select name="locked_slot_id" id="lf_slot">
                                    <option value="">-- Chọn khung giờ --</option>
                                    <?php foreach ($dataset['slots'] as $s): ?>
                                        <option value="<?= (int)$s['id'] ?>"><?= h($s['code'] . ' - ' . $s['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Phòng học khóa</label>
                                <select name="locked_room_id" id="lf_room">
                                    <option value="">-- Chọn phòng --</option>
                                    <?php foreach ($dataset['rooms'] as $r): ?>
                                        <option value="<?= (int)$r['id'] ?>"><?= h($r['code'] . ' (' . $r['room_type'] . ', ' . $r['capacity'] . ' chỗ)') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Ghi chú</label>
                                <input type="text" name="notes" id="lf_notes" placeholder="Ghi chú...">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary" id="lf_submit_btn">Lưu buổi học</button>
                        <button type="button" class="btn btn-outline" onclick="resetLessonForm()">Làm mới</button>
                    </form>
                </details>
                <?php endif; ?>

                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Mã</th>
                                <th>Tên học phần</th>
                                <th>Giảng viên</th>
                                <th>Lớp học</th>
                                <th>Sĩ số</th>
                                <th>Loại phòng</th>
                                <th>Trạng thái khóa</th>
                                <th>Ghi chú</th>
                                <?php if ($isScheduler): ?><th>Thao tác</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dataset['lessons'] as $l): ?>
                            <tr>
                                <td><strong><?= h($l['code']) ?></strong></td>
                                <td><?= h($l['subject_name']) ?></td>
                                <td><?= h($l['teacher_code'] . ' - ' . $l['teacher_name']) ?></td>
                                <td><?= h($l['class_code']) ?></td>
                                <td><?= (int)$l['student_count'] ?> SV</td>
                                <td><span class="badge badge-<?= strtolower(h($l['required_room_type'])) ?>"><?= h($l['required_room_type']) ?></span></td>
                                <td>
                                    <?php if ((int)$l['is_locked'] === 1): ?>
                                        <span class="badge badge-locked">🔒 Đã khóa: <?= h($l['locked_slot_code']) ?> @ <?= h($l['locked_room_code']) ?></span>
                                    <?php else: ?>
                                        <span style="color:#64748b;">Tự động xếp</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($l['notes']) ?></td>
                                <?php if ($isScheduler): ?>
                                <td style="white-space:nowrap;">
                                    <button type="button" class="btn btn-outline btn-sm"
                                        onclick='populateLessonForm(<?= json_encode($l, JSON_UNESCAPED_UNICODE) ?>)'>Sửa</button>
                                    <?php if ((int)$l['is_locked'] === 1): ?>
                                        <form method="post" action="index.php?tab=data&sec=lessons" style="display:inline;">
                                            <input type="hidden" name="action" value="unlock_lesson">
                                            <input type="hidden" name="lesson_id" value="<?= (int)$l['id'] ?>">
                                            <button type="submit" class="btn btn-warning btn-sm">Mở khóa</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="index.php?tab=data&sec=lessons" style="display:inline;" onsubmit="return confirm('Xóa buổi học này?');">
                                        <input type="hidden" name="action" value="delete_lesson">
                                        <input type="hidden" name="lesson_id" value="<?= (int)$l['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Xóa</button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($activeSec === '' || $activeSec === 'faculties'): ?>
            <!-- Quản lý Khoa -->
            <div class="card" id="sec-faculties">
                <div class="card-header">
                    <h2>🏛️ Khoa</h2>
                </div>

                <div style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:14px;">
                    <?php foreach ($dataset['faculties'] ?? [] as $f): ?>
                    <div style="border:1px solid #cbd5e1; background:#f8fafc; border-radius:8px; padding:8px 12px; display:flex; align-items:center; gap:10px;">
                        <div>
                            <span class="badge badge-lt"><?= h($f['code']) ?></span>
                            <strong><?= h($f['name']) ?></strong>
                        </div>
                        <?php if ($isScheduler): ?>
                        <div style="display:flex; gap:4px;">
                            <button type="button" class="btn btn-outline btn-sm" style="padding:2px 6px;"
                                onclick='populateFacultyForm(<?= json_encode($f, JSON_UNESCAPED_UNICODE) ?>)'>Sửa</button>
                            <form method="post" action="index.php?tab=data&sec=faculties" style="display:inline;" onsubmit="return confirm('Xóa Khoa này khỏi danh mục?');">
                                <input type="hidden" name="action" value="delete_faculty">
                                <input type="hidden" name="faculty_id" value="<?= (int)$f['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm" style="padding:2px 6px;">✕</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($isScheduler): ?>
                <form method="post" action="index.php?tab=data&sec=faculties" style="background:#f8fafc; padding:12px; border-radius:6px; border:1px solid #e2e8f0;">
                    <input type="hidden" name="action" value="add_faculty" id="ff_action">
                    <input type="hidden" name="faculty_id" value="" id="ff_id">
                    <div style="font-size:12.5px; font-weight:700; margin-bottom:8px; color:#1e40af;" id="ff_title">
                        ➕ Thêm / Sửa Khoa:
                    </div>
                    <div class="form-row" style="margin-bottom:8px;">
                        <input type="text" name="code" id="ff_code" placeholder="Mã Khoa (VD: QTKD, KT, CNTT...)" required>
                        <input type="text" name="name" id="ff_name" placeholder="Tên Khoa (VD: Khoa Quản trị kinh doanh)" required>
                        <div style="display:flex; gap:6px;">
                            <button type="submit" class="btn btn-primary btn-sm" id="ff_submit">Lưu Khoa</button>
                            <button type="button" class="btn btn-outline btn-sm" onclick="resetFacultyForm()">Làm mới</button>
                        </div>
                    </div>
                    <div style="font-size:12px; color:#475569;">
                        <strong>Chọn nhanh:</strong>
                        <span class="dnu-demo-chip" onclick="quickFillFaculty('CNTT', 'Khoa Công nghệ thông tin')">☑️ Khoa CNTT</span>
                        <span class="dnu-demo-chip" onclick="quickFillFaculty('KT', 'Khoa Kinh tế & Quản trị kinh doanh')">☑️ Khoa Kinh tế</span>
                        <span class="dnu-demo-chip" onclick="quickFillFaculty('TCNH', 'Khoa Tài chính - Ngân hàng')">☑️ Khoa Tài chính - Ngân hàng</span>
                        <span class="dnu-demo-chip" onclick="quickFillFaculty('YD', 'Khoa Y - Dược')">☑️ Khoa Y - Dược</span>
                        <span class="dnu-demo-chip" onclick="quickFillFaculty('NN', 'Khoa Ngoại ngữ')">☑️ Khoa Ngoại ngữ</span>
                        <span class="dnu-demo-chip" onclick="quickFillFaculty('LUAT', 'Khoa Luật')">☑️ Khoa Luật</span>
                        <span class="dnu-demo-chip" onclick="quickFillFaculty('DL', 'Khoa Du lịch')">☑️ Khoa Du lịch</span>
                    </div>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="<?= $activeSec === '' ? 'grid-3' : '' ?>">
                <?php if ($activeSec === '' || $activeSec === 'classes' || $activeSec === 'siso'): ?>
                <!-- Bảng Lớp học & Sĩ số -->
                <div class="card" id="sec-classes">
                    <div class="card-header">
                        <h3><?= $activeSec === 'siso' ? '👥 Sĩ số' : '🏫 Lớp học' ?></h3>
                    </div>
                    <table>
                        <thead>
                            <tr><th>Mã lớp</th><th>Tên lớp, Khoa & Giờ vào học</th><th>Sĩ số</th><?php if ($isScheduler): ?><th>Thao tác</th><?php endif; ?></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dataset['classes'] as $c):
                                $morningTime = ConstraintChecker::formatCohortTime((string)$c['code'], 1);
                                $afternoonTime = ConstraintChecker::formatCohortTime((string)$c['code'], 2);
                            ?>
                            <tr>
                                <td><strong><?= h($c['code']) ?></strong></td>
                                <td>
                                    <?= h($c['name']) ?><br>
                                    <span class="badge badge-lt" style="margin-top:2px;"><?= h($c['major']) ?></span>
                                    <div style="font-size:11px; color:#475569; margin-top:3px;">
                                        ☀️ Sáng: <strong><?= h($morningTime) ?></strong> • 🌤️ Chiều: <strong><?= h($afternoonTime) ?></strong>
                                    </div>
                                </td>
                                <td><strong style="color:#1d4ed8;"><?= (int)$c['student_count'] ?> SV</strong></td>
                                <?php if ($isScheduler): ?>
                                <td style="white-space:nowrap;">
                                    <button type="button" class="btn btn-outline btn-sm"
                                        onclick='populateClassForm(<?= json_encode($c, JSON_UNESCAPED_UNICODE) ?>)'>Sửa</button>
                                    <form method="post" action="index.php?tab=data&sec=<?= h($activeSec ?: 'classes') ?>" style="display:inline;" onsubmit="return confirm('Xóa lớp học này?');">
                                        <input type="hidden" name="action" value="delete_class">
                                        <input type="hidden" name="class_id" value="<?= (int)$c['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm">✕</button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($isScheduler): ?>
                    <form method="post" action="index.php?tab=data&sec=<?= h($activeSec ?: 'classes') ?>" style="margin-top:12px; background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                        <input type="hidden" name="action" value="add_class" id="cf_action">
                        <input type="hidden" name="class_id" value="" id="cf_id">
                        <div style="font-size:12px; font-weight:700; margin-bottom:6px; color:#1e40af;" id="cf_title">➕ Thêm / Sửa Lớp học &amp; Sĩ số:</div>
                        <div class="form-row" style="margin-bottom:8px;">
                            <input type="text" name="code" id="cf_code" placeholder="Mã lớp (VD: 18-09, 19-09...)" required>
                            <input type="text" name="name" id="cf_name" placeholder="Tên lớp (VD: CNTT 18-09)" required>
                            <input type="text" name="major" id="cf_major" list="faculty_datalist" placeholder="Chọn Khoa" value="Khoa Công nghệ thông tin" required>
                            <input type="number" name="student_count" id="cf_count" placeholder="Sĩ số SV (VD: 45)" min="1" max="500" required>
                        </div>
                        <div id="cf_sync_wrap" style="display:none; margin-bottom:8px; font-size:12px;">
                            <label style="cursor:pointer;">
                                <input type="checkbox" name="sync_lessons" value="1" checked>
                                Đồng bộ sĩ số mới sang các buổi học của lớp này
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm" id="cf_submit">Lưu Lớp & Sĩ số</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="resetClassForm()">Làm mới</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($activeSec === '' || $activeSec === 'rooms'): ?>
                <!-- Bảng Phòng học -->
                <div class="card" id="sec-rooms">
                    <div class="card-header">
                        <h3>🏢 Phòng học</h3>
                    </div>
                    <table>
                        <thead>
                            <tr><th>Mã</th><th>Tên phòng</th><th>Loại</th><th>Sức chứa</th><?php if ($isScheduler): ?><th>Thao tác</th><?php endif; ?></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dataset['rooms'] as $r): ?>
                            <tr>
                                <td><strong><?= h($r['code']) ?></strong></td>
                                <td><?= h($r['name']) ?></td>
                                <td><span class="badge badge-<?= strtolower(h($r['room_type'])) ?>"><?= h($r['room_type']) ?></span></td>
                                <td><strong><?= (int)$r['capacity'] ?> chỗ</strong></td>
                                <?php if ($isScheduler): ?>
                                <td style="white-space:nowrap;">
                                    <button type="button" class="btn btn-outline btn-sm"
                                        onclick='populateRoomForm(<?= json_encode($r, JSON_UNESCAPED_UNICODE) ?>)'>Sửa</button>
                                    <form method="post" action="index.php?tab=data&sec=rooms" style="display:inline;" onsubmit="return confirm('Xóa phòng học này?');">
                                        <input type="hidden" name="action" value="delete_room">
                                        <input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm">✕</button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($isScheduler): ?>
                    <form method="post" action="index.php?tab=data&sec=rooms" style="margin-top:12px; background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                        <input type="hidden" name="action" value="add_room" id="rf_action">
                        <input type="hidden" name="room_id" value="" id="rf_id">
                        <div style="font-size:12px; font-weight:700; margin-bottom:6px; color:#1e40af;" id="rf_title">➕ Thêm / Sửa Phòng học:</div>
                        <div class="form-row" style="margin-bottom:8px;">
                            <input type="text" name="code" id="rf_code" placeholder="Mã phòng (P103)" required>
                            <input type="text" name="name" id="rf_name" placeholder="Tên phòng" required>
                            <input type="number" name="capacity" id="rf_capacity" placeholder="Sức chứa" min="1" max="500" required>
                            <select name="room_type" id="rf_type">
                                <option value="LT">LT (Lý thuyết)</option>
                                <option value="TH">TH (Thực hành)</option>
                                <option value="TN">TN (Thí nghiệm)</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm" id="rf_submit">Lưu Phòng học</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="resetRoomForm()">Làm mới</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($activeSec === '' || $activeSec === 'teachers'): ?>
                <!-- Bảng Giảng viên -->
                <div class="card" id="sec-teachers">
                    <div class="card-header">
                        <h3>👨‍🏫 Giảng viên</h3>
                    </div>
                    <table>
                        <thead>
                            <tr><th>Mã</th><th>Họ tên</th><th>Thuộc Khoa</th><th>Max ca/ngày</th><?php if ($isScheduler): ?><th>Thao tác</th><?php endif; ?></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dataset['teachers'] as $t): ?>
                            <tr>
                                <td><strong><?= h($t['code']) ?></strong></td>
                                <td><?= h($t['name']) ?></td>
                                <td><span class="badge badge-th"><?= h($t['department']) ?></span></td>
                                <td><?= (int)$t['max_lessons_per_day'] ?> ca</td>
                                <?php if ($isScheduler): ?>
                                <td style="white-space:nowrap;">
                                    <button type="button" class="btn btn-outline btn-sm"
                                        onclick='populateTeacherForm(<?= json_encode($t, JSON_UNESCAPED_UNICODE) ?>)'>Sửa</button>
                                    <form method="post" action="index.php?tab=data&sec=teachers" style="display:inline;" onsubmit="return confirm('Xóa giảng viên này?');">
                                        <input type="hidden" name="action" value="delete_teacher">
                                        <input type="hidden" name="teacher_id" value="<?= (int)$t['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm">✕</button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($isScheduler): ?>
                    <form method="post" action="index.php?tab=data&sec=teachers" style="margin-top:12px; background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                        <input type="hidden" name="action" value="add_teacher" id="tf_action">
                        <input type="hidden" name="teacher_id" value="" id="tf_id">
                        <div style="font-size:12px; font-weight:700; margin-bottom:6px; color:#1e40af;" id="tf_title">➕ Thêm / Sửa Giảng viên:</div>
                        <div class="form-row" style="margin-bottom:8px;">
                            <input type="text" name="code" id="tf_code" placeholder="Mã GV (VD: GV07)" required>
                            <input type="text" name="name" id="tf_name" placeholder="Họ tên GV" required>
                            <input type="text" name="department" id="tf_dept" list="faculty_datalist" placeholder="Chọn hoặc gõ Khoa" value="Khoa Công nghệ thông tin" required>
                            <input type="number" name="max_lessons_per_day" id="tf_max" value="2" min="1" max="4" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm" id="tf_submit">Lưu Giảng viên</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="resetTeacherForm()">Làm mới</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="<?= $activeSec === '' ? 'grid-2' : '' ?>">
                <?php if ($activeSec === '' || $activeSec === 'unavailability' || $activeSec === 'teachers'): ?>
                <!-- Lịch bận & Mong muốn ưu tiên -->
                <div class="card" id="sec-unavailability">
                    <div class="card-header">
                        <h3>🗓️ Lịch bận</h3>
                    </div>
                    <?php if ($isScheduler || $isTeacher): ?>
                    <form method="post" action="index.php?tab=data&sec=unavailability" style="margin-bottom:14px;">
                        <input type="hidden" name="action" value="save_unavailability">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Giảng viên</label>
                                <select name="teacher_id">
                                    <?php foreach ($dataset['teachers'] as $t):
                                        if ($isTeacher && (int)$t['id'] !== (int)($currentUser['teacher_id'] ?? 0)) {
                                            continue;
                                        }
                                    ?>
                                        <option value="<?= (int)$t['id'] ?>"><?= h($t['code'] . ' - ' . $t['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Khung giờ</label>
                                <select name="slot_id">
                                    <?php foreach ($dataset['slots'] as $s): ?>
                                        <option value="<?= (int)$s['id'] ?>"><?= h($s['code'] . ' - ' . $s['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Phân loại ràng buộc</label>
                                <select name="constraint_type">
                                    <option value="HARD_BUSY">HARD_BUSY - Bận bắt buộc (Cấm xếp)</option>
                                    <option value="SOFT_AVOID">SOFT_AVOID - Không ưu tiên (Phạt mềm +15đ)</option>
                                    <option value="SOFT_PREFER">SOFT_PREFER - Khung giờ mong muốn (-4đ)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Lý do</label>
                                <input type="text" name="reason" placeholder="Họp khoa / Lịch cá nhân..." required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Lưu Lịch bận</button>
                    </form>
                    <?php endif; ?>

                    <div style="max-height: 360px; overflow-y: auto;">
                        <table>
                            <thead>
                                <tr><th>Giảng viên</th><th>Khung giờ</th><th>Loại ràng buộc</th><th>Lý do</th><?php if ($isScheduler): ?><th>Xóa</th><?php endif; ?></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dataset['unavailability'] as $u): ?>
                                <tr>
                                    <td><strong><?= h($u['teacher_code']) ?></strong> - <?= h($u['teacher_name']) ?></td>
                                    <td><?= h($u['slot_code']) ?></td>
                                    <td>
                                        <?php if ($u['constraint_type'] === 'HARD_BUSY'): ?>
                                            <span class="badge badge-hard">Bắt buộc: Bận</span>
                                        <?php elseif ($u['constraint_type'] === 'SOFT_AVOID'): ?>
                                            <span class="badge badge-soft-avoid">Ưu tiên: Tránh (+15đ)</span>
                                        <?php else: ?>
                                            <span class="badge badge-soft-prefer">Ưu tiên: Thích (-4đ)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($u['reason']) ?></td>
                                    <?php if ($isScheduler): ?>
                                    <td>
                                        <form method="post" action="index.php?tab=data&sec=unavailability" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_unavailability">
                                            <input type="hidden" name="unavailability_id" value="<?= (int)$u['id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm">✕</button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($activeSec === '' || $activeSec === 'slots'): ?>
                <!-- Danh sách Khung giờ -->
                <div class="card" id="sec-slots">
                    <div class="card-header">
                        <h3>🕒 Khung giờ</h3>
                    </div>
                    <div style="max-height: 430px; overflow-y: auto;">
                        <table>
                            <thead>
                                <tr><th>ID</th><th>Mã khung giờ</th><th>Tuần</th><th>Thứ</th><th>Ca học</th><th>Giờ học (18-* / 19-*)</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dataset['slots'] as $s):
                                    $pDay = (int)$s['period_of_day'];
                                    $wNo = (int)($s['week_no'] ?? 1);
                                ?>
                                <tr>
                                    <td><?= (int)$s['id'] ?></td>
                                    <td><strong><?= h($s['code']) ?></strong></td>
                                    <td><span class="badge badge-lt">Tuần <?= $wNo ?></span></td>
                                    <td><?= (int)$s['day_of_week'] === 8 ? 'Chủ nhật' : ('Thứ ' . (int)$s['day_of_week']) ?></td>
                                    <td>
                                        <?php if ($pDay === 1): ?>
                                            <span class="badge badge-soft-prefer">☀️ Ca 1 (Buổi Sáng)</span>
                                        <?php else: ?>
                                            <span class="badge badge-th">🌤️ Ca 2 (Buổi Chiều)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($pDay === 1): ?>
                                            <strong>18-*:</strong> 07:20 - 11:20 • <strong>19-*:</strong> 07:10 - 11:10
                                        <?php else: ?>
                                            <strong>18-*:</strong> 13:20 - 17:20 • <strong>19-*:</strong> 13:10 - 17:10
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($isScheduler && ($activeSec === '' || $activeSec === 'students')): ?>
            <!-- Quản lý Mã Sinh viên -->
            <div class="card" id="sec-students">
                <div class="card-header">
                    <h2>🎓 Mã sinh viên</h2>
                </div>

                <form method="post" action="index.php?tab=data&sec=students" style="background:#f8fafc; padding:12px; border-radius:6px; border:1px solid #cbd5e1; margin-bottom:14px;">
                    <input type="hidden" name="action" value="add_user" id="uf_action">
                    <input type="hidden" name="user_id" value="" id="uf_id">
                    <div style="font-size:13px; font-weight:700; margin-bottom:8px; color:#1d4ed8;" id="uf_title">
                        ➕ Thêm / Sửa Mã sinh viên:
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Mã sinh viên / Tài khoản *</label>
                            <input type="text" name="username" id="uf_username" placeholder="VD: 1871020001 hoặc sv07" required>
                        </div>
                        <div class="form-group">
                            <label>Họ và tên *</label>
                            <input type="text" name="full_name" id="uf_fullname" placeholder="VD: Nguyễn Văn An" required>
                        </div>
                        <div class="form-group">
                            <label>Nhóm tài khoản *</label>
                            <select name="role" id="uf_role" onchange="toggleUserRoleFields()">
                                <option value="student">🎓 Sinh viên</option>
                                <option value="teacher">👨‍🏫 Giảng viên</option>
                                <option value="scheduler">⚙️ Quản trị Đào tạo</option>
                            </select>
                        </div>
                        <div class="form-group" id="uf_class_wrap">
                            <label>Lớp học *</label>
                            <select name="class_id" id="uf_class">
                                <?php foreach ($dataset['classes'] as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>">
                                        <?= h($c['code'] . ' — ' . $c['major']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" id="uf_teacher_wrap" style="display:none;">
                            <label>Giảng viên *</label>
                            <select name="teacher_id" id="uf_teacher">
                                <?php foreach ($dataset['teachers'] as $t): ?>
                                    <option value="<?= (int)$t['id'] ?>">
                                        <?= h($t['code'] . ' - ' . $t['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email" id="uf_email" placeholder="sv@uni.edu.vn">
                        </div>
                        <div class="form-group">
                            <label>Mật khẩu (Mặc định: 123456)</label>
                            <input type="text" name="password" id="uf_password" placeholder="Nhập mật khẩu...">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" id="uf_submit">Lưu Mã sinh viên</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="resetUserForm()">Làm mới</button>
                </form>

                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Mã SV / Tài khoản</th>
                                <th>Họ và tên</th>
                                <th>Vai trò</th>
                                <th>Lớp học / Mã GV</th>
                                <th>Khoa</th>
                                <th>Email</th>
                                <th>Thời khóa biểu</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allUsers as $uRow):
                                $uRole = (string)($uRow['role'] ?? 'student');
                            ?>
                            <tr>
                                <td><strong style="color:#1e40af; font-family:monospace; font-size:13.5px;"><?= h($uRow['username']) ?></strong></td>
                                <td><strong><?= h($uRow['full_name']) ?></strong></td>
                                <td>
                                    <?php if ($uRole === 'student'): ?>
                                        <span class="badge badge-lt">🎓 Sinh viên</span>
                                    <?php elseif ($uRole === 'teacher'): ?>
                                        <span class="badge badge-th">👨‍🏫 Giảng viên</span>
                                    <?php else: ?>
                                        <span class="badge badge-locked">⚙️ Admin</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($uRole === 'student' && !empty($uRow['class_code'])): ?>
                                        <strong>Lớp <?= h($uRow['class_code']) ?></strong>
                                    <?php elseif ($uRole === 'teacher' && !empty($uRow['teacher_code'])): ?>
                                        <strong>GV: <?= h($uRow['teacher_code']) ?></strong>
                                    <?php else: ?>
                                        <span style="color:#64748b;">Toàn trường</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($uRole === 'student' && !empty($uRow['class_major'])): ?>
                                        <?= h($uRow['class_major']) ?>
                                    <?php else: ?>
                                        <span style="color:#64748b;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($uRow['email'] ?? '') ?></td>
                                <td>
                                    <?php if ($uRole === 'student' && !empty($uRow['class_id'])): ?>
                                        <a href="index.php?tab=schedule&filter_type=class&filter_id=<?= (int)$uRow['class_id'] ?>" class="btn btn-outline btn-sm">
                                            📅 Xem TKB
                                        </a>
                                    <?php elseif ($uRole === 'teacher' && !empty($uRow['teacher_id'])): ?>
                                        <a href="index.php?tab=schedule&filter_type=teacher&filter_id=<?= (int)$uRow['teacher_id'] ?>" class="btn btn-outline btn-sm">
                                            📅 Xem TKB
                                        </a>
                                    <?php else: ?>
                                        <a href="index.php?tab=schedule" class="btn btn-outline btn-sm">📅 Xem TKB</a>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space:nowrap;">
                                    <button type="button" class="btn btn-outline btn-sm"
                                        onclick='populateUserForm(<?= json_encode($uRow, JSON_UNESCAPED_UNICODE) ?>)'>Sửa</button>
                                    <?php if ((int)$uRow['id'] !== (int)($currentUser['id'] ?? 0)): ?>
                                    <form method="post" action="index.php?tab=data&sec=students" style="display:inline;" onsubmit="return confirm('Xóa Mã sinh viên / Tài khoản này?');">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?= (int)$uRow['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Xóa</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <script>
            function populateLessonForm(l) {
                document.getElementById('lesson_form_action').value = 'update_lesson';
                document.getElementById('lesson_form_id').value = l.id;
                document.getElementById('lf_code').value = l.code;
                document.getElementById('lf_subject').value = l.subject_name;
                document.getElementById('lf_teacher').value = l.teacher_id;
                document.getElementById('lf_class').value = l.class_id;
                document.getElementById('lf_count').value = l.student_count;
                document.getElementById('lf_rtype').value = l.required_room_type;
                document.getElementById('lf_locked').value = l.is_locked;
                document.getElementById('lf_slot').value = l.locked_slot_id || '';
                document.getElementById('lf_room').value = l.locked_room_id || '';
                document.getElementById('lf_notes').value = l.notes || '';
                document.getElementById('lf_submit_btn').textContent = 'Cập nhật buổi học [' + l.code + ']';
                document.querySelector('details').open = true;
                window.scrollTo({top: 150, behavior: 'smooth'});
            }
            function resetLessonForm() {
                document.getElementById('lesson_form_action').value = 'add_lesson';
                document.getElementById('lesson_form_id').value = '';
                document.getElementById('lf_code').value = '';
                document.getElementById('lf_subject').value = '';
                document.getElementById('lf_notes').value = '';
                document.getElementById('lf_locked').value = '0';
                document.getElementById('lf_slot').value = '';
                document.getElementById('lf_room').value = '';
                document.getElementById('lf_submit_btn').textContent = 'Lưu buổi học';
                syncClassStudentCount();
            }
            function syncClassStudentCount() {
                const sel = document.getElementById('lf_class');
                if (!sel) return;
                const opt = sel.options[sel.selectedIndex];
                if (opt && opt.getAttribute('data-students')) {
                    document.getElementById('lf_count').value = opt.getAttribute('data-students');
                }
            }
            function populateFacultyForm(f) {
                document.getElementById('ff_action').value = 'update_faculty';
                document.getElementById('ff_id').value = f.id;
                document.getElementById('ff_code').value = f.code;
                document.getElementById('ff_name').value = f.name;
                document.getElementById('ff_title').textContent = '✏️ Chỉnh sửa Khoa [' + f.code + ' - ' + f.name + ']:';
                document.getElementById('ff_submit').textContent = 'Cập nhật Khoa [' + f.code + ']';
            }
            function resetFacultyForm() {
                document.getElementById('ff_action').value = 'add_faculty';
                document.getElementById('ff_id').value = '';
                document.getElementById('ff_code').value = '';
                document.getElementById('ff_name').value = '';
                document.getElementById('ff_title').textContent = '➕ Tự thêm Khoa đào tạo mới hoặc bấm đánh dấu nhanh:';
                document.getElementById('ff_submit').textContent = 'Lưu Khoa Đào tạo';
            }
            function quickFillFaculty(code, name) {
                document.getElementById('ff_action').value = 'add_faculty';
                document.getElementById('ff_id').value = '';
                document.getElementById('ff_code').value = code;
                document.getElementById('ff_name').value = name;
            }
            function populateClassForm(c) {
                document.getElementById('cf_action').value = 'update_class';
                document.getElementById('cf_id').value = c.id;
                document.getElementById('cf_code').value = c.code;
                document.getElementById('cf_name').value = c.name;
                document.getElementById('cf_major').value = c.major || 'Khoa Công nghệ thông tin';
                document.getElementById('cf_count').value = c.student_count;
                document.getElementById('cf_sync_wrap').style.display = 'block';
                document.getElementById('cf_title').textContent = '✏️ Sửa Khoa & Sĩ số lớp [' + c.code + ']:';
                document.getElementById('cf_submit').textContent = 'Cập nhật [' + c.code + ']';
            }
            function resetClassForm() {
                document.getElementById('cf_action').value = 'add_class';
                document.getElementById('cf_id').value = '';
                document.getElementById('cf_code').value = '';
                document.getElementById('cf_name').value = '';
                document.getElementById('cf_major').value = 'Khoa Công nghệ thông tin';
                document.getElementById('cf_count').value = '';
                document.getElementById('cf_sync_wrap').style.display = 'none';
                document.getElementById('cf_title').textContent = '➕ Thêm Lớp học mới (Tự nhận giờ học theo số đầu 18-09, 19-09...):';
                document.getElementById('cf_submit').textContent = 'Lưu Lớp & Sĩ số';
            }
            function populateRoomForm(r) {
                document.getElementById('rf_action').value = 'update_room';
                document.getElementById('rf_id').value = r.id;
                document.getElementById('rf_code').value = r.code;
                document.getElementById('rf_name').value = r.name;
                document.getElementById('rf_capacity').value = r.capacity;
                document.getElementById('rf_type').value = r.room_type;
                document.getElementById('rf_title').textContent = '✏️ Sửa sức chứa & loại phòng [' + r.code + ']:';
                document.getElementById('rf_submit').textContent = 'Cập nhật Phòng [' + r.code + ']';
            }
            function resetRoomForm() {
                document.getElementById('rf_action').value = 'add_room';
                document.getElementById('rf_id').value = '';
                document.getElementById('rf_code').value = '';
                document.getElementById('rf_name').value = '';
                document.getElementById('rf_capacity').value = '';
                document.getElementById('rf_type').value = 'LT';
                document.getElementById('rf_title').textContent = '➕ Thêm / Sửa Phòng học:';
                document.getElementById('rf_submit').textContent = 'Lưu Phòng học';
            }
            function populateTeacherForm(t) {
                document.getElementById('tf_action').value = 'update_teacher';
                document.getElementById('tf_id').value = t.id;
                document.getElementById('tf_code').value = t.code;
                document.getElementById('tf_name').value = t.name;
                document.getElementById('tf_dept').value = t.department;
                document.getElementById('tf_max').value = t.max_lessons_per_day;
                document.getElementById('tf_title').textContent = '✏️ Sửa thông tin Giảng viên [' + t.code + ']:';
                document.getElementById('tf_submit').textContent = 'Cập nhật GV [' + t.code + ']';
            }
            function resetTeacherForm() {
                document.getElementById('tf_action').value = 'add_teacher';
                document.getElementById('tf_id').value = '';
                document.getElementById('tf_code').value = '';
                document.getElementById('tf_name').value = '';
                document.getElementById('tf_dept').value = 'Công nghệ thông tin';
                document.getElementById('tf_max').value = '2';
                document.getElementById('tf_title').textContent = '➕ Thêm / Sửa Giảng viên:';
                document.getElementById('tf_submit').textContent = 'Lưu Giảng viên';
            }
            function toggleUserRoleFields() {
                const roleEl = document.getElementById('uf_role');
                if (!roleEl) return;
                const role = roleEl.value;
                const classWrap = document.getElementById('uf_class_wrap');
                const teacherWrap = document.getElementById('uf_teacher_wrap');
                if (classWrap) classWrap.style.display = (role === 'student') ? '' : 'none';
                if (teacherWrap) teacherWrap.style.display = (role === 'teacher') ? '' : 'none';
            }
            function populateUserForm(u) {
                document.getElementById('uf_action').value = 'update_user';
                document.getElementById('uf_id').value = u.id;
                document.getElementById('uf_username').value = u.username;
                document.getElementById('uf_fullname').value = u.full_name;
                document.getElementById('uf_role').value = u.role || 'student';
                if (u.class_id) document.getElementById('uf_class').value = u.class_id;
                if (u.teacher_id) document.getElementById('uf_teacher').value = u.teacher_id;
                document.getElementById('uf_email').value = u.email || '';
                document.getElementById('uf_password').value = '';
                toggleUserRoleFields();
                document.getElementById('uf_title').textContent = '✏️ Chỉnh sửa Mã SV / Tài khoản [' + u.username + ' - ' + u.full_name + ']:';
                document.getElementById('uf_submit').textContent = 'Cập nhật [' + u.username + ']';
                const sec = document.getElementById('sec-students');
                if (sec) sec.scrollIntoView({behavior: 'smooth'});
            }
            function resetUserForm() {
                document.getElementById('uf_action').value = 'add_user';
                document.getElementById('uf_id').value = '';
                document.getElementById('uf_username').value = '';
                document.getElementById('uf_fullname').value = '';
                document.getElementById('uf_role').value = 'student';
                document.getElementById('uf_email').value = '';
                document.getElementById('uf_password').value = '';
                toggleUserRoleFields();
                document.getElementById('uf_title').textContent = '➕ Cấp mới Mã Sinh viên / Tài khoản (Mật khẩu mặc định nếu để trống: 123456):';
                document.getElementById('uf_submit').textContent = 'Lưu Mã Sinh viên / Tài khoản';
            }
            </script>

        <?php elseif ($activeTab === 'validate'): ?>
            <!-- ==================== TAB 2: KIỂM TRA DỮ LIỆU & PHÂN LOẠI RÀNG BUỘC ==================== -->
            <div class="grid-2">
                <div class="card">
                    <div class="card-header">
                        <h2>1. Nhóm Điều kiện Bắt buộc (Hard Constraints — Cấm vi phạm)</h2>
                        <span class="badge <?= $validation['is_ready_to_schedule'] ? 'badge-soft-prefer' : 'badge-hard' ?>">
                            <?= $validation['is_ready_to_schedule'] ? 'HỢP LỆ ĐỂ XẾP LỊCH' : 'CÓ LỖI BẮT BUỘC' ?>
                        </span>
                    </div>
                    <table>
                        <thead>
                            <tr><th>Mã quy tắc</th><th>Nội dung điều kiện bắt buộc</th><th>Cơ chế xử lý</th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>HC-01</strong></td>
                                <td>Không trùng <strong>Giảng viên</strong> trong cùng một khung giờ</td>
                                <td>Loại bỏ ngay khi chọn khung giờ</td>
                            </tr>
                            <tr>
                                <td><strong>HC-02</strong></td>
                                <td>Không trùng <strong>Lớp học</strong> trong cùng một khung giờ</td>
                                <td>Loại bỏ ngay khi chọn khung giờ</td>
                            </tr>
                            <tr>
                                <td><strong>HC-03</strong></td>
                                <td>Không dùng trùng <strong>Phòng học</strong> trong cùng một khung giờ</td>
                                <td>Mỗi phòng chỉ chứa tối đa 1 buổi/ca</td>
                            </tr>
                            <tr>
                                <td><strong>HC-04</strong></td>
                                <td><strong>Sức chứa phòng học</strong> &ge; Sĩ số sinh viên của buổi học</td>
                                <td>Loại bỏ mọi phòng nhỏ hơn sĩ số</td>
                            </tr>
                            <tr>
                                <td><strong>HC-05</strong></td>
                                <td><strong>Loại phòng học</strong> đúng yêu cầu buổi học (<code>LT</code>, <code>TH</code>, <code>TN</code>)</td>
                                <td>Loại bỏ phòng khác loại</td>
                            </tr>
                            <tr>
                                <td><strong>HC-06</strong></td>
                                <td>Giảng viên không nằm trong <strong>Lịch bận bắt buộc (<code>HARD_BUSY</code>)</strong></td>
                                <td>Cấm xếp vào khung giờ bận</td>
                            </tr>
                            <tr>
                                <td><strong>HC-07</strong></td>
                                <td>Giữ nguyên 100% các <strong>Buổi học đã khóa (<code>is_locked = 1</code>)</strong></td>
                                <td>Ghim cố định trước khi xếp</td>
                            </tr>
                        </tbody>
                    </table>

                    <h3 style="margin-top:16px; font-size:14px;">Kết quả rà soát dữ liệu bắt buộc hiện tại:</h3>
                    <?php if (empty($validation['hard_errors'])): ?>
                        <div class="alert alert-success" style="margin-top:8px;">
                            ✅ Toàn bộ <?= count($dataset['lessons']) ?> buổi học (gồm <?= (int)$validation['stats']['locked_lessons'] ?> buổi đã khóa) đều hợp lệ về dữ liệu gốc và không có xung đột khóa cứng.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-error" style="margin-top:8px;">
                            <ul>
                                <?php foreach ($validation['hard_errors'] as $err): ?>
                                    <li><?= h($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <h3 style="margin-top:16px; font-size:14px;">Phân tích nút cổ chai & miền giá trị khả thi (Domain Analysis):</h3>
                    <?php if (empty($validation['bottleneck_warnings'])): ?>
                        <p style="color:#15803d;">Không có cảnh báo nghẽn cổ chai.</p>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <ul style="margin:0; padding-left:18px;">
                                <?php foreach ($validation['bottleneck_warnings'] as $w): ?>
                                    <li><?= h($w) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2>2. Nhóm Mong muốn Ưu tiên (Soft Constraints — Tối ưu hóa)</h2>
                        <span class="badge badge-lt">Tính điểm phạt ưu tiên</span>
                    </div>
                    <p style="color:#475569; font-size:13px; margin-top:0;">
                        Khác với điều kiện bắt buộc, các mong muốn ưu tiên vẫn cho phép xếp lịch khi hết lựa chọn tốt hơn, nhưng sẽ cộng <strong>điểm phạt mềm (Soft Penalty)</strong>. Thuật toán cải thiện (IMPROVED) tối ưu hóa để giảm thiểu tổng điểm phạt này:
                    </p>
                    <table>
                        <thead>
                            <tr><th>Loại ưu tiên</th><th>Trọng số</th><th>Đối tượng</th><th>Khung giờ</th><th>Chi tiết</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($validation['soft_constraints_summary'] as $sc): ?>
                            <tr>
                                <td><strong><?= h($sc['type']) ?></strong></td>
                                <td><?= h($sc['weight']) ?></td>
                                <td><?= h($sc['target']) ?></td>
                                <td><?= h($sc['slot']) ?></td>
                                <td><?= h($sc['detail']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <h3 style="margin-top:16px; font-size:14px;">Miền lựa chọn ban đầu (Số cặp Khung giờ × Phòng hợp lệ) của 20 buổi học:</h3>
                    <div style="max-height:260px; overflow-y:auto;">
                        <table>
                            <thead>
                                <tr><th>Mã buổi</th><th>Học phần</th><th>Trạng thái</th><th>Số cặp (Khung giờ, Phòng) khả thi</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dataset['lessons'] as $l):
                                    $cnt = (int)($validation['lesson_domain_counts'][(int)$l['id']] ?? 0);
                                ?>
                                <tr>
                                    <td><strong><?= h($l['code']) ?></strong></td>
                                    <td><?= h($l['subject_name']) ?></td>
                                    <td><?= (int)$l['is_locked'] === 1 ? '<span class="badge badge-locked">🔒 Đã khóa</span>' : 'Tự động' ?></td>
                                    <td>
                                        <strong style="color: <?= $cnt === 0 ? '#b91c1c' : ($cnt <= 3 ? '#b45309' : '#15803d') ?>;">
                                            <?= $cnt ?> phương án khả thi
                                        </strong>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif ($activeTab === 'schedule'): ?>
            <!-- ==================== GIAO DIỆN THỜI KHÓA BIỂU THEO VAI TRÒ ==================== -->
            <?php
            $selectedClassCode = (string)($currentUser['class_code'] ?? '');
            if ($viewFilterType === 'class' && $viewFilterId > 0) {
                foreach ($dataset['classes'] as $c) {
                    if ((int)$c['id'] === $viewFilterId) {
                        $selectedClassCode = (string)$c['code'];
                        break;
                    }
                }
            }
            $selectedWeekNo = isset($_GET['week_no']) ? (int)$_GET['week_no'] : 1;
            if (!in_array($selectedWeekNo, [0, 1, 2], true)) {
                $selectedWeekNo = 1;
            }
            $scheduleSubView = (string)($_GET['subview'] ?? 'week'); // week | list

            $grid = [];
            $filteredList = [];
            foreach ($activeAssignments as $a) {
                if ($viewFilterType === 'class' && $viewFilterId > 0 && (int)$a['class_id'] !== $viewFilterId) {
                    continue;
                }
                if ($viewFilterType === 'teacher' && $viewFilterId > 0 && (int)$a['teacher_id'] !== $viewFilterId) {
                    continue;
                }
                if ($viewFilterType === 'room' && $viewFilterId > 0 && (int)$a['room_id'] !== $viewFilterId) {
                    continue;
                }
                $w = (int)($a['week_no'] ?? 1);
                $p = (int)$a['period_of_day'];
                $d = (int)$a['day_of_week'];
                $grid[$w][$p][$d][] = $a;
                $filteredList[] = $a;
            }
            $weekRangeLabels = [
                1 => '19/10/2026 - 25/10/2026',
                2 => '26/10/2026 - 01/11/2026',
            ];
            $weekSelectLabels = [
                1 => 'Tuần 1 (19/10 - 25/10/2026)',
                2 => 'Tuần 2 (26/10 - 01/11/2026)',
            ];
            $dayNames = [
                2 => 'Thứ Hai',
                3 => 'Thứ Ba',
                4 => 'Thứ Tư',
                5 => 'Thứ Năm',
                6 => 'Thứ Sáu',
                7 => 'Thứ Bảy',
                8 => 'Chủ Nhật',
            ];
            $slotLookup = [];
            foreach ($dataset['slots'] as $sItem) {
                $slotLookup[(int)($sItem['week_no'] ?? 1)][(int)$sItem['period_of_day']][(int)$sItem['day_of_week']] = $sItem;
            }
            ?>

            <?php if ($isStudent): ?>
                <!-- ==================== 1. GIAO DIỆN SINH VIÊN MỚI (BENTO + KANBAN 5 CỘT NGÀY + THẺ HỌC PHẦN) ==================== -->
                <?php
                $ltCount = 0;
                $thCount = 0;
                $roomSet = [];
                foreach ($filteredList as $fItem) {
                    if (($fItem['room_type'] ?? 'LT') === 'TH') {
                        $thCount++;
                    } else {
                        $ltCount++;
                    }
                    $roomSet[$fItem['room_code']] = $fItem['room_code'];
                }
                $firstCourse = $filteredList[0] ?? null;
                $studentClassObj = null;
                foreach ($dataset['classes'] as $cObj) {
                    if ((int)$cObj['id'] === $viewFilterId) {
                        $studentClassObj = $cObj;
                        break;
                    }
                }
                ?>
                <!-- 3 Thẻ Bento Tổng quan Sinh viên -->
                <div class="bento-student-grid">
                    <div class="bento-card bento-accent-indigo">
                        <div class="bento-eyebrow">🏫 LỚP HÀNH CHÍNH ĐANG THEO HỌC</div>
                        <div class="bento-main-val"><?= h($selectedClassCode ?: 'CNTT 18-12') ?></div>
                        <div class="bento-sub-text">
                            <?= h($studentClassObj['major'] ?? 'Khoa Công nghệ thông tin') ?> •
                            Sĩ số: <strong><?= (int)($studentClassObj['student_count'] ?? 35) ?> sinh viên</strong>
                        </div>
                        <div class="bento-chips">
                            <span class="bento-chip">☀️ Sáng: <?= h($studentCohortTimeS) ?></span>
                            <span class="bento-chip">🌤️ Chiều: <?= h($studentCohortTimeC) ?></span>
                        </div>
                    </div>

                    <div class="bento-card bento-accent-emerald">
                        <div class="bento-eyebrow">📚 TỔNG QUAN LỊCH HỌC TRONG KỲ</div>
                        <div class="bento-main-val"><?= count($filteredList) ?> <span style="font-size:15px; font-weight:600; color:#475569;">buổi học</span></div>
                        <div class="bento-sub-text">
                            Gồm <strong><?= $ltCount ?> buổi Lý thuyết</strong> &amp; <strong><?= $thCount ?> buổi Thực hành</strong>
                        </div>
                        <div class="bento-chips">
                            <span class="bento-chip">📍 Phòng học: <?= !empty($roomSet) ? h(implode(', ', $roomSet)) : 'Đang cập nhật' ?></span>
                        </div>
                    </div>

                    <div class="bento-card bento-accent-violet">
                        <div class="bento-eyebrow">⚡ TIÊU ĐIỂM HỌC PHẦN ĐẦU TUẦN</div>
                        <?php if ($firstCourse): ?>
                            <div class="bento-main-val" style="font-size:17px; line-height:1.3;">
                                <?= h($firstCourse['subject_name']) ?>
                            </div>
                            <div class="bento-sub-text">
                                GV: <strong><?= h($firstCourse['teacher_name']) ?></strong>
                            </div>
                            <div class="bento-chips">
                                <span class="bento-chip">📍 Phòng <?= h($firstCourse['room_code']) ?></span>
                                <span class="bento-chip">🗓️ <?= (int)$firstCourse['day_of_week'] === 8 ? 'Chủ nhật' : ('Thứ ' . (int)$firstCourse['day_of_week']) ?> (<?= (int)$firstCourse['period_of_day'] === 1 ? 'Sáng' : 'Chiều' ?>)</span>
                            </div>
                        <?php else: ?>
                            <div class="bento-main-val" style="font-size:16px;">Chưa có buổi học</div>
                            <div class="bento-sub-text">Hiện chưa có lịch phân công cho lớp.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Thanh công cụ chọn Tuần & Tìm kiếm nhanh của Sinh viên -->
                <div class="portal-control-bar">
                    <div class="week-pill-group">
                        <a href="index.php?tab=schedule&subview=<?= h($scheduleSubView) ?>&week_no=1"
                           class="week-pill <?= $selectedWeekNo === 1 ? 'active' : '' ?>">
                            🗓️ Tuần hiện tại — Tuần 1 (19/10 – 25/10)
                        </a>
                        <a href="index.php?tab=schedule&subview=<?= h($scheduleSubView) ?>&week_no=2"
                           class="week-pill <?= $selectedWeekNo === 2 ? 'active' : '' ?>">
                            🗓️ Tuần kế tiếp — Tuần 2 (26/10 – 01/11)
                        </a>
                        <a href="index.php?tab=schedule&subview=<?= h($scheduleSubView) ?>&week_no=0"
                           class="week-pill <?= $selectedWeekNo === 0 ? 'active' : '' ?>">
                            📑 Xem cả 2 tuần
                        </a>
                    </div>

                    <div class="portal-control-right">
                        <input type="search" id="student_quick_search" class="portal-search-input"
                               placeholder="🔍 Tìm môn học, phòng, giảng viên..." oninput="filterKanbanCards(this.value)">
                        <div class="dnu-subtabs">
                            <a href="index.php?tab=schedule&subview=week&week_no=<?= (int)$selectedWeekNo ?>"
                               class="dnu-subtab <?= $scheduleSubView !== 'list' ? 'active' : '' ?>">
                                🗓️ Thời khóa biểu tuần
                            </a>
                            <a href="index.php?tab=schedule&subview=list&week_no=<?= (int)$selectedWeekNo ?>"
                               class="dnu-subtab <?= $scheduleSubView === 'list' ? 'active' : '' ?>">
                                📋 Danh sách học phần
                            </a>
                        </div>
                    </div>
                </div>

                <?php if ($scheduleSubView !== 'list'): ?>
                    <!-- Bảng Kanban 5 cột ngày (Thứ 2 -> Thứ 6) dành cho Sinh viên -->
                    <?php
                    $weeksToRender = $selectedWeekNo === 0 ? [1, 2] : [$selectedWeekNo];
                    foreach ($weeksToRender as $idxW => $w):
                    ?>
                        <div class="kanban-week-section" style="margin-top: <?= $idxW > 0 ? '24px' : '0' ?>;">
                            <div class="kanban-week-banner">
                                <span>🗓️ <strong><?= h($weekSelectLabels[$w]) ?></strong></span>
                                <span class="kanban-week-tag">Lớp <?= h($selectedClassCode) ?></span>
                            </div>

                            <div class="kanban-board-5col">
                                <?php foreach ($dayNames as $dNum => $dLabel): ?>
                                    <div class="kanban-day-col">
                                        <div class="kanban-day-header">
                                            <span class="day-title"><?= h($dLabel) ?></span>
                                            <span class="day-badge"><?= $dNum === 8 ? 'CN' : ('T' . $dNum) ?></span>
                                        </div>

                                        <div class="kanban-day-body">
                                            <?php foreach ([1 => '☀️ CA SÁNG (Tiết 1–5)', 2 => '🌤️ CA CHIỀU (Tiết 6–10)'] as $pNum => $pTitle):
                                                $slotItems = $grid[$w][$pNum][$dNum] ?? [];
                                            ?>
                                                <div class="kanban-session-slot">
                                                    <div class="kanban-session-title"><?= $pTitle ?></div>
                                                    <?php if (empty($slotItems)): ?>
                                                        <div class="kanban-empty-slot">Trống lịch — Tự học</div>
                                                    <?php else: ?>
                                                        <?php foreach ($slotItems as $item):
                                                            $exactTime = ConstraintChecker::formatCohortTime((string)$item['class_code'], (int)$item['period_of_day']);
                                                            $rType = strtolower((string)($item['room_type'] ?? 'lt'));
                                                        ?>
                                                            <div class="kanban-course-card type-<?= h($rType) ?> searchable-card"
                                                                 onclick="openLessonModal(<?= (int)$item['lesson_id'] ?>)"
                                                                 title="Bấm để xem chi tiết học phần & danh sách sinh viên lớp">
                                                                <div class="kcard-top">
                                                                    <span class="kcard-type-badge badge-<?= h($rType) ?>">
                                                                        <?= $rType === 'th' ? '💻 Thực hành' : '📖 Lý thuyết' ?>
                                                                    </span>
                                                                    <span class="kcard-room">📍 P.<?= h($item['room_code']) ?></span>
                                                                </div>
                                                                <div class="kcard-subject"><?= h($item['subject_name']) ?></div>
                                                                <div class="kcard-time">🕒 <?= h($exactTime) ?></div>
                                                                <div class="kcard-teacher">👨‍🏫 <?= h($item['teacher_name']) ?></div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- Chế độ Timeline Danh sách cho Sinh viên -->
                    <div class="timeline-list-wrap">
                        <?php
                        $timelineRows = array_values(array_filter(
                            $filteredList,
                            static fn(array $r): bool => $selectedWeekNo === 0 || (int)($r['week_no'] ?? 1) === $selectedWeekNo
                        ));
                        ?>
                        <?php if (empty($timelineRows)): ?>
                            <div class="card" style="text-align:center; color:#64748b; padding:24px;">
                                Không có buổi học nào trong tuần đã chọn.
                            </div>
                        <?php else: ?>
                            <?php foreach ($timelineRows as $idx => $row):
                                $wRow = (int)($row['week_no'] ?? 1);
                                $pRow = (int)$row['period_of_day'];
                                $exactTime = ConstraintChecker::formatCohortTime((string)$row['class_code'], $pRow);
                                $rType = strtolower((string)($row['room_type'] ?? 'lt'));
                            ?>
                                <div class="timeline-item-card searchable-card">
                                    <div class="timeline-left-badge">
                                        <div class="tl-day"><?= (int)$row['day_of_week'] === 8 ? 'Chủ nhật' : ('Thứ ' . (int)$row['day_of_week']) ?></div>
                                        <div class="tl-session"><?= $pRow === 1 ? '☀️ Sáng' : '🌤️ Chiều' ?></div>
                                        <div class="tl-week">Tuần <?= $wRow ?></div>
                                    </div>
                                    <div class="timeline-main-info">
                                        <div class="tl-subject">
                                            <?= h($row['subject_name']) ?>
                                            <span class="badge badge-<?= h($rType) ?>"><?= h($row['room_type']) ?></span>
                                        </div>
                                        <div class="tl-meta">
                                            <span>🕒 <strong><?= h($exactTime) ?></strong></span>
                                            <span>📍 Phòng <strong><?= h($row['room_code']) ?></strong> (<?= h($row['room_name'] ?? '') ?>)</span>
                                            <span>👨‍🏫 GV: <strong><?= h($row['teacher_name']) ?></strong></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Danh mục Học phần & Giảng viên giảng dạy Lớp -->
                <div class="card" style="margin-top:24px;">
                    <div class="card-header">
                        <h2>📖 Danh mục Học phần &amp; Giảng viên giảng dạy lớp <?= h($selectedClassCode) ?></h2>
                    </div>
                    <div class="course-directory-grid">
                        <?php foreach ($filteredList as $cItem):
                            $cTime = ConstraintChecker::formatCohortTime((string)$cItem['class_code'], (int)$cItem['period_of_day']);
                        ?>
                            <div class="course-dir-card searchable-card">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                    <span class="badge badge-<?= strtolower(h($cItem['room_type'])) ?>"><?= h($cItem['lesson_code']) ?> • <?= h($cItem['room_type']) ?></span>
                                    <span style="font-size:12px; font-weight:700; color:#4f46e5;">P.<?= h($cItem['room_code']) ?></span>
                                </div>
                                <div style="font-weight:700; font-size:14px; color:#0f172a; margin-bottom:6px;">
                                    <?= h($cItem['subject_name']) ?>
                                </div>
                                <div style="font-size:12.5px; color:#475569;">
                                    <div>👨‍🏫 Giảng viên: <strong><?= h($cItem['teacher_name']) ?></strong></div>
                                    <div style="margin-top:3px;">🗓️ Tuần <?= (int)($cItem['week_no'] ?? 1) ?> • <?= (int)$cItem['day_of_week'] === 8 ? 'Chủ nhật' : ('Thứ ' . (int)$cItem['day_of_week']) ?> (<?= h($cTime) ?>)</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            <?php elseif ($isTeacher): ?>
                <!-- ==================== 2. GIAO DIỆN GIẢNG VIÊN MỚI (KPI BENTO + KANBAN LỊCH DẠY & TRẠNG THÁI CÁ NHÂN) ==================== -->
                <?php
                $myTid = (int)($currentUser['teacher_id'] ?? 0);
                $myUnavailBySlot = [];
                $busyCount = 0;
                $preferCount = 0;
                $avoidCount = 0;
                foreach ($dataset['unavailability'] as $uItem) {
                    if ((int)$uItem['teacher_id'] === $myTid) {
                        $myUnavailBySlot[(int)$uItem['slot_id']] = $uItem;
                        if ($uItem['constraint_type'] === 'HARD_BUSY') {
                            $busyCount++;
                        } elseif ($uItem['constraint_type'] === 'SOFT_PREFER') {
                            $preferCount++;
                        } else {
                            $avoidCount++;
                        }
                    }
                }
                $slotLookup = [];
                foreach ($dataset['slots'] as $sItem) {
                    $slotLookup[(int)($sItem['week_no'] ?? 1)][(int)$sItem['period_of_day']][(int)$sItem['day_of_week']] = $sItem;
                }
                $morningTeach = 0;
                $afternoonTeach = 0;
                $mySoftPenalty = 0;
                $taughtClasses = [];
                foreach ($filteredList as $fItem) {
                    if ((int)$fItem['period_of_day'] === 1) {
                        $morningTeach++;
                    } else {
                        $afternoonTeach++;
                    }
                    $mySoftPenalty += (int)($fItem['soft_penalty'] ?? 0);
                    $taughtClasses[$fItem['class_code']] = $fItem['class_code'];
                }
                ?>
                <!-- 4 Thẻ KPI Giảng dạy của Giảng viên -->
                <div class="stats-grid">
                    <div class="stat-card" style="border-top: 3px solid #0d9488;">
                        <div class="stat-label">📚 Khối lượng giảng dạy</div>
                        <div class="stat-value"><?= count($filteredList) ?> ca</div>
                        <div class="stat-sub">☀️ <?= $morningTeach ?> ca sáng • 🌤️ <?= $afternoonTeach ?> ca chiều</div>
                    </div>
                    <div class="stat-card" style="border-top: 3px solid #2563eb;">
                        <div class="stat-label">🏫 Lớp phụ trách</div>
                        <div class="stat-value"><?= count($taughtClasses) ?> lớp</div>
                        <div class="stat-sub"><?= !empty($taughtClasses) ? h(implode(', ', $taughtClasses)) : 'Chưa phân công' ?></div>
                    </div>
                    <div class="stat-card" style="border-top: 3px solid #e11d48;">
                        <div class="stat-label">🔴 Lịch bận cứng đã khóa</div>
                        <div class="stat-value"><?= $busyCount ?> ca</div>
                        <div class="stat-sub">Được bảo vệ 100% không xếp trùng</div>
                    </div>
                    <div class="stat-card" style="border-top: 3px solid #10b981;">
                        <div class="stat-label">✨ Đáp ứng nguyện vọng</div>
                        <div class="stat-value" style="color: <?= $mySoftPenalty === 0 ? '#059669' : '#d97706' ?>;">
                            <?= $mySoftPenalty === 0 ? 'Tối ưu (0đ phạt)' : ($mySoftPenalty . 'đ phạt') ?>
                        </div>
                        <div class="stat-sub">🟢 <?= $preferCount ?> ca ưu tiên • 🟡 <?= $avoidCount ?> ca hạn chế</div>
                    </div>
                </div>

                <!-- Thanh điều khiển Lịch giảng dạy của Giảng viên -->
                <div class="portal-control-bar">
                    <div class="week-pill-group">
                        <a href="index.php?tab=schedule&version_id=<?= (int)($activeVersion['id'] ?? 0) ?>&week_no=1"
                           class="week-pill <?= $selectedWeekNo === 1 ? 'active' : '' ?>">
                            🗓️ Tuần hiện tại — Tuần 1 (19/10 – 25/10)
                        </a>
                        <a href="index.php?tab=schedule&version_id=<?= (int)($activeVersion['id'] ?? 0) ?>&week_no=2"
                           class="week-pill <?= $selectedWeekNo === 2 ? 'active' : '' ?>">
                            🗓️ Tuần kế tiếp — Tuần 2 (26/10 – 01/11)
                        </a>
                        <a href="index.php?tab=schedule&version_id=<?= (int)($activeVersion['id'] ?? 0) ?>&week_no=0"
                           class="week-pill <?= $selectedWeekNo === 0 ? 'active' : '' ?>">
                            📑 Xem cả 2 tuần
                        </a>
                    </div>

                    <div class="portal-control-right">
                        <form method="get" action="index.php" style="display:flex; gap:8px; align-items:center; margin:0;">
                            <input type="hidden" name="tab" value="schedule">
                            <input type="hidden" name="week_no" value="<?= (int)$selectedWeekNo ?>">
                            <select name="version_id" onchange="this.form.submit()" class="toolbar-select">
                                <?php foreach ($versions as $v): ?>
                                    <option value="<?= (int)$v['id'] ?>" <?= ($activeVersion && (int)$activeVersion['id'] === (int)$v['id']) ? 'selected' : '' ?>>
                                        #<?= (int)$v['id'] ?>: <?= h($v['version_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <?php if ($activeVersion): ?>
                            <a class="btn btn-outline btn-sm" target="_blank"
                               href="index.php?action=export&format=print&version_id=<?= (int)$activeVersion['id'] ?>&filter_type=teacher&filter_id=<?= $myTid ?>">
                               🖨️ Bản in
                            </a>
                            <a class="btn btn-outline btn-sm"
                               href="index.php?action=export&format=csv&version_id=<?= (int)$activeVersion['id'] ?>&filter_type=teacher&filter_id=<?= $myTid ?>">
                               📥 Xuất CSV
                            </a>
                        <?php endif; ?>
                        <a href="index.php?tab=data" class="btn btn-primary btn-sm">🗓️ Cập nhật lịch bận</a>
                    </div>
                </div>

                <!-- Bảng Kanban Lịch Giảng Dạy 5 Ngày kết hợp Trạng thái Lịch bận của Giảng viên -->
                <?php
                $weeksToRender = $selectedWeekNo === 0 ? [1, 2] : [$selectedWeekNo];
                foreach ($weeksToRender as $idxW => $w):
                ?>
                    <div class="kanban-week-section" style="margin-top: <?= $idxW > 0 ? '24px' : '0' ?>;">
                        <div class="kanban-week-banner teacher-banner">
                            <span>🗓️ <strong><?= h($weekSelectLabels[$w]) ?></strong> — Lịch giảng dạy &amp; Trạng thái cá nhân</span>
                            <span class="kanban-week-tag"><?= h($currentUser['full_name'] ?? '') ?></span>
                        </div>

                        <div class="kanban-board-5col">
                            <?php foreach ($dayNames as $dNum => $dLabel): ?>
                                <div class="kanban-day-col">
                                    <div class="kanban-day-header">
                                        <span class="day-title"><?= h($dLabel) ?></span>
                                        <span class="day-badge"><?= $dNum === 8 ? 'CN' : ('T' . $dNum) ?></span>
                                    </div>

                                    <div class="kanban-day-body">
                                        <?php foreach ([1 => '☀️ CA SÁNG (Tiết 1–5)', 2 => '🌤️ CA CHIỀU (Tiết 6–10)'] as $pNum => $pTitle):
                                            $slotItems = $grid[$w][$pNum][$dNum] ?? [];
                                            $sObj = $slotLookup[$w][$pNum][$dNum] ?? null;
                                            $uObj = $sObj ? ($myUnavailBySlot[(int)$sObj['id']] ?? null) : null;
                                        ?>
                                            <div class="kanban-session-slot">
                                                <div class="kanban-session-title"><?= $pTitle ?></div>
                                                <?php if (!empty($slotItems)): ?>
                                                    <?php foreach ($slotItems as $item):
                                                        $exactTime = ConstraintChecker::formatCohortTime((string)$item['class_code'], (int)$item['period_of_day']);
                                                        $rType = strtolower((string)($item['room_type'] ?? 'lt'));
                                                        $isLocked = (int)$item['is_locked'] === 1 || (int)$item['current_lesson_locked'] === 1;
                                                    ?>
                                                        <div class="kanban-course-card type-<?= h($rType) ?> <?= $isLocked ? 'is-locked-card' : '' ?>"
                                                             onclick="openLessonModal(<?= (int)$item['lesson_id'] ?>)"
                                                             title="Bấm để xem chi tiết hoặc gửi đề xuất xin đổi ca dạy">
                                                            <div class="kcard-top">
                                                                <span class="kcard-type-badge badge-<?= h($rType) ?>">
                                                                    Lớp <?= h($item['class_code']) ?>
                                                                </span>
                                                                <span class="kcard-room">📍 P.<?= h($item['room_code']) ?></span>
                                                            </div>
                                                            <div class="kcard-subject"><?= h($item['subject_name']) ?></div>
                                                            <div class="kcard-time">🕒 <?= h($exactTime) ?></div>
                                                            <div class="kcard-teacher">
                                                                👥 Sĩ số: <strong><?= (int)$item['student_count'] ?>/<?= (int)$item['room_capacity'] ?> chỗ</strong>
                                                                <?= $isLocked ? ' • 🔒 Cố định' : '' ?>
                                                            </div>
                                                            <div style="margin-top:6px; font-size:11px; font-weight:700; color:#0f766e;">
                                                                📝 Bấm để xin đổi lịch / dạy bù
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php elseif ($uObj): ?>
                                                    <?php if ($uObj['constraint_type'] === 'HARD_BUSY'): ?>
                                                        <div class="kanban-status-box status-busy">
                                                            <strong>🔴 Đã đăng ký Bận cứng</strong>
                                                            <div><?= h($uObj['reason']) ?></div>
                                                        </div>
                                                    <?php elseif ($uObj['constraint_type'] === 'SOFT_PREFER'): ?>
                                                        <div class="kanban-status-box status-prefer">
                                                            <strong>🟢 Ca mong muốn dạy</strong>
                                                            <div><?= h($uObj['reason']) ?></div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="kanban-status-box status-avoid">
                                                            <strong>🟡 Hạn chế xếp lịch</strong>
                                                            <div><?= h($uObj['reason']) ?></div>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <div class="kanban-empty-slot">⚪ Ca trống (Không có lịch dạy)</div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Khu vực Theo dõi Đề xuất Xin đổi lịch / Dạy bù của Giảng viên -->
                <?php
                $myRequests = array_values(array_filter(
                    $dataset['reschedule_requests'] ?? [],
                    static fn(array $r): bool => (int)($r['teacher_id'] ?? 0) === $myTid
                ));
                $myLessons = array_values(array_filter(
                    $dataset['lessons'] ?? [],
                    static fn(array $l): bool => (int)($l['teacher_id'] ?? 0) === $myTid
                ));
                ?>
                <div class="grid-2" style="margin-top:22px;">
                    <div class="card">
                        <div class="card-header">
                            <h3>📝 Gửi Đề xuất Xin đổi lịch / Dạy bù tới Phòng Đào tạo</h3>
                            <span class="badge badge-th">Workflow 2 chiều</span>
                        </div>
                        <form method="post" action="index.php?tab=schedule">
                            <input type="hidden" name="action" value="submit_reschedule_request">
                            <input type="hidden" name="version_id" value="<?= (int)($activeVersion['id'] ?? 2) ?>">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>1. Chọn học phần của tôi cần xin đổi ca</label>
                                    <select name="lesson_id" required>
                                        <?php foreach ($myLessons as $ml): ?>
                                            <option value="<?= (int)$ml['id'] ?>">
                                                <?= h($ml['code'] . ' — ' . $ml['subject_name'] . ' (' . $ml['class_code'] . ', ' . $ml['student_count'] . ' SV)') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>2. Khung giờ mong muốn chuyển sang</label>
                                    <select name="target_slot_id" required>
                                        <?php foreach ($dataset['slots'] as $s): ?>
                                            <option value="<?= (int)$s['id'] ?>"><?= h($s['code'] . ' — ' . $s['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>3. Phòng học đề xuất</label>
                                    <select name="target_room_id" required>
                                        <?php foreach ($dataset['rooms'] as $r): ?>
                                            <option value="<?= (int)$r['id'] ?>"><?= h($r['code'] . ' — ' . $r['name'] . ' (' . $r['room_type'] . ', ' . $r['capacity'] . ' chỗ)') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>4. Lý do xin đổi lịch / dạy bù</label>
                                    <input type="text" name="reason" placeholder="VD: Trùng lịch công tác đột xuất, xin dạy bù vào Thứ 7..." required>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">📨 Gửi yêu cầu phê duyệt</button>
                        </form>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3>📬 Trạng thái các yêu cầu đổi lịch đã gửi (<?= count($myRequests) ?>)</h3>
                        </div>
                        <div style="max-height:260px; overflow-y:auto;">
                            <table>
                                <thead>
                                    <tr><th>Buổi học</th><th>Ca &amp; Phòng xin chuyển</th><th>Lý do</th><th>Trạng thái</th></tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($myRequests)): ?>
                                        <tr><td colspan="4" style="text-align:center; color:#64748b;">Chưa có yêu cầu đổi lịch nào.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($myRequests as $mr): ?>
                                            <tr>
                                                <td><strong><?= h($mr['lesson_code']) ?></strong><br><small><?= h($mr['subject_name']) ?></small></td>
                                                <td><strong><?= h($mr['target_slot_code']) ?></strong><br>P.<?= h($mr['target_room_code']) ?></td>
                                                <td><?= h($mr['reason']) ?><?php if (!empty($mr['admin_note'])): ?><br><small style="color:#4f46e5;">💬 ĐT: <?= h($mr['admin_note']) ?></small><?php endif; ?></td>
                                                <td>
                                                    <?php if ($mr['status'] === 'PENDING'): ?>
                                                        <span class="badge badge-soft-avoid">⏳ Chờ duyệt</span>
                                                    <?php elseif ($mr['status'] === 'APPROVED'): ?>
                                                        <span class="badge badge-soft-prefer">✅ Đã duyệt</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-hard">❌ Từ chối</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- ==================== 3. GIAO DIỆN XEM THỜI KHÓA BIỂU CỦA ADMIN (THỜI KHÓA BIỂU Ở TRÊN — CÁC CHỨC NĂNG KHÁC Ở DƯỚI) ==================== -->
                <!-- PHẦN TRÊN: THỜI KHÓA BIỂU TOÀN MÀN HÌNH TƯƠNG TÁC TRỰC TIẾP (DRAG & DROP + LIVE CONSTRAINT HIGHLIGHT) -->
                <section class="admin-main-stage">
                    <div class="portal-control-bar">
                        <div class="week-pill-group">
                            <a href="index.php?tab=schedule&subview=<?= h($scheduleSubView) ?>&version_id=<?= (int)($activeVersion['id'] ?? 0) ?>&filter_type=<?= h($viewFilterType) ?>&filter_id=<?= (int)$viewFilterId ?>&week_no=1"
                               class="week-pill <?= $selectedWeekNo === 1 ? 'active' : '' ?>">
                                🗓️ Tuần 1 (19/10 – 25/10)
                            </a>
                            <a href="index.php?tab=schedule&subview=<?= h($scheduleSubView) ?>&version_id=<?= (int)($activeVersion['id'] ?? 0) ?>&filter_type=<?= h($viewFilterType) ?>&filter_id=<?= (int)$viewFilterId ?>&week_no=2"
                               class="week-pill <?= $selectedWeekNo === 2 ? 'active' : '' ?>">
                                🗓️ Tuần 2 (26/10 – 01/11)
                            </a>
                            <a href="index.php?tab=schedule&subview=<?= h($scheduleSubView) ?>&version_id=<?= (int)($activeVersion['id'] ?? 0) ?>&filter_type=<?= h($viewFilterType) ?>&filter_id=<?= (int)$viewFilterId ?>&week_no=0"
                               class="week-pill <?= $selectedWeekNo === 0 ? 'active' : '' ?>">
                                📑 Cả 2 tuần
                            </a>
                        </div>

                        <div class="portal-control-right">
                            <input type="search" class="portal-search-input"
                                   placeholder="🔍 Lọc nhanh môn, lớp, GV, phòng..." oninput="filterKanbanCards(this.value)">
                            <div class="dnu-subtabs">
                                <a href="index.php?tab=schedule&subview=week&version_id=<?= (int)($activeVersion['id'] ?? 0) ?>&filter_type=<?= h($viewFilterType) ?>&filter_id=<?= (int)$viewFilterId ?>&week_no=<?= (int)$selectedWeekNo ?>"
                                   class="dnu-subtab <?= $scheduleSubView !== 'list' ? 'active' : '' ?>">
                                    🗓️ Lưới TKB 7 Ngày (Thứ 2 – CN)
                                </a>
                                <a href="index.php?tab=schedule&subview=list&version_id=<?= (int)($activeVersion['id'] ?? 0) ?>&filter_type=<?= h($viewFilterType) ?>&filter_id=<?= (int)$viewFilterId ?>&week_no=<?= (int)$selectedWeekNo ?>"
                                   class="dnu-subtab <?= $scheduleSubView === 'list' ? 'active' : '' ?>">
                                    📋 Danh sách chi tiết (<?= count($filteredList) ?>)
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Thanh chỉ dẫn tương tác Kéo-Thả & Kiểm tra Ràng buộc trực tiếp trên lưới -->
                    <div class="interactive-guide-bar" id="dnd_status_banner">
                        <div>
                            <strong>Hướng dẫn Điều phối Trực tiếp trên Lưới TKB:</strong>
                            Giữ chuột <strong>Kéo - Thả</strong> học phần sang ô Thứ/Ca khác để hệ thống kiểm tra tự động 7 ràng buộc cứng và đánh dấu
                            <span class="guide-pill-valid">🟢 Hợp lệ (Gợi ý phòng trống)</span> hoặc
                            <span class="guide-pill-invalid">🔴 Trùng lịch / Vi phạm cứng</span>.
                            Nhấn vào ô học phần bất kỳ để xem chi tiết hoặc đổi phòng nhanh.
                        </div>
                    </div>

                    <!-- Form ẩn phục vụ Kéo - Thả (Drag & Drop) tự động cập nhật lịch -->
                    <form method="post" action="index.php?tab=schedule&version_id=<?= (int)($activeVersion['id'] ?? 0) ?>&week_no=<?= (int)$selectedWeekNo ?>" id="drag_drop_move_form" style="display:none;">
                        <input type="hidden" name="action" value="apply_manual_move">
                        <input type="hidden" name="version_id" value="<?= (int)($activeVersion['id'] ?? 0) ?>">
                        <input type="hidden" name="lesson_id" id="dnd_lesson_id" value="">
                        <input type="hidden" name="target_slot_id" id="dnd_slot_id" value="">
                        <input type="hidden" name="target_room_id" id="dnd_room_id" value="">
                    </form>

                    <?php if ($scheduleSubView !== 'list'): ?>
                        <?php
                        $weeksToRender = $selectedWeekNo === 0 ? [1, 2] : [$selectedWeekNo];
                        foreach ($weeksToRender as $idxW => $w):
                        ?>
                            <div class="kanban-week-section" style="margin-top: <?= $idxW > 0 ? '24px' : '0' ?>;">
                                <div class="kanban-week-banner">
                                    <span>🗓️ <strong><?= h($weekSelectLabels[$w]) ?></strong> — Phương án: <?= h($activeVersion['version_name'] ?? '') ?></span>
                                    <span class="kanban-week-tag"><?= count($filteredList) ?> buổi hiển thị</span>
                                </div>

                                <div class="kanban-board-5col">
                                    <?php foreach ($dayNames as $dNum => $dLabel): ?>
                                        <div class="kanban-day-col">
                                            <div class="kanban-day-header">
                                                <span class="day-title"><?= h($dLabel) ?></span>
                                                <span class="day-badge"><?= $dNum === 8 ? 'CN' : ('Thứ ' . $dNum) ?></span>
                                            </div>

                                            <div class="kanban-day-body">
                                                <?php foreach ([1 => '☀️ CA SÁNG (Tiết 1–5)', 2 => '🌤️ CA CHIỀU (Tiết 6–10)'] as $pNum => $pTitle):
                                                    $slotItems = $grid[$w][$pNum][$dNum] ?? [];
                                                    $slotCellObj = $slotLookup[$w][$pNum][$dNum] ?? null;
                                                    $slotCellId = $slotCellObj ? (int)$slotCellObj['id'] : 0;
                                                ?>
                                                    <div class="kanban-session-slot"
                                                         data-slot-id="<?= $slotCellId ?>"
                                                         ondragover="handleSlotDragOver(event, <?= $slotCellId ?>)"
                                                         ondragleave="handleSlotDragLeave(event)"
                                                         ondrop="handleSlotDrop(event, <?= $slotCellId ?>)">
                                                        <div class="kanban-session-title">
                                                            <span><?= $pTitle ?></span>
                                                            <?php if ($slotCellObj): ?>
                                                                <span style="float:right; font-weight:600; color:#94a3b8;"><?= h($slotCellObj['code']) ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="slot-drop-indicator" id="slot_drop_ind_<?= $slotCellId ?>"></div>
                                                        <?php if (empty($slotItems)): ?>
                                                            <div class="kanban-empty-slot">Kéo thả môn học vào ca này</div>
                                                        <?php else: ?>
                                                            <?php foreach ($slotItems as $item):
                                                                $isLocked = (int)$item['is_locked'] === 1 || (int)$item['current_lesson_locked'] === 1;
                                                                $exactCohortTime = ConstraintChecker::formatCohortTime((string)$item['class_code'], (int)$item['period_of_day']);
                                                                $rTypeClass = strtolower((string)($item['room_type'] ?? 'lt'));
                                                            ?>
                                                                <div class="kanban-course-card type-<?= h($rTypeClass) ?> <?= $isLocked ? 'is-locked-card' : 'is-draggable-card' ?> searchable-card"
                                                                     draggable="<?= $isLocked ? 'false' : 'true' ?>"
                                                                     ondragstart="handleLessonDragStart(event, <?= (int)$item['lesson_id'] ?>)"
                                                                     ondragend="handleLessonDragEnd(event)"
                                                                     onclick="openLessonModal(<?= (int)$item['lesson_id'] ?>)"
                                                                     title="<?= $isLocked ? 'Buổi học đã khóa cố định (Bấm để xem/mở khóa)' : 'Giữ chuột kéo sang ô khác để đổi lịch, hoặc bấm để điều chỉnh phòng nhanh' ?>">
                                                                    <div class="kcard-top">
                                                                        <span class="kcard-type-badge badge-<?= h($rTypeClass) ?>">
                                                                            <?= h($item['lesson_code']) ?> • <?= h($item['class_code']) ?>
                                                                        </span>
                                                                        <span class="kcard-room">📍 P.<?= h($item['room_code']) ?></span>
                                                                    </div>
                                                                    <div class="kcard-subject"><?= h($item['subject_name']) ?></div>
                                                                    <div class="kcard-time">🕒 <?= h($exactCohortTime) ?></div>
                                                                    <div class="kcard-teacher">
                                                                        👨‍🏫 <?= h($item['teacher_name']) ?><br>
                                                                        👥 Sĩ số: <strong><?= (int)$item['student_count'] ?>/<?= (int)$item['room_capacity'] ?> chỗ</strong>
                                                                        <?php if ($isLocked): ?>
                                                                            <span class="locked-mini-tag">🔒 Đã khóa</span>
                                                                        <?php else: ?>
                                                                            <span class="drag-mini-tag">✥ Kéo thả / Chi tiết</span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <?php if (!$isLocked): ?>
                                                                        <div class="event-actions" onclick="event.stopPropagation();">
                                                                            <form method="post" action="index.php?tab=schedule&version_id=<?= (int)$activeVersion['id'] ?>&week_no=<?= (int)$selectedWeekNo ?>" style="display:inline;">
                                                                                <input type="hidden" name="action" value="lock_from_version">
                                                                                <input type="hidden" name="lesson_id" value="<?= (int)$item['lesson_id'] ?>">
                                                                                <input type="hidden" name="slot_id" value="<?= (int)$item['slot_id'] ?>">
                                                                                <input type="hidden" name="room_id" value="<?= (int)$item['room_id'] ?>">
                                                                                <button type="submit" class="btn btn-outline btn-sm event-lock-btn">🔒 Ghim cố định</button>
                                                                            </form>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="card">
                            <div class="tt-table-scroll">
                                <table class="dnu-tt-table list-mode-table">
                                    <thead>
                                        <tr>
                                            <th>MÃ BUỔI</th>
                                            <th>MÔN HỌC / HỌC PHẦN</th>
                                            <th>LỚP HỌC</th>
                                            <th>THỨ &amp; CA</th>
                                            <th>GIỜ HỌC</th>
                                            <th>PHÒNG HỌC</th>
                                            <th>GIẢNG VIÊN</th>
                                            <th>TRẠNG THÁI</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($filteredList)): ?>
                                            <tr><td colspan="8" style="padding:24px; color:#64748b; text-align:center;">Chưa có buổi học nào trong phạm vi lọc này.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($filteredList as $row):
                                                $wRow = (int)($row['week_no'] ?? 1);
                                                $pRow = (int)$row['period_of_day'];
                                                $exactTime = ConstraintChecker::formatCohortTime((string)$row['class_code'], $pRow);
                                                $buoiStr = ($pRow === 1) ? '☀️ Sáng' : '🌤️ Chiều';
                                                $isLocked = (int)$row['is_locked'] === 1 || (int)$row['current_lesson_locked'] === 1;
                                            ?>
                                            <tr class="searchable-card" style="cursor:pointer;" onclick="openLessonModal(<?= (int)$row['lesson_id'] ?>)">
                                                <td><strong><?= h($row['lesson_code']) ?></strong></td>
                                                <td style="text-align:left;"><strong><?= h($row['subject_name']) ?></strong></td>
                                                <td><span class="badge badge-lt"><?= h($row['class_code']) ?> (<?= (int)$row['student_count'] ?> SV)</span></td>
                                                <td>Tuần <?= $wRow ?> • <?= (int)$row['day_of_week'] === 8 ? 'Chủ nhật' : ('Thứ ' . (int)$row['day_of_week']) ?> (<?= $buoiStr ?>)</td>
                                                <td><strong><?= h($exactTime) ?></strong></td>
                                                <td><strong>P.<?= h($row['room_code']) ?></strong> (<?= (int)$row['room_capacity'] ?> chỗ)</td>
                                                <td><?= h($row['teacher_name']) ?></td>
                                                <td><?= $isLocked ? '<span class="badge badge-locked">🔒 Đã khóa</span>' : '<span class="badge badge-soft-prefer">Tự động</span>' ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- PHẦN DƯỚI: THỐNG KÊ KPI PHƯƠNG ÁN & CÁC CHỨC NĂNG ĐIỀU PHỐI (CHẠY THUẬT TOÁN, BỘ LỌC, RADAR BUỔI CHƯA XẾP) -->
                <div class="admin-kpi-ribbon" style="margin-top: 24px;">
                    <div class="kpi-tile kpi-tile-primary">
                        <div class="kpi-tile-label">PHƯƠNG ÁN ĐANG XÉT</div>
                        <div class="kpi-tile-title"><?= h($activeVersion['version_name'] ?? 'Chưa có phương án') ?></div>
                        <div class="kpi-tile-foot">
                            <span class="badge badge-lt"><?= h($activeVersion['algorithm_type'] ?? 'IMPROVED') ?></span>
                            <span>Tổng <?= count($versions) ?> phương án đã lưu</span>
                        </div>
                    </div>

                    <div class="kpi-tile">
                        <div class="kpi-tile-label">TIẾN ĐỘ XẾP LỊCH</div>
                        <div class="kpi-tile-big">
                            <?= (int)$activeAudit['scheduled_count'] ?><span>/<?= (int)$activeAudit['total_lessons'] ?> buổi (<?= $schedPct ?>%)</span>
                        </div>
                        <div class="kpi-progress-bar">
                            <div class="kpi-progress-fill" style="width: <?= $schedPct ?>%;"></div>
                        </div>
                    </div>

                    <div class="kpi-tile">
                        <div class="kpi-tile-label">TRẠNG THÁI RÀNG BUỘC CỨNG</div>
                        <div class="kpi-tile-big" style="color: <?= $activeAudit['hard_violations'] === 0 ? '#059669' : '#dc2626' ?>;">
                            <?= (int)$activeAudit['hard_violations'] ?> vi phạm
                        </div>
                        <div class="kpi-tile-foot">
                            🔒 Đã ghim cố định <?= (int)($validation['stats']['locked_lessons'] ?? 3) ?> buổi khóa
                        </div>
                    </div>

                    <div class="kpi-tile">
                        <div class="kpi-tile-label">ĐIỂM PHẠT ƯU TIÊN (SOFT SCORE)</div>
                        <div class="kpi-tile-big" style="color:#4f46e5;">
                            <?= (int)$activeAudit['soft_penalty_score'] ?> <span>điểm phạt</span>
                        </div>
                        <div class="kpi-tile-foot">
                            <?= (int)$activeAudit['unscheduled_count'] > 0
                                ? ('⚠️ Còn ' . (int)$activeAudit['unscheduled_count'] . ' buổi chưa xếp')
                                : '✅ Đã xếp kín 100% số buổi' ?>
                        </div>
                    </div>
                </div>

                <div class="admin-bottom-tools-grid">
                    <!-- Khối 1: Chạy Thuật toán -->
                    <div class="studio-side-card">
                        <div class="studio-side-header">
                            <span>🚀 Chạy Thuật toán Xếp lịch</span>
                            <span class="badge badge-lt">Chức năng 3</span>
                        </div>
                        <form method="post" action="index.php?tab=schedule">
                            <input type="hidden" name="action" value="run_schedule">
                            <div class="form-group" style="margin-bottom:10px;">
                                <label>Phương pháp xếp lịch</label>
                                <select name="algorithm_type">
                                    <option value="BOTH">⚡ Chạy đồng thời cả 2 cách xếp</option>
                                    <option value="IMPROVED">2. Cách xếp Cải thiện (MRV + Best-Fit)</option>
                                    <option value="BASIC">1. Cách xếp Cơ bản (First-Fit)</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom:12px;">
                                <label>Đặt tên phương án (Tùy chọn)</label>
                                <input type="text" name="version_name" placeholder="Tự động đặt tên nếu để trống...">
                            </div>
                            <button type="submit" class="btn btn-primary" style="width:100%;">🚀 Thực thi xếp thời khóa biểu</button>
                        </form>
                    </div>

                    <!-- Khối 2: Chọn Phương án & Bộ lọc đa chiều -->
                    <div class="studio-side-card">
                        <div class="studio-side-header">
                            <span>🎛️ Bộ lọc &amp; Phương án</span>
                        </div>
                        <form method="get" action="index.php">
                            <input type="hidden" name="tab" value="schedule">
                            <input type="hidden" name="subview" value="<?= h($scheduleSubView) ?>">

                            <div class="form-group" style="margin-bottom:10px;">
                                <label>Phương án đang xem</label>
                                <select name="version_id" onchange="this.form.submit()">
                                    <?php foreach ($versions as $v): ?>
                                        <option value="<?= (int)$v['id'] ?>" <?= ($activeVersion && (int)$activeVersion['id'] === (int)$v['id']) ? 'selected' : '' ?>>
                                            #<?= (int)$v['id'] ?>: <?= h($v['version_name']) ?> (<?= (int)$v['scheduled_count'] ?>/<?= (int)$v['total_lessons'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group" style="margin-bottom:10px;">
                                <label>Phạm vi lọc đối tượng</label>
                                <select name="filter_type" onchange="this.form.submit()">
                                    <option value="all" <?= $viewFilterType === 'all' ? 'selected' : '' ?>>🌐 Toàn trường (Tất cả các lớp)</option>
                                    <option value="class" <?= $viewFilterType === 'class' ? 'selected' : '' ?>>🏫 Lọc theo Lớp học</option>
                                    <option value="teacher" <?= $viewFilterType === 'teacher' ? 'selected' : '' ?>>👨‍🏫 Lọc theo Giảng viên</option>
                                    <option value="room" <?= $viewFilterType === 'room' ? 'selected' : '' ?>>🏢 Lọc theo Phòng học</option>
                                </select>
                            </div>

                            <?php if ($viewFilterType === 'class'): ?>
                                <div class="form-group" style="margin-bottom:10px;">
                                    <label>Chọn Lớp học</label>
                                    <select name="filter_id" onchange="this.form.submit()">
                                        <option value="0">-- Tất cả lớp --</option>
                                        <?php foreach ($dataset['classes'] as $c): ?>
                                            <option value="<?= (int)$c['id'] ?>" <?= $viewFilterId === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['code'] . ' (' . $c['student_count'] . ' SV)') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php elseif ($viewFilterType === 'teacher'): ?>
                                <div class="form-group" style="margin-bottom:10px;">
                                    <label>Chọn Giảng viên</label>
                                    <select name="filter_id" onchange="this.form.submit()">
                                        <option value="0">-- Tất cả giảng viên --</option>
                                        <?php foreach ($dataset['teachers'] as $t): ?>
                                            <option value="<?= (int)$t['id'] ?>" <?= $viewFilterId === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['code'] . ' - ' . $t['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php elseif ($viewFilterType === 'room'): ?>
                                <div class="form-group" style="margin-bottom:10px;">
                                    <label>Chọn Phòng học</label>
                                    <select name="filter_id" onchange="this.form.submit()">
                                        <option value="0">-- Tất cả phòng học --</option>
                                        <?php foreach ($dataset['rooms'] as $r): ?>
                                            <option value="<?= (int)$r['id'] ?>" <?= $viewFilterId === (int)$r['id'] ? 'selected' : '' ?>><?= h($r['code'] . ' (' . $r['room_type'] . ', ' . $r['capacity'] . ' chỗ)') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <?php if ($activeVersion): ?>
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:12px;">
                                    <a class="btn btn-outline btn-sm" target="_blank"
                                       href="index.php?action=export&format=print&version_id=<?= (int)$activeVersion['id'] ?>&filter_type=<?= h($viewFilterType) ?>&filter_id=<?= (int)$viewFilterId ?>">
                                       🖨️ Bản in PDF
                                    </a>
                                    <a class="btn btn-outline btn-sm"
                                       href="index.php?action=export&format=csv&version_id=<?= (int)$activeVersion['id'] ?>&filter_type=<?= h($viewFilterType) ?>&filter_id=<?= (int)$viewFilterId ?>">
                                       📥 Xuất CSV
                                    </a>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- Khối 3: Danh sách Học phần chưa xếp được lịch (Có thể Kéo - Thả trực tiếp lên bảng TKB) -->
                    <div class="studio-side-card">
                        <div class="studio-side-header">
                            <span>⚠️ Học phần chưa xếp được lịch (Kéo thả lên TKB)</span>
                            <span class="badge <?= $activeAudit['unscheduled_count'] > 0 ? 'badge-soft-avoid' : 'badge-soft-prefer' ?>">
                                <?= (int)$activeAudit['unscheduled_count'] ?> buổi
                            </span>
                        </div>
                        <?php if (empty($activeAudit['unscheduled_lessons'])): ?>
                            <div class="alert alert-success" style="margin:0; font-size:12.5px;">
                                🎉 Đã xếp kín 100% (<?= $activeAudit['total_lessons'] ?>/<?= $activeAudit['total_lessons'] ?> buổi). Không có buổi học nào bị kẹt!
                            </div>
                        <?php else: ?>
                            <div class="radar-unscheduled-list">
                                <?php foreach ($activeAudit['unscheduled_lessons'] as $uLesson): ?>
                                    <div class="radar-item is-draggable-card"
                                         draggable="true"
                                         ondragstart="handleLessonDragStart(event, <?= (int)$uLesson['lesson_id'] ?>)"
                                         ondragend="handleLessonDragEnd(event)"
                                         onclick="openLessonModal(<?= (int)$uLesson['lesson_id'] ?>)"
                                         title="Giữ chuột kéo buổi học này lên bảng TKB phía trên để kiểm tra ô hợp lệ">
                                        <div class="radar-item-top">
                                            <strong><?= h($uLesson['code']) ?> — <?= h($uLesson['subject_name']) ?></strong>
                                            <span class="badge badge-<?= strtolower(h($uLesson['required_room_type'])) ?>">
                                                <?= (int)$uLesson['student_count'] ?> SV • <?= h($uLesson['required_room_type']) ?>
                                            </span>
                                        </div>
                                        <div class="radar-item-reason"><?= h($uLesson['reason']) ?></div>
                                        <div style="display:flex; gap:6px; margin-top:6px;" onclick="event.stopPropagation();">
                                            <button type="button" class="btn btn-outline btn-sm" style="flex:1;"
                                                    onclick="openLessonModal(<?= (int)$uLesson['lesson_id'] ?>)">
                                                🔍 Kiểm tra &amp; Xếp nhanh
                                            </button>
                                            <a href="index.php?tab=adjust&version_id=<?= (int)$activeVersion['id'] ?>&pick_lesson_id=<?= (int)$uLesson['lesson_id'] ?>"
                                               class="btn btn-primary btn-sm" style="flex:1;">
                                                🔧 Trạm Đổi lịch
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ==================== POPUP MODAL ĐIỀU KHIỂN CHI TIẾT BUỔI HỌC (DÙNG CHUNG CHO ADMIN / GV / SV) ==================== -->
            <div class="tt-modal-backdrop" id="lesson_detail_modal" style="display:none;" onclick="if(event.target===this) closeLessonModal()">
                <div class="tt-modal-dialog">
                    <div class="tt-modal-header">
                        <div>
                            <div class="tt-modal-eyebrow" id="md_lesson_code">BUỔI HỌC</div>
                            <h3 class="tt-modal-title" id="md_subject_name">Chi tiết buổi học</h3>
                        </div>
                        <button type="button" class="tt-modal-close" onclick="closeLessonModal()">✕</button>
                    </div>
                    <div class="tt-modal-body">
                        <div class="tt-modal-meta-grid">
                            <div class="tt-meta-box">
                                <span class="tt-meta-label">🏫 Lớp học &amp; Sĩ số</span>
                                <strong id="md_class_info">—</strong>
                            </div>
                            <div class="tt-meta-box">
                                <span class="tt-meta-label">👨‍🏫 Giảng viên phụ trách</span>
                                <strong id="md_teacher_info">—</strong>
                            </div>
                            <div class="tt-meta-box">
                                <span class="tt-meta-label">🕒 Khung giờ hiện tại</span>
                                <strong id="md_slot_info">—</strong>
                            </div>
                            <div class="tt-meta-box">
                                <span class="tt-meta-label">📍 Phòng học &amp; Loại phòng</span>
                                <strong id="md_room_info">—</strong>
                            </div>
                        </div>

                        <div id="md_soft_note_box" class="alert alert-info" style="font-size:12.5px; margin:12px 0;"></div>

                        <!-- Danh sách sinh viên thuộc lớp -->
                        <div style="margin:12px 0;">
                            <div style="font-size:12px; font-weight:700; color:#334155; margin-bottom:6px;">
                                🎓 Tài khoản Sinh viên thuộc lớp (<span id="md_student_list_count">0</span> tài khoản):
                            </div>
                            <div id="md_student_chips" style="display:flex; flex-wrap:wrap; gap:6px;"></div>
                        </div>

                        <?php if ($isScheduler && $activeVersion): ?>
                            <!-- Bảng điều khiển nhanh cho Admin ngay trong Modal -->
                            <div class="tt-modal-action-panel">
                                <div style="font-weight:800; font-size:13.5px; color:#1e1b4b; margin-bottom:10px;">
                                    ⚡ Điều phối Nhanh: Đổi Phòng học / Khung giờ (Kiểm tra ràng buộc trực tiếp)
                                </div>
                                <form method="post" action="index.php?tab=schedule&version_id=<?= (int)$activeVersion['id'] ?>&week_no=<?= (int)$selectedWeekNo ?>">
                                    <input type="hidden" name="action" value="apply_manual_move">
                                    <input type="hidden" name="version_id" value="<?= (int)$activeVersion['id'] ?>">
                                    <input type="hidden" name="lesson_id" id="md_admin_lesson_id" value="">
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Khung giờ đích</label>
                                            <select name="target_slot_id" id="md_admin_slot_select" onchange="refreshModalRoomOptions()"></select>
                                        </div>
                                        <div class="form-group">
                                            <label>Phòng học khả dụng (Đã lọc đúng loại &amp; sức chứa)</label>
                                            <select name="target_room_id" id="md_admin_room_select"></select>
                                        </div>
                                    </div>
                                    <div id="md_live_check_msg" style="font-size:12px; font-weight:700; margin-bottom:10px;"></div>
                                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                        <button type="submit" class="btn btn-primary btn-sm" id="md_admin_save_btn">💾 Áp dụng thay đổi ngay</button>
                                        <a id="md_admin_adjust_link" href="#" class="btn btn-outline btn-sm">🔧 Mở trong Trạm Đổi lịch</a>
                                    </div>
                                </form>
                            </div>
                        <?php elseif ($isTeacher): ?>
                            <!-- Form gửi yêu cầu xin đổi lịch ngay trong Modal cho Giảng viên -->
                            <div class="tt-modal-action-panel">
                                <div style="font-weight:800; font-size:13.5px; color:#0f766e; margin-bottom:10px;">
                                    📝 Gửi đề xuất Xin đổi lịch / Dạy bù buổi học này
                                </div>
                                <form method="post" action="index.php?tab=schedule">
                                    <input type="hidden" name="action" value="submit_reschedule_request">
                                    <input type="hidden" name="version_id" value="<?= (int)($activeVersion['id'] ?? 2) ?>">
                                    <input type="hidden" name="lesson_id" id="md_teacher_lesson_id" value="">
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Khung giờ mong muốn chuyển sang</label>
                                            <select name="target_slot_id" id="md_teacher_slot_select" onchange="refreshTeacherModalRooms()"></select>
                                        </div>
                                        <div class="form-group">
                                            <label>Phòng học đề xuất</label>
                                            <select name="target_room_id" id="md_teacher_room_select"></select>
                                        </div>
                                    </div>
                                    <div class="form-group" style="margin-bottom:10px;">
                                        <label>Lý do xin đổi ca / dạy bù</label>
                                        <input type="text" name="reason" placeholder="VD: Bận công tác đột xuất, xin chuyển ca dạy..." required>
                                    </div>
                                    <button type="submit" class="btn btn-primary btn-sm">📨 Gửi đề xuất tới Phòng Đào tạo</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php
            // Chuẩn bị dữ liệu JSON cho Engine Kéo - Thả (Drag & Drop) & Kiểm tra Ràng buộc Thời gian thực trên Trình duyệt
            $jsLessons = [];
            foreach ($dataset['lessons'] as $l) {
                $jsLessons[(int)$l['id']] = [
                    'id' => (int)$l['id'],
                    'code' => $l['code'],
                    'subject_name' => $l['subject_name'],
                    'teacher_id' => (int)$l['teacher_id'],
                    'teacher_code' => $l['teacher_code'],
                    'teacher_name' => $l['teacher_name'],
                    'class_id' => (int)$l['class_id'],
                    'class_code' => $l['class_code'],
                    'student_count' => (int)$l['student_count'],
                    'required_room_type' => $l['required_room_type'],
                    'is_locked' => (int)$l['is_locked'],
                    'notes' => $l['notes'] ?? '',
                ];
            }
            $jsSlots = [];
            foreach ($dataset['slots'] as $s) {
                $jsSlots[(int)$s['id']] = [
                    'id' => (int)$s['id'],
                    'code' => $s['code'],
                    'label' => $s['label'],
                    'week_no' => (int)($s['week_no'] ?? 1),
                    'day_of_week' => (int)$s['day_of_week'],
                    'period_of_day' => (int)$s['period_of_day'],
                ];
            }
            $jsRooms = [];
            foreach ($dataset['rooms'] as $r) {
                $jsRooms[(int)$r['id']] = [
                    'id' => (int)$r['id'],
                    'code' => $r['code'],
                    'name' => $r['name'],
                    'capacity' => (int)$r['capacity'],
                    'room_type' => $r['room_type'],
                ];
            }
            $jsAssignments = [];
            foreach ($activeAssignments as $a) {
                $jsAssignments[(int)$a['lesson_id']] = [
                    'lesson_id' => (int)$a['lesson_id'],
                    'slot_id' => (int)$a['slot_id'],
                    'room_id' => (int)$a['room_id'],
                    'room_code' => $a['room_code'],
                    'slot_code' => $a['slot_code'],
                    'slot_label' => $a['slot_label'],
                    'is_locked' => (int)$a['is_locked'],
                    'soft_penalty' => (int)($a['soft_penalty'] ?? 0),
                    'soft_notes' => $a['soft_notes'] ?? '',
                ];
            }
            $jsHardBusy = [];
            foreach ($dataset['unavailability'] as $u) {
                if ($u['constraint_type'] === 'HARD_BUSY') {
                    $jsHardBusy[(int)$u['teacher_id']][(int)$u['slot_id']] = $u['reason'];
                }
            }
            $jsStudentsByClass = [];
            foreach ($studentAccounts as $stu) {
                $cid = (int)($stu['class_id'] ?? 0);
                $jsStudentsByClass[$cid][] = $stu['username'] . ' (' . $stu['full_name'] . ')';
            }
            ?>
            <script>
            window.TT_STATE = {
                lessons: <?= json_encode($jsLessons, JSON_UNESCAPED_UNICODE) ?>,
                slots: <?= json_encode($jsSlots, JSON_UNESCAPED_UNICODE) ?>,
                rooms: <?= json_encode($jsRooms, JSON_UNESCAPED_UNICODE) ?>,
                assignments: <?= json_encode($jsAssignments, JSON_UNESCAPED_UNICODE) ?>,
                hardBusy: <?= json_encode($jsHardBusy, JSON_UNESCAPED_UNICODE) ?>,
                studentsByClass: <?= json_encode($jsStudentsByClass, JSON_UNESCAPED_UNICODE) ?>,
                activeVersionId: <?= (int)($activeVersion['id'] ?? 0) ?>
            };

            let draggedLessonId = null;

            function filterKanbanCards(query) {
                const q = (query || '').toLowerCase().trim();
                const cards = document.querySelectorAll('.searchable-card');
                cards.forEach(card => {
                    const text = (card.textContent || '').toLowerCase();
                    card.style.display = (!q || text.includes(q)) ? '' : 'none';
                });
            }

            /**
             * Đánh giá tức thời 7 điều kiện bắt buộc khi đặt lessonId vào slotId.
             * Trả về: { valid: bool, reason: string, bestRoom: object|null, compatibleFreeRooms: array }
             */
            function evaluateSlotForLesson(lessonId, slotId) {
                const lesson = window.TT_STATE.lessons[lessonId];
                const slot = window.TT_STATE.slots[slotId];
                if (!lesson || !slot) {
                    return { valid: false, reason: 'Không hợp lệ', bestRoom: null, compatibleFreeRooms: [] };
                }
                if (lesson.is_locked === 1) {
                    return { valid: false, reason: '🔒 Buổi đã khóa cố định', bestRoom: null, compatibleFreeRooms: [] };
                }
                // 1. Kiểm tra lịch bận cứng của giảng viên (HC-06)
                const busyMap = window.TT_STATE.hardBusy[lesson.teacher_id] || {};
                if (busyMap[slotId]) {
                    return { valid: false, reason: '⛔ GV bận cứng (' + busyMap[slotId] + ')', bestRoom: null, compatibleFreeRooms: [] };
                }
                // 2. Kiểm tra trùng giảng viên, trùng lớp và phòng đang bị chiếm trong slotId (HC-01, HC-02, HC-03)
                const occupiedRooms = {};
                for (const [otherLidStr, assign] of Object.entries(window.TT_STATE.assignments)) {
                    const otherLid = parseInt(otherLidStr, 10);
                    if (otherLid === lessonId) continue;
                    if (assign.slot_id === slotId) {
                        const otherLesson = window.TT_STATE.lessons[otherLid];
                        if (otherLesson && otherLesson.teacher_id === lesson.teacher_id) {
                            return { valid: false, reason: '⛔ Trùng GV (' + otherLesson.code + ')', bestRoom: null, compatibleFreeRooms: [] };
                        }
                        if (otherLesson && otherLesson.class_id === lesson.class_id) {
                            return { valid: false, reason: '⛔ Trùng Lớp (' + otherLesson.code + ')', bestRoom: null, compatibleFreeRooms: [] };
                        }
                        occupiedRooms[assign.room_id] = otherLesson ? otherLesson.code : 'Đã có lớp';
                    }
                }
                // 3. Tìm các phòng đúng loại, đủ sức chứa và còn trống trong ca này (HC-04, HC-05)
                const freeRooms = [];
                for (const room of Object.values(window.TT_STATE.rooms)) {
                    if (room.room_type === lesson.required_room_type && room.capacity >= lesson.student_count) {
                        if (!occupiedRooms[room.id]) {
                            freeRooms.push(room);
                        }
                    }
                }
                freeRooms.sort((a, b) => a.capacity - b.capacity);
                if (freeRooms.length === 0) {
                    return { valid: false, reason: '⛔ Hết phòng ' + lesson.required_room_type + ' ≥ ' + lesson.student_count + ' chỗ', bestRoom: null, compatibleFreeRooms: [] };
                }
                const best = freeRooms[0];
                return {
                    valid: true,
                    reason: '✅ Hợp lệ — Gợi ý P.' + best.code + ' (' + best.capacity + ' chỗ)',
                    bestRoom: best,
                    compatibleFreeRooms: freeRooms
                };
            }

            function handleLessonDragStart(e, lessonId) {
                const lesson = window.TT_STATE.lessons[lessonId];
                if (!lesson || lesson.is_locked === 1) {
                    e.preventDefault();
                    return;
                }
                draggedLessonId = lessonId;
                e.dataTransfer.setData('text/plain', String(lessonId));
                e.currentTarget.classList.add('dragging-card');

                // Đánh giá toàn bộ các ô khung giờ trên màn hình và tô màu Xanh / Đỏ trực tiếp
                document.querySelectorAll('.kanban-session-slot[data-slot-id]').forEach(slotEl => {
                    const sId = parseInt(slotEl.getAttribute('data-slot-id') || '0', 10);
                    if (!sId) return;
                    const res = evaluateSlotForLesson(lessonId, sId);
                    const indEl = document.getElementById('slot_drop_ind_' + sId);
                    if (res.valid) {
                        slotEl.classList.add('slot-drop-valid');
                        slotEl.classList.remove('slot-drop-invalid');
                        if (indEl) {
                            indEl.textContent = res.reason;
                            indEl.className = 'slot-drop-indicator show-valid';
                        }
                    } else {
                        slotEl.classList.add('slot-drop-invalid');
                        slotEl.classList.remove('slot-drop-valid');
                        if (indEl) {
                            indEl.textContent = res.reason;
                            indEl.className = 'slot-drop-indicator show-invalid';
                        }
                    }
                });
            }

            function handleLessonDragEnd(e) {
                e.currentTarget.classList.remove('dragging-card');
                draggedLessonId = null;
                document.querySelectorAll('.kanban-session-slot').forEach(slotEl => {
                    slotEl.classList.remove('slot-drop-valid', 'slot-drop-invalid', 'slot-drag-over');
                });
                document.querySelectorAll('.slot-drop-indicator').forEach(indEl => {
                    indEl.textContent = '';
                    indEl.className = 'slot-drop-indicator';
                });
            }

            function handleSlotDragOver(e, slotId) {
                if (!draggedLessonId || !slotId) return;
                const res = evaluateSlotForLesson(draggedLessonId, slotId);
                if (res.valid) {
                    e.preventDefault();
                    e.currentTarget.classList.add('slot-drag-over');
                }
            }

            function handleSlotDragLeave(e) {
                e.currentTarget.classList.remove('slot-drag-over');
            }

            function handleSlotDrop(e, slotId) {
                e.preventDefault();
                e.currentTarget.classList.remove('slot-drag-over');
                const lId = draggedLessonId || parseInt(e.dataTransfer.getData('text/plain') || '0', 10);
                if (!lId || !slotId) return;
                const res = evaluateSlotForLesson(lId, slotId);
                if (!res.valid || !res.bestRoom) {
                    alert('Không thể thả vào khung giờ này: ' + res.reason);
                    return;
                }
                document.getElementById('dnd_lesson_id').value = lId;
                document.getElementById('dnd_slot_id').value = slotId;
                document.getElementById('dnd_room_id').value = res.bestRoom.id;
                document.getElementById('drag_drop_move_form').submit();
            }

            // Mở Modal Chi tiết & Điều khiển nhanh Buổi học
            function openLessonModal(lessonId) {
                const lesson = window.TT_STATE.lessons[lessonId];
                if (!lesson) return;
                const assign = window.TT_STATE.assignments[lessonId] || null;

                document.getElementById('md_lesson_code').textContent =
                    lesson.code + ' • LOẠI PHÒNG YÊU CẦU: ' + lesson.required_room_type + (lesson.is_locked ? ' • 🔒 ĐÃ KHÓA CỐ ĐỊNH' : ' • XẾP TỰ ĐỘNG');
                document.getElementById('md_subject_name').textContent = lesson.subject_name;
                document.getElementById('md_class_info').textContent = 'Lớp ' + lesson.class_code + ' (' + lesson.student_count + ' sinh viên)';
                document.getElementById('md_teacher_info').textContent = lesson.teacher_code + ' — ' + lesson.teacher_name;

                if (assign) {
                    document.getElementById('md_slot_info').textContent = assign.slot_code + ' (' + assign.slot_label + ')';
                    const rObj = window.TT_STATE.rooms[assign.room_id];
                    document.getElementById('md_room_info').textContent =
                        'Phòng ' + assign.room_code + (rObj ? (' (' + rObj.room_type + ', Sức chứa ' + rObj.capacity + ' chỗ)') : '');
                    document.getElementById('md_soft_note_box').innerHTML =
                        '<strong>Trạng thái tối ưu:</strong> Điểm phạt mềm = <strong>' + assign.soft_penalty + 'đ</strong>. ' +
                        (assign.soft_notes ? ('(' + assign.soft_notes + ')') : 'Đạt tối ưu toàn bộ ràng buộc cứng và mềm.');
                } else {
                    document.getElementById('md_slot_info').textContent = '⚠️ Chưa xếp được khung giờ';
                    document.getElementById('md_room_info').textContent = '⚠️ Chưa phân phòng';
                    document.getElementById('md_soft_note_box').innerHTML =
                        '<strong>Trạng thái:</strong> Buổi học này hiện đang nằm trong danh sách chưa xếp. Bạn có thể chọn Khung giờ và Phòng học bên dưới để xếp ngay.';
                }

                // Danh sách sinh viên của lớp
                const stuList = window.TT_STATE.studentsByClass[lesson.class_id] || [];
                document.getElementById('md_student_list_count').textContent = stuList.length;
                const chipsWrap = document.getElementById('md_student_chips');
                chipsWrap.innerHTML = '';
                if (stuList.length === 0) {
                    chipsWrap.innerHTML = '<span style="font-size:12px; color:#64748b;">Lớp hiện có ' + lesson.student_count + ' sinh viên theo danh sách đào tạo.</span>';
                } else {
                    stuList.forEach(sText => {
                        const sp = document.createElement('span');
                        sp.className = 'bento-chip';
                        sp.textContent = '🎓 ' + sText;
                        chipsWrap.appendChild(sp);
                    });
                }

                // Nếu là Admin
                const adminLessonInput = document.getElementById('md_admin_lesson_id');
                if (adminLessonInput) {
                    adminLessonInput.value = lessonId;
                    const slotSel = document.getElementById('md_admin_slot_select');
                    slotSel.innerHTML = '';
                    Object.values(window.TT_STATE.slots).forEach(s => {
                        const evalRes = evaluateSlotForLesson(lessonId, s.id);
                        const opt = document.createElement('option');
                        opt.value = s.id;
                        opt.textContent = (evalRes.valid ? '🟢 ' : '🔴 ') + s.code + ' — ' + s.label + (evalRes.valid ? '' : (' [' + evalRes.reason + ']'));
                        if (assign && assign.slot_id === s.id) opt.selected = true;
                        slotSel.appendChild(opt);
                    });
                    const adjLink = document.getElementById('md_admin_adjust_link');
                    if (adjLink) {
                        adjLink.href = 'index.php?tab=adjust&version_id=' + window.TT_STATE.activeVersionId + '&pick_lesson_id=' + lessonId;
                    }
                    refreshModalRoomOptions();
                }

                // Nếu là Giảng viên
                const teacherLessonInput = document.getElementById('md_teacher_lesson_id');
                if (teacherLessonInput) {
                    teacherLessonInput.value = lessonId;
                    const tSlotSel = document.getElementById('md_teacher_slot_select');
                    tSlotSel.innerHTML = '';
                    Object.values(window.TT_STATE.slots).forEach(s => {
                        const evalRes = evaluateSlotForLesson(lessonId, s.id);
                        const opt = document.createElement('option');
                        opt.value = s.id;
                        opt.textContent = (evalRes.valid ? '🟢 ' : '🔴 ') + s.code + ' — ' + s.label;
                        if (assign && assign.slot_id === s.id) opt.selected = true;
                        tSlotSel.appendChild(opt);
                    });
                    refreshTeacherModalRooms();
                }

                document.getElementById('lesson_detail_modal').style.display = 'flex';
            }

            function refreshModalRoomOptions() {
                const lessonId = parseInt(document.getElementById('md_admin_lesson_id').value || '0', 10);
                const slotId = parseInt(document.getElementById('md_admin_slot_select').value || '0', 10);
                const roomSel = document.getElementById('md_admin_room_select');
                const msgEl = document.getElementById('md_live_check_msg');
                const saveBtn = document.getElementById('md_admin_save_btn');
                if (!lessonId || !slotId || !roomSel) return;

                const lesson = window.TT_STATE.lessons[lessonId];
                const assign = window.TT_STATE.assignments[lessonId] || null;
                const evalRes = evaluateSlotForLesson(lessonId, slotId);

                roomSel.innerHTML = '';
                Object.values(window.TT_STATE.rooms).forEach(r => {
                    const isTypeOk = r.room_type === lesson.required_room_type;
                    const isCapOk = r.capacity >= lesson.student_count;
                    if (!isTypeOk || !isCapOk) return;
                    const isFree = evalRes.compatibleFreeRooms.some(fr => fr.id === r.id) || (assign && assign.slot_id === slotId && assign.room_id === r.id);
                    const opt = document.createElement('option');
                    opt.value = r.id;
                    opt.textContent = (isFree ? '✅ ' : '⛔ ') + r.code + ' — ' + r.name + ' (' + r.capacity + ' chỗ)' + (isFree ? ' [Trống]' : ' [Đã có lớp]');
                    if (assign && assign.room_id === r.id) opt.selected = true;
                    roomSel.appendChild(opt);
                });

                if (lesson.is_locked === 1) {
                    msgEl.style.color = '#b45309';
                    msgEl.textContent = '🔒 Buổi học đang bị khóa cố định. Hãy mở khóa nếu muốn di chuyển.';
                    saveBtn.disabled = true;
                } else if (evalRes.valid) {
                    msgEl.style.color = '#059669';
                    msgEl.textContent = evalRes.reason;
                    saveBtn.disabled = false;
                } else {
                    msgEl.style.color = '#dc2626';
                    msgEl.textContent = evalRes.reason;
                    saveBtn.disabled = false;
                }
            }

            function refreshTeacherModalRooms() {
                const lessonId = parseInt(document.getElementById('md_teacher_lesson_id').value || '0', 10);
                const roomSel = document.getElementById('md_teacher_room_select');
                if (!lessonId || !roomSel) return;
                const lesson = window.TT_STATE.lessons[lessonId];
                roomSel.innerHTML = '';
                Object.values(window.TT_STATE.rooms).forEach(r => {
                    if (r.room_type === lesson.required_room_type && r.capacity >= lesson.student_count) {
                        const opt = document.createElement('option');
                        opt.value = r.id;
                        opt.textContent = r.code + ' — ' + r.name + ' (' + r.capacity + ' chỗ)';
                        roomSel.appendChild(opt);
                    }
                });
            }

            function closeLessonModal() {
                document.getElementById('lesson_detail_modal').style.display = 'none';
            }
            </script>

        <?php elseif ($activeTab === 'requests' && $isScheduler): ?>
            <!-- ==================== TAB MỚI: PHÊ DUYỆT ĐỀ XUẤT ĐỔI LỊCH GIẢNG VIÊN & NHẬT KÝ HỆ THỐNG ==================== -->
            <?php
            $requestsList = $dataset['reschedule_requests'] ?? [];
            $auditLogsList = $dataset['audit_logs'] ?? [];
            $currentAssignByLesson = [];
            foreach ($activeAssignments as $aRow) {
                $currentAssignByLesson[(int)$aRow['lesson_id']] = $aRow;
            }
            ?>
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>📩 Phê duyệt Yêu cầu Xin đổi lịch / Dạy bù từ Giảng viên</h2>
                        <p style="margin:4px 0 0; font-size:13px; color:#64748b;">
                            Hệ thống tự động kiểm toán trước 7 điều kiện bắt buộc và mức biến thiên điểm phạt mềm của từng đề xuất trên phương án đang xét (<strong><?= h($activeVersion['version_name'] ?? '') ?></strong>).
                        </p>
                    </div>
                    <span class="badge badge-lt"><?= count($requestsList) ?> yêu cầu</span>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Mã YC</th>
                            <th>Giảng viên đề xuất</th>
                            <th>Buổi học &amp; Lớp</th>
                            <th>Vị trí hiện tại</th>
                            <th>Đề xuất chuyển tới</th>
                            <th>Lý do của Giảng viên</th>
                            <th>Kiểm tra Ràng buộc Tự động</th>
                            <th>Trạng thái / Phê duyệt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requestsList)): ?>
                            <tr><td colspan="8" style="text-align:center; padding:20px; color:#64748b;">Chưa có yêu cầu đổi lịch nào từ giảng viên.</td></tr>
                        <?php else: ?>
                            <?php foreach ($requestsList as $rq):
                                $rqLid = (int)$rq['lesson_id'];
                                $curAssign = $currentAssignByLesson[$rqLid] ?? null;
                                $liveImpact = $engine->checkManualMoveImpact(
                                    $rqLid,
                                    (int)$rq['target_slot_id'],
                                    (int)$rq['target_room_id'],
                                    $activeAssignments,
                                    $dataset
                                );
                            ?>
                            <tr>
                                <td><strong>#<?= (int)$rq['id'] ?></strong></td>
                                <td>
                                    <strong><?= h($rq['teacher_code']) ?></strong><br>
                                    <?= h($rq['teacher_name']) ?>
                                </td>
                                <td>
                                    <strong><?= h($rq['lesson_code']) ?> — <?= h($rq['subject_name']) ?></strong><br>
                                    <span class="badge badge-lt">Lớp <?= h($rq['class_code']) ?> (<?= (int)$rq['student_count'] ?> SV • <?= h($rq['required_room_type']) ?>)</span>
                                </td>
                                <td>
                                    <?php if ($curAssign): ?>
                                        <strong><?= h($curAssign['slot_code']) ?></strong><br>
                                        Phòng <strong><?= h($curAssign['room_code']) ?></strong>
                                    <?php else: ?>
                                        <span class="badge badge-soft-avoid">Chưa xếp</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong style="color:#4f46e5;"><?= h($rq['target_slot_code']) ?></strong> (<?= h($rq['target_slot_label']) ?>)<br>
                                    Phòng <strong><?= h($rq['target_room_code']) ?></strong> (<?= (int)$rq['target_room_capacity'] ?> chỗ)
                                </td>
                                <td><?= h($rq['reason']) ?></td>
                                <td>
                                    <?php if ($rq['status'] !== 'PENDING'): ?>
                                        <span style="font-size:12px; color:#64748b;"><?= h($rq['admin_note'] ?: 'Đã xử lý') ?></span>
                                    <?php elseif ($liveImpact['can_apply']): ?>
                                        <span class="badge badge-soft-prefer">✅ Thỏa mãn 7/7 ràng buộc cứng</span>
                                        <div style="font-size:11.5px; color:#059669; margin-top:4px;">
                                            Điểm phạt mềm sau đổi: <strong><?= (int)$liveImpact['after_soft_score'] ?>đ</strong>
                                            (<?= (int)$liveImpact['soft_score_delta'] <= 0 ? (int)$liveImpact['soft_score_delta'] : ('+' . (int)$liveImpact['soft_score_delta']) ?>đ)
                                        </div>
                                    <?php else: ?>
                                        <span class="badge badge-hard">⛔ Vi phạm ràng buộc cứng</span>
                                        <div style="font-size:11.5px; color:#dc2626; margin-top:4px;">
                                            <?= h(implode('; ', $liveImpact['hard_violations'] ?? [$liveImpact['summary']])) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="min-width:190px;">
                                    <?php if ($rq['status'] === 'PENDING'): ?>
                                        <div style="display:flex; flex-direction:column; gap:6px;">
                                            <form method="post" action="index.php?tab=requests" style="margin:0;">
                                                <input type="hidden" name="action" value="review_reschedule_request">
                                                <input type="hidden" name="request_id" value="<?= (int)$rq['id'] ?>">
                                                <input type="hidden" name="decision" value="APPROVE">
                                                <button type="submit" class="btn btn-primary btn-sm" style="width:100%;" <?= !$liveImpact['can_apply'] ? 'disabled title="Đang xung đột ràng buộc cứng"' : '' ?>>
                                                    ✅ Duyệt &amp; Tự động đổi lịch
                                                </button>
                                            </form>
                                            <form method="post" action="index.php?tab=requests" style="margin:0;">
                                                <input type="hidden" name="action" value="review_reschedule_request">
                                                <input type="hidden" name="request_id" value="<?= (int)$rq['id'] ?>">
                                                <input type="hidden" name="decision" value="REJECT">
                                                <button type="submit" class="btn btn-outline btn-sm" style="width:100%;">
                                                    ❌ Từ chối đề xuất
                                                </button>
                                            </form>
                                        </div>
                                    <?php elseif ($rq['status'] === 'APPROVED'): ?>
                                        <span class="badge badge-soft-prefer">✅ ĐÃ PHÊ DUYỆT</span>
                                    <?php else: ?>
                                        <span class="badge badge-hard">❌ ĐÃ TỪ CHỐI</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Nhật ký Tác động Hệ thống (System Audit Log) -->
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>📜 Nhật ký Tác động &amp; Lịch sử Điều phối Hệ thống (Audit Log)</h2>
                        <p style="margin:4px 0 0; font-size:13px; color:#64748b;">
                            Ghi nhận tự động mọi thao tác chạy thuật toán, kéo-thả đổi lịch, gửi đề xuất của giảng viên, phê duyệt và nạp dữ liệu hàng loạt.
                        </p>
                    </div>
                    <span class="badge badge-th"><?= count($auditLogsList) ?> bản ghi gần nhất</span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Thời gian</th>
                            <th>Người thực hiện</th>
                            <th>Vai trò</th>
                            <th>Mã hành động</th>
                            <th>Nội dung tác động</th>
                            <th>Chi tiết kỹ thuật</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($auditLogsList as $log): ?>
                            <tr>
                                <td style="white-space:nowrap; font-size:12px; color:#64748b;"><?= h($log['created_at']) ?></td>
                                <td><strong><?= h($log['actor_name']) ?></strong></td>
                                <td>
                                    <span class="badge <?= $log['actor_role'] === 'scheduler' ? 'badge-locked' : 'badge-th' ?>">
                                        <?= $log['actor_role'] === 'scheduler' ? '⚙️ Quản trị' : '👨‍🏫 Giảng viên' ?>
                                    </span>
                                </td>
                                <td><code><?= h($log['action_code']) ?></code></td>
                                <td><strong><?= h($log['summary']) ?></strong></td>
                                <td style="font-size:12.5px; color:#475569;"><?= h($log['details'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($activeTab === 'analytics' && $isScheduler): ?>
            <!-- ==================== TAB MỚI: PHÂN TÍCH CÔNG SUẤT PHÒNG & TẢI GIẢNG DẠY + BULK IMPORT CSV ==================== -->
            <?php
            $totalSlotsCount = max(1, count($dataset['slots']));
            $roomStats = [];
            foreach ($dataset['rooms'] as $r) {
                $rid = (int)$r['id'];
                $roomStats[$rid] = [
                    'room' => $r,
                    'used_slots' => 0,
                    'total_students' => 0,
                    'morning_count' => 0,
                    'afternoon_count' => 0,
                ];
            }
            $teacherStats = [];
            foreach ($dataset['teachers'] as $t) {
                $tid = (int)$t['id'];
                $teacherStats[$tid] = [
                    'teacher' => $t,
                    'taught_count' => 0,
                    'morning_count' => 0,
                    'afternoon_count' => 0,
                    'hard_busy_count' => 0,
                    'soft_prefer_count' => 0,
                    'soft_avoid_count' => 0,
                    'soft_penalty_sum' => 0,
                ];
            }
            foreach ($dataset['unavailability'] as $u) {
                $tid = (int)$u['teacher_id'];
                if (!isset($teacherStats[$tid])) continue;
                if ($u['constraint_type'] === 'HARD_BUSY') {
                    $teacherStats[$tid]['hard_busy_count']++;
                } elseif ($u['constraint_type'] === 'SOFT_PREFER') {
                    $teacherStats[$tid]['soft_prefer_count']++;
                } else {
                    $teacherStats[$tid]['soft_avoid_count']++;
                }
            }
            foreach ($activeAssignments as $a) {
                $rid = (int)$a['room_id'];
                $tid = (int)$a['teacher_id'];
                $pDay = (int)$a['period_of_day'];
                if (isset($roomStats[$rid])) {
                    $roomStats[$rid]['used_slots']++;
                    $roomStats[$rid]['total_students'] += (int)$a['student_count'];
                    if ($pDay === 1) $roomStats[$rid]['morning_count']++;
                    else $roomStats[$rid]['afternoon_count']++;
                }
                if (isset($teacherStats[$tid])) {
                    $teacherStats[$tid]['taught_count']++;
                    $teacherStats[$tid]['soft_penalty_sum'] += (int)($a['soft_penalty'] ?? 0);
                    if ($pDay === 1) $teacherStats[$tid]['morning_count']++;
                    else $teacherStats[$tid]['afternoon_count']++;
                }
            }
            ?>
            <div class="grid-2">
                <!-- 1. Biểu đồ Công suất Khai thác Phòng học & Hiệu suất Lấp đầy Ghế ngồi -->
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2>🏢 Phân tích Công suất Phòng học &amp; Hiệu suất Lấp đầy Ghế</h2>
                            <p style="margin:4px 0 0; font-size:12.5px; color:#64748b;">
                                Đánh giá mức độ khai thác từng phòng học và tỷ lệ lấp đầy sức chứa (Seat Fill Rate %) trên phương án hiện tại.
                            </p>
                        </div>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Phòng học</th>
                                <th>Loại &amp; Sức chứa</th>
                                <th>Số ca sử dụng</th>
                                <th>Hiệu suất lấp đầy ghế (Seat Fill %)</th>
                                <th>Trạng thái khai thác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roomStats as $rs):
                                $r = $rs['room'];
                                $used = $rs['used_slots'];
                                $slotPct = round(($used * 100) / $totalSlotsCount, 1);
                                $avgStudents = $used > 0 ? round($rs['total_students'] / $used, 1) : 0;
                                $seatFillPct = ($used > 0 && (int)$r['capacity'] > 0)
                                    ? min(100, round(($avgStudents * 100) / (int)$r['capacity'], 1))
                                    : 0;
                            ?>
                            <tr>
                                <td>
                                    <strong><?= h($r['code']) ?></strong><br>
                                    <small style="color:#64748b;"><?= h($r['name']) ?></small>
                                </td>
                                <td>
                                    <span class="badge badge-<?= strtolower(h($r['room_type'])) ?>"><?= h($r['room_type']) ?></span>
                                    <strong><?= (int)$r['capacity'] ?> chỗ</strong>
                                </td>
                                <td>
                                    <strong><?= $used ?>/<?= $totalSlotsCount ?> ca</strong> (<?= $slotPct ?>%)<br>
                                    <small style="color:#64748b;">☀️ <?= $rs['morning_count'] ?> sáng • 🌤️ <?= $rs['afternoon_count'] ?> chiều</small>
                                </td>
                                <td style="min-width:190px;">
                                    <div style="display:flex; justify-content:space-between; font-size:12px; font-weight:700; margin-bottom:4px;">
                                        <span>TB: <?= $avgStudents ?>/<?= (int)$r['capacity'] ?> SV</span>
                                        <span style="color:#4f46e5;"><?= $seatFillPct ?>%</span>
                                    </div>
                                    <div class="kpi-progress-bar">
                                        <div class="kpi-progress-fill" style="width: <?= $seatFillPct ?>%;"></div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($seatFillPct >= 75): ?>
                                        <span class="badge badge-soft-prefer">⚡ Tối ưu cao (Sát sĩ số)</span>
                                    <?php elseif ($used > 0): ?>
                                        <span class="badge badge-lt">✅ Cân bằng</span>
                                    <?php else: ?>
                                        <span class="badge badge-soft-avoid">⚪ Đang rảnh</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 2. Biểu đồ Phân tích Tải Giảng dạy & Ràng buộc Giảng viên -->
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2>👨‍🏫 Phân tích Tải Giảng dạy &amp; Mức độ Đáp ứng Nguyện vọng GV</h2>
                            <p style="margin:4px 0 0; font-size:12.5px; color:#64748b;">
                                Thống kê khối lượng giảng dạy, mật độ lịch bận cứng và mức độ thỏa mãn nguyện vọng ưu tiên của từng giảng viên.
                            </p>
                        </div>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Giảng viên</th>
                                <th>Số ca dạy</th>
                                <th>Mật độ Lịch bận / Ưu tiên</th>
                                <th>Điểm phạt mềm</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teacherStats as $ts):
                                $t = $ts['teacher'];
                                $freeSlots = max(0, $totalSlotsCount - $ts['hard_busy_count']);
                                $busyPct = round(($ts['hard_busy_count'] * 100) / $totalSlotsCount, 1);
                            ?>
                            <tr>
                                <td>
                                    <strong><?= h($t['code']) ?> — <?= h($t['name']) ?></strong><br>
                                    <small style="color:#64748b;"><?= h($t['department']) ?> (Max <?= (int)$t['max_lessons_per_day'] ?> ca/ngày)</small>
                                </td>
                                <td>
                                    <strong><?= $ts['taught_count'] ?> buổi</strong><br>
                                    <small style="color:#64748b;">☀️ <?= $ts['morning_count'] ?> sáng • 🌤️ <?= $ts['afternoon_count'] ?> chiều</small>
                                </td>
                                <td>
                                    <div style="font-size:12px;">
                                        🔴 Bận cứng: <strong><?= $ts['hard_busy_count'] ?>/<?= $totalSlotsCount ?> ca</strong> (Còn rảnh <?= $freeSlots ?> ca)
                                    </div>
                                    <div style="font-size:11.5px; color:#475569; margin-top:2px;">
                                        🟢 Ưu tiên: <?= $ts['soft_prefer_count'] ?> • 🟡 Hạn chế: <?= $ts['soft_avoid_count'] ?>
                                    </div>
                                    <div class="kpi-progress-bar" style="margin-top:4px;">
                                        <div class="kpi-progress-fill" style="width: <?= $busyPct ?>%; background: <?= $busyPct >= 80 ? '#ef4444' : '#3b82f6' ?>;"></div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($ts['soft_penalty_sum'] === 0): ?>
                                        <span class="badge badge-soft-prefer">0đ (Tối ưu)</span>
                                    <?php else: ?>
                                        <span class="badge badge-soft-avoid"><?= $ts['soft_penalty_sum'] ?>đ phạt</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 3. Công cụ Bulk Import CSV / Excel Paste kèm Kiểm tra Ràng buộc Hàng loạt -->
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>📥 Công cụ Nạp Hàng Loạt Buổi học bằng CSV / Excel Paste (Bulk Import &amp; Batch Validation)</h2>
                        <p style="margin:4px 0 0; font-size:13px; color:#64748b;">
                            Hỗ trợ nhập hàng loạt học phần từ bảng tính CSV. Hệ thống tự động kiểm tra định dạng, mã giảng viên, mã lớp, sĩ số và loại phòng của từng dòng trước khi lưu vào CSDL.
                        </p>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" onclick="fillSampleCsvImport()">
                        ⚡ Điền nhanh 3 buổi học CSV mẫu để Demo
                    </button>
                </div>

                <form method="post" action="index.php?tab=analytics">
                    <input type="hidden" name="action" value="bulk_import_lessons">
                    <div class="form-group" style="margin-bottom:12px;">
                        <label>Định dạng mỗi dòng: <code>Mã buổi, Tên học phần, Mã GV, Mã lớp, Sĩ số, Loại phòng (LT/TH), Ghi chú</code></label>
                        <textarea name="csv_content" id="bulk_csv_textarea" rows="5"
                                  style="width:100%; font-family:monospace; font-size:13px; padding:10px; border:1px solid #cbd5e1; border-radius:8px;"
                                  placeholder="BH21, Phát triển ứng dụng đám mây, GV01, CNTT 18-12, 35, LT, Học vào cuối tuần hoặc trong tuần&#10;BH22, Thực hành Docker & Kubernetes, GV04, CNTT 18-09, 38, TH, Cần phòng máy LAB"></textarea>
                    </div>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <button type="submit" class="btn btn-primary">📥 Kiểm tra Ràng buộc &amp; Nạp hàng loạt vào CSDL</button>
                        <span style="font-size:12.5px; color:#64748b;">Sau khi nạp xong, bạn có thể sang tab <strong>Thời khóa biểu</strong> để chạy thuật toán xếp luôn các buổi mới!</span>
                    </div>
                </form>
            </div>
            <script>
            function fillSampleCsvImport() {
                document.getElementById('bulk_csv_textarea').value =
                    "BH21, Phát triển ứng dụng Đám mây (Cloud), GV01, CNTT 18-12, 35, LT, Học phần bổ sung học kỳ 1\n" +
                    "BH22, Thực hành Docker & Microservices, GV04, CNTT 18-09, 38, TH, Thực hành tại phòng LAB\n" +
                    "BH23, Phân tích dữ liệu kinh doanh, GV05, KT 18-10, 60, LT, Lớp ghép 60 sinh viên";
            }
            </script>

        <?php elseif ($activeTab === 'adjust'): ?>
            <!-- ==================== TAB 4: ĐỔI LỊCH THỦ CÔNG & KIỂM TRA ẢNH HƯỞNG ==================== -->
            <?php
            $pickedLessonId = isset($_GET['pick_lesson_id'])
                ? (int)$_GET['pick_lesson_id']
                : (isset($_POST['lesson_id']) ? (int)$_POST['lesson_id'] : (int)($dataset['lessons'][3]['id'] ?? 4));
            $pickedSlotId = isset($_POST['target_slot_id']) ? (int)$_POST['target_slot_id'] : 1;
            $pickedRoomId = isset($_POST['target_room_id']) ? (int)$_POST['target_room_id'] : 1;
            ?>
            <div class="grid-2">
                <div class="card">
                    <div class="card-header">
                        <h2>Công cụ Đổi lịch & Kiểm tra Ảnh hưởng (Chức năng 4)</h2>
                        <span>Phương án đang xét: <strong><?= h($activeVersion['version_name'] ?? '') ?></strong></span>
                    </div>
                    <p style="font-size:13px; color:#475569; margin-top:0;">
                        Chọn một buổi học (đã xếp hoặc chưa xếp) và chọn Khung giờ + Phòng học đích. Hệ thống sẽ kiểm tra ngay toàn bộ 7 điều kiện bắt buộc (bao gồm bảo vệ buổi đã khóa) và tính toán mức thay đổi điểm phạt ưu tiên trước khi áp dụng.
                    </p>

                    <?php if ($isScheduler && $activeVersion): ?>
                    <form method="post" action="index.php?tab=adjust&version_id=<?= (int)$activeVersion['id'] ?>">
                        <input type="hidden" name="version_id" value="<?= (int)$activeVersion['id'] ?>">
                        <div class="form-group" style="margin-bottom:12px;">
                            <label>1. Chọn Buổi học cần đổi lịch hoặc xếp bổ sung</label>
                            <select name="lesson_id" onchange="window.location='index.php?tab=adjust&version_id=<?= (int)$activeVersion['id'] ?>&pick_lesson_id='+this.value">
                                <?php
                                $assignedMap = [];
                                foreach ($activeAssignments as $a) {
                                    $assignedMap[(int)$a['lesson_id']] = $a;
                                }
                                foreach ($dataset['lessons'] as $l):
                                    $lId = (int)$l['id'];
                                    $cur = $assignedMap[$lId] ?? null;
                                    $curTag = $cur ? "Đang ở {$cur['slot_code']} @ {$cur['room_code']}" : "CHƯA XẾP ĐƯỢC";
                                    if ((int)$l['is_locked'] === 1) {
                                        $curTag .= " [🔒 ĐÃ KHÓA]";
                                    }
                                ?>
                                    <option value="<?= $lId ?>" <?= $pickedLessonId === $lId ? 'selected' : '' ?>>
                                        <?= h("{$l['code']} - {$l['subject_name']} ({$l['student_count']} SV, Phòng {$l['required_room_type']}) — [{$curTag}]") ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>2. Chọn Khung giờ đích</label>
                                <select name="target_slot_id">
                                    <?php foreach ($dataset['slots'] as $s): ?>
                                        <option value="<?= (int)$s['id'] ?>" <?= $pickedSlotId === (int)$s['id'] ? 'selected' : '' ?>>
                                            <?= h($s['code'] . ' - ' . $s['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>3. Chọn Phòng học đích</label>
                                <select name="target_room_id">
                                    <?php foreach ($dataset['rooms'] as $r): ?>
                                        <option value="<?= (int)$r['id'] ?>" <?= $pickedRoomId === (int)$r['id'] ? 'selected' : '' ?>>
                                            <?= h("{$r['code']} - {$r['name']} (Loại {$r['room_type']}, {$r['capacity']} chỗ)") ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div style="display:flex; gap:10px; margin-top:8px;">
                            <button type="submit" name="action" value="check_manual_move" class="btn btn-outline">
                                🔍 Kiểm tra ảnh hưởng (Chưa lưu)
                            </button>
                            <button type="submit" name="action" value="apply_manual_move" class="btn btn-primary">
                                ✅ Áp dụng đổi lịch (Nếu hợp lệ)
                            </button>
                        </div>
                    </form>
                    <?php else: ?>
                        <div class="alert alert-warning">Chỉ tài khoản phân quyền <strong>Người phụ trách đào tạo (Người xếp lịch)</strong> mới được phép thực hiện đổi lịch.</div>
                    <?php endif; ?>

                    <?php if ($flash['impact_result'] !== null):
                        $imp = $flash['impact_result'];
                    ?>
                    <div style="margin-top:16px; padding:14px; border-radius:8px; border:1px solid <?= $imp['can_apply'] ? '#86efac' : '#fca5a5' ?>; background: <?= $imp['can_apply'] ? '#f0fdf4' : '#fef2f2' ?>;">
                        <h3 style="margin:0 0 8px; font-size:15px;">Báo cáo Kiểm tra Ảnh hưởng (Impact Report):</h3>
                        <p style="margin:4px 0;"><strong>Kết luận:</strong> <?= h($imp['summary']) ?></p>
                        <ul style="margin:6px 0; padding-left:18px;">
                            <li>Số buổi xếp được: <strong><?= (int)$imp['scheduled_before'] ?></strong> &rarr; <strong><?= (int)$imp['scheduled_after'] ?></strong> buổi</li>
                            <li>Số buổi chưa xếp: <strong><?= (int)$imp['unscheduled_before'] ?></strong> &rarr; <strong><?= (int)$imp['unscheduled_after'] ?></strong> buổi</li>
                            <li>Tổng điểm phạt ưu tiên: <strong><?= (int)$imp['soft_penalty_before'] ?>đ</strong> &rarr; <strong><?= (int)$imp['soft_penalty_after'] ?>đ</strong></li>
                            <li>Đánh giá ưu tiên tại vị trí đích: <?= h($imp['lesson_soft_notes'] ?? '') ?></li>
                        </ul>
                        <?php if (!empty($imp['hard_violations'])): ?>
                            <div style="color:#991b1b; margin-top:6px;">
                                <strong>Chi tiết vi phạm điều kiện bắt buộc:</strong>
                                <ul>
                                    <?php foreach ($imp['hard_violations'] as $v): ?>
                                        <li>[<?= h($v['code']) ?>] <?= h($v['message']) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Bảng gợi ý tự động các vị trí hợp lệ cho buổi học đang chọn -->
                <div class="card">
                    <?php
                    $pickedLesson = null;
                    foreach ($dataset['lessons'] as $l) {
                        if ((int)$l['id'] === $pickedLessonId) {
                            $pickedLesson = $l;
                            break;
                        }
                    }
                    $validSuggestions = [];
                    if ($pickedLesson) {
                        foreach ($dataset['slots'] as $s) {
                            foreach ($dataset['rooms'] as $r) {
                                $hc = $checker->checkHardConstraintsForPlacement($pickedLesson, $s, $r, $activeAssignments, $dataset, $pickedLessonId);
                                if ($hc['valid']) {
                                    $sc = $checker->evaluateSoftConstraintsForPlacement($pickedLesson, $s, $r, $activeAssignments, $dataset, $pickedLessonId);
                                    $validSuggestions[] = [
                                        'slot' => $s,
                                        'room' => $r,
                                        'soft_penalty' => $sc['penalty'],
                                        'soft_notes' => $sc['notes_text'],
                                    ];
                                }
                            }
                        }
                        usort($validSuggestions, fn(array $a, array $b) => $a['soft_penalty'] <=> $b['soft_penalty']);
                    }
                    ?>
                    <div class="card-header">
                        <h2>Các vị trí (Khung giờ, Phòng) hợp lệ cho [<?= h($pickedLesson['code'] ?? '') ?>]</h2>
                        <span class="badge badge-lt"><?= count($validSuggestions) ?> lựa chọn khả thi</span>
                    </div>
                    <?php if (empty($validSuggestions)): ?>
                        <div class="alert alert-warning">
                            Hiện tại buổi học <strong><?= h($pickedLesson['code'] ?? '') ?></strong> không còn cặp (Khung giờ, Phòng) trống nào thỏa mãn điều kiện bắt buộc trên phương án này.<br>
                            <strong>Chẩn đoán:</strong> <?= $pickedLesson ? h($checker->diagnoseUnscheduledReason($pickedLesson, $activeAssignments, $dataset)) : '' ?>
                        </div>
                    <?php else: ?>
                        <div style="max-height: 360px; overflow-y: auto;">
                            <table>
                                <thead>
                                    <tr><th>Khung giờ</th><th>Phòng học</th><th>Điểm phạt mềm</th><th>Ghi chú ưu tiên</th><?php if ($isScheduler): ?><th>Chọn nhanh</th><?php endif; ?></tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($validSuggestions, 0, 15) as $sug): ?>
                                    <tr>
                                        <td><strong><?= h($sug['slot']['code']) ?></strong><br><small><?= h($sug['slot']['label']) ?></small></td>
                                        <td><strong><?= h($sug['room']['code']) ?></strong> (<?= h($sug['room']['room_type']) ?>, <?= (int)$sug['room']['capacity'] ?> chỗ)</td>
                                        <td><strong><?= (int)$sug['soft_penalty'] ?>đ</strong></td>
                                        <td><?= h($sug['soft_notes']) ?></td>
                                        <?php if ($isScheduler): ?>
                                        <td>
                                            <form method="post" action="index.php?tab=adjust&version_id=<?= (int)$activeVersion['id'] ?>&pick_lesson_id=<?= $pickedLessonId ?>">
                                                <input type="hidden" name="action" value="apply_manual_move">
                                                <input type="hidden" name="version_id" value="<?= (int)$activeVersion['id'] ?>">
                                                <input type="hidden" name="lesson_id" value="<?= $pickedLessonId ?>">
                                                <input type="hidden" name="target_slot_id" value="<?= (int)$sug['slot']['id'] ?>">
                                                <input type="hidden" name="target_room_id" value="<?= (int)$sug['room']['id'] ?>">
                                                <button type="submit" class="btn btn-primary btn-sm">Chuyển tới đây</button>
                                            </form>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($activeTab === 'compare'): ?>
            <!-- ==================== TAB 5: SO SÁNH PHƯƠNG ÁN & XUẤT LỊCH ==================== -->
            <?php
            $verIds = array_column($versions, 'id');
            $defaultA = (int)($verIds[count($verIds) - 1] ?? 0);
            $defaultB = (int)($verIds[0] ?? 0);
            foreach ($versions as $v) {
                if ($v['algorithm_type'] === 'BASIC') {
                    $defaultA = (int)$v['id'];
                    break;
                }
            }
            foreach ($versions as $v) {
                if ($v['algorithm_type'] === 'IMPROVED') {
                    $defaultB = (int)$v['id'];
                    break;
                }
            }

            $compareIdA = isset($_GET['compare_a']) ? (int)$_GET['compare_a'] : $defaultA;
            $compareIdB = isset($_GET['compare_b']) ? (int)$_GET['compare_b'] : $defaultB;

            $verA = $repo->getScheduleVersionById($compareIdA) ?? ($versions[0] ?? null);
            $verB = $repo->getScheduleVersionById($compareIdB) ?? ($versions[0] ?? null);

            $comparison = null;
            if ($verA && $verB) {
                $assignA = $repo->getAssignmentsByVersion((int)$verA['id']);
                $assignB = $repo->getAssignmentsByVersion((int)$verB['id']);
                $snapshotDataset = !empty($verB['constraint_snapshot_json'])
                    ? (json_decode($verB['constraint_snapshot_json'], true) ?: $dataset)
                    : $dataset;
                $comparison = $engine->compareTwoVersions($verA, $assignA, $verB, $assignB, $snapshotDataset);
            }
            ?>

            <div class="card">
                <div class="card-header">
                    <h2>So sánh Hai Phương Án Thời Khóa Biểu Trên Cùng Dữ Liệu (Chức năng 5 & Yêu cầu kỹ thuật 2)</h2>
                    <form method="get" action="index.php" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                        <input type="hidden" name="tab" value="compare">
                        <label>Phương án A:</label>
                        <select name="compare_a" onchange="this.form.submit()" style="padding:6px; border-radius:6px; border:1px solid #cbd5e1;">
                            <?php foreach ($versions as $v): ?>
                                <option value="<?= (int)$v['id'] ?>" <?= ($verA && (int)$verA['id'] === (int)$v['id']) ? 'selected' : '' ?>>
                                    #<?= (int)$v['id'] ?>: <?= h($v['version_name']) ?> (<?= (int)$v['scheduled_count'] ?>/<?= (int)$v['total_lessons'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label>Phương án B:</label>
                        <select name="compare_b" onchange="this.form.submit()" style="padding:6px; border-radius:6px; border:1px solid #cbd5e1;">
                            <?php foreach ($versions as $v): ?>
                                <option value="<?= (int)$v['id'] ?>" <?= ($verB && (int)$verB['id'] === (int)$v['id']) ? 'selected' : '' ?>>
                                    #<?= (int)$v['id'] ?>: <?= h($v['version_name']) ?> (<?= (int)$v['scheduled_count'] ?>/<?= (int)$v['total_lessons'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>

                <?php if ($comparison):
                    $mA = $comparison['metrics_a'];
                    $mB = $comparison['metrics_b'];
                ?>
                <div class="alert <?= $comparison['same_dataset'] ? 'alert-success' : 'alert-warning' ?>">
                    <strong>Đánh giá tự động từ lịch thực tế:</strong> <?= h($comparison['recommendation']) ?><br>
                    • Đối chiếu mã băm bộ dữ liệu (Dataset Hash):
                    <strong><?= $comparison['same_dataset'] ? '✅ Cùng một bộ dữ liệu và điều kiện ràng buộc' : '⚠️ Khác phiên bản dữ liệu đầu vào' ?></strong>
                    • Kiểm chứng bảo toàn buổi học đã khóa:
                    <strong><?= $comparison['locked_preserved_all'] ? '✅ Giữ nguyên 100% các buổi đã khóa' : '❌ Có thay đổi buổi khóa' ?></strong>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Tiêu chí đánh giá (Tính trực tiếp từ lịch thực tế)</th>
                            <th>Phương án A: <?= h($mA['version_name']) ?> (<?= h($mA['algorithm_type']) ?>)</th>
                            <th>Phương án B: <?= h($mB['version_name']) ?> (<?= h($mB['algorithm_type']) ?>)</th>
                            <th>Chênh lệch (B so với A)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>1. Số buổi học xếp được / Tổng số buổi</strong></td>
                            <td><strong><?= $mA['scheduled_count'] ?> / <?= $mA['total_lessons'] ?> buổi</strong> (<?= round($mA['scheduled_count'] * 100 / max(1, $mA['total_lessons']), 1) ?>%)</td>
                            <td><strong style="color:#15803d;"><?= $mB['scheduled_count'] ?> / <?= $mB['total_lessons'] ?> buổi</strong> (<?= round($mB['scheduled_count'] * 100 / max(1, $mB['total_lessons']), 1) ?>%)</td>
                            <td>
                                <?php $diffSched = $mB['scheduled_count'] - $mA['scheduled_count']; ?>
                                <strong style="color: <?= $diffSched >= 0 ? '#15803d' : '#b91c1c' ?>;">
                                    <?= $diffSched > 0 ? "+{$diffSched} buổi" : ($diffSched === 0 ? "Bằng nhau" : "{$diffSched} buổi") ?>
                                </strong>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>2. Số buổi chưa xếp được</strong></td>
                            <td><?= $mA['unscheduled_count'] ?> buổi (<?= implode(', ', array_column($mA['unscheduled_lessons'], 'code')) ?: 'Không có' ?>)</td>
                            <td><?= $mB['unscheduled_count'] ?> buổi (<?= implode(', ', array_column($mB['unscheduled_lessons'], 'code')) ?: 'Không có' ?>)</td>
                            <td><?= $mB['unscheduled_count'] - $mA['unscheduled_count'] ?> buổi</td>
                        </tr>
                        <tr>
                            <td><strong>3. Số vi phạm điều kiện bắt buộc (Hard Violations)</strong></td>
                            <td><span class="badge badge-soft-prefer"><?= $mA['hard_violations'] ?> vi phạm</span></td>
                            <td><span class="badge badge-soft-prefer"><?= $mB['hard_violations'] ?> vi phạm</span></td>
                            <td>Đảm bảo 100% ràng buộc cứng</td>
                        </tr>
                        <tr>
                            <td><strong>4. Tổng điểm phạt mong muốn ưu tiên (Soft Penalty)</strong></td>
                            <td><strong><?= $mA['soft_penalty_score'] ?> điểm phạt</strong></td>
                            <td><strong style="color:#1d4ed8;"><?= $mB['soft_penalty_score'] ?> điểm phạt</strong></td>
                            <td>
                                <?php $diffSoft = $mB['soft_penalty_score'] - $mA['soft_penalty_score']; ?>
                                <strong style="color: <?= $diffSoft <= 0 ? '#15803d' : '#b45309' ?>;">
                                    <?= $diffSoft < 0 ? "Giảm " . abs($diffSoft) . " điểm phạt" : ($diffSoft === 0 ? "Bằng nhau" : "+{$diffSoft} điểm phạt") ?>
                                </strong>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h3 style="margin-top:18px; font-size:15px;">Bảng đối chiếu chi tiết từng buổi học giữa Phương án A và Phương án B:</h3>
                <div style="max-height: 420px; overflow-y: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Mã buổi</th>
                                <th>Học phần</th>
                                <th>Giảng viên & Lớp</th>
                                <th>YC Phòng</th>
                                <th>Phương án A (<?= h($mA['algorithm_type']) ?>)</th>
                                <th>Phương án B (<?= h($mB['algorithm_type']) ?>)</th>
                                <th>So sánh</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($comparison['lesson_rows'] as $row): ?>
                            <tr>
                                <td>
                                    <strong><?= h($row['code']) ?></strong>
                                    <?= $row['is_locked'] ? '<span class="badge badge-locked">🔒 Khóa</span>' : '' ?>
                                </td>
                                <td><?= h($row['subject_name']) ?></td>
                                <td><?= h($row['teacher_name']) ?> • <strong><?= h($row['class_code']) ?></strong> (<?= (int)$row['student_count'] ?> SV)</td>
                                <td><span class="badge badge-<?= strtolower(h($row['required_room_type'])) ?>"><?= h($row['required_room_type']) ?></span></td>
                                <td style="color: <?= $row['version_a_placement'] === 'CHƯA XẾP ĐƯỢC' ? '#b91c1c' : '#0f172a' ?>;">
                                    <strong><?= h($row['version_a_placement']) ?></strong>
                                    <?= $row['version_a_soft'] !== null ? " <small>({$row['version_a_soft']}đ phạt)</small>" : '' ?>
                                </td>
                                <td style="color: <?= $row['version_b_placement'] === 'CHƯA XẾP ĐƯỢC' ? '#b91c1c' : '#15803d' ?>;">
                                    <strong><?= h($row['version_b_placement']) ?></strong>
                                    <?= $row['version_b_soft'] !== null ? " <small>({$row['version_b_soft']}đ phạt)</small>" : '' ?>
                                </td>
                                <td>
                                    <?php if ($row['is_locked']): ?>
                                        <span class="badge badge-locked">🔒 Cố định như nhau</span>
                                    <?php elseif ($row['status_badge'] === 'GAINED_IN_B'): ?>
                                        <span class="badge badge-soft-prefer">✨ B xếp thêm được</span>
                                    <?php elseif ($row['status_badge'] === 'LOST_IN_B'): ?>
                                        <span class="badge badge-hard">B chưa xếp</span>
                                    <?php elseif ($row['status_badge'] === 'SAME'): ?>
                                        <span style="color:#64748b;">Giống nhau</span>
                                    <?php else: ?>
                                        <span class="badge badge-lt">Đổi vị trí tối ưu hơn</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Xuất lịch theo Lớp học hoặc Giảng viên -->
            <div class="card">
                <div class="card-header">
                    <h2>Xuất Thời Khóa Biểu Theo Lớp Học Hoặc Giảng Viên (CSV / JSON / Bản In)</h2>
                </div>
                <form method="get" action="index.php" target="_blank">
                    <input type="hidden" name="action" value="export">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Chọn phương án cần xuất</label>
                            <select name="version_id">
                                <?php foreach ($versions as $v): ?>
                                    <option value="<?= (int)$v['id'] ?>" <?= ($activeVersion && (int)$activeVersion['id'] === (int)$v['id']) ? 'selected' : '' ?>>
                                        #<?= (int)$v['id'] ?>: <?= h($v['version_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Loại đối tượng xuất lịch</label>
                            <select name="filter_type" id="export_filter_type" onchange="syncExportTarget()">
                                <option value="class" <?= $isStudent ? 'selected' : '' ?>>Theo Lớp học</option>
                                <option value="teacher" <?= $isTeacher ? 'selected' : '' ?>>Theo Giảng viên</option>
                                <option value="all">Toàn bộ các lớp & giảng viên</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Chọn Lớp học / Giảng viên cụ thể</label>
                            <select name="filter_id" id="export_filter_id">
                                <?php foreach ($dataset['classes'] as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" data-type="class" <?= ($isStudent && (int)($currentUser['class_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>>
                                        Lớp: <?= h($c['code'] . ' - ' . $c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php foreach ($dataset['teachers'] as $t): ?>
                                    <option value="<?= (int)$t['id'] ?>" data-type="teacher" style="display:none;" <?= ($isTeacher && (int)($currentUser['teacher_id'] ?? 0) === (int)$t['id']) ? 'selected' : '' ?>>
                                        GV: <?= h($t['code'] . ' - ' . $t['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Định dạng xuất</label>
                            <select name="format">
                                <option value="print">🖨️ Bản in Thời khóa biểu (HTML / PDF)</option>
                                <option value="csv">📥 Tệp bảng tính Excel (.CSV UTF-8)</option>
                                <option value="json">📄 Dữ liệu tích hợp (.JSON)</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">📤 Xuất Thời Khóa Biểu</button>
                </form>
            </div>

            <script>
            function syncExportTarget() {
                const type = document.getElementById('export_filter_type').value;
                const sel = document.getElementById('export_filter_id');
                let firstVisible = null;
                let hasSelectedVisible = false;
                for (const opt of sel.options) {
                    const optType = opt.getAttribute('data-type');
                    if (type === 'all' || optType === type) {
                        opt.style.display = '';
                        if (!firstVisible) firstVisible = opt.value;
                        if (opt.selected) hasSelectedVisible = true;
                    } else {
                        opt.style.display = 'none';
                    }
                }
                if (!hasSelectedVisible && firstVisible) sel.value = firstVisible;
            }
            syncExportTarget();
            </script>
        <?php endif; ?>
    </main>
    <?php if ($isScheduler): ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

</body>
</html>
