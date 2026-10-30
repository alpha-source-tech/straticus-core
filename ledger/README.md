# STRATICUS accounting core (`ledger`)

One double-entry journal per set of books, shared by every STRATICUS
product. LITICUS is the first host; it lives in this repository until a
second product needs it, then moves to its own package unchanged.

The core knows nothing about its host: no firms, matters or users. A host
gives each tenant a set of books, binds tenancy in the database, and posts
its business events through a pack of posting rules (LITICUS's legal pack
is `web/app/books.php`).

## What is here

| File | What it is |
|---|---|
| `sql/` | The `ledger` schema. Applied by `ledger_migrate()`, which a host calls before its own migrations. |
| `ledger.php` | The engine: books, accounts, posting, documents, payments and their assignment, write-offs, reversals, trial balance, fingerprint. |
| `templates.php` | The core chart of accounts and the equity section for each tax treatment. |
| `tests/core_tests.php` | Tests that use only the core's API. |
| `tests/run.php` | Runs them on their own, with no host: `php core/ledger/tests/run.php`. |

## Rules the database enforces

- Every entry balances and has at least two lines (checked at commit).
- Posted entries and lines are never changed or removed; corrections are
  reversing entries, and a reversal is never itself reversed.
- Nothing is dated on or before a book's locked date. The engine moves a
  late posting to the first open day and keeps the date asked for.
- Lines post only to active accounts of the same book; control accounts
  (receivables, payables, client trust) only with a party.
- Documents, their lines, payments and assignments keep the figures they
  were written with. A payment's assignment adds up to its amount and never
  takes a line past what it owes.
- Row-level security keeps tenants apart.

## Two bases from one journal

Every entry is marked `both`, `accrual` or `cash`. A report on a basis reads
that basis plus `both`, and each basis balances on its own.

- Issuing an invoice or bill posts an **accrual** entry.
- A payment posts an **accrual** entry (cash against the control account)
  and a **cash** entry (cash against the document lines it was assigned to).
- A payment that clears a document is assigned to every line's remainder
  automatically. A part payment waits in *unapplied receipts* on the cash
  view until a person assigns it, line by line.
- A write-off is **accrual** only: on the cash view the revenue never counted.
- Everything with no receivable or payable in it (trust money, transfers,
  owner draws, expenses paid) is **both**.

## Binding a host

A host replaces two functions in its own migration:

```sql
CREATE OR REPLACE FUNCTION ledger.is_system() ...      -- trusted code
CREATE OR REPLACE FUNCTION ledger.tenant_ok(t bigint) ... -- may this scope see tenant t?
```

The defaults read the `ledger.system` and `ledger.tenant_id` settings, which
is what the standalone tests use. LITICUS binds them to its own scope
functions (`db/migrations/0034_books.sql`).

Posting is idempotent on a source key, so a host can derive entries from its
own records and re-run the derivation at any time.
