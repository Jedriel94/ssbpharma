<?php
// Configurar zona horaria de México
date_default_timezone_set('America/Mexico_City');

/**
 * Credenciales de la base de datos.
 *
 * Las credenciales REALES viven en config/database.local.php, un archivo que
 * NO se sube a git (está en .gitignore). Así cada servidor/cliente mantiene sus
 * propios datos y nunca se exponen en el repositorio.
 *
 * Para configurar un servidor nuevo: copia config/database.example.php como
 * config/database.local.php y coloca ahí las credenciales reales.
 */
$__dbLocalConfig = __DIR__ . '/database.local.php';
if (file_exists($__dbLocalConfig)) {
    require_once $__dbLocalConfig;
}

// Valores por defecto (desarrollo local) si database.local.php no los definió
if (!defined('DB_HOST'))    define('DB_HOST', 'localhost');
if (!defined('DB_NAME'))    define('DB_NAME', 'solumedic_dbshop');
if (!defined('DB_USER'))    define('DB_USER', 'root');
if (!defined('DB_PASS'))    define('DB_PASS', '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

class Database {
    private static $instance = null;
    private $conn;

    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $this->conn = new PDO($dsn, DB_USER, DB_PASS);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // Configurar zona horaria de MySQL a Ciudad de México
            $this->conn->exec("SET time_zone = '-06:00'");
        } catch(PDOException $e) {
            die("Error de conexión: " . $e->getMessage());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }
}
