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

Laravel AI native tool approval can suspend a provider response before the agent has finished. `AgentNode` does not implement the native `Decisions` continuation protocol. Since 0.16.3, a response with pending approvals or a streamed `ToolApprovalRequest` raises `AgentApprovalRequiredException`. The graph fails without committing that node's partial writes or running downstream nodes, and graph retry policies do not repeat the model call for this exception.

Authorize work with a graph [approval interrupt](../concepts/interrupts.md) before invoking the agent, or implement an application-owned node that durably handles native approval decisions. A streamed text prefix is provisional: consumers must inspect the final run outcome before treating it as a completed answer. Non-recoverable streaming errors likewise fail with `AgentStreamException`.

When `stream()` is enabled, Laravel AI remains the source of token/model streaming. AgentGraph iterates the returned `StreamableAgentResponse`, then uses Laravel AI's completed `StreamedAgentResponse` to write the final text, usage, metadata, tool calls, and tool results. It dispatches the existing `GraphStreamDelta` event for `TextDelta` payloads and records one bounded completion trace without raw streamed text.

Laravel AI 0.11.2 does not expose structured output or execution steps on `StreamedAgentResponse`. Calling `writeStructuredTo()` or `writeStepsTo()` with `stream()` therefore fails before the provider is invoked. Use a non-streaming `prompt()` call when those mappings are required.

Native streaming events remain on Laravel AI's response. AgentGraph only serializes them into graph state when `writeStreamEventsTo()` is configured.

Stream events and `onTextDelta()` callbacks are observational. Their failures are reported without repeating a completed provider invocation; applications must keep authorization and durable side effects in graph nodes or their gateway.

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
