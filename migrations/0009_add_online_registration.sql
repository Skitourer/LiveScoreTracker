-- Online registration (spec § 5.3, step 11): language chosen by the family,
-- email verification link (48 h) and withdrawal link. Only SHA-256 hashes of
-- the links' tokens are stored.

ALTER TABLE registrations ADD COLUMN locale VARCHAR(8) NULL;

ALTER TABLE registrations ADD COLUMN verify_token VARCHAR(64) NULL;

ALTER TABLE registrations ADD COLUMN verify_expires_at VARCHAR(19) NULL;

ALTER TABLE registrations ADD COLUMN withdraw_token VARCHAR(64) NULL;

CREATE INDEX registrations_verify_token ON registrations (verify_token);

CREATE INDEX registrations_withdraw_token ON registrations (withdraw_token);
