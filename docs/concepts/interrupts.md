# Interrupts

Interrupts pause graph execution and return control to the application.

Supported MVP interrupt types:

- `input`
- `approval`
- `delay`
- `webhook`
- `manual_review`
- `state_edit`

Applications resume with `AgentGraph::resume($runId, [...])`. AgentGraph stores the pending interrupt, response payload, resolver metadata, and checkpoint pointer. It does not ship a UI; Filament, Nova, API controllers, or custom dashboards can build approval/input screens from the interrupt payload.

During the resumed node invocation, `NodeContext::hasResumePayload()`, `resumePayload()`, and `interruptId()` expose the resume response. Delay interrupts are scheduled through the `DelayScheduler` contract; the default implementation dispatches `ContinueDelayedGraphJob`, and applications may bind their own scheduler.

Resume and state-edit resume run under the AgentGraph run lock. Terminal runs cannot be resumed again, and interrupt resolution is scoped to the expected run and pending interrupt id.
