<?php
header('Content-Type: application/json');
require_once '../config/database.php';
require_once '../models/Administrador.php';
require_once '../models/Role.php';
require_once '../models/RepresentantePerfil.php';

// Verificar sesión admin
session_start();
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$adminModel = new Administrador();
$roleModel = new Role();
$perfilRepresentanteModel = new RepresentantePerfil();
$db = Database::getInstance()->getConnection();
$admin_actual = $adminModel->getById($_SESSION['admin_id']);
$es_super_admin = ($admin_actual['rol_codigo'] ?? 'admin') === 'admin';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'crear':
            if (!$es_super_admin) {
                throw new Exception('No tiene permisos para crear usuarios');
            }
            
            $datos = [
                'usuario'    => $_POST['usuario'] ?? '',
                'password'   => $_POST['password'] ?? '',
                'nombre'     => $_POST['nombre'] ?? '',
                'email'      => $_POST['email'] ?? '',
                'rol_id'     => $_POST['rol_id'] ?? null,
                'superior_id'=> (isset($_POST['superior_id']) && $_POST['superior_id'] !== '') ? (int)$_POST['superior_id'] : null,
                'ruta'       => $_POST['ruta'] ?? '',
                'desc_ruta'  => $_POST['desc_ruta'] ?? '',
                'celular'    => $_POST['celular'] ?? '',
            ];
            
            if (empty($datos['usuario']) || empty($datos['password']) || empty($datos['nombre'])) {
                throw new Exception('Faltan datos requeridos');
            }
            
            $rol = $roleModel->getById($datos['rol_id']);
            $esRepresentante = ($rol['codigo'] ?? '') === 'representante';

            if ($esRepresentante && empty($_POST['representante_codigo'])) {
                throw new Exception('El codigo del representante es requerido');
            }

            $db->beginTransaction();
            $id = $adminModel->create($datos);

            if ($esRepresentante) {
                $perfilRepresentanteModel->guardarParaUsuario($id, [
                    'codigo'          => $_POST['representante_codigo'] ?? '',
                    'telefono'        => $_POST['representante_telefono'] ?? '',
                    'email'           => $_POST['email'] ?? '',
                    'tags_permitidos' => $_POST['representante_tags_permitidos'] ?? '',
                    'dir_calle'       => $_POST['dir_calle'] ?? '',
                    'dir_numero'      => $_POST['dir_numero'] ?? '',
                    'dir_colonia'     => $_POST['dir_colonia'] ?? '',
                    'dir_ciudad'      => $_POST['dir_ciudad'] ?? '',
                    'dir_estado'      => $_POST['dir_estado'] ?? '',
                    'dir_cp'          => $_POST['dir_cp'] ?? '',
                    'activo'          => 1
                ]);
            }

            $db->commit();
            echo json_encode(['success' => true, 'id' => $id, 'message' => 'Usuario creado exitosamente']);
            break;
            
        case 'actualizar':
            if (!$es_super_admin) {
                throw new Exception('No tiene permisos para modificar usuarios');
            }
            
            $id = $_POST['id'] ?? 0;
            $datos = [
                'usuario'    => $_POST['usuario'] ?? '',
                'nombre'     => $_POST['nombre'] ?? '',
                'email'      => $_POST['email'] ?? '',
                'rol_id'     => $_POST['rol_id'] ?? null,
                'superior_id'=> (isset($_POST['superior_id']) && $_POST['superior_id'] !== '') ? (int)$_POST['superior_id'] : null,
                'ruta'       => $_POST['ruta'] ?? '',
                'desc_ruta'  => $_POST['desc_ruta'] ?? '',
                'celular'    => $_POST['celular'] ?? '',
            ];
            
            if (!empty($_POST['password'])) {
                $datos['password'] = $_POST['password'];
            }
            
            $rol = $roleModel->getById($datos['rol_id']);
            $esRepresentante = ($rol['codigo'] ?? '') === 'representante';

            if ($esRepresentante && empty($_POST['representante_codigo'])) {
                throw new Exception('El codigo del representante es requerido');
            }

            $db->beginTransaction();
            $adminModel->update($id, $datos);

            if ($esRepresentante) {
                $usuario = $adminModel->getById($id);
                $perfilRepresentanteModel->guardarParaUsuario($id, [
                    'codigo'          => $_POST['representante_codigo'] ?? '',
                    'telefono'        => $_POST['representante_telefono'] ?? '',
                    'email'           => $_POST['email'] ?? '',
                    'tags_permitidos' => $_POST['representante_tags_permitidos'] ?? '',
                    'dir_calle'       => $_POST['dir_calle'] ?? '',
                    'dir_numero'      => $_POST['dir_numero'] ?? '',
                    'dir_colonia'     => $_POST['dir_colonia'] ?? '',
                    'dir_ciudad'      => $_POST['dir_ciudad'] ?? '',
                    'dir_estado'      => $_POST['dir_estado'] ?? '',
                    'dir_cp'          => $_POST['dir_cp'] ?? '',
                    'activo'          => $usuario['activo'] ?? 1
                ]);
            } else {
                $perfilRepresentanteModel->desactivarPorUsuario($id);
            }

            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Usuario actualizado exitosamente']);
            break;

        case 'reasignar_superior':
            if (!$es_super_admin) {
                throw new Exception('No tiene permisos para reasignar usuarios');
            }
            $repId    = (int)($_POST['rep_id'] ?? 0);
            $nuevoSup = (int)($_POST['nuevo_superior_id'] ?? 0);
            if (!$repId || !$nuevoSup) {
                throw new Exception('Datos incompletos');
            }
            $rep = $adminModel->getById($repId);
            $sup = $adminModel->getById($nuevoSup);
            if (!$rep || !$sup) {
                throw new Exception('Usuario no encontrado');
            }
            if (($rep['rol_codigo'] ?? '') !== 'representante') {
                throw new Exception('Solo se pueden reasignar representantes');
            }
            if ((int)($sup['nivel_jerarquico'] ?? 0) !== 3) {
                throw new Exception('El destino debe ser un Gerente de Distrito');
            }
            $adminModel->update($repId, ['superior_id' => $nuevoSup]);
            echo json_encode([
                'success'  => true,
                'message'  => 'Representante reasignado a ' . $sup['nombre'],
                'sup_nombre' => $sup['nombre'],
            ]);
            break;

        case 'toggle':
            if (!$es_super_admin) {
                throw new Exception('No tiene permisos para cambiar el estado');
            }
            
            $id = $_GET['id'] ?? $_POST['id'] ?? 0;
            $usuario = $adminModel->getById($id);
            
            if (!$usuario) {
                throw new Exception('Usuario no encontrado');
            }
            
            $nuevo_estado = $usuario['activo'] ? 0 : 1;
            $adminModel->update($id, ['activo' => $nuevo_estado]);

            if (($usuario['rol_codigo'] ?? '') === 'representante') {
                $perfilRepresentanteModel->guardarParaUsuario($id, [
                    'codigo' => $usuario['representante_codigo'] ?? '',
                    'telefono' => $usuario['representante_telefono'] ?? '',
                    'email' => $usuario['representante_email'] ?? '',
                    'tags_permitidos' => $usuario['representante_tags_permitidos'] ?? '',
                    'activo' => $nuevo_estado
                ]);
            }
            
            echo json_encode([
                'success' => true, 
                'message' => 'Estado actualizado',
                'nuevo_estado' => $nuevo_estado
            ]);
            break;
            
        case 'obtener':
            $id = $_GET['id'] ?? 0;
            $usuario = $adminModel->getById($id);
            
            if (!$usuario) {
                throw new Exception('Usuario no encontrado');
            }
            
            // Solo admins o el mismo usuario pueden ver datos completos
            if (!$es_super_admin && $usuario['id'] != $_SESSION['admin_id']) {
                throw new Exception('No tiene permisos');
            }

            // Incluir perfil de representante si aplica
            if (($usuario['rol_codigo'] ?? '') === 'representante') {
                $perfil = $perfilRepresentanteModel->getByAdminId($id);
                if ($perfil) {
                    $usuario['representante_codigo']          = $perfil['codigo'] ?? '';
                    $usuario['representante_telefono']        = $perfil['telefono'] ?? '';
                    $usuario['representante_email']           = $perfil['email'] ?? '';
                    $usuario['representante_tags_permitidos'] = $perfil['tags_permitidos'] ?? '';
                    $usuario['dir_calle']                     = $perfil['dir_calle'] ?? '';
                    $usuario['dir_numero']                    = $perfil['dir_numero'] ?? '';
                    $usuario['dir_colonia']                   = $perfil['dir_colonia'] ?? '';
                    $usuario['dir_ciudad']                    = $perfil['dir_ciudad'] ?? '';
                    $usuario['dir_estado']                    = $perfil['dir_estado'] ?? '';
                    $usuario['dir_cp']                        = $perfil['dir_cp'] ?? '';
                }
            }
            
            echo json_encode(['success' => true, 'data' => $usuario]);
            break;
            
        case 'listar':
            $usuarios = $adminModel->getAll();
            echo json_encode(['success' => true, 'data' => $usuarios]);
            break;
            
        case 'subordinados':
            $id = $_GET['id'] ?? $_SESSION['admin_id'];
            $subordinados = $adminModel->getSubordinados($id);
            echo json_encode(['success' => true, 'data' => $subordinados]);
            break;
            
        default:
            throw new Exception('Acción no válida');
    }
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    $msg = $e->getMessage();
    if (str_contains($msg, '1062') || str_contains($msg, 'Duplicate entry')) {
        if (str_contains($msg, 'email')) {
            $msg = 'El correo electrónico ya está registrado. Usa uno diferente.';
        } elseif (str_contains($msg, 'usuario')) {
            $msg = 'El nombre de usuario ya existe. Elige otro.';
        } else {
            $msg = 'Ya existe un registro con esos datos. Verifica e intenta de nuevo.';
        }
    }

    echo json_encode([
        'success' => false,
        'message' => $msg
    ]);
}
