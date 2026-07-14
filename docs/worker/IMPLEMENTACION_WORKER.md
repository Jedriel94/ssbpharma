# ✅ Sistema de Generación Automática de Ligas de Pago - IMPLEMENTADO

## 🎯 Resumen

Se implementó un **sistema de cola de trabajos** que permite generar ligas de pago automáticamente mediante una macro, sin intervención manual del administrador.

---

## 📦 Archivos Creados/Modificados

### ✅ Nuevos Archivos

1. **database/schema_produccion_unificado.sql**
   - Incluye la tabla `liga_pago_queue` en el esquema consolidado
   - Ya no depende de una migración separada en este repo

2. **worker.php**
   - Proceso principal que ejecuta la macro
   - Corre en loop infinito en PC del admin
   - Lee jobs pendientes cada 10 segundos

3. **iniciar-worker.ps1**
   - Script PowerShell alternativo
   - Con colores y mejor formateo

4. **WORKER_README.md**
   - Documentación completa del sistema
   - Guía de uso y troubleshooting

5. **macro-simulador.bat**
   - Simulador de macro para pruebas
   - Genera URLs fake

### 🔧 Archivos Modificados

1. **procesar-pago.php** (líneas 21-73)
   - Caso `solicitar_liga_pago` modificado
   - Ahora inserta job en `liga_pago_queue`
   - Valida que no existan jobs duplicados
   - Mensaje mejorado al cliente

---

## 🔄 Flujo Completo

```
1. Cliente → "Solicitar Liga de Pago"
2. Sistema → INSERT en liga_pago_queue (estado: pendiente)
3. Sistema → Mensaje en chat
4. Cliente → Ve "Esperando liga..." (polling cada 5s)

5. Worker → Detecta job pendiente
6. Worker → Ejecuta macro.exe con parámetros del pedido
7. Worker → Captura enlace retornado
8. Worker → Guarda en pedidos.liga_pago
9. Worker → Marca job como completado

10. Cliente → Polling detecta enlace
11. Cliente → Muestra liga automáticamente
12. Cliente → Realiza pago y sube comprobante
```

---

## 🚀 Pasos Para Activar

### 1️⃣ Verificar estructura SQL (Una sola vez)

**Opción A: phpMyAdmin**
- Abrir phpMyAdmin
- Seleccionar base de datos `solumedic_dbshop`
- Ir a pestaña "SQL"
- Ejecutar: `SHOW TABLES LIKE 'liga_pago_queue';`

**Opción B: Línea de comandos**
```powershell
cd C:\laragon\www\proceso
mysql -u root -D solumedic_dbshop -e "SHOW TABLES LIKE 'liga_pago_queue';"
```

**Verificar:**
```sql
DESCRIBE liga_pago_queue;
```

Debe mostrar la estructura de la tabla.

---

### 2️⃣ Configurar Ruta de la Macro

Editar `worker.php` línea 22:

```php
// Si tu macro está en la raíz del proyecto
define('MACRO_PATH', __DIR__ . '/macro.exe');

// O ruta completa
define('MACRO_PATH', 'C:/ruta/completa/macro.exe');
```

**Para pruebas (usar simulador):**
```php
define('MACRO_PATH', __DIR__ . '/macro-simulador.bat');
```

---

### 3️⃣ Iniciar Worker

**Método 1: PowerShell (Más Fácil)**
1. Ejecutar `iniciar-worker.ps1`
2. Se abre ventana de consola
3. Minimizar (NO cerrar)

**Método 2: Línea de comandos**
```powershell
cd C:\laragon\www\botikitpedidos
php worker.php
```

**Pantalla esperada:**
```
============================================================
🚀 WORKER - GENERADOR AUTOMÁTICO DE LIGAS DE PAGO
============================================================
Iniciado: 2025-11-13 14:30:00
Intervalo: 10 segundos
Presiona Ctrl+C para detener
============================================================

✅ Conexión a base de datos establecida

[14:30:10] 💤 Esperando trabajos... (10s sin actividad)
.......
```

---

### 4️⃣ Probar Sistema

**Desde la web:**
1. Ir a `http://localhost/botikitpedidos/procesar-pago.php?pedido_id=XXX&telefono=XXX`
2. Seleccionar método "Liga de Pago"
3. Click en "Solicitar Liga de Pago"
4. Observar ventana del worker

**En worker debe aparecer:**
```
------------------------------------------------------------
📋 NUEVO JOB #1
   Pedido: #XXX
   Monto: $XXX.XX
   Cliente: Nombre del Cliente
   Intento: 1/3
------------------------------------------------------------
[HH:MM:SS] ⏳ Ejecutando macro...
[HH:MM:SS] 💻 Comando: ...
[HH:MM:SS] 📤 Respuesta de macro: https://...
[HH:MM:SS] ✅ Liga generada exitosamente
[HH:MM:SS] 💾 Enlace guardado en pedido #XXX
[HH:MM:SS] 🎉 Job completado exitosamente
```

**En la web:**
- Después de ~3-15 segundos (depende del intervalo)
- Aparecerá automáticamente la liga de pago
- Sin refrescar la página

---

## 🗂️ Estructura de Base de Datos

### Tabla: liga_pago_queue

| Campo | Tipo | Descripción |
|-------|------|-------------|
| id | INT | ID autoincrementable |
| pedido_id | INT | ID del pedido |
| monto | DECIMAL(10,2) | Monto del pedido |
| nombre_cliente | VARCHAR(200) | Nombre del cliente |
| metodo_pago | VARCHAR(50) | Siempre 'liga_pago' |
| estado | ENUM | pendiente, procesando, completado, error |
| enlace_generado | TEXT | URL generada por la macro |
| error_mensaje | TEXT | Mensaje de error si falla |
| intentos | INT | Número de reintentos (máx 3) |
| created_at | TIMESTAMP | Cuándo se creó la solicitud |
| processed_at | TIMESTAMP | Cuándo se procesó |

---

## 🔍 Monitoreo

### Ver todos los jobs:
```sql
SELECT * FROM liga_pago_queue ORDER BY created_at DESC LIMIT 20;
```

### Ver solo pendientes:
```sql
SELECT * FROM liga_pago_queue WHERE estado = 'pendiente';
```

### Ver errores:
```sql
SELECT * FROM liga_pago_queue WHERE estado = 'error';
```

### Resetear job trabado:
```sql
UPDATE liga_pago_queue 
SET estado = 'pendiente', intentos = 0 
WHERE id = XXX;
```

---

## ⚙️ Configuración Avanzada

### Cambiar intervalo del worker

Editar `worker.php` línea 19:
```php
define('WORKER_INTERVAL', 10);  // Segundos (10 = revisar cada 10s)
```

### Cambiar máximo de reintentos

Editar `worker.php` línea 20:
```php
define('MAX_INTENTOS', 3);  // Número de reintentos antes de marcar como error
```

---

## 🔐 Producción

### Diferencias con Desarrollo

**En desarrollo (actual):**
- Worker se conecta a BD local (localhost)
- Macro está en tu PC
- Worker corre en tu PC

**En producción:**
- Worker se conecta a BD remota del servidor
- Macro sigue en tu PC (o en VPS con Windows)
- Worker corre en tu PC/VPS
- Cliente accede desde internet

### Configuración para Producción

**1. Editar config/database.php en la carpeta del worker:**
```php
private $host = 'tu-servidor-mysql.com';  // IP o dominio del servidor
private $db   = 'botikit_prod';
private $user = 'usuario_remoto';
private $pass = 'contraseña_segura';
private $port = 3306;
```

**2. Abrir puerto MySQL en servidor:**
- Permitir conexiones desde IP de tu PC
- O usar túnel SSH/VPN

**3. Preparar base en el servidor:**
- Importar `database/schema_produccion_unificado.sql` en BD de producción
- Subir archivos modificados (procesar-pago.php)

**4. Iniciar worker en tu PC:**
- Mismo procedimiento (doble click o línea de comandos)
- Ahora se conecta a BD remota

---

## ⚠️ Consideraciones Importantes

### ✅ Ventajas

- **Automático:** Sin intervención manual
- **Auditable:** Todo queda registrado en BD
- **Robusto:** Reintentos automáticos
- **Simple:** Solo PHP nativo
- **Escalable:** Fácil agregar más features

### ⚠️ Limitaciones

- **Requiere PC encendida:** Worker debe estar corriendo
- **Internet estable:** Para conectar a BD remota
- **Una sola PC:** Solo una instancia del worker
- **Windows only:** La macro típicamente es Windows

### 💡 Mejoras Futuras

- Convertir worker en servicio Windows (inicio automático)
- Panel de administración web para monitorear cola
- Notificaciones por email si el worker se detiene
- Logs en archivo además de consola
- Migrar macro a API de Fiserv (si disponible)

---

## 📞 Troubleshooting

Ver `WORKER_README.md` para guía completa de solución de problemas.

**Errores comunes:**

1. **"Can't connect to MySQL"** → Iniciar MySQL en Laragon
2. **"La macro no retornó URL válida"** → Verificar ruta en MACRO_PATH
3. **Jobs quedan en 'procesando'** → Reiniciar worker, resetear job manualmente
4. **Worker se detiene solo** → Revisar memoria/PHP limits

---

## ✅ Checklist de Implementación

- [ ] Verificar tabla `liga_pago_queue` en la base activa
- [ ] Configurar MACRO_PATH en worker.php
- [ ] Iniciar MySQL en Laragon
- [ ] Probar worker con macro-simulador.bat
- [ ] Crear pedido de prueba
- [ ] Solicitar liga de pago desde web
- [ ] Verificar que worker procesa el job
- [ ] Verificar que liga aparece en web
- [ ] Sustituir simulador por macro real
- [ ] Probar con pedido real
- [ ] Documentar ruta final de macro.exe
- [ ] Crear acceso directo en escritorio
- [ ] Configurar inicio automático (opcional)

---

**Estado:** ✅ Implementación completa  
**Fecha:** Noviembre 2025  
**Versión:** 1.0  
**Listo para:** Pruebas en desarrollo
