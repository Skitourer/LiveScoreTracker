-- How a result was obtained (spec § 8.4, § 8.5): built-in timer, timer value
-- edited by hand, or counter with the detail of each action. Empty for a
-- value typed by hand.

ALTER TABLE attempts ADD COLUMN details TEXT NULL;
