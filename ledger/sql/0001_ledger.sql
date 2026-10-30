-- STRATICUS accounting core: one double-entry journal per set of books.
--
-- This schema knows nothing about the product that hosts it. A host (LITICUS
-- first, other STRATICUS products later) gives each of its tenants one or
-- more books, and binds tenancy by replacing ledger.tenant_ok() and
-- ledger.is_system() with its own scope rules. The defaults below read two
-- settings a host can set per connection: ledger.tenant_id and ledger.system.
--
-- Rules the database itself enforces, whatever the application does:
--   * every entry balances (debits = credits) and has at least two lines;
--   * posted entries and lines are never changed or removed; a correction is
--     a reversing entry plus a new one;
--   * nothing is dated on or before a book's locked date;
--   * a line posts only to an active account of the same book, and a control
--     account (receivables, payables, client trust) only with a party;
--   * documents, their lines, settlements and allocations keep the figures
--     they were written with;
--   * a settlement's allocations add up to its amount;
--   * every row belongs to one tenant, and row-level security keeps tenants
--     apart.
--
-- Two bases from one journal: every entry is marked 'both', 'accrual' or
-- 'cash'. A report on a basis reads the entries marked with it plus 'both'.
-- An invoice's revenue is an accrual entry; a payment against it posts an
-- accrual entry (cash against receivables) and a cash entry (cash against
-- the invoice lines the payment was assigned to). Each basis balances on
-- its own.

CREATE SCHEMA ledger;

-- ── Tenancy (hosts replace these two) ───────────────────────────────────
CREATE FUNCTION ledger.is_system() RETURNS boolean LANGUAGE sql STABLE AS
  $$ SELECT coalesce(current_setting('ledger.system', true), '') = '1' $$;
CREATE FUNCTION ledger.tenant_ok(t bigint) RETURNS boolean LANGUAGE sql STABLE AS
  $$ SELECT ledger.is_system() OR t = nullif(current_setting('ledger.tenant_id', true), '')::bigint $$;

-- ── Books ───────────────────────────────────────────────────────────────
CREATE TABLE ledger.books (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id      bigint      NOT NULL,
    name           text        NOT NULL CHECK (length(name) BETWEEN 1 AND 160),
    entity_form    text        NOT NULL DEFAULT 'unspecified'
                   CHECK (entity_form IN ('unspecified','sole_proprietor','single_member_llc','multi_member_llc',
                                          'partnership','pllc','professional_corporation','corporation')),
    tax_treatment  text        NOT NULL DEFAULT 'unspecified'
                   CHECK (tax_treatment IN ('unspecified','sole_proprietor','partnership','s_corp','c_corp')),
    default_basis  text        NOT NULL DEFAULT 'accrual' CHECK (default_basis IN ('accrual','cash')),
    currency       text        NOT NULL DEFAULT 'USD' CHECK (currency ~ '^[A-Z]{3}$'),
    locked_through date,                                -- nothing may be dated on or before this day
    created_at     timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, id)
);

-- ── Chart of accounts ───────────────────────────────────────────────────
CREATE TABLE ledger.accounts (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id       bigint      NOT NULL,
    book_id         bigint      NOT NULL,
    code            text        NOT NULL CHECK (code ~ '^[0-9A-Za-z][0-9A-Za-z.-]{0,15}$'),
    name            text        NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
    type            text        NOT NULL CHECK (type IN ('asset','liability','equity','revenue','expense')),
    contra          boolean     NOT NULL DEFAULT false,   -- normal balance opposite its type (discounts, accumulated depreciation)
    statement_line  text        NOT NULL CHECK (length(statement_line) BETWEEN 1 AND 60),
    cash_flow_class text        NOT NULL
                    CHECK (cash_flow_class IN ('cash','restricted_cash','operating','investing','financing','non_cash','none')),
    equity_type     text        CHECK (equity_type IN ('capital','contributions','draws','distributions','retained_earnings',
                                                       'stock','paid_in_capital','opening_balance')),
    owner_party     text        NOT NULL DEFAULT '' CHECK (length(owner_party) <= 120),   -- the partner or member, for capital and draws
    accrual_only    boolean     NOT NULL DEFAULT false,   -- receivables, payables, accruals: absent from the cash view
    control         text        NOT NULL DEFAULT 'none' CHECK (control IN ('none','receivable','payable','trust_liability')),
    system_key      text        CHECK (system_key ~ '^[a-z0-9_.:-]{1,80}$'),   -- stable name a pack posts to
    active          boolean     NOT NULL DEFAULT true,     -- posting allowed; never hides a balance from reports
    created_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (book_id, id),
    UNIQUE (book_id, code),
    UNIQUE (book_id, system_key),
    FOREIGN KEY (tenant_id, book_id) REFERENCES ledger.books (tenant_id, id) ON DELETE CASCADE,
    CHECK (equity_type IS NULL OR type = 'equity'),
    CHECK (control = 'none' OR (control = 'receivable' AND type = 'asset') OR (control IN ('payable','trust_liability') AND type = 'liability'))
);

-- ── Journal ─────────────────────────────────────────────────────────────
CREATE TABLE ledger.entries (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id    bigint      NOT NULL,
    book_id      bigint      NOT NULL,
    entry_on     date        NOT NULL,
    basis        text        NOT NULL DEFAULT 'both' CHECK (basis IN ('both','accrual','cash')),
    source_key   text        CHECK (length(source_key) BETWEEN 1 AND 200),   -- idempotency: one entry per source event
    event_type   text        NOT NULL DEFAULT 'manual' CHECK (event_type ~ '^[a-z0-9_.]{1,60}$'),
    rule         text        NOT NULL DEFAULT '' CHECK (length(rule) <= 80),  -- the posting rule that wrote it
    rule_version int         NOT NULL DEFAULT 0,
    memo         text        NOT NULL DEFAULT '' CHECK (length(memo) <= 500),
    reverses_id  bigint,                                  -- this entry reverses that one
    original_on  date,                                    -- the date asked for, when a locked period moved it later
    actor        text        NOT NULL DEFAULT '' CHECK (length(actor) <= 120),
    created_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (book_id, id),
    UNIQUE (book_id, source_key),
    FOREIGN KEY (tenant_id, book_id) REFERENCES ledger.books (tenant_id, id) ON DELETE CASCADE,
    FOREIGN KEY (book_id, reverses_id) REFERENCES ledger.entries (book_id, id)
);
CREATE UNIQUE INDEX entries_one_reversal ON ledger.entries (reverses_id) WHERE reverses_id IS NOT NULL;
CREATE INDEX entries_book_date ON ledger.entries (book_id, entry_on);

CREATE TABLE ledger.lines (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id        bigint      NOT NULL,
    book_id          bigint      NOT NULL,
    entry_id         bigint      NOT NULL,
    account_id       bigint      NOT NULL,
    debit_cents      bigint      NOT NULL DEFAULT 0 CHECK (debit_cents >= 0),
    credit_cents     bigint      NOT NULL DEFAULT 0 CHECK (credit_cents >= 0),
    party            text        NOT NULL DEFAULT '' CHECK (length(party) <= 120),
    document_line_id bigint,
    memo             text        NOT NULL DEFAULT '' CHECK (length(memo) <= 300),
    CHECK ((debit_cents > 0 AND credit_cents = 0) OR (credit_cents > 0 AND debit_cents = 0)),
    FOREIGN KEY (tenant_id, book_id) REFERENCES ledger.books (tenant_id, id) ON DELETE CASCADE,
    FOREIGN KEY (book_id, entry_id) REFERENCES ledger.entries (book_id, id) ON DELETE CASCADE,
    FOREIGN KEY (book_id, account_id) REFERENCES ledger.accounts (book_id, id)
);
CREATE INDEX lines_entry ON ledger.lines (entry_id);
CREATE INDEX lines_account ON ledger.lines (account_id);

-- ── Documents: what is owed to or by the business ───────────────────────
CREATE TABLE ledger.documents (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id          bigint      NOT NULL,
    book_id            bigint      NOT NULL,
    kind               text        NOT NULL CHECK (kind IN ('receivable','payable')),
    party              text        NOT NULL CHECK (length(party) BETWEEN 1 AND 120),
    ref                text        NOT NULL DEFAULT '' CHECK (length(ref) <= 80),
    issued_on          date        NOT NULL,
    total_cents        bigint      NOT NULL CHECK (total_cents >= 0),
    control_account_id bigint      NOT NULL,
    source_key         text        NOT NULL CHECK (length(source_key) BETWEEN 1 AND 200),
    issue_entry_id     bigint,
    status             text        NOT NULL DEFAULT 'open' CHECK (status IN ('open','void')),
    void_entry_id      bigint,
    created_at         timestamptz NOT NULL DEFAULT now(),
    UNIQUE (book_id, id),
    UNIQUE (book_id, source_key),
    FOREIGN KEY (tenant_id, book_id) REFERENCES ledger.books (tenant_id, id) ON DELETE CASCADE,
    FOREIGN KEY (book_id, control_account_id) REFERENCES ledger.accounts (book_id, id),
    FOREIGN KEY (book_id, issue_entry_id) REFERENCES ledger.entries (book_id, id),
    FOREIGN KEY (book_id, void_entry_id) REFERENCES ledger.entries (book_id, id)
);

CREATE TABLE ledger.document_lines (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id    bigint      NOT NULL,
    book_id      bigint      NOT NULL,
    document_id  bigint      NOT NULL,
    account_id   bigint      NOT NULL,
    amount_cents bigint      NOT NULL CHECK (amount_cents <> 0),   -- negative: a discount or adjustment
    memo         text        NOT NULL DEFAULT '' CHECK (length(memo) <= 300),
    source_ref   text        NOT NULL DEFAULT '' CHECK (length(source_ref) <= 120),
    created_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (book_id, id),
    FOREIGN KEY (tenant_id, book_id) REFERENCES ledger.books (tenant_id, id) ON DELETE CASCADE,
    FOREIGN KEY (book_id, document_id) REFERENCES ledger.documents (book_id, id) ON DELETE CASCADE,
    FOREIGN KEY (book_id, account_id) REFERENCES ledger.accounts (book_id, id)
);
CREATE INDEX document_lines_document ON ledger.document_lines (document_id);
ALTER TABLE ledger.lines ADD FOREIGN KEY (book_id, document_line_id) REFERENCES ledger.document_lines (book_id, id);

-- A payment against a document, and how the person who recorded it assigned
-- it across the document's lines (the cash view recognises exactly that).
CREATE TABLE ledger.settlements (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id        bigint      NOT NULL,
    book_id          bigint      NOT NULL,
    document_id      bigint      NOT NULL,
    settled_on       date        NOT NULL,
    amount_cents     bigint      NOT NULL CHECK (amount_cents > 0),
    cash_account_id  bigint      NOT NULL,
    source_key       text        NOT NULL CHECK (length(source_key) BETWEEN 1 AND 200),
    accrual_entry_id bigint,
    cash_entry_id    bigint,       -- cash against the assigned lines, or against unapplied receipts until assigned
    assign_entry_id  bigint,       -- unapplied receipts moved onto the lines, once assigned
    reversed_at      timestamptz,
    reverse_reason   text        NOT NULL DEFAULT '' CHECK (length(reverse_reason) <= 300),
    created_at       timestamptz NOT NULL DEFAULT now(),
    UNIQUE (book_id, id),
    UNIQUE (book_id, source_key),
    FOREIGN KEY (tenant_id, book_id) REFERENCES ledger.books (tenant_id, id) ON DELETE CASCADE,
    FOREIGN KEY (book_id, document_id) REFERENCES ledger.documents (book_id, id) ON DELETE CASCADE,
    FOREIGN KEY (book_id, cash_account_id) REFERENCES ledger.accounts (book_id, id),
    FOREIGN KEY (book_id, accrual_entry_id) REFERENCES ledger.entries (book_id, id),
    FOREIGN KEY (book_id, cash_entry_id) REFERENCES ledger.entries (book_id, id),
    FOREIGN KEY (book_id, assign_entry_id) REFERENCES ledger.entries (book_id, id)
);
CREATE INDEX settlements_document ON ledger.settlements (document_id);

CREATE TABLE ledger.allocations (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id        bigint      NOT NULL,
    book_id          bigint      NOT NULL,
    settlement_id    bigint      NOT NULL,
    document_line_id bigint      NOT NULL,
    amount_cents     bigint      NOT NULL CHECK (amount_cents <> 0),
    actor            text        NOT NULL DEFAULT '' CHECK (length(actor) <= 120),
    created_at       timestamptz NOT NULL DEFAULT now(),
    UNIQUE (settlement_id, document_line_id),
    FOREIGN KEY (tenant_id, book_id) REFERENCES ledger.books (tenant_id, id) ON DELETE CASCADE,
    FOREIGN KEY (book_id, settlement_id) REFERENCES ledger.settlements (book_id, id) ON DELETE CASCADE,
    FOREIGN KEY (book_id, document_line_id) REFERENCES ledger.document_lines (book_id, id)
);

-- An unpaid amount the owner decided will not be collected. Accrual only:
-- on the cash view the revenue never counted, so nothing happens there.
CREATE TABLE ledger.write_offs (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id    bigint      NOT NULL,
    book_id      bigint      NOT NULL,
    document_id  bigint      NOT NULL,
    written_on   date        NOT NULL,
    amount_cents bigint      NOT NULL CHECK (amount_cents > 0),
    reason       text        NOT NULL CHECK (length(reason) BETWEEN 3 AND 300),
    requested_by text        NOT NULL DEFAULT '' CHECK (length(requested_by) <= 120),
    approved_by  text        NOT NULL CHECK (length(approved_by) BETWEEN 1 AND 120),
    source_key   text        NOT NULL CHECK (length(source_key) BETWEEN 1 AND 200),
    entry_id     bigint      NOT NULL,
    created_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (book_id, source_key),
    FOREIGN KEY (tenant_id, book_id) REFERENCES ledger.books (tenant_id, id) ON DELETE CASCADE,
    FOREIGN KEY (book_id, document_id) REFERENCES ledger.documents (book_id, id) ON DELETE CASCADE,
    FOREIGN KEY (book_id, entry_id) REFERENCES ledger.entries (book_id, id)
);

-- ── Fill tenant_id from the book ────────────────────────────────────────
CREATE FUNCTION ledger.fill_tenant() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    SELECT tenant_id INTO NEW.tenant_id FROM ledger.books WHERE id = NEW.book_id;
    IF NEW.tenant_id IS NULL THEN
        RAISE EXCEPTION 'no such book';
    END IF;
    RETURN NEW;
END $$;
DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['accounts','entries','lines','documents','document_lines','settlements','allocations','write_offs'] LOOP
        EXECUTE format('CREATE TRIGGER %I BEFORE INSERT ON ledger.%I FOR EACH ROW EXECUTE FUNCTION ledger.fill_tenant()', t || '_a_tenant', t);
    END LOOP;
END $$;

-- ── Guards ──────────────────────────────────────────────────────────────

-- Books: the lock date only moves forward, except for trusted code.
CREATE FUNCTION ledger.guard_books() RETURNS trigger LANGUAGE plpgsql AS $$
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
    RETURN NEW;
END $$;
CREATE TRIGGER books_guard BEFORE UPDATE ON ledger.books FOR EACH ROW EXECUTE FUNCTION ledger.guard_books();

-- Accounts: what an account is never changes once something is posted to it.
CREATE FUNCTION ledger.guard_accounts() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF ledger.is_system() THEN
        RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
    END IF;
    IF TG_OP = 'DELETE' THEN
        IF NOT EXISTS (SELECT 1 FROM ledger.books WHERE id = OLD.book_id) THEN
            RETURN OLD;   -- the books themselves are being removed
        END IF;
        IF EXISTS (SELECT 1 FROM ledger.lines WHERE account_id = OLD.id)
           OR EXISTS (SELECT 1 FROM ledger.document_lines WHERE account_id = OLD.id) THEN
            RAISE EXCEPTION 'an account with postings is deactivated, never deleted';
        END IF;
        RETURN OLD;
    END IF;
    IF NEW.book_id <> OLD.book_id OR NEW.tenant_id <> OLD.tenant_id OR NEW.system_key IS DISTINCT FROM OLD.system_key THEN
        RAISE EXCEPTION 'an account stays in its book under its key';
    END IF;
    IF (NEW.type, NEW.contra, NEW.control, NEW.accrual_only, NEW.equity_type, NEW.owner_party)
       IS DISTINCT FROM (OLD.type, OLD.contra, OLD.control, OLD.accrual_only, OLD.equity_type, OLD.owner_party)
       AND (EXISTS (SELECT 1 FROM ledger.lines WHERE account_id = OLD.id)
            OR EXISTS (SELECT 1 FROM ledger.document_lines WHERE account_id = OLD.id)) THEN
        RAISE EXCEPTION 'an account with postings keeps its type and behaviour; open a new account instead';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER accounts_guard BEFORE UPDATE OR DELETE ON ledger.accounts FOR EACH ROW EXECUTE FUNCTION ledger.guard_accounts();

-- Entries: dated after the lock, stamped now, never changed or removed.
CREATE FUNCTION ledger.guard_entries() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE locked date; rev record;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF ledger.is_system() OR NOT EXISTS (SELECT 1 FROM ledger.books WHERE id = OLD.book_id) THEN
            RETURN OLD;
        END IF;
        RAISE EXCEPTION 'journal entries are never removed; post a reversing entry';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        RAISE EXCEPTION 'journal entries are never changed; post a reversing entry';
    END IF;
    NEW.created_at := now();   -- the lines of an entry are written in the transaction that writes it
    SELECT locked_through INTO locked FROM ledger.books WHERE id = NEW.book_id FOR SHARE;
    IF locked IS NOT NULL AND NEW.entry_on <= locked THEN
        RAISE EXCEPTION 'the books are locked through %; nothing can be dated %', locked, NEW.entry_on;
    END IF;
    IF NEW.reverses_id IS NOT NULL THEN
        SELECT basis, reverses_id INTO rev FROM ledger.entries WHERE id = NEW.reverses_id AND book_id = NEW.book_id;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'the entry being reversed is not in these books';
        END IF;
        IF rev.basis <> NEW.basis THEN
            RAISE EXCEPTION 'a reversal keeps the basis of the entry it reverses';
        END IF;
        IF rev.reverses_id IS NOT NULL THEN
            RAISE EXCEPTION 'a reversal is not itself reversed; post a new entry';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER entries_guard BEFORE INSERT OR UPDATE OR DELETE ON ledger.entries FOR EACH ROW EXECUTE FUNCTION ledger.guard_entries();

-- Lines: only into an entry written in this transaction, only to an active
-- account, a control account only with a party; never changed or removed.
CREATE FUNCTION ledger.guard_lines() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE e record; a record; dl record;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF ledger.is_system() OR NOT EXISTS (SELECT 1 FROM ledger.entries WHERE id = OLD.entry_id) THEN
            RETURN OLD;
        END IF;
        RAISE EXCEPTION 'journal lines are never removed; post a reversing entry';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        RAISE EXCEPTION 'journal lines are never changed; post a reversing entry';
    END IF;
    SELECT created_at INTO e FROM ledger.entries WHERE id = NEW.entry_id AND book_id = NEW.book_id;
    IF NOT FOUND OR e.created_at <> now() THEN
        RAISE EXCEPTION 'lines are added only with their entry, in the same transaction';
    END IF;
    SELECT active, control INTO a FROM ledger.accounts WHERE id = NEW.account_id AND book_id = NEW.book_id;
    IF NOT FOUND OR NOT a.active THEN
        RAISE EXCEPTION 'that account is closed to posting';
    END IF;
    IF a.control <> 'none' AND NEW.party = '' THEN
        RAISE EXCEPTION 'a % account needs a party on every line', a.control;
    END IF;
    IF NEW.document_line_id IS NOT NULL THEN
        SELECT account_id INTO dl FROM ledger.document_lines WHERE id = NEW.document_line_id AND book_id = NEW.book_id;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'that document line is not in these books';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER lines_guard BEFORE INSERT OR UPDATE OR DELETE ON ledger.lines FOR EACH ROW EXECUTE FUNCTION ledger.guard_lines();

-- Balance, checked when the transaction commits: every entry written in it
-- has at least two lines and its debits equal its credits.
CREATE FUNCTION ledger.check_balanced() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE eid bigint; n int; d bigint; c bigint;
BEGIN
    IF TG_TABLE_NAME = 'entries' THEN
        eid := NEW.id;
    ELSE
        eid := NEW.entry_id;
    END IF;
    SELECT COUNT(*), coalesce(SUM(debit_cents), 0), coalesce(SUM(credit_cents), 0) INTO n, d, c
      FROM ledger.lines WHERE entry_id = eid;
    IF NOT EXISTS (SELECT 1 FROM ledger.entries WHERE id = eid) THEN
        RETURN NULL;   -- removed with its books in the same transaction
    END IF;
    IF n < 2 THEN
        RAISE EXCEPTION 'journal entry % has % line(s); an entry needs at least two', eid, n;
    END IF;
    IF d <> c THEN
        RAISE EXCEPTION 'journal entry % does not balance: debits % cents, credits % cents', eid, d, c;
    END IF;
    RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER entries_balanced AFTER INSERT ON ledger.entries
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger.check_balanced();
CREATE CONSTRAINT TRIGGER lines_balanced AFTER INSERT ON ledger.lines
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger.check_balanced();

-- Documents and their lines keep what they were written with. A document
-- changes only from open to void, and only once nothing is settled on it.
CREATE FUNCTION ledger.guard_documents() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF ledger.is_system() OR NOT EXISTS (SELECT 1 FROM ledger.books WHERE id = OLD.book_id) THEN
            RETURN OLD;
        END IF;
        RAISE EXCEPTION 'documents are voided, never deleted';
    END IF;
    IF TG_OP = 'INSERT' THEN
        NEW.created_at := now();
        IF NOT EXISTS (SELECT 1 FROM ledger.accounts WHERE id = NEW.control_account_id AND book_id = NEW.book_id
                       AND control = CASE WHEN NEW.kind = 'receivable' THEN 'receivable' ELSE 'payable' END) THEN
            RAISE EXCEPTION 'a % document posts to a % control account', NEW.kind, NEW.kind;
        END IF;
        RETURN NEW;
    END IF;
    IF ledger.is_system() THEN
        RETURN NEW;
    END IF;
    IF (to_jsonb(NEW) - 'issue_entry_id' - 'status' - 'void_entry_id') <> (to_jsonb(OLD) - 'issue_entry_id' - 'status' - 'void_entry_id')
       OR (OLD.issue_entry_id IS NOT NULL AND NEW.issue_entry_id IS DISTINCT FROM OLD.issue_entry_id)
       OR (OLD.void_entry_id IS NOT NULL AND NEW.void_entry_id IS DISTINCT FROM OLD.void_entry_id) THEN
        RAISE EXCEPTION 'a document keeps what it was written with';
    END IF;
    IF OLD.status = 'void' AND NEW.status <> 'void' THEN
        RAISE EXCEPTION 'a void document stays void';
    END IF;
    IF NEW.status = 'void' AND OLD.status = 'open' THEN
        IF EXISTS (SELECT 1 FROM ledger.settlements WHERE document_id = OLD.id AND reversed_at IS NULL)
           OR EXISTS (SELECT 1 FROM ledger.write_offs WHERE document_id = OLD.id) THEN
            RAISE EXCEPTION 'a document with payments or write-offs on it is not voided; reverse them first';
        END IF;
        IF NEW.void_entry_id IS NULL AND OLD.issue_entry_id IS NOT NULL THEN
            RAISE EXCEPTION 'a void needs its reversing entry';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER documents_guard BEFORE INSERT OR UPDATE OR DELETE ON ledger.documents FOR EACH ROW EXECUTE FUNCTION ledger.guard_documents();

-- Rows that are written once with their parent and never changed.
CREATE FUNCTION ledger.guard_written_once() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent_created timestamptz;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF ledger.is_system() OR NOT EXISTS (SELECT 1 FROM ledger.books WHERE id = OLD.book_id) THEN
            RETURN OLD;
        END IF;
        RAISE EXCEPTION '% rows are never removed', TG_TABLE_NAME;
    END IF;
    IF TG_OP = 'UPDATE' THEN
        RAISE EXCEPTION '% rows are never changed', TG_TABLE_NAME;
    END IF;
    IF TG_TABLE_NAME = 'document_lines' THEN
        SELECT created_at INTO parent_created FROM ledger.documents WHERE id = NEW.document_id AND book_id = NEW.book_id;
        IF parent_created IS DISTINCT FROM now() THEN
            RAISE EXCEPTION 'a document''s lines are written with the document, in the same transaction';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER document_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON ledger.document_lines FOR EACH ROW EXECUTE FUNCTION ledger.guard_written_once();
CREATE TRIGGER allocations_guard BEFORE UPDATE OR DELETE ON ledger.allocations FOR EACH ROW EXECUTE FUNCTION ledger.guard_written_once();
CREATE TRIGGER write_offs_guard BEFORE UPDATE OR DELETE ON ledger.write_offs FOR EACH ROW EXECUTE FUNCTION ledger.guard_written_once();

-- Settlements: the figures never change. The entry links fill in once each,
-- and a reversal is recorded once.
CREATE FUNCTION ledger.guard_settlements() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF ledger.is_system() OR NOT EXISTS (SELECT 1 FROM ledger.documents WHERE id = OLD.document_id) THEN
            RETURN OLD;
        END IF;
        RAISE EXCEPTION 'a payment is reversed, never deleted';
    END IF;
    IF ledger.is_system() THEN
        RETURN NEW;
    END IF;
    IF (to_jsonb(NEW) - 'accrual_entry_id' - 'cash_entry_id' - 'assign_entry_id' - 'reversed_at' - 'reverse_reason')
       <> (to_jsonb(OLD) - 'accrual_entry_id' - 'cash_entry_id' - 'assign_entry_id' - 'reversed_at' - 'reverse_reason')
       OR (OLD.accrual_entry_id IS NOT NULL AND NEW.accrual_entry_id IS DISTINCT FROM OLD.accrual_entry_id)
       OR (OLD.cash_entry_id IS NOT NULL AND NEW.cash_entry_id IS DISTINCT FROM OLD.cash_entry_id)
       OR (OLD.assign_entry_id IS NOT NULL AND NEW.assign_entry_id IS DISTINCT FROM OLD.assign_entry_id)
       OR (OLD.reversed_at IS NOT NULL AND (NEW.reversed_at IS DISTINCT FROM OLD.reversed_at OR NEW.reverse_reason <> OLD.reverse_reason)) THEN
        RAISE EXCEPTION 'a payment keeps what it was recorded with';
    END IF;
    IF OLD.reversed_at IS NULL AND NEW.reversed_at IS NOT NULL AND length(NEW.reverse_reason) < 3 THEN
        RAISE EXCEPTION 'a payment is reversed with a reason';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER settlements_guard BEFORE UPDATE OR DELETE ON ledger.settlements FOR EACH ROW EXECUTE FUNCTION ledger.guard_settlements();

-- A settlement's allocations, once any exist, add up to its amount, each
-- to a line of the same document, never past what the line still owes.
CREATE FUNCTION ledger.check_allocations() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s record; total bigint; bad bigint;
BEGIN
    SELECT * INTO s FROM ledger.settlements WHERE id = NEW.settlement_id;
    IF NOT FOUND THEN
        RETURN NULL;
    END IF;
    SELECT coalesce(SUM(amount_cents), 0) INTO total FROM ledger.allocations WHERE settlement_id = s.id;
    IF total <> s.amount_cents THEN
        RAISE EXCEPTION 'payment % is assigned % cents of its % cents', s.id, total, s.amount_cents;
    END IF;
    SELECT a.id INTO bad FROM ledger.allocations a JOIN ledger.document_lines dl ON dl.id = a.document_line_id
     WHERE a.settlement_id = s.id AND dl.document_id <> s.document_id LIMIT 1;
    IF bad IS NOT NULL THEN
        RAISE EXCEPTION 'a payment is assigned only to lines of its own document';
    END IF;
    -- Each line: what all live payments assign to it stays between zero and the line's amount.
    SELECT dl.id INTO bad
      FROM ledger.document_lines dl
      JOIN ledger.allocations a ON a.document_line_id = dl.id
      JOIN ledger.settlements st ON st.id = a.settlement_id AND st.reversed_at IS NULL
     WHERE dl.document_id = s.document_id
     GROUP BY dl.id, dl.amount_cents
    HAVING (dl.amount_cents > 0 AND (SUM(a.amount_cents) > dl.amount_cents OR SUM(a.amount_cents) < 0))
        OR (dl.amount_cents < 0 AND (SUM(a.amount_cents) < dl.amount_cents OR SUM(a.amount_cents) > 0))
     LIMIT 1;
    IF bad IS NOT NULL THEN
        RAISE EXCEPTION 'that assignment is more than document line % still owes', bad;
    END IF;
    RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER allocations_add_up AFTER INSERT ON ledger.allocations
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger.check_allocations();

-- ── Row-level security ──────────────────────────────────────────────────
DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['books','accounts','entries','lines','documents','document_lines','settlements','allocations','write_offs'] LOOP
        EXECUTE format('ALTER TABLE ledger.%I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format('ALTER TABLE ledger.%I FORCE ROW LEVEL SECURITY', t);
        EXECUTE format('CREATE POLICY tenant ON ledger.%I USING (ledger.tenant_ok(tenant_id)) WITH CHECK (ledger.tenant_ok(tenant_id))', t);
    END LOOP;
END $$;

-- ── What the application role may do ────────────────────────────────────
-- Add and read; change only the few columns the guards allow; never delete.
GRANT USAGE ON SCHEMA ledger TO {{APP_ROLE}};
GRANT SELECT, INSERT ON ALL TABLES IN SCHEMA ledger TO {{APP_ROLE}};
GRANT UPDATE (name, entity_form, tax_treatment, default_basis, locked_through) ON ledger.books TO {{APP_ROLE}};
GRANT UPDATE (code, name, statement_line, cash_flow_class, active) ON ledger.accounts TO {{APP_ROLE}};
GRANT UPDATE (issue_entry_id, status, void_entry_id) ON ledger.documents TO {{APP_ROLE}};
GRANT UPDATE (accrual_entry_id, cash_entry_id, assign_entry_id, reversed_at, reverse_reason) ON ledger.settlements TO {{APP_ROLE}};
GRANT USAGE ON ALL SEQUENCES IN SCHEMA ledger TO {{APP_ROLE}};
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA ledger TO {{APP_ROLE}};
