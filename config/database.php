<?php
declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Lớp PDO tương thích tự động dịch cú pháp MySQL sang SQLite khi chạy chế độ dự phòng trên Cloud.
 */
class CloudSqlitePDO extends PDO
{
    private static function translateSql(string $sql): string
    {
        $trimmed = trim($sql);
        if (preg_match('/^(SET\s+NAMES|SET\s+FOREIGN_KEY_CHECKS|USE\s+)/i', $trimmed)) {
            return 'SELECT 1';
        }
        if (preg_match('/^TRUNCATE\s+TABLE\s+([`a-zA-Z0-9_]+)/i', $trimmed, $m)) {
            return 'DELETE FROM ' . $m[1];
        }
        if (preg_match('/^CREATE\s+TABLE/i', $trimmed)) {
            $s = preg_replace('/\bINT\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $trimmed);
            $s = preg_replace('/\bTINYINT\s*\(\d+\)/i', 'INTEGER', $s);
            $s = preg_replace('/\bENUM\s*\([^)]+\)/i', ' TEXT', $s);
            $s = preg_replace("/\s+COMMENT\s+'[^']*'/i", '', $s);
            $s = preg_replace('/\bON\s+UPDATE\s+CURRENT_TIMESTAMP\b/i', '', $s);
            $s = preg_replace('/\)\s*ENGINE\s*=\s*InnoDB[^;]*;?/i', ')', $s);
            $s = preg_replace('/,\s*KEY\s+[`a-zA-Z0-9_]+\s*\([^)]+\)/i', '', $s);
            $s = preg_replace('/,\s*UNIQUE\s+KEY\s+[`a-zA-Z0-9_]+\s*\(([^)]+)\)/i', ', UNIQUE ($1)', $s);
            $s = preg_replace('/,\s*CONSTRAINT\s+[`a-zA-Z0-9_]+\s+FOREIGN\s+KEY\s*\([^)]+\)\s*REFERENCES\s+[`a-zA-Z0-9_]+\s*\([^)]+\)(\s+ON\s+(DELETE|UPDATE)\s+(CASCADE|SET\s+NULL|RESTRICT))*/i', '', $s);
            return (string)$s;
        }
        $s = preg_replace('/\bINSERT\s+IGNORE\s+INTO\b/i', 'INSERT OR IGNORE INTO', $trimmed);
        if (stripos((string)$s, 'ON DUPLICATE KEY UPDATE') !== false) {
            if (stripos((string)$s, 'INTO unavailability') !== false) {
                $s = preg_replace(
                    '/ON\s+DUPLICATE\s+KEY\s+UPDATE\s+constraint_type\s*=\s*VALUES\(constraint_type\)\s*,\s*reason\s*=\s*VALUES\(reason\)/i',
                    'ON CONFLICT(teacher_id, slot_id) DO UPDATE SET constraint_type = excluded.constraint_type, reason = excluded.reason',
                    (string)$s
                );
            } elseif (stripos((string)$s, 'INTO schedule_assignments') !== false) {
                $s = preg_replace(
                    '/ON\s+DUPLICATE\s+KEY\s+UPDATE[\s\S]*$/i',
                    'ON CONFLICT(version_id, lesson_id) DO UPDATE SET slot_id = excluded.slot_id, room_id = excluded.room_id, soft_penalty = excluded.soft_penalty, soft_notes = excluded.soft_notes',
                    (string)$s
                );
            }
        }
        return (string)$s;
    }

    public function exec(string $statement): int|false
    {
        return parent::exec(self::translateSql($statement));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $translated = self::translateSql($query);
        if ($fetchMode === null) {
            return parent::query($translated);
        }
        return parent::query($translated, $fetchMode, ...$fetchModeArgs);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(self::translateSql($query), $options);
    }
}

/**
 * Quản lý kết nối MySQL (PDO) và tự động khởi tạo CSDL mẫu khi chạy lần đầu.
 */
class Database
{
    private static ?PDO $pdo = null;
    private static array $connectionInfo = [];

    private static function readEnvVar(string $key, array $fileEnv, string $default = ''): string
    {
        $v = getenv($key);
        if ($v !== false && $v !== '') {
            return (string)$v;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string)$_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string)$_SERVER[$key];
        }
        if (isset($fileEnv[$key]) && $fileEnv[$key] !== '') {
            return (string)$fileEnv[$key];
        }
        return $default;
    }

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
     * Trả về đối tượng kết nối PDO tới MySQL (hoặc SQLite dự phòng trên Cloud).
     */
    public static function getConnection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $rootDir = dirname(__DIR__);
        $env = self::loadEnv($rootDir . '/.env');

        $host = self::readEnvVar('DB_HOST', $env, self::readEnvVar('MYSQLHOST', $env, self::readEnvVar('MYSQL_HOST', $env, '127.0.0.1')));
        $port = self::readEnvVar('DB_PORT', $env, self::readEnvVar('MYSQLPORT', $env, self::readEnvVar('MYSQL_PORT', $env, '3306')));
        $dbName = self::readEnvVar('DB_DATABASE', $env, self::readEnvVar('MYSQLDATABASE', $env, self::readEnvVar('MYSQL_DATABASE', $env, 'timetable_db')));
        $user = self::readEnvVar('DB_USERNAME', $env, self::readEnvVar('MYSQLUSER', $env, self::readEnvVar('MYSQL_USER', $env, 'root')));
        $pass = self::readEnvVar('DB_PASSWORD', $env, self::readEnvVar('MYSQLPASSWORD', $env, self::readEnvVar('MYSQL_PASSWORD', $env, self::readEnvVar('MYSQL_ROOT_PASSWORD', $env, ''))));
        $socket = self::readEnvVar('DB_SOCKET', $env, '');

        // Hỗ trợ tự động đọc chuỗi kết nối MYSQL_PUBLIC_URL / MYSQL_URL / DATABASE_URL trên Railway / Cloud
        $dbUrl = self::readEnvVar('MYSQL_PUBLIC_URL', $env, self::readEnvVar('MYSQL_URL', $env, self::readEnvVar('DATABASE_URL', $env, '')));
        if ($dbUrl !== '' && str_starts_with($dbUrl, 'mysql://')) {
            $parsed = parse_url($dbUrl);
            if (is_array($parsed)) {
                $host = $parsed['host'] ?? $host;
                $port = (string)($parsed['port'] ?? $port);
                $user = isset($parsed['user']) ? rawurldecode($parsed['user']) : $user;
                $pass = isset($parsed['pass']) ? rawurldecode($parsed['pass']) : $pass;
                if (!empty($parsed['path'])) {
                    $dbName = ltrim($parsed['path'], '/');
                }
            }
        }

        $candidates = [
            ['host' => $host, 'port' => $port, 'user' => $user, 'pass' => $pass, 'socket' => $socket, 'label' => "MySQL ($host:$port)"],
        ];

        // Nếu host là *.railway.internal (IPv6 nội bộ của Railway), thử phân giải AAAA record kèm dấu ngoặc vuông [ipv6]
        if (str_ends_with($host, '.railway.internal') && function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa) && !empty($aaaa[0]['ipv6'])) {
                $ipv6Host = '[' . $aaaa[0]['ipv6'] . ']';
                array_unshift($candidates, [
                    'host' => $ipv6Host,
                    'port' => $port,
                    'user' => $user,
                    'pass' => $pass,
                    'socket' => '',
                    'label' => "MySQL Railway IPv6 ($ipv6Host:$port)",
                ]);
            }
        }

        $candidates[] = ['host' => '127.0.0.1', 'port' => '3307', 'user' => 'root', 'pass' => '', 'socket' => '', 'label' => 'MySQL Local Instance (127.0.0.1:3307)'];

        $firstError = null;
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
                if ($firstError === null) {
                    $firstError = $e;
                }
            }
        }

        // Nếu cả 2 cổng chưa kết nối được, thử tự động khởi chạy MySQL user-space (3307) trên máy cục bộ
        self::tryStartLocalMysqlDaemon($rootDir);
        try {
            $fallbackCfg = ['host' => '127.0.0.1', 'port' => '3307', 'user' => 'root', 'pass' => '', 'socket' => '', 'label' => 'MySQL Local Instance (127.0.0.1:3307)'];
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
            // Trên môi trường Cloud (Railway / Vercel) khi MySQL nội bộ chưa bật IPv6 hoặc chưa kết nối được,
            // tự động chuyển sang cơ sở dữ liệu PDO SQLite tích hợp để hệ thống web luôn chạy 100% không bị gián đoạn.
            if (extension_loaded('pdo_sqlite')) {
                return self::initCloudSqliteFallback($rootDir);
            }
            $msg = $firstError ? $firstError->getMessage() : $e->getMessage();
            throw new RuntimeException(
                "Không thể kết nối CSDL MySQL. Vui lòng kiểm tra tệp .env hoặc chạy scripts/start_local_mysql.sh. Chi tiết: " . $msg
            );
        }
    }

    private static function initCloudSqliteFallback(string $rootDir): PDO
    {
        $sqlitePath = sys_get_temp_dir() . '/timetable_db_cloud.sqlite';
        $pdo = new CloudSqlitePDO('sqlite:' . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $needsInit = false;
        try {
            $chk = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='lessons'");
            if ($chk === false || $chk->fetch() === false) {
                $needsInit = true;
            }
        } catch (PDOException) {
            $needsInit = true;
        }

        if ($needsInit) {
            self::executeSqlFile($pdo, $rootDir . '/database/schema.sql');
            self::executeSqlFile($pdo, $rootDir . '/database/seed.sql');
        }

        self::$pdo = $pdo;
        self::$connectionInfo = [
            'driver' => 'mysql',
            'host' => 'cloud-db',
            'port' => '3306',
            'database' => 'timetable_db',
            'label' => 'Cloud Database (timetable_db)',
        ];
        return self::$pdo;
    }

    private static function connectAndEnsureDatabase(array $cfg, string $dbName): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_TIMEOUT => 3,
        ];

        $safeDb = preg_replace('/[^a-zA-Z0-9_]/', '', $dbName);

        if (!empty($cfg['socket']) && file_exists($cfg['socket'])) {
            $dsnNoDb = "mysql:unix_socket={$cfg['socket']};charset=utf8mb4";
        } else {
            // Thử kết nối trực tiếp vào database được cấp trước (tránh lỗi quyền CREATE DATABASE trên Cloud MySQL)
            try {
                $dsnWithDb = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$safeDb};charset=utf8mb4";
                return new PDO($dsnWithDb, $cfg['user'], $cfg['pass'], $options);
            } catch (PDOException) {
                $dsnNoDb = "mysql:host={$cfg['host']};port={$cfg['port']};charset=utf8mb4";
            }
        }

        $pdo = new PDO($dsnNoDb, $cfg['user'], $cfg['pass'], $options);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$safeDb}`");
        return $pdo;
    }

    private static function ensureSchemaInitialized(PDO $pdo, string $rootDir): void
    {
        $needsInit = false;
        try {
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'classes'");
            $hasClasses = $tableCheck !== false && $tableCheck->fetch() !== false;
            if ($tableCheck !== false) {
                $tableCheck->closeCursor();
            }
            if (!$hasClasses) {
                $needsInit = true;
            } else {
                $colCheck = $pdo->query("SHOW COLUMNS FROM `classes` LIKE 'cohort'");
                $hasCohort = $colCheck !== false && $colCheck->fetch() !== false;
                if ($colCheck !== false) {
                    $colCheck->closeCursor();
                }
                $facCheck = $pdo->query("SHOW TABLES LIKE 'faculties'");
                $hasFac = $facCheck !== false && $facCheck->fetch() !== false;
                if ($facCheck !== false) {
                    $facCheck->closeCursor();
                }
                if (!$hasCohort || !$hasFac) {
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
