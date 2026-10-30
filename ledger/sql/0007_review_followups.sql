-- Follow-ups from the 30 Oct 2026 review.
--
-- An account marked accrual-only (receivables, payables, accruals, prepaids)
-- takes lines only from accrual-basis entries: on the cash view it must
-- always be absent. A hand entry to accrued liabilities "on both bases"
-- would otherwise put an accrual on the cash balance sheet.
CREATE OR REPLACE FUNCTION ledger.guard_accrual_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM ledger.accounts a WHERE a.id = NEW.account_id AND a.accrual_only)
       AND (SELECT basis FROM ledger.entries WHERE id = NEW.entry_id) <> 'accrual' THEN
        RAISE EXCEPTION 'that account is kept on the accrual basis only; post the entry as accrual-only';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER lines_accrual_only BEFORE INSERT ON ledger.lines FOR EACH ROW EXECUTE FUNCTION ledger.guard_accrual_only();
GRANT EXECUTE ON FUNCTION ledger.guard_accrual_only() TO {{APP_ROLE}};
