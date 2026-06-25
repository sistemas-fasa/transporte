-- Agregar permisos faltantes para control de menu desde Roles
INSERT IGNORE INTO permisos (codigo, nombre, modulo) VALUES
('combustible_importar', 'Importar combustible', 'Combustible'),
('viajes_importar', 'Importar viajes', 'Kilometraje'),
('choferes_ver', 'Ver choferes', 'Choferes'),
('choferes_crear', 'Crear choferes', 'Choferes'),
('choferes_editar', 'Editar choferes', 'Choferes'),
('choferes_eliminar', 'Eliminar choferes', 'Choferes'),
('alertas_ver', 'Ver alertas', 'Alertas'),
('empresas_ver', 'Ver empresas', 'Empresas'),
('empresas_crear', 'Crear empresas', 'Empresas'),
('empresas_editar', 'Editar empresas', 'Empresas'),
('empresas_eliminar', 'Eliminar empresas', 'Empresas'),
('matafuegos_ver', 'Ver matafuegos', 'Matafuegos'),
('matafuegos_crear', 'Crear matafuegos', 'Matafuegos'),
('matafuegos_editar', 'Editar matafuegos', 'Matafuegos'),
('matafuegos_eliminar', 'Eliminar matafuegos', 'Matafuegos');

-- Asignar todos los nuevos permisos al Administrador (rol 1)
INSERT IGNORE INTO rol_permiso (id_rol, id_permiso)
SELECT 1, id_permiso FROM permisos
WHERE codigo IN ('combustible_importar', 'viajes_importar',
  'choferes_ver', 'choferes_crear', 'choferes_editar', 'choferes_eliminar',
  'alertas_ver',
  'empresas_ver', 'empresas_crear', 'empresas_editar', 'empresas_eliminar',
  'matafuegos_ver', 'matafuegos_crear', 'matafuegos_editar', 'matafuegos_eliminar');

-- Asignar permisos de solo lectura al Supervisor (rol 2)
INSERT IGNORE INTO rol_permiso (id_rol, id_permiso)
SELECT 2, id_permiso FROM permisos
WHERE codigo IN ('choferes_ver', 'choferes_crear', 'choferes_editar',
  'alertas_ver', 'empresas_ver', 'matafuegos_ver');
