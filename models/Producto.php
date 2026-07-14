<?php
require_once __DIR__ . '/../config/database.php';

class Producto {
    private $conn;
    
    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }
    
    public function getAll() {
        $query = "SELECT * FROM productos ORDER BY activo DESC, producto ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getAllActivos() {
        $query = "SELECT * FROM productos WHERE activo = 1 ORDER BY producto ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Obtener productos activos filtrados por tags
     * @param string|null $tags_permitidos Tags del representante (ej: "cosmetico,natural" o "*" o null)
     * @return array Lista de productos filtrados
     */
    public function getAllActivosByTags($tags_permitidos = null) {
        // Si no hay tags o es "*", retornar todos los productos activos
        if (empty($tags_permitidos) || $tags_permitidos === '*') {
            return $this->getAllActivos();
        }
        
        // Convertir string de tags en array
        $tags_array = array_map('trim', explode(',', $tags_permitidos));
        
        // Construir query con FIND_IN_SET para cada tag
        $conditions = [];
        foreach ($tags_array as $tag) {
            $conditions[] = "FIND_IN_SET(:tag_" . md5($tag) . ", REPLACE(tags, ',', ','))";
        }
        
        $query = "SELECT * FROM productos 
                  WHERE activo = 1 
                  AND tags IS NOT NULL 
                  AND (" . implode(' OR ', $conditions) . ")
                  ORDER BY producto ASC";
        
        $stmt = $this->conn->prepare($query);
        
        // Bind cada tag
        foreach ($tags_array as $tag) {
            $stmt->bindValue(':tag_' . md5($tag), $tag);
        }
        
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Obtener todos los tags únicos del sistema
     * @return array Lista de tags únicos
     */
    public function getAllTags() {
        $query = "SELECT DISTINCT tags FROM productos WHERE tags IS NOT NULL AND tags != ''";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        $tags_unicos = [];
        foreach ($results as $row) {
            $tags = array_map('trim', explode(',', $row['tags']));
            $tags_unicos = array_merge($tags_unicos, $tags);
        }
        
        $tags_unicos = array_unique($tags_unicos);
        sort($tags_unicos);
        
        return $tags_unicos;
    }
    
    public function getById($id) {
        $query = "SELECT * FROM productos WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch();
    }
    
    public function create($producto, $existencia, $imagen = null, $activo = 1, $tags = null, $sin_cargo_envio = 0, $en_carrusel = 0, $codigo_barras = null, $marca = null, $impuesto = 0.16) {
        $query = "INSERT INTO productos (producto, marca, imagen, existencia, activo, tags, sin_cargo_envio, en_carrusel, codigo_barras, impuesto) 
                  VALUES (:producto, :marca, :imagen, :existencia, :activo, :tags, :sin_cargo_envio, :en_carrusel, :codigo_barras, :impuesto)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':producto', $producto);
        $stmt->bindParam(':marca', $marca);
        $stmt->bindParam(':imagen', $imagen);
        $stmt->bindParam(':existencia', $existencia);
        $stmt->bindParam(':activo', $activo);
        $stmt->bindParam(':tags', $tags);
        $stmt->bindParam(':sin_cargo_envio', $sin_cargo_envio, PDO::PARAM_INT);
        $stmt->bindParam(':en_carrusel', $en_carrusel, PDO::PARAM_INT);
        $stmt->bindParam(':codigo_barras', $codigo_barras);
        $stmt->bindParam(':impuesto', $impuesto);
        return $stmt->execute();
    }
    
    public function update($id, $producto, $existencia, $imagen = null, $activo = 1, $tags = null, $sin_cargo_envio = 0, $en_carrusel = 0, $codigo_barras = null, $marca = null, $impuesto = 0.16) {
        // Si la imagen es null, no la actualizamos
        if ($imagen === null) {
            $query = "UPDATE productos SET producto = :producto, marca = :marca, existencia = :existencia, activo = :activo, tags = :tags, sin_cargo_envio = :sin_cargo_envio, en_carrusel = :en_carrusel, codigo_barras = :codigo_barras, impuesto = :impuesto WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':producto', $producto);
            $stmt->bindParam(':marca', $marca);
            $stmt->bindParam(':existencia', $existencia);
            $stmt->bindParam(':activo', $activo);
            $stmt->bindParam(':tags', $tags);
            $stmt->bindParam(':sin_cargo_envio', $sin_cargo_envio, PDO::PARAM_INT);
            $stmt->bindParam(':en_carrusel', $en_carrusel, PDO::PARAM_INT);
            $stmt->bindParam(':codigo_barras', $codigo_barras);
            $stmt->bindParam(':impuesto', $impuesto);
        } else {
            $query = "UPDATE productos SET producto = :producto, marca = :marca, imagen = :imagen, existencia = :existencia, activo = :activo, tags = :tags, sin_cargo_envio = :sin_cargo_envio, en_carrusel = :en_carrusel, codigo_barras = :codigo_barras, impuesto = :impuesto WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':producto', $producto);
            $stmt->bindParam(':marca', $marca);
            $stmt->bindParam(':imagen', $imagen);
            $stmt->bindParam(':existencia', $existencia);
            $stmt->bindParam(':activo', $activo);
            $stmt->bindParam(':tags', $tags);
            $stmt->bindParam(':sin_cargo_envio', $sin_cargo_envio, PDO::PARAM_INT);
            $stmt->bindParam(':en_carrusel', $en_carrusel, PDO::PARAM_INT);
            $stmt->bindParam(':codigo_barras', $codigo_barras);
            $stmt->bindParam(':impuesto', $impuesto);
        }
        return $stmt->execute();
    }

    public function getCarrusel($tags_permitidos = null) {
        $base = "SELECT p.*, 
                         (SELECT rp.precio FROM rangos_precios rp 
                          WHERE rp.producto_id = p.id 
                          ORDER BY rp.cantidad_min ASC LIMIT 1) AS precio_base
                  FROM productos p 
                  WHERE p.activo = 1 AND p.en_carrusel = 1";

        // Si hay tags y no es comodín, filtrar igual que getAllActivosByTags
        if (!empty($tags_permitidos) && $tags_permitidos !== '*') {
            $tags_array = array_map('trim', explode(',', $tags_permitidos));
            $conditions = [];
            foreach ($tags_array as $tag) {
                $conditions[] = "FIND_IN_SET(:tag_" . md5($tag) . ", REPLACE(p.tags, ' ', ''))";
            }
            $base .= " AND p.tags IS NOT NULL AND (" . implode(' OR ', $conditions) . ")";
            $base .= " ORDER BY p.producto ASC";
            $stmt = $this->conn->prepare($base);
            foreach ($tags_array as $tag) {
                $stmt->bindValue(':tag_' . md5($tag), $tag);
            }
        } else {
            $base .= " ORDER BY p.producto ASC";
            $stmt = $this->conn->prepare($base);
        }

        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function delete($id) {
        $query = "DELETE FROM productos WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }
    
    // Métodos para rangos de precios
    public function getRangosPrecios($producto_id) {
        $query = "SELECT * FROM rangos_precios WHERE producto_id = :producto_id ORDER BY cantidad_min ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':producto_id', $producto_id);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function createRangoPrecio($producto_id, $cantidad_min, $cantidad_max, $precio) {
        $query = "INSERT INTO rangos_precios (producto_id, cantidad_min, cantidad_max, precio) 
                  VALUES (:producto_id, :cantidad_min, :cantidad_max, :precio)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':producto_id', $producto_id);
        $stmt->bindParam(':cantidad_min', $cantidad_min);
        $stmt->bindParam(':cantidad_max', $cantidad_max);
        $stmt->bindParam(':precio', $precio);
        return $stmt->execute();
    }
    
    public function deleteRangoPrecio($id) {
        $query = "DELETE FROM rangos_precios WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }
    
    public function getRangoPrecioById($id) {
        $query = "SELECT * FROM rangos_precios WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch();
    }
    
    public function updateRangoPrecio($id, $cantidad_min, $cantidad_max, $precio) {
        $query = "UPDATE rangos_precios 
                  SET cantidad_min = :cantidad_min, cantidad_max = :cantidad_max, precio = :precio 
                  WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':cantidad_min', $cantidad_min);
        $stmt->bindParam(':cantidad_max', $cantidad_max);
        $stmt->bindParam(':precio', $precio);
        return $stmt->execute();
    }
    
    public function getPrecioByQuantity($producto_id, $cantidad) {
        $query = "SELECT precio FROM rangos_precios 
                  WHERE producto_id = :producto_id 
                  AND cantidad_min <= :cantidad 
                  AND (cantidad_max >= :cantidad OR cantidad_max IS NULL)
                  ORDER BY cantidad_min DESC LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':producto_id', $producto_id);
        $stmt->bindParam(':cantidad', $cantidad);
        $stmt->execute();
        $result = $stmt->fetch();
        return $result ? $result['precio'] : null;
    }
}
