# AgentGraph for Laravel AI SDK

AgentGraph is a Laravel package and runtime SDK for durable AI agent graphs. It complements the official `laravel/ai` package with graph orchestration, checkpoints, resumable runs, interrupts, scoped memory, idempotent tasks, traces, queues, and graph-as-tool integration.

AgentGraph does not replace Laravel AI providers, agents, tools, streaming, or structured output. It uses Laravel AI through public contracts such as `Laravel\Ai\Contracts\Agent` and `Laravel\Ai\Contracts\Tool`.

## Beta Status

`0.9.x` is a public beta intended for sandbox and real chatbot integration testing. Breaking changes are allowed before v1, but they will be documented in `CHANGELOG.md` and `UPGRADE.md`.

The v1 target is a hardened MVP: stable graph execution, checkpoints, interrupts/resume, idempotent tasks, scoped memory, traces, queues, Laravel AI agent nodes, and graphs as tools. True parallel execution, checkpoint forking, full time travel, pgvector semantic memory, OpenTelemetry export, and visual workflow editing are intentionally outside v1.

## Installation

```bash
composer require heiner/agent-graph
php artisan agent-graph:install
php artisan migrate
```

## First Graph

```php
use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

final class ClassifyTicket implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['category' => 'billing']);
    }
}

AgentGraph::define(
    StateGraph::make('support_triage')
        ->state([
            'input' => 'string',
            'category' => 'string|null',
            'answer' => 'string|null',
        ])
        ->node('classify', ClassifyTicket::class)
        ->edge(StateGraph::START, 'classify')
        ->edge('classify', StateGraph::END)
);

$run = AgentGraph::graph('support_triage')
    ->thread($conversationId)
    ->input(['input' => $message])
    ->run();
```

## Interrupts

Nodes can pause execution for user input, approval, delay, webhook, manual review, or state edit.

```php
return NodeResult::interrupt('approval', [
    'title' => 'Approve CRM update',
    'summary' => 'The agent wants to update the customer plan.',
]);
```

Resume later:

```php
$run = AgentGraph::resume($runId, [
    'interrupt_id' => $interruptId,
    'approved' => true,
]);
```

## Laravel AI Agent Node

```php
use Heiner\AgentGraph\LaravelAi\AgentNode;

AgentNode::make('answer')
    ->agent(App\Ai\SupportAgent::class)
    ->prompt(fn (array $state) => $state['input'])
    ->writeTextTo('answer')
    ->writeUsageTo('usage');
```

## Graphs as Tools

```php
public function tools(): iterable
{
    return [
        AgentGraph::tool('support_triage')
            ->name('run_support_triage')
            ->description('Run or resume the durable support workflow.')
            ->thread(fn ($request) => $request['thread_id']),
    ];
}
```

The tool returns JSON with `status`, `run_id`, `thread_id`, `state`, `interrupt`, and `error`.

Tool responses always include:

```json
{
  "status": "completed",
  "run_id": "run_...",
  "thread_id": "thread-123",
  "state": {},
  "interrupt": null,
  "error": null
}
```

Interrupted runs return a machine-readable `interrupt` payload. Failed runs return `status: "failed"` and an `error` object.

## Stable v1 Public APIs

The intended v1-stable API surface is:

- `StateGraph` for fluent graph definitions.
- `Node` and `NodeContext` for runtime node implementation.
- `NodeResult` for writes, gotos, interrupts, completion, and failures.
- `AgentGraph` facade for defining, running, resuming, cancelling, and exposing tools.
- `AgentNode` for Laravel AI agent execution.
- `GraphTool` for Laravel AI tool integration.
- Store contracts for production adapters and tests.

## Production Checklist

- Run and monitor the published migrations.
- Use database stores as the source of truth.
- Configure queue workers for background and delayed graph continuation.
- Keep trace redaction keys current for your domain.
- Scope memory by tenant or actor before using it in multi-tenant apps.
- Use idempotent task keys for every external side effect.
- Keep graph definitions generic; product-specific UI belongs in consuming apps.
- For multi-tenant memory, always include tenant or actor scope in reads and writes.
- Run `php artisan agent-graph:doctor` after deploys and before release validation.

## Status

This MVP includes the durable runtime core, database and in-memory stores, scoped memory, interrupts, tasks, traces, queue jobs, Laravel AI adapter, graph tool adapter, commands, tests, and docs. Post-MVP work includes true parallel fan-out/fan-in, checkpoint forking, full time travel, pgvector semantic memory, OpenTelemetry export, and visual editor serialization.
