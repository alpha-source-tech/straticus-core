-- Statements for more than the operating account: savings, a second operating
-- account, a payroll account, and credit cards. A card is a liability, so an
-- account says it keeps statements; a card statement's charges are money out
-- (negative) and its payments money in (positive), as from the card's side.
ALTER TABLE ledger.accounts ADD COLUMN statement_kind text NOT NULL DEFAULT '' CHECK (statement_kind IN ('', 'bank', 'card'));
-- Set when an account is made (ledger_account_ensure); these are the accounts already there.
UPDATE ledger.accounts SET statement_kind = 'bank' WHERE cash_flow_class = 'cash';
UPDATE ledger.accounts SET statement_kind = 'card' WHERE system_key = 'cards';

CREATE OR REPLACE FUNCTION ledger.guard_bank_lines() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE l record; locked date;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF ledger.is_system() OR NOT EXISTS (SELECT 1 FROM ledger.books WHERE id = OLD.book_id) THEN
            RETURN OLD;
        END IF;
        RAISE EXCEPTION 'bank lines are kept as the bank sent them';
    END IF;
    IF TG_OP = 'INSERT' THEN
        IF NOT EXISTS (SELECT 1 FROM ledger.accounts WHERE id = NEW.account_id
                        AND (cash_flow_class IN ('cash','restricted_cash') OR (type = 'liability' AND statement_kind = 'card'))) THEN
            RAISE EXCEPTION 'statements are imported into cash accounts and credit cards only';
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
