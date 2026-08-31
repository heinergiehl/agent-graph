<?php

use Heiner\AgentGraph\Contracts\LeasingTaskStore;
use Heiner\AgentGraph\Events\GraphTaskCompleted;
use Heiner\AgentGraph\Exceptions\TaskClaimLostException;
use Heiner\AgentGraph\Persistence\DatabaseTaskStore;
use Heiner\AgentGraph\Persistence\InMemoryTaskStore;
use Heiner\AgentGraph\Runtime\TaskRunner;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\Query\Builder;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();
    config()->set('agent-graph.tasks.lease_seconds', 60);
});

it('rejects an overlapping task claim while its winning lease is active', function (string $driver) {
    $store = makeTaskOwnershipStore($driver, pausing: true);
    $store->pauseNextClaim = true;
    $effects = [];
    $runnerA = new TaskRunner($store, 'run_a', 'node');
    $runnerB = new TaskRunner($store, 'run_b', 'node');
    $workerA = new Fiber(function () use ($runnerA, &$effects) {
        return $runnerA->once('shared-task', ['id' => 1], function () use (&$effects) {
            $effects[] = 'a';

            return 'receipt-a';
        });
    });
    $workerB = new Fiber(function () use ($runnerB, &$effects) {
        return $runnerB->once('shared-task', ['id' => 1], function () use (&$effects) {
            $effects[] = 'b';
            Fiber::suspend();

            return 'receipt-b';
        });
    });

    $workerA->start();
    $workerB->start();
    expect($store->activeLeaseUntil($store->findByKey('shared-task')))->not->toBeNull();

    try {
        expect(fn () => $workerA->resume())->toThrow(RuntimeException::class, 'already running');
    } finally {
        $workerB->resume();
    }

    expect($effects)->toBe(['b'])
        ->and($store->findByKey('shared-task')['attempts'])->toBe(1)
        ->and($store->findByKey('shared-task')['result'])->toBe('receipt-b');
})->with(['database', 'memory']);

it('reuses a receipt completed while another caller was waiting to claim', function (string $driver) {
    $store = makeTaskOwnershipStore($driver, pausing: true);
    $store->pauseNextClaim = true;
    $effects = 0;
    $runner = new TaskRunner($store, 'run', 'node');
    $worker = new Fiber(function () use ($runner, &$effects) {
        return $runner->once('completed-race', [], function () use (&$effects) {
            $effects++;

            return 'duplicate';
        });
    });

    $worker->start();
    $receipt = $runner->once('completed-race', [], function () use (&$effects) {
        $effects++;

        return 'original';
    });
    $worker->resume();

    expect($effects)->toBe(1)
        ->and($worker->getReturn())->toBe($receipt)
        ->and($store->findByKey('completed-race')['attempts'])->toBe(1);
})->with(['database', 'memory']);

it('keeps a committed receipt when its completion observer throws', function (string $driver) {
    $store = makeTaskOwnershipStore($driver);
    $runner = new TaskRunner($store, 'run', 'node');
    $effects = 0;
    $handler = function () use (&$effects): array {
        return ['receipt' => ++$effects];
    };
    app('events')->listen(GraphTaskCompleted::class, function (): void {
        throw new RuntimeException('Completion observer is unavailable.');
    });

    try {
        expect(fn () => $runner->once('observer-task', [], $handler))
            ->toThrow(RuntimeException::class, 'Completion observer is unavailable.');
    } finally {
        app('events')->forget(GraphTaskCompleted::class);
    }

    expect($store->findByKey('observer-task')['status'])->toBe('completed')
        ->and($store->findByKey('observer-task')['result'])->toBe(['receipt' => 1])
        ->and($runner->once('observer-task', [], $handler))->toBe(['receipt' => 1])
        ->and($effects)->toBe(1);
})->with(['database', 'memory']);

it('fences completion and failure from a replaced task attempt', function (string $driver) {
    $store = makeTaskOwnershipStore($driver);
    $first = $store->start('replaced-task', 'input-hash', []);
    $this->travel(61)->seconds();
    $second = $store->start('replaced-task', 'input-hash', []);

    expect($second['attempts'])->toBe($first['attempts'] + 1);
    expect(fn () => $store->complete('replaced-task', $first['attempts'], 'stale-result'))
        ->toThrow(TaskClaimLostException::class);
    expect(fn () => $store->fail('replaced-task', $first['attempts'], 'stale-error'))
        ->toThrow(TaskClaimLostException::class);
    expect($store->findByKey('replaced-task')['status'])->toBe('running')
        ->and($store->findByKey('replaced-task')['result'])->toBeNull()
        ->and($store->findByKey('replaced-task')['error'])->toBeNull();

    $store->complete('replaced-task', $second['attempts'], 'current-result');

    expect(fn () => $store->fail('replaced-task', $first['attempts'], 'late-error'))
        ->toThrow(TaskClaimLostException::class);
    expect($store->findByKey('replaced-task')['status'])->toBe('completed')
        ->and($store->findByKey('replaced-task')['result'])->toBe('current-result');
})->with(['database', 'memory']);

it('does not let a late task runner overwrite the replacement receipt', function (string $driver) {
    $store = makeTaskOwnershipStore($driver);
    $runner = new TaskRunner($store, 'run', 'node');
    $worker = new Fiber(fn () => $runner->once('late-worker', [], function () {
        Fiber::suspend();

        return 'stale-receipt';
    }));

    $worker->start();
    $this->travel(61)->seconds();
    expect($runner->once('late-worker', [], fn () => 'current-receipt'))->toBe('current-receipt');
    expect(fn () => $worker->resume())->toThrow(TaskClaimLostException::class);
    expect($store->findByKey('late-worker')['status'])->toBe('completed')
        ->and($store->findByKey('late-worker')['result'])->toBe('current-receipt')
        ->and($store->findByKey('late-worker')['attempts'])->toBe(2);
})->with(['database', 'memory']);

it('returns completed store claims unchanged and rejects changed input', function (string $driver) {
    $store = makeTaskOwnershipStore($driver);
    $claim = $store->start('completed-task', 'first-input', []);
    $completed = $store->complete('completed-task', $claim['attempts'], ['ok' => true]);

    expect($store->start('completed-task', 'first-input', []))->toBe($completed);
    expect(fn () => $store->start('completed-task', 'different-input', []))
        ->toThrow(RuntimeException::class, 'different input');
})->with(['database', 'memory']);

it('retries a failed handler using a new fenced attempt', function (string $driver) {
    $store = makeTaskOwnershipStore($driver);
    $runner = new TaskRunner($store, 'run', 'node');

    expect(fn () => $runner->once('failed-task', [], fn () => throw new RuntimeException('Handler failed.')))
        ->toThrow(RuntimeException::class, 'Handler failed.');
    expect($store->findByKey('failed-task')['status'])->toBe('failed');
    expect($runner->once('failed-task', [], fn () => 'recovered'))->toBe('recovered')
        ->and($store->findByKey('failed-task')['attempts'])->toBe(2)
        ->and($store->findByKey('failed-task')['error'])->toBeNull();
})->with(['database', 'memory']);

it('retries a conflicting first insert after rolling back its own savepoint', function () {
    $store = new DatabaseTaskStore(app('db'));
    $claim = $store->start('insert-race', 'same-input', []);
    $store->complete('insert-race', $claim['attempts'], 'winning-receipt');
    $racingStore = new FirstReadMissingTaskOwnershipStore(app('db'));
    $connection = app('db')->connection();
    $rollbackLevels = [];
    app('events')->listen(TransactionRolledBack::class, function (TransactionRolledBack $event) use (&$rollbackLevels): void {
        $rollbackLevels[] = $event->connection->transactionLevel();
    });

    $connection->transaction(function () use ($connection, $racingStore, $store): void {
        $task = $racingStore->start('insert-race', 'same-input', []);

        expect($connection->transactionLevel())->toBe(1)
            ->and($task['status'])->toBe('completed')
            ->and($task['attempts'])->toBe(1)
            ->and($task['result'])->toBe('winning-receipt');

        $store->start('outer-transaction-survived', 'other-input', []);
    });

    expect($rollbackLevels)->toBe([1])
        ->and($store->findByKey('outer-transaction-survived'))->not->toBeNull()
        ->and($store->findByKey('insert-race')['result'])->toBe('winning-receipt');
});

function makeTaskOwnershipStore(string $driver, bool $pausing = false): LeasingTaskStore
{
    if ($driver === 'database') {
        return $pausing ? new PausingDatabaseTaskOwnershipStore(app('db')) : new DatabaseTaskStore(app('db'));
    }

    return $pausing ? new PausingInMemoryTaskOwnershipStore : new InMemoryTaskStore;
}

trait PausesNextTaskOwnershipClaim
{
    public bool $pauseNextClaim = false;

    public function start(string $key, string $inputHash, array $input, array $context = []): array
    {
        if ($this->pauseNextClaim) {
            $this->pauseNextClaim = false;
            Fiber::suspend();
        }

        return parent::start($key, $inputHash, $input, $context);
    }
}

class PausingDatabaseTaskOwnershipStore extends DatabaseTaskStore
{
    use PausesNextTaskOwnershipClaim;
}

class PausingInMemoryTaskOwnershipStore extends InMemoryTaskStore
{
    use PausesNextTaskOwnershipClaim;
}

class FirstReadMissingTaskOwnershipStore extends DatabaseTaskStore
{
    public bool $hideNextRead = true;

    protected function query(): Builder
    {
        return (new FirstReadMissingTaskOwnershipQuery($this->connection(), $this))->from($this->table());
    }
}

class FirstReadMissingTaskOwnershipQuery extends Builder
{
    public function __construct(ConnectionInterface $connection, private FirstReadMissingTaskOwnershipStore $store)
    {
        parent::__construct($connection);
    }

    public function first($columns = ['*'])
    {
        // Model a caller that read before a competing insert committed. The
        // losing insert and transaction/savepoint rollback use the real DB.
        if ($this->store->hideNextRead) {
            $this->store->hideNextRead = false;

            return null;
        }

        return parent::first($columns);
    }
}
