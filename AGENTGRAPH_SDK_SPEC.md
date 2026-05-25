# AgentGraph SDK Specification

> Durable agent graph runtime for Laravel AI SDK agents.

**Status:** Draft handoff specification  
**Date:** 2026-05-25  
**Target package:** `heiner/agent-graph`  
**Public product name:** AgentGraph  
**Positioning:** A generic, Laravel-native runtime library / SDK for durable agent workflows, designed to complement the official `laravel/ai` package without forking it.

---

## New Chat Handoff Prompt

Use this prompt when starting a fresh implementation chat in this folder:

```text
We are building AgentGraph, a generic Composer package in C:\Users\Heiner\Documents\agent-graph.

AgentGraph is a Laravel-native durable agent graph runtime that complements the official laravel/ai package. It must not fork laravel/ai and must avoid depending on laravel/ai internal gateway classes. It should integrate through public contracts such as Laravel\Ai\Contracts\Agent, Laravel\Ai\Contracts\Tool, prompt(), stream(), HasTools, and HasStructuredOutput.

AgentGraph should be explicitly modeled after LangGraph's runtime concepts and execution logic, adapted idiomatically for Laravel and PHP. Study LangGraph concepts such as StateGraph, threads, checkpoints, checkpoint writes, interrupts, commands, reducers, subgraphs, durable execution, time travel, human-in-the-loop flows, and memory stores before implementing the package.

Read AGENTGRAPH_SDK_SPEC.md first. Then scaffold the Composer package, tests, contracts, runtime, persistence adapters, Laravel AI adapter, memory store, checkpoint store, interrupts, idempotent tasks, graph execution, and migration-friendly APIs described in the spec.

The package must be generic and reusable across Laravel apps. It must not depend on Filament Agentic Chatbot. Filament Agentic Chatbot will later consume this package and migrate its custom workflow runtime, memory, traces, and resume logic onto AgentGraph.
```

---

## Executive Summary

AgentGraph should be a standalone PHP / Laravel package that provides the missing durable runtime layer around Laravel AI SDK agents.

The official Laravel AI SDK is responsible for:

- model providers
- agent classes
- tools
- structured output
- streaming
- queueing
- provider failover
- embeddings and vector stores
- conversation storage where explicitly enabled

AgentGraph should be responsible for:

- graph execution
- durable checkpoints
- resumable agent workflows
- human-in-the-loop interrupts
- idempotent side-effect tasks
- long-term memory
- state history and time travel
- subgraphs
- multi-agent orchestration
- trace events
- storage adapters
- Laravel-native integration points

The goal is not to replace Laravel AI SDK. The goal is to make Laravel AI SDK agents usable inside production-grade, LangGraph-inspired workflows.

---

## LangGraph-Inspired Runtime Requirements

AgentGraph should use LangGraph as the primary architectural reference for runtime concepts and execution semantics.

This does not mean copying Python APIs directly. It means implementing the same core ideas in a Laravel-native way using Composer packages, service container bindings, Laravel queues, database migrations, Eloquent-friendly stores, Laravel events, and PHP contracts.

The implementation must not be a line-by-line port of LangGraph. Python-specific abstractions, naming conventions, async patterns, and package structure should be translated only when they make sense in Laravel and PHP. Prefer Laravel conventions over mimicry. The goal is conceptual compatibility with LangGraph's mental model, not source-level or API-level cloning.

Required LangGraph-style concepts:

- **StateGraph:** graphs are state machines composed of nodes, edges, conditional routing, start markers, and end markers.
- **Threads:** every durable run belongs to a stable application-provided thread ID, such as a chat session, support ticket, tenant workflow, user session, or business record.
- **Runs:** a run is an execution attempt of a graph for a thread, with clear lifecycle states.
- **Checkpoints:** the runtime persists graph state after steps so execution can resume after interrupts, failures, queue boundaries, or process restarts.
- **Checkpoint writes:** state changes should be represented as writes, not only as opaque final snapshots, so the runtime can show diffs, replay, fork, and debug.
- **Reducers:** state channels need deterministic merge behavior, especially for future parallel execution.
- **Interrupts:** nodes can intentionally pause execution for user input, approval, external webhook response, scheduled delay, or manual state correction.
- **Commands:** nodes should be able to return explicit control instructions such as update state, go to node, interrupt, end, retry, or fail.
- **Durable execution:** the runtime must be safe across crashes, queue retries, HTTP request boundaries, and deploys.
- **Time travel:** the runtime should support restoring, replaying, and later forking from historical checkpoints.
- **Subgraphs:** graphs can call other graphs with isolated, shared, or mapped state.
- **Human-in-the-loop:** approvals and manual corrections should be first-class runtime primitives, not product-specific hacks.
- **Memory store:** long-term memory is separate from checkpoints and can be scoped, searched, updated, expired, and optionally embedded.
- **Streaming events:** graph execution should emit structured runtime events, not just final text.

Required Laravel adaptations:

- Use database-backed stores as the production source of truth.
- Use Laravel cache / Redis only for optional locks, queues, rate limits, and short-lived acceleration.
- Use Laravel queues for delayed and background continuation.
- Use Laravel events for observability and UI integrations.
- Use Laravel migrations and configurable table names.
- Use PHP contracts for stores, nodes, tasks, interrupts, and adapters.
- Keep the package independent from Filament, chat widgets, and any specific application models.

The resulting package should feel like “LangGraph for Laravel AI SDK agents”, while remaining legally and architecturally independent from LangGraph and Laravel's first-party packages.

Implementation quality requirement:

- Build this as a public developer SDK, not as application glue code.
- Public APIs must be documented, stable, and intentionally designed.
- Examples should teach developers how to build useful agent workflows without knowing the original Filament Agentic Chatbot codebase.
- Internal complexity should be hidden behind clear contracts and fluent APIs.
- The code should be idiomatic Laravel / PHP, not translated Python.

---

## Naming and Trademark Guidance

Do not name the package `Laravel AI Graph` as the primary product name.

Reasons:

- It may imply official Laravel ownership or endorsement.
- Laravel's trademark policy restricts commercial use of names containing Laravel, especially when used for software products.
- A product name that starts with `Laravel` is more likely to be considered confusing.

Recommended naming:

- Product name: `AgentGraph`
- Composer package: `heiner/agent-graph`
- PHP namespace: `Heiner\AgentGraph`
- Tagline: `Durable agent graph runtime for Laravel AI SDK`
- README title: `AgentGraph for Laravel AI SDK`

This keeps the brand independent while clearly explaining the integration target.

---

## Package Type

Formal Composer type:

```json
{
  "type": "library"
}
```

Public positioning:

- Call it a **package** in Composer / Packagist contexts.
- Call it a **library** when referring to reusable code.
- Call it an **SDK** when describing the public developer API and integration surface.
- Avoid calling it a framework unless it grows into a complete end-to-end application framework.

Recommended wording:

> AgentGraph is a Laravel package and runtime SDK for durable AI agent graphs.

---

## Core Design Principle

AgentGraph must be generic.

It must not know about:

- Filament
- chat widgets
- RAG bot models
- `RagConversation`
- `RagBot`
- `WorkflowRun`
- `AgentWorkflow`
- channel integrations
- analytics dashboards
- product-specific billing

Those concerns belong in downstream products such as Filament Agentic Chatbot.

AgentGraph may provide extension points that downstream apps can bind to their own models, tables, policies, queues, events, and UIs.

---

## Relationship to Laravel AI SDK

AgentGraph should depend on `laravel/ai`, but only through stable public APIs.

Allowed integration points:

- `Laravel\Ai\Contracts\Agent`
- `Laravel\Ai\Contracts\Tool`
- `Laravel\Ai\Contracts\HasTools`
- `Laravel\Ai\Contracts\HasStructuredOutput`
- `Laravel\Ai\Responses\AgentResponse`
- `Laravel\Ai\Responses\StreamableAgentResponse`
- `Laravel\Ai\Streaming\Events\TextDelta`
- public `prompt()` method
- public `stream()` method

Avoid:

- internal gateway classes
- provider-specific parser internals
- private traits
- internal response transformation code
- monkey-patching Laravel AI providers
- replacing Laravel AI tool invocation internals

AgentGraph should treat Laravel AI SDK as an execution backend for model and agent calls.

---

## Recommended Development Workspace

The implementation agent may clone reference repositories next to this package, but these repositories must be treated as read-only references.

Actual local layout for this workspace:

```text
C:\Users\Heiner\Documents\
  agent-graph\                 # this package, active implementation repo
    references\                # gitignored read-only upstream references
      laravel-ai\              # read-only clone of https://github.com/laravel/ai
      langgraph\               # read-only clone of https://github.com/langchain-ai/langgraph
```

The `references/` directory is local-only and gitignored. Do not commit it, import code from it, or make package code depend on it.

### Reference Repository Rules

The implementation agent should use the reference repositories for learning, source navigation, and architectural comparison only.

Allowed:

- inspect public contracts and tests in `laravel/ai`
- inspect LangGraph concepts and implementation patterns
- compare checkpoint, interrupt, state, reducer, and memory semantics
- cite commit SHAs in design notes when relying on specific source behavior
- use docs and source to understand runtime behavior

Not allowed:

- copy large blocks of code from LangGraph
- port Python internals line-by-line
- mirror LangGraph APIs mechanically when a Laravel-native API would be clearer
- make AgentGraph depend on local clone paths
- import code from the reference folders
- modify reference repositories as part of AgentGraph implementation
- vendor reference repositories into this package

### Laravel AI SDK Reference

The Laravel AI SDK clone is useful and recommended because AgentGraph must integrate cleanly with the SDK's public contracts.

Use it to inspect:

- `Laravel\Ai\Contracts\Agent`
- `Laravel\Ai\Contracts\Tool`
- `Laravel\Ai\Contracts\HasTools`
- `Laravel\Ai\Contracts\HasStructuredOutput`
- `Promptable`
- `AgentTool`
- streaming response events
- tests around agents, tools, sub-agents, and conversation storage

However, actual integration must happen through the Composer dependency installed in `vendor/`, not through the sibling clone.

### LangGraph Reference

The LangGraph clone is optional but useful when implementing runtime semantics.

Use it to understand:

- StateGraph concepts
- checkpoint stores
- checkpoint writes
- threads
- interrupts
- commands
- reducers
- subgraphs
- durable execution
- time travel
- memory stores
- human-in-the-loop patterns

LangGraph should be the conceptual reference, not a source-code dependency. AgentGraph should translate the concepts into idiomatic Laravel and PHP.

When a LangGraph concept has no natural PHP/Laravel equivalent, design a Laravel-native abstraction that preserves the runtime guarantee instead of copying the original API shape.

### Documentation Is Primary for Semantics

When source code and docs differ, prefer current official documentation for product-level semantics, then inspect source for implementation detail.

Relevant sources:

- Laravel AI SDK repository: `https://github.com/laravel/ai`
- Laravel AI SDK documentation: `https://laravel.com/docs/ai-sdk`
- LangGraph repository: `https://github.com/langchain-ai/langgraph`
- LangGraph documentation: `https://docs.langchain.com/oss/python/langgraph/overview`

### Pin Reference State

If implementation decisions depend on specific upstream behavior, create a short note in:

```text
docs/reference-sources.md
```

Include:

- repository URL
- checked commit SHA
- date inspected
- files or documentation pages used
- decision influenced by that reference

This keeps future updates auditable when Laravel AI SDK or LangGraph changes.

---

## Compatibility Goals

AgentGraph must be resilient to Laravel AI SDK updates.

Required stability mechanisms:

- a thin Laravel AI adapter layer
- contract tests for adapter behavior
- version matrix in CI
- semantic version constraints
- no direct dependency on non-public Laravel AI internals
- feature detection for optional Laravel AI capabilities
- graceful degradation when a Laravel AI feature is unavailable

Suggested Composer constraint at launch:

```json
{
  "require": {
    "php": "^8.3",
    "illuminate/contracts": "^12.0 || ^13.0",
    "illuminate/support": "^12.0 || ^13.0",
    "illuminate/database": "^12.0 || ^13.0",
    "illuminate/queue": "^12.0 || ^13.0",
    "laravel/ai": "^0.6 || ^1.0"
  }
}
```

If `laravel/ai` remains pre-1.0, keep the constraint conservative and test every supported minor version.

---

## Feature Goals

AgentGraph should implement the durable agent runtime features that Laravel AI SDK does not try to solve.

Required MVP features:

- graph definition API
- node and edge execution
- typed graph state
- checkpoint persistence
- resumable runs
- interrupts
- idempotent tasks
- Laravel AI agent node
- Laravel AI tool adapter
- memory store
- trace events
- database storage adapter
- queue-based delayed resume
- tests and fake drivers

Post-MVP features:

- time travel
- checkpoint forking
- human approval UI hooks
- parallel fan-out / fan-in
- subgraphs with scoped state
- semantic long-term memory using pgvector
- run comparison
- replay with model override
- OpenTelemetry export

---

## Non-Goals

AgentGraph should not:

- implement its own LLM provider clients
- replace Laravel AI SDK provider support
- require Filament
- ship a visual workflow editor
- ship a chat widget
- ship RAG ingestion pipelines
- own product analytics
- own bot management UI
- own channel integrations
- require Redis for correctness
- require pgvector for correctness

Redis and pgvector can be optional adapters or accelerators, but the primary correctness layer should work with a relational database.

---

## Architecture Overview

AgentGraph should have five major layers:

1. **Graph Definition Layer**
   - Builder APIs for nodes, edges, state schemas, reducers, and interrupts.

2. **Runtime Layer**
   - Executes graphs deterministically, resolves edges, applies state writes, handles retries, interrupts, and completion.

3. **Persistence Layer**
   - Stores threads, runs, checkpoints, writes, interrupts, task results, memory items, and traces.

4. **Integration Layer**
   - Adapters for Laravel AI agents/tools, Laravel queues, Laravel events, cache locks, and optional vector search.

5. **Developer Experience Layer**
   - Artisan commands, test fakes, docs, examples, and migration helpers.

---

## Concept Model

### Graph

A graph is a reusable workflow definition.

It contains:

- graph key
- schema version
- nodes
- edges
- state schema
- reducer rules
- interrupt rules
- metadata

Graphs should be definable in PHP and optionally serializable to JSON.

### Thread

A thread is a long-lived conversation or workflow context.

Examples:

- a chat session
- a customer onboarding flow
- a support ticket automation
- a workflow attached to a CRM record

Thread IDs should be application-provided strings.

### Run

A run is one execution attempt of a graph against a thread.

A thread can have many runs. A run can be:

- running
- interrupted
- waiting
- delayed
- completed
- failed
- cancelled

### Checkpoint

A checkpoint is a durable snapshot after a graph step.

It should store:

- checkpoint ID
- parent checkpoint ID
- run ID
- thread ID
- graph key
- graph version
- step number
- state values
- next nodes
- completed nodes
- pending interrupts
- metadata
- creation timestamp

### Write

A write is a state change emitted by a node.

Writes are important because they allow:

- deterministic replay
- state diffs
- reducers
- debugging
- time travel

### Task

A task is a side-effect operation that must be idempotent.

Examples:

- HTTP request
- API connector call
- database write
- email send
- Slack message
- CRM update
- payment action
- file generation

Task results should be stored under a stable idempotency key.

### Interrupt

An interrupt pauses execution until an external actor resumes it.

Examples:

- wait for user input
- wait for admin approval
- wait for scheduled time
- wait for webhook
- wait for manual state correction

### Memory

Memory is durable context that can be read by future runs.

It is not the same as checkpoints.

Memory examples:

- user preference
- customer fact
- workflow summary
- previously selected option
- known integration setting
- reusable tool result

---

## Storage Model

AgentGraph should ship with a database storage adapter.

Recommended tables:

### `agent_graph_runs`

Stores execution runs.

Fields:

- `id`
- `public_id`
- `thread_id`
- `graph_key`
- `graph_version`
- `status`
- `current_checkpoint_id`
- `started_at`
- `finished_at`
- `cancelled_at`
- `failed_at`
- `resume_at`
- `error`
- `meta`
- timestamps

### `agent_graph_checkpoints`

Stores checkpoint snapshots.

Fields:

- `id`
- `checkpoint_id`
- `parent_checkpoint_id`
- `run_id`
- `thread_id`
- `graph_key`
- `graph_version`
- `step`
- `state`
- `next_nodes`
- `completed_nodes`
- `interrupts`
- `meta`
- timestamps

### `agent_graph_writes`

Stores state writes per checkpoint.

Fields:

- `id`
- `checkpoint_id`
- `run_id`
- `node_id`
- `channel`
- `key`
- `value`
- `reducer`
- `meta`
- timestamps

### `agent_graph_tasks`

Stores idempotent side-effect results.

Fields:

- `id`
- `task_key`
- `run_id`
- `checkpoint_id`
- `node_id`
- `status`
- `input_hash`
- `input`
- `result`
- `error`
- `attempts`
- `locked_until`
- timestamps

Unique constraint:

- `task_key`

### `agent_graph_interrupts`

Stores pending external waits.

Fields:

- `id`
- `interrupt_id`
- `run_id`
- `checkpoint_id`
- `node_id`
- `type`
- `status`
- `payload`
- `response`
- `resolved_by`
- `resolved_at`
- timestamps

### `agent_graph_memories`

Stores long-term memory.

Fields:

- `id`
- `scope_type`
- `scope_id`
- `namespace`
- `key`
- `memory_type`
- `value`
- `content`
- `embedding`
- `confidence`
- `source`
- `expires_at`
- `meta`
- timestamps

Unique constraint:

- `scope_type`, `scope_id`, `namespace`, `key`

Embedding should be optional. If the database does not support vectors, exact and full-text lookup should still work.

### `agent_graph_traces`

Stores observability events.

Fields:

- `id`
- `run_id`
- `checkpoint_id`
- `node_id`
- `event_type`
- `status`
- `input`
- `output`
- `state_before`
- `state_after`
- `duration_ms`
- `error`
- `meta`
- timestamps

Trace capture should be configurable to avoid storing sensitive data or oversized payloads.

---

## Storage Adapter Contracts

AgentGraph should define contracts rather than hard-coding Eloquent everywhere.

Required contracts:

- `RunStore`
- `CheckpointStore`
- `TaskStore`
- `InterruptStore`
- `MemoryStore`
- `TraceStore`
- `LockProvider`
- `Clock`

Default implementations:

- `DatabaseRunStore`
- `DatabaseCheckpointStore`
- `DatabaseTaskStore`
- `DatabaseInterruptStore`
- `DatabaseMemoryStore`
- `DatabaseTraceStore`
- `CacheLockProvider`
- `SystemClock`

Testing implementations:

- `InMemoryRunStore`
- `InMemoryCheckpointStore`
- `InMemoryTaskStore`
- `InMemoryInterruptStore`
- `InMemoryMemoryStore`
- `NullTraceStore`

The in-memory stores are for tests and demos only. They must not be documented as production-safe.

---

## Graph Definition API

The package should support an expressive PHP API.

Example:

```php
use Heiner\AgentGraph\Graph\StateGraph;

$graph = StateGraph::make('support_triage')
    ->state([
        'input' => 'string',
        'messages' => 'messages',
        'category' => 'string|null',
        'answer' => 'string|null',
        'sources' => 'array',
    ])
    ->node('classify', ClassifyTicketNode::class)
    ->node('knowledge', KnowledgeSearchNode::class)
    ->node('answer', AnswerAgentNode::class)
    ->edge('__start__', 'classify')
    ->conditional('classify', fn ($state) => $state['category'], [
        'docs' => 'knowledge',
        'default' => 'answer',
    ])
    ->edge('knowledge', 'answer')
    ->edge('answer', '__end__');
```

The API should also allow array / JSON graph definitions for apps that provide visual editors.

---

## Node Contract

Nodes should be small, testable units.

Suggested contract:

```php
namespace Heiner\AgentGraph\Contracts;

use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

interface Node
{
    public function __invoke(NodeContext $context): NodeResult;
}
```

`NodeContext` should expose:

- current state
- run ID
- thread ID
- checkpoint ID
- node ID
- graph metadata
- memory reader
- task runner
- interrupt helper
- logger / trace helper
- event dispatcher

`NodeResult` should support:

- state writes
- next node override
- interrupt
- completion
- failure
- trace metadata

---

## State Model

AgentGraph should avoid a single untyped variables array as the only state model.

It should support:

- named state channels
- reducer rules
- typed values where possible
- schema validation
- state diffs
- state snapshots

Example state channels:

- `input`
- `messages`
- `facts`
- `sources`
- `artifacts`
- `errors`
- `approvals`
- `memory`
- `tool_results`

Reducers:

- last write wins
- append list
- merge associative array
- add messages
- max confidence
- custom reducer callable

This is important for future parallel execution. If two nodes write to the same channel, the runtime must know how to merge the writes.

---

## Checkpoint Semantics

The runtime must checkpoint after every successful node step.

The runtime should also checkpoint:

- before a side-effect task
- after a side-effect task
- before an interrupt
- after an interrupt resume
- when a run fails
- when a run is cancelled

Required checkpoint capabilities:

- get latest checkpoint for thread
- get latest checkpoint for run
- list checkpoint history
- restore checkpoint
- fork from checkpoint
- resume from checkpoint
- replay from checkpoint

MVP can implement latest-checkpoint resume first, then add full time travel.

---

## Interrupts

Interrupts should be first-class runtime primitives.

Interrupt types:

- `input`
- `approval`
- `delay`
- `webhook`
- `manual_review`
- `state_edit`

Example:

```php
return NodeResult::interrupt('approval', [
    'title' => 'Approve CRM update',
    'summary' => 'The agent wants to update the customer plan to Pro.',
    'proposed_action' => $payload,
]);
```

The app should be able to resume:

```php
$runtime->resume($runId, [
    'interrupt_id' => $interruptId,
    'approved' => true,
    'edited_payload' => [...],
]);
```

AgentGraph should not ship a UI, but it should expose enough data for Filament, Nova, custom dashboards, or API consumers to build approval screens.

---

## Idempotent Tasks

Side effects must be safe under retries, crashes, and resumes.

Every side-effect node should run through a task API:

```php
$result = $context->tasks()->once(
    key: "crm-update:{$customerId}:{$runId}:{$nodeId}",
    input: $payload,
    handler: fn () => $crm->updateCustomer($customerId, $payload),
);
```

Guarantees:

- If the same task key already completed, return stored result.
- If the task is running, avoid duplicate execution where possible.
- If the task failed, retry according to policy.
- Store input hash to detect accidental key reuse with different input.
- Store result for deterministic replay.

This is required before the runtime is safe for production side effects.

---

## Memory System

AgentGraph should separate four kinds of memory:

### 1. Working Memory

Current run state.

Stored in:

- RAM during execution
- checkpoints for durability

Used for:

- current variables
- messages in this run
- pending node outputs

### 2. Thread Memory

Conversation or workflow thread history.

Stored in:

- app conversation tables
- AgentGraph checkpoints
- optional summary memory

Used for:

- resolving follow-up messages
- preserving multi-turn context
- resuming after interrupts

### 3. Long-Term Memory

Facts and preferences that survive across runs.

Stored in:

- `agent_graph_memories`
- optional pgvector embedding

Used for:

- user preferences
- customer profile facts
- stable decisions
- repeated context

### 4. Cache Memory

Short-lived accelerators.

Stored in:

- Redis
- Laravel cache

Used for:

- locks
- rate limits
- duplicate suppression
- expensive retrieval cache

Cache memory is not a source of truth.

---

## Memory Scopes

Required scopes:

- `run`
- `thread`
- `actor`
- `tenant`
- `application`
- `global`

Downstream apps should be able to define custom scopes.

Example:

```php
$memory->write(
    scope: MemoryScope::actor($tenantId, $userId),
    namespace: 'preferences',
    key: 'timezone',
    value: 'Europe/Berlin',
    type: 'preference',
);
```

---

## Memory Types

Required memory types:

- `state`
- `fact`
- `preference`
- `summary`
- `tool_result`
- `semantic`
- `instruction`
- `episode`

Memory should include metadata:

- source
- confidence
- extraction method
- created by
- updated by
- expires at
- last used at
- usage count

---

## Memory Writer

AgentGraph should not automatically store every user message as long-term memory.

It should provide a memory writer service that can be used explicitly:

- as a node
- as a post-run hook
- as a queued background job

The memory writer should extract only durable, useful information.

Examples:

- "The user prefers German" -> preference
- "Customer uses Slack" -> fact
- "Selected plan is Pro" -> state / fact
- "Last workflow topic was billing" -> thread state

Sensitive information should not be stored unless explicitly configured.

---

## Memory Reader

The memory reader should support:

- exact key lookup
- namespace lookup
- type filtering
- keyword search
- optional vector similarity
- recency filtering
- scope fallback

Example fallback order:

1. run
2. thread
3. actor
4. tenant
5. application
6. global

The fallback order should be configurable.

---

## Laravel AI Agent Node

AgentGraph must provide a node that can execute any Laravel AI SDK agent.

Example:

```php
AgentNode::make('answer')
    ->agent(SupportAgent::class)
    ->prompt(fn ($state) => $state['input'])
    ->writeTextTo('answer')
    ->stream(true);
```

Requirements:

- supports `prompt()`
- supports `stream()`
- captures text deltas as runtime events
- stores response text
- stores usage metadata when available
- stores tool calls and tool results when available
- supports provider/model override if the agent supports it
- supports timeout
- handles exceptions consistently
- can be faked in tests

Do not inspect Laravel AI internal gateway state.

---

## Laravel AI Tool Adapter

AgentGraph should be able to expose a graph as a Laravel AI SDK tool.

Use case:

- A Laravel AI parent agent calls a graph as a tool.
- The graph can start, resume, interrupt, or cancel a durable workflow.

Example:

```php
final class RunSupportWorkflowTool extends GraphTool
{
    protected string $graph = 'support_triage';
}
```

The tool should return structured JSON:

```json
{
  "status": "interrupted",
  "summary": "I need your order number.",
  "run_id": "run_01...",
  "interrupt": {
    "type": "input",
    "prompt": "What is your order number?"
  }
}
```

This mirrors the current Filament Agentic Chatbot `RunWorkflowTool` pattern, but generic.

---

## Subagents and Subgraphs

Laravel AI SDK already supports sub-agents by wrapping agents as tools.

AgentGraph should complement this with durable orchestration:

- an `AgentNode` can run a Laravel AI agent
- a `SubgraphNode` can run a graph
- a `SupervisorNode` can route work to subagents
- a `ParallelAgentsNode` can fan out to multiple agents
- a subgraph can have isolated or shared state

Subgraph state modes:

- `isolated`: subgraph state does not leak unless mapped
- `shared`: subgraph writes directly to parent state
- `mapped`: explicit input/output mapping

---

## Parallel Execution

Parallel execution can be post-MVP, but state design must support it from the beginning.

Needed primitives:

- fan-out edges
- join nodes
- reducers
- step barriers
- per-node task isolation
- deterministic merge order

Example use cases:

- ask three specialist agents in parallel
- query multiple APIs in parallel
- search knowledge base and CRM in parallel
- run safety check and answer generation in parallel

---

## Trace and Observability Events

AgentGraph should emit Laravel events and optionally store traces.

Required events:

- `GraphRunStarted`
- `GraphRunCompleted`
- `GraphRunFailed`
- `GraphRunCancelled`
- `GraphNodeStarted`
- `GraphNodeCompleted`
- `GraphNodeFailed`
- `GraphCheckpointCreated`
- `GraphInterrupted`
- `GraphResumed`
- `GraphTaskStarted`
- `GraphTaskCompleted`
- `GraphTaskFailed`
- `GraphMemoryRead`
- `GraphMemoryWritten`
- `GraphStreamDelta`

Trace capture should be configurable:

- capture input
- capture output
- capture state before
- capture state after
- capture metadata
- redact keys
- max string length
- max payload size

---

## Security and Privacy

AgentGraph must be safe for commercial apps.

Required:

- configurable trace redaction
- max trace size
- memory TTL
- memory export API
- memory delete API
- run delete API
- interrupt payload redaction
- task result redaction
- support tenant scoping
- no accidental cross-tenant memory retrieval

Do not store raw secrets in traces or memory by default.

Provide configuration for redacted keys:

- `password`
- `token`
- `secret`
- `api_key`
- `authorization`
- `cookie`
- `credit_card`
- `ssn`

---

## Error Handling

The runtime should distinguish:

- node failure
- model failure
- validation failure
- storage failure
- task failure
- interrupt timeout
- cancellation
- stale running run
- max steps exceeded

Every failure should preserve enough information to debug without exposing secrets.

Failures should be resumable only when safe.

---

## Concurrency

AgentGraph should prevent duplicate execution for the same run.

Required:

- lock per run
- lock per thread when starting/resuming
- stale lock recovery
- stale running run detection
- cancellation checks between nodes
- task-level idempotency

Redis can be used for locks through Laravel cache, but database-backed locking should remain possible.

---

## Queueing and Delays

AgentGraph should support:

- immediate execution
- queued execution
- delayed resume
- scheduled resume
- async task execution

Laravel queue integration should be optional but first-class.

Required jobs:

- `RunGraphJob`
- `ResumeGraphJob`
- `ContinueDelayedGraphJob`

Jobs should be idempotent.

---

## Testing Strategy

AgentGraph should be test-first.

Required test categories:

- unit tests for state reducers
- unit tests for edge resolution
- unit tests for checkpoint serialization
- unit tests for memory scopes
- unit tests for idempotent task behavior
- integration tests for database stores
- integration tests for Laravel AI adapter with faked agents
- resume tests
- interrupt tests
- cancellation tests
- stale run tests
- trace redaction tests
- version compatibility tests with supported Laravel AI versions

Testing tools:

- Pest or PHPUnit
- Orchestra Testbench
- SQLite for default database tests
- optional Postgres test suite for pgvector features

---

## Developer Experience

Artisan commands:

- `agent-graph:install`
- `agent-graph:make-node`
- `agent-graph:make-graph`
- `agent-graph:doctor`
- `agent-graph:prune`

Testing helpers:

- fake agent responses
- fake node outputs
- assert graph path
- assert checkpoint count
- assert interrupt created
- assert memory written
- assert task executed once

Example assertion API:

```php
AgentGraph::fake()
    ->forGraph('support_triage')
    ->assertPath(['classify', 'knowledge', 'answer'])
    ->assertInterrupted('approval');
```

---

## Documentation Requirements

AgentGraph should ship as a developer-facing SDK. Documentation is part of the product, not an afterthought.

Required docs:

- `README.md`
  - what AgentGraph is
  - how it relates to Laravel AI SDK
  - installation
  - first graph example
  - first Laravel AI agent node example
  - graph-as-tool example
  - production checklist

- `docs/concepts/state-graphs.md`
  - graphs
  - nodes
  - edges
  - conditional routing
  - start/end nodes
  - Laravel-native differences from LangGraph

- `docs/concepts/checkpoints.md`
  - threads
  - runs
  - checkpoints
  - checkpoint writes
  - resume
  - time travel
  - storage guarantees

- `docs/concepts/interrupts.md`
  - input interrupts
  - approval interrupts
  - delay interrupts
  - webhook/manual interrupts
  - how apps build UI around interrupts

- `docs/concepts/memory.md`
  - working memory
  - thread memory
  - long-term memory
  - cache memory
  - exact lookup
  - semantic lookup
  - privacy and TTL

- `docs/concepts/idempotent-tasks.md`
  - why side effects need task keys
  - retry behavior
  - input hash checks
  - safe HTTP/API/database actions

- `docs/guides/laravel-ai-agents.md`
  - using `Laravel\Ai\Contracts\Agent`
  - using `prompt()`
  - using `stream()`
  - handling tool calls and usage metadata
  - avoiding Laravel AI internals

- `docs/guides/graphs-as-tools.md`
  - exposing graphs as Laravel AI SDK tools
  - parent-agent orchestration
  - structured JSON results
  - resume/cancel behavior

- `docs/guides/testing.md`
  - fake stores
  - fake agents
  - path assertions
  - checkpoint assertions
  - interrupt assertions
  - task idempotency assertions

- `docs/guides/production.md`
  - migrations
  - queues
  - locks
  - pruning
  - trace redaction
  - tenant isolation
  - privacy exports/deletes

- `docs/reference-sources.md`
  - reference repo URLs
  - inspected commits
  - inspected docs
  - decisions influenced by upstream references

Documentation style:

- Use complete examples that can be pasted into a Laravel app.
- Explain runtime guarantees in plain language.
- Explicitly distinguish production-safe stores from in-memory test stores.
- Avoid assuming knowledge of Filament Agentic Chatbot.
- Avoid claiming official affiliation with Laravel or LangGraph.
- Prefer concise examples over abstract theory, but link concepts together.

Every major public API should have:

- short purpose statement
- method signatures
- example usage
- failure behavior
- testing approach

---

## Suggested Package Structure

```text
agent-graph/
  composer.json
  README.md
  config/
    agent-graph.php
  database/
    migrations/
  src/
    AgentGraphServiceProvider.php
    Contracts/
    Graph/
    Runtime/
    State/
    Persistence/
    Memory/
    Tasks/
    Interrupts/
    Tracing/
    LaravelAi/
    Queue/
    Console/
    Testing/
    Support/
  tests/
    Unit/
    Feature/
    Integration/
  docs/
    concepts/
    guides/
    examples/
    reference-sources.md
```

---

## Migration from Filament Agentic Chatbot

The existing plugin should later consume AgentGraph.

Move into AgentGraph:

- workflow runner core
- execution loop
- resume handler
- checkpointing
- state model
- memory service concepts
- interrupt handling
- idempotent task layer
- trace builder concepts
- graph-as-tool adapter
- agent node executor concepts

Keep in Filament Agentic Chatbot:

- Filament resources
- visual editor
- bot model
- RAG source management
- widget
- channels
- analytics
- bot access tokens
- commercial product UX
- domain-specific workflow nodes
- domain-specific prompt generation

The plugin should become an application layer on top of AgentGraph.

---

## Migration Strategy

Avoid a big-bang rewrite.

Recommended phases:

### Phase 1: Extract Contracts and Runtime Skeleton

- create AgentGraph package
- add graph, state, node, run, checkpoint contracts
- add in-memory stores
- add database migrations
- add test suite

### Phase 2: Add Laravel AI Adapter

- implement `AgentNode`
- implement `GraphTool`
- test against fake Laravel AI agents
- avoid provider internals

### Phase 3: Add Durable Execution

- implement run lifecycle
- implement checkpoint store
- implement resume from latest checkpoint
- implement cancellation
- implement max steps

### Phase 4: Add Interrupts and Idempotent Tasks

- input interrupts
- approval interrupts
- delay interrupts
- task store
- task idempotency guarantees

### Phase 5: Add Memory

- memory store
- scopes
- exact lookup
- namespace lookup
- TTL
- optional semantic search

### Phase 6: Integrate Filament Agentic Chatbot

- wrap existing workflows into AgentGraph graphs
- map existing workflow runs to AgentGraph runs
- migrate memory nodes
- migrate trace display
- keep old runtime behind compatibility flag

### Phase 7: Replace Old Runtime

- make AgentGraph runtime default
- retain migration path for existing runs
- remove duplicated runtime code after stability period

---

## Public API Stability Contract

AgentGraph should publish a clear stability policy.

Stable APIs:

- contracts
- graph builder
- runtime facade
- storage adapter interfaces
- node result API
- memory API
- interrupt API
- task API

Internal APIs:

- database model internals
- serialization details
- trace payload internals
- queue job internals

Use semantic versioning.

Avoid breaking public contracts in minor releases.

---

## Example End-State Usage

```php
use Heiner\AgentGraph\Facades\AgentGraph;

$run = AgentGraph::graph('support_triage')
    ->thread($conversationId)
    ->input([
        'message' => $userMessage,
        'actor_id' => $userId,
    ])
    ->run();

if ($run->interrupted()) {
    return [
        'status' => 'waiting',
        'prompt' => $run->interrupt()->prompt(),
    ];
}

return [
    'status' => 'completed',
    'answer' => $run->state('answer'),
];
```

Resume:

```php
AgentGraph::resume($runId, [
    'interrupt_id' => $interruptId,
    'input' => $userAnswer,
]);
```

Expose graph to Laravel AI as a tool:

```php
public function tools(): iterable
{
    return [
        AgentGraph::tool('support_triage')
            ->thread(fn () => session()->getId())
            ->name('run_support_workflow')
            ->description('Run or resume the support workflow.'),
    ];
}
```

---

## Acceptance Criteria for MVP

MVP is complete when:

- a Laravel app can install the package with Composer
- migrations can be published and run
- a graph can be defined in PHP
- a graph can run synchronously
- each node step creates a checkpoint
- a graph can interrupt for user input
- a graph can resume from an interrupt
- a Laravel AI SDK agent can run inside an `AgentNode`
- a graph can be exposed as a Laravel AI SDK tool
- idempotent task execution prevents duplicate side effects
- memory can be read and written by scope
- traces can be emitted and stored
- tests cover the happy path and core failure modes
- no Filament Agentic Chatbot classes are required

---

## Open Decisions

These decisions should be made before implementation:

1. Should the first version use Pest or PHPUnit?
2. Should package namespace be `Heiner\AgentGraph` or a vendor-neutral namespace?
3. Should graph definitions be PHP-only at first, or PHP plus JSON import/export?
4. Should the default database IDs be UUID/ULID strings or auto-increment integers?
5. Should semantic memory ship in v1 or be a separate optional adapter?
6. Should parallel execution be included in v1 or designed for but deferred?

Recommended answers:

1. Use PHPUnit if matching Filament Agentic Chatbot test style matters; otherwise Pest is fine.
2. Use `Heiner\AgentGraph` initially.
3. Support PHP graph definitions first, design JSON serialization for visual editors.
4. Use public ULID/UUID strings for external references and auto-increment internals for database efficiency.
5. Defer semantic memory to an optional adapter.
6. Design reducers now, defer actual parallel execution until after MVP.

---

## Summary

AgentGraph should be the reusable runtime currently missing between Laravel AI SDK and product-specific agent applications.

It should let any Laravel app build durable, resumable, inspectable, memory-aware agent workflows while continuing to benefit from the official Laravel AI SDK for models, tools, providers, streaming, and structured output.

The Filament Agentic Chatbot plugin should later become a consumer of AgentGraph rather than the owner of all runtime logic.
