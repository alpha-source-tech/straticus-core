-- How a partnership (or a multi-member LLC taxed as one) splits its
-- earnings: each partner's share, in basis points (10000 = 100%), from a
-- date. A new set of shares is new rows with a later date; old rows stay as
-- the record of what applied before.
CREATE TABLE ledger.profit_shares (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id      bigint      NOT NULL,
    book_id        bigint      NOT NULL,
    owner_party    text        NOT NULL CHECK (length(owner_party) BETWEEN 1 AND 120),
    share_bp       int         NOT NULL CHECK (share_bp BETWEEN 0 AND 10000),
    effective_from date        NOT NULL,
    actor          text        NOT NULL DEFAULT '' CHECK (length(actor) <= 120),
    created_at     timestamptz NOT NULL DEFAULT now(),
    UNIQUE (book_id, effective_from, owner_party),
    FOREIGN KEY (tenant_id, book_id) REFERENCES ledger.books (tenant_id, id) ON DELETE CASCADE
);
CREATE TRIGGER profit_shares_a_tenant BEFORE INSERT ON ledger.profit_shares FOR EACH ROW EXECUTE FUNCTION ledger.fill_tenant();
CREATE TRIGGER profit_shares_guard BEFORE UPDATE OR DELETE ON ledger.profit_shares FOR EACH ROW EXECUTE FUNCTION ledger.guard_written_once();
ALTER TABLE ledger.profit_shares ENABLE ROW LEVEL SECURITY;
ALTER TABLE ledger.profit_shares FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON ledger.profit_shares USING (ledger.tenant_ok(tenant_id)) WITH CHECK (ledger.tenant_ok(tenant_id));
GRANT SELECT, INSERT ON ledger.profit_shares TO {{APP_ROLE}};
GRANT USAGE ON ALL SEQUENCES IN SCHEMA ledger TO {{APP_ROLE}};
