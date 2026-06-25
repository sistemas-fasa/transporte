ALTER TABLE km_recorrido
  ADD COLUMN external_id VARCHAR(100) DEFAULT NULL AFTER observaciones,
  ADD COLUMN external_source VARCHAR(50) DEFAULT 'VFP' AFTER external_id;

CREATE UNIQUE INDEX uk_external ON km_recorrido (external_source, external_id);
