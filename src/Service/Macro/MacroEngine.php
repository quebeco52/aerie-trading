<?php

declare(strict_types=1);

namespace App\Service\Macro;

use App\DTO\BoardReadingDTO;
use App\DTO\GovernmentPolicyDTO;
use App\DTO\MacroStateDTO;
use App\Service\Event\ShockEvent;
use App\Service\Macro\Recorder\MacroDiagnosticsProbe;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Politics\Department\SecuritiesCommission;
use Psr\Log\LoggerInterface;

class MacroEngine
{
    public const REDIS_MACRO_STATE = 'macroeconomic_state';

    // --- Sector Demand Factor ---
    /**
     * Time constant (years) of the per-sector demand factor read by every firm's earnings physics: an industry upswing
     * or slump persists for about a year rather than resetting each tick.
     */
    public const SECTOR_DEMAND_PERSISTENCE_YEARS = 1.0;

    // --- KALDOR-KALECKI 2D LIMIT CYCLE ---
    /** Sensitivity scaling diffusion volatility of output gap and inflation during severe cyclical stress. */
    public const STRESS_MULTIPLIER_GAP_SENSITIVITY = 10.0;

    // --- District-Wide Systemic Event Triggers ---
    /**
     * Minimum simulated years between district-wide events, so distinct crises arriving together report a quarter apart
     * rather than on one tick.
     */
    public const SYSTEMIC_EVENT_COOLDOWN_YEARS = 0.25;

    public function __construct(
        private readonly MathUtility $mathUtility,
        private readonly \Redis $redis,
        private readonly MacroSnapshotRecorder $snapshotRecorder,
        private readonly MonetaryPolicySubsystem $monetarySubsystem,
        private readonly LaborMarketSubsystem $laborSubsystem,
        private readonly MacroAggregateSubsystem $aggregateSubsystem,
        private readonly CommodityLogisticsSubsystem $commoditySubsystem,
        private readonly AssetMarketSubsystem $assetSubsystem,
        private readonly CreditFiscalSubsystem $creditFiscalSubsystem,
        private readonly ?LoggerInterface $logger = null,
        /** Records the quarter's averages. Null in a test or a headless harness, off everywhere the ticker is not. */
        private readonly ?MacroDiagnosticsProbe $diagnostics = null,
        /**
         * The sovereign reserve fund. Null means the district has none, which is what a caller that builds the engine
         * by hand gets.
         */
        private readonly ?SovereignFundSubsystem $sovereignFundSubsystem = null,
    ) {}

    private function loadState(): MacroState
    {
        return $this->readPersistedState() ?? new MacroState();
    }

    /** The state Redis holds, or null when it holds none that decodes. */
    private function readPersistedState(): ?MacroState
    {
        $rawState = $this->redis->get(self::REDIS_MACRO_STATE);
        if (!is_string($rawState) || trim($rawState) === '') {
            return null;
        }

        try {
            $decoded = json_decode($rawState, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                $this->logger?->warning('Corrupt macroeconomic state in Redis: expected array, got ' . gettype($decoded));
                return null;
            }
            return MacroState::fromArray($decoded);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to decode macroeconomic state from Redis: ' . $e->getMessage());
            return null;
        }
    }

    private function saveState(MacroState $state): void
    {
        try {
            $payload = $state->toArray();
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $this->redis->set(self::REDIS_MACRO_STATE, $encoded);
        } catch (\Throwable $e) {
            $this->logger?->critical('Failed to persist macroeconomic state to Redis: ' . $e->getMessage());
        }
    }

    public function getLiveState(): MacroState
    {
        return $this->loadState();
    }

    /**
     * Sets the macroeconomic clock to an authoritative simulation time.
     *
     * The macro state is cached in Redis and the simulation clock is committed to the database, so a cache
     * that has lost writes comes back describing an economy at the wrong moment. Everything else in the state
     * — the rate, the regime, where the cycle stands — is a level with no record of when it was taken, and
     * there is nothing to reconcile it against; only time itself can be corrected, and it has to be, because
     * the expiry grids and sampling cadences read off it. The ticker does this once at start-up.
     *
     * A cache with no state at all is refused once the clock has run: an opening state stamped with a late clock is
     * a Year-1 economy (deflator 1.0) under a board carrying every year of nominal growth since, and the firms that
     * report on it book their plant at a price level a hundredth of the one they bought it at.
     *
     * @throws \RuntimeException When Redis holds no macro state and the clock is past the opening.
     */
    public function alignClock(float $totalTime): void
    {
        $state = $this->readPersistedState();
        if ($state === null) {
            if ($totalTime > 0.0) {
                throw new \RuntimeException(sprintf(
                    'No macroeconomic state in Redis (key "%s") while the simulation clock stands at year %.4f. '
                    . 'Restore the key, or start over with app:market-reset; opening a fresh economy here would run '
                    . 'Year-1 prices under the current board.',
                    self::REDIS_MACRO_STATE,
                    $totalTime,
                ));
            }
            $state = new MacroState();
        }

        if ($state->totalTime === $totalTime) {
            return;
        }

        $this->logger?->warning(sprintf(
            'Macroeconomic clock realigned from %.6f to %.6f simulation years to match the database.',
            $state->totalTime,
            $totalTime,
        ));

        $state->totalTime = $totalTime;
        $this->saveState($state);
    }

    /**
     * Advances the macroeconomic state by one tick.
     * Calculates Inflation, Output Gap, Taylor Rule (Short Rate), and the Yield Curve.
     *
     * Everything here runs forward, from the economy to the prices it sets, with one exception: household
     * equity wealth. The board's CAPITALISATION comes back in through $board so that a bull market can spend and a
     * crash can save, which is a loop, and it is closed deliberately and visibly -- the caller hands over what it
     * measured on the PREVIOUS tick, never anything from this one.
     *
     * Capitalisation, not an index level. A level is a tradable instrument's scale: the district's funds
     * split themselves whenever their price runs away, and the index divisor is restated to match, so a
     * level re-bases on a share-count cosmetic. Household wealth does not. Reading the level once cost this
     * engine a 73% phantom crash and years of demand drag behind it.
     *
     * A null level leaves the last observation standing, and a caller that never reports a market (the headless
     * harness, the unit tests) leaves the capitalisation at zero, which the wealth channel reads as "no market" and
     * contributes exactly nothing for.
     *
     * The sovereign fund reads the board the same way and on the same lag: its float-adjusted capitalisation (a
     * level, which stands when not reported) and the float-weighted price return, dividend cash and net issuance of
     * that tick (flows, which are zero when not reported, so a missing observation never replays the last one).
     *
     * The government is outside the economy too (App\Service\Politics\PoliticsEngine): what it hands over, the levers
     * in force and the election calendar's pull on policy uncertainty, comes in through $policy as of the previous
     * tick, and null leaves both where they stand, so a caller with no politics runs on the opening levers and no
     * calendar.
     *
     * @param float                    $dt     Time increment in years.
     * @param BoardReadingDTO|null     $board  The board as of the previous tick, or null for no market.
     * @param GovernmentPolicyDTO|null $policy The government's levers and election pulse as of the previous tick, or
     *                                         null.
     */
    public function updateMacroState(
        float $dt,
        ?BoardReadingDTO $board = null,
        ?GovernmentPolicyDTO $policy = null,
    ): MacroStateDTO {
        $state = $this->loadState();

        if ($policy !== null) {
            $this->enactPolicy($state, $policy);
        }

        $board ??= new BoardReadingDTO();
        if ($board->equityMarketCap !== null && $board->equityMarketCap > 0.0) {
            $state->equityMarketCap = $board->equityMarketCap;
        }
        if ($board->floatCap !== null && $board->floatCap > 0.0) {
            $state->boardFloatCap = $board->floatCap;
        }
        $state->boardPriceReturn = $board->priceReturn;
        $state->boardDividendCash = $board->dividendCash;
        $state->boardNetIssuance = $board->netIssuance;
        $state->boardPriceIndex *= 1.0 + $board->priceReturn;
        $state->boardTotalReturnIndex *= 1.0 + $board->priceReturn + ($state->boardFloatCap > 0.0 ? $board->dividendCash / $state->boardFloatCap : 0.0);
        $state->boardStampDuty = $board->stampDuty;
        $state->strategicStakeCash = $board->strategicStakeCash;
        if ($board->bankLevy !== null) {
            $state->boardBankLevy = $board->bankLevy;
        }

        // Advance physical simulation time in years
        $state->totalTime += $dt;

        // 1. Solow-Swan (1956) Total Factor Productivity (TFP) secular drift and endogenous growth.
        $tfpTrendGrowthRate = $this->aggregateSubsystem->calculateTotalFactorProductivity($state, $dt);
        // Basu, Fernald & Kimball (2006): potential absorbs a productivity shock gradually; everything built on
        // productivity growth (wage bargains, unit labour cost, money demand, potential GDP) reads that path.
        $productivityGrowthRate = $this->aggregateSubsystem->absorbProductivityShocks($state, $tfpTrendGrowthRate, $dt);

        // 2. Holston, Laubach & Williams (2017) natural rate of interest (r*), on trend growth only: the secular drift,
        // not the R&D term that rides the output gap, which the docblock there keeps out of r*.
        $this->aggregateSubsystem->calculateNaturalRate($state, MacroConstants::TFP_DRIFT, $dt);

        // 3. Okun (1962) & Diamond-Mortensen-Pissarides (1994) labor market dynamics.
        $this->laborSubsystem->calculateUnemployment($state, $dt);
        $this->laborSubsystem->calculateCyclicalMigration($state);
        $this->laborSubsystem->calculateLaborMarketAndWages($state, $productivityGrowthRate, $dt);

        // 4. Gurkaynak, Sack & Wright (2010) TIPS breakeven inflation expectations, anchored on the Authority's target
        //    and
        // priced off the long-run inflation the public has learned over it (Kozicki & Tinsley 2001).
        $this->monetarySubsystem->updateInflationEndpoint($state, MacroConstants::TARGET_INFLATION, $dt);
        $state->tipsBreakeven = $this->aggregateSubsystem->calculateTipsBreakeven($state, MacroConstants::TARGET_INFLATION);

        // 5. The Authority's published rule, and its decision at each of its eight meetings.
        $state->targetRate = $this->monetarySubsystem->calculateTargetRate($state, MacroConstants::TARGET_INFLATION, $state->naturalRate, $dt);
        $clampedTarget = max(MacroConstants::EFFECTIVE_LOWER_BOUND, min(MacroConstants::POLICY_RATE_CEILING, $state->targetRate));
        $state->policyRate = $this->monetarySubsystem->updatePolicyRate($state, $clampedTarget, $dt);

        // 6. Central bank balance sheet unconventional QE/QT operations (Bernanke & Reinhart 2004).
        $balanceSheetData = $this->monetarySubsystem->calculateBalanceSheetOperations($state, $dt);
        $holdingsBefore = MonetaryPolicySubsystem::authorityHoldingsToGdp($state);
        $state->balanceSheetIntensity = $balanceSheetData['new_balance_sheet_intensity'];
        MonetaryPolicySubsystem::rollAuthorityBookCoupon($state, $holdingsBefore, $dt);
        $state->balanceSheetHoldTimer = $balanceSheetData['new_hold_timer'];
        $state->qeIntensity = $balanceSheetData['new_qe_intensity'];
        $qeWasActive = $state->qeActive;
        $state->qeActive = $state->qeIntensity > MacroConstants::BALANCE_SHEET_ACTIVE_THRESHOLD;
        if ($state->qeActive && !$qeWasActive) {
            $state->lastQeLaunchAt = $state->totalTime;
        }
        $state->qtIntensity = $balanceSheetData['new_qt_intensity'];
        $state->qtActive = $state->qtIntensity > MacroConstants::BALANCE_SHEET_ACTIVE_THRESHOLD;

        // 7. Adrian, Crump & Moench (2013) term premium and market expectations dynamics.
        $this->monetarySubsystem->updateTermPremiumDynamics($state, $dt);
        $this->monetarySubsystem->updateMarketExpectations($state, $dt);

        // 8. Nelson-Siegel (1987) / Svensson (1994) sovereign yield curve term structure.
        $yieldData = $this->monetarySubsystem->calculateYieldCurve($state, MacroConstants::TARGET_INFLATION, $state->naturalRate);

        $this->monetarySubsystem->applyYieldCurve($state, $yieldData, $dt);

        $systemicShock = $this->assetSubsystem->updateSystemicMarketFactor($state, $dt);
        $this->aggregateSubsystem->updateCapitalStockOverhang($state, $dt);
        // Baker, Bloom & Davis (2016) economic policy uncertainty index simulation.
        $this->creditFiscalSubsystem->calculatePolicyUncertainty($state, $dt);
        // Mundell-Fleming multi-country foreign output gap and global policy transmission.
        $openingMainlandGap = $state->foreignOutputGap;
        $this->assetSubsystem->calculateForeignEconomy($state, $dt);

        $stressMultiplier = 1.0 + (abs($state->outputGap) * self::STRESS_MULTIPLIER_GAP_SENSITIVITY);
        $expectedInflation = $this->monetarySubsystem->calculateExpectedInflation($state, MacroConstants::TARGET_INFLATION);
        $openingGap = $state->outputGap;
        $state->outputGap = $this->aggregateSubsystem->calculateOutputGap($state, $state->yield5y, $state->naturalRate, $expectedInflation, $dt, $stressMultiplier);

        $this->aggregateSubsystem->calculateCapacityUtilization($state);
        $this->commoditySubsystem->calculateEnergyShock($state, $dt);
        $this->commoditySubsystem->calculateNaturalGasIndex($state, $dt);
        $this->commoditySubsystem->calculateWholesalePowerIndex($state, $dt);
        $this->commoditySubsystem->calculateRefiningCrackSpread($state, $dt);
        $this->assetSubsystem->calculateExchangeRate($state, $dt);
        $this->assetSubsystem->calculateTradeBalance($state, $dt);
        $this->commoditySubsystem->calculateIndustrialMetalsIndex($state, $dt);
        $this->commoditySubsystem->calculateGoldPriceIndex($state, $dt);
        $this->creditFiscalSubsystem->calculateGovernmentSpending($state, $dt);
        $this->assetSubsystem->calculateAlliedDefenseSpending($state, $dt);
        $this->assetSubsystem->calculateCommercialPropertyIndex($state, $dt);
        $this->creditFiscalSubsystem->calculateHouseholdCredit($state, $dt);
        $this->creditFiscalSubsystem->calculateCreditCrisisHazard($state, $dt);
        // Gilchrist & Zakrajsek (2012): the premium moves before volatility and the spreads that load on it.
        $this->creditFiscalSubsystem->updateExcessBondPremium($state, $state->outputGap - $openingGap, $dt, $state->foreignOutputGap - $openingMainlandGap);
        $state->tipsBreakevenQuoted = MacroAggregateSubsystem::quotedTipsBreakeven($state->tipsBreakeven, $state->excessBondPremium);
        $this->creditFiscalSubsystem->calculateRetailDefaultRate($state, $dt);
        $this->commoditySubsystem->calculateAgriculturalCommodityIndex($state, $dt);
        $this->commoditySubsystem->calculateCatastropheLosses($state, $dt);
        $this->commoditySubsystem->calculateFreightRateIndex($state, $dt);
        $this->commoditySubsystem->calculateSupplierDeliveryTimes($state, $dt);
        $this->commoditySubsystem->calculateSupplyChainPressureIndex($state);
        $this->assetSubsystem->calculateResidentialPropertyIndex($state, $expectedInflation, $dt);
        $this->assetSubsystem->calculateHousingStarts($state, $dt);

        $this->aggregateSubsystem->calculateServicesRepricing($state, $dt);
        $state->inflation = $this->aggregateSubsystem->calculateInflation($state, MacroConstants::TARGET_INFLATION, $productivityGrowthRate, $dt);
        $this->aggregateSubsystem->calculateProducerPriceInflation($state, $productivityGrowthRate, $dt);
        $state->marketVolatility = $this->assetSubsystem->calculateMarketVolatility($state, $dt, $systemicShock['varianceJump'], $systemicShock['returnInnovation']);

        $this->aggregateSubsystem->calculateManufacturingPmi($state, $dt);
        $this->monetarySubsystem->calculateMoneySupplyGrowth($state, $dt, $productivityGrowthRate);
        $this->monetarySubsystem->calculateDepositChannel($state, $dt);

        $this->aggregateSubsystem->updateExponentialMovingAverages($state, $dt);

        $this->creditFiscalSubsystem->calculateMacroCreditSpread($state);
        $this->creditFiscalSubsystem->updateCreditSpreadTrend($state, $dt);
        $this->creditFiscalSubsystem->calculateInterbankLiquiditySpread($state);
        $this->creditFiscalSubsystem->calculateSloosCreditStandards($state, $dt);
        $this->creditFiscalSubsystem->calculateCorporateDefaultRate($state, $dt);

        $this->aggregateSubsystem->calculatePotentialAndNominalGdp($state, $dt, $productivityGrowthRate);
        MacroAggregateSubsystem::recordQuarterlyRealGdp($state, $dt);
        // Singapore NIR draw and a GPIF-style rebalancing band: struck on this tick's GDP, before the budget reads the
        // draw.
        $this->sovereignFundSubsystem?->update($state, $dt);
        $this->creditFiscalSubsystem->calculateDynamicFiscalPolicy($state, $dt);
        $this->creditFiscalSubsystem->calculateSovereignDebt($state, $dt);
        $this->creditFiscalSubsystem->calculateSovereignRiskSpread($state, $dt);
        $this->creditFiscalSubsystem->calculateReimbursementRate($state, $dt);
        $this->assetSubsystem->calculateEquityRiskPremium($state);
        $this->assetSubsystem->calculateFinancialConditionsIndex($state, $dt);
        $this->assetSubsystem->calculateConsumerSentiment($state, $dt);
        $this->monetarySubsystem->calculateRecessionProbability($state);
        $this->assetSubsystem->calculateCapitalMarketsDealIndex($state, $dt);
        $this->updateSectorFactors($state, $dt);
        $this->evaluateSystemicEvent($state, $dt);
        if ($state->eventType !== null) {
            $this->diagnostics?->recordEvent('systemic.' . $state->eventType, 1.0);
        }

        $this->diagnostics?->recordAverages($state, $dt);

        $this->saveState($state);
        return MacroStateDTO::fromMacroState($state);
    }

    /**
     * Advances one persistent shock per macro sector, the second common factor behind every equity price.
     *
     * With only the market factor, two banks co-move solely through their betas, so a banking crisis moves
     * their earnings together while their prices diffuse independently between reporting dates.
     *
     * These shocks are deliberately i.i.d. rather than autocorrelated. Autocorrelating them would have to be
     * paid for by scaling each shock down to keep integrated variance intact, which shrinks the factor's
     * per-step contribution to nearly nothing and makes the sector correlation it exists to create invisible
     * at any horizon a player actually looks at. It is also unnecessary: a sector ROTATION lives in the
     * cumulative level, and the level of a random walk wanders and stays displaced for months at a time
     * without any memory in its increments. Autocorrelation would only add predictable momentum, which is
     * the market factor's job and is kept small there for the same horizon-stability reason.
     */
    private function updateSectorFactors(MacroState $state, float $dt): void
    {
        // Ornstein-Uhlenbeck persistent sector demand factor with unit stationary variance (Vasicek 1977).
        $decay = exp(-$dt / self::SECTOR_DEMAND_PERSISTENCE_YEARS);
        $innovationScale = sqrt(max(0.0, 1.0 - ($decay * $decay)));

        foreach (array_keys(\App\Data\Company\Sectors::MACRO_SECTORS) as $sector) {
            $state->sectorZ[$sector] = $this->mathUtility->generateStandardNormal();
            $previous = (float) ($state->sectorDemandZ[$sector] ?? 0.0);
            $state->sectorDemandZ[$sector] = ($decay * $previous) + ($innovationScale * $this->mathUtility->generateStandardNormal());
        }
    }

    /**
     * Selects at most one district-wide systemic event per tick from the macro state just computed.
     *
     * A crisis satisfies its threshold for many consecutive ticks, so each level alarm latches when it reports and
     * re-arms only once its condition has cleared: one headline per episode, not one per cooldown for as long as
     * the episode lasts. The event is a single-tick pulse -- consumers read it on the tick it fires and it is cleared
     * on the next -- and the cooldown then holds further events back until the regime has had time to develop; an
     * alarm held back stays armed and reports when the cooldown ends.
     *
     * Conditions are evaluated most-severe-first so a funding freeze outranks a recession declaration that
     * would inevitably accompany it.
     */
    private function evaluateSystemicEvent(MacroState $state, float $dt): void
    {
        // Pulse trigger: resets instantaneous systemic event classification before evaluation.
        $state->eventType = null;

        // The episode each level alarm reports once in. Deleveraging reports when debt starts to fall inside one.
        $episodes = [
            ShockEvent::SYSTEMIC_LIQUIDITY_FREEZE => $state->interbankLiquiditySpread >= MacroConstants::SYSTEMIC_LIQUIDITY_FREEZE_SPREAD,
            ShockEvent::CREDIT_MARKET_SEIZURE => $state->highYieldCreditSpread >= MacroConstants::SYSTEMIC_CREDIT_SEIZURE_SPREAD,
            ShockEvent::SOVEREIGN_DOWNGRADE => $state->sovereignRiskSpread >= MacroConstants::SYSTEMIC_SOVEREIGN_STRESS_SPREAD,
            ShockEvent::RECESSION_DECLARED => $state->recessionDeclared,
            ShockEvent::HOUSEHOLD_DELEVERAGING => $state->householdDebtServiceGap >= MacroConstants::HOUSEHOLD_DSR_STRESS_MARGIN,
            ShockEvent::YIELD_CURVE_INVERSION_ALARM => $state->inversionDuration >= MacroConstants::SYSTEMIC_INVERSION_ALARM_YEARS,
        ];
        foreach ($episodes as $alarm => $inEpisode) {
            if (!$inEpisode) {
                unset($state->systemicAlarmLatches[$alarm]);
            }
        }
        $armed = static fn(string $alarm): bool => $episodes[$alarm] && !isset($state->systemicAlarmLatches[$alarm]);

        // Inside the cooldown only an edge-triggered event lands: the level conditions it suppresses must not outrank
        // it.
        $edgeOnly = false;
        if ($state->eventCooldownTimer > 0.0) {
            $state->eventCooldownTimer = max(0.0, $state->eventCooldownTimer - $dt);
            if ($state->lastCatastropheAt !== $state->totalTime
                && $state->lastCreditCrisisAt !== $state->totalTime
                && $state->lastQeLaunchAt !== $state->totalTime
                && $state->lastSovereignRebalanceAt !== $state->totalTime
            ) {
                return;
            }
            $edgeOnly = true;
        }

        $eventType = match (true) {
            // Mian & Sufi (2018) systemic banking crisis triggered by debt overhang default hazard.
            $state->lastCreditCrisisAt === $state->totalTime
            => ShockEvent::BANKING_CRISIS,

            // Launch of an asset-purchase programme: one headline per programme, reported on the tick it starts.
            $state->lastQeLaunchAt === $state->totalTime
            => ShockEvent::TITAN_INTERVENTION,

            // The sovereign fund starts a rebalance: one headline per programme, buying after a fall or trimming after
            // a run.
            $state->lastSovereignRebalanceAt === $state->totalTime && $state->sovereignFundRebalanceBacklog > 0.0
            => ShockEvent::SOVEREIGN_WEALTH_DEPLOYMENT,

            $state->lastSovereignRebalanceAt === $state->totalTime && $state->sovereignFundRebalanceBacklog < 0.0
            => ShockEvent::SOVEREIGN_WEALTH_TRIM,

            !$edgeOnly && $armed(ShockEvent::SYSTEMIC_LIQUIDITY_FREEZE)
            => ShockEvent::SYSTEMIC_LIQUIDITY_FREEZE,

            !$edgeOnly && $armed(ShockEvent::CREDIT_MARKET_SEIZURE)
            => ShockEvent::CREDIT_MARKET_SEIZURE,

            !$edgeOnly && $armed(ShockEvent::SOVEREIGN_DOWNGRADE)
            => ShockEvent::SOVEREIGN_DOWNGRADE,

            // The statistics office calls a recession on two falling quarters.
            !$edgeOnly && $armed(ShockEvent::RECESSION_DECLARED)
            => ShockEvent::RECESSION_DECLARED,

            // Natural catastrophe physical damage shock event (Hallegatte et al. 2007).
            $state->lastCatastropheAt === $state->totalTime
            => ShockEvent::NATURAL_CATASTROPHE,

            // Drehmann & Juselius (2012) household balance sheet debt-service deleveraging shock.
            !$edgeOnly
                && $armed(ShockEvent::HOUSEHOLD_DELEVERAGING)
                && $state->householdDebtToIncome < $state->householdDebtToIncomeEma
            => ShockEvent::HOUSEHOLD_DELEVERAGING,

            !$edgeOnly && $armed(ShockEvent::YIELD_CURVE_INVERSION_ALARM)
            => ShockEvent::YIELD_CURVE_INVERSION_ALARM,

            default => null,
        };

        if ($eventType !== null) {
            $state->eventType = $eventType;
            if (isset($episodes[$eventType])) {
                $state->systemicAlarmLatches[$eventType] = $state->totalTime;
            }
            // District-wide systemic crisis refractory cooldown timer arming.
            $state->eventCooldownTimer = self::SYSTEMIC_EVENT_COOLDOWN_YEARS;
        }
    }

    /**
     * Whether the statistics office has a recession declared and not yet over
     * (MacroAggregateSubsystem::recordQuarterlyRealGdp()).
     */
    public static function recessionDeclared(MacroState|MacroStateDTO $state): bool
    {
        return $state->recessionDeclared;
    }

    /**
     * Puts the government's levers in force and takes its election pulse. A tariff's change moves productivity by
     * Furceri et al.'s (2018) output loss, a level potential absorbs over the years that follow.
     */
    private function enactPolicy(MacroState $state, GovernmentPolicyDTO $policy): void
    {
        $productivityLoss = -MacroConstants::TARIFF_OUTPUT_LOSS * ($policy->importTariffRate - $state->importTariffRate);
        $state->tfpShockLevel += $productivityLoss;
        $state->totalFactorProductivityIndex *= exp($productivityLoss);

        $state->publicSpendingShare = $policy->publicSpendingShare;
        $state->corporateTaxPolicyShift = $policy->corporateTaxPolicyShift;
        $state->importTariffRate = $policy->importTariffRate;
        $state->laborForceGrowthRate = $policy->laborForceGrowthRate;
        $state->foreignBuyerTaxRate = $policy->foreignBuyerTaxRate;
        $state->mergerReviewLeniency = $policy->mergerReviewLeniency;
        $state->greenBeltStringency = $policy->greenBeltStringency;
        $state->carbonPrice = $policy->carbonPrice;
        $state->extractionStringency = $policy->extractionStringency;
        $state->stampDutyRate = $policy->stampDutyRate;
        $state->bankLevyRate = $policy->bankLevyRate;
        $state->reserveDrawShare = $policy->reserveDrawShare;
        $state->consolidationTaxShare = $policy->consolidationTaxShare;
        $state->reserveHomeEquityShare = $policy->reserveHomeEquityShare;
        $state->utilityRateCaseGrantShare = $policy->utilityRateCaseGrantShare;
        $state->dividendTaxRate = $policy->dividendTaxRate;
        $state->debtTarget = $policy->debtTarget;
        $state->debtPace = $policy->debtPace;
        $state->electionPulse = $policy->electionPulse;
        $state->authorityMajority = $policy->authorityMajority ?? 0.0;
        $state->authorityCommitteeSeated = $policy->authorityMajority === null ? 0.0 : 1.0;
        $state->bankCapitalRequirement = $policy->bankCapitalRequirement ?? $state->bankCapitalRequirement;
        $state->mortgageLtvCap = $policy->mortgageLtvCap;
        $state->neutralCountercyclicalBuffer = $policy->neutralCountercyclicalBuffer;
        $state->sovereignFundMandateEquityShare = $policy->reserveFundEquityShare ?? $state->sovereignFundMandateEquityShare;
        $state->sovereignDebtMaturityTarget = $policy->debtAverageMaturity ?? $state->sovereignDebtMaturityTarget;
        // No rule from the Securities Commission is the founding one, not the last one in force.
        $state->initialMarginRequirement = $policy->initialMarginRequirement ?? SecuritiesCommission::initialMargin(null);
        $state->earningsManagementScale = $policy->earningsManagementScale ?? SecuritiesCommission::earningsManagementScale(null);

        $state->previousSittingLevers = $state->sittingLevers;
        $state->previousSittingPolicyFrom = $state->sittingPolicyFrom;
        $state->previousExpectedLevers = $state->expectedLevers;
        $state->previousExpectedPolicyFrom = $state->expectedPolicyFrom;
        $state->sittingLevers = $policy->sittingLevers ?? [];
        $state->sittingPolicyFrom = $policy->sittingLevers === null ? -1.0 : ($policy->sittingPolicyFrom ?? -1.0);
        $state->expectedLevers = $policy->expectedLevers ?? [];
        $state->expectedPolicyFrom = $policy->expectedLevers === null ? -1.0 : ($policy->expectedPolicyFrom ?? -1.0);
    }

    /**
     * Persists an immutable historical econometric snapshot to the database.
     *
     * Records all macroeconomic time series across interest rates, yield curves,
     * inflation, labor market dynamics, credit spreads, commodities, real estate,
     * and national accounts into the macro_report table.
     *
     * @param MacroStateDTO                         $macroState State snapshot to record.
     * @param \Doctrine\DBAL\Connection                       $conn       Database connection.
     * @param Recorder\QuarterRecord|null $quarter    The probes' closed windows and the run's identity.
     */
    public function recordMacroSnapshot(
        MacroStateDTO $macroState,
        \Doctrine\DBAL\Connection $conn,
        ?Recorder\QuarterRecord $quarter = null,
    ): void {
        $this->snapshotRecorder->recordSnapshot($macroState, $conn, $quarter);
    }
}
