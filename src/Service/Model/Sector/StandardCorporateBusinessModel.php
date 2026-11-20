<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Company\IndustryBetas;
use App\Data\Company\IndustryMoats;
use App\Data\Company\IndustryTurnovers;
use App\Data\Company\ManagementStyle;
use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Market\Pricing\SeedRows;
use App\Service\Math\Decimal;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Math\Valuation;
use App\Service\Model\AbstractBusinessModel;

/**
 * Earnings strategy for normal, non-financial companies.
 *
 * Financial Physics:
 * - Evaluated on Return on Invested Capital (ROIC).
 * - Subject to supply chain inflation and physical depreciation.
 * - Operating scale is based on physical assets, not financial leverage.
 */
class StandardCorporateBusinessModel extends AbstractBusinessModel
{
    // --- ROIC & Target Metrics ---
    /** Weight given to historical baseline ROIC when blending with TTM ROIC. */
    public const BASELINE_ROIC_WEIGHT = 0.50;
    /** Weight given to TTM ROIC when blending with historical baseline ROIC. */
    public const TTM_ROIC_WEIGHT      = 0.50;

    // --- Opening Books ---
    /** Share of an operating firm's opening equity that is retained earnings (half), where its row states none. */
    public const OPENING_RETAINED_SHARE = 0.50;
    /** Relative gap between the revenue the books earn and the stated revenue below which the solve stops. */
    public const OPENING_BOOK_TOLERANCE = 1.0e-9;
    /** Most passes of the opening solve; the books are near scale-free, so a few suffice. */
    public const MAX_OPENING_BOOK_PASSES = 12;
    /** Decimal places the derived opening margin is rounded to, matching Stock::$operatingMargin (NUMERIC(6,4)). */
    public const OPENING_MARGIN_SCALE = 4;
    /** What an operating firm's seed row states when its books are built from its character (resolveOpeningBooks()). */
    public const OPENING_CHARACTER_KEYS = ['revenue', 'addressable_share'];
    /** What resolveOpeningBooks() builds, so a character row that also states one of them is refused. */
    public const OPENING_BOOK_KEYS = [
        'baseline_roic', 'operating_margin', 'total_equity', 'wholesale_debt', 'customer_deposits', 'corporate_treasury',
        'retained_earnings', 'sam_ratio',
    ];

    // --- Pricing Power & Macro Physics ---
    /**
     * Base of the demand's sensitivity to the lagged gap before the firm's pricing power is added: (0.50 + pricing
     * power) x cyclicality, so the median firm (pricing power 0.5) answers the gap one for one.
     */
    public const CYCLE_SENSITIVITY_BASE = 0.50;

    // --- Inflation Pass-Through (Gopinath & Itskhoki 2010) ---
    /**
     * Pass-through elasticity of a pure price taker; the pricing power index adds to it, so the median firm recovers
     * expected inflation exactly and a price setter one and a half times over.
     */
    public const PASS_THROUGH_BASE_ELASTICITY = 0.50;
    /**
     * Characteristic time in years for expected inflation to reach selling prices through menu costs and contract
     * repricing (Nakamura & Steinsson 2008 price durations). Sector models override for annual tariffs or multi-year
     * contracts.
     */
    public const PRICE_PASS_THROUGH_LAG_YEARS = 0.75;
    /**
     * Elasticity of selling prices to expected inflation for a model that prices off a contractual or regulated
     * formula; null derives it from pricing power (the base elasticity plus the pricing power index).
     *
     * @var float|null
     */
    public const PRICING_ELASTICITY = null;

    // --- Revenue & Shock Physics ---
    /** Variance scalar applied to baseline volatility for sales volume shocks. */
    public const REVENUE_VARIANCE_SCALAR = 0.15;

    /**
     * An operating firm's opening books from its character: the revenue it earns at its industry's capital intensity
     * (IndustryTurnovers), its moat (IndustryMoats) over what its business must earn (CAPM on its asset beta at the
     * base premium), the operating margin implied by both, and the share of its addressable market its capital spans.
     * Its invested capital is what earns that revenue at that return; its manager's leverage target, capped where its
     * net debt reaches its manager's share of the covenant, splits it into debt and equity; it holds the cash its
     * treasury targets, and half its equity, or the share its row states, is retained earnings. A row that states its
     * books (no revenue key) is handed back as it is.
     */
    public function resolveOpeningBooks(array $seedRow, MacroStateDTO $openingMacro): array
    {
        if (!isset($seedRow['revenue'])) {
            return $seedRow;
        }

        $this->assertOpeningCharacter($seedRow);

        $ticker = (string) $seedRow['ticker'];
        $industry = $seedRow['industry'] ?? null;
        $revenue = (float) $seedRow['revenue'];
        $addressableShare = (float) $seedRow['addressable_share'];
        $taxRate = $this->getEffectiveTaxRate($openingMacro->corporateTaxRate);
        $baselineRoic = $this->openingReturn($seedRow, $openingMacro);
        $margin = $this->openingMargin($seedRow, $baselineRoic, $taxRate);

        $stock = (new Stock())
            ->setTicker($ticker)
            ->setIndustry($industry)
            ->setBeta((string) IndustryBetas::seedBetaFor($seedRow))
            ->setManagementStyle(ManagementStyle::tryFromNullable($seedRow['management_style'] ?? null))
            ->setOperatingMargin(Decimal::format($margin, self::OPENING_MARGIN_SCALE))
            ->setTotalRevenue(Decimal::format($revenue))
            ->setDepreciationRate((string) $seedRow['depreciation_rate'])
            ->setBaselineRoic((string) $baselineRoic)
            ->setRoicTtm((string) $baselineRoic)
            ->setLongRunReturn((string) $baselineRoic);

        // The manager the engine recapitalises toward, at the typical intensity of its style
        // (DebtEngine::analyzeDebtHealth()).
        $manager = $stock->getManagementProfile();
        $leverageShare = min(1.0, $manager->leverageBias());
        $leverageTarget = $this->getLeverageTarget(Sectors::metricsFor($industry)['equity_limit'] * $leverageShare);
        $covenant = Sectors::ebitdaCovenant($industry);
        $covenantMultiple = $covenant !== null ? $this->getLeverageTarget($covenant * $leverageShare) : null;
        $leaseLiability = CorporateMetrics::getInstance()->calculateLeaseLiability($revenue, $this->getLeaseIntensity($stock));
        // The working capital the opening builds on this revenue; the rest of the capital is the plant that
        // depreciates.
        $workingCapital = CorporateMetrics::getInstance()->buildWorkingCapitalBalances($stock, $this->getWorkingCapitalDays($stock), $revenue, $revenue * (1.0 - $margin));

        // Trial capital at the derived margin and opening turnover, then scaled until the capital earns the stated
        // revenue as the opening strikes it (OpeningBoardBuilder: invested capital at its structural turnover, net of
        // saturation).
        $bookDepRate = (float) $seedRow['depreciation_rate'];
        $openingTurnover = $this->resolveStructuralAssetTurnover($baselineRoic, $margin, $taxRate, $bookDepRate);
        $investedCapital = $openingTurnover > 0.0
            ? $revenue / $openingTurnover
            : ($revenue * $margin * (1.0 - $taxRate) / max(0.01, $baselineRoic));
        $equity = $investedCapital;
        $debt = 0.0;
        $cash = 0.0;
        $converged = false;
        for ($pass = 0; $pass < self::MAX_OPENING_BOOK_PASSES; $pass++) {
            $heldCash = $cash;
            $cash = $manager->managedTargetCash($this, max($revenue, $equity, FinancialConstants::MIN_OPERATING_BASE_CASH), 0.0, $debt);
            // Net debt plus leases at the leverage target, nothing borrowed where the leases alone reach it.
            $equity = ($investedCapital + $cash + $leaseLiability) / (1.0 + $leverageTarget);
            $debt = ($leverageTarget * $equity) - $leaseLiability;
            if ($debt < 0.0) {
                $debt = 0.0;
                $equity = $investedCapital + $cash;
            }
            $this->setOpeningBooks($stock, $equity, $debt, $cash, $addressableShare, $openingMacro);
            if ($covenantMultiple !== null) {
                $depreciation = max(0.0, $this->getPlantCapital($stock, $stock->getInvestedCapital()) - $workingCapital) * $bookDepRate;
                $covenantDebt = ($covenantMultiple * (float) $this->getNormalEbitda($stock, $taxRate, $depreciation)) + $cash;
                if ($debt > $covenantDebt) {
                    $debt = $covenantDebt;
                    $equity = $investedCapital + $cash - $debt;
                    $this->setOpeningBooks($stock, $equity, $debt, $cash, $addressableShare, $openingMacro);
                }
            }

            $metrics = $this->getTargetMetrics($stock, $openingMacro, new MathUtility());
            $earned = $metrics['invested_capital'] * $this->resolveStructuralAssetTurnover($metrics['baseline_roic'], $margin, $taxRate, $bookDepRate);
            if ($earned <= 0.0 || $equity <= 0.0) {
                throw new \DomainException("{$ticker} earns nothing on the books its character builds.");
            }
            // Settled once the capital earns the revenue and the cash target no longer moves with the equity it sits
            // on.
            if (abs($revenue / $earned - 1.0) < self::OPENING_BOOK_TOLERANCE && abs($cash - $heldCash) <= self::OPENING_BOOK_TOLERANCE * $revenue) {
                $converged = true;
                break;
            }
            $investedCapital *= $revenue / $earned;
        }
        if (!$converged) {
            throw new \DomainException("{$ticker}'s books do not settle on its stated revenue in " . self::MAX_OPENING_BOOK_PASSES . ' passes.');
        }

        return array_merge($seedRow, [
            'baseline_roic' => $baselineRoic,
            'operating_margin' => $margin,
            'total_equity' => $equity,
            'wholesale_debt' => $debt,
            'customer_deposits' => 0.0,
            'corporate_treasury' => $cash,
            'retained_earnings' => (float) ($seedRow['retained_share'] ?? self::OPENING_RETAINED_SHARE) * $equity,
            'sam_ratio' => (float) $stock->getSamRatio(),
        ]);
    }

    /**
     * Validates a character row before building its books: Standard firms omit operating_margin and sit in an industry
     * listed in IndustryTurnovers; REITs override this to keep operating_margin as a character input.
     *
     * @param array<string, mixed> $seedRow
     */
    protected function assertOpeningCharacter(array $seedRow): void
    {
        SeedRows::assertCharacter($seedRow, static::OPENING_CHARACTER_KEYS, static::OPENING_BOOK_KEYS, [
            'revenue' => (float) ($seedRow['revenue'] ?? 0.0) > 0.0,
            'addressable_share' => (float) ($seedRow['addressable_share'] ?? 0.0) > 0.0,
            'retained_share' => (float) ($seedRow['retained_share'] ?? 0.0) >= 0.0 && (float) ($seedRow['retained_share'] ?? 0.0) <= 1.0,
        ]);
        IndustryTurnovers::assertRow($seedRow);
    }

    /**
     * The operating margin implied by the firm's opening return and its industry's capital turnover, rounded to the
     * column precision of Stock::$operatingMargin so invested capital, OpeningBoardBuilder and tick 1 share one figure.
     *
     * @param array<string, mixed> $seedRow
     */
    protected function openingMargin(array $seedRow, float $openingReturn, float $taxRate): float
    {
        return round(
            FirmEconomics::structuralOperatingMargin($openingReturn, IndustryTurnovers::forRow($seedRow), $taxRate),
            self::OPENING_MARGIN_SCALE,
        );
    }

    /**
     * The return the firm opens on: what its business must earn (CAPM on its asset beta at the base premium) plus its
     * moat.
     *
     * @param array<string, mixed> $seedRow
     */
    protected function openingReturn(array $seedRow, MacroStateDTO $openingMacro): float
    {
        return Valuation::calculateCAPM($openingMacro->equityRiskFreeRate, IndustryBetas::seedBetaFor($seedRow), MacroConstants::BASE_EQUITY_RISK_PREMIUM)
            + IndustryMoats::forRow($seedRow)->spread();
    }

    /** The trial books, with the market sized so the capital spans the stated share of it. */
    private function setOpeningBooks(Stock $stock, float $equity, float $debt, float $cash, float $addressableShare, MacroStateDTO $openingMacro): void
    {
        $stock->setTotalEquity(Decimal::format($equity))
            ->setWholesaleDebt(Decimal::format($debt))
            ->setCorporateTreasury(Decimal::format($cash));
        $stock->setSamRatio((string) ($stock->getInvestedCapital() / (CorporateMetrics::BASELINE_SECTOR_TAM * $openingMacro->nominalGdpIndex * $addressableShare)));
    }

    /**
     * Physical businesses evaluate their true structural scale based on Invested Capital
     * (Total Equity + Debt - Cash), requiring physical assets to turn a profit.
     */
    public function getTargetMetrics(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility): array
    {
        $baselineRoic = max(0.01, (float) $stock->getBaselineRoic());

        $ttmRoic = (float) $stock->getRoicTtm();
        if ($ttmRoic !== 0.0) {
            $baselineRoic = ($baselineRoic * self::BASELINE_ROIC_WEIGHT) + ($ttmRoic * self::TTM_ROIC_WEIGHT);
        }

        // Marginal ROIC on next dollar of capital: structural return minus Penrose scale diseconomy.
        $saturationPenalty = CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, $stock->getInvestedCapital(), $macroState);
        $effectiveRoic = max(0.0, $baselineRoic - $saturationPenalty);

        return [
            'invested_capital' => $stock->getInvestedCapital(),
            'baseline_roic' => $effectiveRoic,
        ];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        return [
            'macro_demand_shift' => $this->resolveCycleDemandShift($stock, $macroState),
            ...$this->resolvePricingMultipliers($stock, $macroState),
        ];
    }

    /**
     * The firm's demand shift from the lagged output gap and the exchange rate. Pure: unlike getMacroPhysics() it
     * steps no pass-through state, so a sector model may read it again inside its own physics.
     */
    protected function resolveCycleDemandShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $macroSensitivityMultiplier = self::CYCLE_SENSITIVITY_BASE + $this->resolvePricingPower($stock);

        $outputGap = $this->resolveLaggedOutputGap($macroState);
        $beta = $this->getOperatingCyclicality($stock);

        return ($outputGap * $macroSensitivityMultiplier * $beta) + $this->resolveFxDemandShift($macroState);
    }

    /**
     * Elasticity of the firm's selling prices to expected inflation. Defaults to the pricing-power form
     * (a price taker recovers half, the median firm all, a price setter one and a half times); sector models
     * that price off a contractual or regulated formula declare PRICING_ELASTICITY directly.
     */
    protected function resolvePricingElasticity(Stock $stock): float
    {
        if (static::PRICING_ELASTICITY !== null) {
            return (float) static::PRICING_ELASTICITY;
        }

        return self::PASS_THROUGH_BASE_ELASTICITY + $this->resolvePricingPower($stock);
    }

    /**
     * The selling-price and input-cost multipliers the engine applies to revenue capacity and the cost base.
     * Both track the same inflation measure with the same repricing lag; only the elasticity differs, so the
     * gap between them is the firm's real pricing. Called exactly once per quarter (the lag state persists on
     * the stock), so sector models compose it rather than recomputing pass-through themselves.
     *
     * @return array{pricing_power_multiplier: float, input_cost_multiplier: float}
     */
    public function resolvePricingMultipliers(Stock $stock, MacroStateDTO $macroState): array
    {
        $expectedInflation = $this->resolveExpectedInflationBasis($macroState);
        $costInflation = $this->resolveCostInflationBasis($macroState);
        $elasticity = $this->resolvePricingElasticity($stock);

        $passThrough = $this->resolveInflationPassThrough($stock, $macroState, $elasticity, $expectedInflation, (float) static::PRICE_PASS_THROUGH_LAG_YEARS);

        return [
            'pricing_power_multiplier' => 1.0 + $passThrough,
            // Input prices move with expected inflation at unit elasticity and the same repricing lag, whatever the
            // firm itself manages to charge: the gap between the two multipliers is the firm's real pricing. If the
            // model sets an administered pricing basis distinct from its cost basis, input costs inflate at their own
            // basis.
            'input_cost_multiplier' => 1.0 + ($costInflation !== $expectedInflation ? $costInflation : ($elasticity > 0.0 ? $passThrough / $elasticity : $expectedInflation)),
        ];
    }

    /**
     * Incomplete and lagged pass-through of expected inflation into selling prices (Gopinath & Itskhoki 2010).
     *
     * The share of inflation a firm recovers in price is its pricing power, not its beta. Beta measures
     * systematic risk and already scales the demand shift above. The elasticity is centred so the median firm
     * (pricing power 0.5) has unit elasticity, mirroring the 0.5 + p form the demand multiplier uses.
     *
     * Pass-through is then distributed over time rather than landing whole in the quarter expectations move:
     * menu costs and contract repricing mean posted prices reach the new level over several quarters. The
     * lag state is persisted on the stock, so a firm carries its own repricing history.
     */
    protected function resolveInflationPassThrough(Stock $stock, MacroStateDTO $macroState, float $elasticity, ?float $expectedInflation = null, ?float $lagYears = null): float
    {
        $targetPassThrough = ($expectedInflation ?? $macroState->tipsBreakevenEma) * $elasticity;

        $laggedPassThrough = TimeSeries::calculateDistributedLag(
            currentLaggedValue: $stock->getInflationPassThrough() ?? $targetPassThrough,
            targetValue: $targetPassThrough,
            dt: FinancialConstants::QUARTER_YEARS,
            lagTimeConstant: $lagYears ?? (float) static::PRICE_PASS_THROUGH_LAG_YEARS,
        );

        $stock->setInflationPassThrough($laggedPassThrough);

        return $laggedPassThrough;
    }

    /**
     * Idiosyncratic variance is applied directly to sales volume.
     */
    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $pricingPower = $this->resolvePricingPower($stock);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $revenueZ = $streams->generateDemandZ('revenue');

        $revenueShock = ($revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR));
        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);

        // Input cost basket: energy, metals, agricultural, freight, wholesale-goods and wage prices reach the
        // variable cost base with the buying lag and are recovered in selling prices with the repricing lag.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $inputCostDrag);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: [
                'core_business' => $actualRevenue,
            ],
        );
    }

    /**
     * Normal physical companies are evaluated on NOPAT / Invested Capital (ROIC).
     */
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate, float $wacc = 0.08, float $costOfEquity = 0.10, ?MacroStateDTO $macroState = null, float $depreciation = 0.0, float $afterTaxSpecialCharges = 0.0): float
    {
        $nopatProxy = $ebit > 0 ? $ebit * (1.0 - $corporateTaxRate) : $ebit;

        $effectiveCapital = max(1.0, abs($investedCapital));
        $truePostTaxReturn = ($nopatProxy / $effectiveCapital) * FinancialConstants::QUARTERS_PER_YEAR;

        $stock->setCurrentRoic((string) max(FinancialConstants::MIN_REPORTED_RETURN, min(FinancialConstants::MAX_REPORTED_RETURN, $truePostTaxReturn)));
        $stock->setRoicTtm((string) $this->revertTrailingReturn($stock, (float) $stock->getRoicTtm(), $truePostTaxReturn, $wacc, self::TTM_ROIC_WEIGHT, abs($investedCapital), $macroState));

        return $truePostTaxReturn;
    }

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return [
            'exchange_rate_index_ema',
            'import_price_level',
            'industrial_metals_index_ema',
            'output_gap_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
