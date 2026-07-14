-- Agregar rol "viewer" al sistema
-- Ejecutar en producción: solumedic.shop/columbia (u884609367_dbshop)

INSERT INTO roles (nombre, codigo, nivel_jerarquico, descripcion, permisos)
VALUES (
    'Viewer',
    'viewer',
    5,
    'Acceso de solo lectura al dashboard. No puede realizar ninguna acción de gestión.',
    '{"dashboard": true, "solo_lectura": true}'
);
