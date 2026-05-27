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
composer audit --no-dev
```

For release work, also validate the package in a fresh Laravel sandbox app. Before Packagist registration, use the public GitHub repository as a Composer VCS repository and require the tagged beta constraint. After Packagist registration, repeat the same smoke test without a custom repository entry.

`composer.lock` stays ignored because this repository is a library; run security audits against the local resolved dependency set before tagging.

## Packagist Updates

Packagist can be updated through its GitHub hook or through this repository's `Update Packagist` GitHub Actions workflow.

For the GitHub Actions path, add these repository secrets:

- `PACKAGIST_USERNAME`: the Packagist account name that maintains the package.
- `PACKAGIST_API_TOKEN`: the API token from the Packagist profile page.

The workflow runs on pushes to `main`, version tags, and manual dispatch. If the secrets are missing, it skips with a notice so normal CI does not fail.
