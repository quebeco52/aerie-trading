<?php

declare(strict_types=1);

namespace App\Service\Model\Strategy;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\DTO\SectorCoverageProfile;
use App\Entity\Stock;
use App\Service\Math\MathUtility;

interface OperatingStrategyInterface
{
    public function getTargetMetrics(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility): array;
    public function computeActualFinancials(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): ActualFinancialsDTO;
    public function getCoverageProfile(Stock $stock): SectorCoverageProfile;
    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array;
    /**
     * MacroStateDTO field names (snake_case, matching MacroStateDTO::toArray()) this model's own
     * operating code reads: calculateSectorPhysics(), getMacroPhysics(), calculateInterestIncome(),
     * processPassiveLiabilityGrowth(), getForwardCreditLossMultiplier() and the helpers they call,
     * following parent:: delegation.
     * Generic trait physics is not model-specific coupling and does not count. Valuation-only reads
     * feeding WACC alone (equityRiskPremium, corporateTaxRate, policyRate) are excluded, so this
     * stays a genuine operating-coupling declaration rather than everything a model touches.
     *
     * Consumed by App\Service\District\DistrictConduitResolver to derive which district
     * institutions draw a conduit to this model — see App\Data\DistrictMap's class docblock.
     * BusinessModelMacroFieldDeclarationTest enforces that the declaration matches the source.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array;
    public function getEffectiveTaxRate(float $macroTaxRate): float;
    public function calculateEconomicReturn(Stock $stock, float $nopat, float $investedCapital): float;
    /**
     * @param float $depreciation Quarterly depreciation already deducted from $ebit, for models whose return
     *                             is defined before it (a REIT's cap rate is struck on net operating income).
     */
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate, float $wacc = 0.08, float $costOfEquity = 0.10, ?\App\DTO\MacroStateDTO $macroState = null, float $depreciation = 0.0): float;
    public function getSecularGrowthRate(Stock $stock): float;
    public function getCapexCyclicality(): float;
    public function getSurpriseBlendWeights(): array;
    public function getEffectiveReturn(Stock $stock): float;
    public function getTrueReturn(Stock $stock): float;
    public function getEvaluationCapital(float $equity, float $investedCapital): float;
    public function getWorkingCapitalIntensity(Stock $stock): float;
    /**
     * The cash conversion cycle split into its three day counts. Working capital is carried as real
     * balances, and the risks attach to the parts rather than the total: a receivable can go bad, and
     * inventory can be worth less than it cost. Defaulted from getWorkingCapitalIntensity() so a sector
     * model only overrides this when its cycle is shaped unusually.
     *
     * @return array{dso: float, dio: float, dpo: float}
     */
    public function getWorkingCapitalDays(Stock $stock): array;
    /**
     * Labor's share of the fixed cost base (salaried staff, SG&A payroll). Scales how much of an excess
     * wage-growth impulse reaches fixed costs: a law firm or software house feels nearly all of it, a
     * utility or pipeline operator very little. Variable-cost labor (kitchen crews, crews on site) is
     * modelled inside each sector's own physics, not here.
     */
    public function getLaborCostShare(): float;

    /** Propensity of this sector's management to steer reported earnings toward consensus with accruals (EARNINGS_MANAGEMENT_PROPENSITY). */
    public function getEarningsManagementPropensity(Stock $stock): float;

    /** Share of revenue whose competitiveness moves with the trade-weighted exchange rate (FX_REVENUE_EXPOSURE). */
    /** Years for a move in the output gap to reach this firm's order book (DEMAND_LAG_YEARS). */
    public function getDemandLagYears(): float;

    /** The output gap as it has actually reached the firm, distributed over its transmission lag. */
    public function resolveLaggedOutputGap(Stock $stock, MacroStateDTO $macroState): float;

    public function getFxRevenueExposure(): float;

    /** Signed demand shift from the trade-weighted exchange rate, at the model's exposure or a channel-level override. */
    public function resolveFxDemandShift(MacroStateDTO $macroState, ?float $exposure = null): float;
    /**
     * Capitalized operating lease liabilities (IFRS 16 / ASC 842) as a fraction of annual revenue. Rent is
     * already inside fixed costs, so leases add NO interest; they add debt-like obligations to leverage,
     * coverage and solvency metrics (restaurants, retailers, airlines and hospitals look unlevered otherwise).
     */
    public function getLeaseIntensity(): float;
    /**
     * Stock-based compensation (ASC 718) as a fraction of revenue. It is already inside the cost base, so it
     * changes no margin; it is a non-cash expense added back to free cash flow and settled in new shares,
     * which is why software and biotech report FCF above earnings and dilute a few percent a year.
     */
    public function getStockCompensationIntensity(): float;
    /**
     * Calendar quarter (0 = Jan-Mar .. 3 = Oct-Dec) in which the fiscal year begins. Seasonality stays on the
     * calendar; the fiscal quarter drives annual events such as the goodwill impairment test.
     */
    public function getFiscalYearStartQuarter(Stock $stock): int;
    public function getCapExCompletionRate(Stock $stock): float;
    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void;
    public function getMarginReversionSpeed(): float;
    public function getReversionSpeed(): float;
    public function getMoatSpread(): float;
    public function getPhysicalCapital(Stock $stock): float;
    /**
     * The asset account depreciation is charged against. Physical businesses depreciate net PP&E only:
     * goodwill is not depreciated (it is impairment-tested) and working capital does not wear out, so
     * charging depreciation on invested capital overstated it for every acquisitive or inventory-heavy
     * firm. Financial balance sheets have no meaningful plant, so they keep their capital proxy.
     */
    public function getDepreciableBase(Stock $stock): float;
    public function allowsPhysicalOrganicCapex(): bool;
    public function getReturnBasisIncome(Stock $stock, float $quarterlyNopat, float $actualTotalNetIncome): float;
    /**
     * @return array<int, float> Quarterly revenue seasonality multipliers [Q1, Q2, Q3, Q4] summing to 4.0.
     */
    public function getSeasonalityFactors(): array;
    /**
     * Annual through-the-cycle expected credit loss on gross earning assets: the charge-off rate the
     * stable cost base already carries. Zero for any business whose assets are not credit.
     *
     * Pass the firm whenever one is in hand: a lender whose underwriting posture is tuned per company
     * resolves a firm-specific long-run default rate, and falls back to the sector rate without it.
     */
    public function getThroughTheCycleCreditLossRate(?Stock $stock = null): float;
    /** Years of expected loss the credit-loss allowance covers (ASC 326 lifetime horizon). */
    public function getCreditLossHorizonYears(): float;
    /**
     * Multiplier on the lifetime expected-loss estimate for the macro outlook the lender reserves against
     * (ASC 326 reasonable-and-supportable forecast). 1.0 at the through-the-cycle outlook; above it the
     * allowance target rises and the roll-forward books a BUILD once, then nothing more while the outlook
     * stays there; below it reserves are released. A balance, not a flow: this is what makes a forward
     * reserve news when the outlook changes rather than a cost charged every quarter it stays bad.
     */
    public function getForwardCreditLossMultiplier(?Stock $stock, MacroStateDTO $macroState): float;
    /**
     * Fraction of an idiosyncratic revenue gain that is market share taken from same-industry rivals (and,
     * symmetrically, the fraction of a rival's gain this firm loses). 1.0 is a fixed pie fought over by
     * substitutes; 0.0 a firm whose volume is sold into a global pool that peers never notice.
     */
    public function getIndustrySubstitutability(): float;
    /** Own-price elasticity of demand applied to real price changes (price growth above expected inflation). */
    public function getPriceElasticityOfDemand(): float;
    /**
     * Operating cyclicality: how strongly the firm's volumes, pricing and input costs respond to the macro
     * cycle (1.0 = moves one for one with the output gap). This is an operating elasticity declared per
     * sector and tunable per ticker; equity beta is a market statistic that the pricing engine derives
     * from it and from leverage, never an input to the operating physics.
     */
    public function getOperatingCyclicality(Stock $stock): float;
}
