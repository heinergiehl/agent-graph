<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

// Opt in against a disposable PostgreSQL database:
// AGENT_GRAPH_POSTGRES_CONCURRENCY=1 AGENT_GRAPH_POSTGRES_HOST=127.0.0.1
// AGENT_GRAPH_POSTGRES_PORT=5432 AGENT_GRAPH_POSTGRES_DATABASE=agentgraph_test
// AGENT_GRAPH_POSTGRES_USERNAME=agentgraph AGENT_GRAPH_POSTGRES_PASSWORD=...
// php vendor/bin/pest tests/Integration/PostgresRuntimeConcurrencyTest.php
// Requires pdo_pgsql, proc_open, and CREATE SCHEMA permission. Every test owns a
// randomized schema; teardown stops its workers before dropping only that schema.

beforeEach(function () {
    if (getenv('AGENT_GRAPH_POSTGRES_CONCURRENCY') !== '1') {
        $this->markTestSkipped('Set AGENT_GRAPH_POSTGRES_CONCURRENCY=1 to run independent PostgreSQL workers.');
    }
    if (! extension_loaded('pdo_pgsql') || ! function_exists('proc_open')) {
        $this->markTestSkipped('PostgreSQL concurrency tests require pdo_pgsql and proc_open.');
    }
});

it('cancels a blocked synchronous worker promptly without admitting its late result or successor', function () {
    $h = new PostgresRuntimeProcesses('cancel');
    try {
        $original = $h->start('original', 'run');
        $runId = $h->barrier('entered_original')['run_id'];
        $started = microtime(true);
        $cancel = $h->start('cancel', 'cancel', $runId);
        $cancelled = $h->result('cancel', $cancel, 8);

        expect(microtime(true) - $started)->toBeLessThan(8)
            ->and($original->isRunning())->toBeTrue()
            ->and($cancelled['status'])->toBe('cancelled');
        $revision = $h->run($runId)->revision;
        $h->release('release_original');
        $late = $h->result('original', $original);

        expect($late['status'])->toBe('cancelled')
            ->and($h->run($runId)->status)->toBe('cancelled')
            ->and($h->run($runId)->revision)->toBe($revision)
            ->and($h->table('agent_graph_checkpoints')->count())->toBe(0)
            ->and($h->table('agent_graph_writes')->count())->toBe(0)
            ->and($h->table('test_effects')->where('node', 'after')->count())->toBe(0);
    } finally {
        $h->close();
    }
});

it('lets a replacement process reclaim an expired node lease and rejects the old live worker', function () {
    $h = new PostgresRuntimeProcesses('takeover');
    try {
        $original = $h->start('original', 'run');
        $runId = $h->barrier('entered_original')['run_id'];
        $receipt = $h->table('agent_graph_node_executions')->where('run_id', $runId)->first();
        expect($receipt->status)->toBe('running');
        // Deterministically expire the persisted lease while its owner is alive.
        $h->table('agent_graph_node_executions')->where('execution_id', $receipt->execution_id)
            ->update(['locked_until' => now()->subMinute()]);
        $replacement = $h->start('replacement', 'recover', $runId);
        $winner = $h->result('replacement', $replacement);
        $committed = $h->table('agent_graph_node_executions')->where('execution_id', $receipt->execution_id)->first();

        expect($winner['status'])->toBe('completed')
            ->and($winner['state']['answer'])->toBe('replacement')
            ->and($committed->claim_token)->not->toBe($receipt->claim_token)
            ->and($original->isRunning())->toBeTrue();
        $revision = $h->run($runId)->revision;
        $h->release('release_original');
        $h->result('original', $original);

        expect($h->run($runId)->revision)->toBe($revision)
            ->and($h->table('agent_graph_node_executions')->where('execution_id', $receipt->execution_id)->first())->toEqual($committed)
            ->and($h->table('test_effects')->where('node', 'after')->count())->toBe(1)
            ->and($h->table('agent_graph_checkpoints')->count())->toBe(2);
    } finally {
        $h->close();
    }
});

it('recovers a killed synchronous fan-out process from receipts and preserves duplicate Send inputs', function () {
    $h = new PostgresRuntimeProcesses('fanout');
    try {
        $original = $h->start('original', 'run');
        $runId = $h->barrier('entered_original')['run_id'];
        $receipts = $h->table('agent_graph_node_executions')->where('step', 2)->orderBy('schedule_index')->get();
        expect($receipts->pluck('status')->all())->toBe(['completed', 'running'])
            ->and($receipts->pluck('node_id')->all())->toBe(['work', 'work']);
        $first = $receipts[0];
        $original->stop(0, 9);
        expect($original->isRunning())->toBeFalse();
        $h->table('agent_graph_node_executions')->where('execution_id', $receipts[1]->execution_id)
            ->update(['locked_until' => now()->subMinute()]);

        $recovery = $h->start('recovery', 'recover', $runId);
        $result = $h->result('recovery', $recovery);
        expect($result['status'])->toBe('completed')
            ->and($result['state']['answers'])->toBe(['A', 'B'])
            ->and($h->table('agent_graph_node_executions')->where('execution_id', $first->execution_id)->first())->toEqual($first)
            ->and($h->table('test_effects')->where('item', 'A')->where('phase', 'entered')->count())->toBe(1)
            ->and($h->table('test_effects')->where('item', 'B')->where('phase', 'entered')->count())->toBe(2)
            ->and($h->table('test_effects')->where('phase', 'finished')->orderBy('id')->pluck('item')->all())->toBe(['A', 'B'])
            ->and($h->table('agent_graph_node_executions')->where('step', 2)->orderBy('schedule_index')->get()
                ->map(fn (object $row) => json_decode($row->node_state, true)['item'])->all())->toBe(['A', 'B']);
    } finally {
        $h->close();
    }
});

it('admits a pending interrupt only once across concurrent resume processes', function () {
    $h = new PostgresRuntimeProcesses('resume');
    try {
        $setup = $h->start('setup', 'run');
        $waiting = $h->result('setup', $setup);
        expect($waiting['status'])->toBe('interrupted');
        $runId = $waiting['run_id'];
        $interruptId = $waiting['interrupt']['interrupt_id'];
        $one = $h->start('one', 'resume', $runId, $interruptId);
        $two = $h->start('two', 'resume', $runId, $interruptId);
        $h->barrier('ready_one');
        $h->barrier('ready_two');
        $h->release('start_resumes');
        // Both processes hold the same pending interrupt snapshot. Neither can
        // resolve it until this barrier opens, even with an expired run lock.
        $h->barrier('read_one');
        $h->barrier('read_two');
        $h->release('admit_resumes');
        $h->until(fn () => $h->table('test_effects')->where('node', 'approval')->count() === 1);
        $h->release('release_resumes');
        $one->wait();
        $two->wait();
        $outcomes = [$h->outcome('one', $one), $h->outcome('two', $two)];

        expect(collect($outcomes)->where('status', 'completed')->count())->toBe(1)
            ->and(collect($outcomes)->where('class', RuntimeException::class)->count())->toBe(1)
            ->and(collect($outcomes)->firstWhere('class', RuntimeException::class)['message'])->toContain('no longer pending')
            ->and($h->run($runId)->status)->toBe('completed')
            ->and($h->table('test_effects')->where('node', 'approval')->count())->toBe(1)
            ->and($h->table('agent_graph_node_executions')->where('step', 2)->count())->toBe(1)
            ->and($h->table('agent_graph_interrupts')->where('interrupt_id', $interruptId)->value('status'))->toBe('resolved');
    } finally {
        $h->close();
    }
});

final class PostgresRuntimeProcesses
{
    private string $schema;

    private string $directory;

    private array $processes = [];

    private bool $closed = false;

    private string $originalConnection;

    private ?string $originalGraphConnection;

    public function __construct(string $scenario)
    {
        $this->schema = 'pgrt_'.bin2hex(random_bytes(10));
        $this->directory = sys_get_temp_dir().'/'.$this->schema;
        mkdir($this->directory, 0700);
        $connection = [
            'driver' => 'pgsql',
            'host' => getenv('AGENT_GRAPH_POSTGRES_HOST') ?: '127.0.0.1',
            'port' => getenv('AGENT_GRAPH_POSTGRES_PORT') ?: '5432',
            'database' => getenv('AGENT_GRAPH_POSTGRES_DATABASE') ?: 'agentgraph_test',
            'username' => getenv('AGENT_GRAPH_POSTGRES_USERNAME') ?: 'agentgraph',
            'password' => getenv('AGENT_GRAPH_POSTGRES_PASSWORD') ?: '',
            'charset' => 'utf8', 'prefix' => '', 'search_path' => $this->schema,
            'sslmode' => 'prefer',
        ];
        $this->originalConnection = config('database.default');
        $this->originalGraphConnection = config('agent-graph.database.connection');
        config(['database.connections.runtime_concurrency' => $connection]);
        DB::purge('runtime_concurrency');
        DB::connection('runtime_concurrency')->statement('CREATE SCHEMA "'.$this->schema.'"');
        config(['database.default' => 'runtime_concurrency', 'agent-graph.database.connection' => 'runtime_concurrency']);
        try {
            foreach (glob(__DIR__.'/../../database/migrations/*.php') as $path) {
                (require $path)->up();
            }
            Schema::create('test_effects', function ($table) {
                $table->id();
                $table->string('run_id');
                $table->string('worker');
                $table->string('node');
                $table->string('phase');
                $table->string('item')->nullable();
            });
            file_put_contents($this->directory.'/settings.json', json_encode([
                'connection' => $connection, 'scenario' => $scenario,
            ], JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            $this->close();
            throw $exception;
        }
    }

    public function start(string $worker, string $action, ?string $runId = null, ?string $interruptId = null): Process
    {
        $command = [PHP_BINARY, __DIR__.'/../Fixtures/postgres-runtime-worker.php', $this->directory.'/settings.json', $worker, $action];
        if ($runId !== null) {
            $command[] = $runId;
        }
        if ($interruptId !== null) {
            $command[] = $interruptId;
        }
        $process = new Process($command, dirname(__DIR__, 2), timeout: 45);
        $this->processes[$worker] = $process;
        $process->start();

        return $process;
    }

    public function barrier(string $name, float $timeout = 15): array
    {
        $this->until(fn () => is_file($this->directory.'/'.$name.'.json'), $timeout);

        return json_decode(file_get_contents($this->directory.'/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function release(string $name): void
    {
        file_put_contents($this->directory.'/'.$name.'.json', '{}');
    }

    public function until(Closure $condition, float $timeout = 15): void
    {
        $deadline = microtime(true) + $timeout;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                $diagnostics = [];
                foreach ($this->processes as $name => $process) {
                    $diagnostics[$name] = ['running' => $process->isRunning(), 'stderr' => $process->getErrorOutput(), 'stdout' => $process->getOutput()];
                }
                throw new RuntimeException('Process barrier timed out: '.json_encode($diagnostics));
            }
            usleep(10000);
            clearstatcache();
        }
    }

    public function result(string $worker, Process $process, float $timeout = 15): array
    {
        $this->until(fn () => ! $process->isRunning(), $timeout);
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Worker '.$worker.' failed: '.$process->getErrorOutput().$process->getOutput());
        }

        return $this->barrier('result_'.$worker);
    }

    public function outcome(string $worker, Process $process): array
    {
        return $this->barrier(($process->isSuccessful() ? 'result_' : 'error_').$worker);
    }

    public function table(string $name): Builder
    {
        return DB::connection('runtime_concurrency')->table($name);
    }

    public function run(string $runId): object
    {
        return $this->table('agent_graph_runs')->where('public_id', $runId)->first();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0, 9);
            }
        }
        if (! preg_match('/^pgrt_[a-f0-9]{20}$/D', $this->schema)) {
            throw new RuntimeException('Refusing to drop an unowned schema.');
        }
        DB::connection('runtime_concurrency')->statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
        DB::purge('runtime_concurrency');
        config([
            'database.default' => $this->originalConnection,
            'agent-graph.database.connection' => $this->originalGraphConnection,
        ]);
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }
}
