# Support Triage Example

```php
AgentGraph::define(
    StateGraph::make('support_triage')
        ->state([
            'input' => 'string',
            'category' => 'string|null',
            'answer' => 'string|null',
        ])
        ->node('classify', ClassifyTicket::class)
        ->node('answer', AgentNode::make('answer')
            ->agent(SupportAgent::class)
            ->prompt(fn (array $state) => $state['input'])
            ->writeTextTo('answer'))
        ->edge(StateGraph::START, 'classify')
        ->edge('classify', 'answer')
        ->edge('answer', StateGraph::END)
);
```
