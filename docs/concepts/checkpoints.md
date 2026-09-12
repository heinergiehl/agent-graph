# Checkpoints

AgentGraph persists a checkpoint after every successful node step. A checkpoint records the run, thread, graph key/version, step number, state snapshot, next nodes, completed nodes, interrupt metadata, and trace metadata.

Writes are stored separately from snapshots. This allows debugging, state diffs, replay foundations, and time-travel/forking support.

In both `sync` and `queued_supersteps` modes, AgentGraph persists the frontier before invocation and treats node execution rows as pending writes. Recovery reuses completed receipts. The same commit path aggregates durable node results into one checkpoint once the frontier is complete; only inline versus queue delivery differs.

Run transitions and checkpoint commits are fenced by the expected run revision. Node claims independently fence lease replacement. Late outcomes cannot replace a newer run state.

An unfinished node can be reclaimed after its lease expires. A crash after an external effect but before its receipt still requires application idempotency and reconciliation. Previously committed receipts are reused, including separate Sends to the same node. See the [0.17 upgrade notes](../releases/v0.17.0.md).

Normal resume continues from the latest checkpoint for a run.

The persisted continuation includes each Send's destination, local input, and metadata. Recovery preserves separate Sends to the same destination. Wait checkpoints retain the interrupted invocation in synchronous and queued modes, so ordinary and state-edit resumes reconstruct the same local context. An empty continuation means there is no remaining node to execute; recovery completes the run without replaying its entry nodes. Local context already discarded by checkpoints written before 0.16.3 cannot be reconstructed automatically.

Time-travel APIs:

- `AgentGraph::checkpoint($checkpointId, withWrites: true)` returns a read-only snapshot of one checkpoint and optionally its writes.
- `AgentGraph::replay($checkpointId)` creates a new run from the checkpoint state and continues through the checkpoint's recorded `next_nodes`.
- `AgentGraph::fork($checkpointId, statePatch: [...])` creates a new run, applies a schema-validated state patch, persists an initial fork checkpoint, and continues from either the original `next_nodes` or the successors of `asNode`.
- `AgentGraph::timeTravelChildren($checkpointId)` lists replay and fork runs whose run metadata points back to the source checkpoint.

Replay and fork runs never rewrite the source run. New checkpoints link back through `parent_checkpoint_id`, while run metadata stores `time_travel.source_checkpoint_id` for checkpoint-lineage queries.

Replay and fork runs also store `run.meta.parent` pointing to the source run with `relationship` set to `replay` or `fork`. Use `AgentGraph::childRuns($sourceRunId)` for run-level lineage and `AgentGraph::timeTravelChildren($checkpointId)` for checkpoint-specific replay/fork lineage. Parent checkpoint chains describe execution ancestry; source-lineage describes which replays and forks were created from a checkpoint.
