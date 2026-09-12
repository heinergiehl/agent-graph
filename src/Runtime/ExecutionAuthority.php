<?php

namespace Heiner\AgentGraph\Runtime;

use Heiner\AgentGraph\Contracts\NodeExecutionStore;
use Heiner\AgentGraph\Contracts\RunStore;
use Heiner\AgentGraph\Exceptions\NodeExecutionClaimLostException;
use Heiner\AgentGraph\Exceptions\NodeTimeoutException;
use Heiner\AgentGraph\Exceptions\RunStateChangedException;
use InvalidArgumentException;
use RuntimeException;

/** @internal One expected run revision and optional node claim; checks never adopt a newer owner. */
final readonly class ExecutionAuthority
{
    public function __construct(
        private RunStore $runs,
        private array $run,
        private ?NodeExecutionStore $nodeExecutions = null,
        private ?string $executionId = null,
        private ?string $claimToken = null,
        private ?float $deadline = null,
    ) {
        if (($executionId === null) !== ($claimToken === null)
            || ($executionId !== null && ($executionId === '' || $claimToken === '' || $nodeExecutions === null))) {
            throw new InvalidArgumentException('Node authority requires an execution store, execution ID and non-empty claim token together.');
        }
    }

    public function runId(): string
    {
        return $this->run['public_id'];
    }

    public function revision(): int
    {
        return (int) $this->run['revision'];
    }

    /** Ownership-only checks remain usable when recording an elapsed deadline as failure. */
    public function assertCurrent(): void
    {
        $current = $this->runs->find($this->runId());
        if ($current === null || (int) $current['revision'] !== $this->revision()) {
            throw new RunStateChangedException("Run [{$this->runId()}] changed during execution.");
        }
        if (($current['status'] ?? null) !== RunStatus::RUNNING) {
            throw new RunStateChangedException("Run [{$this->runId()}] is no longer running.");
        }

        $ancestor = $current;
        $seen = [$this->runId()];
        while (data_get($ancestor, 'meta.parent.relationship') === 'subgraph') {
            $parentId = data_get($ancestor, 'meta.parent.run_id');
            if (! is_string($parentId) || in_array($parentId, $seen, true)) {
                throw new RuntimeException('Invalid subgraph ancestry.');
            }
            $seen[] = $parentId;
            $ancestor = $this->runs->find($parentId);
            if ($ancestor === null || RunStatus::isTerminal($ancestor['status'] ?? null)) {
                $this->runs->transition($this->runId(), $this->revision(), ['status' => RunStatus::CANCELLED]);
                throw new RunStateChangedException('The parent no longer authorizes subgraph execution.');
            }
        }

        if ($this->executionId !== null) {
            $execution = $this->nodeExecutions->find($this->executionId);
            if (($execution['run_id'] ?? null) !== $this->runId()
                || ($execution['status'] ?? null) !== 'running'
                || ($execution['claim_token'] ?? null) !== $this->claimToken) {
                throw new NodeExecutionClaimLostException('Node execution ownership changed.');
            }
        }
    }

    public function assertActive(): void
    {
        $this->assertCurrent();
        if ($this->remainingSeconds() === 0.0) {
            throw new NodeTimeoutException('The node deadline expired before execution admission.');
        }
    }

    public function remainingSeconds(): ?float
    {
        return $this->deadline === null ? null : max(0.0, $this->deadline - hrtime(true) / 1e9);
    }

    public function deadline(): ?float
    {
        return $this->deadline;
    }

    /** Establish a single budget before lock waiting and retries; never extend an existing one. */
    public function withTimeout(?float $seconds): self
    {
        if ($seconds === null) {
            return $this;
        }
        if ($seconds <= 0) {
            throw new InvalidArgumentException('Timeout seconds must be greater than zero.');
        }
        $deadline = hrtime(true) / 1e9 + $seconds;

        return new self(
            $this->runs, $this->run, $this->nodeExecutions, $this->executionId, $this->claimToken,
            $this->deadline === null ? $deadline : min($this->deadline, $deadline),
        );
    }
}
