<?php

declare(strict_types=1);

namespace App\Service\District;

use App\Data\DistrictMap;
use App\Service\Model\BusinessModelRegistryInterface;

/**
 * Derives which district institutions a business model draws a conduit from.
 *
 * A conduit exists only where the model's own declared operating-macro fields
 * (App\Service\Model\Strategy\OperatingStrategyInterface::getOperatingMacroFields()) genuinely
 * intersect one of the institution's published fields — see App\Data\DistrictMap's class
 * docblock for the rule this enforces, and DistrictMap::UBIQUITOUS_MACRO_FIELDS for why a
 * handful of near-universal fields are excluded from the intersection.
 */
class DistrictConduitResolver
{
    public function __construct(
        private readonly BusinessModelRegistryInterface $registry,
    ) {
    }

    /**
     * @return list<string> institution ids this business model draws a conduit from
     */
    public function conduitsForBusinessModel(string $businessModel): array
    {
        $model = $this->registry->get($businessModel);
        $ownFields = array_diff($model->getOperatingMacroFields(), DistrictMap::UBIQUITOUS_MACRO_FIELDS);

        $conduits = [];
        foreach (DistrictMap::INSTITUTIONS as $institutionId => $institution) {
            if (array_intersect($ownFields, $institution['fields']) !== []) {
                $conduits[] = $institutionId;
            }
        }

        return $conduits;
    }
}
