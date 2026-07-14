<?php
require_once __DIR__ . '/../config/database.php';

class RepresentantePerfil {
    private $conn;

    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function getByAdminId($admin_id) {
        $stmt = $this->conn->prepare("
            SELECT id, admin_id, codigo, telefono, email, tags_permitidos,
                   dir_calle, dir_numero, dir_colonia, dir_ciudad, dir_estado, dir_cp,
                   dir_referencias, dir_quien_recibe,
                   activo, created_at, updated_at
            FROM representante_perfiles
            WHERE admin_id = :admin_id
        ");
        $stmt->bindValue(':admin_id', $admin_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function guardarParaUsuario($admin_id, $datos) {
        $codigo = strtoupper(trim($datos['codigo'] ?? ''));

        if ($codigo === '') {
            throw new Exception('El codigo del representante es requerido');
        }

        $telefono        = $this->normalizarVacio($datos['telefono'] ?? null);
        $email           = $this->normalizarVacio($datos['email'] ?? null);
        $tags_permitidos = $this->normalizarVacio($datos['tags_permitidos'] ?? null);
        $activo          = isset($datos['activo']) ? (int) $datos['activo'] : 1;
        $dir_calle       = $this->normalizarVacio($datos['dir_calle'] ?? null);
        $dir_numero      = $this->normalizarVacio($datos['dir_numero'] ?? null);
        $dir_colonia     = $this->normalizarVacio($datos['dir_colonia'] ?? null);
        $dir_ciudad      = $this->normalizarVacio($datos['dir_ciudad'] ?? null);
        $dir_estado      = $this->normalizarVacio($datos['dir_estado'] ?? null);
        $dir_cp              = $this->normalizarVacio($datos['dir_cp']           ?? null);
        $dir_referencias     = $this->normalizarVacio($datos['dir_referencias']  ?? null);
        $dir_quien_recibe    = $this->normalizarVacio($datos['dir_quien_recibe'] ?? null);

        $existente = $this->getByAdminId($admin_id);

        if ($existente) {
            $stmt = $this->conn->prepare("
                UPDATE representante_perfiles
                SET codigo = :codigo,
                    telefono = :telefono,
                    email = :email,
                    tags_permitidos = :tags_permitidos,
                    dir_calle = :dir_calle,
                    dir_numero = :dir_numero,
                    dir_colonia = :dir_colonia,
                    dir_ciudad = :dir_ciudad,
                    dir_estado = :dir_estado,
                    dir_cp = :dir_cp,
                    dir_referencias = :dir_referencias,
                    dir_quien_recibe = :dir_quien_recibe,
                    activo = :activo
                WHERE admin_id = :admin_id
            ");
        } else {
            $stmt = $this->conn->prepare("
                INSERT INTO representante_perfiles
                    (admin_id, codigo, telefono, email, tags_permitidos,
                     dir_calle, dir_numero, dir_colonia, dir_ciudad, dir_estado, dir_cp,
                     dir_referencias, dir_quien_recibe, activo)
                VALUES
                    (:admin_id, :codigo, :telefono, :email, :tags_permitidos,
                     :dir_calle, :dir_numero, :dir_colonia, :dir_ciudad, :dir_estado, :dir_cp,
                     :dir_referencias, :dir_quien_recibe, :activo)
            ");
        }

        $stmt->bindValue(':admin_id',        $admin_id, PDO::PARAM_INT);
        $stmt->bindValue(':codigo',           $codigo);
        $stmt->bindValue(':telefono',         $telefono);
        $stmt->bindValue(':email',            $email);
        $stmt->bindValue(':tags_permitidos',  $tags_permitidos);
        $stmt->bindValue(':dir_calle',        $dir_calle);
        $stmt->bindValue(':dir_numero',       $dir_numero);
        $stmt->bindValue(':dir_colonia',      $dir_colonia);
        $stmt->bindValue(':dir_ciudad',       $dir_ciudad);
        $stmt->bindValue(':dir_estado',       $dir_estado);
        $stmt->bindValue(':dir_cp',           $dir_cp);
        $stmt->bindValue(':dir_referencias',   $dir_referencias);
        $stmt->bindValue(':dir_quien_recibe',  $dir_quien_recibe);
        $stmt->bindValue(':activo',            $activo, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function desactivarPorUsuario($admin_id) {
        $stmt = $this->conn->prepare("
            UPDATE representante_perfiles
            SET activo = 0
            WHERE admin_id = :admin_id
        ");
        $stmt->bindValue(':admin_id', $admin_id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    private function normalizarVacio($valor) {
        $valor = trim((string) $valor);
        return $valor === '' ? null : $valor;
    }
}
