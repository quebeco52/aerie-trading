<?php

declare(strict_types=1);

namespace App\DTO;

use App\Service\Politics\Policy\Lever;

/**
 * What the government hands the economy each tick: the levers it has in force, and how far the election calendar
 * stands above its average pull on policy uncertainty. The macro engine reads nothing else of politics.
 */
final readonly class GovernmentPolicyDTO
{
    public function __construct(
        /**
         * @var array<string, float> The Diet's levers in force, keyed by Lever in budget order
         *                           (App\Service\Politics\Policy\BudgetRound::standingLevers()).
         */
        public array $levers,
        /** Where merger review stands between the 2023 guidelines (0) and the 2010 guidelines (1). */
        public float $mergerReviewLeniency,
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
         *                                Lever; null before they have been read.
         */
        public ?array $sittingLevers = null,
        /** When that round falls; null before it has been read. */
        public ?float $sittingPolicyFrom = null,
        /**
         * @var array<string, float>|null The levers the market expects from the next government's first budget
         *                                (App\Service\Politics\Diet\ElectionForecast), keyed the same way; null before it
         *                                has a forecast.
         */
        public ?array $expectedLevers = null,
        /**
         * When the market expects that budget: the first round after the next government takes office; null before it
         * has a forecast.
         */
        public ?float $expectedPolicyFrom = null,
    ) {}

    /** A lever in force. */
    public function lever(Lever $lever): float
    {
        return $this->levers[$lever->value];
    }
}
