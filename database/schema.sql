CREATE DATABASE IF NOT EXISTS gestion_flota CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE gestion_flota;

-- Tabla de usuarios (login)
CREATE TABLE IF NOT EXISTS usuarios (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    rol ENUM('admin', 'chofer') NOT NULL DEFAULT 'chofer',
    id_chofer INT DEFAULT NULL,
    activo TINYINT(1) DEFAULT 1,
    ultimo_acceso DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Tabla choferes
CREATE TABLE IF NOT EXISTS choferes (
    id_chofer INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NOT NULL,
    dni VARCHAR(20) NOT NULL UNIQUE,
    telefono VARCHAR(30),
    licencia VARCHAR(50),
    vencimiento_licencia DATE DEFAULT NULL,
    estado ENUM('activo', 'inactivo') DEFAULT 'activo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Tabla camiones
CREATE TABLE IF NOT EXISTS camiones (
    id_camion INT AUTO_INCREMENT PRIMARY KEY,
    patente VARCHAR(20) NOT NULL UNIQUE,
    marca VARCHAR(100) NOT NULL,
    modelo VARCHAR(100) NOT NULL,
    anio INT DEFAULT NULL,
    kilometraje_actual DECIMAL(12,2) DEFAULT 0,
    horas_actuales DECIMAL(12,2) DEFAULT 0,
    capacidad_tanque DECIMAL(10,2) DEFAULT NULL,
    vtv DATE DEFAULT NULL,
    tara DECIMAL(10,2) DEFAULT NULL,
    tipo VARCHAR(50) DEFAULT 'camion',
    estado ENUM('activo', 'mantenimiento', 'fuera_de_servicio') DEFAULT 'activo',
    por_hora TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Tabla asignaciones (chofer - camion historial)
CREATE TABLE IF NOT EXISTS asignaciones (
    id_asignacion INT AUTO_INCREMENT PRIMARY KEY,
    id_chofer INT NOT NULL,
    id_camion INT NOT NULL,
    fecha_desde DATE NOT NULL,
    fecha_hasta DATE DEFAULT NULL,
    activa TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_chofer) REFERENCES choferes(id_chofer) ON DELETE CASCADE,
    FOREIGN KEY (id_camion) REFERENCES camiones(id_camion) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabla hoja de ruta (kilometraje)
CREATE TABLE IF NOT EXISTS km_recorrido (
    id_hoja INT AUTO_INCREMENT PRIMARY KEY,
    id_chofer INT NOT NULL,
    id_camion INT NOT NULL,
    fecha DATE NOT NULL,
    km_salida DECIMAL(12,2) DEFAULT 0,
    km_llegada DECIMAL(12,2) DEFAULT 0,
    km_recorridos DECIMAL(12,2) GENERATED ALWAYS AS (km_llegada - km_salida) STORED,
    hs_salida DECIMAL(10,2) DEFAULT NULL,
    hs_llegada DECIMAL(10,2) DEFAULT NULL,
    hs_recorridas DECIMAL(10,2) GENERATED ALWAYS AS (hs_llegada - hs_salida) STORED,
    origen VARCHAR(255) DEFAULT NULL,
    destino VARCHAR(255) DEFAULT NULL,
    observaciones TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_chofer) REFERENCES choferes(id_chofer) ON DELETE CASCADE,
    FOREIGN KEY (id_camion) REFERENCES camiones(id_camion) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabla combustible
CREATE TABLE IF NOT EXISTS combustible (
    id_combustible INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATETIME NOT NULL,
    id_chofer INT NOT NULL,
    id_camion INT NOT NULL,
    estacion_servicio VARCHAR(200) DEFAULT NULL,
    litros DECIMAL(12,4) NOT NULL,
    precio_litro DECIMAL(12,4) NOT NULL,
    importe_total DECIMAL(12,2) GENERATED ALWAYS AS (litros * precio_litro) STORED,
    kilometraje_al_cargar DECIMAL(12,2) DEFAULT NULL,
    horas_al_cargar DECIMAL(12,2) DEFAULT NULL,
    km_recorridos DECIMAL(12,2) DEFAULT NULL,
    km_por_litro DECIMAL(12,2) DEFAULT NULL,
    litros_cada_100km DECIMAL(12,2) DEFAULT NULL,
    costo_por_km DECIMAL(12,2) DEFAULT NULL,
    hs_recorridas DECIMAL(12,2) DEFAULT NULL,
    litros_por_hora DECIMAL(12,2) DEFAULT NULL,
    costo_por_hora DECIMAL(12,2) DEFAULT NULL,
    error_consumo VARCHAR(255) DEFAULT NULL,
    foto_ticket VARCHAR(255) DEFAULT NULL,
    id_usuario_registra INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_combustible_fecha (fecha),
    INDEX idx_combustible_km (kilometraje_al_cargar),
    FOREIGN KEY (id_chofer) REFERENCES choferes(id_chofer) ON DELETE CASCADE,
    FOREIGN KEY (id_camion) REFERENCES camiones(id_camion) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabla mantenimientos
CREATE TABLE IF NOT EXISTS mantenimientos (
    id_mantenimiento INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL,
    id_camion INT NOT NULL,
    tipo ENUM('cambio_aceite', 'filtros', 'cubiertas', 'frenos', 'embrague', 'reparacion_general', 'otro') NOT NULL,
    descripcion TEXT DEFAULT NULL,
    proveedor VARCHAR(200) DEFAULT NULL,
    costo DECIMAL(12,2) DEFAULT 0,
    kilometraje DECIMAL(12,2) DEFAULT NULL,
    proximo_mantenimiento_km DECIMAL(12,2) DEFAULT NULL,
    proximo_mantenimiento_fecha DATE DEFAULT NULL,
    foto_factura VARCHAR(255) DEFAULT NULL,
    id_usuario_registra INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_camion) REFERENCES camiones(id_camion) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabla alertas
CREATE TABLE IF NOT EXISTS alertas (
    id_alerta INT AUTO_INCREMENT PRIMARY KEY,
    tipo ENUM('vencimiento_licencia', 'vencimiento_vtv', 'vencimiento_seguro', 'cambio_aceite', 'mantenimiento_programado') NOT NULL,
    id_referencia INT DEFAULT NULL,
    mensaje VARCHAR(500) NOT NULL,
    severidad ENUM('verde', 'amarillo', 'rojo') DEFAULT 'verde',
    leida TINYINT(1) DEFAULT 0,
    resuelta TINYINT(1) DEFAULT 0,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_referencia) REFERENCES camiones(id_camion) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Tabla de auditoria
CREATE TABLE IF NOT EXISTS auditoria (
    id_auditoria INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT DEFAULT NULL,
    accion VARCHAR(50) NOT NULL,
    tabla VARCHAR(50) DEFAULT NULL,
    id_registro INT DEFAULT NULL,
    detalle TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Tabla vtv (para alertas de vencimiento)
CREATE TABLE IF NOT EXISTS vtv (
    id_vtv INT AUTO_INCREMENT PRIMARY KEY,
    id_camion INT NOT NULL,
    fecha_vencimiento DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_camion) REFERENCES camiones(id_camion) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabla seguros
CREATE TABLE IF NOT EXISTS seguros (
    id_seguro INT AUTO_INCREMENT PRIMARY KEY,
    id_camion INT NOT NULL,
    compania VARCHAR(200) DEFAULT NULL,
    poliza VARCHAR(100) DEFAULT NULL,
    fecha_vencimiento DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_camion) REFERENCES camiones(id_camion) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Insertar usuario admin por defecto (password: admin123)
INSERT INTO usuarios (username, password, email, rol, activo) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@reciclarg.com.ar', 'admin', 1);
