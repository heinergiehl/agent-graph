# Checkpoints

AgentGraph persists a checkpoint after every successful node step. A checkpoint records the run, thread, graph key/version, step number, state snapshot, next nodes, completed nodes, interrupt metadata, and trace metadata.

Writes are stored separately from snapshots. This allows debugging, state diffs, replay foundations, and future time-travel/forking support.

The MVP resumes from the latest checkpoint for a run. Full checkpoint forking and replay are post-MVP features.
