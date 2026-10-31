-- Loans: debt with a schedule. Opening one posts the principal (to cash, or
-- against the asset it financed); each payment is split into principal
-- (balance sheet) and interest (profit and loss) from the schedule; and the
-- part of the balance due within twelve months is kept in a current-portion
-- account so the balance sheet reads short-term and long-term correctly.

CREATE TABLE ledger.loans (
    id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id            bigint      NOT NULL,
    book_id              bigint      NOT NULL,
    name                 text        NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
    lender               text        NOT NULL DEFAULT '' CHECK (length(lender) <= 120),
    principal_cents      bigint      NOT NULL CHECK (principal_cents > 0),
    apr_bp               int         NOT NULL DEFAULT 0 CHECK (apr_bp BETWEEN 0 AND 10000),   -- annual rate in basis points (650 = 6.50%)
    payment_cents        bigint      NOT NULL CHECK (payment_cents > 0),
    payments_n           int         NOT NULL CHECK (payments_n BETWEEN 1 AND 600),
    first_payment_on     date        NOT NULL,
    opened_on            date        NOT NULL,
    liability_account_id bigint      NOT NULL,     -- long-term debt
    current_account_id   bigint      NOT NULL,     -- current portion of long-term debt
    interest_account_id  bigint      NOT NULL,     -- interest expense
    source_key           text        NOT NULL CHECK (length(source_key) BETWEEN 1 AND 200),
    closed_at            timestamptz,
    UNIQUE (book_id, source_key),
    FOREIGN KEY (tenant_id, book_id) REFERENCES ledger.books (tenant_id, id) ON DELETE CASCADE
);
CREATE TRIGGER loans_a_tenant BEFORE INSERT ON ledger.loans FOR EACH ROW EXECUTE FUNCTION ledger.fill_tenant();
ALTER TABLE ledger.loans ENABLE ROW LEVEL SECURITY;
ALTER TABLE ledger.loans FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON ledger.loans USING (ledger.tenant_ok(tenant_id)) WITH CHECK (ledger.tenant_ok(tenant_id));
GRANT SELECT, INSERT ON ledger.loans TO {{APP_ROLE}};
GRANT UPDATE (closed_at) ON ledger.loans TO {{APP_ROLE}};
GRANT USAGE ON ALL SEQUENCES IN SCHEMA ledger TO {{APP_ROLE}};
