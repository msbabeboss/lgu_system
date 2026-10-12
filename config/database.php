<?php
class Database {
    private static $instance = null;
    private $conn;
    private string $charset = 'utf8mb4';

    private function __construct() {
        // Load .env from project root (one level up from config/)
        $envFile = dirname(__DIR__) . '/.env';
        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
                [$key, $val] = explode('=', $line, 2);
                $_ENV[trim($key)] = trim($val);
            }
        }

        $host   = $_ENV['DB_HOST'] ?? 'localhost';
        $port   = $_ENV['DB_PORT'] ?? '3306';
        $dbname = $_ENV['DB_NAME'] ?? 'lgu_system';
        $user   = $_ENV['DB_USER'] ?? 'root';
        $pass   = $_ENV['DB_PASS'] ?? '';  // no hardcoded password — must be set in .env

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$this->charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $this->conn = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // Log full error server-side; never expose DB details to the browser
            error_log('[Database] Connection failed: ' . $e->getMessage());
            die(json_encode(['error' => 'Database connection failed. Please contact the system administrator.']));
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        return $this->conn;
    }
}
