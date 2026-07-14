<?php
session_start();
require_once __DIR__ . '/models/Cliente.php';

$clienteModel = new Cliente();

// Función para manejar subida de constancia fiscal
function handleConstanciaUpload($file, $telefono) {
    $allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
    $maxSize = 5 * 1024 * 1024;
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Error al subir el archivo'];
    }
    
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => 'El archivo no debe superar 5MB'];
    }
    
    if (!in_array($file['type'], $allowedTypes)) {
        return ['success' => false, 'message' => 'Solo se permiten archivos PDF o imágenes JPG/PNG'];
    }
    
    $uploadDir = __DIR__ . '/uploads/fiscales/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'constancia_' . $telefono . '_' . time() . '.' . $extension;
    $uploadPath = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
        return ['success' => true, 'filename' => $filename];
    } else {
        return ['success' => false, 'message' => 'Error al guardar el archivo'];
    }
}
