# ============================================
# Worker - Sistema de Generación Automática de Ligas de Pago
# ============================================

Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  WORKER - LIGAS DE PAGO AUTOMÁTICAS" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

# Verificar que estamos en el directorio correcto
if (-not (Test-Path "worker.php")) {
    Write-Host "ERROR: No se encuentra worker.php" -ForegroundColor Red
    Write-Host "Por favor ejecuta este script desde la carpeta del proyecto" -ForegroundColor Yellow
    Read-Host "Presiona Enter para salir"
    exit 1
}

# Buscar PHP
$phpPath = $null

# Intentar con PHP global
try {
    $phpVersion = php --version 2>&1
    if ($LASTEXITCODE -eq 0) {
        $phpPath = "php"
        Write-Host "✓ PHP encontrado en PATH del sistema" -ForegroundColor Green
    }
} catch {
    # PHP no está en PATH
}

# Si no encontró PHP global, buscar en Laragon
if ($null -eq $phpPath) {
    Write-Host "⚠ PHP no encontrado en PATH, buscando en Laragon..." -ForegroundColor Yellow
    
    $laraPhp = "C:\laragon\bin\php\php-8.2.23-Win32-vs16-x64\php.exe"
    if (Test-Path $laraPhp) {
        $phpPath = $laraPhp
        Write-Host "✓ PHP encontrado en Laragon" -ForegroundColor Green
    } else {
        Write-Host "ERROR: No se encuentra PHP" -ForegroundColor Red
        Write-Host "Por favor instala PHP o configura el PATH" -ForegroundColor Yellow
        Read-Host "Presiona Enter para salir"
        exit 1
    }
}

Write-Host ""
Write-Host "Iniciando worker..." -ForegroundColor Cyan
Write-Host ""
Write-Host "IMPORTANTE:" -ForegroundColor Yellow
Write-Host "- NO cierres esta ventana" -ForegroundColor White
Write-Host "- Para detener el worker presiona Ctrl+C" -ForegroundColor White
Write-Host "- Minimiza esta ventana para seguir trabajando" -ForegroundColor White
Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

# Ejecutar worker
& $phpPath worker.php

# Si el worker termina
Write-Host ""
Write-Host ""
Write-Host "============================================" -ForegroundColor Red
Write-Host "Worker detenido" -ForegroundColor Red
Write-Host "============================================" -ForegroundColor Red
Write-Host ""
Read-Host "Presiona Enter para salir"
