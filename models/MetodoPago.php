<?php
require_once __DIR__ . '/../config/database.php';

class MetodoPago {
    private $conn;
    private $table = 'metodos_pago';

    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }

    // Obtener todos los métodos de pago
    public function getAll() {
        $query = "SELECT * FROM " . $this->table . " ORDER BY orden ASC, id ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Obtener método por ID
    public function getById($id) {
        $query = "SELECT * FROM " . $this->table . " WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Obtener método por tipo (transferencia, oxxo, etc)
    public function getByMetodo($metodo) {
        $query = "SELECT * FROM " . $this->table . " WHERE metodo = :metodo";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':metodo', $metodo);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Obtener métodos activos
    public function getActivos() {
        $query = "SELECT * FROM " . $this->table . " WHERE activo = 1 ORDER BY orden ASC, id ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Actualizar método de pago
    public function update($id, $datos) {
        $query = "UPDATE " . $this->table . " 
                  SET nombre_display = :nombre_display,
                      descripcion = :descripcion,
                      instrucciones = :instrucciones,
                      banco = :banco,
                      titular = :titular,
                      cuenta = :cuenta,
                      clabe = :clabe,
                      numero_tarjeta = :numero_tarjeta,
                      beneficiario = :beneficiario,
                      rfc_empresa = :rfc_empresa,
                      referencia = :referencia,
                      monto_minimo = :monto_minimo,
                      monto_maximo = :monto_maximo,
                      comision_porcentaje = :comision_porcentaje,
                      activo = :activo,
                      orden = :orden,
                      imagen = :imagen,
                      paypal_client_id = :paypal_client_id,
                      paypal_secret = :paypal_secret,
                      paypal_mode = :paypal_mode,
                      paypal_webhook_url = :paypal_webhook_url,
                      paypal_sin_cuenta = :paypal_sin_cuenta,
                      mp_public_key = :mp_public_key,
                      mp_access_token = :mp_access_token,
                      mp_mode = :mp_mode,
                      mp_sin_cuenta = :mp_sin_cuenta,
                      ecartpay_public_key = :ecartpay_public_key,
                      ecartpay_private_key = :ecartpay_private_key,
                      ecartpay_sandbox = :ecartpay_sandbox,
                      openpay_merchant_id = :openpay_merchant_id,
                      openpay_private_key = :openpay_private_key,
                      openpay_public_key = :openpay_public_key,
                      openpay_sandbox = :openpay_sandbox,
                      flujo_a = :flujo_a,
                      flujo_b = :flujo_b,
                      flujo_c = :flujo_c,
                      flujo_d = :flujo_d
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':nombre_display', $datos['nombre_display']);
        $stmt->bindParam(':descripcion', $datos['descripcion']);
        $stmt->bindParam(':instrucciones', $datos['instrucciones']);
        $stmt->bindParam(':banco', $datos['banco']);
        $stmt->bindParam(':titular', $datos['titular']);
        $stmt->bindParam(':cuenta', $datos['cuenta']);
        $stmt->bindParam(':clabe', $datos['clabe']);
        $stmt->bindParam(':numero_tarjeta', $datos['numero_tarjeta']);
        $stmt->bindParam(':beneficiario', $datos['beneficiario']);
        $stmt->bindParam(':rfc_empresa', $datos['rfc_empresa']);
        $stmt->bindParam(':referencia', $datos['referencia']);
        $stmt->bindParam(':monto_minimo', $datos['monto_minimo']);
        $stmt->bindParam(':monto_maximo', $datos['monto_maximo']);
        $stmt->bindParam(':comision_porcentaje', $datos['comision_porcentaje']);
        $stmt->bindParam(':activo', $datos['activo']);
        $stmt->bindParam(':orden', $datos['orden']);
        $stmt->bindParam(':imagen', $datos['imagen']);
        $stmt->bindParam(':paypal_client_id', $datos['paypal_client_id']);
        $stmt->bindParam(':paypal_secret', $datos['paypal_secret']);
        $stmt->bindParam(':paypal_mode', $datos['paypal_mode']);
        $stmt->bindParam(':paypal_webhook_url', $datos['paypal_webhook_url']);
        $stmt->bindParam(':paypal_sin_cuenta', $datos['paypal_sin_cuenta']);
        $stmt->bindParam(':mp_public_key', $datos['mp_public_key']);
        $stmt->bindParam(':mp_access_token', $datos['mp_access_token']);
        $stmt->bindParam(':mp_mode', $datos['mp_mode']);
        $stmt->bindParam(':mp_sin_cuenta', $datos['mp_sin_cuenta']);
        $stmt->bindParam(':ecartpay_public_key', $datos['ecartpay_public_key']);
        $stmt->bindParam(':ecartpay_private_key', $datos['ecartpay_private_key']);
        $stmt->bindParam(':ecartpay_sandbox', $datos['ecartpay_sandbox']);
        $stmt->bindParam(':openpay_merchant_id', $datos['openpay_merchant_id']);
        $stmt->bindParam(':openpay_private_key', $datos['openpay_private_key']);
        $stmt->bindParam(':openpay_public_key', $datos['openpay_public_key']);
        $stmt->bindParam(':openpay_sandbox', $datos['openpay_sandbox']);
        $stmt->bindParam(':flujo_a', $datos['flujo_a']);
        $stmt->bindParam(':flujo_b', $datos['flujo_b']);
        $stmt->bindParam(':flujo_c', $datos['flujo_c']);
        $stmt->bindParam(':flujo_d', $datos['flujo_d']);

        return $stmt->execute();
    }

    // Obtener métodos activos para un flujo específico (a, b, c, d)
    public function getActivosPorFlujo($flujo) {
        $col = 'flujo_' . $flujo;
        $query = "SELECT * FROM {$this->table} WHERE activo = 1 AND {$col} = 1 ORDER BY orden ASC, id ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Cambiar estado activo/inactivo
    public function toggleActivo($id) {
        $query = "UPDATE " . $this->table . " SET activo = NOT activo WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }
}
