# Testing

Set `agent-graph.store` to `memory` for fast unit and feature tests. In-memory stores are not production-safe.

Useful assertions:

- final run status and state
- checkpoint count per run
- write records per run
- pending interrupt payload
- idempotent task result reuse
- scoped memory lookup
- lifecycle event dispatches

The package test suite uses Pest and Orchestra Testbench.
