-- When a document is due, so a cash forecast can place what it brings in or
-- sends out, and how late each party usually pays can be measured.
ALTER TABLE ledger.documents ADD COLUMN due_on date;
CREATE INDEX documents_open_due ON ledger.documents (book_id, status, due_on);
