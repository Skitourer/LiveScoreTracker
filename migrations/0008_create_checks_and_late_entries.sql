-- Control board (spec § 10): a result "to check" marked as verified. A
-- correction is a new version of the attempt, so it starts unverified again.

ALTER TABLE attempts ADD COLUMN checked_at VARCHAR(19) NULL;

ALTER TABLE attempts ADD COLUMN checked_by VARCHAR(64) NULL;

-- Ties that no criterion could break, ordered by hand by an admin (spec § 9.6).
-- A decision applies only while the same children are still tied.
CREATE TABLE tie_decisions (
    id {{id}},
    category_id VARCHAR(32) NOT NULL,
    bib_set VARCHAR(255) NOT NULL,
    bibs TEXT NOT NULL,
    decided_at VARCHAR(19) NOT NULL,
    decided_by VARCHAR(64) NOT NULL
) {{table_options}};

CREATE UNIQUE INDEX tie_decisions_group ON tie_decisions (category_id, bib_set);

-- Entries sent by a phone after the competition was closed (spec § 8.8): kept
-- aside, not counted, until an admin or organizer accepts or refuses them.
CREATE TABLE late_entries (
    id {{id}},
    client_id VARCHAR(64) NOT NULL UNIQUE,
    workshop_id VARCHAR(64) NOT NULL,
    bib INTEGER NOT NULL,
    variant_id VARCHAR(64) NOT NULL,
    payload TEXT NOT NULL,
    received_at VARCHAR(19) NOT NULL,
    user_id BIGINT NULL,
    username VARCHAR(64) NULL,
    referee_name VARCHAR(40) NULL,
    device_id VARCHAR(32) NULL,
    decision VARCHAR(16) NULL,
    decided_at VARCHAR(19) NULL,
    decided_by VARCHAR(64) NULL
) {{table_options}};
