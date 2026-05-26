# Checkpoints

AgentGraph persists a checkpoint after every successful node step. A checkpoint records the run, thread, graph key/version, step number, state snapshot, next nodes, completed nodes, interrupt metadata, and trace metadata.

Writes are stored separately from snapshots. This allows debugging, state diffs, replay foundations, and time-travel/forking support.

Normal resume continues from the latest checkpoint for a run. Time-travel APIs work from any specific checkpoint:

- `AgentGraph::checkpoint($checkpointId, withWrites: true)` returns a read-only snapshot of one checkpoint and optionally its writes.
- `AgentGraph::replay($checkpointId)` creates a new run from the checkpoint state and continues through the checkpoint's recorded `next_nodes`.
- `AgentGraph::fork($checkpointId, statePatch: [...])` creates a new run, applies a schema-validated state patch, persists an initial fork checkpoint, and continues from either the original `next_nodes` or the successors of `asNode`.
- `AgentGraph::timeTravelChildren($checkpointId)` lists replay and fork runs whose run metadata points back to the source checkpoint.

Replay and fork runs never rewrite the source run. New checkpoints link back through `parent_checkpoint_id`, while run metadata stores `time_travel.source_checkpoint_id` for checkpoint-lineage queries.

Replay and fork runs also store `run.meta.parent` pointing to the source run with `relationship` set to `replay` or `fork`. Use `AgentGraph::childRuns($sourceRunId)` for run-level lineage and `AgentGraph::timeTravelChildren($checkpointId)` for checkpoint-specific replay/fork lineage. Parent checkpoint chains describe execution ancestry; source-lineage describes which replays and forks were created from a checkpoint.
