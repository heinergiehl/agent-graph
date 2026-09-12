# Filament Agentic Chatbot: upgrade to AgentGraph 0.18.0

The SDK release is published and verified. **The inspected plugin cannot safely adopt it through a dependency update alone.** This handoff identifies required integration work; it is not a claim that the plugin changes or integration tests have already been completed.

Inspection: 2026-09-12, local `filament-agentic-chatbot` checkout at `93629747babb0002208360c5ab1aaf8a721f5374`, with ongoing uncommitted chatbot refactoring. Paths below are relative to that plugin repository. Reconcile this guide with the current working tree; preserve other work. Its current SDK pin is `0.16.3`, so both the 0.17 and 0.18 upgrade requirements apply.

## 1. Adapt structured execution before enabling 0.18

`src/Services/AgentGraph/StructuredConcurrencyGraphRuntime.php` extends SDK internals:

- `continueLocked()` tracks `executingRuns` around execution and performs terminal cascading. The SDK no longer calls this method. Consequently the plugin loses that tracking and can reject a legitimate child with “A structured child run may only be created by its executing parent.” Calling the removed parent method also fails. Merely renaming the hook to `prepareContinuationLocked()` is insufficient: preparation now ends before node delivery.
- `recoverLocked()` and its local callback expect `RunResult`. The SDK can now return an internal `ExecutionFrontier`; returning it through the old wrapper causes a type error. Accepted child-resume authorization currently wraps this recovery path and must remain in scope during actual child execution, not only scheduling.
- SDK `runSession()` now selects/creates and schedules through `startRun()` rather than calling the public `run()` override. Cover session-start behavior explicitly when relocating the plugin's parent-admission checks.
- The plugin's `withStructuredRootLock()` still wraps execution and cancellation. Rework that coordination alongside the new SDK boundaries; retaining it unchanged can negate short-lock cancellation behavior. Preserve parent/child bindings, depth/child bounds, cancellation cascading and exact accepted-resume validation.

Move application authorization to explicit plugin control and node/tool boundaries, with execution-scoped bookkeeping spanning actual delivery. Do not remove these checks or recreate the old SDK execution loop. Any interim use of protected SDK methods requires explicit adapter tests because they remain internal interfaces.

## 2. Preserve bounded errors and revision fencing

`src/Infrastructure/AgentGraph/Persistence/BoundedDatabaseRunStore.php` currently normalizes errors only in `update()`. SDK runtime transitions now call:

```php
public function transition(string $runId, int $revision, array $attributes): array
```

Move the normalization to this entry point and forward the exact expected revision to `parent::transition()`. Inherited `update()` delegates to `transition()`, so avoid redundant normalization wrappers. Preserve bounded persisted error payloads and protect optional diagnostic logging from changing a confirmed business outcome. Test a real failed SDK node against the database adapter, not only a direct `update()` call.

The structured runtime's `cancelOne()` currently calls `$this->runs->update(...)` after reading a run. Review that path for migration to `transition()` with the observed revision; do not silently adopt a fresh revision after a race. Retain atomic interrupt resolution and reject/reconcile stale transitions.

## 3. Update every exact dependency/compatibility pin

After adapting the integration, change the following together:

| Plugin file | Required update |
| --- | --- |
| `composer.json` | `heiner/agent-graph`: exact `0.18.0`; Laravel AI remains `^0.11.2`. |
| `scripts/release/release-contract.json` | SDK constraint to exact `0.18.0`; update release evidence/status through the plugin's normal release procedure. |
| `src/Services/Runtime/Contracts/DeploymentRuntimeCompatibility.php` | SDK constraint `0.18.0` and normalized installed version `0.18.0.0`. |
| `src/Console/DoctorCommand.php` | Update SDK remediation version and relevant feature checks. |
| `tests/Unit/DeploymentRuntimeCompatibilityTest.php`, `tests/Unit/DoctorCommandTest.php` | Adapt exact-version expectations while retaining rejection tests. |
| Host/sandbox Composer locks | Resolve both the updated plugin and exact SDK release; confirm the installed package reference. |

The deployment compatibility checks deliberately bind immutable artifacts to the SDK version. Republish affected deployments through the supported plugin procedure and test treatment of existing runs. Do not rewrite historical deployment manifests or widen version acceptance merely to silence the guard.

In the plugin development checkout, after its manifest is updated:

```bash
composer update heiner/agent-graph -W
```

In a host that resolves the updated plugin package:

```bash
composer update heiner/filament-agentic-chatbot heiner/agent-graph -W
```

The host must actually resolve the updated plugin source/version; an older published plugin still pins SDK 0.16.3.

## 4. Migrate and verify the consuming application

Stop graph-executing workers during the coordinated update. Publish missing SDK migrations without overwriting existing files, run migrations and doctor in the host, then restart all processes on the same dependency set:

```bash
php artisan vendor:publish --tag=agent-graph-migrations
php artisan migrate
php artisan agent-graph:doctor
```

The additive 0.17 run-revision migration is required when starting from 0.16.3; 0.18 adds no further migration. Synchronous runs now persist node receipts, so retain the associated tables and apply retention policy. Do not reset the database or reinstall the plugin to perform this upgrade.

Run `composer test:agent-runtime` and the plugin's normal static/release checks. Also run these targeted tests explicitly: not all are included in that script.

- `tests/Unit/AgentGraphStructuredConcurrencyCharacterizationTest.php`
- `tests/Feature/AgentGraphStructuredConcurrencyRestartCharacterizationTest.php`
- `tests/Feature/AgentGraphStructuredConcurrencyAcceptedResumeRecoveryTest.php`
- `tests/Feature/AgentGraphDatabaseRestartRestorePreflightTest.php`
- `tests/Feature/AgentGraphBoundedDatabaseStoresTest.php`
- `tests/Feature/AgentGraphWorkflowDelayRecoveryTest.php`
- `tests/Unit/DeploymentRuntimeCompatibilityTest.php`

Add behavioral coverage for the changed boundaries: parent/child execution via session start, sync and queue; accepted resume followed by a crash before child delivery; cancellation while a child blocks; bounded failure persistence through `transition()`; and stale cancellation revisions. Finally verify actual chatbot streaming, approval/resume, cancellation and page reload in the host. The SDK's 435 passing tests and Packagist sandbox installation do not replace these plugin integration checks.

See [UPGRADE.md](../../UPGRADE.md), [0.17 release notes](../releases/v0.17.0.md) and [0.18 release notes](../releases/v0.18.0.md) for the SDK contracts and limits, including cooperative cancellation and unsupported native tool-approval resumption.
