<?php
/**
 * PLANTILLA de credenciales de base de datos.
 *
 * Copia este archivo como config/database.local.php y coloca las credenciales
 * reales del servidor. database.local.php está en .gitignore y NUNCA se sube al
 * repositorio, por lo que cada servidor/cliente mantiene sus propias credenciales.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'nombre_de_la_base_de_datos');
define('DB_USER', 'usuario_de_la_base_de_datos');
define('DB_PASS', 'contrasena_de_la_base_de_datos');
define('DB_CHARSET', 'utf8mb4');
