<?php
require_once __DIR__ . '/../includes/auth_representante.php';

// Marcar sesión como "representante operando en tienda"
$_SESSION['_rep_modo']   = true;
$_SESSION['_rep_admin_id'] = $representanteAdminId;

// Redirigir a la tienda del representante
header('Location: ' . url('r/' . urlencode($representanteCodigo)));
exit;
