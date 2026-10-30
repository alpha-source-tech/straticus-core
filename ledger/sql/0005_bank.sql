-- Bank statements for any cash account, matched to the journal.
--
-- A host parses the bank's file (CSV, OFX...) and hands the core its lines.
-- Each line matches at most one journal line on the same account, for the
-- same amount; each journal line matches at most one bank line. A line the
-- books lack (a bank fee, interest) is posted from the bank line itself.
-- Re-importing the same statement adds nothing twice (a line's bank id is
-- unique per account).

CREATE TABLE ledger.bank_lines (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id       bigint      NOT NULL,
    book_id         bigint      NOT NULL,
    account_id      bigint      NOT NULL,
    posted_on       date        NOT NULL,
    amount_cents    bigint      NOT NULL CHECK (amount_cents <> 0),   -- in positive, out negative
    description     text        NOT NULL DEFAULT '' CHECK (length(description) <= 300),
    ref             text        NOT NULL DEFAULT '' CHECK (length(ref) <= 60),
    bank_id         text        NOT NULL CHECK (length(bank_id) BETWEEN 1 AND 120),
    statement_end   date        NOT NULL,
    statement_closing_cents bigint,
    matched_line_id bigint,
    matched_by      text        NOT NULL DEFAULT '' CHECK (length(matched_by) <= 120),
    matched_at      timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (book_id, id),
    UNIQUE (account_id, bank_id),
    FOREIGN KEY (tenant_id, book_id) REFERENCES ledger.books (tenant_id, id) ON DELETE CASCADE,
    FOREIGN KEY (book_id, account_id) REFERENCES ledger.accounts (book_id, id)
);
CREATE UNIQUE INDEX bank_lines_one_match ON ledger.bank_lines (matched_line_id) WHERE matched_line_id IS NOT NULL;
CREATE INDEX bank_lines_account ON ledger.bank_lines (account_id, posted_on);
CREATE TRIGGER bank_lines_a_tenant BEFORE INSERT ON ledger.bank_lines FOR EACH ROW EXECUTE FUNCTION ledger.fill_tenant();

-- A bank line keeps what the bank said. Its match is set once to a journal
-- line of the same account and amount; it can be undone only while that
-- day is not in a locked period.
CREATE FUNCTION ledger.guard_bank_lines() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE l record; locked date;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF ledger.is_system() OR NOT EXISTS (SELECT 1 FROM ledger.books WHERE id = OLD.book_id) THEN
            RETURN OLD;
        END IF;
        RAISE EXCEPTION 'bank lines are kept as the bank sent them';
    END IF;
    IF TG_OP = 'INSERT' THEN
        IF NOT EXISTS (SELECT 1 FROM ledger.accounts WHERE id = NEW.account_id AND cash_flow_class IN ('cash','restricted_cash')) THEN
            RAISE EXCEPTION 'bank statements are imported into cash accounts only';
        END IF;
        NEW.matched_line_id := NULL;
        NEW.matched_at := NULL;
        RETURN NEW;
    END IF;
    IF (to_jsonb(NEW) - 'matched_line_id' - 'matched_by' - 'matched_at') <> (to_jsonb(OLD) - 'matched_line_id' - 'matched_by' - 'matched_at') THEN
        RAISE EXCEPTION 'bank lines are kept as the bank sent them';
    END IF;
    SELECT locked_through INTO locked FROM ledger.books WHERE id = NEW.book_id;
    IF OLD.matched_line_id IS NOT NULL AND NEW.matched_line_id IS DISTINCT FROM OLD.matched_line_id
       AND locked IS NOT NULL AND OLD.posted_on <= locked THEN
        RAISE EXCEPTION 'that match is in a closed period and stays';
    END IF;
    IF NEW.matched_line_id IS NOT NULL AND NEW.matched_line_id IS DISTINCT FROM OLD.matched_line_id THEN
        SELECT ln.account_id, ln.debit_cents - ln.credit_cents AS amt INTO l FROM ledger.lines ln WHERE ln.id = NEW.matched_line_id AND ln.book_id = NEW.book_id;
        IF NOT FOUND OR l.account_id <> NEW.account_id OR l.amt <> NEW.amount_cents THEN
            RAISE EXCEPTION 'a bank line matches a journal line of the same account and amount';
        END IF;
        IF OLD.matched_line_id IS NOT NULL THEN
            RAISE EXCEPTION 'unmatch it first';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER bank_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON ledger.bank_lines FOR EACH ROW EXECUTE FUNCTION ledger.guard_bank_lines();

ALTER TABLE ledger.bank_lines ENABLE ROW LEVEL SECURITY;
ALTER TABLE ledger.bank_lines FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON ledger.bank_lines USING (ledger.tenant_ok(tenant_id)) WITH CHECK (ledger.tenant_ok(tenant_id));
GRANT SELECT, INSERT ON ledger.bank_lines TO {{APP_ROLE}};
GRANT UPDATE (matched_line_id, matched_by, matched_at) ON ledger.bank_lines TO {{APP_ROLE}};
GRANT USAGE ON ALL SEQUENCES IN SCHEMA ledger TO {{APP_ROLE}};
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA ledger TO {{APP_ROLE}};
