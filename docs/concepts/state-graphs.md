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

Reducers define how writes merge into state. Static multi-edges, conditional fan-out, and dynamic `Send` results run as deterministic supersteps: every node in the same frontier reads the same base state and writes are merged after the frontier finishes.

If two nodes in one superstep write the same channel, that channel must define an explicit reducer such as `append`, `merge`, `messages`/`add_messages`, `max`/`max_confidence`, or a custom reducer. `Send` input is local to the target node and is not persisted unless the node writes it.

Per-node retry policies handle transient thrown exceptions without changing graph topology:

```php
StateGraph::make('support_triage')
    ->node('call_api', CallApiNode::class)
    ->retry('call_api', maxAttempts: 3, delayMs: 100, backoff: 2.0);
```

Retries do not apply to `NodeResult::fail()`, interrupts, delays, or schema-validation failures. If a retried node performs external side effects, protect them with `$context->tasks()->once()`.
