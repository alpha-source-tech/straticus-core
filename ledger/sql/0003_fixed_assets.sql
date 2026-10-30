-- Fixed assets: one depreciation schedule per asset.
--
-- Depreciation is computed month by month from the asset's current
-- schedule and what has already been posted for it: each month takes the
-- remaining book value above salvage spread over the months left. So a
-- change of life, salvage or method applies going forward on its own (a
-- change in estimate): nothing already posted moves, and locked periods are
-- never restated. Every change is its own row, with who, why and the
-- schedule before and after.
--
-- A purchase is capitalised when its cost is at or above the book's
-- capitalisation threshold (by default $2,500 per item, the common
-- de minimis safe harbor without audited financials); below it, it is
-- usually expensed. The host asks; the person decides.

ALTER TABLE ledger.books ADD COLUMN capitalization_threshold_cents bigint NOT NULL DEFAULT 250000 CHECK (capitalization_threshold_cents >= 0);
GRANT UPDATE (capitalization_threshold_cents) ON ledger.books TO {{APP_ROLE}};

CREATE TABLE ledger.assets (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id           bigint      NOT NULL,
    book_id             bigint      NOT NULL,
    name                text        NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
    asset_account_id    bigint      NOT NULL,
    accum_account_id    bigint      NOT NULL,
    expense_account_id  bigint      NOT NULL,
    cost_cents          bigint      NOT NULL CHECK (cost_cents > 0),
    salvage_cents       bigint      NOT NULL DEFAULT 0 CHECK (salvage_cents >= 0),
    in_service_on       date        NOT NULL,
    method              text        NOT NULL DEFAULT 'straight_line' CHECK (method IN ('straight_line','declining_balance')),
    life_months         int         NOT NULL CHECK (life_months BETWEEN 1 AND 600),
    db_factor           numeric(4,2) NOT NULL DEFAULT 2.00 CHECK (db_factor > 0 AND db_factor <= 3),   -- 2.00 = double declining
    tag                 text        NOT NULL DEFAULT '' CHECK (length(tag) <= 120),   -- the matter, department or location it belongs to
    source_key          text        NOT NULL CHECK (length(source_key) BETWEEN 1 AND 200),
    disposed_on         date,
    disposal_entry_id   bigint,
    created_at          timestamptz NOT NULL DEFAULT now(),
    UNIQUE (book_id, id),
    UNIQUE (book_id, source_key),
    CHECK (salvage_cents < cost_cents),
    FOREIGN KEY (tenant_id, book_id) REFERENCES ledger.books (tenant_id, id) ON DELETE CASCADE,
    FOREIGN KEY (book_id, asset_account_id) REFERENCES ledger.accounts (book_id, id),
    FOREIGN KEY (book_id, accum_account_id) REFERENCES ledger.accounts (book_id, id),
    FOREIGN KEY (book_id, expense_account_id) REFERENCES ledger.accounts (book_id, id),
    FOREIGN KEY (book_id, disposal_entry_id) REFERENCES ledger.entries (book_id, id)
);

CREATE TABLE ledger.asset_changes (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id   bigint      NOT NULL,
    book_id     bigint      NOT NULL,
    asset_id    bigint      NOT NULL,
    effective_month date    NOT NULL CHECK (extract(day FROM effective_month) = 1),
    before      jsonb       NOT NULL,
    after       jsonb       NOT NULL,
    reason      text        NOT NULL CHECK (length(reason) BETWEEN 3 AND 500),
    actor       text        NOT NULL CHECK (length(actor) BETWEEN 1 AND 120),
    created_at  timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (tenant_id, book_id) REFERENCES ledger.books (tenant_id, id) ON DELETE CASCADE,
    FOREIGN KEY (book_id, asset_id) REFERENCES ledger.assets (book_id, id) ON DELETE CASCADE
);
CREATE INDEX asset_changes_asset ON ledger.asset_changes (asset_id, id);

CREATE FUNCTION ledger.stamp_now() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    NEW.created_at := now();
    RETURN NEW;
END $$;

CREATE TRIGGER assets_a_tenant BEFORE INSERT ON ledger.assets FOR EACH ROW EXECUTE FUNCTION ledger.fill_tenant();
CREATE TRIGGER asset_changes_a_tenant BEFORE INSERT ON ledger.asset_changes FOR EACH ROW EXECUTE FUNCTION ledger.fill_tenant();

-- An asset's identity and cost never change; its schedule changes only with
-- a change row written in the same transaction; a disposal is once.
CREATE FUNCTION ledger.guard_assets() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF ledger.is_system() OR NOT EXISTS (SELECT 1 FROM ledger.books WHERE id = OLD.book_id) THEN
            RETURN OLD;
        END IF;
        RAISE EXCEPTION 'an asset is disposed of, never deleted';
    END IF;
    IF TG_OP = 'INSERT' THEN
        NEW.created_at := now();
        IF NOT EXISTS (SELECT 1 FROM ledger.accounts WHERE id = NEW.asset_account_id AND type = 'asset' AND NOT contra)
           OR NOT EXISTS (SELECT 1 FROM ledger.accounts WHERE id = NEW.accum_account_id AND type = 'asset' AND contra)
           OR NOT EXISTS (SELECT 1 FROM ledger.accounts WHERE id = NEW.expense_account_id AND type = 'expense') THEN
            RAISE EXCEPTION 'an asset needs an asset account, a contra accumulated depreciation account and an expense account';
        END IF;
        RETURN NEW;
    END IF;
    IF ledger.is_system() THEN
        RETURN NEW;
    END IF;
    IF (NEW.book_id, NEW.tenant_id, NEW.name, NEW.asset_account_id, NEW.accum_account_id, NEW.expense_account_id, NEW.cost_cents, NEW.in_service_on, NEW.source_key)
       IS DISTINCT FROM (OLD.book_id, OLD.tenant_id, OLD.name, OLD.asset_account_id, OLD.accum_account_id, OLD.expense_account_id, OLD.cost_cents, OLD.in_service_on, OLD.source_key) THEN
        RAISE EXCEPTION 'an asset keeps its name, accounts, cost and in-service date';
    END IF;
    IF OLD.disposed_on IS NOT NULL THEN
        RAISE EXCEPTION 'a disposed asset is closed';
    END IF;
    IF (NEW.method, NEW.life_months, NEW.salvage_cents, NEW.db_factor, NEW.tag) IS DISTINCT FROM (OLD.method, OLD.life_months, OLD.salvage_cents, OLD.db_factor, OLD.tag)
       AND NOT EXISTS (SELECT 1 FROM ledger.asset_changes WHERE asset_id = OLD.id AND created_at = now()) THEN
        RAISE EXCEPTION 'a schedule changes only with a recorded change: who, why, before and after';
    END IF;
    IF NEW.disposed_on IS NOT NULL AND NEW.disposal_entry_id IS NULL THEN
        RAISE EXCEPTION 'a disposal needs its entry';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER assets_guard BEFORE INSERT OR UPDATE OR DELETE ON ledger.assets FOR EACH ROW EXECUTE FUNCTION ledger.guard_assets();
CREATE TRIGGER asset_changes_guard BEFORE UPDATE OR DELETE ON ledger.asset_changes FOR EACH ROW EXECUTE FUNCTION ledger.guard_written_once();
CREATE TRIGGER asset_changes_stamp BEFORE INSERT ON ledger.asset_changes FOR EACH ROW EXECUTE FUNCTION ledger.stamp_now();

DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['assets','asset_changes'] LOOP
        EXECUTE format('ALTER TABLE ledger.%I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format('ALTER TABLE ledger.%I FORCE ROW LEVEL SECURITY', t);
        EXECUTE format('CREATE POLICY tenant ON ledger.%I USING (ledger.tenant_ok(tenant_id)) WITH CHECK (ledger.tenant_ok(tenant_id))', t);
    END LOOP;
END $$;
GRANT SELECT, INSERT ON ledger.assets, ledger.asset_changes TO {{APP_ROLE}};
GRANT UPDATE (method, life_months, salvage_cents, db_factor, tag, disposed_on, disposal_entry_id) ON ledger.assets TO {{APP_ROLE}};
GRANT USAGE ON ALL SEQUENCES IN SCHEMA ledger TO {{APP_ROLE}};
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA ledger TO {{APP_ROLE}};
