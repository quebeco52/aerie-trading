<?php

declare(strict_types=1);

namespace App\Service\Model;

/**
 * Contract for resolving business model financial physics strategies.
 */
interface BusinessModelRegistryInterface
{
    /**
     * Retrieves the business model strategy by its identifier.
     *
     * @param string $identifier E.g. 'commercial_bank', 'tech', 'biotech', 'none'
     */
    public function get(string $identifier): BusinessModelInterface;

    /**
     * Checks if a specific business model identifier is registered.
     */
    public function has(string $identifier): bool;

    /**
     * Returns all registered business model strategies.
     *
     * @return array<string, BusinessModelInterface>
     */
    public function all(): array;
}
