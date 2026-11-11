-- Reception on the competition day (spec § 6.2): bib handed out (= present),
-- bib given back and gift + medal handed out on leaving. Who did it is kept in
-- registration_history.

ALTER TABLE registrations ADD COLUMN checked_in_at VARCHAR(19) NULL;

ALTER TABLE registrations ADD COLUMN bib_returned_at VARCHAR(19) NULL;

ALTER TABLE registrations ADD COLUMN gift_given_at VARCHAR(19) NULL;
