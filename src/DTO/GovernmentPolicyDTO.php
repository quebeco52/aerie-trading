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
        /** Share of the reserve fund's expected long-term real return the budget spends (MacroEngine::RESERVE_DRAW_CEILING without the fund's consent). */
        public float $reserveDrawShare,
        /** Nearness to the vote (0 at mid-term, 1 on its eve and through the talks after it) less its long-run mean. */
        public float $electionPulse,
        /** The Monetary Authority's rate committee's supermajority: 1 hawkish, -1 dovish, 0 neither; null before the committee has formed. */
        public ?float $authorityMajority = null,
        /** The CET1 requirement on the District's banks in force, as a share of risk-weighted assets; null before the Financial Regulator has a head. */
        public ?float $bankCapitalRequirement = null,
        /** The mortgage loan-to-value cap on the District's lenders in force, as a share of the property's value; null for none. */
        public ?float $mortgageLtvCap = null,
        /** The policy equity share the Sovereign Reserve Fund's head has set; null while the head sitting at Year 1 keeps the mix the fund opened with. */
        public ?float $reserveFundEquityShare = null,
        /** 1 while the Monetary Authority is giving ground to the cabinet's pressure, else 0; null before the Authority has formed. */
        public ?float $authorityConcession = null,
        /** @var array<string, float>|null The levers the sitting government will pass at its next budget round, keyed as App\Service\Politics\PoliticsEngine::LEVER_FIELDS; null before they have been read. */
        public ?array $sittingLevers = null,
        /** When that round falls; null before it has been read. */
        public ?float $sittingPolicyFrom = null,
        /** @var array<string, float>|null The levers the market expects from the next government's first budget (App\Service\Politics\ElectionForecast), keyed the same way; null before it has a forecast. */
        public ?array $expectedLevers = null,
        /** When the market expects that budget: the first round after the next government takes office; null before it has a forecast. */
        public ?float $expectedPolicyFrom = null,
    ) {}
}
