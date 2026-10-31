-- Supporting documents are found per journal entry through the settlement and
-- document that entry belongs to, and through bank-line keys by prefix.
CREATE INDEX settlements_accrual_entry ON ledger.settlements (accrual_entry_id) WHERE accrual_entry_id IS NOT NULL;
CREATE INDEX settlements_cash_entry ON ledger.settlements (cash_entry_id) WHERE cash_entry_id IS NOT NULL;
CREATE INDEX settlements_assign_entry ON ledger.settlements (assign_entry_id) WHERE assign_entry_id IS NOT NULL;
CREATE INDEX settlements_source_prefix ON ledger.settlements (book_id, source_key text_pattern_ops);
CREATE INDEX documents_issue_entry ON ledger.documents (issue_entry_id) WHERE issue_entry_id IS NOT NULL;
CREATE INDEX documents_void_entry ON ledger.documents (void_entry_id) WHERE void_entry_id IS NOT NULL;
CREATE INDEX entries_source_prefix ON ledger.entries (book_id, source_key text_pattern_ops);
