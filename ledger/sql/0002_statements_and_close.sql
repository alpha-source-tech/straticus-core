-- Statements and period close.
--
-- A book's fiscal year starts in a month of its choosing (January unless
-- set). Current-year earnings on the balance sheet run from that start.
--
-- A close is the owner's sign-off of a period: the statement set exactly as
-- signed is fingerprinted (SHA-256), the books lock through the period end,
-- and the close itself never changes. Unlocking is trusted code only, with a
-- reason, and is recorded as its own row.

ALTER TABLE ledger.books ADD COLUMN fiscal_year_start smallint NOT NULL DEFAULT 1 CHECK (fiscal_year_start BETWEEN 1 AND 12);
GRANT UPDATE (fiscal_year_start) ON ledger.books TO {{APP_ROLE}};

CREATE TABLE ledger.closes (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id     bigint      NOT NULL,
    book_id       bigint      NOT NULL,
    period_start  date        NOT NULL,
    period_end    date        NOT NULL CHECK (period_end >= period_start),
    basis         text        NOT NULL CHECK (basis IN ('accrual','cash')),
    statements_sha256 text    NOT NULL CHECK (statements_sha256 ~ '^[0-9a-f]{64}$'),
    journal_sha256    text    NOT NULL CHECK (journal_sha256 ~ '^[0-9a-f]{64}$'),
    signed_by     text        NOT NULL CHECK (length(signed_by) BETWEEN 1 AND 120),
    note          text        NOT NULL DEFAULT '' CHECK (length(note) <= 1000),
    kind          text        NOT NULL DEFAULT 'close' CHECK (kind IN ('close','unlock')),
    unlocked_to   date,                                -- an unlock: the lock date after it
    created_at    timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (tenant_id, book_id) REFERENCES ledger.books (tenant_id, id) ON DELETE CASCADE
);
CREATE INDEX closes_book ON ledger.closes (book_id, period_end);
CREATE TRIGGER closes_a_tenant BEFORE INSERT ON ledger.closes FOR EACH ROW EXECUTE FUNCTION ledger.fill_tenant();

CREATE FUNCTION ledger.guard_closes() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF ledger.is_system() OR NOT EXISTS (SELECT 1 FROM ledger.books WHERE id = OLD.book_id) THEN
            RETURN OLD;
        END IF;
        RAISE EXCEPTION 'a signed close is never removed';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        RAISE EXCEPTION 'a signed close is never changed';
    END IF;
    NEW.created_at := now();
    IF NEW.kind = 'unlock' AND NOT ledger.is_system() THEN
        RAISE EXCEPTION 'a period is unlocked only by trusted code, with a reason on record';
    END IF;
    IF NEW.kind = 'unlock' AND length(NEW.note) < 5 THEN
        RAISE EXCEPTION 'an unlock needs a reason';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER closes_guard BEFORE INSERT OR UPDATE OR DELETE ON ledger.closes FOR EACH ROW EXECUTE FUNCTION ledger.guard_closes();

ALTER TABLE ledger.closes ENABLE ROW LEVEL SECURITY;
ALTER TABLE ledger.closes FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON ledger.closes USING (ledger.tenant_ok(tenant_id)) WITH CHECK (ledger.tenant_ok(tenant_id));
GRANT SELECT, INSERT ON ledger.closes TO {{APP_ROLE}};
GRANT USAGE ON ALL SEQUENCES IN SCHEMA ledger TO {{APP_ROLE}};
GRANT EXECUTE ON FUNCTION ledger.guard_closes() TO {{APP_ROLE}};

-- The lock date advances only with a close signed in the same transaction
-- (ledger_close_period); never by a bare update, which could shut the books.
CREATE OR REPLACE FUNCTION ledger.guard_books() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF ledger.is_system() THEN
        RETURN NEW;
    END IF;
    IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id THEN
        RAISE EXCEPTION 'books stay with their tenant';
    END IF;
    IF OLD.locked_through IS NOT NULL AND (NEW.locked_through IS NULL OR NEW.locked_through < OLD.locked_through) THEN
        RAISE EXCEPTION 'a locked period is unlocked only by trusted code, with a reason on record';
    END IF;
    IF NEW.locked_through IS DISTINCT FROM OLD.locked_through
       AND NOT EXISTS (SELECT 1 FROM ledger.closes c WHERE c.book_id = OLD.id AND c.kind = 'close' AND c.period_end = NEW.locked_through AND c.created_at = now()) THEN
        RAISE EXCEPTION 'the books lock only when a period is signed off';
    END IF;
    RETURN NEW;
END $$;
