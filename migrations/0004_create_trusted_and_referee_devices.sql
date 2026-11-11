-- Devices trusted for 30 days after a two-factor login (spec § 19.1): only a hash of the token is stored.
CREATE TABLE trusted_devices (
    id {{id}},
    user_id BIGINT NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    device_id VARCHAR(32) NOT NULL,
    user_agent VARCHAR(255) NULL,
    created_at VARCHAR(19) NOT NULL,
    last_used_at VARCHAR(19) NOT NULL,
    expires_at VARCHAR(19) NOT NULL
) {{table_options}};

CREATE INDEX trusted_devices_user ON trusted_devices (user_id);

-- Phones used with the shared referee account: the referee's first name is remembered,
-- and the admin can block a phone for good (spec § 19.1).
CREATE TABLE referee_devices (
    device_id VARCHAR(32) NOT NULL PRIMARY KEY,
    referee_name VARCHAR(40) NOT NULL,
    user_agent VARCHAR(255) NULL,
    first_seen_at VARCHAR(19) NOT NULL,
    last_seen_at VARCHAR(19) NOT NULL,
    blocked_at VARCHAR(19) NULL,
    blocked_by VARCHAR(64) NULL
) {{table_options}};
