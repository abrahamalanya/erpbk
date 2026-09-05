/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `agencias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agencias` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `empresa_id` bigint unsigned NOT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `estado` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'activo',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agencias_empresa_id_foreign` (`empresa_id`),
  CONSTRAINT `agencias_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bancos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bancos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bancos_nombre_unique` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bienes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bienes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `empresa_id` bigint unsigned NOT NULL,
  `agencia_id` bigint unsigned NOT NULL,
  `cliente_id` bigint unsigned NOT NULL,
  `registrado_por` bigint unsigned DEFAULT NULL,
  `tipo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `marca` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `modelo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `serie` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `observacion` text COLLATE utf8mb4_unicode_ci,
  `valorizacion` decimal(12,2) NOT NULL,
  `precio_venta` decimal(12,2) DEFAULT NULL,
  `puntaje` tinyint unsigned NOT NULL,
  `foto_cliente_producto_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `video_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en_garantia',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bienes_empresa_id_foreign` (`empresa_id`),
  KEY `bienes_agencia_id_foreign` (`agencia_id`),
  KEY `bienes_cliente_id_foreign` (`cliente_id`),
  KEY `bienes_registrado_por_foreign` (`registrado_por`),
  CONSTRAINT `bienes_agencia_id_foreign` FOREIGN KEY (`agencia_id`) REFERENCES `agencias` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `bienes_cliente_id_foreign` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `bienes_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `bienes_registrado_por_foreign` FOREIGN KEY (`registrado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billetajes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `billetajes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `caja_ciclo_id` bigint unsigned NOT NULL,
  `boveda_id` bigint unsigned NOT NULL,
  `empresa_id` bigint unsigned NOT NULL,
  `monto` decimal(12,2) NOT NULL,
  `motivo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `medio_recepcion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `datos_recepcion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cliente_id` bigint unsigned DEFAULT NULL,
  `estado` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente',
  `solicitado_por` bigint unsigned DEFAULT NULL,
  `aprobado_por` bigint unsigned DEFAULT NULL,
  `motivo_rechazo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `medio_egreso` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `canal_egreso` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cuenta_bancaria_id` bigint unsigned DEFAULT NULL,
  `fecha_resolucion` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `billetajes_empresa_id_foreign` (`empresa_id`),
  KEY `billetajes_cliente_id_foreign` (`cliente_id`),
  KEY `billetajes_solicitado_por_foreign` (`solicitado_por`),
  KEY `billetajes_aprobado_por_foreign` (`aprobado_por`),
  KEY `billetajes_cuenta_bancaria_id_foreign` (`cuenta_bancaria_id`),
  KEY `billetajes_boveda_id_estado_index` (`boveda_id`,`estado`),
  KEY `billetajes_caja_ciclo_id_index` (`caja_ciclo_id`),
  CONSTRAINT `billetajes_aprobado_por_foreign` FOREIGN KEY (`aprobado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `billetajes_boveda_id_foreign` FOREIGN KEY (`boveda_id`) REFERENCES `bovedas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `billetajes_caja_ciclo_id_foreign` FOREIGN KEY (`caja_ciclo_id`) REFERENCES `caja_ciclos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `billetajes_cliente_id_foreign` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `billetajes_cuenta_bancaria_id_foreign` FOREIGN KEY (`cuenta_bancaria_id`) REFERENCES `cuentas_bancarias` (`id`) ON DELETE SET NULL,
  CONSTRAINT `billetajes_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `billetajes_solicitado_por_foreign` FOREIGN KEY (`solicitado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boveda_ciclos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boveda_ciclos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `boveda_id` bigint unsigned NOT NULL,
  `empresa_id` bigint unsigned NOT NULL,
  `fecha` date NOT NULL,
  `estado` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cerrada',
  `saldo_apertura` decimal(12,2) NOT NULL DEFAULT '0.00',
  `saldo_calculado_cierre` decimal(12,2) DEFAULT NULL,
  `saldo_arqueo_cierre` decimal(12,2) DEFAULT NULL,
  `diferencia` decimal(12,2) DEFAULT NULL,
  `abierta_por` bigint unsigned DEFAULT NULL,
  `cerrada_por` bigint unsigned DEFAULT NULL,
  `abierta_at` timestamp NULL DEFAULT NULL,
  `cerrada_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `boveda_ciclos_empresa_id_foreign` (`empresa_id`),
  KEY `boveda_ciclos_abierta_por_foreign` (`abierta_por`),
  KEY `boveda_ciclos_cerrada_por_foreign` (`cerrada_por`),
  KEY `boveda_ciclos_boveda_id_estado_index` (`boveda_id`,`estado`),
  KEY `boveda_ciclos_boveda_id_fecha_index` (`boveda_id`,`fecha`),
  CONSTRAINT `boveda_ciclos_abierta_por_foreign` FOREIGN KEY (`abierta_por`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boveda_ciclos_boveda_id_foreign` FOREIGN KEY (`boveda_id`) REFERENCES `bovedas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `boveda_ciclos_cerrada_por_foreign` FOREIGN KEY (`cerrada_por`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boveda_ciclos_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boveda_movimientos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boveda_movimientos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `boveda_ciclo_id` bigint unsigned NOT NULL,
  `empresa_id` bigint unsigned NOT NULL,
  `tipo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `monto` decimal(12,2) NOT NULL,
  `concepto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `origen` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `grupo_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `billetaje_id` bigint unsigned DEFAULT NULL,
  `caja_ciclo_id` bigint unsigned DEFAULT NULL,
  `registrado_por` bigint unsigned DEFAULT NULL,
  `fecha_boveda` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `boveda_movimientos_empresa_id_foreign` (`empresa_id`),
  KEY `boveda_movimientos_billetaje_id_foreign` (`billetaje_id`),
  KEY `boveda_movimientos_caja_ciclo_id_foreign` (`caja_ciclo_id`),
  KEY `boveda_movimientos_registrado_por_foreign` (`registrado_por`),
  KEY `boveda_movimientos_boveda_ciclo_id_index` (`boveda_ciclo_id`),
  KEY `boveda_movimientos_fecha_boveda_index` (`fecha_boveda`),
  KEY `boveda_movimientos_grupo_id_index` (`grupo_id`),
  CONSTRAINT `boveda_movimientos_billetaje_id_foreign` FOREIGN KEY (`billetaje_id`) REFERENCES `billetajes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boveda_movimientos_boveda_ciclo_id_foreign` FOREIGN KEY (`boveda_ciclo_id`) REFERENCES `boveda_ciclos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `boveda_movimientos_caja_ciclo_id_foreign` FOREIGN KEY (`caja_ciclo_id`) REFERENCES `caja_ciclos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boveda_movimientos_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `boveda_movimientos_registrado_por_foreign` FOREIGN KEY (`registrado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bovedas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bovedas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `empresa_id` bigint unsigned NOT NULL,
  `agencia_id` bigint unsigned DEFAULT NULL,
  `tipo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bovedas_agencia_id_unique` (`agencia_id`),
  KEY `bovedas_empresa_id_foreign` (`empresa_id`),
  CONSTRAINT `bovedas_agencia_id_foreign` FOREIGN KEY (`agencia_id`) REFERENCES `agencias` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `bovedas_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `caja_ciclos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `caja_ciclos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `caja_id` bigint unsigned NOT NULL,
  `empresa_id` bigint unsigned NOT NULL,
  `fecha` date NOT NULL,
  `estado` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cerrada',
  `saldo_apertura` decimal(12,2) NOT NULL DEFAULT '0.00',
  `saldo_calculado_cierre` decimal(12,2) DEFAULT NULL,
  `saldo_efectivo_cierre` decimal(12,2) DEFAULT NULL,
  `saldo_arqueo_cierre` decimal(12,2) DEFAULT NULL,
  `diferencia` decimal(12,2) DEFAULT NULL,
  `cerrada_por` bigint unsigned DEFAULT NULL,
  `cierre_forzado` tinyint(1) NOT NULL DEFAULT '0',
  `cierre_automatico` tinyint(1) NOT NULL DEFAULT '0',
  `abierta_at` timestamp NULL DEFAULT NULL,
  `cerrada_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `caja_ciclos_empresa_id_foreign` (`empresa_id`),
  KEY `caja_ciclos_cerrada_por_foreign` (`cerrada_por`),
  KEY `caja_ciclos_caja_id_estado_index` (`caja_id`,`estado`),
  KEY `caja_ciclos_caja_id_fecha_index` (`caja_id`,`fecha`),
  CONSTRAINT `caja_ciclos_caja_id_foreign` FOREIGN KEY (`caja_id`) REFERENCES `cajas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `caja_ciclos_cerrada_por_foreign` FOREIGN KEY (`cerrada_por`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `caja_ciclos_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `caja_movimientos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `caja_movimientos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `caja_ciclo_id` bigint unsigned NOT NULL,
  `empresa_id` bigint unsigned NOT NULL,
  `tipo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `monto` decimal(12,2) NOT NULL,
  `medio` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'efectivo',
  `canal` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `concepto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `concepto_id` bigint unsigned DEFAULT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `billetaje_id` bigint unsigned DEFAULT NULL,
  `registrado_por` bigint unsigned DEFAULT NULL,
  `fecha_caja` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `caja_movimientos_empresa_id_foreign` (`empresa_id`),
  KEY `caja_movimientos_concepto_id_foreign` (`concepto_id`),
  KEY `caja_movimientos_billetaje_id_foreign` (`billetaje_id`),
  KEY `caja_movimientos_registrado_por_foreign` (`registrado_por`),
  KEY `caja_movimientos_caja_ciclo_id_index` (`caja_ciclo_id`),
  KEY `caja_movimientos_fecha_caja_index` (`fecha_caja`),
  CONSTRAINT `caja_movimientos_billetaje_id_foreign` FOREIGN KEY (`billetaje_id`) REFERENCES `billetajes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `caja_movimientos_caja_ciclo_id_foreign` FOREIGN KEY (`caja_ciclo_id`) REFERENCES `caja_ciclos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `caja_movimientos_concepto_id_foreign` FOREIGN KEY (`concepto_id`) REFERENCES `conceptos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `caja_movimientos_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `caja_movimientos_registrado_por_foreign` FOREIGN KEY (`registrado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cajas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cajas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `empresa_id` bigint unsigned NOT NULL,
  `agencia_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cajas_user_id_unique` (`user_id`),
  KEY `cajas_empresa_id_foreign` (`empresa_id`),
  KEY `cajas_agencia_id_foreign` (`agencia_id`),
  CONSTRAINT `cajas_agencia_id_foreign` FOREIGN KEY (`agencia_id`) REFERENCES `agencias` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cajas_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cajas_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clientes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clientes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `empresa_id` bigint unsigned NOT NULL,
  `agencia_id` bigint unsigned NOT NULL,
  `asesor_id` bigint unsigned DEFAULT NULL,
  `registrado_por` bigint unsigned DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellido` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo_documento` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `numero_documento` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefono` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referencia` text COLLATE utf8mb4_unicode_ci,
  `foto_cliente_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `foto_dni_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `foto_dni_reverso_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `foto_casa_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `foto_negocio_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'activo',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `clientes_empresa_id_numero_documento_unique` (`empresa_id`,`numero_documento`),
  KEY `clientes_agencia_id_foreign` (`agencia_id`),
  KEY `clientes_asesor_id_foreign` (`asesor_id`),
  KEY `clientes_registrado_por_foreign` (`registrado_por`),
  CONSTRAINT `clientes_agencia_id_foreign` FOREIGN KEY (`agencia_id`) REFERENCES `agencias` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `clientes_asesor_id_foreign` FOREIGN KEY (`asesor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `clientes_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `clientes_registrado_por_foreign` FOREIGN KEY (`registrado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `conceptos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `conceptos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `empresa_id` bigint unsigned NOT NULL,
  `tipo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `creado_por` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `conceptos_empresa_id_tipo_nombre_unique` (`empresa_id`,`tipo`,`nombre`),
  KEY `conceptos_creado_por_foreign` (`creado_por`),
  KEY `conceptos_empresa_id_tipo_activo_index` (`empresa_id`,`tipo`,`activo`),
  CONSTRAINT `conceptos_creado_por_foreign` FOREIGN KEY (`creado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `conceptos_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `conciliaciones_bancarias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `conciliaciones_bancarias` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cuenta_bancaria_id` bigint unsigned NOT NULL,
  `empresa_id` bigint unsigned NOT NULL,
  `saldo_sistema` decimal(12,2) NOT NULL,
  `saldo_banco` decimal(12,2) NOT NULL,
  `diferencia` decimal(12,2) NOT NULL,
  `observacion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conciliado_por` bigint unsigned DEFAULT NULL,
  `fecha` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `conciliaciones_bancarias_empresa_id_foreign` (`empresa_id`),
  KEY `conciliaciones_bancarias_conciliado_por_foreign` (`conciliado_por`),
  KEY `conciliaciones_bancarias_cuenta_bancaria_id_fecha_index` (`cuenta_bancaria_id`,`fecha`),
  CONSTRAINT `conciliaciones_bancarias_conciliado_por_foreign` FOREIGN KEY (`conciliado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `conciliaciones_bancarias_cuenta_bancaria_id_foreign` FOREIGN KEY (`cuenta_bancaria_id`) REFERENCES `cuentas_bancarias` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `conciliaciones_bancarias_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `configuraciones_credito_prendario`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `configuraciones_credito_prendario` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `empresa_id` bigint unsigned NOT NULL,
  `agencia_id` bigint unsigned DEFAULT NULL,
  `tipo_credito` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'prendario',
  `interes_default` decimal(5,2) NOT NULL,
  `plazo_dias` int unsigned NOT NULL,
  `dias_espera_mora` int unsigned NOT NULL,
  `dias_minimo_interes` int unsigned NOT NULL DEFAULT '15',
  `tasa_mora_diaria` decimal(5,2) NOT NULL,
  `max_refrendos` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `config_credito_prendario_empresa_agencia_unique` (`empresa_id`,`agencia_id`),
  KEY `configuraciones_credito_prendario_agencia_id_foreign` (`agencia_id`),
  CONSTRAINT `configuraciones_credito_prendario_agencia_id_foreign` FOREIGN KEY (`agencia_id`) REFERENCES `agencias` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `configuraciones_credito_prendario_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `configuraciones_sistema`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `configuraciones_sistema` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre_app` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'umax',
  `favicon_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `credito_garantia`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `credito_garantia` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `credito_id` bigint unsigned NOT NULL,
  `garantia_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `garantia_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `credito_garantia_unique` (`credito_id`,`garantia_type`,`garantia_id`),
  KEY `credito_garantia_garantia_type_garantia_id_index` (`garantia_type`,`garantia_id`),
  CONSTRAINT `credito_garantia_credito_id_foreign` FOREIGN KEY (`credito_id`) REFERENCES `creditos_prendarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `creditos_prendarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `creditos_prendarios` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `empresa_id` bigint unsigned NOT NULL,
  `agencia_id` bigint unsigned NOT NULL,
  `tipo_credito` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'prendario',
  `cliente_id` bigint unsigned NOT NULL,
  `registrado_por` bigint unsigned DEFAULT NULL,
  `supervisado_por` bigint unsigned DEFAULT NULL,
  `refrendo_de_credito_id` bigint unsigned DEFAULT NULL,
  `adenda_de_credito_id` bigint unsigned DEFAULT NULL,
  `numero_refrendo` int unsigned NOT NULL DEFAULT '0',
  `monto_prestamo` decimal(12,2) NOT NULL,
  `interes` decimal(5,2) NOT NULL,
  `tipo_cuota` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `plazo_dias` int unsigned NOT NULL,
  `estado` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente',
  `aprobado_por` bigint unsigned DEFAULT NULL,
  `fecha_aprobacion` timestamp NULL DEFAULT NULL,
  `motivo_rechazo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha_desembolso` date DEFAULT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  `conformidad_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conformidad_confirmada_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `creditos_prendarios_agencia_id_foreign` (`agencia_id`),
  KEY `creditos_prendarios_cliente_id_foreign` (`cliente_id`),
  KEY `creditos_prendarios_registrado_por_foreign` (`registrado_por`),
  KEY `creditos_prendarios_supervisado_por_foreign` (`supervisado_por`),
  KEY `creditos_prendarios_refrendo_de_credito_id_foreign` (`refrendo_de_credito_id`),
  KEY `creditos_prendarios_adenda_de_credito_id_foreign` (`adenda_de_credito_id`),
  KEY `creditos_prendarios_aprobado_por_foreign` (`aprobado_por`),
  KEY `creditos_prendarios_empresa_id_estado_index` (`empresa_id`,`estado`),
  KEY `creditos_prendarios_fecha_vencimiento_index` (`fecha_vencimiento`),
  KEY `creditos_prendarios_empresa_id_tipo_credito_index` (`empresa_id`,`tipo_credito`),
  CONSTRAINT `creditos_prendarios_adenda_de_credito_id_foreign` FOREIGN KEY (`adenda_de_credito_id`) REFERENCES `creditos_prendarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `creditos_prendarios_agencia_id_foreign` FOREIGN KEY (`agencia_id`) REFERENCES `agencias` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `creditos_prendarios_aprobado_por_foreign` FOREIGN KEY (`aprobado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `creditos_prendarios_cliente_id_foreign` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `creditos_prendarios_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `creditos_prendarios_refrendo_de_credito_id_foreign` FOREIGN KEY (`refrendo_de_credito_id`) REFERENCES `creditos_prendarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `creditos_prendarios_registrado_por_foreign` FOREIGN KEY (`registrado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `creditos_prendarios_supervisado_por_foreign` FOREIGN KEY (`supervisado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cuenta_bancaria_movimientos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cuenta_bancaria_movimientos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cuenta_bancaria_id` bigint unsigned NOT NULL,
  `empresa_id` bigint unsigned NOT NULL,
  `tipo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `monto` decimal(12,2) NOT NULL,
  `concepto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `origen` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `grupo_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registrado_por` bigint unsigned DEFAULT NULL,
  `fecha` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cuenta_bancaria_movimientos_empresa_id_foreign` (`empresa_id`),
  KEY `cuenta_bancaria_movimientos_registrado_por_foreign` (`registrado_por`),
  KEY `cuenta_bancaria_movimientos_cuenta_bancaria_id_fecha_index` (`cuenta_bancaria_id`,`fecha`),
  KEY `cuenta_bancaria_movimientos_grupo_id_index` (`grupo_id`),
  CONSTRAINT `cuenta_bancaria_movimientos_cuenta_bancaria_id_foreign` FOREIGN KEY (`cuenta_bancaria_id`) REFERENCES `cuentas_bancarias` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cuenta_bancaria_movimientos_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cuenta_bancaria_movimientos_registrado_por_foreign` FOREIGN KEY (`registrado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cuentas_bancarias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cuentas_bancarias` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `boveda_id` bigint unsigned NOT NULL,
  `empresa_id` bigint unsigned NOT NULL,
  `banco_id` bigint unsigned NOT NULL,
  `numero_cuenta` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titular` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo_cuenta` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `moneda` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PEN',
  `alias` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activa` tinyint(1) NOT NULL DEFAULT '1',
  `acepta_yape` tinyint(1) NOT NULL DEFAULT '0',
  `numero_yape` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `acepta_plin` tinyint(1) NOT NULL DEFAULT '0',
  `numero_plin` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `saldo_inicial` decimal(12,2) NOT NULL DEFAULT '0.00',
  `creada_por` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cuentas_bancarias_empresa_id_foreign` (`empresa_id`),
  KEY `cuentas_bancarias_banco_id_foreign` (`banco_id`),
  KEY `cuentas_bancarias_creada_por_foreign` (`creada_por`),
  KEY `cuentas_bancarias_boveda_id_activa_index` (`boveda_id`,`activa`),
  CONSTRAINT `cuentas_bancarias_banco_id_foreign` FOREIGN KEY (`banco_id`) REFERENCES `bancos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cuentas_bancarias_boveda_id_foreign` FOREIGN KEY (`boveda_id`) REFERENCES `bovedas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cuentas_bancarias_creada_por_foreign` FOREIGN KEY (`creada_por`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cuentas_bancarias_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cuotas_credito_prendario`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cuotas_credito_prendario` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `credito_id` bigint unsigned NOT NULL,
  `empresa_id` bigint unsigned NOT NULL,
  `numero_cuota` int unsigned NOT NULL,
  `fecha_vencimiento` date NOT NULL,
  `monto_capital` decimal(10,2) NOT NULL,
  `monto_interes` decimal(10,2) NOT NULL,
  `monto_total` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cuotas_credito_prendario_empresa_id_foreign` (`empresa_id`),
  KEY `cuotas_credito_prendario_credito_id_index` (`credito_id`),
  CONSTRAINT `cuotas_credito_prendario_credito_id_foreign` FOREIGN KEY (`credito_id`) REFERENCES `creditos_prendarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cuotas_credito_prendario_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `documentos_credito_prendario`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentos_credito_prendario` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `credito_id` bigint unsigned NOT NULL,
  `empresa_id` bigint unsigned NOT NULL,
  `tipo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `archivo_firmado_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `generado_por` bigint unsigned DEFAULT NULL,
  `generado_at` timestamp NOT NULL,
  `impreso_at` timestamp NULL DEFAULT NULL,
  `firmado_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `documentos_credito_prendario_empresa_id_foreign` (`empresa_id`),
  KEY `documentos_credito_prendario_generado_por_foreign` (`generado_por`),
  KEY `documentos_credito_prendario_credito_id_index` (`credito_id`),
  CONSTRAINT `documentos_credito_prendario_credito_id_foreign` FOREIGN KEY (`credito_id`) REFERENCES `creditos_prendarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `documentos_credito_prendario_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `documentos_credito_prendario_generado_por_foreign` FOREIGN KEY (`generado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `empresas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `empresas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prefijo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ruc` varchar(11) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `razon_social` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domicilio_legal` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actividad_economica` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `representante_legal` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `firma_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'activo',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `empresas_prefijo_unique` (`prefijo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `garantia_fotos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `garantia_fotos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `garantia_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `garantia_id` bigint unsigned NOT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `orden` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `garantia_fotos_garantia_type_garantia_id_index` (`garantia_type`,`garantia_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inmuebles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inmuebles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `empresa_id` bigint unsigned NOT NULL,
  `agencia_id` bigint unsigned NOT NULL,
  `cliente_id` bigint unsigned NOT NULL,
  `registrado_por` bigint unsigned DEFAULT NULL,
  `partida_registral` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `oficina_registral` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo_inmueble` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `distrito` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provincia` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `departamento` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `area_terreno` decimal(12,2) DEFAULT NULL,
  `area_construida` decimal(12,2) DEFAULT NULL,
  `propietario` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `con_gravamen` tinyint(1) NOT NULL DEFAULT '0',
  `linderos` text COLLATE utf8mb4_unicode_ci,
  `observacion` text COLLATE utf8mb4_unicode_ci,
  `valorizacion` decimal(14,2) NOT NULL,
  `precio_venta` decimal(14,2) DEFAULT NULL,
  `puntaje` tinyint unsigned DEFAULT NULL,
  `foto_cliente_producto_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `video_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en_garantia',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inmuebles_agencia_id_foreign` (`agencia_id`),
  KEY `inmuebles_cliente_id_foreign` (`cliente_id`),
  KEY `inmuebles_registrado_por_foreign` (`registrado_por`),
  KEY `inmuebles_empresa_id_cliente_id_index` (`empresa_id`,`cliente_id`),
  CONSTRAINT `inmuebles_agencia_id_foreign` FOREIGN KEY (`agencia_id`) REFERENCES `agencias` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inmuebles_cliente_id_foreign` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inmuebles_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inmuebles_registrado_por_foreign` FOREIGN KEY (`registrado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `intereses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `intereses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `articulo_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `articulo_id` bigint unsigned NOT NULL,
  `empresa_id` bigint unsigned NOT NULL,
  `agencia_id` bigint unsigned NOT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefono` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mensaje` text COLLATE utf8mb4_unicode_ci,
  `atendido_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `intereses_empresa_id_foreign` (`empresa_id`),
  KEY `intereses_agencia_id_foreign` (`agencia_id`),
  KEY `intereses_articulo_type_articulo_id_index` (`articulo_type`,`articulo_id`),
  CONSTRAINT `intereses_agencia_id_foreign` FOREIGN KEY (`agencia_id`) REFERENCES `agencias` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `intereses_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint unsigned NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_roles` (
  `role_id` bigint unsigned NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `movimiento_fotos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `movimiento_fotos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fotografiable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fotografiable_id` bigint unsigned NOT NULL,
  `tipo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `orden` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `movimiento_fotos_fotografiable_type_fotografiable_id_index` (`fotografiable_type`,`fotografiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_id` bigint unsigned NOT NULL,
  `data` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellido` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `dni` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `estado` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'activo',
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `empresa_id` bigint unsigned DEFAULT NULL,
  `agencia_id` bigint unsigned DEFAULT NULL,
  `supervisor_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_dni_unique` (`dni`),
  KEY `users_empresa_id_foreign` (`empresa_id`),
  KEY `users_agencia_id_foreign` (`agencia_id`),
  KEY `users_supervisor_id_foreign` (`supervisor_id`),
  CONSTRAINT `users_agencia_id_foreign` FOREIGN KEY (`agencia_id`) REFERENCES `agencias` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_supervisor_id_foreign` FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vehiculos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vehiculos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `empresa_id` bigint unsigned NOT NULL,
  `agencia_id` bigint unsigned NOT NULL,
  `cliente_id` bigint unsigned NOT NULL,
  `registrado_por` bigint unsigned DEFAULT NULL,
  `placa` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `motor` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `serie` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `marca` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `modelo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `anio` smallint unsigned DEFAULT NULL,
  `clase` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `propietario` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tiene_soat` tinyint(1) NOT NULL DEFAULT '0',
  `dejo_llave` tinyint(1) NOT NULL DEFAULT '0',
  `dejo_tarjeta_propiedad` tinyint(1) NOT NULL DEFAULT '0',
  `observacion` text COLLATE utf8mb4_unicode_ci,
  `valorizacion` decimal(12,2) NOT NULL,
  `precio_venta` decimal(12,2) DEFAULT NULL,
  `puntaje` tinyint unsigned DEFAULT NULL,
  `foto_cliente_producto_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `video_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en_garantia',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `vehiculos_agencia_id_foreign` (`agencia_id`),
  KEY `vehiculos_cliente_id_foreign` (`cliente_id`),
  KEY `vehiculos_registrado_por_foreign` (`registrado_por`),
  KEY `vehiculos_empresa_id_cliente_id_index` (`empresa_id`,`cliente_id`),
  CONSTRAINT `vehiculos_agencia_id_foreign` FOREIGN KEY (`agencia_id`) REFERENCES `agencias` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `vehiculos_cliente_id_foreign` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `vehiculos_empresa_id_foreign` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `vehiculos_registrado_por_foreign` FOREIGN KEY (`registrado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'2026_08_13_065223_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_08_13_070036_create_permission_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_08_14_163712_create_empresas_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_08_14_163713_create_agencias_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_08_14_163714_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_08_15_021642_create_clientes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_08_18_082101_create_bovedas_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_08_18_082102_create_cajas_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_08_18_082103_create_boveda_ciclos_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_08_18_082104_create_caja_ciclos_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_08_18_103901_create_configuraciones_credito_prendario_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_08_18_103902_create_bienes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_08_18_103904_create_creditos_prendarios_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_08_18_103905_create_documentos_credito_prendario_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_08_19_175038_create_cuotas_credito_prendario_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_08_19_191621_create_notifications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_08_21_054152_create_bancos_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_08_21_054152_create_cuentas_bancarias_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_08_21_054153_create_conciliaciones_bancarias_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_08_21_054153_create_cuenta_bancaria_movimientos_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_08_21_130825_create_conceptos_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_08_21_130825_create_movimiento_fotos_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_08_21_130827_create_billetajes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_08_21_130828_create_boveda_movimientos_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_08_21_130829_create_caja_movimientos_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_08_26_044633_create_configuraciones_sistema_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_09_02_044228_create_credito_garantia_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_09_02_050758_create_vehiculos_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_09_02_050759_create_garantia_fotos_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_09_02_081508_create_intereses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_09_02_091730_create_inmuebles_table',1);
