<?php
require_once __DIR__ . '/app.php';

class Database
{
    private string $host;
    private string $db_name;
    private string $username;
    private string $password;
    public ?mysqli $conn = null;

    public function __construct()
    {
        $isProduction = strtolower(getenv('APP_ENV') ?: 'development') === 'production';
        $defaults = $isProduction
            ? ['DB_HOST' => '', 'DB_NAME' => '', 'DB_USER' => '', 'DB_PASSWORD' => '']
            : [
                'DB_HOST' => 'localhost',
                'DB_NAME' => 'invoice_management_system',
                'DB_USER' => 'root',
                'DB_PASSWORD' => ''
            ];

        $this->host = getenv('DB_HOST') !== false ? getenv('DB_HOST') : $defaults['DB_HOST'];
        $this->db_name = getenv('DB_NAME') !== false ? getenv('DB_NAME') : $defaults['DB_NAME'];
        $this->username = getenv('DB_USER') !== false ? getenv('DB_USER') : $defaults['DB_USER'];
        $this->password = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : $defaults['DB_PASSWORD'];
    }

    public function getConnection(): mysqli
    {
        $this->conn = null;

        try {
            $isProduction = strtolower(getenv('APP_ENV') ?: 'development') === 'production';
            if (
                $isProduction
                && (
                    getenv('DB_HOST') === false
                    || getenv('DB_NAME') === false
                    || getenv('DB_USER') === false
                    || getenv('DB_PASSWORD') === false
                )
            ) {
                throw new RuntimeException('Production database environment variables are missing.');
            }

            if (
                $this->host === ''
                || $this->db_name === ''
                || $this->username === ''
                || ($isProduction && $this->password === '')
            ) {
                throw new RuntimeException('Database environment variables are missing.');
            }

            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $this->conn = new mysqli($this->host, $this->username, $this->password, $this->db_name);
            $this->conn->set_charset("utf8mb4");
        } catch (Throwable $e) {
            error_log('Database connection error: ' . $e->getMessage());

            $isProduction = strtolower(getenv('APP_ENV') ?: 'development') === 'production';
            $message = $isProduction
                ? 'Database connection failed. Check the server logs.'
                : 'Database connection failed: ' . $e->getMessage();
            http_response_code(500);
            exit($message);
        }

        return $this->conn;
    }
}

$database = new Database();
$db = $database->getConnection();