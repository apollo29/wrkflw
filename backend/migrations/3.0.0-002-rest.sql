-- Migration 3.0: REST-Datenquellen ohne Code (F-133 bis F-145). Läuft genau einmal.

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
