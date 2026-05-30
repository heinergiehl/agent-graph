# State Graphs

State graphs are deterministic workflows made of nodes, edges, conditional routing, and named state channels.

```php
StateGraph::make('support_triage')
    ->state(['input' => 'string', 'answer' => 'string|null'])
    ->node('answer', AnswerNode::class)
    ->edge(StateGraph::START, 'answer')
    ->edge('answer', StateGraph::END);
```

Nodes implement `Heiner\AgentGraph\Contracts\Node` and return `NodeResult`. A result can write state, route to another node, dynamically `Send` work to one or more nodes, interrupt, complete, or fail.

Dynamic `goto` targets must reference known graph endpoints. Dynamic `Send` targets must reference executable graph nodes; use `NodeResult::end()` to complete rather than sending work to `StateGraph::END`.

Reducers define how writes merge into state. Static multi-edges, conditional fan-out, and dynamic `Send` results run as deterministic supersteps: every node in the same frontier reads the same base state and writes are merged after the frontier finishes.

Multiple edges from `StateGraph::START` are valid. They schedule all entry nodes in the first superstep. Each entry node reads the same initial input state, and concurrent writes to the same channel require an explicit reducer.

If two nodes in one superstep write the same channel, that channel must define an explicit reducer such as `append`, `merge`, `messages`/`add_messages`, `max`/`max_confidence`, or a custom reducer. Unknown reducer strings throw during graph compilation instead of falling back to last-write-wins. `Send` input is local to the target node and is not persisted unless the node writes it.

Per-node retry policies handle transient thrown exceptions without changing graph topology:

```php
StateGraph::make('support_triage')
    ->node('call_api', CallApiNode::class)
    ->retry('call_api', maxAttempts: 3, delayMs: 100, backoff: 2.0);
```

Retries do not apply to `NodeResult::fail()`, interrupts, delays, or schema-validation failures. If a retried node performs external side effects, protect them with `$context->tasks()->once()`.

Nodes can attach generic metadata for timeline and inspector UIs:

```php
return NodeResult::write(['answer' => $answer])
    ->withNodeMeta([
        'label' => 'Draft answer',
        'type' => 'agent',
        'category' => 'support',
    ]);
```

Stable `meta.node` keys are `id`, `label`, `type`, `status`, `category`, `source`, and `description`.

## Child Run Lineage

Applications can record delegated or nested run relationships without enabling full subgraph orchestration:

```php
$child = AgentGraph::graph('support_triage')
    ->input(['input' => $delegatedRequest])
    ->parent($parentRunId, $parentCheckpointId, 'delegate', relationship: 'tool')
    ->run();
```

The runtime stores this lineage under `run.meta.parent` with `run_id`, `checkpoint_id`, `node_id`, `depth`, and `relationship`. Inspectors can read it through `RunSnapshot::parent()` and list children with `AgentGraph::childRuns($parentRunId)`.

This is metadata only. It does not schedule child graphs, propagate cancellation, or define subgraph state isolation.
