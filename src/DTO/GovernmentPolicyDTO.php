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
        /** How far the green belt stands between the founding planning regime (0) and the strictest on record (1). */
        public float $greenBeltStringency,
        /** The carbon price on power and industry, in dollars a tonne of CO2. */
        public float $carbonPrice,
        /** How far the rules on extraction stand between the founding ones (0) and the strictest on record (1). */
        public float $extractionStringency,
        /** Stamp duty on each side of a share trade, paid into the reserve fund. */
        public float $stampDutyRate,
        /** Bank levy on short-term funding, a year (half that on long-term funding). */
        public float $bankLevyRate,
        /** Nearness to the vote (0 at mid-term, 1 on its eve and through the talks after it) less its long-run mean. */
        public float $electionPulse,
    ) {}
}
