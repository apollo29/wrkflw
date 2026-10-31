-- Migration 2.x → 3.0: Änderungszeitpunkt je Version, Indizes für die Instanzliste,
-- Sperrfrist für Trigger und Buchführung der Migrationen. Läuft genau einmal
-- (wf_schema_version); die Anweisungen sind trotzdem wiederholbar formuliert.

ALTER TABLE wf_definition
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at;

UPDATE wf_definition SET updated_at = created_at;

ALTER TABLE wf_instance
    MODIFY updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE wf_instance ADD INDEX IF NOT EXISTS idx_definition (definition_id, definition_ver, status);

ALTER TABLE wf_instance ADD INDEX IF NOT EXISTS idx_list (status, updated_at);

CREATE TABLE IF NOT EXISTS wf_trigger_lock (
    trigger_key   VARCHAR(128) NOT NULL,
    subject_key   VARCHAR(191) NOT NULL,
    locked_until  DATETIME     NOT NULL,
    PRIMARY KEY (trigger_key, subject_key),
    KEY idx_until (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_schema_version (
    name          VARCHAR(128) NOT NULL,
    applied_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
