# Filament chatbot plugin upgrade to AgentGraph 0.16

AgentGraph 0.16 changes the durable task and queued-node completion contracts to reject writes from workers whose claim has been replaced. The existing Filament Agentic Chatbot database-store subclasses must be updated together with the SDK. The unchanged subclasses cause PHP declaration errors when loaded; passing the plugin's memory-store tests does not establish production compatibility.

This guide describes the upgrade to stable AgentGraph `0.16.3`. General AgentGraph root applications may use `^0.16.3`, but the Filament Agentic Chatbot release architecture freezes an exact productive dependency closure. Its package manifest and release contract must therefore pin exact `0.16.3`, and the host lock must resolve that exact stable version together with Laravel AI `^0.11.2`. Patch 0.16.3 retains the 0.16.1 duplicate-column migration fix and adds the [context and resume safety corrections](../../UPGRADE.md#0162-to-0163-durable-context-and-resume-safety). Verify the stricter resume rules and explicit native tool-approval failure in the consuming product's own gates. The original RC1 compatibility patch and its early test results remain below as historical evidence.

## Verified RC2-to-stable integration

The [pending delay recovery addition](delay-recovery.md) can call the bound scheduler repeatedly for an existing interrupt. The original RC1 evidence did not cover this behavior, and the first RC2 review correctly identified that the plugin calculated a new expected projection version after the initial wait was projected. That historical adapter would reject valid redelivery as a changed identity.

The verified plugin integration at commit `65956f031260cff4ca4d6285b1fde5bf4c6f6879` now reuses the existing projection revision only when workflow run, SDK run, checkpoint, interrupt, deployment hash, continuation-authority token, projected state, and due time agree. Its delivery ledger returns an existing receipt only when that immutable binding agrees and does not reset leases, unknown outcomes, attempt counts, terminal receipts, or the original due time. A different checkpoint still advances the projection revision.

Commit `c42448fd193e5f3856ece18b76c50ee299f94140` closes the separate crash boundary after a parent subgraph response is accepted but before child dispatch. Explicit recovery and an identical retry reconstruct only the exact accepted child authorization under the SDK run lock; response, resolved interrupt, source checkpoint, graph/thread/version, parent/child lineage, and current child interrupt must agree.

The provider-free `composer test:agent-runtime` gate was rerun at plugin commit `08d2e7315d23ddf9368d633beaf021a35788b888` and passed **644 tests with 5,913 assertions**, including the delay, accepted-resume, and bounded-store regression files. The inspected later head `a49791607be444097ee86d08318e17bf9b7409b4` differs by two test-only commits in `AgentGraphWorkflowDelayRecoveryTest`: the combined diff invokes the unchanged delayed-resume job handler explicitly and exposes its existing `@throws` contract to Larastan. No runtime or production file differs. The 644-test result belongs to `08d2e7315d23ddf9368d633beaf021a35788b888`; no later CI or local run is represented as passed here.

This closes the previously open AgentGraph-specific plugin recovery gates. It does not approve the plugin's commercial release contract, provider/model matrix, exact artifact, marketplace, external-host, MySQL, or production-deployment gates.

## Required plugin changes

The historical [filament-plugin-upgrade-0.16.patch](filament-plugin-upgrade-0.16.patch) records the two mandatory store-signature changes. The verified plugin candidate already contains equivalent changes; do not apply the patch again without reviewing the target. Custom consuming stores still need the same adaptation:

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

For the stable plugin release, update both its package requirement and release-contract constraint to the exact stable version in the same reviewed change:

```json
"heiner/agent-graph": "0.16.3"
```

Do not use `^0.16.3` in this plugin's package or release contract: that would weaken its exact immutable dependency closure. A root application that directly constrains AgentGraph must admit exact `0.16.3`; otherwise it can inherit the plugin's exact requirement. Keep the existing stable minimum-stability setting and unrelated repository definitions. Refresh the plugin package metadata, AgentGraph, and Laravel AI together when updating the host lock, and verify the installed source/dist references. Changing only the plugin's own vendor directory does not update the host SDK.

Composer installation alone does not authorize existing immutable deployments to use a new runtime. The plugin must also review its SDK-version acceptance and artifact compatibility contract. Do not rewrite stored artifact hashes, deployment manifests, graph versions, or pinned dependency closures to bypass that check. Verify preserved releases through an explicit compatibility policy or publish newly compiled releases through the normal deployment lifecycle.

The historical patch intentionally does not modify Composer files. Do not combine the new SDK with the old overrides, or the new overrides with SDK 0.15.1; the method signatures are incompatible in both directions. Resolve dependencies and validate the plugin in its normal isolated development workflow before deploying.

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

The pre-existing plugin recovery defect was reproduced before the fix. A `GraphResumed` observer was made to throw after the parent response had been persisted but before child continuation. At that point the parent was running, its interrupt was resolved, and the child still waited:

- Repeating the same accepted response is rejected by the plugin's `parentResumeBinding()` before SDK duplicate recovery can run: `Child identity is only valid for a current subgraph interrupt.`
- Calling `recover()` does not restore the plugin's authorized-child-resume context. The parent fails because the child appears to be resumed directly, and the plugin cancels the child.

The same two temporary characterization tests reproduced this behavior with installed SDK 0.15.1 and with the early SDK 0.16 integration (22 assertions for each). They remain historical failure evidence, not successful recovery evidence. Commit `c42448fd193e5f3856ece18b76c50ee299f94140` subsequently fixed the boundary without removing the wrappers or accepting arbitrary child IDs. Eighteen database-backed cases cover observer failure, lost in-memory execution context, identical retry, explicit recovery, duplicate delivery, cancellation, foreign responses, changed graph/checkpoint/child bindings, and a newer child interrupt. The accepted-resume and structured-concurrency group passed 30 tests with 476 assertions in the retained integration record and is also included in the measured 644-test gate above.

## Historical RC1 compatibility evidence and limits

The table below records the bounded compatibility work before the full RC2 plugin integration. It explains why the store changes were mandatory; it is not the current stable integration result.

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

At that historical RC1 stage, no live provider, external host application, production database, or release deployment was exercised. Those claims remain outside that bounded check. The later integration evidence described above closes the named AgentGraph adapter and recovery defects, but does not convert unrelated product/provider/release gates into passing evidence.
