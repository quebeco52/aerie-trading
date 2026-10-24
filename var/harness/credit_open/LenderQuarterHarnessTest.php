<?php

declare(strict_types=1);

use App\DTO\MacroStateDTO;
use App\Entity\CorporateReport;
use App\Entity\Stock;

require_once __DIR__ . '/../lib/BoardRun.php';

/**
 * credit_open: the whole-board run (var/harness/lib/BoardRun.php) with interbank_ref's report fields (pre-tax income,
 * tangible equity, baseline ROE, interbank spread, policy EMA, primary-market access), its cap-weighted board return
 * per tick (splits not restated), macro rows and VR_OUT.
 *
 * LQ_OUT=<json>: each lender's franchise calibration at seed (the lines its pricing assumes) and every lender report's
 * realised lines with the credit-cycle macro fields it was filed under; each non-financial report's receivables
 * provision.
 */
final class LenderQuarterHarnessTest extends BoardRun
{
    /** @var array<string, array{0: float, 1: float}> last price and cap per name */
    private array $irPrev = [];
    /** @var list<float> */
    private array $irIdx = [];
    /** @var list<array<string, float>> */
    private array $irMacro = [];
    /** @var array<int, float> */
    private array $irMcoe0 = [];
    private int $nonFinite = 0;
    /** @var array<string, mixed> VR_OUT: per-tick price, reported vol, spread, ADV; earnings rows; option skew. */
    private array $vr = [];

    protected function onStart(): void
    {
        $this->lqCalib = $this->lqCalibrate($this->stocks);
    }

    protected function afterStocks(array $result): void
    {
        $tick = $this->tick;
        $tpy = $this->tpy;
        $m = $this->currentMacro;
        // Cap-weighted board return per tick (last tick's caps as weights), non-finite counts, and one macro row a
        // quarter (plus tick 1, for the pairing check).
        $wsum = 0.0;
        $rsum = 0.0;
        foreach ($result['updates'] as $u) {
            $tk = (string) $u['ticker'];
            $px = (float) $u['price'];
            $cap = (float) ($u['market_cap'] ?? 0.0);
            if (!is_finite($px) || !is_finite($cap)) {
                $this->nonFinite++;
                continue;
            }
            if (empty($u['is_bankrupt']) && isset($this->irPrev[$tk]) && $this->irPrev[$tk][0] > 0.0 && $px > 0.0) {
                $wsum += $this->irPrev[$tk][1];
                $rsum += $this->irPrev[$tk][1] * ($px / $this->irPrev[$tk][0] - 1.0);
            }
            $this->irPrev[$tk] = [$px, $cap];
        }
        $this->irIdx[] = $wsum > 0.0 ? $rsum / $wsum : 0.0;
        if ($tick === 1) {
            $this->irMcoe0 = [$m->marketCostOfEquity(), (new MacroStateDTO())->marketCostOfEquity()];
        }
        if ($tick === 1 || $tick % intdiv($tpy, 4) === 0) {
            $this->irMacro[] = ['t' => round($tick / $tpy, 3), 'pol' => $m->policyRate, 'pole' => $m->policyRateEma,
                'ib' => $m->interbankLiquiditySpreadEma, 'ibr' => $m->interbankLiquiditySpread, 'gap' => $m->outputGap,
                'infl' => $m->inflation, 'u' => $m->unemploymentRate, 'erp' => $m->equityRiskPremium, 'vol' => $m->marketVolatility];
        }
        if (getenv('VR_OUT')) {
            foreach ($result['updates'] as $u) {
                if (!empty($u['is_bankrupt'])) {
                    continue;
                }
                $this->vr['tk'][$u['ticker']][$tick] = [(float) $u['price'], (float) $u['current_volatility'] / 100.0,
                    (float) ($u['spread_bps'] ?? 0.0), (float) ($u['adv_shares'] ?? 0.0), (float) ($u['volume'] ?? 0.0),
                    (float) ($u['market_cap'] ?? 0.0)];
            }
            if ($tick % intdiv($tpy, 4) === 0) {
                $opt = static::getContainer()->get(App\Service\Market\Option\OptionPricingEngine::class);
                foreach ($this->list as $s) {
                    if (!$s->isBankrupt()) {
                        $sf = $opt->surfaceFor($s, 30.0 / 360.0);
                        $this->vr['skew'][$s->getTicker()][$tick] = [$sf['skewness'], $sf['atm_volatility']];
                    }
                }
            }
        }
    }

    protected function reportRow(array $row, CorporateReport $report): array
    {
        $this->lqCapture($report);
        if (getenv('VR_OUT')) {
            // Reported EPS against consensus, keyed to the tick the report lands on.
            $this->vr['er'][] = [$report->getStock()->getTicker(), (int) round($this->currentTime * $this->tpy),
                $report->getReportedEps() !== null ? (float) $report->getReportedEps() : null,
                $report->getConsensusEps() !== null ? (float) $report->getConsensusEps() : null];
        }
        $m = $this->currentMacro;

        // Lender returns against target, funding stress and market access.
        return self::insertAfter($row, 'aris', [
            'pti' => (float) $report->getPreTaxIncome(),
            'teq' => $report->getStock()->getTangibleEquity(),
            'broe' => (float) ($report->getStock()->getBaselineRoe() ?? 0.0),
            'ib' => $m?->interbankLiquiditySpreadEma ?? 0.0,
            'pol' => $m?->policyRateEma ?? 0.0,
            'open' => $m !== null && $this->debtEngine !== null ? $this->debtEngine->primaryMarketOpenTo($report->getStock(), $m) : null,
        ]);
    }

    protected function record(array $record): array
    {
        return $record + ['idx' => $this->irIdx, 'macro' => $this->irMacro, 'mcoe0' => $this->irMcoe0, 'nonfinite' => $this->nonFinite];
    }

    protected function onEnd(): void
    {
        if (getenv('VR_OUT')) {
            $this->vr['dead'] = $this->dead;
            file_put_contents((string) getenv('VR_OUT'), json_encode($this->vr));
        }
        if (getenv('LQ_OUT')) {
            file_put_contents((string) getenv('LQ_OUT'), json_encode(['seed' => $this->seed, 'calib' => $this->lqCalib, 'rows' => $this->lqRows, 'nonfin' => $this->lqNonFin], JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
    }

    private const LQ = ['LAKE', 'RIVR', 'PLVR', 'STRK', 'EGRT', 'BNTH', 'POOL', 'TALN'];
    /** @var array<string, mixed> */
    private array $lqCalib = [];
    /** @var list<array<string, mixed>> */
    private array $lqRows = [];
    /**
     * @var list<array{0: float, 1: string, 2: float, 3: float, 4: float}> t, ticker, receivables provision,
     *                                                                     receivables, revenue
     */
    private array $lqNonFin = [];

    private static function lqCall(object $m, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($m, $method))->invoke($m, ...$args);
    }

    /** @param array<string, Stock> $stocks */
    private function lqCalibrate(array $stocks): array
    {
        $out = [];
        $open = new MacroStateDTO();
        foreach (self::LQ as $t) {
            $s = clone $stocks[$t];
            $m = App\Data\Company\Sectors::strategyFor($s->getIndustry());
            $row = ['model' => (new ReflectionClass($m))->getShortName(), 'te' => $s->getTangibleEquity(), 'eq' => (float) $s->getTotalEquity(),
                'gw' => (float) $s->getGoodwill(), 'cash' => (float) $s->getCorporateTreasury(), 'D' => (float) $s->getCustomerDeposits(),
                'W' => (float) $s->getWholesaleDebt(), 'debt' => (float) $s->getTotalDebt(), 'f' => (float) $s->getFloatingDebtRatio(),
                'H' => (float) $s->getHistoricalFixedRate(), 'gross' => (float) $s->getEarningAssets(), 'allow' => (float) $s->getCreditLossAllowance(),
                'ea' => $m->resolveEarningAssets($s), 'tax' => $open->corporateTaxRate, 'broe' => (float) $s->getBaselineRoe(),
                'om' => (float) $s->getOperatingMargin(), 'fcr' => (float) $s->getFixedCostRatio(),
                'int_exp' => $this->debtEngine->calculateInterestExpense(clone $s, $open)->interestExpense,
                'mom_keys' => array_keys($s->getEarningsMomentumZ() ?? [])];
            if ($m instanceof App\Service\Model\Sector\CommercialBankBusinessModel) {
                $row += ['roe_tgt' => self::lqCall($m, 'resolveCalibrationRoe', $s, $open), 'cal_yield' => self::lqCall($m, 'resolveCalibrationYield', $s, $open),
                    'pricing' => self::lqCall($m, 'resolveFranchisePricing', $s), 'opcost' => $m->getOperatingCostToEarningAssets($s),
                    'ttc_rate' => $m->getThroughTheCycleCreditLossRate($s), 'ttc_prov_rate' => $m->resolveThroughTheCycleCreditProvision($s, 1.0),
                    'treas_inc' => self::lqCall($m, 'resolveTreasuryIncome', $s, $open), 'int_yield' => $m->resolveInterestYield($s, $open),
                    'fwd' => $m->getForwardCreditLossMultiplier($s, $open), 'loan_share' => $m->getLoanShareOfEarningAssets(),
                    'ivs' => self::lqCall($m, 'resolveInterestVolumeShift', $open), 'fvs' => self::lqCall($m, 'resolveFeeVolumeShift', $s, $open),
                    'macro_phys' => $m->getMacroPhysics($s, $open), 'horizon' => $m->getCreditLossHorizonYears()];
            } elseif ($m instanceof App\Service\Model\Sector\ShadowBankBusinessModel) {
                $row += ['roe_tgt' => self::lqCall($m, 'resolveCalibrationRoe', $s, $open), 'pricing' => self::lqCall($m, 'resolveFranchisePricing', $s),
                    'ttc_rate' => $m->getThroughTheCycleCreditLossRate($s), 'ttc_prov_rate' => $m->resolveThroughTheCycleCreditProvision($s, 1.0),
                    'fwd' => $m->getForwardCreditLossMultiplier($s, $open)];
            }
            $out[$t] = $row;
        }

        return $out;
    }

    private function lqCapture(CorporateReport $r): void
    {
        $s = $r->getStock();
        $t = $s->getTicker();
        if (!App\Data\Company\Sectors::strategyFor($s->getIndustry())->isFinancial()) {
            $this->lqNonFin[] = [round($this->currentTime, 4), $t, (float) $r->getReceivablesProvision(), (float) $r->getReceivables(), (float) $r->getRevenue()];
        }
        if (!in_array($t, self::LQ, true)) {
            return;
        }
        $m = $this->currentMacro;
        $strat = App\Data\Company\Sectors::strategyFor($s->getIndustry());
        $mom = $s->getEarningsMomentumZ() ?? [];
        $keep = [];
        foreach ($mom as $k => $v) {
            if (is_scalar($v) && (str_starts_with((string) $k, 'state:') || str_starts_with((string) $k, 'z:') || !str_contains((string) $k, ':'))) {
                $keep[$k] = $v;
            }
        }
        $row = ['t' => round($this->currentTime, 4), 'tk' => $t, 'rev' => (float) $r->getRevenue(), 'streams' => $r->getRevenueStreams(),
            'opc' => (float) $r->getOperatingCosts(), 'dep' => (float) $r->getDepreciation(), 'ebitda' => (float) $r->getEbitda(), 'ebit' => (float) $r->getEbit(),
            'prov' => (float) $r->getCreditLossProvision(), 'nco' => (float) $r->getNetChargeOffs(), 'gross' => (float) $r->getEarningAssets(),
            'allow' => (float) $r->getCreditLossAllowance(), 'ii' => (float) $r->getInterestIncome(), 'ie' => (float) $r->getInterestExpense(),
            'pti' => (float) $r->getPreTaxIncome(), 'tax' => (float) $r->getTaxPaid(), 'ni' => (float) $r->getNetIncome(), 'levy' => (float) $r->getBankLevy(),
            'secmark' => (float) $r->getUnrealizedSecuritiesMark(), 'asl' => (float) $r->getAssetSaleLoss(), 'sbc' => (float) $r->getStockCompensation(),
            'nlo' => (float) $r->getNetLoanOriginations(), 'nim' => $r->getNetInterestMargin(), 'gwi' => (float) $r->getGoodwillImpairment(),
            'rs' => (float) $r->getReceivablesProvision(), 'kpis' => $r->getReportedKpis(), 'det' => $r->getStreamDetails(),
            'te' => $s->getTangibleEquity(), 'eq' => (float) $s->getTotalEquity(), 'cash' => (float) $s->getCorporateTreasury(), 'D' => (float) $s->getCustomerDeposits(),
            'W' => (float) $s->getWholesaleDebt(), 'debt' => (float) $s->getTotalDebt(), 'div' => (float) $r->getDividendPaid(), 'bb' => (float) $r->getStockBuybacks(),
            'mom' => $keep];
        if ($m !== null) {
            $row['mac'] = ['rdE' => $m->retailDefaultRateEma, 'rdT' => $m->retailDefaultRateTrend, 'cdE' => $m->corporateDefaultRateEma, 'cdT' => $m->corporateDefaultRateTrend,
                'csE' => $m->macroCreditSpreadEma, 'csT' => $m->macroCreditSpreadTrend, 'rpE' => $m->recessionProbabilityEma, 'rpT' => $m->recessionProbabilityTrend,
                'gapE' => $m->outputGapEma, 'sloos' => $m->sloosTighteningIndexEma, 'hs' => $m->housingStartsIndexEma, 'm2' => $m->moneySupplyGrowthEma,
                'm2T' => $m->moneySupplyGrowthTrend, 'cgap' => $m->creditToGdpGapEma, 'polE' => $m->policyRateEma, 'y2' => $m->yield2yEma, 'y5' => $m->yield5yEma,
                'y10' => $m->yield10yEma, 'fref' => $m->floatingReferenceRate(), 'tax' => $m->corporateTaxRate, 'rpi' => $m->residentialPropertyIndexEma,
                'cpi_re' => $m->commercialPropertyIndexEma, 'cpl' => $m->consumerPriceLevel, 'infE' => $m->inflationEma, 'levy' => $m->bankLevyRate];
            if ($strat instanceof App\Service\Model\Sector\CommercialBankBusinessModel) {
                $c = clone $s;
                $row['mdl'] = ['ttc' => $strat->getThroughTheCycleCreditLossRate($c), 'fwd' => $strat->getForwardCreditLossMultiplier($c, $m),
                    'ivs' => self::lqCall($strat, 'resolveInterestVolumeShift', $m), 'fvs' => self::lqCall($strat, 'resolveFeeVolumeShift', $c, $m),
                    'mp' => $strat->getMacroPhysics($c, $m), 'iy' => $strat->resolveInterestYield($c, $m), 'ea' => $strat->resolveEarningAssets($c),
                    'opcost' => $strat->getOperatingCostToEarningAssets($c), 'treas' => self::lqCall($strat, 'resolveTreasuryIncome', $c, $m)];
            }
        }
        $this->lqRows[] = $row;
    }
}
