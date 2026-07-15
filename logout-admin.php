<?php
session_start();

// Destruir todas las variables de sesión de administrador
unset($_SESSION['admin_id']);
unset($_SESSION['admin_usuario']);
unset($_SESSION['admin_nombre']);
// Limpiar modo tienda del representante
unset($_SESSION['_rep_modo']);
unset($_SESSION['_rep_admin_id']);

// Limpiar cookies de referido de representante para que el inicio NO muestre
// "Representante: X" despues de cerrar sesion.
$repCookieExp = time() - 3600;
$secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
foreach (['botikit_rep_admin', 'botikit_rep_codigo', 'botikit_rep_nombre', 'botikit_rep'] as $ck) {
    setcookie($ck, '', $repCookieExp, '/', '', $secure, true);
    unset($_COOKIE[$ck]);
}

// Redirigir al inicio
header('Location: index.php');
exit;
