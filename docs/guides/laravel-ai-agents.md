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

When `stream()` is enabled, Laravel AI remains the source of token/model streaming. AgentGraph iterates the returned `StreamableAgentResponse` exactly as a graph node concern, dispatches the existing `GraphStreamDelta` event for `TextDelta` payloads, and records stream traces.

If a run uses `onEvent()` or `collectEvents()`, those same text deltas are also exposed as normalized `stream.delta` `RunEvent` objects. This is useful for workflow observers and admin UIs, but it is not an SSE helper, Vercel protocol adapter, or replacement for Laravel AI streaming.

Use `onTextDelta()` when a graph node should forward streamed text directly to an application transport:

```php
AgentNode::make('answer')
    ->agent(App\Ai\SupportAgent::class)
    ->prompt(fn (array $state) => $state['input'])
    ->stream()
    ->onTextDelta(function (string $delta, array $payload, NodeContext $context): void {
        // Forward $delta to a websocket, UI event bus, or chat transport.
    })
    ->writeTextTo('answer');
```
