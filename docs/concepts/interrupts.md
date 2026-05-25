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
