<?php
require_once __DIR__ . '/../config/database.php';

class Configuracion {
    private static $cache = null;
    
    /**
     * Obtener valor de configuración
     */
    public static function get($clave, $default = null) {
        // Cargar cache si no existe
        if (self::$cache === null) {
            self::loadCache();
        }
        
        return self::$cache[$clave] ?? $default;
    }
    
    /**
     * Actualizar valor de configuración (solo admin)
     */
    public static function set($clave, $valor) {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        
        $stmt = $pdo->prepare("
            UPDATE configuracion 
            SET valor = :valor 
            WHERE clave = :clave
        ");
        
        $stmt->execute([
            'valor' => $valor,
            'clave' => $clave
        ]);
        
        // Recargar cache
        self::loadCache();
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Obtener todas las configuraciones
     */
    public static function getAll() {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        
        $stmt = $pdo->query("SELECT * FROM configuracion ORDER BY id");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Cargar configuraciones en cache
     */
    private static function loadCache() {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        
        $stmt = $pdo->query("SELECT clave, valor FROM configuracion");
        $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        self::$cache = [];
        foreach ($configs as $config) {
            self::$cache[$config['clave']] = $config['valor'];
        }
    }
    
    /**
     * Limpiar cache (útil después de actualizaciones)
     */
    public static function clearCache() {
        self::$cache = null;
    }
}
