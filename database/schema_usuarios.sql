-- ============================================
-- Módulo de Usuarios - Sistema de Flota
-- ============================================

-- Extender tabla usuarios
ALTER TABLE usuarios
  ADD COLUMN nombre VARCHAR(100) AFTER username,
  ADD COLUMN apellido VARCHAR(100) AFTER nombre,
  ADD COLUMN telefono VARCHAR(30) AFTER email,
  ADD COLUMN intentos_fallidos INT DEFAULT 0,
  ADD COLUMN bloqueado_hasta DATETIME DEFAULT NULL;

-- Tabla de roles
CREATE TABLE IF NOT EXISTS roles (
    id_rol INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE,
    descripcion TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Tabla de permisos
CREATE TABLE IF NOT EXISTS permisos (
    id_permiso INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(50) NOT NULL UNIQUE,
    nombre VARCHAR(100) NOT NULL,
    modulo VARCHAR(50) NOT NULL,
    descripcion TEXT
) ENGINE=InnoDB;

-- Relación rol - permiso
CREATE TABLE IF NOT EXISTS rol_permiso (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_rol INT NOT NULL,
    id_permiso INT NOT NULL,
    FOREIGN KEY (id_rol) REFERENCES roles(id_rol) ON DELETE CASCADE,
    FOREIGN KEY (id_permiso) REFERENCES permisos(id_permiso) ON DELETE CASCADE,
    UNIQUE KEY (id_rol, id_permiso)
) ENGINE=InnoDB;

-- Relación usuario - rol (reemplaza usuarios.rol)
CREATE TABLE IF NOT EXISTS usuario_rol (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    id_rol INT NOT NULL,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
    FOREIGN KEY (id_rol) REFERENCES roles(id_rol) ON DELETE CASCADE,
    UNIQUE KEY (id_usuario, id_rol)
) ENGINE=InnoDB;

-- Asignación chofer/usuario - vehículo
CREATE TABLE IF NOT EXISTS vehiculos_usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    vehiculo_id INT NOT NULL,
    fecha_asignacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
    FOREIGN KEY (vehiculo_id) REFERENCES camiones(id_camion) ON DELETE CASCADE,
    UNIQUE KEY (usuario_id, vehiculo_id)
) ENGINE=InnoDB;

-- Auditoría de accesos
CREATE TABLE IF NOT EXISTS auditoria_accesos (
    id_auditoria_acceso INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT DEFAULT NULL,
    fecha_hora TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) DEFAULT NULL,
    accion VARCHAR(100) NOT NULL,
    modulo VARCHAR(50) DEFAULT NULL,
    id_registro INT DEFAULT NULL,
    detalle TEXT,
    user_agent VARCHAR(500) DEFAULT NULL,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================
-- Datos iniciales
-- ============================================

-- Roles por defecto
INSERT INTO roles (nombre, descripcion) VALUES
('Administrador', 'Acceso total al sistema. Puede gestionar usuarios, roles y toda la información.'),
('Supervisor', 'Puede ver toda la información, crear y editar registros. No puede gestionar usuarios.'),
('Chofer', 'Puede ver únicamente sus registros, cargar combustible y registrar kilometraje.');

-- Permisos disponibles
INSERT INTO permisos (codigo, nombre, modulo) VALUES
('vehiculos_ver', 'Ver vehículos', 'Vehículos'),
('vehiculos_crear', 'Crear vehículos', 'Vehículos'),
('vehiculos_editar', 'Editar vehículos', 'Vehículos'),
('vehiculos_eliminar', 'Eliminar vehículos', 'Vehículos'),
('combustible_ver', 'Ver combustible', 'Combustible'),
('combustible_cargar', 'Cargar combustible', 'Combustible'),
('combustible_editar', 'Editar combustible', 'Combustible'),
('combustible_eliminar', 'Eliminar combustible', 'Combustible'),
('kilometraje_ver', 'Ver kilometraje', 'Kilometraje'),
('kilometraje_cargar', 'Cargar kilometraje', 'Kilometraje'),
('kilometraje_editar', 'Editar kilometraje', 'Kilometraje'),
('mantenimiento_ver', 'Ver mantenimiento', 'Mantenimiento'),
('mantenimiento_crear', 'Crear mantenimiento', 'Mantenimiento'),
('mantenimiento_editar', 'Editar mantenimiento', 'Mantenimiento'),
('mantenimiento_eliminar', 'Eliminar mantenimiento', 'Mantenimiento'),
('reportes_ver', 'Ver reportes', 'Reportes'),
('reportes_exportar_pdf', 'Exportar PDF', 'Reportes'),
('reportes_exportar_excel', 'Exportar Excel', 'Reportes'),
('usuarios_ver', 'Ver usuarios', 'Usuarios'),
('usuarios_crear', 'Crear usuarios', 'Usuarios'),
('usuarios_editar', 'Editar usuarios', 'Usuarios'),
('usuarios_eliminar', 'Eliminar usuarios', 'Usuarios');

-- Asignar todos los permisos al Administrador (rol 1)
INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT 1, id_permiso FROM permisos;

-- Asignar permisos al Supervisor (rol 2): todo excepto gestión de usuarios y eliminación
INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT 2, id_permiso FROM permisos
WHERE codigo NOT IN ('usuarios_crear', 'usuarios_editar', 'usuarios_eliminar', 'vehiculos_eliminar', 'combustible_eliminar', 'mantenimiento_eliminar');

-- Asignar permisos al Chofer (rol 3): solo lo básico
INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT 3, id_permiso FROM permisos
WHERE codigo IN ('combustible_cargar', 'kilometraje_cargar', 'mantenimiento_crear');

-- Migrar usuarios existentes al nuevo sistema de roles
INSERT INTO usuario_rol (id_usuario, id_rol)
SELECT u.id_usuario, 1 FROM usuarios u WHERE u.rol = 'admin'
AND NOT EXISTS (SELECT 1 FROM usuario_rol ur WHERE ur.id_usuario = u.id_usuario AND ur.id_rol = 1);

INSERT INTO usuario_rol (id_usuario, id_rol)
SELECT u.id_usuario, 3 FROM usuarios u WHERE u.rol = 'chofer'
AND NOT EXISTS (SELECT 1 FROM usuario_rol ur WHERE ur.id_usuario = u.id_usuario AND ur.id_rol = 3);

-- ============================================
-- Agregar columnas a tablas existentes
-- ============================================

-- Agregar usuario_id y fecha_modificacion a tablas principales
-- Nota: Si alguna columna ya existe, el ALTER fallará silenciosamente
ALTER TABLE camiones
  ADD COLUMN usuario_id INT DEFAULT NULL AFTER tara,
  ADD COLUMN fecha_modificacion TIMESTAMP NULL AFTER usuario_id;

ALTER TABLE choferes
  ADD COLUMN usuario_id INT DEFAULT NULL AFTER vencimiento_licencia,
  ADD COLUMN fecha_modificacion TIMESTAMP NULL AFTER usuario_id;

ALTER TABLE km_recorrido
  ADD COLUMN usuario_id INT DEFAULT NULL AFTER observaciones,
  ADD COLUMN fecha_modificacion TIMESTAMP NULL AFTER usuario_id;

ALTER TABLE combustible
  ADD COLUMN fecha_modificacion TIMESTAMP NULL AFTER created_at;

ALTER TABLE mantenimientos
  ADD COLUMN fecha_modificacion TIMESTAMP NULL AFTER created_at;
