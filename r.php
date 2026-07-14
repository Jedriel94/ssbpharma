<?php
/**
 * Punto de entrada para enlaces de representantes
 * URL: botikit.shop/r.php?c=REP001
 * O con mod_rewrite: botikit.shop/r/REP001
 * 
 * Función:
 * 1. Recibe código de representante
 * 2. Valida que exista y esté activo
 * 3. Guarda cookie PERMANENTE (hasta que el usuario la borre)
 * 4. Redirecciona a la página principal
 */

require_once __DIR__ . '/config/database.php';

// Detectar base path (para desarrollo y producción)
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
$basePath = ($scriptPath === '/' || $scriptPath === '\\') ? '' : $scriptPath;

// Obtener código del representante
$codigo = $_GET['c'] ?? '';

// Si no hay código, redirigir a inicio sin cookie
if (empty($codigo)) {
    header('Location: ' . $basePath . '/index.php');
    exit;
}

// Conectar a base de datos
$db = Database::getInstance();
$pdo = $db->getConnection();

$stmt = $pdo->prepare("
    SELECT
        a.id AS admin_id,
        a.nombre,
        rp.codigo
    FROM representante_perfiles rp
    INNER JOIN administradores a ON a.id = rp.admin_id
    WHERE UPPER(rp.codigo) = UPPER(?)
      AND rp.activo = 1
      AND a.activo = 1
    LIMIT 1
");
$stmt->execute([$codigo]);
$representante = $stmt->fetch(PDO::FETCH_ASSOC);

// Validar que existe y está activo
if ($representante && !empty($representante['admin_id'])) {
    // COOKIE PERMANENTE (10 años)
    // Se mantendrá hasta que el usuario limpie cookies manualmente
    $expiracion = time() + (10 * 365 * 24 * 60 * 60); // 10 años
    
    // Detectar si estamos en HTTPS
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    
    setcookie(
        'botikit_rep_admin',
        (string)$representante['admin_id'],
        $expiracion,
        '/',
        '',
        $secure,
        true
    );

    setcookie('botikit_rep', '', time() - 3600, '/', '', $secure, true);

    setcookie(
        'botikit_rep_codigo',
        $representante['codigo'],
        $expiracion,
        '/',
        '',
        $secure,
        false
    );
    
    // También guardar el nombre en otra cookie para mostrarlo
    setcookie(
        'botikit_rep_nombre',
        $representante['nombre'],
        $expiracion,
        '/',
        '',
        $secure,                           // Secure: true en HTTPS, false en HTTP
        false  // Esta sí puede ser leída por JS para mostrar mensaje
    );
    
    // Redirigir a la página principal con mensaje de éxito
    header('Location: ' . $basePath . '/index.php?ref=1');
    exit;
    
} else {
    // Código inválido o representante inactivo
    header('Location: ' . $basePath . '/index.php?ref_error=1');
    exit;
}
