<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/src/Domain/ConstraintChecker.php';
require_once dirname(__DIR__) . '/src/Domain/SchedulerEngine.php';
require_once dirname(__DIR__) . '/src/Repository/TimetableRepository.php';

use App\Config\Database;
use App\Domain\ConstraintChecker;
use App\Domain\SchedulerEngine;
use App\Repository\TimetableRepository;

echo "=== KHỞI TẠO CƠ SỞ DỮ LIỆU & DỮ LIỆU MẪU ĐỀ TÀI 18 ===\n";
Database::resetDatabase();
$pdo = Database::getConnection();
$info = Database::getConnectionInfo();
echo "Kết nối thành công: {$info['label']} | Database: {$info['database']}\n";

$repo = new TimetableRepository($pdo);
$checker = new ConstraintChecker();
$engine = new SchedulerEngine($checker);

$dataset = $repo->loadFullDataset();
echo sprintf(
    "Đã nạp dữ liệu mẫu: %d Giảng viên, %d Lớp học, %d Phòng học, %d Khung giờ, %d Buổi học (%d mục lịch bận/ưu tiên).\n",
    count($dataset['teachers']),
    count($dataset['classes']),
    count($dataset['rooms']),
    count($dataset['slots']),
    count($dataset['lessons']),
    count($dataset['unavailability'])
);

$resBasic = $engine->scheduleBasic($dataset);
$idBasic = $repo->saveScheduleVersion(
    'Phương án 1 - Cách xếp Cơ bản (First-Fit)',
    'Chạy tuần tự từng buổi học trên bộ dữ liệu gốc (20 buổi, 3 buổi khóa)',
    $resBasic,
    $dataset,
    false
);
echo "Đã tạo Phương án #{$idBasic} (BASIC): Xếp được {$resBasic['scheduled_count']}/{$resBasic['total_lessons']} buổi | Chưa xếp: {$resBasic['unscheduled_count']} | Phạt ưu tiên: {$resBasic['soft_penalty_score']}đ\n";

$resImproved = $engine->scheduleImproved($dataset);
$idImproved = $repo->saveScheduleVersion(
    'Phương án 2 - Cách xếp Cải thiện (MRV + Best-Fit)',
    'Ưu tiên buổi khó (MRV), chọn phòng sát sĩ số và tối ưu điểm phạt mềm trên cùng dữ liệu gốc',
    $resImproved,
    $dataset,
    true
);
echo "Đã tạo Phương án #{$idImproved} (IMPROVED): Xếp được {$resImproved['scheduled_count']}/{$resImproved['total_lessons']} buổi | Chưa xếp: {$resImproved['unscheduled_count']} | Phạt ưu tiên: {$resImproved['soft_penalty_score']}đ\n";
echo "=== HOÀN TẤT KHỞI TẠO ===\n";
