# Security Policy

## Supported Versions

| Version | Status |
| --- | --- |
| 0.13.x | Public beta security fixes |
| 1.x | Supported after v1 release |

## Reporting

Report security issues privately to the package maintainer before public disclosure.

Do not open public issues for:

- Cross-tenant memory exposure.
- Trace redaction bypasses.
- Duplicate side effects from idempotent task races.
- Unsafe resume or interrupt payload handling.

## Defaults

AgentGraph redacts common sensitive keys in trace payloads by default. Applications should extend `agent-graph.tracing.redact_keys` with domain-specific secrets and always scope memory by tenant or actor in multi-tenant apps.
