-- How these books began: a start-up opens at zero on its first day; an
-- existing business brings balances in as of the day before its start date,
-- and everything before that date is locked. Until the owner has chosen,
-- the books are 'unset' and the host shows its setup instead of statements.

ALTER TABLE ledger.books
    ADD COLUMN origin     text NOT NULL DEFAULT 'unset' CHECK (origin IN ('unset', 'startup', 'existing')),
    ADD COLUMN started_on date;                       -- the first day these books cover
GRANT UPDATE (origin, started_on) ON ledger.books TO {{APP_ROLE}};

-- Once chosen, the origin and start date never change: the books' history
-- is read against them.
CREATE OR REPLACE FUNCTION ledger.guard_books_origin() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.origin <> 'unset' AND (NEW.origin IS DISTINCT FROM OLD.origin OR NEW.started_on IS DISTINCT FROM OLD.started_on) AND NOT ledger.is_system() THEN
        RAISE EXCEPTION 'how these books began does not change';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER books_guard_origin BEFORE UPDATE ON ledger.books FOR EACH ROW EXECUTE FUNCTION ledger.guard_books_origin();
GRANT EXECUTE ON FUNCTION ledger.guard_books_origin() TO {{APP_ROLE}};
