-- Accounts, sessions and security (spec § 3, § 19) and the audit log (spec § 11.6).
-- Dates are stored as 'YYYY-MM-DD HH:MM:SS' in UTC.

CREATE TABLE users (
    id {{id}},
    username VARCHAR(64) NOT NULL UNIQUE,
    display_name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NULL,
    role VARCHAR(16) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    must_change_password SMALLINT NOT NULL DEFAULT 0,
    totp_secret VARCHAR(128) NULL,
    totp_enabled_at VARCHAR(19) NULL,
    session_version INTEGER NOT NULL DEFAULT 1,
    disabled_at VARCHAR(19) NULL,
    last_login_at VARCHAR(19) NULL,
    created_at VARCHAR(19) NOT NULL,
    updated_at VARCHAR(19) NOT NULL
) {{table_options}};

CREATE TABLE sessions (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    user_id BIGINT NULL,
    data TEXT NOT NULL,
    device_id VARCHAR(32) NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at VARCHAR(19) NOT NULL,
    last_seen_at VARCHAR(19) NOT NULL,
    expires_at VARCHAR(19) NOT NULL
) {{table_options}};

CREATE INDEX sessions_user ON sessions (user_id);

CREATE INDEX sessions_expires ON sessions (expires_at);

CREATE TABLE recovery_codes (
    id {{id}},
    user_id BIGINT NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    used_at VARCHAR(19) NULL,
    created_at VARCHAR(19) NOT NULL
) {{table_options}};

CREATE INDEX recovery_codes_user ON recovery_codes (user_id);

CREATE TABLE password_resets (
    id {{id}},
    user_id BIGINT NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    expires_at VARCHAR(19) NOT NULL,
    used_at VARCHAR(19) NULL,
    created_at VARCHAR(19) NOT NULL
) {{table_options}};

CREATE TABLE throttle (
    throttle_key VARCHAR(191) NOT NULL PRIMARY KEY,
    failures INTEGER NOT NULL DEFAULT 0,
    blocked_until VARCHAR(19) NULL,
    updated_at VARCHAR(19) NOT NULL
) {{table_options}};

CREATE TABLE audit_log (
    id {{id}},
    occurred_at VARCHAR(19) NOT NULL,
    user_id BIGINT NULL,
    username VARCHAR(64) NULL,
    role VARCHAR(16) NULL,
    action VARCHAR(64) NOT NULL,
    details TEXT NULL,
    ip VARCHAR(45) NULL,
    device_id VARCHAR(32) NULL
) {{table_options}};

CREATE INDEX audit_log_occurred ON audit_log (occurred_at);

CREATE INDEX audit_log_action ON audit_log (action);
