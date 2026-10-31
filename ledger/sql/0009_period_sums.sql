-- Period sums: every statement and balance used to add the journal up from
-- its first line. The journal is append-only, so a month's total for an
-- account never changes once the month is over; it is kept here, maintained
-- by trigger as lines post, and the readers add only the partial months at
-- the edges of a period from the lines themselves. Figures are identical to
-- the full sum (the core tests prove it); only the cost changes.
--
-- Dimensions: account, the entry's basis, the month, the party (for control
-- accounts' sub-ledgers), and whether the entry is a year-end close (the
-- statements leave those out when asked).

CREATE TABLE ledger.period_sums (
    tenant_id    bigint  NOT NULL,
    book_id      bigint  NOT NULL,
    account_id   bigint  NOT NULL,
    basis        text    NOT NULL CHECK (basis IN ('both', 'accrual', 'cash')),
    month        date    NOT NULL CHECK (month = date_trunc('month', month)::date),
    party        text    NOT NULL DEFAULT '',
    closing      boolean NOT NULL DEFAULT false,
    debit_cents  bigint  NOT NULL DEFAULT 0,
    credit_cents bigint  NOT NULL DEFAULT 0,
    PRIMARY KEY (book_id, account_id, basis, month, party, closing),
    FOREIGN KEY (tenant_id, book_id) REFERENCES ledger.books (tenant_id, id) ON DELETE CASCADE
);
CREATE INDEX period_sums_book_month ON ledger.period_sums (book_id, month);
CREATE INDEX lines_book_id ON ledger.lines (book_id, id);

-- Maintained by the lines themselves. SECURITY DEFINER: the application role
-- may read the sums but never write them directly.
CREATE FUNCTION ledger.sum_line() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER AS $$
DECLARE e record; r record; sgn int;
BEGIN
    r := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
    sgn := CASE WHEN TG_OP = 'DELETE' THEN -1 ELSE 1 END;
    SELECT entry_on, basis, event_type INTO e FROM ledger.entries WHERE id = r.entry_id;
    IF NOT FOUND THEN
        RETURN NULL;   -- the entry is going with its lines (a cascade); the sums go with the book
    END IF;
    INSERT INTO ledger.period_sums (tenant_id, book_id, account_id, basis, month, party, closing, debit_cents, credit_cents)
    VALUES (r.tenant_id, r.book_id, r.account_id, e.basis, date_trunc('month', e.entry_on)::date, r.party, e.event_type = 'period.year_end',
            sgn * r.debit_cents, sgn * r.credit_cents)
    ON CONFLICT (book_id, account_id, basis, month, party, closing) DO UPDATE
        SET debit_cents = ledger.period_sums.debit_cents + EXCLUDED.debit_cents,
            credit_cents = ledger.period_sums.credit_cents + EXCLUDED.credit_cents;
    RETURN NULL;
END $$;
CREATE TRIGGER lines_sum AFTER INSERT OR DELETE ON ledger.lines FOR EACH ROW EXECUTE FUNCTION ledger.sum_line();

-- What is already in the journal.
INSERT INTO ledger.period_sums (tenant_id, book_id, account_id, basis, month, party, closing, debit_cents, credit_cents)
SELECT l.tenant_id, l.book_id, l.account_id, e.basis, date_trunc('month', e.entry_on)::date, l.party, e.event_type = 'period.year_end',
       SUM(l.debit_cents), SUM(l.credit_cents)
  FROM ledger.lines l JOIN ledger.entries e ON e.id = l.entry_id
 GROUP BY l.tenant_id, l.book_id, l.account_id, e.basis, date_trunc('month', e.entry_on), l.party, e.event_type = 'period.year_end';

ALTER TABLE ledger.period_sums ENABLE ROW LEVEL SECURITY;
ALTER TABLE ledger.period_sums FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON ledger.period_sums USING (ledger.tenant_ok(tenant_id)) WITH CHECK (ledger.tenant_ok(tenant_id));
GRANT SELECT ON ledger.period_sums TO {{APP_ROLE}};
GRANT EXECUTE ON FUNCTION ledger.sum_line() TO {{APP_ROLE}};

-- A cheap change-stamp for caches: the journal only grows, so its newest line
-- id changes whenever anything is posted. (The SHA-256 fingerprint stays for
-- closes and audit; this is for memoisation.)
CREATE FUNCTION ledger.stamp(b bigint) RETURNS bigint LANGUAGE sql STABLE AS
  $$ SELECT coalesce(MAX(id), 0) FROM ledger.lines WHERE book_id = b $$;
GRANT EXECUTE ON FUNCTION ledger.stamp(bigint) TO {{APP_ROLE}};
