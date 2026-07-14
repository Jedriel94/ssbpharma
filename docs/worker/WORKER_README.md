# 🤖 Worker - Sistema de Generación Automática de Ligas de Pago

## 📋 ¿Qué es esto?

El **worker** es un proceso que corre en segundo plano en la PC del administrador. Su función es:

1. Monitorear la base de datos buscando solicitudes de ligas de pago
2. Ejecutar automáticamente la macro para generar las ligas
3. Guardar las ligas generadas en la base de datos
4. El cliente las recibe automáticamente sin intervención manual

---

## 🚀 Inicio Rápido

### Opción 1: PowerShell (Recomendado)

**PowerShell:**
1. Click derecho en `iniciar-worker.ps1`
2. Seleccionar "Ejecutar con PowerShell"
3. El worker comenzará a funcionar
4. **Minimizar la ventana** (NO cerrar)

### Opción 2: Línea de Comandos

```powershell
cd C:\laragon\www\botikitpedidos
php worker.php
```

---

## ⚙️ Configuración Inicial

### 1. Verificar estructura SQL requerida

**Primera vez solamente:**

```sql
-- Abrir phpMyAdmin o MySQL Workbench
-- Verificar que exista la tabla de cola:
SHOW TABLES LIKE 'liga_pago_queue';
```

O desde línea de comandos:
```powershell
cd C:\laragon\www\proceso
mysql -u root -D solumedic_dbshop -e "SHOW TABLES LIKE 'liga_pago_queue';"
```

### 2. Configurar Ruta de la Macro

Editar `worker.php` línea 22:

```php
define('MACRO_PATH', __DIR__ . '/macro.exe');  // Ajustar ruta si es necesario
```

Si tu macro está en otro lugar:
```php
define('MACRO_PATH', 'C:/ruta/completa/a/tu/macro.exe');
```

### 3. Asegurarse que MySQL está corriendo

- En Laragon: Click en "Start All"
- Verificar que MySQL esté activo

---

## 📊 Cómo Funciona

```
[Cliente Web] 
    ↓ Click "Solicitar Liga de Pago"
    ↓
[Base de Datos]
    → INSERT en tabla liga_pago_queue
    → estado = 'pendiente'
    ↓
[Worker en PC Admin] ← Revisa cada 10 segundos
    ↓ Encuentra job pendiente
    ↓ Ejecuta: macro.exe 250.00 123 "Juan Perez" 1
    ↓ Recibe: https://fiserv.com/pay/abc123
    ↓ Guarda en pedidos.liga_pago
    ↓
[Cliente Web]
    ← Polling detecta la liga
    ← Muestra enlace automáticamente
```

---

## 🖥️ Pantalla del Worker

```
============================================================
🚀 WORKER - GENERADOR AUTOMÁTICO DE LIGAS DE PAGO
============================================================
Iniciado: 2025-11-13 14:30:00
Intervalo: 10 segundos
Presiona Ctrl+C para detener
============================================================

✅ Conexión a base de datos establecida

[14:30:15] 💤 Esperando trabajos... (60s sin actividad)
[14:31:20] 💤 Esperando trabajos... (120s sin actividad)

------------------------------------------------------------
📋 NUEVO JOB #1
   Pedido: #123
   Monto: $250.00
   Cliente: Juan Perez
   Intento: 1/3
------------------------------------------------------------
[14:32:05] ⏳ Ejecutando macro...
[14:32:05] 💻 Comando: "C:\...\macro.exe" 250.00 123 "Juan Perez" 1
[14:32:08] 📤 Respuesta de macro: https://fiserv.com/pay/abc123xyz
[14:32:08] ✅ Liga generada exitosamente
[14:32:08] 💾 Enlace guardado en pedido #123
[14:32:08] 🎉 Job completado exitosamente
============================================================

[14:32:20] 💤 Esperando trabajos... (10s sin actividad)
```

---

## ⚠️ Troubleshooting

### Problema: "Can't connect to MySQL server"

**Solución:**
- Iniciar MySQL en Laragon
- Verificar que la base de datos existe
- Revisar config/database.php

### Problema: "La macro no retornó una URL válida"

**Solución:**
- Verificar que `macro.exe` existe en la ruta configurada
- Probar macro manualmente: `macro.exe 100.00 1 "Test" 1`
- Verificar que la macro retorna SOLO la URL (sin texto adicional)

### Problema: Worker se detiene solo

**Solución:**
- Revisar logs en pantalla
- Verificar que PHP no tiene límite de tiempo (php.ini: max_execution_time = 0)
- Revisar memoria disponible

### Problema: Jobs quedan en 'procesando' para siempre

**Solución:**
```sql
-- Resetear jobs trabados
UPDATE liga_pago_queue 
SET estado = 'pendiente', intentos = 0 
WHERE estado = 'procesando' AND processed_at IS NULL;
```

---

## 🛑 Detener el Worker

**Método 1: Ctrl+C**
- En la ventana del worker
- Presionar `Ctrl+C`
- Esperar a que termine el ciclo actual

**Método 2: Cerrar ventana**
- Click en la X de la ventana
- El proceso se detendrá

**El worker puede detenerse y reiniciarse sin problemas.** Los jobs pendientes se procesarán cuando se reinicie.

---

## 🔄 Reintentos Automáticos

Si la macro falla:
- El job regresa a estado `pendiente`
- Se reintenta automáticamente
- Máximo 3 intentos
- Después de 3 fallos → estado `error`

Ver jobs con error:
```sql
SELECT * FROM liga_pago_queue WHERE estado = 'error';
```

---

## 📈 Monitoreo

### Ver cola actual:
```sql
SELECT * FROM liga_pago_queue ORDER BY created_at DESC LIMIT 20;
```

### Ver solo pendientes:
```sql
SELECT * FROM liga_pago_queue WHERE estado = 'pendiente';
```

### Estadísticas:
```sql
SELECT 
    estado,
    COUNT(*) as total,
    AVG(TIMESTAMPDIFF(SECOND, created_at, processed_at)) as tiempo_promedio_segundos
FROM liga_pago_queue
GROUP BY estado;
```

---

## 💡 Tips

1. **Dejar corriendo 24/7:** Minimizar la ventana, no cerrar
2. **Acceso directo:** Crear acceso directo de `iniciar-worker.ps1` en el escritorio
3. **Inicio automático:** Agregar acceso directo en `shell:startup` de Windows
4. **Múltiples PCs:** Solo una PC debe ejecutar el worker (la que tiene la macro)
5. **Backup:** Si el worker se cae, solo reiniciarlo - no se pierden jobs

---

## 🔐 Producción

Para producción, el worker debe:
1. Conectar a la base de datos remota (editar config/database.php)
2. Correr en la PC del admin que tiene la macro
3. Tener internet estable
4. Configurar inicio automático con Windows

---

## 📞 Soporte

Si tienes problemas:
1. Revisar logs en pantalla del worker
2. Verificar tabla `liga_pago_queue` en BD
3. Probar macro manualmente
4. Revisar esta documentación

---

**Última actualización:** Noviembre 2025  
**Versión:** 1.0
