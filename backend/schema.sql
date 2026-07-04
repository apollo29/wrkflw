-- =============================================================
--  Workflow Engine - MariaDB Schema (>= 10.5 fuer JSON/CHECK)
-- =============================================================

-- Unveraenderliche Workflow-DEFINITION (Template), versioniert.
CREATE TABLE IF NOT EXISTS wf_definition (
    id            VARCHAR(64)  NOT NULL,
    version       INT          NOT NULL DEFAULT 1,
    name          VARCHAR(255) NOT NULL,
    -- Vollstaendige Definition als JSON: startStep, steps, transitions ...
    definition    JSON         NOT NULL,
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Laufende INSTANZ einer Definition (ein konkreter Durchlauf).
CREATE TABLE IF NOT EXISTS wf_instance (
    id              CHAR(36)     NOT NULL,            -- UUID
    definition_id   VARCHAR(64)  NOT NULL,
    definition_ver  INT          NOT NULL,
    current_step    VARCHAR(128) NOT NULL,
    -- running | waiting_event | waiting_timer | completed | failed
    status          VARCHAR(32)  NOT NULL,
    -- Der Variablen-Kontext der Instanz (frei erweiterbar).
    context         JSON         NOT NULL,
    -- Fuer zeit-/timer-basierte Schritte: wann der Cron sie aufwecken soll.
    wake_at         DATETIME     NULL,
    -- Fehlversuche der aktuellen Action (Retry/Backoff); bei jedem erfolgreichen
    -- Schrittwechsel auf 0 zurueckgesetzt.
    attempts        INT          NOT NULL DEFAULT 0,
    -- Optionaler Bezug auf eine Entitaet der Host-App (z.B. order:123).
    subject_type    VARCHAR(64)  NULL,
    subject_id      VARCHAR(64)  NULL,
    last_error      TEXT         NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wake   (status, wake_at),
    KEY idx_subject(subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit-/HISTORIE: jeder Schritt, jedes Event, jede Transition.
CREATE TABLE IF NOT EXISTS wf_history (
    id            BIGINT       NOT NULL AUTO_INCREMENT,
    instance_id   CHAR(36)     NOT NULL,
    -- enter_step | action | event | transition | error | complete
    kind          VARCHAR(32)  NOT NULL,
    step          VARCHAR(128) NULL,
    detail        JSON         NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_instance (instance_id, id),
    CONSTRAINT fk_hist_instance FOREIGN KEY (instance_id)
        REFERENCES wf_instance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Wiederverwendbare TEMPLATES (z.B. E-Mail): Betreff + HTML-Body mit {{platzhalter}}.
-- Workflow-Schritte referenzieren ein Template ueber seine id (config.templateId).
CREATE TABLE IF NOT EXISTS wf_template (
    id            VARCHAR(64)   NOT NULL,
    name          VARCHAR(255)  NOT NULL,
    subject       VARCHAR(1024) NOT NULL DEFAULT '',
    body          MEDIUMTEXT    NOT NULL,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
