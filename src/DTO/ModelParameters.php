<?php

declare(strict_types=1);

namespace App\DTO;

use App\Data\ModelParam;

/**
 * Strongly-typed parameter bag for business model physics tuning.
 * Provides type-safe parameter resolution with fallback defaults and ArrayAccess support.
 *
 * @implements \ArrayAccess<ModelParam|string, float>
 */
class ModelParameters implements \ArrayAccess, \Countable
{
    /**
     * @param array<string, float> $parameters
     */
    public function __construct(
        private array $parameters = []
    ) {}

    /**
     * Creates a ModelParameters instance from a raw array with ModelParam or string keys.
     *
     * @param array<ModelParam|string, float> $raw
     */
    public static function from(array $raw): self
    {
        $normalized = [];
        foreach ($raw as $key => $value) {
            $stringKey = $key instanceof ModelParam ? $key->value : (string) $key;
            $normalized[$stringKey] = (float) $value;
        }

        return new self($normalized);
    }

    /**
     * Retrieves a parameter value by ModelParam or string key, with optional fallback default.
     * Throws \OutOfBoundsException if key is missing and no default is provided.
     */
    public function get(ModelParam|string $param, ?float $default = null): float
    {
        $key = $param instanceof ModelParam ? $param->value : (string) $param;

        if (array_key_exists($key, $this->parameters)) {
            return $this->parameters[$key];
        }

        if ($default !== null) {
            return $default;
        }

        throw new \OutOfBoundsException(sprintf("Model parameter '%s' was not resolved and has no default.", $key));
    }

    /**
     * Retrieves a parameter value as a float with a default fallback.
     */
    public function getFloat(ModelParam|string $param, float $default = 0.0): float
    {
        return $this->get($param, $default);
    }

    /**
     * Checks if a parameter key exists in this container.
     */
    public function has(ModelParam|string $param): bool
    {
        $key = $param instanceof ModelParam ? $param->value : (string) $param;
        return array_key_exists($key, $this->parameters);
    }

    /**
     * Returns all resolved parameters as an associative array.
     *
     * @return array<string, float>
     */
    public function all(): array
    {
        return $this->parameters;
    }

    // --- ArrayAccess implementation ---

    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset instanceof ModelParam ? $offset : (string) $offset);
    }

    public function offsetGet(mixed $offset): float
    {
        return $this->get($offset instanceof ModelParam ? $offset : (string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $key = $offset instanceof ModelParam ? $offset->value : (string) $offset;
        $this->parameters[$key] = (float) $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        $key = $offset instanceof ModelParam ? $offset->value : (string) $offset;
        unset($this->parameters[$key]);
    }

    // --- Countable implementation ---

    public function count(): int
    {
        return count($this->parameters);
    }
}
