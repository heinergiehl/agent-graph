# Filament chatbot plugin upgrade to AgentGraph 0.16

AgentGraph 0.16 changes the durable task and queued-node completion contracts to reject writes from workers whose claim has been replaced. The existing Filament Agentic Chatbot database-store subclasses must be updated together with the SDK. The unchanged subclasses cause PHP declaration errors when loaded; passing the plugin's memory-store tests does not establish production compatibility.

This guide describes the upgrade for the `0.16.0-rc.2` integration candidate. Select that exact version in both the plugin and the root test application; reserve `^0.16.0` for the subsequent stable release. The original RC1 compatibility audit did not modify the active plugin, its Composer files, its vendor tree, or its Git index. Its patch and test results below are historical RC1 evidence, not verification of RC2's delay recovery or a complete host upgrade.

## Additional gate for RC2 delay recovery

The [pending delay recovery addition](delay-recovery.md) in 0.16.0-rc.2 can call the bound scheduler repeatedly for an existing interrupt. The earlier compatibility evidence below does not cover this addition.

Code review of plugin commit `5d853a94` identified an adapter mismatch: `AgentGraphWorkflowDelayScheduler::schedule()` calculates `expectedRunVersion` from the current `WorkflowRun::state_version + 1` on every call. `AgentGraphWorkflowProjection` increments that version when the initial wait is projected. The existing `WorkflowResumeDeliveryLedger` requires repeated scheduling of the same interrupt to match the original expected version. Redelivery after that projection can therefore be rejected as a changed delivery identity. This is a code-derived integration risk; no live host failure was reproduced in this SDK slice.

Before adopting delay recovery, characterize scheduling before and after the initial projection and update the plugin adapter to reuse the exact existing delivery authority where valid. Do not relax checkpoint, interrupt, deployment, continuation-token, version, cancellation, or structured-concurrency checks. Test repeated recovery, lost delivery, and cancellation through the plugin's normal gateway/runtime path. No plugin files, dependencies, live bots, or deployments were changed by the SDK slice.

## Required plugin changes

Apply [filament-plugin-upgrade-0.16.patch](filament-plugin-upgrade-0.16.patch) from the plugin repository root in the authorized plugin-upgrade change. Review the patch before applying it. It changes only these two overrides:

```diff
--- a/src/Infrastructure/AgentGraph/Persistence/BoundedDatabaseTaskStore.php
+++ b/src/Infrastructure/AgentGraph/Persistence/BoundedDatabaseTaskStore.php
@@
-    public function fail(string $key, string $message, array $meta = []): array
+    public function fail(string $key, int $attempt, string $message, array $meta = []): array
@@
         return parent::fail(
             $key,
+            $attempt,
             $error->safeMessage,
             array_merge($meta, ['bounded_error' => $error->toArray()]),
         );
--- a/src/Infrastructure/AgentGraph/Persistence/BoundedDatabaseNodeExecutionStore.php
+++ b/src/Infrastructure/AgentGraph/Persistence/BoundedDatabaseNodeExecutionStore.php
@@
-    public function fail(string $executionId, array $error): array
+    public function fail(string $executionId, string $claimToken, array $error): array
@@
-        return parent::fail($executionId, $bounded ?? []);
+        return parent::fail($executionId, $claimToken, $bounded ?? []);
```

Keep the existing bounded-error normalization, safe messages, correlation IDs, and protected diagnostic logging. Do not omit, replace, or invent the attempt or claim token. The caller must forward the value returned by the successful claim. Stale completion/failure writes must continue to raise `TaskClaimLostException` or `NodeExecutionClaimLostException`.

The two plugin subclasses inherit `complete()` and, for nodes, `interrupt()`, so they need no additional overrides. Review any other application-specific stores or callers against the full contracts:

```text
// TaskStore
complete(string $key, int $attempt, mixed $result): array;
fail(string $key, int $attempt, string $message, array $meta = []): array;

// NodeExecutionStore
complete(string $executionId, string $claimToken, array $result): array;
interrupt(string $executionId, string $claimToken, array $result): array;
fail(string $executionId, string $claimToken, array $error): array;
```

For release-candidate testing, update the plugin's requirement in the same change as the two store overrides:

```json
"heiner/agent-graph": "0.16.0-rc.2"
```

The root test application must also explicitly require `0.16.0-rc.2`, including when it installs this plugin through a path repository. Dependencies' stability flags do not grant permission at the root. Keep the existing stable minimum-stability setting and unrelated repository definitions. Refresh the plugin package metadata and SDK together when updating the host's lock file. Changing only the plugin's own vendor directory does not update the host's SDK. After successful integration and publication of stable `0.16.0`, change both constraints to `^0.16.0` in the stable plugin release.

Composer installation alone does not authorize existing immutable deployments to use a new runtime. The plugin must also review its SDK-version acceptance and artifact compatibility contract. Do not rewrite stored artifact hashes, deployment manifests, graph versions, or pinned dependency closures to bypass that check. Verify preserved releases through an explicit compatibility policy or publish newly compiled releases through the normal deployment lifecycle.

The supplied patch intentionally does not modify Composer files. Do not combine the new SDK with the old overrides, or the new overrides with SDK 0.15.1; the method signatures are incompatible in both directions. Resolve dependencies and validate the plugin in its normal isolated development workflow before deploying.

## Migration and deployment order

1. Pause new work and scheduled continuations. Drain and stop existing workers before replacing runtime code. Old and new workers must not process the same durable execution concurrently across this API change.
2. Deploy the compatible SDK and plugin changes together. Keep immutable deployment artifacts, graph versions, and dependency pins governed by the existing release process. Do not rewrite stored `graph_version` values, relax version checks, or replace definitions needed to resume existing runs as an upgrade shortcut.
3. Publish and run the new package migration on the configured AgentGraph database connection:

   ```bash
   php artisan vendor:publish --tag=agent-graph-migrations
   php artisan migrate
   ```

   `2026_08_31_010000_add_claim_token_to_agent_graph_node_executions.php` adds nullable `claim_token` to the node-execution table. Configure `AGENT_GRAPH_DB_CONNECTION` before migration if the runtime uses a dedicated connection. Do not edit previously applied migrations.
4. Run `php artisan agent-graph:doctor` and the plugin's targeted database/runtime checks. Restart workers and resume scheduling only after the schema and application code agree.

Previously persisted interrupted subgraph runs do not need a new interrupt payload shape. Continue sending the parent interrupt ID and the saved child run/interrupt IDs. SDK 0.16 validates that binding before accepting a parent response; a stale response must never authorize a newer child interrupt.

## Keep the structured-concurrency wrappers

Do not remove `StructuredConcurrencyGraphRuntime` or `StructuredConcurrencySubgraphNode` during this upgrade. The plugin still supplies transitive cancellation, ancestor activity checks, bounded ownership, direct-child resume restrictions, and workflow semantic-status handling. Core SDK hardening does not replace all those policies. In particular, core parent cancellation still does not imply arbitrary child-tree ownership or cascade cancellation.

The existing ordinary nested resume, delayed-child handling, confirmation, cancellation, and restart characterization tests pass with the new SDK and these wrappers retained. There is no demonstrated need to weaken their ordinary child-ID guards for this upgrade.

A separate, pre-existing plugin recovery defect was also reproduced. A `GraphResumed` observer was made to throw after the parent response had been persisted but before child continuation. At that point the parent is running, its interrupt is resolved, and the child still waits:

- Repeating the same accepted response is rejected by the plugin's `parentResumeBinding()` before SDK duplicate recovery can run: `Child identity is only valid for a current subgraph interrupt.`
- Calling `recover()` does not restore the plugin's authorized-child-resume context. The parent fails because the child appears to be resumed directly, and the plugin cancels the child.

The same two temporary characterization tests reproduced this behavior with installed SDK 0.15.1 and with SDK 0.16 (22 assertions for each). They assert the defect, not successful recovery. This is separate from the two-store API patch and remains unfixed in the plugin. A follow-up plugin change must reconstruct authorization for a persisted, accepted recovery response while preserving lineage, current-interrupt, terminal-parent, and root-lock checks. Do not remove wrappers or accept arbitrary child IDs to bypass the problem.

## Compatibility evidence and limits

The following existing plugin test files ran unchanged against the new SDK:

- `tests/Unit/AgentGraphPublicApiCompatibilityTest.php`
- `tests/Feature/AgentGraphInterruptedResumeCharacterizationTest.php`
- `tests/Feature/AgentGraphConfirmationPayloadParityTest.php`
- `tests/Unit/AgentGraphStructuredConcurrencyCharacterizationTest.php`
- `tests/Feature/AgentGraphStructuredConcurrencyRestartCharacterizationTest.php`

| Check | Result | Scope |
| --- | --- | --- |
| Five existing test files, unchanged plugin | 19 tests / 475 assertions passed | Memory/Testbench paths and the existing restart fixture; not production-store loading |
| Same files, separate two-store overlay | 19 tests / 475 assertions passed | New SDK with only the proposed plugin overrides redirected |
| Unchanged production store classes loaded against new SDK | Both failed with PHP exit 255 | Confirmed mandatory plugin signature changes |
| Overlay production store classes loaded against new SDK | Both loaded successfully | Reflected signatures and source paths verified |
| Overlay on isolated SQLite with all five new SDK migrations | 26 assertions passed | Token/attempt forwarding, stale-claim rejection without overwrites, durable error redaction, and protected diagnostic logging |

A temporary Composer classmap/PSR-4 redirect loaded the new SDK without editing vendor files. Reflection verified the new source paths in both PHPUnit processes and all twelve restart subprocesses. No named SDK runtime classes resolved to the installed old SDK. The optional overlay redirected exactly the two plugin store classes; all other plugin classes came from the active plugin tree. The runs used `--do-not-cache-result`.

The restart fixture explicitly loads migrations from `vendor/heiner/agent-graph/database/migrations`, even under a source-class override. Those restart runs therefore used the installed 0.15.1 migration files. They establish the tested synchronous restart behavior with that schema, not a full new-schema queue/deployment certification. The separate isolated SQLite overlay check applied all new SDK migrations, including `claim_token`, and verified the updated durable-store behavior.

No live provider, external host application, production database, or release deployment was exercised. Those gates remain outside this bounded compatibility check. The active plugin contained unrelated concurrent changes, which were left untouched; review and integrate the prepared patch in its own authorized plugin change.
