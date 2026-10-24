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

$pdo = Database::getConnection();
$connInfo = Database::getConnectionInfo();
$repo = new TimetableRepository($pdo);
$checker = new ConstraintChecker();
$engine = new SchedulerEngine($checker);
$controller = new TimetableController($repo, $checker, $engine);

$controller->ensureInitialVersionsExist();
$flash = $controller->handleRequest();

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
if (!in_array($activeTab, ['data', 'validate', 'schedule', 'adjust', 'compare'], true)) {
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
    <title><?= !$isAuthenticated ? 'Đăng nhập hệ thống' : 'Đề tài 18 - Hệ thống Xếp Thời Khóa Biểu Theo Ràng Buộc (Nhóm 11)' ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="<?= !$isAuthenticated ? 'login-page-body' : '' ?>">

<?php if (!$isAuthenticated): ?>
    <!-- ==================== GIAO DIỆN ĐĂNG NHẬP CHUẨN CỔNG ĐẠI NAM UNIVERSITY ==================== -->
    <?php
    $selectedLoginRole = (string)($_POST['login_role'] ?? 'student');
    ?>
    <div class="dnu-login-card">
        <!-- Logo Đại Nam University -->
        <div class="dnu-logo-wrap">
            <svg width="56" height="56" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" style="display:block; margin:0 auto;">
                <g fill="#f37021">
                    <?php for ($deg = 0; $deg < 360; $deg += 22.5): ?>
                        <g transform="rotate(<?= $deg ?> 50 50)">
                            <polygon points="48.2,34 51.8,34 50.6,12 49.4,12" />
                            <circle cx="50" cy="9" r="2.2" />
                        </g>
                    <?php endfor; ?>
                    <circle cx="50" cy="50" r="16" />
                    <path d="M40,53 Q45,49 50,53 Q55,49 60,53 L60,47 Q55,43 50,47 Q45,43 40,47 Z" fill="#ffffff"/>
                </g>
            </svg>
            <div class="dnu-logo-title">ĐẠI NAM</div>
            <div class="dnu-logo-sub">U N I V E R S I T Y</div>
        </div>

        <?php if ($flash['message'] !== ''): ?>
            <div class="alert alert-<?= h($flash['type'] ?: 'error') ?>" style="text-align:left; padding:9px 12px; font-size:12.5px; margin-bottom:14px;">
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
            <h1 class="dnu-heading">Đăng nhập tài khoản</h1>

            <form method="post" action="index.php" id="dnu_login_form">
                <input type="hidden" name="action" value="login">

                <!-- 1. Chọn nhóm phân quyền (Sinh Viên / Giảng Viên / Phụ trách Đào tạo) -->
                <div class="dnu-input-group">
                    <span class="dnu-input-addon" title="Phân quyền người dùng">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="#f37021" xmlns="http://www.w3.org/2000/svg">
                            <path d="M8.354 1.146a.5.5 0 0 0-.708 0l-6 6A.5.5 0 0 0 1.5 7.5v7a.5.5 0 0 0 .5.5h4.5a.5.5 0 0 0 .5-.5v-4h2v4a.5.5 0 0 0 .5.5H14a.5.5 0 0 0 .5-.5v-7a.5.5 0 0 0-.146-.354L13 5.793V2.5a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5v1.293L8.354 1.146z"/>
                        </svg>
                    </span>
                    <select name="login_role" id="login_role" class="dnu-form-control" onchange="updateForgotLabel()">
                        <option value="student" <?= $selectedLoginRole === 'student' ? 'selected' : '' ?>>Sinh Viên</option>
                        <option value="teacher" <?= $selectedLoginRole === 'teacher' ? 'selected' : '' ?>>Giảng Viên</option>
                        <option value="scheduler" <?= $selectedLoginRole === 'scheduler' ? 'selected' : '' ?>>Phụ trách Đào tạo (Người xếp lịch)</option>
                    </select>
                </div>

                <!-- 2. Tài khoản -->
                <div class="dnu-input-group">
                    <span class="dnu-input-addon" title="Tài khoản">
                        <svg width="15" height="15" viewBox="0 0 16 16" fill="#007bff" xmlns="http://www.w3.org/2000/svg">
                            <path d="M3 14s-1 0-1-1 1-4 6-4 6 3 6 4-1 1-1 1H3zm5-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/>
                        </svg>
                    </span>
                    <input type="text" name="username" id="login_username" class="dnu-form-control"
                           placeholder="Tài khoản" value="<?= h($_POST['username'] ?? '') ?>" required>
                </div>

                <!-- 3. Mật khẩu + Nút con mắt ẩn/hiện -->
                <div class="dnu-input-group has-right-addon">
                    <span class="dnu-input-addon" title="Mật khẩu">
                        <svg width="15" height="15" viewBox="0 0 16 16" fill="#6c757d" xmlns="http://www.w3.org/2000/svg">
                            <path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2zm3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/>
                        </svg>
                    </span>
                    <input type="password" name="password" id="login_password" class="dnu-form-control"
                           placeholder="Mật khẩu" required>
                    <button type="button" class="dnu-input-addon-right" onclick="togglePasswordVisibility()" title="Hiện/Ẩn mật khẩu">
                        <svg id="eye_icon" width="15" height="15" viewBox="0 0 16 16" fill="#495057" xmlns="http://www.w3.org/2000/svg">
                            <path d="M10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0z"/>
                            <path d="M0 8s3-5.5 8-5.5S16 8 16 8s-3 5.5-8 5.5S0 8 0 8zm8 3.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/>
                        </svg>
                    </button>
                </div>

                <div class="dnu-links-row">
                    <a href="javascript:void(0)" id="forgot_pw_link" onclick="toggleDemoHint()">[ Quên mật khẩu sinh viên ]</a>
                </div>

                <div class="dnu-btn-row">
                    <button type="submit" class="dnu-btn dnu-btn-login">
                        <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
                            <path fill-rule="evenodd" d="M6 3.5a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v9a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-2a.5.5 0 0 0-1 0v2A1.5 1.5 0 0 0 6.5 14h8a1.5 1.5 0 0 0 1.5-1.5v-9A1.5 1.5 0 0 0 14.5 2h-8A1.5 1.5 0 0 0 5 3.5v2a.5.5 0 0 0 1 0v-2z"/>
                            <path fill-rule="evenodd" d="M11.854 8.354a.5.5 0 0 0 0-.708l-3-3a.5.5 0 1 0-.708.708L10.293 7.5H1.5a.5.5 0 0 0 0 1h8.793l-2.147 2.146a.5.5 0 0 0 .708.708l3-3z"/>
                        </svg>
                        Đăng nhập
                    </button>
                    <a href="index.php" class="dnu-btn dnu-btn-home" onclick="toggleDemoHint(); return false;">
                        <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
                            <path d="M8.354 1.146a.5.5 0 0 0-.708 0l-6 6A.5.5 0 0 0 1.5 7.5v7a.5.5 0 0 0 .5.5h4.5a.5.5 0 0 0 .5-.5v-4h2v4a.5.5 0 0 0 .5.5H14a.5.5 0 0 0 .5-.5v-7a.5.5 0 0 0-.146-.354L13 5.793V2.5a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5v1.293L8.354 1.146z"/>
                        </svg>
                        Trang chủ
                    </a>
                </div>
            </form>

            <!-- Gợi ý điền nhanh tài khoản nhà trường cấp sẵn (ẩn/hiện khi bấm Quên mật khẩu hoặc Trang chủ) -->
            <div class="dnu-demo-hint" id="demo_hint_box" style="display:none;">
                <div><strong>Tài khoản do Nhà trường cấp sẵn (Mật khẩu: <code>123456</code>):</strong></div>
                <div style="margin-top:4px;">
                    <span class="dnu-demo-chip" onclick="fillDemoLogin('student', 'sv01', '123456')">🎓 SV Lớp 18-12 (sv01)</span>
                    <span class="dnu-demo-chip" onclick="fillDemoLogin('student', 'sv03', '123456')">🎓 SV Lớp 19-09 (sv03)</span>
                    <span class="dnu-demo-chip" onclick="fillDemoLogin('teacher', 'gv01', '123456')">👨‍🏫 Giảng viên 01 (gv01)</span>
                    <span class="dnu-demo-chip" onclick="fillDemoLogin('scheduler', 'admin', '123456')">⚙️ Đào tạo / Xếp lịch (admin)</span>
                </div>
            </div>
        </div>
    </div>

    <script>
    function togglePasswordVisibility() {
        const pwInput = document.getElementById('login_password');
        pwInput.type = (pwInput.type === 'password') ? 'text' : 'password';
    }
    function updateForgotLabel() {
        const role = document.getElementById('login_role').value;
        const link = document.getElementById('forgot_pw_link');
        if (role === 'student') {
            link.textContent = '[ Quên mật khẩu sinh viên ]';
        } else if (role === 'teacher') {
            link.textContent = '[ Quên mật khẩu giảng viên ]';
        } else {
            link.textContent = '[ Quên mật khẩu cán bộ ]';
        }
    }
    function fillDemoLogin(role, username, password) {
        document.getElementById('login_role').value = role;
        document.getElementById('login_username').value = username;
        document.getElementById('login_password').value = password;
        updateForgotLabel();
    }
    function toggleDemoHint() {
        const box = document.getElementById('demo_hint_box');
        box.style.display = (box.style.display === 'none') ? 'block' : 'none';
    }
    updateForgotLabel();
    </script>

<?php else: ?>
<?php
$allUsers = $dataset['users'] ?? [];
$studentAccounts = array_values(array_filter($allUsers, static fn(array $u): bool => ($u['role'] ?? '') === 'student'));
$activeSec = (string)($_GET['sec'] ?? '');
?>

<?php if ($isScheduler): ?>
<!-- ==================== BỐ CỤC BANNER DỌC QUẢN TRỊ ĐÀO TẠO (ADMIN VERTICAL BANNER) ==================== -->
<div class="admin-layout-wrapper">
    <aside class="admin-vertical-banner">
        <div>
            <div class="admin-vbanner-brand">
                <div class="admin-vbanner-logo">🏛️</div>
                <div class="admin-vbanner-title">QUẢN TRỊ ĐÀO TẠO</div>
            </div>

            <nav class="admin-vbanner-list">
                <a href="index.php?tab=schedule"
                   class="admin-vbanner-item <?= $activeTab === 'schedule' ? 'active' : '' ?>">
                    <span class="admin-vbanner-icon">📅</span>
                    <span>Thời khóa biểu</span>
                </a>
                <a href="index.php?tab=data&sec=classes"
                   class="admin-vbanner-item <?= ($activeTab === 'data' && $activeSec === 'classes') ? 'active' : '' ?>">
                    <span class="admin-vbanner-icon">🏫</span>
                    <span>Lớp học</span>
                </a>
                <a href="index.php?tab=data&sec=siso"
                   class="admin-vbanner-item <?= ($activeTab === 'data' && $activeSec === 'siso') ? 'active' : '' ?>">
                    <span class="admin-vbanner-icon">👥</span>
                    <span>Sĩ số</span>
                </a>
                <a href="index.php?tab=data&sec=faculties"
                   class="admin-vbanner-item <?= ($activeTab === 'data' && $activeSec === 'faculties') ? 'active' : '' ?>">
                    <span class="admin-vbanner-icon">🏛️</span>
                    <span>Khoa</span>
                </a>
                <a href="index.php?tab=data&sec=lessons"
                   class="admin-vbanner-item <?= ($activeTab === 'data' && ($activeSec === 'lessons' || $activeSec === '')) ? 'active' : '' ?>">
                    <span class="admin-vbanner-icon">📚</span>
                    <span>Môn học</span>
                </a>
                <a href="index.php?tab=data&sec=students"
                   class="admin-vbanner-item <?= ($activeTab === 'data' && $activeSec === 'students') ? 'active' : '' ?>">
                    <span class="admin-vbanner-icon">🎓</span>
                    <span>Mã sinh viên</span>
                </a>
                <a href="index.php?tab=data&sec=teachers"
                   class="admin-vbanner-item <?= ($activeTab === 'data' && $activeSec === 'teachers') ? 'active' : '' ?>">
                    <span class="admin-vbanner-icon">👨‍🏫</span>
                    <span>Giảng viên</span>
                </a>
                <a href="index.php?tab=data&sec=unavailability"
                   class="admin-vbanner-item <?= ($activeTab === 'data' && $activeSec === 'unavailability') ? 'active' : '' ?>">
                    <span class="admin-vbanner-icon">🗓️</span>
                    <span>Lịch bận</span>
                </a>
                <a href="index.php?tab=data&sec=rooms"
                   class="admin-vbanner-item <?= ($activeTab === 'data' && $activeSec === 'rooms') ? 'active' : '' ?>">
                    <span class="admin-vbanner-icon">🏢</span>
                    <span>Phòng học</span>
                </a>
                <a href="index.php?tab=data&sec=slots"
                   class="admin-vbanner-item <?= ($activeTab === 'data' && $activeSec === 'slots') ? 'active' : '' ?>">
                    <span class="admin-vbanner-icon">🕒</span>
                    <span>Khung giờ</span>
                </a>
                <a href="index.php?tab=validate"
                   class="admin-vbanner-item <?= $activeTab === 'validate' ? 'active' : '' ?>">
                    <span class="admin-vbanner-icon">🛡️</span>
                    <span>Ràng buộc</span>
                </a>
                <a href="index.php?tab=adjust"
                   class="admin-vbanner-item <?= $activeTab === 'adjust' ? 'active' : '' ?>">
                    <span class="admin-vbanner-icon">🔧</span>
                    <span>Đổi lịch</span>
                </a>
                <a href="index.php?tab=compare"
                   class="admin-vbanner-item <?= $activeTab === 'compare' ? 'active' : '' ?>">
                    <span class="admin-vbanner-icon">📊</span>
                    <span>So sánh &amp; Xuất lịch</span>
                </a>
            </nav>
        </div>

        <div class="admin-vbanner-footer">
            <div class="admin-vbanner-user">
                👤 <strong><?= h($currentUser['full_name']) ?></strong>
            </div>
            <a href="index.php?action=logout" class="btn btn-danger btn-sm" style="width:100%;">🚪 Đăng xuất</a>
        </div>
    </aside>

    <main class="admin-main-content">
<?php else: ?>
<header class="topbar">
    <div class="topbar-brand">
        <h1><?= $isStudent ? 'CỔNG THÔNG TIN ĐÀO TẠO — TRA CỨU THỜI KHÓA BIỂU SINH VIÊN' : 'HỆ THỐNG TRA CỨU LỊCH GIẢNG DẠY & ĐĂNG KÝ LỊCH BẬN GIẢNG VIÊN' ?></h1>
        <p>
            <?php if ($isStudent): ?>
                Lớp hành chính: <strong><?= h($currentUser['class_code'] ?? '') ?></strong> • Mã sinh viên: <strong><?= h($currentUser['username'] ?? '') ?></strong>
            <?php else: ?>
                Giảng viên: <strong><?= h($currentUser['full_name'] ?? '') ?></strong> (Mã GV: <strong><?= h($currentUser['teacher_code'] ?? '') ?></strong>)
            <?php endif; ?>
        </p>
    </div>

    <div class="topbar-controls">
        <div class="role-badge">
            <span>👤 <strong><?= h($currentUser['full_name']) ?></strong> (<code><?= h($currentUser['username']) ?></code>)</span>
            <span class="badge <?= $isTeacher ? 'badge-th' : 'badge-lt' ?>">
                <?= h($controller->getRoleLabel((string)$currentUser['role'])) ?>
                <?php if ($isTeacher && !empty($currentUser['teacher_code'])): ?>
                    — <?= h($currentUser['teacher_code']) ?>
                <?php elseif ($isStudent && !empty($currentUser['class_code'])): ?>
                    — <?= h($currentUser['class_code']) ?>
                <?php endif; ?>
            </span>
        </div>
        <a href="index.php?action=logout" class="btn btn-danger btn-sm">🚪 Đăng xuất</a>
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
        <?php if ($isTeacher): ?>
        <!-- Thanh điều hướng gọn cho Giảng viên (Admin dùng Banner tổng ở trên; Sinh viên chỉ xem Thời Khóa Biểu) -->
        <nav class="nav-tabs" style="margin-bottom: 14px;">
            <a href="index.php?tab=schedule" class="nav-tab <?= $activeTab === 'schedule' ? 'active' : '' ?>">
                📅 1. Xem Thời Khóa Biểu Giảng Dạy
            </a>
            <a href="index.php?tab=data#sec-teachers" class="nav-tab <?= $activeTab === 'data' ? 'active' : '' ?>">
                🗓️ 2. Đăng ký Lịch bận &amp; Mong muốn ưu tiên cá nhân
            </a>
            <a href="index.php?tab=compare" class="nav-tab <?= $activeTab === 'compare' ? 'active' : '' ?>">
                📤 3. Xuất Lịch Giảng Dạy
            </a>
        </nav>
        <?php endif; ?>

        <?php if ($activeTab === 'data'): ?>
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
                                    <td>Thứ <?= (int)$s['day_of_week'] ?></td>
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
                            <input type="email" name="email" id="uf_email" placeholder="sv@dainam.edu.vn">
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
            <!-- ==================== GIAO DIỆN LỊCH HỌC CHUẨN CỔNG SINH VIÊN ĐẠI NAM ==================== -->
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
            $hocKy = (string)($_GET['hoc_ky'] ?? '1');
            $namHoc = (string)($_GET['nam_hoc'] ?? '2026-2027');

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
                1 => 'Tuần 12 [Từ 19/10/2026 -- Đến 25/10/2026]',
                2 => 'Tuần 13 [Từ 26/10/2026 -- Đến 01/11/2026]',
            ];
            ?>
            <div class="dnu-schedule-box">
                <!-- 1. Thanh Breadcrumb: Trang chủ / Lịch học -->
                <div class="dnu-breadcrumb">
                    <div>
                        <a href="index.php">Trang chủ</a>
                        <span style="color:#ccc; margin:0 6px;">/</span>
                        <span style="color:#777;">Lịch học</span>
                    </div>
                    <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                        <?php if (!$isStudent): ?>
                            <form method="get" action="index.php" style="display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin:0;">
                                <input type="hidden" name="tab" value="schedule">
                                <input type="hidden" name="subview" value="<?= h($scheduleSubView) ?>">
                                <input type="hidden" name="week_no" value="<?= (int)$selectedWeekNo ?>">
                                <select name="version_id" onchange="this.form.submit()" style="height:30px; padding:2px 8px; border-radius:4px; border:1px solid #ccc; font-size:12px;">
                                    <?php foreach ($versions as $v): ?>
                                        <option value="<?= (int)$v['id'] ?>" <?= ($activeVersion && (int)$activeVersion['id'] === (int)$v['id']) ? 'selected' : '' ?>>
                                            #<?= (int)$v['id'] ?>: <?= h($v['version_name']) ?> (<?= (int)$v['scheduled_count'] ?>/<?= (int)$v['total_lessons'] ?> buổi)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="filter_type" onchange="this.form.submit();" style="height:30px; padding:2px 8px; border-radius:4px; border:1px solid #ccc; font-size:12px;">
                                    <option value="all" <?= $viewFilterType === 'all' ? 'selected' : '' ?>>Toàn trường</option>
                                    <option value="class" <?= $viewFilterType === 'class' ? 'selected' : '' ?>>Theo Lớp</option>
                                    <option value="teacher" <?= $viewFilterType === 'teacher' ? 'selected' : '' ?>>Theo Giảng viên</option>
                                    <option value="room" <?= $viewFilterType === 'room' ? 'selected' : '' ?>>Theo Phòng</option>
                                </select>
                                <?php if ($viewFilterType === 'class'): ?>
                                    <select name="filter_id" onchange="this.form.submit()" style="height:30px; padding:2px 8px; border-radius:4px; border:1px solid #ccc; font-size:12px;">
                                        <option value="0">-- Tất cả lớp --</option>
                                        <?php foreach ($dataset['classes'] as $c): ?>
                                            <option value="<?= (int)$c['id'] ?>" <?= $viewFilterId === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['code']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($viewFilterType === 'teacher'): ?>
                                    <select name="filter_id" onchange="this.form.submit()" style="height:30px; padding:2px 8px; border-radius:4px; border:1px solid #ccc; font-size:12px;">
                                        <option value="0">-- Tất cả GV --</option>
                                        <?php foreach ($dataset['teachers'] as $t): ?>
                                            <option value="<?= (int)$t['id'] ?>" <?= $viewFilterId === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['code'] . ' - ' . $t['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($viewFilterType === 'room'): ?>
                                    <select name="filter_id" onchange="this.form.submit()" style="height:30px; padding:2px 8px; border-radius:4px; border:1px solid #ccc; font-size:12px;">
                                        <option value="0">-- Tất cả phòng --</option>
                                        <?php foreach ($dataset['rooms'] as $r): ?>
                                            <option value="<?= (int)$r['id'] ?>" <?= $viewFilterId === (int)$r['id'] ? 'selected' : '' ?>><?= h($r['code']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <?php if ($activeVersion): ?>
                                    <a class="btn btn-outline btn-sm" target="_blank"
                                       href="index.php?action=export&format=print&version_id=<?= (int)$activeVersion['id'] ?>&filter_type=<?= h($viewFilterType) ?>&filter_id=<?= (int)$viewFilterId ?>">
                                       🖨️ Bản in
                                    </a>
                                    <a class="btn btn-outline btn-sm"
                                       href="index.php?action=export&format=csv&version_id=<?= (int)$activeVersion['id'] ?>&filter_type=<?= h($viewFilterType) ?>&filter_id=<?= (int)$viewFilterId ?>">
                                       📥 Xuất CSV
                                    </a>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 2. Hai tab: LỊCH HỌC TUẦN | LỊCH HỌC -->
                <div class="dnu-subtabs">
                    <a href="index.php?tab=schedule&subview=week&version_id=<?= (int)($activeVersion['id'] ?? 0) ?>&filter_type=<?= h($viewFilterType) ?>&filter_id=<?= (int)$viewFilterId ?>&week_no=<?= (int)$selectedWeekNo ?>"
                       class="dnu-subtab <?= $scheduleSubView !== 'list' ? 'active' : '' ?>">
                        LỊCH HỌC TUẦN
                    </a>
                    <a href="index.php?tab=schedule&subview=list&version_id=<?= (int)($activeVersion['id'] ?? 0) ?>&filter_type=<?= h($viewFilterType) ?>&filter_id=<?= (int)$viewFilterId ?>"
                       class="dnu-subtab <?= $scheduleSubView === 'list' ? 'active' : '' ?>">
                        LỊCH HỌC
                    </a>
                </div>

                <?php if ($scheduleSubView !== 'list'): ?>
                    <!-- 3. Hàng 4 ô chọn: Học kỳ | Năm học | Ngành học | Tuần -->
                    <form method="get" action="index.php">
                        <input type="hidden" name="tab" value="schedule">
                        <input type="hidden" name="subview" value="week">
                        <input type="hidden" name="version_id" value="<?= (int)($activeVersion['id'] ?? 0) ?>">
                        <input type="hidden" name="filter_type" value="<?= h($viewFilterType) ?>">
                        <input type="hidden" name="filter_id" value="<?= (int)$viewFilterId ?>">

                        <div class="dnu-filter-row">
                            <div class="dnu-filter-item">
                                <label>Học kỳ</label>
                                <select name="hoc_ky" onchange="this.form.submit()">
                                    <option value="1" <?= $hocKy === '1' ? 'selected' : '' ?>>Kỳ 1</option>
                                    <option value="2" <?= $hocKy === '2' ? 'selected' : '' ?>>Kỳ 2</option>
                                    <option value="3" <?= $hocKy === '3' ? 'selected' : '' ?>>Kỳ Hè</option>
                                </select>
                            </div>
                            <div class="dnu-filter-item">
                                <label>Năm học</label>
                                <select name="nam_hoc" onchange="this.form.submit()">
                                    <option value="2026-2027" <?= $namHoc === '2026-2027' ? 'selected' : '' ?>>2026-2027</option>
                                    <option value="2025-2026" <?= $namHoc === '2025-2026' ? 'selected' : '' ?>>2025-2026</option>
                                </select>
                            </div>
                            <div class="dnu-filter-item">
                                <label>Ngành học</label>
                                <select name="nganh_hoc">
                                    <option value="main">Chuyên ngành chính</option>
                                </select>
                            </div>
                            <div class="dnu-filter-item">
                                <label>Tuần</label>
                                <select name="week_no" onchange="this.form.submit()">
                                    <option value="1" <?= $selectedWeekNo === 1 ? 'selected' : '' ?>><?= h($weekSelectLabels[1]) ?></option>
                                    <option value="2" <?= $selectedWeekNo === 2 ? 'selected' : '' ?>><?= h($weekSelectLabels[2]) ?></option>
                                    <?php if (!$isStudent): ?>
                                        <option value="0" <?= $selectedWeekNo === 0 ? 'selected' : '' ?>>Hiển thị cả 2 tuần (Tuần 12 & Tuần 13)</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                    </form>

                    <!-- 4. Bảng Lịch Học Tuần (BUỔI | Thứ 2 .. Thứ 7) -->
                    <?php
                    $weeksToRender = $selectedWeekNo === 0 ? [1, 2] : [$selectedWeekNo];
                    foreach ($weeksToRender as $idxW => $w):
                    ?>
                        <?php if ($selectedWeekNo === 0): ?>
                            <div style="font-weight:700; font-size:13px; color:#333; margin: <?= $idxW > 0 ? '18px' : '0' ?> 0 6px;">
                                <?= h($weekSelectLabels[$w]) ?>
                            </div>
                        <?php endif; ?>
                        <div style="overflow-x:auto;">
                            <table class="dnu-tt-table">
                                <thead>
                                    <tr>
                                        <th>BUỔI</th>
                                        <th>THỨ 2</th>
                                        <th>THỨ 3</th>
                                        <th>THỨ 4</th>
                                        <th>THỨ 5</th>
                                        <th>THỨ 6</th>
                                        <th>THỨ 7</th>
                                        <th>CHỦ NHẬT(học lại)</th>
                                        <th>GHI CHÚ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $buoiLabels = [
                                        1 => "SÁNG<br>(4 Tiết)",
                                        2 => "CHIỀU<br>(4 Tiết)",
                                    ];
                                    for ($p = 1; $p <= 2; $p++):
                                    ?>
                                    <tr>
                                        <td class="dnu-buoi-col"><?= $buoiLabels[$p] ?></td>
                                        <?php for ($d = 2; $d <= 8; $d++):
                                            $cellItems = $grid[$w][$p][$d] ?? [];
                                        ?>
                                        <td>
                                            <?php foreach ($cellItems as $item):
                                                $isLocked = (int)$item['is_locked'] === 1 || (int)$item['current_lesson_locked'] === 1;
                                                $exactCohortTime = ConstraintChecker::formatCohortTime((string)$item['class_code'], (int)$item['period_of_day']);
                                                $tietText = ((int)$item['period_of_day'] === 1) ? '1 - 5' : '6 - 10';
                                                $dateRangeText = $weekRangeLabels[$w] ?? '19/10/2026 - 25/10/2026';
                                            ?>
                                            <div class="dnu-lesson-cell-item">
                                                <div><?= h($item['subject_name']) ?></div>
                                                <div>P.<?= h($item['room_code']) ?></div>
                                                <div>Tiết: <?= $tietText ?> (<?= h($exactCohortTime) ?>)</div>
                                                <div>(<?= h($dateRangeText) ?>)</div>
                                                <div>GV: <?= h($item['teacher_name']) ?></div>
                                                <?php if (!$isStudent): ?>
                                                    <div style="color:#1e40af; font-size:11.5px; margin-top:2px;">
                                                        Lớp: <?= h($item['class_code']) ?> (<?= (int)$item['student_count'] ?>/<?= (int)$item['room_capacity'] ?>)
                                                        <?= $isLocked ? '🔒' : '' ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($isScheduler && !$isLocked): ?>
                                                    <div style="margin-top:4px;">
                                                        <form method="post" action="index.php?tab=schedule&version_id=<?= (int)$activeVersion['id'] ?>&week_no=<?= (int)$selectedWeekNo ?>" style="display:inline;">
                                                            <input type="hidden" name="action" value="lock_from_version">
                                                            <input type="hidden" name="lesson_id" value="<?= (int)$item['lesson_id'] ?>">
                                                            <input type="hidden" name="slot_id" value="<?= (int)$item['slot_id'] ?>">
                                                            <input type="hidden" name="room_id" value="<?= (int)$item['room_id'] ?>">
                                                            <button type="submit" class="btn btn-outline btn-sm" style="padding:1px 6px; font-size:11px;">🔒 Khóa</button>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </td>
                                        <?php endfor; ?>
                                        <td class="dnu-note-col"></td>
                                    </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- Tab 2: LỊCH HỌC (Danh sách tổng hợp các buổi học trong học kỳ) -->
                    <div style="overflow-x:auto;">
                        <table class="dnu-tt-table">
                            <thead>
                                <tr>
                                    <th>STT</th>
                                    <th>MÔN HỌC / HỌC PHẦN</th>
                                    <th>LỚP HỌC</th>
                                    <th>THỨ & BUỔI</th>
                                    <th>TIẾT & GIỜ HỌC</th>
                                    <th>PHÒNG HỌC</th>
                                    <th>GIẢNG VIÊN</th>
                                    <th>THỜI GIAN</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($filteredList)): ?>
                                    <tr><td colspan="8" style="padding:18px; color:#777;">Chưa có lịch học nào.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($filteredList as $idx => $row):
                                        $wRow = (int)($row['week_no'] ?? 1);
                                        $pRow = (int)$row['period_of_day'];
                                        $exactTime = ConstraintChecker::formatCohortTime((string)$row['class_code'], $pRow);
                                        $tietStr = ($pRow === 1) ? 'Tiết 1 - 5' : 'Tiết 6 - 10';
                                        $buoiStr = ($pRow === 1) ? 'SÁNG' : 'CHIỀU';
                                    ?>
                                    <tr>
                                        <td><?= $idx + 1 ?></td>
                                        <td><?= h($row['subject_name']) ?></td>
                                        <td><?= h($row['class_code']) ?></td>
                                        <td>Thứ <?= (int)$row['day_of_week'] ?> (<?= $buoiStr ?>)</td>
                                        <td><?= $tietStr ?> (<?= h($exactTime) ?>)</td>
                                        <td>P.<?= h($row['room_code']) ?></td>
                                        <td><?= h($row['teacher_name']) ?></td>
                                        <td><?= h($weekRangeLabels[$wRow] ?? '19/10/2026 - 25/10/2026') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($isScheduler): ?>
            <!-- Bảng Điều khiển Chạy Thuật Toán & Hai Tình huống mẫu (nằm ngay dưới Bảng Thời Khóa Biểu) -->
            <div class="card">
                <div class="card-header">
                    <h2>⚙️ Chạy Thuật Toán Tạo Thời Khóa Biểu & Kịch bản Mẫu (Chức năng 3)</h2>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <form method="post" action="index.php?tab=schedule" style="display:inline;">
                            <input type="hidden" name="action" value="reset_seed">
                            <button type="submit" class="btn btn-outline btn-sm">🔄 Tình huống 1: Khôi phục dữ liệu gốc & Chạy 2 phương án</button>
                        </form>
                        <form method="post" action="index.php?tab=compare" style="display:inline;">
                            <input type="hidden" name="action" value="scenario2_quick_adjust">
                            <button type="submit" class="btn btn-primary btn-sm">⚡ Tình huống 2: Khóa thêm BH04 + Đổi yêu cầu BH20 & So sánh</button>
                        </form>
                    </div>
                </div>
                <form method="post" action="index.php?tab=schedule">
                    <input type="hidden" name="action" value="run_schedule">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Chọn phương pháp xếp lịch</label>
                            <select name="algorithm_type">
                                <option value="BOTH">⚡ Chạy đồng thời cả 2 cách xếp (Cơ bản & Cải thiện) trên cùng dữ liệu</option>
                                <option value="BASIC">1. Cách xếp Cơ bản (Sequential First-Fit Greedy)</option>
                                <option value="IMPROVED">2. Cách xếp Cải thiện (Dynamic MRV + Best-Fit + Swap Repair + Local Search)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Tên phương án lưu trữ (Tùy chọn)</label>
                            <input type="text" name="version_name" placeholder="Để trống sẽ tự động đặt tên kèm thời gian...">
                        </div>
                        <div class="form-group" style="display:flex; align-items:flex-end;">
                            <button type="submit" class="btn btn-primary" style="width:100%;">🚀 Thực thi xếp thời khóa biểu</button>
                        </div>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <?php if (!$isStudent): ?>
            <!-- Danh sách các buổi chưa xếp được trong phương án đang xét (Ẩn với Sinh viên) -->
            <div class="card">
                <div class="card-header">
                    <h2>
                        Danh sách Buổi học Chưa xếp được trong phương án này (<?= $activeAudit['unscheduled_count'] ?> buổi)
                    </h2>
                    <span class="badge <?= $activeAudit['unscheduled_count'] > 0 ? 'badge-soft-avoid' : 'badge-soft-prefer' ?>">
                        <?= $activeAudit['unscheduled_count'] > 0 ? 'Báo cáo rõ phần chưa xếp (Không kết luận vô nghiệm)' : 'Đã xếp đầy đủ 100%' ?>
                    </span>
                </div>

                <?php if (empty($activeAudit['unscheduled_lessons'])): ?>
                    <div class="alert alert-success">
                        🎉 Tất cả <?= $activeAudit['total_lessons'] ?>/<?= $activeAudit['total_lessons'] ?> buổi học đã được xếp lịch thành công mà không vi phạm bất kỳ điều kiện bắt buộc nào!
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Mã buổi</th>
                                <th>Tên học phần</th>
                                <th>Sĩ số & Loại phòng YC</th>
                                <th>Chẩn đoán nguyên nhân chưa xếp được (Từ lịch thực tế)</th>
                                <?php if ($isScheduler): ?><th>Thao tác xử lý</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeAudit['unscheduled_lessons'] as $uLesson): ?>
                            <tr>
                                <td><strong><?= h($uLesson['code']) ?></strong></td>
                                <td><?= h($uLesson['subject_name']) ?></td>
                                <td>
                                    <strong><?= (int)$uLesson['student_count'] ?> SV</strong> —
                                    <span class="badge badge-<?= strtolower(h($uLesson['required_room_type'])) ?>"><?= h($uLesson['required_room_type']) ?></span>
                                </td>
                                <td style="color:#9a3412;"><?= h($uLesson['reason']) ?></td>
                                <?php if ($isScheduler): ?>
                                <td>
                                    <a href="index.php?tab=adjust&version_id=<?= (int)$activeVersion['id'] ?>&pick_lesson_id=<?= (int)$uLesson['lesson_id'] ?>" class="btn btn-primary btn-sm">
                                        🔧 Thử xếp / Kiểm tra ảnh hưởng
                                    </a>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>

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
