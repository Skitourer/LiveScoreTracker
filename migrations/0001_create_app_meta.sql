-- Key/value store for application-level metadata (schema version, install date...).
CREATE TABLE app_meta (
    meta_key VARCHAR(64) NOT NULL PRIMARY KEY,
    meta_value TEXT NOT NULL
);
