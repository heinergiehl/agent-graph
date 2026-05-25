<?php

namespace Heiner\AgentGraph\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneCommand extends Command
{
    protected $signature = 'agent-graph:prune
        {--days=30 : Delete records older than this many days}
        {--runs : Prune completed, failed, or cancelled runs}
        {--traces : Prune old trace records}
        {--tasks : Prune old completed or failed tasks}
        {--memories : Prune expired memories}
        {--dry-run : Count records without deleting them}';

    protected $description = 'Prune old AgentGraph data according to application retention policy.';

    public function handle(): int
    {
        $selected = collect(['runs', 'traces', 'tasks', 'memories'])
            ->filter(fn (string $option): bool => (bool) $this->option($option))
            ->values();

        if ($selected->isEmpty()) {
            $this->components->warn('No prune target selected. Pass --runs, --traces, --tasks, or --memories.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays((int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('runs')) {
            $query = DB::table(config('agent-graph.tables.runs'))
                ->whereIn('status', ['completed', 'failed', 'cancelled'])
                ->where('updated_at', '<', $cutoff);

            $this->line('runs pruned: '.$this->prune($query, $dryRun));
        }

        if ($this->option('traces')) {
            $query = DB::table(config('agent-graph.tables.traces'))
                ->where('created_at', '<', $cutoff);

            $this->line('traces pruned: '.$this->prune($query, $dryRun));
        }

        if ($this->option('tasks')) {
            $query = DB::table(config('agent-graph.tables.tasks'))
                ->whereIn('status', ['completed', 'failed'])
                ->where('updated_at', '<', $cutoff);

            $this->line('tasks pruned: '.$this->prune($query, $dryRun));
        }

        if ($this->option('memories')) {
            $query = DB::table(config('agent-graph.tables.memories'))
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now());

            $this->line('expired memories pruned: '.$this->prune($query, $dryRun));
        }

        return self::SUCCESS;
    }

    protected function prune($query, bool $dryRun): int
    {
        $count = (clone $query)->count();

        if (! $dryRun && $count > 0) {
            $query->delete();
        }

        return $count;
    }
}
