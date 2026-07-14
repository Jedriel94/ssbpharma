<?php
session_start();

// Destruir todas las variables de sesión de administrador
unset($_SESSION['admin_id']);
unset($_SESSION['admin_usuario']);
unset($_SESSION['admin_nombre']);
// Limpiar modo tienda del representante
unset($_SESSION['_rep_modo']);
unset($_SESSION['_rep_admin_id']);

// Redirigir al inicio
header('Location: index.php');
exit;
