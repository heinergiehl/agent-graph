<?php

namespace Heiner\AgentGraph\State;

use Closure;
use InvalidArgumentException;

class StateReducer
{
    public function __construct(protected array $reducers = [])
    {
        foreach ($this->reducers as $channel => $reducer) {
            $this->reducers[$channel] = $this->normalize($reducer);
        }
    }

    public function applyMany(array $state, array $writes): array
    {
        foreach ($writes as $write) {
            $state = $this->apply($state, $write);
        }

        return $state;
    }

    public function apply(array $state, array $write): array
    {
        foreach ($write as $channel => $value) {
            $reducer = $this->reducers[$channel] ?? Reducer::lastWriteWins();
            $state[$channel] = $reducer->apply($state[$channel] ?? null, $value);
        }

        return $state;
    }

    protected function normalize(mixed $reducer): Reducer
    {
        if ($reducer instanceof Reducer) {
            return $reducer;
        }

        if ($reducer instanceof Closure) {
            return Reducer::custom($reducer);
        }

        return match ($reducer) {
            'append' => Reducer::append(),
            'merge' => Reducer::merge(),
            'messages', 'add_messages' => Reducer::addMessages(),
            'max', 'max_confidence' => Reducer::maxConfidence(),
            default => throw new InvalidArgumentException("Unknown reducer [{$reducer}]."),
        };
    }
}
