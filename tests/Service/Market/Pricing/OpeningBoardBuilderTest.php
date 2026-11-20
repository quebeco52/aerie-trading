<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Pricing;

use App\Data\Company\AnchorHoldings;
use App\Data\Company\DividendTier;
use App\Data\Company\IndustryRisks;
use App\Data\Company\PayoutPolicy;
use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Earnings\FixedAssetLedger;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Market\Bond\CorporateDefaultService;
use App\Service\Market\Bond\CreditRatingAgency;
use App\Service\Market\Pricing\LiquidityEngine;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\OpeningBoardBuilder;
use App\Service\Market\Pricing\SeedRows;
use App\Service\Market\Pricing\VarianceBudget;
use App\Service\Math\CreditRisk;
use App\Service\Math\Decimal;
use App\Service\Math\FixedIncome;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\Valuation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The seed and the reset both open the board through OpeningBoardBuilder, so what it opens is what every new market
 * starts from.
 */
#[Group('unit')]
class OpeningBoardBuilderTest extends TestCase
{
    private MathUtility $math;
    private DebtEngine $debtEngine;
    private MarketEngine $marketEngine;
    private AnchorStakeLedger $ledger;
    private OpeningBoardBuilder $builder;

    protected function setUp(): void
    {
        mt_srand(20260929);
        $this->math = new MathUtility();
        $this->debtEngine = new DebtEngine($this->math, CorporateMetrics::getInstance());
        $this->marketEngine = new MarketEngine($this->math);
        $this->ledger = new AnchorStakeLedger();
        $this->builder = new OpeningBoardBuilder($this->math, $this->debtEngine, $this->marketEngine, $this->ledger);
    }

    /** @return array<string, Stock> The whole seed board, opened as the commands open it. */
    private function openBoard(MacroStateDTO $openingMacro): array
    {
        $board = [];
        $spheres = [];
        foreach (SeedRows::all() as $stockData) {
            $stock = (new Stock())->setTicker($stockData['ticker']);
            $sphere = $this->builder->open($stock, $stockData, $openingMacro);
            if ($sphere !== null) {
                $spheres[] = $sphere;
            }
            $board[$stockData['ticker']] = $stock;
        }
        $this->builder->openSpheres($spheres, $board, $openingMacro);

        return $board;
    }

    /**
     * Every firm opens at the fair value the market strikes for it on the inputs it will price it on at the first
     * tick, so trading does not start with a re-rating. The opening used to run a lender's debt analysis before its
     * industry was set, on standard corporate physics, and opened the large banks and funds 30-50% below it.
     */
    public function testEveryFirmOpensAtThePriceTheMarketValuesItAt(): void
    {
        $openingMacro = new MacroStateDTO();

        foreach ($this->openBoard($openingMacro) as $ticker => $stock) {
            $health = $this->debtEngine->analyzeDebtHealth($stock, $openingMacro);
            $fairValue = $this->marketEngine->strikeFairValue(MarketPricingContext::forStock($stock, $openingMacro, $health, $this->ledger))['perceived_fair_value'];

            $this->assertEqualsWithDelta(1.0, $fairValue / (float) $stock->getPrice(), 0.005, sprintf('%s opens %.1f%% away from the value the market puts on it.', $ticker, 100.0 * ($fairValue / (float) $stock->getPrice() - 1.0)));
        }
    }

    /**
     * A market that has been trading opens where each board's dividend policy has already brought it (Lintner 1956): a
     * board closing part of its gap each year runs a steady step below its target payout while earnings grow with the
     * economy, so it opens there, with the manager's fixed effect. A trust's legal minimum binds from its first report,
     * on the taxable income the engine books: applied to the opening estimate of earnings, which carries the
     * depreciation a trust adds back, it opened trusts above their own policy and their records broke in the first
     * year. Opening at the target left the first review nothing to raise toward, and every record of increases broke in
     * its first year or two; opening at half of it left every payer to grow into the rest.
     */
    public function testEveryFirmOpensPayingWhereItsPolicyHasBroughtIt(): void
    {
        $macro = new MacroStateDTO();
        $trendGrowth = MacroConstants::TREND_REAL_GROWTH + $macro->inflationEma;
        $payers = 0;
        foreach ($this->openBoard($macro) as $ticker => $stock) {
            $quarterlyEps = max(0.0, (float) $stock->getTotalNetIncome() / (float) $stock->getSharesOutstanding() / 4.0);
            $annualSpeed = Valuation::lintnerAnnualSpeed((float) $stock->getDividendSpeed() * $stock->getDividendTier()->smoothing());
            $expected = $quarterlyEps * $stock->getManagementProfile()->appliedPayoutRatio((float) $stock->getTargetPayoutRatio()) * Valuation::lintnerSteadyDividendShare($annualSpeed, $trendGrowth);

            // Within 2%: the opening price loop and the anchor-stake pass re-strike earnings after the dividend is set,
            // and the target itself, the old opening, is 8-16% away.
            $this->assertEqualsWithDelta($expected, (float) $stock->getLastDividend(), max(1e-4, 0.02 * $expected), "{$ticker} opens off where its policy has brought it.");
            $payers += $expected > 0.0 ? 1 : 0;
        }

        $this->assertGreaterThan(60, $payers);
    }

    /**
     * A place on the dividend roll is a record of increases, not an adjustment speed. Every payer opens on a record
     * from the board's long-run distribution, so the roll opens at about the size it keeps (a third of payers, one in
     * thirteen a champion) instead of filling from empty, the longest records with the firms paying most, and a firm
     * paying nothing opens with no record.
     */
    public function testTheBoardOpensOnTheRollsLongRunShape(): void
    {
        $board = $this->openBoard(new MacroStateDTO());
        $payers = 0;
        $listed = 0;
        $champions = 0;
        $byPolicy = [];

        foreach ($board as $ticker => $stock) {
            $streak = $stock->getDividendStreakYears();
            if ((float) $stock->getLastDividend() <= 0.0) {
                $this->assertSame(0, $streak, "{$ticker} pays nothing but opens with a record of increases.");

                continue;
            }
            $payers++;
            $listed += $stock->getDividendTier()->isListed() ? 1 : 0;
            $champions += $streak >= DividendTier::CHAMPION_YEARS ? 1 : 0;
            $byPolicy[$stock->getPayoutPolicy()->value][] = $streak;
            $this->assertLessThanOrEqual(DividendTier::LONGEST_OPENING_RECORD, $streak);
        }

        // The mature firm paying most has been paying longest: a harvest payer opens on a longer record than a low one.
        $mean = static fn(array $streaks): float => array_sum($streaks) / count($streaks);
        $this->assertArrayHasKey(PayoutPolicy::Low->value, $byPolicy, 'The board opens with low payers.');
        $this->assertArrayHasKey(PayoutPolicy::Harvest->value, $byPolicy, 'The board opens with harvest payers.');
        $this->assertGreaterThan($mean($byPolicy[PayoutPolicy::Low->value]), $mean($byPolicy[PayoutPolicy::Harvest->value]));

        // Ninety-odd payers draw their records, so a share sits within about two binomial standard errors of its own.
        $this->assertEqualsWithDelta(0.32, $listed / $payers, 0.10);
        $this->assertEqualsWithDelta(0.08, $champions / $payers, 0.06);
    }

    /**
     * Omitting a dividend is bad news in every study of it (Healy & Palepu 1988: about -7%), and it can never be
     * good news for value. An operating company's fair value does not move at all; a trust or a utility, priced
     * on the income its policy pays, loses the income missed while the dividend is rebuilt.
     */
    public function testCuttingADividendNeverRaisesAFirmsFairValue(): void
    {
        $openingMacro = new MacroStateDTO();
        $incomePriced = 0;

        foreach ($this->openBoard($openingMacro) as $ticker => $stock) {
            $fairValue = function () use ($stock, $openingMacro): float {
                $health = $this->debtEngine->analyzeDebtHealth($stock, $openingMacro);

                return $this->marketEngine->strikeFairValue(MarketPricingContext::forStock($stock, $openingMacro, $health, $this->ledger))['perceived_fair_value'];
            };
            $paying = $fairValue();
            $stock->setLastDividend('0.00');
            $omitted = $fairValue();

            $this->assertLessThanOrEqual($paying * (1.0 + 1e-9), $omitted, "{$ticker} is worth more for omitting its dividend.");
            $incomePriced += $omitted < $paying * (1.0 - 1e-6) ? 1 : 0;
        }

        $this->assertGreaterThanOrEqual(4, $incomePriced, 'The REITs and utilities, priced on income, do lose value.');
    }

    /**
     * The board opens before any firm has reported a cash flow, and its first report must not re-rate it. Fair value
     * used to average the multiple with a DCF on one quarter's free cash flow: absent at the open, it switched on at
     * the first report and marked the board down about 10%, a thin positive cash flow halving a firm's earnings value.
     */
    public function testAReportedFreeCashFlowDoesNotRerateTheOpeningBoard(): void
    {
        $openingMacro = new MacroStateDTO();

        foreach ($this->openBoard($openingMacro) as $ticker => $stock) {
            $fairValue = function () use ($stock, $openingMacro): float {
                $health = $this->debtEngine->analyzeDebtHealth($stock, $openingMacro);

                return $this->marketEngine->strikeFairValue(MarketPricingContext::forStock($stock, $openingMacro, $health, $this->ledger))['perceived_fair_value'];
            };
            $opening = $fairValue();

            foreach (['0.01', '-5.00', '12.00'] as $freeCashFlowPerShare) {
                $stock->setFreeCashFlowPerShare($freeCashFlowPerShare);
                $this->assertEqualsWithDelta($opening, $fairValue(), $opening * 1e-9, "{$ticker} re-rates on a free cash flow of {$freeCashFlowPerShare} a share.");
            }
        }
    }

    /** Opening earnings are struck after the interest the firm's debt costs, at no less than its credit spread. */
    public function testALeveredFirmOpensOnEarningsAfterItsInterestBill(): void
    {
        $openingMacro = new MacroStateDTO();
        $checked = 0;

        foreach (SeedRows::all() as $stockData) {
            $strategy = Sectors::strategyFor($stockData['industry']);
            $debt = (float) ($stockData['wholesale_debt'] ?? 0.0);
            if ($strategy->isFinancial() || AnchorHoldings::forHolder($stockData['ticker']) !== [] || $debt < 0.25 * (float) $stockData['total_equity']) {
                continue;
            }

            $stock = (new Stock())->setTicker($stockData['ticker']);
            $this->builder->open($stock, $stockData, $openingMacro);

            $taxRate = $strategy->getEffectiveTaxRate($openingMacro->corporateTaxRate);
            $depreciationAddBack = $strategy->reportsFundsFromOperations()
                ? $strategy->getPlantCapital($stock, $stock->getInvestedCapital()) * FixedAssetLedger::depreciationRateFor($stock)
                : 0.0;
            $preInterest = ((float) $stock->getTotalRevenue() * (float) $stock->getOperatingMargin() + $depreciationAddBack + $strategy->calculateInterestIncome($stock, $openingMacro)) * (1.0 - $taxRate);
            $leastInterest = $debt * (float) $stock->getCreditSpread() * (1.0 - $taxRate);
            if ($preInterest <= $leastInterest) {
                continue;
            }

            $this->assertLessThan($preInterest - $leastInterest, (float) $stock->getTotalNetIncome(), sprintf('%s opens on earnings that ignore its interest bill.', $stockData['ticker']));
            $checked++;
        }

        $this->assertGreaterThanOrEqual(5, $checked);
    }

    /**
     * Two otherwise identical operating firms in the same industry open with the more levered one strictly more
     * volatile: leverage scales both systematic and idiosyncratic risk through DebtEngine's Hamada multiplier.
     */
    public function testLeveredFirmOpensMoreVolatileThanUnleveredPeerInSameIndustry(): void
    {
        $openingMacro = new MacroStateDTO();
        $baseRow = SeedRows::for('WING');

        $unleveredRow = $baseRow;
        $unleveredRow['wholesale_debt'] = 0.0;
        $unleveredRow['total_equity'] = (float) $baseRow['total_equity'] + (float) $baseRow['wholesale_debt'];

        $leveredRow = $baseRow;
        $leveredRow['wholesale_debt'] = ((float) $baseRow['total_equity'] + (float) $baseRow['wholesale_debt']) * 0.50;
        $leveredRow['total_equity'] = ((float) $baseRow['total_equity'] + (float) $baseRow['wholesale_debt']) * 0.50;

        $unlevered = (new Stock())->setTicker('UNLV');
        $levered = (new Stock())->setTicker('LEVR');
        $this->builder->open($unlevered, $unleveredRow, $openingMacro);
        $this->builder->open($levered, $leveredRow, $openingMacro);

        $this->assertGreaterThan((float) $unlevered->getVolatility(), (float) $levered->getVolatility());
        $this->assertGreaterThan((float) $unlevered->getJumpVol(), (float) $levered->getJumpVol());
    }

    /**
     * Every opened firm satisfies the risk-ladder invariants: no firm opens on the 20% idiosyncratic floor,
     * calibratedJumpParameters() never rescales any opening row, Merton's calibrated asset volatility round-trips
     * within 15% of sqrt((beta_A x MACRO_VOL_BASE_ANCHOR)^2 + sigma_idio,A^2) for every non-financial, and turnover
     * and realizedVarianceEma match the converged opening volatility.
     */
    public function testOpeningRiskRoundTripsCleanlyWithNoFloorOrJumpRescaling(): void
    {
        $openingMacro = new MacroStateDTO();
        $board = $this->openBoard($openingMacro);
        $rowsByTicker = [];
        foreach (SeedRows::all() as $row) {
            $rowsByTicker[$row['ticker']] = $row;
        }

        foreach ($board as $ticker => $stock) {
            $row = $rowsByTicker[$ticker];
            $health = $this->debtEngine->analyzeDebtHealth($stock, $openingMacro);
            $vol = (float) $stock->getVolatility();
            $lambda = (float) $stock->getJumpIntensity();
            $jumpVol = (float) $stock->getJumpVol();

            // 1. Equity leverage multiplier obeys the same law as levered beta.
            $assetBeta = (float) $stock->getBeta();
            $this->assertEqualsWithDelta($health->leveredBeta, $assetBeta * $health->equityLeverageMultiplier, 1e-9, "{$ticker}: L disagrees with leveredBeta / beta.");

            // 2. No firm opens on the 20% idiosyncratic variance floor.
            $sysVar = (($health->leveredBeta * MacroConstants::MACRO_VOL_BASE_ANCHOR) ** 2)
                + VarianceBudget::systemicJumpMoments($health->leveredBeta)['variance'];
            $rawIdioVar = ($vol * $vol) - $sysVar;
            $this->assertGreaterThanOrEqual(0.20 * $vol * $vol, $rawIdioVar, "{$ticker} opened on the 20% idiosyncratic floor.");

            // 3. calibratedJumpParameters() never rescales an opening row.
            $calibrated = VarianceBudget::calibratedJumpParameters($vol, $health->leveredBeta, $lambda, $jumpVol);
            $this->assertEqualsWithDelta($jumpVol, $calibrated['scale'], 1e-12, "{$ticker}'s opening jump_vol was rescaled by VarianceBudget.");

            // 4. Merton sigma_V round-trips within 15% of unlevered asset risk for every non-financial.
            if (!Sectors::strategyFor($stock->getIndustry())->isFinancial()) {
                $stepVol = IndustryRisks::businessForRow($row)->vol();
                $unleveredVol = sqrt((($assetBeta * MacroConstants::MACRO_VOL_BASE_ANCHOR) ** 2) + ($stepVol * $stepVol));
                $assetVol = $stock->getAssetVolatility();
                $this->assertNotNull($assetVol, "{$ticker} has no calibrated asset volatility.");
                $this->assertEqualsWithDelta(1.0, $assetVol / $unleveredVol, 0.15, sprintf('%s Merton sigma_V %.4f vs unlevered %.4f.', $ticker, $assetVol, $unleveredVol));
            }

            // 5. Post-convergence vol, turnover and realized variance EMA agree.
            $this->assertSame($stock->getVolatility(), $stock->getCurrentVolatility(), "{$ticker} currentVolatility disagrees with volatility.");
            $this->assertEqualsWithDelta(LiquidityEngine::structuralTurnoverRatio($vol), (float) $stock->getTurnoverRatio(), 1e-12, "{$ticker} turnover ratio stale.");
            $this->assertEqualsWithDelta($vol ** 2, (float) $stock->getRealizedVarianceEma(), 1e-12, "{$ticker} realizedVarianceEma stale.");
        }
    }

    /**
     * Continuity at the open: every firm's dynamic long-run volatility and jump scale computed by
     * MarketPricingContext::forStock() match its stored opening volatility and jump_vol within 1e-4, calibrated
     * jump parameters never rescale, and clearing businessVolatility heals from IndustryRisks.
     */
    public function testDynamicLongRunRiskMatchesOpeningVolatilityAndJumpScaleForEveryFirm(): void
    {
        $openingMacro = new MacroStateDTO();
        $board = $this->openBoard($openingMacro);
        $rowsByTicker = [];
        foreach (SeedRows::all() as $row) {
            $rowsByTicker[$row['ticker']] = $row;
        }

        foreach ($board as $ticker => $stock) {
            $expectedBizVol = IndustryRisks::businessForRow($rowsByTicker[$ticker])->vol();
            $this->assertSame($expectedBizVol, $stock->getBusinessVolatility(), "{$ticker} missing opening businessVolatility.");

            $health = $this->debtEngine->analyzeDebtHealth($stock, $openingMacro);
            $ctx = MarketPricingContext::forStock($stock, $openingMacro, $health, $this->ledger);

            $this->assertEqualsWithDelta((float) $stock->getVolatility(), $ctx->longTermVolatility, 1e-4, "{$ticker} sigma_LR diverges from opening volatility.");
            $this->assertSame((float) $stock->getVolatility(), $stock->getLeveredVolatility(), "{$ticker} opening leveredVolatility disagrees with opening volatility.");
            // Stored jump_vol is floored to 4 dp on the 4-dp stored volatility, so unrounded sigma_LR's jump scale can
            // sit up to ~1.5e-4 above it (max ~1.24e-4 on PLZA); the stored-volatility jump scale itself is within
            // 1e-4.
            $this->assertEqualsWithDelta((float) $stock->getJumpVol(), $ctx->jumpVol, 1.5e-4, "{$ticker} derived jump scale diverges from opening jump_vol.");
            $storedVolJumpScale = VarianceBudget::jumpScaleForVarianceShare(
                sqrt(VarianceBudget::longTermIdiosyncraticVariance((float) $stock->getVolatility(), $health->leveredBeta)),
                $ctx->lambda,
            );
            $this->assertEqualsWithDelta((float) $stock->getJumpVol(), $storedVolJumpScale, 1e-4, "{$ticker} stored jump_vol diverges from 4-dp floor of stored-vol jump scale.");

            $calibrated = VarianceBudget::calibratedJumpParameters($ctx->longTermVolatility, $ctx->beta, $ctx->lambda, $ctx->jumpVol);
            $this->assertEqualsWithDelta($ctx->jumpVol, $calibrated['scale'], 1e-12, "{$ticker} derived jump scale was rescaled.");

            $stock->setBusinessVolatility(null);
            $this->assertSame($expectedBizVol, IndustryRisks::resolveBusinessVolatility($stock), "{$ticker} failed to heal null businessVolatility.");
        }
    }

    /**
     * Direction: raising an operating firm's D/E raises sigma_LR without shrinking its idiosyncratic variance share;
     * zeroing net debt drops sigma_LR to the unlevered value. A financial's sigma_LR does not move with D/E.
     */
    public function testReleveringRaisesLongRunVolatilityAndPreservesIdiosyncraticShare(): void
    {
        $openingMacro = new MacroStateDTO();
        $board = $this->openBoard($openingMacro);

        $firm = $board['FALC'];
        $openHealth = $this->debtEngine->analyzeDebtHealth($firm, $openingMacro);
        $openCtx = MarketPricingContext::forStock($firm, $openingMacro, $openHealth, $this->ledger);
        $openIdioShare = VarianceBudget::longTermIdiosyncraticVariance($openCtx->longTermVolatility, $openCtx->beta)
            / ($openCtx->longTermVolatility ** 2);

        // Double net debt (raising D/E well above opening): sigma_LR rises and idiosyncratic share does not fall.
        $openDebt = (float) $firm->getWholesaleDebt();
        $firm->setWholesaleDebt((string) ($openDebt * 2.0));
        $highHealth = $this->debtEngine->analyzeDebtHealth($firm, $openingMacro);
        $highCtx = MarketPricingContext::forStock($firm, $openingMacro, $highHealth, $this->ledger);
        $highIdioShare = VarianceBudget::longTermIdiosyncraticVariance($highCtx->longTermVolatility, $highCtx->beta)
            / ($highCtx->longTermVolatility ** 2);

        $this->assertGreaterThan($openHealth->equityLeverageMultiplier, $highHealth->equityLeverageMultiplier);
        $this->assertGreaterThan($openCtx->longTermVolatility, $highCtx->longTermVolatility);
        $this->assertGreaterThanOrEqual($openIdioShare - 1e-9, $highIdioShare);

        // Zero net debt (D/E = 0, no excess net cash): sigma_LR falls to the unlevered value (L = 1).
        $firm->setWholesaleDebt((string) $firm->getCorporateTreasury());
        $unleveredHealth = $this->debtEngine->analyzeDebtHealth($firm, $openingMacro);
        $unleveredCtx = MarketPricingContext::forStock($firm, $openingMacro, $unleveredHealth, $this->ledger);
        $expectedUnlevered = VarianceBudget::equityVolatilityFromBusinessRisk(
            (float) $firm->getBeta(),
            1.0,
            IndustryRisks::resolveBusinessVolatility($firm),
        );

        $this->assertLessThan($openCtx->longTermVolatility, $unleveredCtx->longTermVolatility);
        $this->assertEqualsWithDelta($expectedUnlevered, $unleveredCtx->longTermVolatility, 1e-6);

        // Financial firm: doubling wholesale debt leaves L = 1 and sigma_LR unchanged.
        $bank = $board['LAKE'];
        $bankOpenCtx = MarketPricingContext::forStock($bank, $openingMacro, $this->debtEngine->analyzeDebtHealth($bank, $openingMacro), $this->ledger);
        $bank->setWholesaleDebt((string) ((float) $bank->getWholesaleDebt() * 2.0));
        $bankHighCtx = MarketPricingContext::forStock($bank, $openingMacro, $this->debtEngine->analyzeDebtHealth($bank, $openingMacro), $this->ledger);

        $this->assertEqualsWithDelta($bankOpenCtx->longTermVolatility, $bankHighCtx->longTermVolatility, 1e-12);
    }

    /**
     * Every firm opens with its stored Stock::$creditSpread equal to its through-the-cycle spread at its converged
     * opening rating, for pages and admin inspection.
     */
    public function testEveryFirmOpensWithStoredCreditSpreadEqualToThroughTheCycleSpreadAtItsOpeningRating(): void
    {
        $openingMacro = new MacroStateDTO();
        $ratedDebtEngine = new DebtEngine($this->math, CorporateMetrics::getInstance(), new CreditRatingAgency());
        $builder = new OpeningBoardBuilder($this->math, $ratedDebtEngine, $this->marketEngine, $this->ledger);

        $board = [];
        $spheres = [];
        foreach (SeedRows::all() as $stockData) {
            $stock = (new Stock())->setTicker($stockData['ticker']);
            $sphere = $builder->open($stock, $stockData, $openingMacro);
            if ($sphere !== null) {
                $spheres[] = $sphere;
            }
            $board[$stockData['ticker']] = $stock;
        }
        $builder->openSpheres($spheres, $board, $openingMacro);

        $seenRatings = [];
        foreach ($board as $ticker => $stock) {
            $seenRatings[$stock->getCreditRating()] = true;
            $lgd = Sectors::strategyFor($stock->getIndustry())->getLossGivenDefault();
            $expectedTtc = DebtEngine::throughTheCycleCreditSpread($stock, $openingMacro, $lgd);

            $this->assertSame(
                Decimal::format($expectedTtc, 4),
                $stock->getCreditSpread(),
                "{$ticker} ({$stock->getCreditRating()}) opening stored creditSpread must match rounded TTC spread.",
            );
            $this->assertEqualsWithDelta($expectedTtc, (float) $stock->getCreditSpread(), 1e-4, $ticker);
        }

        $this->assertGreaterThanOrEqual(3, count($seenRatings), 'Opening ratings must span multiple classes.');
    }

    /**
     * In-scope firms derive opening coupon and floating share from their converged opening rating inside
     * strikeOpeningPrice(), strike opening net income on those same debt charges, and converge below the pass ceiling.
     */
    public function testEveryInScopeFirmOpensWithDerivedCouponFloatingShareNetIncomeAndFastConvergence(): void
    {
        $openingMacro = new MacroStateDTO();
        $ratedDebtEngine = new DebtEngine($this->math, CorporateMetrics::getInstance(), new CreditRatingAgency());
        $builder = new OpeningBoardBuilder($this->math, $ratedDebtEngine, $this->marketEngine, $this->ledger);

        $expectedFloatingTable = [
            'AAA' => 0.10,
            'AA' => 0.10,
            'A' => 0.15,
            'BBB' => 0.20,
            'BB' => 0.35,
            'B' => 0.50,
            'CCC' => 0.60,
            'D' => 0.60,
        ];
        $classCurveLgd = 1.0 - CorporateDefaultService::RECOVERY_SENIOR_UNSECURED;
        $cycle = $openingMacro->macroCreditSpreadTrend / $openingMacro->macroCreditSpreadEma;

        $board = [];
        $spheres = [];
        $couponDerivedCount = 0;
        $floatingDerivedCount = 0;
        $nonFinancialCount = 0;

        foreach (SeedRows::all() as $stockData) {
            $stock = (new Stock())->setTicker($stockData['ticker']);
            $sphere = $builder->open($stock, $stockData, $openingMacro);
            $this->assertLessThan(
                OpeningBoardBuilder::MAX_OPENING_PRICE_PASSES,
                $builder->getLastOpeningPricePasses(),
                "{$stockData['ticker']} reached MAX_OPENING_PRICE_PASSES during open().",
            );
            if ($sphere !== null) {
                $spheres[] = $sphere;
            }
            $board[$stockData['ticker']] = $stock;
        }
        $builder->openSpheres($spheres, $board, $openingMacro);
        $this->assertLessThan(
            OpeningBoardBuilder::MAX_OPENING_PRICE_PASSES,
            $builder->getLastOpeningPricePasses(),
            'Sphere reached MAX_OPENING_PRICE_PASSES during openSpheres().',
        );

        foreach ($board as $ticker => $stock) {
            $strategy = Sectors::strategyFor($stock->getIndustry());
            $rating = $stock->getCreditRating();
            $lgd = $strategy->getLossGivenDefault();

            $ttcSpread = CreditRisk::calculateRecoveryAdjustedSpread(
                CreditRisk::calculateRatingClassSpread(
                    $rating,
                    $openingMacro->macroCreditSpreadEma * $cycle,
                    $openingMacro->highYieldCreditSpreadEma * $cycle,
                ),
                $lgd,
                $classCurveLgd,
            );
            $expectedCoupon = $openingMacro->yield5yEma + $ttcSpread;

            if ($strategy->derivesOpeningCoupon()) {
                $couponDerivedCount++;
                $this->assertEqualsWithDelta(
                    $expectedCoupon,
                    (float) $stock->getHistoricalFixedRate(),
                    1e-9,
                    "{$ticker} ({$rating}) derived fixed coupon mismatch.",
                );
            }

            if ($strategy->derivesOpeningFloatingShare()) {
                $floatingDerivedCount++;
                $this->assertEqualsWithDelta(
                    $expectedFloatingTable[$rating],
                    (float) $stock->getFloatingDebtRatio(),
                    1e-9,
                    "{$ticker} ({$rating}) derived floating share mismatch.",
                );
            }

            if (!$strategy->isFinancial()) {
                $nonFinancialCount++;
                $spotSpread = CreditRisk::calculateRecoveryAdjustedSpread(
                    CreditRisk::calculateRatingClassSpread(
                        $rating,
                        $openingMacro->macroCreditSpreadEma,
                        $openingMacro->highYieldCreditSpreadEma,
                    ),
                    $lgd,
                    $classCurveLgd,
                );
                $floatingRate = $openingMacro->floatingReferenceRate() + $spotSpread;
                $interestExpense = FixedIncome::fixedFloatingInterest(
                    (float) $stock->getTotalDebt(),
                    (float) $stock->getFloatingDebtRatio(),
                    (float) $stock->getHistoricalFixedRate(),
                    $floatingRate,
                );
                $ebit = (float) $stock->getTotalRevenue() * (float) $stock->getOperatingMargin();
                $interestIncome = $strategy->calculateInterestIncome($stock, $openingMacro);
                $effectiveTaxRate = $strategy->getEffectiveTaxRate($openingMacro->corporateTaxRate);
                $expectedNi = max(0.0, ($ebit - $interestExpense + $interestIncome) * (1.0 - $effectiveTaxRate));
                if ($strategy->reportsFundsFromOperations()) {
                    $expectedNi += $strategy->getPlantCapital($stock, $stock->getInvestedCapital()) * FixedAssetLedger::depreciationRateFor($stock);
                }

                // Stock::setEarningsPerShare() overwrites totalNetIncome from the 2-decimal rounded EPS.
                $shares = max(1.0, (float) $stock->getSharesOutstanding());
                $expectedStoredNi = round($expectedNi / $shares, 2) * $shares;

                $this->assertEqualsWithDelta(
                    $expectedStoredNi,
                    (float) $stock->getTotalNetIncome(),
                    1.0,
                    "{$ticker} ({$rating}) opening net income must reflect converged debt charges.",
                );
            }
        }

        $this->assertSame(95, $couponDerivedCount);
        $this->assertSame(86, $floatingDerivedCount);
        $this->assertSame(82, $nonFinancialCount);
    }

    /**
     * Direction: two otherwise identical firms separated by a credit grade open with the lower-rated one paying the
     * higher fixed coupon and carrying at least as high a floating-rate share.
     */
    public function testLowerRatedTwinOpensWithHigherCouponAndFloatingShare(): void
    {
        $openingMacro = new MacroStateDTO();
        $ratedDebtEngine = new DebtEngine($this->math, CorporateMetrics::getInstance(), new CreditRatingAgency());
        $builder = new OpeningBoardBuilder($this->math, $ratedDebtEngine, $this->marketEngine, $this->ledger);

        $baseRow = SeedRows::for('WING');
        $leveragedRow = $baseRow;
        $leveragedRow['wholesale_debt'] = $baseRow['wholesale_debt'] * 3.0;

        $higher = (new Stock())->setTicker('WING');
        $lower = (new Stock())->setTicker('WING');
        $builder->open($higher, $baseRow, $openingMacro);
        $builder->open($lower, $leveragedRow, $openingMacro);

        $this->assertLessThan(
            CreditRatingAgency::rank($higher->getCreditRating()),
            CreditRatingAgency::rank($lower->getCreditRating()),
            'Heavier leverage twin must land on a lower credit rating.',
        );
        $this->assertGreaterThan(
            (float) $higher->getHistoricalFixedRate(),
            (float) $lower->getHistoricalFixedRate(),
            'Lower-rated twin must open with a strictly higher fixed coupon.',
        );
        $this->assertGreaterThanOrEqual(
            (float) $higher->getFloatingDebtRatio(),
            (float) $lower->getFloatingDebtRatio(),
            'Lower-rated twin must open with at least as high a floating debt share.',
        );
    }

    /**
     * A firm whose rating transitions off entity default BBB during strikeOpeningPrice() opens with the converged
     * rating's coupon and floating share rather than BBB's.
     */
    public function testFirmWhoseRatingMovesDuringOpeningLoopOpensAtConvergedDebtTerms(): void
    {
        $openingMacro = new MacroStateDTO();
        $ratedDebtEngine = new DebtEngine($this->math, CorporateMetrics::getInstance(), new CreditRatingAgency());
        $builder = new OpeningBoardBuilder($this->math, $ratedDebtEngine, $this->marketEngine, $this->ledger);

        foreach (['HUMM' => 'A', 'LOON' => 'BB'] as $ticker => $expectedRating) {
            $stock = (new Stock())->setTicker($ticker);
            $builder->open($stock, SeedRows::for($ticker), $openingMacro);

            $this->assertSame($expectedRating, $stock->getCreditRating(), "{$ticker} must transition off default BBB.");
            $this->assertSame(
                CreditRatingAgency::openingFloatingDebtRatio($expectedRating),
                (float) $stock->getFloatingDebtRatio(),
                "{$ticker} floating share must reflect converged rating {$expectedRating}, not BBB.",
            );

            $lgd = Sectors::strategyFor($stock->getIndustry())->getLossGivenDefault();
            $expectedCoupon = DebtEngine::openingFixedCoupon($stock, $openingMacro, $lgd);
            $this->assertEqualsWithDelta(
                $expectedCoupon,
                (float) $stock->getHistoricalFixedRate(),
                1e-9,
                "{$ticker} coupon must reflect converged rating {$expectedRating}, not BBB.",
            );
            $this->assertNotEqualsWithDelta(
                CreditRatingAgency::openingFloatingDebtRatio('BBB'),
                (float) $stock->getFloatingDebtRatio(),
                1e-6,
            );
        }
    }
}
