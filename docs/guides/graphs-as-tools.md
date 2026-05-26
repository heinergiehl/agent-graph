# Graphs as Tools

Expose a durable graph to a Laravel AI parent agent with `AgentGraph::tool()`.

```php
AgentGraph::tool('support_triage')
    ->name('run_support_triage')
    ->description('Run or resume the support workflow.')
    ->thread(fn ($request) => $request['thread_id']);
```

The tool can start a new run or resume an existing run when `run_id` is present. Responses are structured JSON so chat UIs can detect `completed`, `interrupted`, `delayed`, and `failed` statuses.

Use mapping hooks when the parent agent's tool request shape is different from graph state:

```php
AgentGraph::tool('support_triage')
    ->thread(fn ($request) => $request['conversation_id'])
    ->input(fn ($request) => ['input' => $request['message']])
    ->meta(fn ($request) => ['source' => 'parent-agent'])
    ->output(fn ($run) => [
        'status' => $run->status(),
        'answer' => $run->state('answer'),
        'interrupt' => $run->interrupt(),
    ]);
```

Keep durable lifecycle observation in `RunEvent` callbacks. GraphTool hooks are for request/response mapping, not app-specific persistence.
