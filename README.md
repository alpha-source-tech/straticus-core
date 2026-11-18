# STRATICUS core

The shared engine behind every STRATICUS product: LITICUS (legal), STRATICUS BOS (small and mid-sized businesses), and the editions to come.

| Folder | What it is |
|---|---|
| `ledger/` | The accounting core: one double-entry journal per set of books, read on an accrual or cash basis; invoices and bills with line-by-line payment assignment; write-offs; loans; fixed assets; bank reconciliation; financial statements, tie-outs and period close; forecast; financial analysis. See `ledger/README.md`. |
| `agent/` | The AI agent core the products use to read and propose work on the books. |

The core knows nothing about its host. A host (LITICUS's `web/app/books.php`, STRATICUS BOS's service layer) gives each tenant a set of books, binds tenancy in the database, and posts its business events through its own pack of posting rules.

## Tests

```sh
# a PostgreSQL 16+ with an owner role and an app role
LEDGER_TEST_DSN="pgsql:host=127.0.0.1;dbname=ledger_core_test" php ledger/tests/run.php
php agent/tests/run.php
```

## History

Built as the STRATICUS core inside the LITICUS repository (`core/`) and split out with its full history on 18 Nov 2026, when STRATICUS BOS became the second product to use it. LITICUS takes updates from here.
