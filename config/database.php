<?php
declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Quản lý kết nối MySQL (PDO) và tự động khởi tạo CSDL mẫu khi chạy lần đầu.
 */
class Database
{
    private static ?PDO $pdo = null;
    private static array $connectionInfo = [];

    /**
     * Đọc biến môi trường từ tệp .env (nếu tồn tại)
     */
    public static function loadEnv(string $envFile): array
    {
        $vars = [];
        if (is_file($envFile) && is_readable($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $val = trim($parts[1], " \t\n\r\0\x0B\"'");
                    $vars[$key] = $val;
                }
            }
        }
        return $vars;
    }

    /**
     * Trả về đối tượng kết nối PDO tới MySQL.
     * Hỗ trợ kết nối MySQL hệ thống (cổng 3306) hoặc MySQL cục bộ không cần quyền root (cổng 3307).
     */
    public static function getConnection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $rootDir = dirname(__DIR__);
        $env = self::loadEnv($rootDir . '/.env');

        $host = getenv('DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
        $port = getenv('DB_PORT') ?: ($env['DB_PORT'] ?? '3306');
        $dbName = getenv('DB_DATABASE') ?: ($env['DB_DATABASE'] ?? 'timetable_db');
        $user = getenv('DB_USERNAME') ?: ($env['DB_USERNAME'] ?? 'root');
        $pass = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : ($env['DB_PASSWORD'] ?? '');
        $socket = getenv('DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');

        $candidates = [
            ['host' => $host, 'port' => $port, 'user' => $user, 'pass' => $pass, 'socket' => $socket, 'label' => "MySQL ($host:$port)"],
            ['host' => '127.0.0.1', 'port' => '3307', 'user' => 'root', 'pass' => '', 'socket' => '/tmp/mysql_btl18/run/mysqld.sock', 'label' => 'MySQL Local Instance (127.0.0.1:3307)'],
        ];

        $lastError = null;
        foreach ($candidates as $cfg) {
            try {
                $pdo = self::connectAndEnsureDatabase($cfg, $dbName);
                self::$pdo = $pdo;
                self::$connectionInfo = [
                    'driver' => 'mysql',
                    'host' => $cfg['host'],
                    'port' => $cfg['port'],
                    'database' => $dbName,
                    'label' => $cfg['label'],
                ];
                self::ensureSchemaInitialized($pdo, $rootDir);
                return self::$pdo;
            } catch (PDOException $e) {
                $lastError = $e;
            }
        }

        // Nếu cả 2 cổng chưa kết nối được, thử tự động khởi chạy MySQL user-space (3307)
        self::tryStartLocalMysqlDaemon($rootDir);
        try {
            $fallbackCfg = $candidates[1];
            $pdo = self::connectAndEnsureDatabase($fallbackCfg, $dbName);
            self::$pdo = $pdo;
            self::$connectionInfo = [
                'driver' => 'mysql',
                'host' => $fallbackCfg['host'],
                'port' => $fallbackCfg['port'],
                'database' => $dbName,
                'label' => $fallbackCfg['label'],
            ];
            self::ensureSchemaInitialized($pdo, $rootDir);
            return self::$pdo;
        } catch (PDOException $e) {
            $msg = $lastError ? $lastError->getMessage() : $e->getMessage();
            throw new RuntimeException(
                "Không thể kết nối CSDL MySQL. Vui lòng kiểm tra tệp .env hoặc chạy scripts/start_local_mysql.sh. Chi tiết: " . $msg
            );
        }
    }

    private static function connectAndEnsureDatabase(array $cfg, string $dbName): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if (!empty($cfg['socket']) && file_exists($cfg['socket'])) {
            $dsnNoDb = "mysql:unix_socket={$cfg['socket']};charset=utf8mb4";
        } else {
            $dsnNoDb = "mysql:host={$cfg['host']};port={$cfg['port']};charset=utf8mb4";
        }

        $pdo = new PDO($dsnNoDb, $cfg['user'], $cfg['pass'], $options);
        $safeDb = preg_replace('/[^a-zA-Z0-9_]/', '', $dbName);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$safeDb}`");
        return $pdo;
    }

    private static function ensureSchemaInitialized(PDO $pdo, string $rootDir): void
    {
        $needsInit = false;
        try {
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'classes'");
            if ($tableCheck === false || $tableCheck->fetch() === false) {
                $needsInit = true;
            } else {
                $colCheck = $pdo->query("SHOW COLUMNS FROM `classes` LIKE 'cohort'");
                $facCheck = $pdo->query("SHOW TABLES LIKE 'faculties'");
                if ($colCheck === false || $colCheck->fetch() === false || $facCheck === false || $facCheck->fetch() === false) {
                    $needsInit = true;
                }
            }
        } catch (PDOException) {
            $needsInit = true;
        }

        if ($needsInit) {
            self::executeSqlFile($pdo, $rootDir . '/database/schema.sql');
            self::executeSqlFile($pdo, $rootDir . '/database/seed.sql');
        }
    }

    public static function resetDatabase(): void
    {
        $pdo = self::getConnection();
        $rootDir = dirname(__DIR__);
        self::executeSqlFile($pdo, $rootDir . '/database/schema.sql');
        self::executeSqlFile($pdo, $rootDir . '/database/seed.sql');
    }

    public static function executeSqlFile(PDO $pdo, string $filePath): void
    {
        if (!is_file($filePath)) {
            throw new RuntimeException("Không tìm thấy tệp SQL: {$filePath}");
        }
        $sql = file_get_contents($filePath);
        if ($sql === false) {
            throw new RuntimeException("Không thể đọc tệp SQL: {$filePath}");
        }

        // Loại bỏ các dòng comment SQL và tách theo dấu chấm phẩy
        $lines = explode("\n", $sql);
        $cleanLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $cleanLines[] = $line;
        }
        $cleanSql = implode("\n", $cleanLines);
        $statements = explode(';', $cleanSql);
        foreach ($statements as $statement) {
            $stmt = trim($statement);
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }
    }

    private static function tryStartLocalMysqlDaemon(string $rootDir): void
    {
        $script = $rootDir . '/scripts/start_local_mysql.sh';
        if (is_file($script)) {
            @shell_exec('bash ' . escapeshellarg($script) . ' >/dev/null 2>&1');
        }
    }

    public static function getConnectionInfo(): array
    {
        if (self::$pdo === null) {
            self::getConnection();
        }
        return self::$connectionInfo;
    }
}
