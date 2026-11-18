-- Supporting documents: the receipt, contract, invoice or photo behind an
-- entry or a document (an invoice or bill).
--
-- The core keeps what the file is and its SHA-256 fingerprint; the host keeps
-- the bytes (a directory in development, S3 with object lock in production)
-- under the key recorded here. Rules the database enforces:
--   * an attachment belongs to exactly one entry or one document, in its book;
--   * only photos (JPEG, PNG, WebP, HEIC) and PDFs, up to 15 MB;
--   * the same file is attached to the same thing once;
--   * attachments are permanent: never changed, never removed (a wrong one is
--     answered by attaching the right one; the record of both stays);
--   * attaching is allowed in a closed period: a document never changes an amount.

CREATE TABLE ledger.attachments (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id    bigint      NOT NULL,
    book_id      bigint      NOT NULL,
    entry_id     bigint,
    document_id  bigint,
    kind         text        NOT NULL DEFAULT 'other'
                 CHECK (kind IN ('receipt','invoice','bill','contract','statement','photo','other')),
    name         text        NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
    content_type text        NOT NULL CHECK (content_type IN ('image/jpeg','image/png','image/webp','image/heic','application/pdf')),
    size_bytes   bigint      NOT NULL CHECK (size_bytes BETWEEN 1 AND 15728640),
    sha256       text        NOT NULL CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    storage_key  text        NOT NULL CHECK (length(storage_key) BETWEEN 1 AND 300),
    note         text        NOT NULL DEFAULT '' CHECK (length(note) <= 300),
    actor        text        NOT NULL DEFAULT '' CHECK (length(actor) <= 120),
    created_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (book_id, id),
    CHECK (num_nonnulls(entry_id, document_id) = 1),
    FOREIGN KEY (tenant_id, book_id) REFERENCES ledger.books (tenant_id, id) ON DELETE CASCADE,
    FOREIGN KEY (book_id, entry_id) REFERENCES ledger.entries (book_id, id) ON DELETE CASCADE,
    FOREIGN KEY (book_id, document_id) REFERENCES ledger.documents (book_id, id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX attachments_once_entry ON ledger.attachments (entry_id, sha256) WHERE entry_id IS NOT NULL;
CREATE UNIQUE INDEX attachments_once_document ON ledger.attachments (document_id, sha256) WHERE document_id IS NOT NULL;
CREATE INDEX attachments_book ON ledger.attachments (book_id, created_at);

CREATE TRIGGER attachments_a_tenant BEFORE INSERT ON ledger.attachments FOR EACH ROW EXECUTE FUNCTION ledger.fill_tenant();

CREATE FUNCTION ledger.guard_attachments() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        -- Books being removed take their attachments with them (the cascade); nothing else does.
        IF ledger.is_system() OR NOT EXISTS (SELECT 1 FROM ledger.books WHERE id = OLD.book_id) THEN
            RETURN OLD;
        END IF;
        RAISE EXCEPTION 'attachments are kept; attach the right document alongside a wrong one';
    END IF;
    RAISE EXCEPTION 'attachments are kept as they were attached';
END $$;
CREATE TRIGGER attachments_guard BEFORE UPDATE OR DELETE ON ledger.attachments FOR EACH ROW EXECUTE FUNCTION ledger.guard_attachments();

ALTER TABLE ledger.attachments ENABLE ROW LEVEL SECURITY;
ALTER TABLE ledger.attachments FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON ledger.attachments USING (ledger.tenant_ok(tenant_id)) WITH CHECK (ledger.tenant_ok(tenant_id));
GRANT SELECT, INSERT ON ledger.attachments TO {{APP_ROLE}};
GRANT USAGE ON ALL SEQUENCES IN SCHEMA ledger TO {{APP_ROLE}};
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA ledger TO {{APP_ROLE}};
