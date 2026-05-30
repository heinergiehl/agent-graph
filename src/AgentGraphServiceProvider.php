<?php

namespace Heiner\AgentGraph;

use Heiner\AgentGraph\Console\DoctorCommand;
use Heiner\AgentGraph\Console\InstallCommand;
use Heiner\AgentGraph\Console\MakeGraphCommand;
use Heiner\AgentGraph\Console\MakeNodeCommand;
use Heiner\AgentGraph\Console\PruneCommand;
use Heiner\AgentGraph\Contracts\CheckpointStore;
use Heiner\AgentGraph\Contracts\Clock;
use Heiner\AgentGraph\Contracts\DelayScheduler;
use Heiner\AgentGraph\Contracts\EmbeddingGenerator;
use Heiner\AgentGraph\Contracts\EnumerableMemoryStore;
use Heiner\AgentGraph\Contracts\InterruptStore;
use Heiner\AgentGraph\Contracts\LeasingTaskStore;
use Heiner\AgentGraph\Contracts\LockProvider;
use Heiner\AgentGraph\Contracts\MemoryExtractor;
use Heiner\AgentGraph\Contracts\MemoryStore;
use Heiner\AgentGraph\Contracts\NodeExecutionStore;
use Heiner\AgentGraph\Contracts\RunStore;
use Heiner\AgentGraph\Contracts\TaskStore;
use Heiner\AgentGraph\Contracts\TraceStore;
use Heiner\AgentGraph\Contracts\VectorMemoryStore;
use Heiner\AgentGraph\Contracts\WriteStore;
use Heiner\AgentGraph\Memory\DeterministicMemoryExtractor;
use Heiner\AgentGraph\Memory\InMemoryVectorMemoryStore;
use Heiner\AgentGraph\Memory\MemoryManager;
use Heiner\AgentGraph\Memory\NullEmbeddingGenerator;
use Heiner\AgentGraph\Persistence\DatabaseCheckpointStore;
use Heiner\AgentGraph\Persistence\DatabaseInterruptStore;
use Heiner\AgentGraph\Persistence\DatabaseMemoryStore;
use Heiner\AgentGraph\Persistence\DatabaseNodeExecutionStore;
use Heiner\AgentGraph\Persistence\DatabaseRunStore;
use Heiner\AgentGraph\Persistence\DatabaseTaskStore;
use Heiner\AgentGraph\Persistence\DatabaseTraceStore;
use Heiner\AgentGraph\Persistence\DatabaseWriteStore;
use Heiner\AgentGraph\Persistence\InMemoryCheckpointStore;
use Heiner\AgentGraph\Persistence\InMemoryInterruptStore;
use Heiner\AgentGraph\Persistence\InMemoryMemoryStore;
use Heiner\AgentGraph\Persistence\InMemoryNodeExecutionStore;
use Heiner\AgentGraph\Persistence\InMemoryRunStore;
use Heiner\AgentGraph\Persistence\InMemoryTaskStore;
use Heiner\AgentGraph\Persistence\InMemoryTraceStore;
use Heiner\AgentGraph\Persistence\InMemoryWriteStore;
use Heiner\AgentGraph\Runtime\GraphRuntime;
use Heiner\AgentGraph\Runtime\RunEventDispatcher;
use Heiner\AgentGraph\Support\CacheLockProvider;
use Heiner\AgentGraph\Support\QueueDelayScheduler;
use Heiner\AgentGraph\Support\SystemClock;
use Illuminate\Support\ServiceProvider;

class AgentGraphServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agent-graph.php', 'agent-graph');

        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(LockProvider::class, CacheLockProvider::class);
        $this->app->singleton(DelayScheduler::class, QueueDelayScheduler::class);

        $this->registerStores();

        $this->app->singleton(MemoryExtractor::class, DeterministicMemoryExtractor::class);
        $this->app->singleton(EmbeddingGenerator::class, NullEmbeddingGenerator::class);
        $this->app->singleton(VectorMemoryStore::class, InMemoryVectorMemoryStore::class);
        $this->app->singleton(MemoryManager::class);
        $this->app->singleton(RunEventDispatcher::class);
        $this->app->singleton(GraphRuntime::class);
        $this->app->singleton(AgentGraphManager::class);
        $this->app->alias(AgentGraphManager::class, 'agent-graph');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/agent-graph.php' => config_path('agent-graph.php'),
        ], 'agent-graph-config');

        $this->publishesMigrations($this->migrationPublishPaths(), 'agent-graph-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                MakeGraphCommand::class,
                MakeNodeCommand::class,
                DoctorCommand::class,
                PruneCommand::class,
            ]);
        }
    }

    protected function registerStores(): void
    {
        $memory = config('agent-graph.store') === 'memory'
            || ($this->app->environment('testing') && config('agent-graph.store') === 'database');

        $bindings = [
            RunStore::class => $memory ? InMemoryRunStore::class : DatabaseRunStore::class,
            CheckpointStore::class => $memory ? InMemoryCheckpointStore::class : DatabaseCheckpointStore::class,
            WriteStore::class => $memory ? InMemoryWriteStore::class : DatabaseWriteStore::class,
            TaskStore::class => $memory ? InMemoryTaskStore::class : DatabaseTaskStore::class,
            InterruptStore::class => $memory ? InMemoryInterruptStore::class : DatabaseInterruptStore::class,
            MemoryStore::class => $memory ? InMemoryMemoryStore::class : DatabaseMemoryStore::class,
            NodeExecutionStore::class => $memory ? InMemoryNodeExecutionStore::class : DatabaseNodeExecutionStore::class,
            TraceStore::class => $memory ? InMemoryTraceStore::class : DatabaseTraceStore::class,
        ];

        foreach ($bindings as $contract => $implementation) {
            $this->app->singleton($contract, $implementation);
        }

        $this->app->alias(RunStore::class, 'agent-graph.runs');
        $this->app->alias(CheckpointStore::class, 'agent-graph.checkpoints');
        $this->app->alias(WriteStore::class, 'agent-graph.writes');
        $this->app->alias(TaskStore::class, 'agent-graph.tasks');
        $this->app->alias(TaskStore::class, LeasingTaskStore::class);
        $this->app->alias(InterruptStore::class, 'agent-graph.interrupts');
        $this->app->alias(MemoryStore::class, 'agent-graph.memory');
        $this->app->alias(MemoryStore::class, EnumerableMemoryStore::class);
        $this->app->alias(MemoryStore::class, 'agent-graph.memory.enumerable');
        $this->app->alias(NodeExecutionStore::class, 'agent-graph.node-executions');
        $this->app->alias(TraceStore::class, 'agent-graph.traces');
    }

    protected function migrationPublishPaths(): array
    {
        $migrations = [
            '2026_05_25_000000_create_agent_graph_tables.php' => 'create_agent_graph_tables',
            '2026_05_26_000000_add_agent_graph_hardening_tables.php' => 'add_agent_graph_hardening_tables',
            '2026_05_26_010000_add_worker_fields_to_agent_graph_node_executions.php' => 'add_worker_fields_to_agent_graph_node_executions',
            '2026_05_30_000000_add_agent_graph_runtime_invariants.php' => 'add_agent_graph_runtime_invariants',
        ];

        $paths = [];

        foreach ($migrations as $file => $name) {
            if ($this->publishedMigrationExists($name)) {
                continue;
            }

            $paths[__DIR__.'/../database/migrations/'.$file] = database_path('migrations/'.$file);
        }

        return $paths;
    }

    protected function publishedMigrationExists(string $name): bool
    {
        $matches = glob(database_path('migrations/*_'.$name.'.php'));

        return $matches !== false && $matches !== [];
    }
}
