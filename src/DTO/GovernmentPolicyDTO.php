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
        /** Government purchases as a share of GDP: the level the purchases process reverts to. */
        public float $publicSpendingShare,
        /** The corporate rate's shift from the neutral rate. */
        public float $corporateTaxPolicyShift,
        /** Average tariff on imports. */
        public float $importTariffRate,
        /** Labour force growth, the immigration regime in it. */
        public float $laborForceGrowthRate,
        /** Tax on a home bought by a buyer from outside the District, a share of the price. */
        public float $foreignBuyerTaxRate,
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
        /**
         * Share of the reserve fund's expected long-term real return the budget spends
         * (MacroConstants::RESERVE_DRAW_CEILING without the fund's consent).
         */
        public float $reserveDrawShare,
        /**
         * Share of the surplus the debt calls for (App\Service\Macro\Subsystem\CreditFiscalSubsystem::fiscalStance())
         * raised by tax rather than by cutting purchases, and of the room under the line spent on purchases rather than
         * tax cuts.
         */
        public float $consolidationTaxShare,
        /** Share of the reserve fund's equities held on the District's own board. */
        public float $reserveHomeEquityShare,
        /** Share of a utility's rate request the utility commission grants. */
        public float $utilityRateCaseGrantShare,
        /** Tax on the dividends the District's own shareholders are paid. */
        public float $dividendTaxRate,
        /** Nearness to the vote (0 at mid-term, 1 on its eve and through the talks after it) less its long-run mean. */
        public float $electionPulse,
        /**
         * The Monetary Authority's rate committee's supermajority: 1 hawkish, -1 dovish, 0 neither; null before the
         * committee has formed.
         */
        public ?float $authorityMajority = null,
        /**
         * The CET1 requirement on the District's banks in force, as a share of risk-weighted assets; null before the
         * Financial Regulator has a head.
         */
        public ?float $bankCapitalRequirement = null,
        /**
         * The mortgage loan-to-value cap on the District's lenders in force, as a share of the property's value; null
         * for none.
         */
        public ?float $mortgageLtvCap = null,
        /**
         * The countercyclical buffer the Financial Regulator's head holds when credit is not running ahead, as a share
         * of risk-weighted assets.
         */
        public float $neutralCountercyclicalBuffer = 0.0,
        /**
         * The policy equity share the Sovereign Reserve Fund's head has set; null while the head sitting at Year 1
         * keeps the mix the fund opened with.
         */
        public ?float $reserveFundEquityShare = null,
        /**
         * The initial margin the Securities Commission requires of an investor buying or shorting on credit; null for
         * the founding Regulation T margin.
         */
        public ?float $initialMarginRequirement = null,
        /**
         * The multiplier the Commission's enforcement puts on each firm's propensity to manage its reported earnings;
         * null for none.
         */
        public ?float $earningsManagementScale = null,
        /**
         * The average maturity, in years, the Exchequer's head steers the debt toward; null while the head sitting at
         * Year 1 keeps the book the debt opened with.
         */
        public ?float $debtAverageMaturity = null,
        /**
         * @var array<string, float>|null The levers the sitting government will pass at its next budget round, keyed as
         *                                App\Service\Politics\PoliticsEngine::LEVER_FIELDS; null before they have been
         *                                read.
         */
        public ?array $sittingLevers = null,
        /** When that round falls; null before it has been read. */
        public ?float $sittingPolicyFrom = null,
        /**
         * @var array<string, float>|null The levers the market expects from the next government's first budget
         *                                (App\Service\Politics\ElectionForecast), keyed the same way; null before it
         *                                has a forecast.
         */
        public ?array $expectedLevers = null,
        /**
         * When the market expects that budget: the first round after the next government takes office; null before it
         * has a forecast.
         */
        public ?float $expectedPolicyFrom = null,
        /** Gross debt to GDP the Diet's budget steers toward. */
        public float $debtTarget = \App\Service\Macro\Subsystem\CreditFiscalSubsystem::FOUNDING_DEBT_TARGET,
        /** Points of GDP a year the budget moves debt toward that target. */
        public float $debtPace = \App\Service\Macro\Subsystem\CreditFiscalSubsystem::FOUNDING_DEBT_PACE,
    ) {}
}
