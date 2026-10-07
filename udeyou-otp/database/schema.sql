-- =====================================================================
--  Udeyou OTP Platform — MySQL / MariaDB schema
--  utf8mb4 + InnoDB. All timestamps are stored in UTC.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1) clients: the companies / stores / developers subscribed to the platform
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clients (
    id             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    company_name   VARCHAR(120)     NOT NULL,
    email          VARCHAR(190)     NOT NULL,
    password_hash  VARCHAR(255)     NOT NULL,
    credits        INT UNSIGNED     NOT NULL DEFAULT 0,      -- balance: 1 credit = 1 sent OTP
    is_admin       TINYINT(1)       NOT NULL DEFAULT 0,
    status         ENUM('active','suspended') NOT NULL DEFAULT 'active',
    created_at     DATETIME         NOT NULL,
    updated_at     DATETIME         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_clients_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2) api_keys: only the SHA-256 hash of a key is stored, never the key itself
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_keys (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    client_id     INT UNSIGNED  NOT NULL,
    name          VARCHAR(80)   NOT NULL,
    mode          ENUM('live','test') NOT NULL DEFAULT 'live',
    key_prefix    VARCHAR(20)   NOT NULL,            -- e.g. "sk_live_a1B2c3" (shown in dashboard)
    key_hash      CHAR(64)      NOT NULL,            -- sha256(full key)
    last_used_at  DATETIME      NULL,
    revoked_at    DATETIME      NULL,
    created_at    DATETIME      NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_keys_hash (key_hash),
    KEY idx_api_keys_client (client_id),
    CONSTRAINT fk_api_keys_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3) otps: every OTP sent (also serves as the message log)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS otps (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id        VARCHAR(40)     NOT NULL,           -- "otp_..." returned to the client
    client_id        INT UNSIGNED    NOT NULL,
    api_key_id       INT UNSIGNED    NOT NULL,
    mode             ENUM('live','test') NOT NULL,
    recipient_email  VARCHAR(190)    NOT NULL,
    sender_name      VARCHAR(80)     NOT NULL,
    code_hash        CHAR(64)        NOT NULL,           -- HMAC-SHA256(code), never the plain code
    status           ENUM('pending','sent','failed','verified') NOT NULL DEFAULT 'pending',
    attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts     TINYINT UNSIGNED NOT NULL DEFAULT 5,
    expires_at       DATETIME        NOT NULL,
    sent_at          DATETIME        NULL,
    verified_at      DATETIME        NULL,
    error_message    VARCHAR(255)    NULL,
    ip_address       VARCHAR(45)     NULL,
    created_at       DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_otps_public_id (public_id),
    KEY idx_otps_client_recipient (client_id, recipient_email, created_at),
    KEY idx_otps_client_created (client_id, created_at),
    CONSTRAINT fk_otps_client  FOREIGN KEY (client_id)  REFERENCES clients (id)  ON DELETE CASCADE,
    CONSTRAINT fk_otps_api_key FOREIGN KEY (api_key_id) REFERENCES api_keys (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4) credit_transactions: ledger of every balance change (audit trail)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS credit_transactions (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id   INT UNSIGNED    NOT NULL,
    amount      INT             NOT NULL,            -- negative = spend, positive = top-up / refund
    type        ENUM('signup_bonus','topup','otp_send','refund','adjustment') NOT NULL,
    reference   VARCHAR(64)     NULL,                -- e.g. otp public_id
    note        VARCHAR(255)    NULL,
    created_at  DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_credit_tx_client (client_id, created_at),
    CONSTRAINT fk_credit_tx_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
