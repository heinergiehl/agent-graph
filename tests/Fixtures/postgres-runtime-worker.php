<?php

// Standalone process fixture. Its only database authority is the test-owned schema.
require __DIR__.'/../../vendor/autoload.php';

use Heiner\AgentGraph\AgentGraphServiceProvider;
use Heiner\AgentGraph\Contracts\LockProvider;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Persistence\DatabaseCheckpointStore;
use Heiner\AgentGraph\Persistence\DatabaseInterruptStore;
use Heiner\AgentGraph\Persistence\DatabaseMemoryStore;
use Heiner\AgentGraph\Persistence\DatabaseNodeExecutionStore;
use Heiner\AgentGraph\Persistence\DatabaseRunStore;
use Heiner\AgentGraph\Persistence\DatabaseTaskStore;
use Heiner\AgentGraph\Persistence\DatabaseTraceStore;
use Heiner\AgentGraph\Persistence\DatabaseWriteStore;
use Heiner\AgentGraph\Runtime\GraphRuntime;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\RunEventDispatcher;
use Heiner\AgentGraph\Runtime\Send;
use Heiner\AgentGraph\State\Reducer;
use Orchestra\Testbench\Foundation\Application;

$settings = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$worker = $argv[2];
$action = $argv[3];
$runId = $argv[4] ?? null;
$interruptId = $argv[5] ?? null;
if (! preg_match('/^pgrt_[a-f0-9]{20}$/D', $settings['connection']['search_path'])
    || ! preg_match('/^[a-z0-9_]+$/D', $worker)) {
    throw new RuntimeException('Invalid isolated worker configuration.');
}

$app = Application::create(options: ['extra' => ['providers' => [AgentGraphServiceProvider::class]]]);
$app['config']->set('database.default', 'runtime_concurrency');
$app['config']->set('database.connections.runtime_concurrency', $settings['connection']);
$app['config']->set('agent-graph.database.connection', 'runtime_concurrency');
$app['config']->set('agent-graph.execution.mode', 'sync');
$app['config']->set('agent-graph.execution.node_lease_seconds', 300);
$db = $app['db'];
$db->purge('runtime_concurrency');
$directory = dirname($argv[1]);

$signal = static function (string $name, array $data = []) use ($directory): void {
    $path = $directory.'/'.$name.'.json';
    file_put_contents($path.'.tmp', json_encode($data, JSON_THROW_ON_ERROR));
    rename($path.'.tmp', $path);
};
$wait = static function (string $name) use ($directory): void {
    $deadline = microtime(true) + 40;
    while (! is_file($directory.'/'.$name.'.json')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timed out at worker barrier '.$name);
        }
        usleep(10000);
        clearstatcache();
    }
};
$effect = static function (NodeContext $context, string $phase, ?string $item = null) use ($db, $worker): void {
    $db->table('test_effects')->insert([
        'run_id' => $context->runId(), 'worker' => $worker,
        'node' => $context->nodeId(), 'phase' => $phase, 'item' => $item,
    ]);
};

// flock is shared by independent processes and released by an OS-level kill.
// Takeover and resume bypass it to model an expired distributed run lock;
// node ownership is still the real PostgreSQL lease and claim token.
$locks = new class($directory, in_array($settings['scenario'], ['takeover', 'resume'], true)) implements LockProvider
{
    public function __construct(private string $directory, private bool $expired) {}

    public function withLock(string $key, Closure $callback): mixed
    {
        if ($this->expired) {
            return $callback();
        }
        $handle = fopen($this->directory.'/lock_'.hash('sha256', $key), 'c');
        if (! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to acquire process lock.');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
};
$interrupts = new class($db, $action === 'resume', function () use ($signal, $wait, $worker): void {
    $signal('read_'.$worker);
    $wait('admit_resumes');
}) extends DatabaseInterruptStore
{
    public function __construct($db, private bool $pause, private Closure $barrier)
    {
        parent::__construct($db);
    }

    public function pendingForRun(string $runId): ?array
    {
        $interrupt = parent::pendingForRun($runId);
        if ($this->pause && $interrupt !== null) {
            $this->pause = false;
            ($this->barrier)();
        }

        return $interrupt;
    }
};
$runtime = new GraphRuntime(
    container: $app,
    runs: new DatabaseRunStore($db),
    checkpoints: new DatabaseCheckpointStore($db),
    writes: new DatabaseWriteStore($db),
    tasks: new DatabaseTaskStore($db),
    interrupts: $interrupts,
    memory: new DatabaseMemoryStore($db),
    traces: new DatabaseTraceStore($db),
    locks: $locks,
    events: new RunEventDispatcher,
    nodeExecutions: new DatabaseNodeExecutionStore($db),
);

$scenario = $settings['scenario'];
$graph = StateGraph::make('postgres_'.$scenario)->state(['answer' => 'string', 'approved' => 'bool']);
if ($scenario === 'fanout') {
    $graph = StateGraph::make('postgres_fanout')
        ->state(['answers' => 'array', 'item' => 'string'])
        ->reducer('answers', Reducer::append())
        ->node('split', fn () => NodeResult::sendMany([
            Send::to('work', ['item' => 'A']), Send::to('work', ['item' => 'B']),
        ]))
        ->node('work', function (NodeContext $context) use ($worker, $effect, $signal, $wait) {
            $item = $context->state('item');
            $effect($context, 'entered', $item);
            if ($worker === 'original' && $item === 'B') {
                $signal('entered_'.$worker, ['run_id' => $context->runId()]);
                $wait('release_'.$worker);
            }
            $effect($context, 'finished', $item);

            return NodeResult::write(['answers' => [$item]]);
        })->edge(StateGraph::START, 'split')->edge('work', StateGraph::END);
} elseif ($scenario === 'resume') {
    $graph->node('approval', function (NodeContext $context) use ($effect, $worker, $signal, $wait) {
        if (! $context->hasResumePayload()) {
            return NodeResult::interrupt('input');
        }
        $effect($context, 'entered');
        $signal('entered_'.$worker, ['run_id' => $context->runId()]);
        $wait('release_resumes');

        return NodeResult::end(['approved' => true]);
    })->edge(StateGraph::START, 'approval');
} else {
    $graph->node('work', function (NodeContext $context) use ($worker, $effect, $signal, $wait) {
        $effect($context, 'entered');
        if ($worker === 'original') {
            $signal('entered_'.$worker, ['run_id' => $context->runId()]);
            $wait('release_'.$worker);
        }
        $effect($context, 'finished');

        return NodeResult::write(['answer' => $worker]);
    })->node('after', function (NodeContext $context) use ($effect) {
        $effect($context, 'entered');

        return NodeResult::end();
    })->edge(StateGraph::START, 'work')->edge('work', 'after');
}
$graph = $graph->compile();
$graphs = [$graph->key() => $graph];

try {
    if ($action === 'resume') {
        $signal('ready_'.$worker);
        $wait('start_resumes');
    }
    $result = match ($action) {
        'run' => $runtime->run($graph, 'postgres-concurrency'),
        'cancel' => $runtime->cancel($runId),
        'recover' => $runtime->recover($runId, $graphs),
        'resume' => $runtime->resume($runId, ['interrupt_id' => $interruptId, 'approved' => true], $graphs),
        default => throw new RuntimeException('Unknown worker action.'),
    };
    $signal('result_'.$worker, [
        'run_id' => $result->runId(), 'status' => $result->status(),
        'state' => $result->state(), 'interrupt' => $result->interrupt(),
    ]);
} catch (Throwable $exception) {
    $signal('error_'.$worker, ['class' => $exception::class, 'message' => $exception->getMessage()]);
    fwrite(STDERR, $exception."\n");
    exit(1);
}
