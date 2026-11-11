-- Results entered at the workshops (spec § 7.4, § 8): one row per version of an
-- attempt. A correction adds a new version and closes the old one; a deletion
-- only marks the current version. Nothing is ever physically erased (spec § 8.3).
-- Real personal data stays in registrations: here only ids and bibs.

CREATE TABLE attempts (
    id {{id}},
    client_id VARCHAR(64) NOT NULL UNIQUE,
    original_id BIGINT NULL,
    registration_id BIGINT NOT NULL,
    bib INTEGER NOT NULL,
    variant_id VARCHAR(64) NOT NULL,
    measured INTEGER NULL,
    modifiers TEXT NULL,
    modifier_total INTEGER NOT NULL DEFAULT 0,
    warnings TEXT NULL,
    entered_at VARCHAR(19) NOT NULL,
    user_id BIGINT NULL,
    username VARCHAR(64) NULL,
    referee_name VARCHAR(40) NULL,
    device_id VARCHAR(32) NULL,
    replaced_at VARCHAR(19) NULL,
    deleted_at VARCHAR(19) NULL,
    deleted_by VARCHAR(64) NULL
) {{table_options}};

CREATE INDEX attempts_child ON attempts (registration_id, variant_id);

CREATE INDEX attempts_variant ON attempts (variant_id);
