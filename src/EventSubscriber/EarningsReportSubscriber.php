<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Event\EarningsReportedEvent;
use App\Service\Math\Decimal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use App\Data\Macro\MacroFieldCatalog;
use App\Data\Company\Sectors;
use App\Service\Corporate\DebtEngine;

class EarningsReportSubscriber implements EventSubscriberInterface
{
    // --- Driver Attribution Display Bands ---

    /** Share of the stream's revenue at or above which a macro driver prints at full (3-pip) strength: the SEC SAB 99 5% rule of thumb. */
    private const DRIVER_STRENGTH_HIGH = 0.05;

    /** Share of the stream's revenue at or above which a macro driver prints at moderate (2-pip) strength. */
    private const DRIVER_STRENGTH_MODERATE = 0.01;

    /** Share of the stream's revenue below which a macro driver is dropped rather than printed as noise. */
    private const DRIVER_MATERIALITY_FLOOR = 0.0025;

    /** |Z| at or above which operational momentum is material enough to name as its own driver. */
    private const MOMENTUM_MATERIALITY_FLOOR = 0.15;

    /** |Z| at or above which operational momentum prints at moderate (2-pip) strength: one standard deviation. */
    private const MOMENTUM_STRENGTH_MODERATE = 1.0;

    /** |Z| at or above which operational momentum prints at full (3-pip) strength: two standard deviations. */
    private const MOMENTUM_STRENGTH_HIGH = 2.0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DebtEngine $debtEngine
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            EarningsReportedEvent::class => 'onEarningsReported',
        ];
    }

    public function onEarningsReported(EarningsReportedEvent $event): void
    {
        $ctx = $event->getContext();
        $stock = $ctx->stock;
        
        $report = new \App\Entity\CorporateReport();
        $report->setStock($stock);
        $report->setRecordedAt(new \DateTime());
        $report->setTotalTime(Decimal::format($ctx->macroState->totalTime, 6));

        $report->setRevenue(Decimal::format($ctx->actualRevenue, 4));
        $report->setNetIncome(Decimal::format($ctx->reportedActualNetIncome, 4));
        $report->setOperatingMargin(Decimal::format($ctx->trueOperatingMargin, 4));
        $report->setRevenueStreams($ctx->streamRevenue);
        $report->setOperatingCosts(Decimal::format($ctx->operatingCosts, 4));
        $report->setEbit(Decimal::format($ctx->ebit, 4));
        $report->setPreTaxIncome(Decimal::format($ctx->preTaxIncome, 4));
        $report->setTaxPaid(Decimal::format($ctx->taxPaid, 4));
        // The consensus the surprise was struck against, and the EPS it was struck on (EarningsEngine::calculateEPSAndSurprise()).
        $report->setReportedEps(Decimal::format($ctx->actualQuarterlyEps, 6));
        $report->setConsensusEps(Decimal::format($ctx->expectedQuarterlyEps, 6));
        $report->setConsensusRevenue(Decimal::format($ctx->analystExpectedRevenue, 4));

        $report->setInterestExpense(Decimal::format($ctx->debtMetrics->interestExpense / 4.0, 4)); // Quarterly report
        $report->setInterestIncome(Decimal::format($ctx->quarterlyInterestIncome, 4));
        $report->setBlendedRate(Decimal::format($ctx->debtMetrics->blendedRate, 4));
        $report->setDynamicSpread(Decimal::format($ctx->debtMetrics->dynamicSpread, 4));

        $report->setCapitalExpenditures(Decimal::format($ctx->totalReportedCapex, 4));
        $report->setDepreciation(Decimal::format($ctx->quarterlyDepreciation, 4));
        $report->setEbitda(Decimal::format($ctx->ebitda, 4));
        $report->setGrossPpe($stock->getGrossPpe());
        $report->setNetPpe(Decimal::format($stock->getNetPpe(), 4));
        $report->setReceivables(Decimal::format($stock->getNetReceivables(), 4));
        // Carrying value: cost less the write-downs still held against it, the figure a balance sheet shows.
        $report->setInventory($stock->getInventory() === null ? null : Decimal::format($stock->getNetInventory(), 4));
        $report->setPayables($stock->getPayables());
        $report->setInventoryWriteDown(Decimal::format($ctx->inventoryWriteDown, 4));
        $report->setReceivablesProvision(Decimal::format($ctx->receivablesProvision, 4));
        $report->setDeferredTaxExpense(Decimal::format($ctx->deferredTaxExpense, 4));
        $report->setDeferredTaxLiability($stock->getDeferredTaxLiability());
        $report->setCashTaxPaid(Decimal::format($ctx->cashTaxPaid, 4));

        // Balance sheet. The lease is computed the same way the leverage and solvency tests compute it, so
        // the statement agrees with the ratios rather than quietly using a second definition.
        $leaseLiability = \App\Service\Corporate\CorporateMetrics::getInstance()->calculateLeaseLiability(
            (float) $stock->getTotalRevenue(),
            $ctx->strategy->getLeaseIntensity()
        );
        $report->setCip($stock->getCipBalance());
        $report->setGoodwill($stock->getGoodwill());
        $report->setLeaseLiability(Decimal::format($leaseLiability, 4));
        // The asset side exists once a ledger is open: the plant and trade cycle of an operating company, or
        // the loans and securities of a balance-sheet business. A firm that has never reported has neither,
        // and its report leaves the total unstated rather than publishing a proxy.
        $hasAssetSide = $stock->hasBalanceSheetLedger();
        $report->setTotalAssets($hasAssetSide ? Decimal::format($stock->getTotalAssets($leaseLiability), 4) : null);
        $report->setTotalLiabilities(Decimal::format($stock->getTotalLiabilities($leaseLiability), 4));
        $report->setAssetAge($stock->getGrossPpe() !== null ? Decimal::format($stock->getAssetAge(), 4) : null);

        // The lender's side of the sheet: the book, the losses expected on it, what went bad, what was
        // written and what was sold, plus the two ratios a bank is actually judged on.
        $hasEarningAssets = $stock->hasEarningAssetLedger();
        $report->setEarningAssets($stock->getEarningAssets());
        $report->setCreditLossAllowance($hasEarningAssets ? $stock->getCreditLossAllowance() : null);
        $report->setCreditLossProvision($hasEarningAssets ? Decimal::format($ctx->creditLossProvision, 4) : null);
        $report->setBankLevy($ctx->bankLevy > 0.0 ? Decimal::format($ctx->bankLevy, 4) : null);
        $report->setNetChargeOffs($hasEarningAssets ? Decimal::format($ctx->netChargeOffs, 4) : null);
        $report->setNetLoanOriginations($hasEarningAssets ? Decimal::format($ctx->netLoanOriginations, 4) : null);
        $report->setAssetSaleLoss($hasEarningAssets ? Decimal::format($ctx->assetSaleLoss, 4) : null);
        // Disclosed whenever the firm carries a book, including the quarter the mark returns to zero: a
        // disclosure that vanishes when the loss heals reads as a filing that stopped mentioning it.
        $report->setUnrealizedSecuritiesMark(
            $ctx->strategy->resolveSecuritiesBook($stock) > 0.0 || $ctx->unrealizedSecuritiesMark !== 0.0
                ? Decimal::format($ctx->unrealizedSecuritiesMark, 4)
                : null
        );
        $report->setCustomerDeposits($ctx->strategy->isFinancial() ? $stock->getCustomerDeposits() : null);
        $report->setCet1Ratio(
            $ctx->strategy instanceof \App\Service\Model\Sector\CommercialBankBusinessModel
                ? Decimal::format($ctx->strategy->calculateCet1Ratio($stock), 4)
                : null
        );
        $netInterestMargin = null;
        if ($hasEarningAssets && isset($ctx->streamRevenue['net_interest_income']) && $stock->getNetEarningAssets() > 0.0) {
            // Interest earned less interest paid this quarter, annualized over the book that earned it. The duration
            // squeeze is funding cost the model carries in the cost ratio, so it comes off here as well.
            $quarterlyInterestExpense = $ctx->debtMetrics instanceof \App\DTO\DebtMetricsDTO ? $ctx->debtMetrics->interestExpense / 4.0 : 0.0;
            $netInterestMargin = (((float) $ctx->streamRevenue['net_interest_income'] - $quarterlyInterestExpense - $ctx->netInterestSqueeze) / $stock->getNetEarningAssets()) * 4.0;
        }
        $report->setNetInterestMargin($netInterestMargin === null ? null : Decimal::format($netInterestMargin, 4));

        // Cash flow statement, in the three sections whose signs classify the life-cycle stage.
        $report->setOperatingCashFlow(Decimal::format($ctx->operatingCashFlow, 4));
        $report->setInvestingCashFlow(Decimal::format($ctx->investingCashFlow, 4));
        $report->setFinancingCashFlow(Decimal::format($ctx->financingCashFlow, 4));
        $report->setStockCompensation(Decimal::format($ctx->stockCompensation, 4));
        $report->setGoodwillImpairment(Decimal::format($ctx->goodwillImpairment, 4));
        $report->setLifecycleStage($ctx->lifecycleStage?->value);
        $report->setFreeCashFlow(Decimal::format($ctx->trueQuarterlyFcf, 4));
        $report->setEquity($stock->getTotalEquity());
        $report->setTotalDebt($stock->getTotalDebt());
        $report->setTreasury($stock->getCorporateTreasury());

        $report->setRoic(Decimal::format($ctx->truePostTaxReturn, 4));
        $report->setShares((string) $stock->getSharesOutstanding());
        $report->setWacc(Decimal::format($ctx->wacc, 4));
        $report->setEva(Decimal::format($ctx->quarterlyEconomicProfit, 4));
        $report->setDividendPaid(Decimal::format((float) ($ctx->allocation['total_paid'] ?? 0.0), 4));
        $report->setStockBuybacks(Decimal::format((float) ($ctx->allocation['total_cash_spent'] ?? 0.0), 4));
        
        if ($ctx->health) {
            $report->setCashYield(Decimal::format($ctx->health->cashYield, 4));
            $report->setCostOfEquity(Decimal::format((float) ($ctx->health->costOfEquity ?? 0.10), 4));
        }
        
        $report->setDepositApy(isset($ctx->allocation['bank_apy']) ? Decimal::format((float) $ctx->allocation['bank_apy'], 4) : null);

        $finalEquity = (float) $stock->getTotalEquity();
        $finalTotalDebt = (float) $stock->getTotalDebt();

        $roe = $finalEquity > 0 ? ($ctx->reportedActualNetIncome / $finalEquity) * 4.0 : 0.0;
        $report->setReturnOnEquity(Decimal::format($roe, 4));

        // The ratio the regulator closes the firm on (FailureSweep via DebtEngine::calculateAltmanZScore):
        // tangible equity over tangible assets. A firm the capital ratio does not govern discloses none.
        if ($ctx->strategy->requiresAlternativeZScore()) {
            $capitalBase = $this->debtEngine->resolveTangibleCapitalBase($stock, (float) $stock->getTotalRevenue());
            $report->setCapitalRatio(Decimal::format($capitalBase['capital'] / $capitalBase['assets'], 4));
        }

        if (Sectors::isFinancial($ctx->businessModel) || (float) $stock->getCustomerDeposits() > 0) {
            $customerDeposits = (float) $stock->getCustomerDeposits();
            $depositRatio = $finalTotalDebt > 0 ? ($customerDeposits / $finalTotalDebt) : 0.0;
            $report->setCustomerDepositRatio(Decimal::format($depositRatio, 4));
        }

        $streamDetails = $this->buildStreamDetails($ctx, $stock);
        $report->setStreamDetails($streamDetails);
        // Operating KPIs were assembled every quarter and read by nothing. They are what analysts and
        // players actually track between the revenue line and the EPS line, so they belong on the report.
        $report->setReportedKpis($ctx->kpis !== [] ? $ctx->kpis : null);

        $this->entityManager->persist($report);
    }

    /**
     * Constructs a structured attribution dictionary for each revenue stream.
     */
    private function buildStreamDetails(\App\DTO\EarningsSimulationContext $ctx, \App\Entity\Stock $stock): array
    {
        $previousReport = $this->entityManager->getRepository(\App\Entity\CorporateReport::class)
            ->findLatestFor($stock);
        $previousStreams = $previousReport ? ($previousReport->getRevenueStreams() ?? []) : [];

        $streamDetails = [];
        $totalRevenue = max(0.0001, (float) $ctx->actualRevenue);
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $macroSnapshot = $ctx->macroState->toArray();

        foreach ($ctx->streamRevenue as $streamKey => $revenue) {
            $prevRev = (float) ($previousStreams[$streamKey] ?? 0.0);
            // A stream with no prior quarter has no growth rate — not a flat one. Storing 0.0
            // there printed a brand-new stream as unchanged; null is the "n/m" every filing uses.
            $qoqDelta = $prevRev > 0.0 ? round(($revenue - $prevRev) / $prevRev, 4) : null;
            $share = $revenue / $totalRevenue;

            // What each macro input the model reads did to this stream, measured by the model's own physics.
            $drivers = $this->presentDrivers($ctx->streamMacroEffects[$streamKey] ?? [], $macroSnapshot);

            // Operational momentum: the stream's own persistent draw, on its own scale rather than as a revenue share.
            $z = (float) ($momentum[$streamKey] ?? 0.0);
            if (abs($z) >= self::MOMENTUM_MATERIALITY_FLOOR) {
                $drivers[] = [
                    'label'     => $z >= 1.0 ? 'Strong Operational Execution' : ($z <= -1.0 ? 'Operational Headwinds' : 'Operational Drift'),
                    'z'         => round($z, 2),
                    'type'      => 'momentum',
                    'readings'  => [],
                    'direction' => $z <=> 0.0,
                    'strength'  => match (true) {
                        abs($z) >= self::MOMENTUM_STRENGTH_HIGH => 3,
                        abs($z) >= self::MOMENTUM_STRENGTH_MODERATE => 2,
                        default => 1,
                    },
                ];
            }

            $entry = [
                'revenue'   => round($revenue, 2),
                'share'     => round($share, 4),
                'qoq_delta' => $qoqDelta,
                'drivers'   => $drivers,
            ];

            if ($ctx->eventType !== null) {
                $entry['event'] = ucwords(str_replace('_', ' ', $ctx->eventType));
            }

            $streamDetails[$streamKey] = $entry;
        }

        return $streamDetails;
    }

    /**
     * The measured macro drivers of one stream, graded and ranked for display.
     *
     * Each effect is the stream's revenue over what it would have been with that one input at its neutral reading,
     * less one (EarningsEngine::attributeMacroDrivers), so it is a real share of the stream and is printed as one,
     * beside the input's actual reading resolved through App\Data\Macro\MacroFieldCatalog.
     *
     * @param  array<string, float>        $effects       Field => measured effect on the stream.
     * @param  array<string, mixed>        $macroSnapshot MacroStateDTO::toArray()
     * @return list<array<string, mixed>>  ranked strongest-first, immaterial drivers dropped
     */
    private function presentDrivers(array $effects, array $macroSnapshot): array
    {
        $presented = [];

        foreach ($effects as $field => $effect) {
            if (abs($effect) < self::DRIVER_MATERIALITY_FLOOR) {
                continue;
            }

            $reading = MacroFieldCatalog::readingFor($field, $macroSnapshot);
            $presented[] = [
                'label'     => MacroFieldCatalog::labelFor($field),
                'impact'    => round($effect, 4),
                'share'     => round($effect, 4),
                'type'      => 'macro',
                'readings'  => $reading !== null ? [$reading] : [],
                'direction' => $effect <=> 0.0,
                'strength'  => match (true) {
                    abs($effect) >= self::DRIVER_STRENGTH_HIGH => 3,
                    abs($effect) >= self::DRIVER_STRENGTH_MODERATE => 2,
                    default => 1,
                },
            ];
        }

        usort($presented, static fn (array $a, array $b): int => abs((float) $b['impact']) <=> abs((float) $a['impact']));

        return $presented;
    }
}
