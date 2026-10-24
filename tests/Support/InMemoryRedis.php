<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Redis as far as the stores under test use it: string keys held in memory, set with its only-if-absent ('nx') and
 * only-if-present ('xx') options, and flushAll. The bootstrap's stand-in forgets every write; this one keeps them.
 * Parameters are typed loosely so the class extends both the extension and the bootstrap's stub.
 */
final class InMemoryRedis extends \Redis
{
    /** @var array<string, string> */
    private array $store = [];

    public function get(mixed $key): mixed
    {
        return $this->store[(string) $key] ?? false;
    }

    public function set(string $key, mixed $value, mixed $options = null): bool
    {
        $flags = is_array($options) ? $options : [];
        if (in_array('xx', $flags, true) && !isset($this->store[$key])) {
            return false;
        }
        if (in_array('nx', $flags, true) && isset($this->store[$key])) {
            return false;
        }
        $this->store[$key] = (string) $value;

        return true;
    }

    public function flushAll(?bool $sync = null): bool
    {
        $this->store = [];

        return true;
    }
}
