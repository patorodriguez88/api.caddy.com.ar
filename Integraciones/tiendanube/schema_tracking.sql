-- Registro de los estados de envío informados a Tienda Nube (clases/tiendanube_tracking.class.php).
-- Se crea a mano en dinter6_triangular: el usuario de la API no tiene privilegio CREATE.
CREATE TABLE IF NOT EXISTS TiendaNube_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seguimiento_id INT NOT NULL,
    codigo VARCHAR(20) NOT NULL,
    order_id BIGINT NOT NULL,
    store_id BIGINT NOT NULL,
    fo_id VARCHAR(40) NOT NULL DEFAULT '',
    status VARCHAR(40) NOT NULL DEFAULT '',
    http SMALLINT NOT NULL DEFAULT 0,
    respuesta TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_seguimiento (seguimiento_id),
    KEY idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
