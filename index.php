<?php
declare(strict_types=1);

// Chuyển hướng hoặc nạp trực tiếp public/index.php nếu chạy từ thư mục gốc
chdir(__DIR__ . '/public');
require __DIR__ . '/public/index.php';
