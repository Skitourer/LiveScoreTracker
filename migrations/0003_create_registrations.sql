-- Registered children (spec § 5) and the history of changes to each record (spec § 5.9).
-- Real personal data: lives only in the production database, purged 30 days after the event (spec § 17).

CREATE TABLE registrations (
    id {{id}},
    bib INTEGER NULL UNIQUE,
    status VARCHAR(24) NOT NULL,
    source VARCHAR(16) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    birth_date VARCHAR(10) NULL,
    gender VARCHAR(16) NOT NULL,
    school VARCHAR(150) NOT NULL,
    level VARCHAR(16) NOT NULL,
    guardian_name VARCHAR(150) NOT NULL,
    guardian_phone VARCHAR(40) NOT NULL,
    guardian_email VARCHAR(190) NOT NULL,
    volunteer SMALLINT NOT NULL DEFAULT 0,
    match_key VARCHAR(255) NOT NULL,
    created_by BIGINT NULL,
    created_at VARCHAR(19) NOT NULL,
    updated_at VARCHAR(19) NOT NULL,
    verified_at VARCHAR(19) NULL,
    registered_at VARCHAR(19) NULL,
    withdrawn_at VARCHAR(19) NULL
) {{table_options}};

CREATE INDEX registrations_status ON registrations (status);

CREATE INDEX registrations_match ON registrations (match_key);

CREATE TABLE registration_history (
    id {{id}},
    registration_id BIGINT NOT NULL,
    occurred_at VARCHAR(19) NOT NULL,
    user_id BIGINT NULL,
    username VARCHAR(64) NULL,
    action VARCHAR(32) NOT NULL,
    changes TEXT NULL
) {{table_options}};

CREATE INDEX registration_history_registration ON registration_history (registration_id);
