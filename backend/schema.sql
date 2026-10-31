-- Workflow Engine v3 – Datenbankschema (MariaDB ≥ 10.6)
--
-- Frische Installation: `php bin/migrate.php` spielt diese Datei ein und vermerkt alle
-- Migrationen als angewendet. Bestehende 2.x-Datenbanken werden über `migrations/`
-- nachgezogen (siehe docs/migration-v3.md).
--
-- Zeitpunkte stehen in der Zeitzone der Anwendung (wie in 2.x).

CREATE TABLE IF NOT EXISTS wf_definition (
    id            VARCHAR(64)  NOT NULL,
    version       INT          NOT NULL DEFAULT 1,
    name          VARCHAR(255) NOT NULL,
    -- Vollständige Definition als JSON (Format wie 2.x, F-114).
    definition    JSON         NOT NULL,
    -- 1 = aktuelle (neueste) Version dieser id, genau eine je id. Für 2.x mitgeführt.
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    -- active | inactive | draft (F-10)
    status        VARCHAR(16)  NOT NULL DEFAULT 'active',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_instance (
    id              CHAR(36)     NOT NULL,
    definition_id   VARCHAR(64)  NOT NULL,
    definition_ver  INT          NOT NULL,
    current_step    VARCHAR(128) NOT NULL,
    -- running | waiting_event | waiting_timer | completed | failed | cancelled
    status          VARCHAR(32)  NOT NULL,
    context         JSON         NOT NULL,
    wake_at         DATETIME     NULL,
    attempts        INT          NOT NULL DEFAULT 0,
    subject_type    VARCHAR(64)  NULL,
    subject_id      VARCHAR(64)  NULL,
    last_error      TEXT         NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wake       (status, wake_at),
    KEY idx_subject    (subject_type, subject_id),
    KEY idx_definition (definition_id, definition_ver, status),
    KEY idx_list       (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_history (
    id            BIGINT       NOT NULL AUTO_INCREMENT,
    instance_id   CHAR(36)     NOT NULL,
    kind          VARCHAR(32)  NOT NULL,
    step          VARCHAR(128) NULL,
    -- Nur Namen und technische Angaben, keine eingegebenen Werte (F-91).
    detail        JSON         NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_instance (instance_id, id),
    CONSTRAINT fk_hist_instance FOREIGN KEY (instance_id)
        REFERENCES wf_instance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wf_template (
    id            VARCHAR(64)   NOT NULL,
    name          VARCHAR(255)  NOT NULL,
    -- email (Betreff + Inhalt) | page (Inhalt)
    type          VARCHAR(16)   NOT NULL DEFAULT 'email',
    subject       VARCHAR(1024) NOT NULL DEFAULT '',
    body          MEDIUMTEXT    NOT NULL,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sperrfrist für Trigger: je Trigger und Datensatz höchstens ein Start bis `locked_until` (F-143).
CREATE TABLE IF NOT EXISTS wf_trigger_lock (
    trigger_key   VARCHAR(128) NOT NULL,
    subject_key   VARCHAR(191) NOT NULL,
    locked_until  DATETIME     NOT NULL,
    PRIMARY KEY (trigger_key, subject_key),
    KEY idx_until (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zugangsdaten nur verschlüsselt (SodiumSecretStore, ADR 10).
CREATE TABLE IF NOT EXISTS wf_rest_connection (
    id            VARCHAR(64)   NOT NULL,
    name          VARCHAR(255)  NOT NULL,
    base_url      VARCHAR(2048) NOT NULL,
    auth_type     VARCHAR(16)   NOT NULL DEFAULT 'none',
    auth_header   VARCHAR(128)  NULL,
    secret        TEXT          NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- REST-Datenmodelle; nicht versioniert, Änderungen gelten sofort (F-141).
CREATE TABLE IF NOT EXISTS wf_rest_model (
    name          VARCHAR(64)   NOT NULL,
    label         VARCHAR(255)  NOT NULL,
    connection_id VARCHAR(64)   NOT NULL,
    spec          JSON          NOT NULL,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (name),
    KEY idx_connection (connection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vom Admin eingerichtete Trigger (F-142).
CREATE TABLE IF NOT EXISTS wf_trigger (
    id               VARCHAR(64)  NOT NULL,
    name             VARCHAR(255) NOT NULL,
    model            VARCHAR(64)  NOT NULL,
    definition_id    VARCHAR(64)  NOT NULL,
    conditions       JSON         NOT NULL,
    inputs           JSON         NOT NULL,
    interval_minutes INT          NOT NULL DEFAULT 15,
    lock_days        INT          NOT NULL DEFAULT 7,
    active           TINYINT(1)   NOT NULL DEFAULT 0,
    last_polled_at   DATETIME     NULL,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_model (model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Protokoll der Änderungen an Datenquellen, ohne Zugangsdaten (F-145).
CREATE TABLE IF NOT EXISTS wf_audit (
    id            BIGINT       NOT NULL AUTO_INCREMENT,
    at            DATETIME     NOT NULL,
    actor_id      VARCHAR(64)  NOT NULL,
    actor_name    VARCHAR(255) NOT NULL,
    action        VARCHAR(64)  NOT NULL,
    object_type   VARCHAR(32)  NOT NULL,
    object_id     VARCHAR(64)  NOT NULL,
    detail        JSON         NULL,
    PRIMARY KEY (id),
    KEY idx_object (object_type, object_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Angewendete Migrationen (bin/migrate.php).
CREATE TABLE IF NOT EXISTS wf_schema_version (
    name          VARCHAR(128) NOT NULL,
    applied_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
