# Sandbox Validation

Date: 2026-05-26

Sandbox app: `C:\Users\Heiner\Documents\agent-graph-sandbox`

Package path repository: `C:\Users\Heiner\Documents\agent-graph`

## Environment

- Laravel app: `laravel/laravel` v13.7.0
- Laravel framework: v13.11.2
- AgentGraph package: local path repository, symlinked from `../agent-graph`
- Laravel AI: v0.7.0
- Database: SQLite for the original install smoke; Docker Postgres/pgvector for worker-backed queue validation
- Queue/cache for installed app: database
- Docker validation target: `filament-agentic-chatbot-pgvector`, database `filament_agentic_chatbot`, port `55436`

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

# Worker-backed queued superstep validation used the same sandbox app with:
# DB_CONNECTION=pgsql, DB_HOST=127.0.0.1, DB_PORT=55436,
# DB_DATABASE=filament_agentic_chatbot, DB_USERNAME=postgres,
# DB_PASSWORD=postgres, QUEUE_CONNECTION=database,
# AGENT_GRAPH_EXECUTION_MODE=queued_supersteps,
# AGENT_GRAPH_EXECUTION_QUEUE=agent-graph-smoke
php artisan vendor:publish --tag=agent-graph-config --force --no-interaction
php artisan vendor:publish --tag=agent-graph-migrations --force --no-interaction
php artisan migrate --no-interaction
php artisan test --filter=AgentGraphWorkerQueueSmokeTest
```

## Results

`agent-graph:install` published config and migrations successfully.

`php artisan migrate` ran `2026_05_25_000000_create_agent_graph_tables` successfully.

The Docker Postgres validation ran the additive hardening migrations and then passed the worker smoke tests:

```text
2 tests passed, 16 assertions
```

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
- Worker-backed `queued_supersteps` dispatch `NodeExecutionJob` / `ContinueSuperstepJob` records, run through `php artisan queue:work database --queue=agent-graph-smoke --stop-when-empty`, complete fan-out/fan-in, and resume an interrupt to completion.

## Notes

Because the package is not tagged yet, the path repository install used `heiner/agent-graph:*@dev`. After publishing `0.12.0`, consumers should require the released beta constraint instead.
