-- Migration 001: Template-Typ (email|page) fuer wf_template.
--
-- Nachtraeglich fuer Datenbanken, die vor der Einfuehrung des Typs angelegt wurden.
-- Idempotent (ADD COLUMN IF NOT EXISTS) und damit gefahrlos mehrfach ausfuehrbar.
-- Bestehende Zeilen erhalten den Default 'email'.
ALTER TABLE wf_template
    ADD COLUMN IF NOT EXISTS type VARCHAR(16) NOT NULL DEFAULT 'email' AFTER name;
