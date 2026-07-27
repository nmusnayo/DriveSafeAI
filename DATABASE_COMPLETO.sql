-- ============================================
-- DriveSafe AI - Base de Datos Completa
-- ============================================
-- Este archivo contiene toda la estructura SQL
-- del proyecto DriveSafe AI consolidada
-- ============================================

-- Crear base de datos
CREATE DATABASE IF NOT EXISTS drivesafe_ai
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE drivesafe_ai;

-- ============================================
-- TABLA: organizaciones
-- ============================================
CREATE TABLE IF NOT EXISTS organizaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(160) NOT NULL,
    tipo ENUM('PARTICULAR', 'EMPRESA') NOT NULL DEFAULT 'PARTICULAR',
    estado ENUM('ACTIVA', 'INACTIVA') NOT NULL DEFAULT 'ACTIVA',
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- TABLA: usuarios
-- ============================================
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_organizacion INT NULL,
    nombre VARCHAR(120) NOT NULL,
    correo VARCHAR(160) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    rol ENUM('ADMIN', 'CONDUCTOR', 'SUPERVISOR') NOT NULL DEFAULT 'CONDUCTOR',
    estado ENUM('ACTIVO', 'INACTIVO') NOT NULL DEFAULT 'ACTIVO',
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_organizacion) REFERENCES organizaciones(id),
    INDEX idx_usuarios_rol (rol),
    INDEX idx_usuarios_estado (estado),
    INDEX idx_usuarios_organizacion (id_organizacion)
);

-- ============================================
-- TABLA: conductores
-- ============================================
CREATE TABLE IF NOT EXISTS conductores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    licencia VARCHAR(60) NULL,
    telefono VARCHAR(40) NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id),
    INDEX idx_conductores_usuario (id_usuario)
);

-- ============================================
-- TABLA: vehiculos
-- ============================================
CREATE TABLE IF NOT EXISTS vehiculos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_organizacion INT NULL,
    placa VARCHAR(30) NOT NULL UNIQUE,
    marca VARCHAR(80) NULL,
    modelo VARCHAR(80) NULL,
    estado ENUM('ACTIVO', 'MANTENIMIENTO', 'INACTIVO') NOT NULL DEFAULT 'ACTIVO',
    viajes INT DEFAULT 0,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_organizacion) REFERENCES organizaciones(id),
    INDEX idx_vehiculos_estado (estado),
    INDEX idx_vehiculos_organizacion (id_organizacion)
);

-- ============================================
-- TABLA: viajes
-- ============================================
CREATE TABLE IF NOT EXISTS viajes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_organizacion INT NULL,
    id_conductor INT NOT NULL,
    id_vehiculo INT NULL,
    origen VARCHAR(160) NULL,
    destino VARCHAR(160) NULL,
    estado ENUM('PROGRAMADO', 'EN_CURSO', 'FINALIZADO', 'CANCELADO') NOT NULL DEFAULT 'PROGRAMADO',
    inicio DATETIME NULL,
    fin DATETIME NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    puntos INT DEFAULT 0,
    alertas INT DEFAULT 0,
    incidentes INT DEFAULT 0,
    FOREIGN KEY (id_organizacion) REFERENCES organizaciones(id),
    FOREIGN KEY (id_conductor) REFERENCES usuarios(id),
    FOREIGN KEY (id_vehiculo) REFERENCES vehiculos(id),
    INDEX idx_viajes_conductor (id_conductor),
    INDEX idx_viajes_estado (estado),
    INDEX idx_viajes_organizacion (id_organizacion)
);

-- ============================================
-- TABLA: alertas
-- ============================================
CREATE TABLE IF NOT EXISTS alertas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_conductor INT NOT NULL,
    id_viaje INT NULL,
    evento ENUM('BOSTEZO', 'MICROSUENO', 'FATIGA_ALTA', 'FATIGA_CRITICA', 'MONITOREO') NOT NULL,
    nivel ENUM('BAJO', 'MEDIO', 'ALTO', 'CRITICO') NOT NULL,
    fatiga INT NOT NULL DEFAULT 0,
    recomendacion VARCHAR(255) NOT NULL,
    latitud DECIMAL(10, 7) NULL,
    longitud DECIMAL(10, 7) NULL,
    fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_alertas_fecha (fecha),
    INDEX idx_alertas_nivel (nivel),
    INDEX idx_alertas_viaje (id_viaje),
    INDEX idx_alertas_conductor (id_conductor),
    FOREIGN KEY (id_conductor) REFERENCES usuarios(id),
    FOREIGN KEY (id_viaje) REFERENCES viajes(id)
);

-- ============================================
-- TABLA: ml_samples (Muestras para Machine Learning)
-- ============================================
CREATE TABLE IF NOT EXISTS ml_samples (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_alerta INT NULL,
    id_conductor INT NULL,
    ojos DOUBLE NULL,
    bostezos INT NULL,
    tiempo DOUBLE NULL,
    fatiga INT NULL,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ml_samples_alerta (id_alerta),
    INDEX idx_ml_samples_conductor (id_conductor),
    INDEX idx_ml_samples_fecha (fecha),
    FOREIGN KEY (id_alerta) REFERENCES alertas(id),
    FOREIGN KEY (id_conductor) REFERENCES usuarios(id)
);

-- ============================================
-- TABLA: posiciones_ruta (Tracking GPS)
-- ============================================
CREATE TABLE IF NOT EXISTS posiciones_ruta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_viaje INT NOT NULL,
    id_conductor INT NOT NULL,
    latitud DECIMAL(10, 7) NOT NULL,
    longitud DECIMAL(10, 7) NOT NULL,
    precision_gps DECIMAL(10, 2) NULL,
    velocidad DECIMAL(10, 2) NULL,
    fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_posiciones_viaje_fecha (id_viaje, fecha),
    INDEX idx_posiciones_conductor (id_conductor),
    FOREIGN KEY (id_viaje) REFERENCES viajes(id),
    FOREIGN KEY (id_conductor) REFERENCES usuarios(id)
);

-- ============================================
-- TABLA: incidentes
-- ============================================
CREATE TABLE IF NOT EXISTS incidentes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_viaje INT NOT NULL,
    id_conductor INT NOT NULL,
    tipo VARCHAR(40) NOT NULL,
    descripcion VARCHAR(255) NOT NULL,
    latitud DECIMAL(10, 7) NULL,
    longitud DECIMAL(10, 7) NULL,
    fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_incidentes_fecha (fecha),
    INDEX idx_incidentes_viaje (id_viaje),
    INDEX idx_incidentes_conductor (id_conductor),
    FOREIGN KEY (id_viaje) REFERENCES viajes(id),
    FOREIGN KEY (id_conductor) REFERENCES usuarios(id)
);

-- ============================================
-- TABLA: evidencias_alertas (Videos de evidencia)
-- ============================================
CREATE TABLE IF NOT EXISTS evidencias_alertas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_alerta INT NULL,
    id_viaje INT NOT NULL,
    id_conductor INT NOT NULL,
    evento VARCHAR(40) NOT NULL,
    nivel VARCHAR(20) NOT NULL,
    archivo VARCHAR(255) NOT NULL,
    mime VARCHAR(80) NOT NULL,
    bytes INT NOT NULL DEFAULT 0,
    duracion_ms INT NOT NULL DEFAULT 0,
    latitud DECIMAL(10, 7) NULL,
    longitud DECIMAL(10, 7) NULL,
    fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_evidencias_alerta (id_alerta),
    INDEX idx_evidencias_viaje (id_viaje),
    INDEX idx_evidencias_fecha (fecha),
    INDEX idx_evidencias_conductor (id_conductor),
    FOREIGN KEY (id_alerta) REFERENCES alertas(id),
    FOREIGN KEY (id_viaje) REFERENCES viajes(id),
    FOREIGN KEY (id_conductor) REFERENCES usuarios(id)
);

-- ============================================
-- DATOS INICIALES
-- ============================================

-- Insertar usuario administrador
INSERT INTO usuarios (nombre, correo, password, rol)
VALUES (
    'Administrador DriveSafe',
    'admin@drivesafe.ai',
    '$2y$10$eH3vgKWVZsvJCYSnjIM8iOpmFdJwbbHCdnJD4aw3/wjbMUmoS783.',
    'ADMIN'
)
ON DUPLICATE KEY UPDATE correo = VALUES(correo);

-- Insertar usuario conductor demo
INSERT INTO usuarios (nombre, correo, password, rol)
VALUES (
    'Conductor Demo',
    'conductor@drivesafe.ai',
    '$2y$10$eH3vgKWVZsvJCYSnjIM8iOpmFdJwbbHCdnJD4aw3/wjbMUmoS783.',
    'CONDUCTOR'
)
ON DUPLICATE KEY UPDATE correo = VALUES(correo);

-- Insertar datos del conductor
INSERT INTO conductores (id_usuario, licencia, telefono)
SELECT u.id, 'Categoria C', '70000000'
FROM usuarios u
WHERE u.correo = 'conductor@drivesafe.ai'
    AND NOT EXISTS (
        SELECT 1 FROM conductores c WHERE c.id_usuario = u.id
    );

-- Insertar vehículos de ejemplo
INSERT INTO vehiculos (placa, marca, modelo, estado)
VALUES
    ('DSA-1001', 'Toyota', 'Hiace', 'ACTIVO'),
    ('DSA-2002', 'Volvo', 'Interdepartamental', 'ACTIVO')
ON DUPLICATE KEY UPDATE placa = VALUES(placa);

-- ============================================
-- VISTAS ÚTILES
-- ============================================

-- Vista: Resumen de viajes con conductor y vehículo
CREATE OR REPLACE VIEW vista_viajes_completa AS
SELECT 
    v.id,
    v.origen,
    v.destino,
    v.estado,
    v.inicio,
    v.fin,
    v.puntos,
    vh.placa,
    vh.marca,
    vh.modelo,
    u.nombre AS conductor_nombre,
    u.correo AS conductor_correo
FROM viajes v
LEFT JOIN vehiculos vh ON vh.id = v.id_vehiculo
INNER JOIN usuarios u ON u.id = v.id_conductor;

-- Vista: Alertas recientes con conductor
CREATE OR REPLACE VIEW vista_alertas_recientes AS
SELECT 
    a.id,
    a.evento,
    a.nivel,
    a.fatiga,
    a.recomendacion,
    a.fecha,
    u.nombre AS conductor_nombre,
    v.origen,
    v.destino
FROM alertas a
LEFT JOIN viajes v ON v.id = a.id_viaje
INNER JOIN usuarios u ON u.id = a.id_conductor
ORDER BY a.fecha DESC;

-- Vista: Estadísticas de conductores
CREATE OR REPLACE VIEW vista_estadisticas_conductores AS
SELECT 
    u.id,
    u.nombre,
    u.correo,
    u.estado,
    (SELECT COUNT(*) FROM viajes WHERE id_conductor = u.id AND estado = 'EN_CURSO') AS rutas_activas,
    (SELECT COUNT(*) FROM alertas WHERE id_conductor = u.id AND fecha >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS alertas_24h,
    (SELECT COUNT(*) FROM alertas WHERE id_conductor = u.id) AS total_alertas,
    ROUND((SELECT AVG(fatiga) FROM alertas WHERE id_conductor = u.id), 1) AS fatiga_promedio
FROM usuarios u
WHERE u.rol = 'CONDUCTOR';

-- ============================================
-- FIN DEL SCRIPT
-- ============================================
