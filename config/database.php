<?php
require_once __DIR__ . '/app.php';

class Database
{
    private string $host;
    private string $db_name;
    private string $username;
    private string $password;
    private int $port;
    public ?mysqli $conn = null;

    public function __construct()
    {
        $this->host = getenv('DB_HOST') ?: '';
        $this->db_name = getenv('DB_NAME') ?: '';
        $this->username = getenv('DB_USER') ?: '';
        $this->password = getenv('DB_PASS') ?: '';
        $this->port = (int) (getenv('DB_PORT') ?: 4000);
    }

    public function getConnection(): mysqli
    {
        try {
            if (
                $this->host === '' ||
                $this->db_name === '' ||
                $this->username === '' ||
                $this->password === ''
            ) {
                throw new RuntimeException(
                    'Required database environment variables are missing.'
                );
            }

            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

            $this->conn = mysqli_init();

            if ($this->conn === false) {
                throw new RuntimeException('Failed to initialize MySQLi.');
            }

            // Require an encrypted connection to TiDB Cloud.
            $this->conn->ssl_set(null, null, null, null, null);

            $this->conn->real_connect(
                $this->host,
                $this->username,
                $this->password,
                $this->db_name,
                $this->port,
                null,
                MYSQLI_CLIENT_SSL
            );

            $this->conn->set_charset('utf8mb4');

        } catch (Throwable $e) {
            error_log('Database connection error: ' . $e->getMessage());

            if (!headers_sent()) {
                http_response_code(500);
            }

            exit('Database connection failed. Check server logs and configuration.');
        }

        return $this->conn;
    }
}

$database = new Database();
$db = $database->getConnection();
