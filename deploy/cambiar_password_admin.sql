-- =====================================================
-- CAMBIAR CONTRASEÑA DE ADMIN EN PRODUCCIÓN
-- =====================================================

-- PASO 1: Generar el hash de tu nueva contraseña
-- Ve a: https://phppasswordhash.com/
-- O ejecuta este código PHP en un archivo temporal:

/*
<?php
// cambiar_password.php
$nueva_password = "tu_contraseña_super_segura_123";
$hash = password_hash($nueva_password, PASSWORD_DEFAULT);
echo "Tu hash es: " . $hash;
?>
*/

-- PASO 2: Ejecutar este UPDATE con el hash generado
-- IMPORTANTE: Cambia el hash por el que generaste

UPDATE usuarios 
SET password = '$2y$10$TU_HASH_GENERADO_AQUI'
WHERE username = 'admin';

-- Verificar que se actualizó correctamente
SELECT 
    id,
    username,
    email,
    CASE 
        WHEN password = '$2y$10$TU_HASH_GENERADO_AQUI' THEN '✅ Contraseña actualizada'
        ELSE '❌ Contraseña NO actualizada'
    END AS estado
FROM usuarios 
WHERE username = 'admin';

-- =====================================================
-- EJEMPLO DE HASHES PARA DIFERENTES CONTRASEÑAS
-- (SOLO PARA REFERENCIA - GENERA TU PROPIO HASH)
-- =====================================================

/*
Contraseña: "Admin123!"
Hash: $2y$10$8KzYVvXXXXXXXXXXXXXXXOuZk5Eb3J.7e9kKPPPPPPPPPPPPPPPP

Contraseña: "BotiKit2025#"
Hash: $2y$10$9LzZWwYYYYYYYYYYYYYYYPv0k6Fc4K.8f0lLQQQQQQQQQQQQQQ

NOTA: NUNCA uses estos ejemplos, genera tu propio hash
*/

-- =====================================================
-- SEGURIDAD ADICIONAL
-- =====================================================

-- Cambiar también el email del admin
UPDATE usuarios 
SET email = 'tu_email_real@dominio.com'
WHERE username = 'admin';

-- Agregar fecha de última actualización (opcional)
ALTER TABLE usuarios 
ADD COLUMN password_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Verificar todos los usuarios
SELECT 
    id,
    username,
    email,
    rol,
    activo,
    created_at
FROM usuarios;
