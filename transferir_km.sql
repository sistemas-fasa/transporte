START TRANSACTION;

UPDATE km_recorrido SET id_chofer = 23 WHERE id_chofer = 100000;
UPDATE combustible SET id_chofer = 23 WHERE id_chofer = 100000;
UPDATE asignaciones SET id_chofer = 23 WHERE id_chofer = 100000;
UPDATE usuarios SET id_chofer = 23 WHERE id_chofer = 100000;
DELETE FROM choferes WHERE id_chofer = 100000;

COMMIT;
