# Contributing

AgentGraph is a Laravel package. Keep changes generic to the SDK; consuming apps such as chatbots should not leak into package internals.

## Local Setup

```bash
composer install
composer check
```

## Development Rules

- Add or update tests before changing runtime behavior.
- Keep `references/` read-only and out of package code.
- Use public Laravel AI contracts only.
- Keep database persistence as the durable source of truth.
- Do not add Filament-specific dependencies or assumptions.

## Quality Gate

Run before opening a PR:

```bash
composer validate --strict
composer check
```

For release work, also validate the package in a fresh Laravel sandbox app via a local path repository.
