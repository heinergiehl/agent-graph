# Interrupts

Interrupts pause graph execution and return control to the application.

Supported MVP interrupt types:

- `input`
- `approval`
- `delay`
- `webhook`
- `manual_review`
- `state_edit`

Applications resume with `AgentGraph::resume($runId, [...])`. AgentGraph stores the pending interrupt, response payload, resolver metadata, and checkpoint pointer. It does not ship a UI; admin screens, API controllers, workflow UIs, or custom dashboards can build approval/input screens from the interrupt payload.

For stable machine-readable waitpoints, nodes can return `NodeResult::interruptContract(InterruptContract::slotValue(...))`, `InterruptContract::approval(...)`, or `InterruptContract::choice(...)`. Typed contracts include a `response_schema` field that describes the expected answer form. Use `AgentGraph::resumeContract($runId, [...])` when an endpoint should validate that answer before resolving the pending interrupt. Normal `resume()` remains compatible with free-form interrupt payloads.

During the resumed node invocation, `NodeContext::hasResumePayload()`, `resumePayload()`, and `interruptId()` expose the resume response. Delay interrupts are scheduled through the `DelayScheduler` contract; the default implementation dispatches `ContinueDelayedGraphJob`, and applications may bind their own scheduler.

Resume and state-edit resume run under the AgentGraph run lock. For database stores, pending interrupt resolution, run status/options, and a bounded recovery marker are committed in one transaction before continuation starts. The marker is removed atomically when the next checkpoint or queued frontier becomes durable.

If the process exits after accepting the answer but before that next durable boundary, retry the exact same resume payload or call `AgentGraph::recover($runId)`. A different payload is rejected, and `resolvePending()` remains pending-only and run-scoped. Recovery does not mutate a run that is still waiting on a pending interrupt.

Cancelling an active run resolves its pending interrupt with a `cancelled` response in the same database transaction as the terminal run status. Terminal runs cannot be resumed or cancelled again.
