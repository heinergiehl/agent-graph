# SDK stabilization verification — 2026-09-12

Scope: AgentGraph SDK, based on remote 0.16.3 (`82b0b1bcd8390eea77325ba19a42098f29387c69`). The initial local checkout was clean 0.15.1; implementation started on `codex/sdk-stabilization-0.17` from the remote baseline. No plugin files or production data were changed.

Environment: Windows, PHP 8.4.4, Laravel 13.31.0, Laravel AI 0.11.2. SDK library lock remains ignored. Database suites use isolated SQLite and PostgreSQL 17/pgvector; process tests use separate PHP workers with barriers and synthetic effects. No external model calls.

## Evidence

- Local release candidate: 405 passed / 2,869 assertions; the six external cases were then executed explicitly against PostgreSQL 17 and all passed / 49 assertions. Strict Composer validation, Pint, PHPStan and production dependency audit passed. A fresh Laravel 13 sandbox passed installation, all published migrations, doctor, and a database-backed wait/resume/receipt smoke.
- The original cancellation/stale-owner characterization failed in three cases before revision fencing and passed afterward.
- The shared receipt path preserves the existing retry, resume, approval, delay, Send, queue and recovery contracts. New tests cover cancellation at start/retry, stale resume context, memory rollback, declared parallel waits, shared deadlines and parent cancellation.
- PostgreSQL process tests cover cancellation while a worker blocks, expired-lease replacement, killed sync workers with partial fan-out receipts, and simultaneous resume acceptance. They create randomized schemas and stop only their own processes.
- Migration tests preserve a waiting legacy row, verify repeated publication, preserve revision on rollback, and reject stale transitions for both stores.
- Diagnostic listener failures are isolated. Existing fault-injection tests at persistence/dispatch boundaries still test interrupted commits and recovery; business receipt failures are not swallowed.

## Reproduction

```bash
composer validate --strict
composer test
composer test:types
composer test:lint
composer audit --no-dev
```

Enable the PostgreSQL tests with `AGENT_GRAPH_POSTGRES_CONCURRENCY=1` and `AGENT_GRAPH_POSTGRES_HOST`, `PORT`, `DATABASE`, `USERNAME`, `PASSWORD` against a disposable database. PostgreSQL claim-token migration and pgvector tests have their separate opt-in variables in the test files. The CI PostgreSQL job supplies all three sets and executes real processes.

## Requirements disposition

R00, R06, R07, R08, R11 and the SDK portion of R12 are addressed through the changed runtime and its verification. R04/R05 are reinforced by admission and durable receipts; external-effect ambiguity and inner Laravel AI tool authorization remain the existing application's capability-gateway contract. R01–R03, chatbot context/answer quality, SSE reload behavior and product acceptance belong to the subsequent plugin refactor. R09 generic public tool authorization is deferred: these helpers are trusted host surfaces and are not used as the plugin's authorization gateway. No public-endpoint safety claim is made. R10 is preserved by keeping Laravel AI and product policy out of the graph execution core.

The final release gates are a complete SDK suite, static analysis, formatting, dependency audit, four PHP/Laravel CI combinations, PostgreSQL process tests, a fresh Laravel package-install smoke and a Packagist installation without a custom repository. Results are recorded in the release handoff when these gates complete.
