<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * What the government hands the economy each tick: the levers it has in force, and how far the election calendar
 * stands above its average pull on policy uncertainty. The macro engine reads nothing else of politics.
 */
final readonly class GovernmentPolicyDTO
{
    public function __construct(
        /** The corporate rate's shift from the neutral rate. */
        public float $corporateTaxPolicyShift,
        /** Average tariff on imports. */
        public float $importTariffRate,
        /** Labour force growth, the immigration regime in it. */
        public float $laborForceGrowthRate,
        /** Where merger review stands between the 2023 guidelines (0) and the 2010 guidelines (1). */
        public float $mergerReviewLeniency,
        /** Nearness to the vote (0 at mid-term, 1 on its eve and through the talks after it) less its long-run mean. */
        public float $electionPulse,
    ) {}
}
