<?php

declare(strict_types=1);

namespace App\Service\District;

use App\Data\DistrictMap;
use App\DTO\MacroStateDTO;

/**
 * Decides which district institutions are "stressed" — the gate for which macro conduits render
 * as alarmed on a ward at rest, before any building or institution is selected.
 *
 * Rule-driven from DistrictMap::INSTITUTIONS[*]['stress_rules'], the same declaration the
 * district_controller.js Stimulus value ships to the client, so the two runtimes evaluate
 * identical rules instead of two hand-mirrored implementations. Every threshold in those rules
 * reuses one the simulation itself already treats as a distress signal — see that constant's
 * docblock — with exactly one documented exception (the Land Registry's property-index
 * deviation). An institution with no `stress_rules` simply never renders stressed; see
 * DistrictMap::INSTITUTIONS' docblock for why that is the honest reading of the rule rather than
 * a gap to fill.
 */
class DistrictStressEvaluator
{
    /**
     * @return array<string, bool> institution id => stressed
     */
    public function evaluate(MacroStateDTO $macro): array
    {
        $macroArray = $macro->toArray();

        $stress = [];
        foreach (DistrictMap::INSTITUTIONS as $institutionId => $institution) {
            $stress[$institutionId] = $this->anyRuleTriggered($institution['stress_rules'] ?? [], $macroArray);
        }

        return $stress;
    }

    /**
     * @param list<array{field: string, op: string, value: float}> $rules
     * @param array<string, mixed>                                 $macroArray
     */
    private function anyRuleTriggered(array $rules, array $macroArray): bool
    {
        foreach ($rules as $rule) {
            if ($this->ruleTriggered($rule, $macroArray)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{field: string, op: string, value: float} $rule
     * @param array<string, mixed>                            $macroArray
     */
    private function ruleTriggered(array $rule, array $macroArray): bool
    {
        $value = $macroArray[$rule['field']] ?? null;
        if (!is_float($value) && !is_int($value)) {
            return false;
        }

        return match ($rule['op']) {
            DistrictMap::OP_GTE => $value >= $rule['value'],
            DistrictMap::OP_LTE => $value <= $rule['value'],
            DistrictMap::OP_LT => $value < $rule['value'],
            DistrictMap::OP_INDEX_DEVIATION => abs($value - 100.0) / 100.0 >= $rule['value'],
            default => false,
        };
    }
}
