<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Data\Company\Sectors;

/**
 * The business-model registry as a service, for code that takes its models by injection. It keeps no list of its
 * own: every identifier resolves through Sectors::BUSINESS_MODELS to the same instance the static lookup hands out,
 * so the injected and the static paths cannot disagree about which model an identifier runs.
 */
class BusinessModelRegistry implements BusinessModelRegistryInterface
{
    public function get(string $identifier): BusinessModelInterface
    {
        return Sectors::getBusinessModelStrategy($identifier);
    }

    public function has(string $identifier): bool
    {
        return isset(Sectors::BUSINESS_MODELS[$identifier]);
    }

    public function all(): array
    {
        $models = [];
        foreach (array_keys(Sectors::BUSINESS_MODELS) as $identifier) {
            $models[$identifier] = Sectors::getBusinessModelStrategy($identifier);
        }

        return $models;
    }
}
