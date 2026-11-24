-- MecaniGest · Migración 001
-- Presupuesto y autorización, cancelación de órdenes, anulación de pagos, kilometraje y VIN,
-- catálogo de servicios, descuentos e IVA, costo de refacciones y estado del vehículo al recibirlo.
-- Solo agrega columnas y tablas; no elimina ni modifica información existente.

ALTER TABLE vehiculos
  ADD COLUMN vin VARCHAR(17) NULL UNIQUE AFTER placas;

ALTER TABLE ordenes_servicio
  MODIFY estado ENUM('RECIBIDO','EN DIAGNÓSTICO','EN REPARACIÓN','LISTO PARA ENTREGAR','ENTREGADO','CANCELADA') NOT NULL DEFAULT 'RECIBIDO',
  ADD COLUMN kilometraje INT UNSIGNED NULL AFTER falla,
  ADD COLUMN nivel_combustible ENUM('Reserva','1/4','1/2','3/4','Lleno') NULL AFTER kilometraje,
  ADD COLUMN notas_recepcion TEXT NULL AFTER nivel_combustible,
  ADD COLUMN presupuesto_centavos BIGINT NULL CHECK(presupuesto_centavos >= 0) AFTER reparaciones,
  ADD COLUMN presupuesto_detalle TEXT NULL AFTER presupuesto_centavos,
  ADD COLUMN autorizado_centavos BIGINT NULL CHECK(autorizado_centavos >= 0) AFTER presupuesto_detalle,
  ADD COLUMN autorizacion_medio VARCHAR(20) NULL AFTER autorizado_centavos,
  ADD COLUMN autorizacion_nota VARCHAR(250) NULL AFTER autorizacion_medio,
  ADD COLUMN autorizado_por INT UNSIGNED NULL AFTER autorizacion_nota,
  ADD COLUMN autorizado_en DATETIME NULL AFTER autorizado_por,
  ADD COLUMN descuento_centavos BIGINT NOT NULL DEFAULT 0 CHECK(descuento_centavos >= 0) AFTER mano_obra_centavos,
  ADD COLUMN descuento_motivo VARCHAR(250) NOT NULL DEFAULT '' AFTER descuento_centavos,
  ADD COLUMN iva_tasa SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER descuento_motivo,
  ADD COLUMN cancelado_motivo VARCHAR(250) NULL AFTER fecha_entrega,
  ADD COLUMN cancelado_por INT UNSIGNED NULL AFTER cancelado_motivo,
  ADD COLUMN cancelado_en DATETIME NULL AFTER cancelado_por,
  ADD CONSTRAINT fk_orden_autorizado_por FOREIGN KEY (autorizado_por) REFERENCES usuarios(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_orden_cancelado_por FOREIGN KEY (cancelado_por) REFERENCES usuarios(id) ON DELETE RESTRICT,
  ADD INDEX idx_orden_vehiculo_estado (vehiculo_id, estado);

ALTER TABLE refacciones
  ADD COLUMN costo_centavos BIGINT NOT NULL DEFAULT 0 CHECK(costo_centavos >= 0) AFTER precio_centavos;

ALTER TABLE detalles_orden
  ADD COLUMN costo_centavos BIGINT NOT NULL DEFAULT 0 CHECK(costo_centavos >= 0) AFTER precio_centavos;

ALTER TABLE movimientos_inventario
  ADD COLUMN costo_unitario_centavos BIGINT NULL CHECK(costo_unitario_centavos >= 0) AFTER cantidad;

ALTER TABLE pagos
  ADD COLUMN anulado_en DATETIME NULL,
  ADD COLUMN anulado_por INT UNSIGNED NULL,
  ADD COLUMN anulacion_motivo VARCHAR(250) NULL,
  ADD CONSTRAINT fk_pago_anulado_por FOREIGN KEY (anulado_por) REFERENCES usuarios(id) ON DELETE RESTRICT;

CREATE TABLE servicios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  descripcion VARCHAR(500) NOT NULL DEFAULT '',
  precio_centavos BIGINT NOT NULL CHECK(precio_centavos >= 0),
  activo TINYINT(1) NOT NULL DEFAULT 1,
  INDEX(nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE servicios_orden (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  orden_id INT UNSIGNED NOT NULL,
  servicio_id INT UNSIGNED NOT NULL,
  nombre_historico VARCHAR(120) NOT NULL,
  cantidad INT NOT NULL CHECK(cantidad > 0),
  precio_centavos BIGINT NOT NULL CHECK(precio_centavos >= 0),
  usuario_id INT UNSIGNED NOT NULL,
  operacion VARCHAR(64) NOT NULL UNIQUE,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(orden_id) REFERENCES ordenes_servicio(id) ON DELETE RESTRICT,
  FOREIGN KEY(servicio_id) REFERENCES servicios(id) ON DELETE RESTRICT,
  FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE fotos_orden (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  orden_id INT UNSIGNED NOT NULL,
  archivo VARCHAR(80) NOT NULL UNIQUE,
  tipo VARCHAR(20) NOT NULL,
  tamano INT UNSIGNED NOT NULL,
  descripcion VARCHAR(250) NOT NULL DEFAULT '',
  usuario_id INT UNSIGNED NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(orden_id) REFERENCES ordenes_servicio(id) ON DELETE RESTRICT,
  FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
