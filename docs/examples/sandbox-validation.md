# Sandbox Validation

Date: 2026-05-26

Sandbox app: `C:\Users\Heiner\Documents\agent-graph-sandbox`

Package path repository: `C:\Users\Heiner\Documents\agent-graph`

## Environment

- Laravel app: `laravel/laravel` v13.7.0
- Laravel framework: v13.11.2
- AgentGraph package: local path repository, symlinked from `../agent-graph`
- Laravel AI: v0.7.0
- Database: SQLite
- Queue/cache for installed app: database

## Commands Run

```bash
composer create-project laravel/laravel ../agent-graph-sandbox --no-interaction --no-progress
cd ../agent-graph-sandbox
composer config repositories.agent-graph path ../agent-graph
composer require heiner/agent-graph:*@dev --no-interaction --no-progress
php artisan agent-graph:install --no-interaction
php artisan migrate --no-interaction
php artisan agent-graph:doctor
php artisan test --filter=AgentGraphSandboxTest
```

## Results

`agent-graph:install` published config and migrations successfully.

`php artisan migrate` ran `2026_05_25_000000_create_agent_graph_tables` successfully.

`agent-graph:doctor` reported:

- store driver: `database`
- queue connection: `database`
- cache driver: `database`
- cache locks: `available`
- all AgentGraph tables present

The sandbox Feature test passed:

```text
1 test passed, 10 assertions
```

## Covered Flows

- Fresh Laravel app installs the package through a local path repository.
- Package discovery finds `heiner/agent-graph`.
- Migrations run in a real Laravel app.
- A support graph interrupts for missing input, resumes from the latest checkpoint, calls an `AgentNode` with a fake Laravel AI agent, and completes.
- Scoped memory writes and reads an actor preference.
- A delay interrupt dispatches `ContinueDelayedGraphJob`.
- `GraphTool` starts an interrupted graph and resumes it to completion with stable JSON responses.

## Notes

Because the package is not tagged yet, the path repository install used `heiner/agent-graph:*@dev`. After publishing `0.12.0`, consumers should require the released beta constraint instead.
