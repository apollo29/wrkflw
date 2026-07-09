-- Migration 002: Lebenszyklus-Status (active|inactive|draft) fuer wf_definition.
--
-- Nachtraeglich fuer Datenbanken, die vor der Status-Einfuehrung angelegt wurden.
-- Idempotent (ADD COLUMN IF NOT EXISTS). Bestehende Zeilen erhalten 'active', bleiben
-- also wie bisher ausgeliefert/getriggert.
ALTER TABLE wf_definition
    ADD COLUMN IF NOT EXISTS status VARCHAR(16) NOT NULL DEFAULT 'active' AFTER active;
