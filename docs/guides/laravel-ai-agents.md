# Laravel AI Agents

Use `AgentNode` to run any Laravel AI SDK agent inside a graph.

```php
AgentNode::make('answer')
    ->agent(App\Ai\SupportAgent::class)
    ->prompt(fn (array $state) => $state['input'])
    ->writeTextTo('answer')
    ->writeUsageTo('usage')
    ->timeout(30);
```

AgentGraph calls only public Laravel AI APIs: `prompt()` and `stream()`. It does not inspect provider gateways or internal parser state.
