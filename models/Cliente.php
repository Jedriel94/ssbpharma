<?php
require_once __DIR__ . '/../config/database.php';

class Cliente {
    private $conn;
    
    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }
    
    public function getAll() {
        $query = "SELECT * FROM clientes ORDER BY created_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getById($id) {
        $query = "SELECT * FROM clientes WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch();
    }
    
    public function getByTelefono($telefono) {
        $query = "SELECT * FROM clientes WHERE telefono = :telefono";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':telefono', $telefono);
        $stmt->execute();
        return $stmt->fetch();
    }
    
    public function create($telefono, $nombre = null) {
        // Verificar si ya existe
        if ($this->getByTelefono($telefono)) {
            return false;
        }
        
        $identidadRepresentante = $this->resolverRepresentanteDesdeCookie();
        $representante_admin_id = $identidadRepresentante['representante_admin_id'];
        
        $query = "INSERT INTO clientes (telefono, nombre, representante_admin_id) VALUES (:telefono, :nombre, :representante_admin_id)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':telefono', $telefono);
        $stmt->bindParam(':nombre', $nombre);
        $stmt->bindValue(':representante_admin_id', $representante_admin_id, $representante_admin_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
        return $stmt->execute();
    }
    
    public function update($id, $telefono, $nombre) {
        $query = "UPDATE clientes SET telefono = :telefono, nombre = :nombre WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':telefono', $telefono);
        $stmt->bindParam(':nombre', $nombre);
        return $stmt->execute();
    }
    
    public function delete($id) {
        $query = "DELETE FROM clientes WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }
    
    public function getOrCreate($telefono, $nombre = null) {
        $cliente = $this->getByTelefono($telefono);
        
        if (!$cliente) {
            $this->create($telefono, $nombre);
            $cliente = $this->getByTelefono($telefono);
        }
        
        return $cliente;
    }
    
    public function updateDatos(
        $telefono, 
        $nombre = null, 
        // Datos de Envío
        $calle = null, 
        $numero = null, 
        $colonia = null, 
        $cp = null, 
        $estado = null, 
        $ciudad = null,
        $referencias = null, 
        $quien_recibe = null,
        // Datos Médicos
        $nombre_medico = null,
        $telefono_medico = null,
        $nombre_representante = null,
        // Datos Fiscales
        $rfc = null,
        $razon_social = null,
        $email_factura = null,
        $codigo_postal = null,
        $empresa = null,
        $regimen = null,
        $uso_cfdi = null,
        $regimen_fiscal = null,
        // Archivos
        $constancia_fiscal = null,
        // Password
        $password_hash = null
    ) {
        // Construir la consulta con todos los campos
        $campos = [
            'nombre = :nombre',
            'calle = :calle',
            'numero = :numero',
            'colonia = :colonia',
            'cp = :cp',
            'estado = :estado',
            'ciudad = :ciudad',
            'referencias = :referencias',
            'quien_recibe = :quien_recibe',
            'nombre_medico = :nombre_medico',
            'telefono_medico = :telefono_medico',
            'nombre_representante = :nombre_representante',
            'rfc = :rfc',
            'razon_social = :razon_social',
            'email_factura = :email_factura',
            'codigo_postal = :codigo_postal',
            // 'empresa' eliminado - se usa razon_social
            'regimen = :regimen',
            'uso_cfdi = :uso_cfdi',
            'regimen_fiscal = :regimen_fiscal'
        ];
        
        if ($constancia_fiscal !== null) {
            $campos[] = 'constancia_fiscal = :constancia_fiscal';
        }
        
        if ($password_hash !== null) {
            $campos[] = 'password = :password';
        }
        
        $query = "UPDATE clientes SET " . implode(', ', $campos) . " WHERE telefono = :telefono";
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':telefono', $telefono);
        $stmt->bindParam(':nombre', $nombre);
        $stmt->bindParam(':calle', $calle);
        $stmt->bindParam(':numero', $numero);
        $stmt->bindParam(':colonia', $colonia);
        $stmt->bindParam(':cp', $cp);
        $stmt->bindParam(':estado', $estado);
        $stmt->bindParam(':ciudad', $ciudad);
        $stmt->bindParam(':referencias', $referencias);
        $stmt->bindParam(':quien_recibe', $quien_recibe);
        $stmt->bindParam(':nombre_medico', $nombre_medico);
        $stmt->bindParam(':telefono_medico', $telefono_medico);
        $stmt->bindParam(':nombre_representante', $nombre_representante);
        $stmt->bindParam(':rfc', $rfc);
        $stmt->bindParam(':razon_social', $razon_social);
        $stmt->bindParam(':email_factura', $email_factura);
        $stmt->bindParam(':codigo_postal', $codigo_postal);
        // $empresa eliminado - se usa razon_social
        $stmt->bindParam(':regimen', $regimen);
        $stmt->bindParam(':uso_cfdi', $uso_cfdi);
        $stmt->bindParam(':regimen_fiscal', $regimen_fiscal);
        
        if ($constancia_fiscal !== null) {
            $stmt->bindParam(':constancia_fiscal', $constancia_fiscal);
        }
        
        if ($password_hash !== null) {
            $stmt->bindParam(':password', $password_hash);
        }
        
        return $stmt->execute();
    }
    
    // Método para actualizar SOLO datos adicionales (médico y representante) sin sobrescribir otros campos
    public function updateDatosAdicionales($telefono, $nombre_medico, $telefono_medico, $nombre_representante) {
        $campos = [];
        $valores = [];
        
        if ($nombre_medico !== null && $nombre_medico !== '') {
            $campos[] = 'nombre_medico = :nombre_medico';
            $valores[':nombre_medico'] = $nombre_medico;
        }
        
        if ($telefono_medico !== null && $telefono_medico !== '') {
            $campos[] = 'telefono_medico = :telefono_medico';
            $valores[':telefono_medico'] = $telefono_medico;
        }
        
        if ($nombre_representante !== null && $nombre_representante !== '') {
            $campos[] = 'nombre_representante = :nombre_representante';
            $valores[':nombre_representante'] = $nombre_representante;
        }
        
        // Si no hay campos para actualizar, retornar true (no hay error)
        if (empty($campos)) {
            return true;
        }
        
        $query = "UPDATE clientes SET " . implode(', ', $campos) . " WHERE telefono = :telefono";
        $stmt = $this->conn->prepare($query);
        
        // Bind del teléfono
        $stmt->bindParam(':telefono', $telefono);
        
        // Bind de los valores dinámicos
        foreach ($valores as $key => $valor) {
            $stmt->bindValue($key, $valor);
        }
        
        return $stmt->execute();
    }

    public function updateDatosFiscales($telefono, $rfc, $razon_social, $email_factura, $codigo_postal, $uso_cfdi, $regimen_fiscal) {
        $campos = [];
        $valores = [];
        
        // Solo agregar campos que tengan valor
        if ($rfc !== null && $rfc !== '') {
            $campos[] = 'rfc = :rfc';
            $valores[':rfc'] = $rfc;
        }
        
        if ($razon_social !== null && $razon_social !== '') {
            $campos[] = 'razon_social = :razon_social';
            $valores[':razon_social'] = $razon_social;
        }
        
        if ($email_factura !== null && $email_factura !== '') {
            $campos[] = 'email_factura = :email_factura';
            $valores[':email_factura'] = $email_factura;
        }
        
        if ($codigo_postal !== null && $codigo_postal !== '') {
            $campos[] = 'codigo_postal = :codigo_postal';
            $valores[':codigo_postal'] = $codigo_postal;
        }
        
        if ($uso_cfdi !== null && $uso_cfdi !== '') {
            $campos[] = 'uso_cfdi = :uso_cfdi';
            $valores[':uso_cfdi'] = $uso_cfdi;
        }
        
        if ($regimen_fiscal !== null && $regimen_fiscal !== '') {
            $campos[] = 'regimen_fiscal = :regimen_fiscal';
            $valores[':regimen_fiscal'] = $regimen_fiscal;
        }
        
        // Si no hay campos para actualizar, retornar true (no hay error)
        if (empty($campos)) {
            return true;
        }
        
        $query = "UPDATE clientes SET " . implode(', ', $campos) . " WHERE telefono = :telefono";
        $stmt = $this->conn->prepare($query);
        
        // Bind del teléfono
        $stmt->bindParam(':telefono', $telefono);
        
        // Bind de los valores dinámicos
        foreach ($valores as $key => $valor) {
            $stmt->bindValue($key, $valor);
        }
        
        return $stmt->execute();
    }

    public function updateDatosEnvio($telefono, $calle, $numero, $colonia, $cp, $estado, $ciudad, $referencias, $quien_recibe) {
        $campos = [];
        $valores = [];
        
        // Solo agregar campos que tengan valor
        if ($calle !== null && $calle !== '') {
            $campos[] = 'calle = :calle';
            $valores[':calle'] = $calle;
        }
        
        if ($numero !== null && $numero !== '') {
            $campos[] = 'numero = :numero';
            $valores[':numero'] = $numero;
        }
        
        if ($colonia !== null && $colonia !== '') {
            $campos[] = 'colonia = :colonia';
            $valores[':colonia'] = $colonia;
        }
        
        if ($cp !== null && $cp !== '') {
            $campos[] = 'cp = :cp';
            $valores[':cp'] = $cp;
        }
        
        if ($estado !== null && $estado !== '') {
            $campos[] = 'estado = :estado';
            $valores[':estado'] = $estado;
        }
        
        if ($ciudad !== null && $ciudad !== '') {
            $campos[] = 'ciudad = :ciudad';
            $valores[':ciudad'] = $ciudad;
        }
        
        if ($referencias !== null && $referencias !== '') {
            $campos[] = 'referencias = :referencias';
            $valores[':referencias'] = $referencias;
        }
        
        if ($quien_recibe !== null && $quien_recibe !== '') {
            $campos[] = 'quien_recibe = :quien_recibe';
            $valores[':quien_recibe'] = $quien_recibe;
        }
        
        // Si no hay campos para actualizar, retornar true (no hay error)
        if (empty($campos)) {
            return true;
        }
        
        $query = "UPDATE clientes SET " . implode(', ', $campos) . " WHERE telefono = :telefono";
        $stmt = $this->conn->prepare($query);
        
        // Bind del teléfono
        $stmt->bindParam(':telefono', $telefono);
        
        // Bind de los valores dinámicos
        foreach ($valores as $key => $valor) {
            $stmt->bindValue($key, $valor);
        }
        
        return $stmt->execute();
    }
    
    /**
     * Actualizar representante del cliente solo si no tiene uno asignado
     * (Respeta el PRIMER representante que lo refirió)
     */
    public function actualizarRepresentanteSiNoTiene($telefono) {
        $identidadRepresentante = $this->resolverRepresentanteDesdeCookie();
        $representante_admin_id = $identidadRepresentante['representante_admin_id'];
        
        // Si no hay cookie, no hacer nada
        if (!$representante_admin_id) {
            return true;
        }
        
        // Solo actualizar si el cliente NO tiene representante asignado
        $query = "UPDATE clientes
                  SET representante_admin_id = :representante_admin_id
                  WHERE telefono = :telefono 
                  AND (representante_admin_id IS NULL OR representante_admin_id = 0)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':telefono', $telefono);
        $stmt->bindValue(':representante_admin_id', $representante_admin_id, $representante_admin_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
        
        return $stmt->execute();
    }

    private function resolverRepresentanteDesdeCookie() {
        $representante_admin_id = isset($_COOKIE['botikit_rep_admin']) ? intval($_COOKIE['botikit_rep_admin']) : null;

        return [
            'representante_admin_id' => $representante_admin_id ?: null
        ];
    }

    public function updateNotif($id, $notif_confirmacion, $notif_factura) {
        $stmt = $this->conn->prepare(
            "UPDATE clientes SET notif_confirmacion = :nc, notif_factura = :nf WHERE id = :id"
        );
        return $stmt->execute([':nc' => (int)$notif_confirmacion, ':nf' => (int)$notif_factura, ':id' => (int)$id]);
    }
}
