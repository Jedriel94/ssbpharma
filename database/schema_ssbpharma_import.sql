-- Esquema unificado generado a partir de database/dbshop.sql
-- Fuente: dump productivo consolidado, sin ALTER TABLE finales para indices/FKs.
-- Base destino por defecto: solumedic_dbshop


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `administradores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `administradores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rol_id` int DEFAULT '1' COMMENT 'FK a roles (1=Admin por defecto)',
  `superior_id` int DEFAULT NULL COMMENT 'Jefe directo en jerarqu├¡a',
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `activo` tinyint(1) DEFAULT '1',
  `ruta` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Clave de ruta (N3/N4)',
  `desc_ruta` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Descripci├│n de la ruta',
  `celular` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Tel├®fono celular',
  `reset_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `usuario` (`usuario`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_administradores_rol` (`rol_id`),
  KEY `idx_administradores_superior` (`superior_id`),
  CONSTRAINT `fk_administradores_rol` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `fk_administradores_superior` FOREIGN KEY (`superior_id`) REFERENCES `administradores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clientes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clientes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `calle` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `colonia` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cp` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ciudad` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Ciudad de env├¡o',
  `referencias` text COLLATE utf8mb4_unicode_ci,
  `quien_recibe` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo_cliente` enum('medico','paciente') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medico',
  `especialidad` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre_medico` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre_representante` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `representante_admin_id` int DEFAULT NULL,
  `telefono_medico` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rfc` varchar(13) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `razon_social` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Raz├│n social o nombre completo',
  `email_factura` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Email para env├¡o de facturas',
  `codigo_postal` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'C├│digo postal fiscal',
  `regimen` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uso_cfdi` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `regimen_fiscal` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'R├®gimen fiscal (601, 612, 621, etc)',
  `constancia_fiscal` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notif_confirmacion` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Recibir correo al confirmar pedido',
  `notif_factura` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Recibir correo con factura adjunta',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `telefono` (`telefono`),
  KEY `idx_clientes_representante_admin` (`representante_admin_id`),
  CONSTRAINT `fk_clientes_representante_admin` FOREIGN KEY (`representante_admin_id`) REFERENCES `administradores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `configuracion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `configuracion` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clave` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valor` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fecha_actualizacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `clave` (`clave`),
  KEY `idx_clave` (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cupones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cupones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `tipo_descuento` enum('porcentaje','monto') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'porcentaje',
  `valor_descuento` decimal(10,2) NOT NULL,
  `tipo_aplicacion` enum('general','productos','tags','kits','representantes') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `aplicacion_ids` text COLLATE utf8mb4_unicode_ci,
  `aplicacion_admin_ids` text COLLATE utf8mb4_unicode_ci,
  `aplicacion_tags` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `minimo_compra` decimal(10,2) DEFAULT '0.00',
  `fecha_inicio` datetime NOT NULL,
  `fecha_expiracion` datetime NOT NULL,
  `usos_maximos` int DEFAULT NULL,
  `usos_actuales` int DEFAULT '0',
  `activo` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_codigo` (`codigo`),
  KEY `idx_activo_fechas` (`activo`,`fecha_inicio`,`fecha_expiracion`),
  KEY `idx_tipo_aplicacion` (`tipo_aplicacion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cupones_uso`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cupones_uso` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cupon_id` int NOT NULL,
  `pedido_id` int NOT NULL,
  `cliente_id` int DEFAULT NULL,
  `representante_admin_id` int DEFAULT NULL,
  `monto_descuento` decimal(10,2) NOT NULL,
  `subtotal_pedido` decimal(10,2) NOT NULL,
  `fecha_uso` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cupon` (`cupon_id`),
  KEY `idx_pedido` (`pedido_id`),
  KEY `idx_cliente` (`cliente_id`),
  KEY `idx_fecha` (`fecha_uso`),
  KEY `idx_representante_admin` (`representante_admin_id`),
  CONSTRAINT `fk_cupones_uso_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cupones_uso_cupon` FOREIGN KEY (`cupon_id`) REFERENCES `cupones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cupones_uso_pedido` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cupones_uso_representante_admin` FOREIGN KEY (`representante_admin_id`) REFERENCES `administradores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `detalle_pedidos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `detalle_pedidos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pedido_id` int NOT NULL,
  `producto_id` int NOT NULL,
  `cantidad` int NOT NULL,
  `precio_unitario` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `impuesto` decimal(5,4) NOT NULL DEFAULT '0.1600' COMMENT 'Tasa IVA al momento de la venta',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `pedido_id` (`pedido_id`),
  KEY `producto_id` (`producto_id`),
  CONSTRAINT `detalle_pedidos_ibfk_1` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `detalle_pedidos_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `especialidades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `especialidades` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `orden` smallint NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `estados`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `estados` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(60) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_estado_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `kit_productos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kit_productos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `kit_id` int NOT NULL,
  `producto_id` int NOT NULL,
  `cantidad` int NOT NULL DEFAULT '1' COMMENT 'Cu├íntas unidades de este producto incluye el kit',
  `precio_unitario` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_kit_producto` (`kit_id`,`producto_id`),
  KEY `producto_id` (`producto_id`),
  CONSTRAINT `kit_productos_ibfk_1` FOREIGN KEY (`kit_id`) REFERENCES `kits` (`id`) ON DELETE CASCADE,
  CONSTRAINT `kit_productos_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `kit_ventas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kit_ventas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `kit_id` int NOT NULL,
  `pedido_id` int NOT NULL,
  `cantidad` int NOT NULL DEFAULT '1' COMMENT 'Cu├íntos kits se vendieron en este pedido',
  `precio_unitario` decimal(10,2) NOT NULL COMMENT 'Precio al que se vendi├│ el kit',
  `subtotal` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kit_id` (`kit_id`),
  KEY `idx_pedido_id` (`pedido_id`),
  KEY `idx_fecha` (`created_at`),
  CONSTRAINT `kit_ventas_ibfk_1` FOREIGN KEY (`kit_id`) REFERENCES `kits` (`id`) ON DELETE CASCADE,
  CONSTRAINT `kit_ventas_ibfk_2` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `kits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `imagen` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `precio_kit` decimal(10,2) NOT NULL COMMENT 'Precio especial del kit',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `en_carrusel` tinyint(1) NOT NULL DEFAULT '0',
  `orden` int DEFAULT '0' COMMENT 'Para ordenar en la visualizaci├│n',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activo` (`activo`),
  KEY `idx_orden` (`orden`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `liga_pago_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `liga_pago_queue` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pedido_id` int NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `nombre_cliente` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `metodo_pago` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'liga_pago',
  `estado` enum('pendiente','procesando','completado','error') COLLATE utf8mb4_unicode_ci DEFAULT 'pendiente',
  `enlace_generado` text COLLATE utf8mb4_unicode_ci,
  `error_mensaje` text COLLATE utf8mb4_unicode_ci,
  `intentos` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_estado` (`estado`),
  KEY `idx_pedido` (`pedido_id`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `liga_pago_queue_ibfk_1` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mensajes_pedido`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mensajes_pedido` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pedido_id` int NOT NULL,
  `usuario_tipo` enum('cliente','admin') COLLATE utf8mb4_unicode_ci NOT NULL,
  `mensaje` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `leido` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `pedido_id` (`pedido_id`),
  CONSTRAINT `mensajes_pedido_ibfk_1` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `metodos_pago`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `metodos_pago` (
  `id` int NOT NULL AUTO_INCREMENT,
  `metodo` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'transferencia, oxxo, etc',
  `activo` tinyint(1) DEFAULT '1' COMMENT 'Si est├í habilitado o no',
  `flujo_a` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Flujo A: Venta directa representante',
  `flujo_b` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Flujo B: Rep opera la tienda por el cliente',
  `flujo_c` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Flujo C: Cliente entra a la tienda v├¡a QR del rep',
  `flujo_d` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Flujo D: Cliente entra a la tienda sin QR',
  `nombre_display` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nombre para mostrar',
  `descripcion` text COLLATE utf8mb4_unicode_ci COMMENT 'Descripci├│n del m├®todo',
  `instrucciones` text COLLATE utf8mb4_unicode_ci COMMENT 'Instrucciones para el cliente',
  `banco` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nombre del banco',
  `titular` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Titular de la cuenta',
  `cuenta` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'N├║mero de cuenta',
  `clabe` varchar(18) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'CLABE interbancaria',
  `numero_tarjeta` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'N├║mero de tarjeta para dep├│sito en tienda',
  `beneficiario` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nombre del beneficiario',
  `rfc_empresa` varchar(13) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'RFC de la empresa para transferencias',
  `referencia` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Referencia o n├║mero de convenio',
  `monto_minimo` decimal(10,2) DEFAULT '0.00' COMMENT 'Monto m├¡nimo aceptado',
  `monto_maximo` decimal(10,2) DEFAULT '0.00' COMMENT 'Monto m├íximo aceptado',
  `comision_porcentaje` decimal(5,2) DEFAULT '0.00' COMMENT 'Porcentaje de comisi├│n',
  `imagen` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Ruta de imagen o logo',
  `orden` int DEFAULT '0',
  `paypal_client_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Client ID de PayPal',
  `paypal_secret` text COLLATE utf8mb4_unicode_ci COMMENT 'Secret Key de PayPal (encriptado)',
  `paypal_mode` enum('sandbox','production') COLLATE utf8mb4_unicode_ci DEFAULT 'sandbox' COMMENT 'Modo de PayPal',
  `paypal_webhook_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'URL del webhook de PayPal',
  `paypal_sin_cuenta` tinyint(1) DEFAULT '0' COMMENT 'Permite pago con tarjeta sin cuenta PayPal',
  `mp_public_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mp_access_token` text COLLATE utf8mb4_unicode_ci,
  `mp_mode` enum('sandbox','production') COLLATE utf8mb4_unicode_ci DEFAULT 'production',
  `mp_sin_cuenta` tinyint(1) DEFAULT '0' COMMENT 'Permite pago con tarjeta sin cuenta Mercado Pago',
  `ecartpay_public_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ecartpay_private_key` text COLLATE utf8mb4_unicode_ci,
  `ecartpay_sandbox` tinyint(1) DEFAULT '1',
  `ecartpay_token_cache` text COLLATE utf8mb4_unicode_ci,
  `ecartpay_token_expires` int DEFAULT NULL,
  `openpay_merchant_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `openpay_private_key` text COLLATE utf8mb4_unicode_ci,
  `openpay_public_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `openpay_sandbox` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `metodo` (`metodo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `municipios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `municipios` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `estado_id` tinyint unsigned NOT NULL,
  `nombre` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_municipio_estado` (`estado_id`),
  CONSTRAINT `fk_municipio_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pedidos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pedidos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cliente_id` int NOT NULL,
  `total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `cupon_codigo` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cupon_descuento` decimal(10,2) DEFAULT '0.00',
  `estado` enum('pendiente','por_verificar','confirmado','en_ruta','entregado','cancelado') COLLATE utf8mb4_unicode_ci DEFAULT 'pendiente',
  `notas` text COLLATE utf8mb4_unicode_ci,
  `comprobante_pago` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comprobante_envio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metodo_pago` enum('transferencia','tienda','tarjeta','liga_pago','paypal','oxxo','mercado_pago','ecartpay','efectivo') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `liga_pago` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'URL de pago enviada por el administrador',
  `calle` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `colonia` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cp_envio` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado_envio` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ciudad` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referencias` text COLLATE utf8mb4_unicode_ci,
  `quien_recibe` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requiere_factura` tinyint(1) DEFAULT '0' COMMENT 'Indica si el cliente solicita factura electr├│nica',
  `rfc` varchar(13) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `razon_social` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_factura` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `codigo_postal` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uso_cfdi` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Uso de CFDI (G01, G03, P01, etc)',
  `regimen_fiscal` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `factura_pdf` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `factura_xml` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `num_factura` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'N├║mero de folio de factura SAT',
  `nombre_medico` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre_representante` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `representante_admin_id` int DEFAULT NULL,
  `telefono_medico` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `canal` enum('cliente_directo','representante_qr','representante_directo') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cliente_directo' COMMENT 'Canal que origin├│ el pedido',
  `entrega_directa` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Pedido entregado f├¡sicamente por representante',
  `fecha_entrega_directa` datetime DEFAULT NULL,
  `estado_liquidacion` enum('no_aplica','pendiente','liquidado','rechazado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'no_aplica' COMMENT 'Control de liquidaci├│n para pagos en efectivo',
  `confirmado_por_admin_id` int DEFAULT NULL COMMENT 'Administrador que confirm├│ el pago',
  `fecha_confirmacion_pago` datetime DEFAULT NULL,
  `fecha_pago` datetime DEFAULT NULL,
  `fecha_por_verificar` datetime DEFAULT NULL,
  `paypal_order_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paypal_transaction_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paypal_payer_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paypal_payer_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mp_payment_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mp_status` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ecartpay_order_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `openpay_charge_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `cliente_id` (`cliente_id`),
  KEY `idx_rfc` (`rfc`),
  KEY `idx_requiere_factura` (`requiere_factura`),
  KEY `idx_cupon_codigo` (`cupon_codigo`),
  KEY `idx_pedidos_canal` (`canal`),
  KEY `idx_pedidos_entrega_directa` (`entrega_directa`),
  KEY `idx_pedidos_liquidacion` (`estado_liquidacion`),
  KEY `idx_pedidos_representante_admin` (`representante_admin_id`),
  KEY `fk_pedidos_confirmado_por_admin` (`confirmado_por_admin_id`),
  CONSTRAINT `fk_pedidos_confirmado_por_admin` FOREIGN KEY (`confirmado_por_admin_id`) REFERENCES `administradores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pedidos_representante_admin` FOREIGN KEY (`representante_admin_id`) REFERENCES `administradores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pedidos_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabla de pedidos con soporte para facturaci├│n electr├│nica';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `productos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `productos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `producto` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `marca` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `imagen` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `existencia` int NOT NULL DEFAULT '0',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `sin_cargo_envio` tinyint(1) DEFAULT '0',
  `en_carrusel` tinyint(1) NOT NULL DEFAULT '0',
  `tags` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `codigo_barras` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `impuesto` decimal(5,4) NOT NULL DEFAULT '0.1600' COMMENT 'Tasa IVA: 0.00=exento, 0.16=16%',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rangos_precios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rangos_precios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `producto_id` int NOT NULL,
  `cantidad_min` int NOT NULL,
  `cantidad_max` int DEFAULT NULL,
  `precio` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `producto_id` (`producto_id`),
  CONSTRAINT `rangos_precios_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `representante_inventario`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `representante_inventario` (
  `id` int NOT NULL AUTO_INCREMENT,
  `representante_admin_id` int DEFAULT NULL,
  `producto_id` int NOT NULL,
  `cantidad_disponible` int NOT NULL DEFAULT '0',
  `cantidad_reservada` int NOT NULL DEFAULT '0',
  `cantidad_vendida` int NOT NULL DEFAULT '0',
  `cantidad_devuelta` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rep_admin_producto` (`representante_admin_id`,`producto_id`),
  KEY `idx_rep_inv_producto` (`producto_id`),
  KEY `idx_rep_inv_representante_admin` (`representante_admin_id`),
  CONSTRAINT `fk_rep_inv_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
  CONSTRAINT `fk_rep_inv_representante_admin` FOREIGN KEY (`representante_admin_id`) REFERENCES `administradores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Inventario vigente asignado a cada representante';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `representante_inventario_movimientos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `representante_inventario_movimientos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `representante_admin_id` int DEFAULT NULL,
  `producto_id` int NOT NULL,
  `pedido_id` int DEFAULT NULL,
  `solicitud_consignacion_id` int DEFAULT NULL,
  `admin_id` int DEFAULT NULL,
  `tipo` enum('entrada_consignacion','venta','reserva','liberacion_reserva','devolucion','ajuste','cancelacion_venta','traspaso_salida','traspaso_entrada') COLLATE utf8mb4_unicode_ci NOT NULL,
  `cantidad` int NOT NULL,
  `cantidad_antes` int NOT NULL DEFAULT '0',
  `cantidad_despues` int NOT NULL DEFAULT '0',
  `notas` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rep_mov_producto` (`producto_id`),
  KEY `idx_rep_mov_pedido` (`pedido_id`),
  KEY `idx_rep_mov_solicitud` (`solicitud_consignacion_id`),
  KEY `idx_rep_mov_tipo` (`tipo`),
  KEY `idx_rep_mov_representante_admin` (`representante_admin_id`),
  KEY `fk_rep_mov_admin` (`admin_id`),
  CONSTRAINT `fk_rep_mov_admin` FOREIGN KEY (`admin_id`) REFERENCES `administradores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rep_mov_pedido` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rep_mov_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
  CONSTRAINT `fk_rep_mov_representante_admin` FOREIGN KEY (`representante_admin_id`) REFERENCES `administradores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rep_mov_solicitud_consignacion` FOREIGN KEY (`solicitud_consignacion_id`) REFERENCES `solicitudes_consignacion` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bit├ícora auditable de movimientos de inventario de representantes';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `representante_perfiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `representante_perfiles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `admin_id` int NOT NULL,
  `codigo` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tags_permitidos` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dir_calle` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dir_numero` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dir_colonia` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dir_ciudad` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dir_estado` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dir_cp` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dir_referencias` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dir_quien_recibe` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_representante_perfiles_admin` (`admin_id`),
  UNIQUE KEY `uq_representante_perfiles_codigo` (`codigo`),
  KEY `idx_representante_perfiles_activo` (`activo`),
  CONSTRAINT `fk_representante_perfiles_admin` FOREIGN KEY (`admin_id`) REFERENCES `administradores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Perfil comercial de usuarios con rol representante';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nombre del rol',
  `codigo` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'C├│digo ├║nico del rol (admin, director_general, etc)',
  `nivel_jerarquico` int NOT NULL COMMENT 'Nivel en jerarqu├¡a (1=m├ís alto, 5=m├ís bajo)',
  `descripcion` text COLLATE utf8mb4_unicode_ci COMMENT 'Descripci├│n del rol',
  `permisos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin COMMENT 'Permisos espec├¡ficos del rol en formato JSON',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`),
  KEY `idx_nivel_jerarquico` (`nivel_jerarquico`),
  CONSTRAINT `roles_chk_1` CHECK (json_valid(`permisos`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Roles del sistema con jerarqu├¡a organizacional';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `solicitudes_consignacion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `solicitudes_consignacion` (
  `id` int NOT NULL AUTO_INCREMENT,
  `representante_admin_id` int DEFAULT NULL,
  `solicitado_por_admin_id` int DEFAULT NULL,
  `revisado_por_admin_id` int DEFAULT NULL,
  `estado` enum('solicitada','aprobada','rechazada','preparando','en_transito','entregada','cancelada') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'solicitada',
  `notas_representante` text COLLATE utf8mb4_unicode_ci,
  `notas_admin` text COLLATE utf8mb4_unicode_ci,
  `paqueteria` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero_guia` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url_rastreo` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guia_archivo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha_solicitud` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_revision` datetime DEFAULT NULL,
  `fecha_envio` datetime DEFAULT NULL,
  `fecha_entrega` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sol_consig_estado` (`estado`),
  KEY `idx_sol_consig_fecha` (`fecha_solicitud`),
  KEY `idx_sol_consig_representante_admin` (`representante_admin_id`),
  KEY `fk_sol_consig_solicitado_por` (`solicitado_por_admin_id`),
  KEY `fk_sol_consig_revisado_por` (`revisado_por_admin_id`),
  CONSTRAINT `fk_sol_consig_representante_admin` FOREIGN KEY (`representante_admin_id`) REFERENCES `administradores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sol_consig_revisado_por` FOREIGN KEY (`revisado_por_admin_id`) REFERENCES `administradores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sol_consig_solicitado_por` FOREIGN KEY (`solicitado_por_admin_id`) REFERENCES `administradores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Solicitudes de inventario a consignaci├│n realizadas por representantes';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `solicitudes_consignacion_detalle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `solicitudes_consignacion_detalle` (
  `id` int NOT NULL AUTO_INCREMENT,
  `solicitud_id` int NOT NULL,
  `producto_id` int NOT NULL,
  `cantidad_solicitada` int NOT NULL,
  `cantidad_aprobada` int NOT NULL DEFAULT '0',
  `cantidad_entregada` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sol_producto` (`solicitud_id`,`producto_id`),
  KEY `idx_sol_det_producto` (`producto_id`),
  CONSTRAINT `fk_sol_det_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
  CONSTRAINT `fk_sol_det_solicitud` FOREIGN KEY (`solicitud_id`) REFERENCES `solicitudes_consignacion` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Productos y cantidades solicitadas en consignaci├│n';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `traspasos_inventario`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `traspasos_inventario` (
  `id` int NOT NULL AUTO_INCREMENT,
  `origen_admin_id` int NOT NULL COMMENT 'Rep que env├¡a',
  `destino_admin_id` int NOT NULL COMMENT 'Rep que recibe',
  `producto_id` int NOT NULL,
  `cantidad` int NOT NULL,
  `estado` enum('pendiente','confirmado','rechazado','cancelado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente',
  `notas` text COLLATE utf8mb4_unicode_ci,
  `respondido_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_traspaso_origen` (`origen_admin_id`),
  KEY `idx_traspaso_destino` (`destino_admin_id`),
  KEY `idx_traspaso_estado` (`estado`),
  KEY `fk_traspaso_producto` (`producto_id`),
  CONSTRAINT `fk_traspaso_destino` FOREIGN KEY (`destino_admin_id`) REFERENCES `administradores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_traspaso_origen` FOREIGN KEY (`origen_admin_id`) REFERENCES `administradores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_traspaso_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Solicitudes de traspaso de inventario entre representantes';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 DROP PROCEDURE IF EXISTS `sp_vender_kit` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE PROCEDURE `sp_vender_kit`(IN `p_kit_id` INT, IN `p_pedido_id` INT, IN `p_cantidad_kits` INT, OUT `p_success` BOOLEAN, OUT `p_mensaje` VARCHAR(500))
BEGIN
    DECLARE v_producto_id INT;
    DECLARE v_cantidad_necesaria INT;
    DECLARE v_existencia_actual INT;
    DECLARE v_precio_kit DECIMAL(10,2);
    DECLARE v_subtotal DECIMAL(10,2);
    DECLARE done INT DEFAULT FALSE;
    
    -- Cursor para recorrer los productos del kit
    DECLARE cur_productos CURSOR FOR 
        SELECT producto_id, cantidad * p_cantidad_kits
        FROM kit_productos
        WHERE kit_id = p_kit_id;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    -- Iniciar transacciÔö£Ôöén
    START TRANSACTION;
    
    -- Obtener precio del kit
    SELECT precio_kit INTO v_precio_kit FROM kits WHERE id = p_kit_id AND activo = 1;
    
    IF v_precio_kit IS NULL THEN
        SET p_success = FALSE;
        SET p_mensaje = 'Kit no encontrado o inactivo';
        ROLLBACK;
    ELSE
        -- Verificar disponibilidad de TODOS los productos
        OPEN cur_productos;
        
        check_loop: LOOP
            FETCH cur_productos INTO v_producto_id, v_cantidad_necesaria;
            
            IF done THEN
                LEAVE check_loop;
            END IF;
            
            SELECT existencia INTO v_existencia_actual 
            FROM productos 
            WHERE id = v_producto_id AND activo = 1;
            
            IF v_existencia_actual IS NULL OR v_existencia_actual < v_cantidad_necesaria THEN
                SET p_success = FALSE;
                SET p_mensaje = CONCAT('Stock insuficiente para producto ID: ', v_producto_id);
                CLOSE cur_productos;
                ROLLBACK;
                LEAVE check_loop;
            END IF;
        END LOOP;
        
        IF p_success IS NULL THEN
            -- Todo OK, proceder con la venta
            SET done = FALSE;
            
            -- Resetear cursor
            CLOSE cur_productos;
            OPEN cur_productos;
            
            -- Descontar productos individualmente
            descuento_loop: LOOP
                FETCH cur_productos INTO v_producto_id, v_cantidad_necesaria;
                
                IF done THEN
                    LEAVE descuento_loop;
                END IF;
                
                -- Actualizar existencia del producto
                UPDATE productos 
                SET existencia = existencia - v_cantidad_necesaria,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = v_producto_id;
                
                -- Registrar en detalle_pedidos (productos individuales)
                INSERT INTO detalle_pedidos (pedido_id, producto_id, cantidad, precio_unitario, subtotal)
                SELECT 
                    p_pedido_id,
                    v_producto_id,
                    v_cantidad_necesaria,
                    0.00, -- Precio 0 porque se cobra el kit completo
                    0.00
                FROM DUAL;
                
            END LOOP;
            
            CLOSE cur_productos;
            
            -- Registrar venta del kit
            SET v_subtotal = v_precio_kit * p_cantidad_kits;
            
            INSERT INTO kit_ventas (kit_id, pedido_id, cantidad, precio_unitario, subtotal)
            VALUES (p_kit_id, p_pedido_id, p_cantidad_kits, v_precio_kit, v_subtotal);
            
            SET p_success = TRUE;
            SET p_mensaje = CONCAT('Kit vendido exitosamente. Subtotal: $', v_subtotal);
            
            COMMIT;
        END IF;
    END IF;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50001 DROP VIEW IF EXISTS `vw_kit_detalle`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY DEFINER */
/*!50001 VIEW `vw_kit_detalle` AS select `k`.`id` AS `kit_id`,`k`.`nombre` AS `kit_nombre`,`k`.`precio_kit` AS `precio_kit`,`kp`.`id` AS `kit_producto_id`,`p`.`id` AS `producto_id`,`p`.`producto` AS `producto_nombre`,`p`.`existencia` AS `producto_existencia`,`kp`.`cantidad` AS `cantidad_en_kit`,floor((`p`.`existencia` / `kp`.`cantidad`)) AS `kits_posibles_con_este_producto` from ((`kits` `k` join `kit_productos` `kp` on((`k`.`id` = `kp`.`kit_id`))) join `productos` `p` on((`kp`.`producto_id` = `p`.`id`))) where (`k`.`activo` = 1) order by `k`.`orden`,`k`.`nombre`,`p`.`producto` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `vw_kits_disponibles`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY DEFINER */
/*!50001 VIEW `vw_kits_disponibles` AS select `k`.`id` AS `id`,`k`.`nombre` AS `nombre`,`k`.`descripcion` AS `descripcion`,`k`.`imagen` AS `imagen`,`k`.`precio_kit` AS `precio_kit`,`k`.`activo` AS `activo`,`k`.`orden` AS `orden`,min(floor((`p`.`existencia` / `kp`.`cantidad`))) AS `stock_disponible`,coalesce((select sum(`kv`.`cantidad`) from `kit_ventas` `kv` where (`kv`.`kit_id` = `k`.`id`)),0) AS `total_vendidos` from ((`kits` `k` join `kit_productos` `kp` on((`k`.`id` = `kp`.`kit_id`))) join `productos` `p` on((`kp`.`producto_id` = `p`.`id`))) where ((`k`.`activo` = 1) and (`p`.`activo` = 1)) group by `k`.`id`,`k`.`nombre`,`k`.`descripcion`,`k`.`imagen`,`k`.`precio_kit`,`k`.`activo`,`k`.`orden` having (min(floor((`p`.`existencia` / `kp`.`cantidad`))) > 0) order by `k`.`orden`,`k`.`nombre` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


