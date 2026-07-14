# ✅ CHECKLIST DE DEPLOY A HOSTINGER
## BotiKit Pedidos - Sistema de Gestión de Pedidos

---

## 📅 FECHA DE DEPLOY: _______________
## 👤 RESPONSABLE: _______________

---

## FASE 1: PREPARACIÓN LOCAL

### Verificación Local
- [ ] Sistema funciona correctamente en local (Laragon)
- [ ] Todos los productos tienen imágenes
- [ ] Al menos 1 pedido de prueba completo realizado
- [ ] Chat funcionando
- [ ] Kanban funcionando
- [ ] Notificaciones push probadas

### Backup Local
- [ ] Backup de base de datos guardado (`.sql`)
- [ ] Backup de carpeta completa guardado
- [ ] Imágenes de productos respaldadas
- [ ] Comprobantes de prueba guardados

### Datos a Guardar
```
Base de datos local:
Nombre: botikitpedidos
Usuario: root
Contraseña: (vacía)

Admin local:
Usuario: admin
Contraseña: _______________
```

---

## FASE 2: HOSTINGER - BASE DE DATOS

### Crear Base de Datos
- [ ] Login en hPanel de Hostinger
- [ ] Ir a "Bases de datos MySQL"
- [ ] Click en "Crear nueva base de datos"
- [ ] Anotar credenciales:

```
Base de datos: u_________________
Usuario:       u_________________
Contraseña:    ___________________
Host:          localhost (siempre)
```

### Importar Datos
- [ ] Abrir phpMyAdmin desde hPanel
- [ ] Seleccionar la base de datos creada
- [ ] Click en pestaña "Importar"
- [ ] Seleccionar archivo `botikitpedidos.sql`
- [ ] Click en "Continuar"
- [ ] Esperar confirmación de importación exitosa
- [ ] Verificar que existen 7 tablas:
  - [ ] usuarios
  - [ ] clientes
  - [ ] categorias
  - [ ] productos
  - [ ] pedidos
  - [ ] detalle_pedidos
  - [ ] mensajes_pedidos

---

## FASE 3: SUBIDA DE ARCHIVOS

### Método: FileZilla (Recomendado)
- [ ] FileZilla instalado
- [ ] Credenciales FTP copiadas de hPanel:
  ```
  Host:     ftp.___________________
  Usuario:  u_____________________
  Password: _______________________
  Puerto:   21
  ```
- [ ] Conectado a FTP exitosamente
- [ ] Navegado a `/public_html/`
- [ ] Todos los archivos subidos (puede tomar 10-20 min)
- [ ] Verificar que se subieron:
  - [ ] Carpeta /admin/
  - [ ] Carpeta /api/
  - [ ] Carpeta /config/
  - [ ] Carpeta /css/
  - [ ] Carpeta /includes/
  - [ ] Carpeta /js/
  - [ ] Carpeta /models/
  - [ ] Carpeta /uploads/
  - [ ] Archivo index.php
  - [ ] Archivo seguimiento.php
  - [ ] Archivo chat-pedido.php

### Método Alternativo: ZIP
- [ ] Proyecto comprimido en ZIP
- [ ] ZIP subido via "Administrador de archivos"
- [ ] ZIP extraído correctamente
- [ ] Archivo ZIP eliminado después de extraer

---

## FASE 4: CONFIGURACIÓN

### Actualizar config/database.php
- [ ] Archivo abierto en editor de Hostinger o via FTP
- [ ] Credenciales actualizadas:
  ```php
  private $host = "localhost";
  private $db_name = "u_________________";
  private $username = "u_________________";
  private $password = "___________________";
  ```
- [ ] Archivo guardado
- [ ] Cambios verificados

### Configurar Permisos
Vía FTP (click derecho → Permisos de archivo):
- [ ] /uploads/                          → 755
- [ ] /uploads/comprobantes_pago/        → 755
- [ ] /uploads/comprobantes_envio/       → 755
- [ ] /uploads/productos/                → 755
- [ ] /config/                           → 755
- [ ] /config/database.php               → 644

### Crear .htaccess
- [ ] Archivo `.htaccess` creado en raíz
- [ ] Contenido de seguridad agregado
- [ ] Redirección HTTPS configurada (opcional)

---

## FASE 5: VERIFICACIÓN

### Acceso al Sitio
- [ ] Sitio principal carga: `https://tudominio.com/botikitpedidos/`
- [ ] Sin errores 500
- [ ] Sin errores de conexión BD
- [ ] Imágenes de productos cargan correctamente

### Login Admin
- [ ] Acceso a: `https://tudominio.com/botikitpedidos/admin/`
- [ ] Login exitoso con credenciales originales
  ```
  Usuario: admin
  Password: _______________
  ```
- [ ] Dashboard carga correctamente
- [ ] Todas las secciones accesibles:
  - [ ] Productos
  - [ ] Categorías
  - [ ] Clientes
  - [ ] Pedidos
  - [ ] Kanban

### Script de Verificación
- [ ] Acceso a: `https://tudominio.com/botikitpedidos/deploy/verificar.php?password=deploy2025`
- [ ] Todas las verificaciones en verde (✅)
- [ ] Sin errores reportados
- [ ] Archivo `verificar.php` ELIMINADO después de verificar

### Prueba de Pedido Completo
- [ ] Agregar producto al carrito
- [ ] Completar formulario de pedido
- [ ] Subir comprobante de pago (imagen de prueba)
- [ ] Ver pedido en `seguimiento.php?telefono=TU_TELEFONO`
- [ ] Login como admin, ver pedido en Kanban
- [ ] Mover pedido a "Confirmado"
- [ ] Subir guía de envío
- [ ] Mover a "En Ruta"
- [ ] Cliente puede ver guía de envío
- [ ] Chat funciona (cliente → admin)
- [ ] Chat funciona (admin → cliente)

### Notificaciones Push
- [ ] Botón de activación aparece
- [ ] Permiso se puede otorgar
- [ ] Notificación de prueba funciona
- [ ] API responde correctamente

---

## FASE 6: SEGURIDAD

### SSL/HTTPS
- [ ] Certificado SSL instalado desde hPanel
- [ ] Sitio accesible via HTTPS
- [ ] Redirección HTTP → HTTPS activa
- [ ] Sin warnings de seguridad en navegador

### Cambiar Contraseña Admin
- [ ] Hash de nueva contraseña generado
- [ ] SQL ejecutado en phpMyAdmin:
  ```sql
  UPDATE usuarios 
  SET password = '$2y$10$___________________'
  WHERE username = 'admin';
  ```
- [ ] Nueva contraseña anotada en lugar seguro:
  ```
  Nueva contraseña admin: _______________
  ```
- [ ] Login con nueva contraseña exitoso

### Limpiar Archivos de Prueba
- [ ] Carpeta `/demo-*.html` eliminada (opcional)
- [ ] Carpeta `/docs/` eliminada (opcional, pero recomendado guardar local)
- [ ] Archivo `/deploy/verificar.php` ELIMINADO
- [ ] Archivos `.log` eliminados

### Configuración PHP
- [ ] `display_errors = Off` (en producción)
- [ ] `log_errors = On`
- [ ] `upload_max_filesize = 10M` (o mayor si necesitas)
- [ ] `post_max_size = 10M` (o mayor)

---

## FASE 7: MONITOREO

### Crear Backup Inicial
- [ ] Backup de BD desde phpMyAdmin guardado
- [ ] Backup de archivos descargado via FTP
- [ ] Backups guardados en lugar seguro

### Documentar URLs
```
Sitio Principal:  https://___________________/botikitpedidos/
Admin:            https://___________________/botikitpedidos/admin/
Seguimiento:      https://___________________/botikitpedidos/seguimiento.php
API Notif:        https://___________________/botikitpedidos/api/check-notifications.php
```

### Pruebas Finales
- [ ] Probar desde móvil
- [ ] Probar desde tablet
- [ ] Probar desde diferentes navegadores:
  - [ ] Chrome
  - [ ] Firefox
  - [ ] Safari (iPhone)
  - [ ] Edge
- [ ] Velocidad de carga aceptable (< 3 segundos)

---

## FASE 8: LANZAMIENTO

### Informar Usuarios
- [ ] Enviar URL a clientes de prueba
- [ ] Enviar credenciales admin a encargados
- [ ] Compartir enlace de seguimiento
- [ ] Instrucciones de uso enviadas

### Monitoreo Primera Semana
- [ ] Revisar error_log diariamente
- [ ] Verificar espacio en disco
- [ ] Verificar pedidos se crean correctamente
- [ ] Verificar notificaciones funcionan
- [ ] Atender dudas de usuarios

---

## 📊 RESUMEN FINAL

### Resultado del Deploy
- [ ] ✅ Exitoso - Todo funcionando
- [ ] ⚠️ Con warnings - Funcionando con detalles menores
- [ ] ❌ Fallido - Requiere corrección

### Notas Adicionales
```
__________________________________________________
__________________________________________________
__________________________________________________
__________________________________________________
```

### Contacto de Soporte
```
Hostinger Support:   https://hpanel.hostinger.com/
Chat 24/7:           Disponible en hPanel
Email:               _______________________
Teléfono:            _______________________
```

---

## ✅ DEPLOY COMPLETADO

**Fecha de finalización:** _______________  
**Hora:** _______________  
**Firmado por:** _______________  

---

**BotiKit Pedidos v1.0**  
**Sistema de Gestión de Pedidos**  
**Desarrollado en Octubre 2025** 🚀
