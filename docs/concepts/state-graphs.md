# State Graphs

State graphs are deterministic workflows made of nodes, edges, conditional routing, and named state channels.

```php
StateGraph::make('support_triage')
    ->state(['input' => 'string', 'answer' => 'string|null'])
    ->node('answer', AnswerNode::class)
    ->edge(StateGraph::START, 'answer')
    ->edge('answer', StateGraph::END);
```

Nodes implement `Heiner\AgentGraph\Contracts\Node` and return `NodeResult`. A result can write state, route to another node, interrupt, complete, or fail.

Reducers define how writes merge into state. The MVP supports last-write-wins, append, merge, add-messages, max-confidence, and custom reducers. This keeps state compatible with future parallel execution without implementing parallel fan-out in the first release.
