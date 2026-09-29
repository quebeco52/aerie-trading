<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Event\EarningsReportedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use App\Data\MacroFieldCatalog;
use App\Data\Sectors;
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

        $report->setRevenue(\App\Service\Math\MathUtility::formatDecimal($ctx->actualRevenue, 4));
        $report->setNetIncome(\App\Service\Math\MathUtility::formatDecimal($ctx->reportedActualNetIncome, 4));
        $report->setOperatingMargin(\App\Service\Math\MathUtility::formatDecimal($ctx->trueOperatingMargin, 4));
        $report->setRevenueStreams($ctx->streamRevenue);
        $report->setOperatingCosts(\App\Service\Math\MathUtility::formatDecimal($ctx->operatingCosts, 4));
        $report->setEbit(\App\Service\Math\MathUtility::formatDecimal($ctx->ebit, 4));
        $report->setPreTaxIncome(\App\Service\Math\MathUtility::formatDecimal($ctx->preTaxIncome, 4));
        $report->setTaxPaid(\App\Service\Math\MathUtility::formatDecimal($ctx->taxPaid, 4));

        $report->setInterestExpense(\App\Service\Math\MathUtility::formatDecimal($ctx->debtMetrics->interestExpense / 4.0, 4)); // Quarterly report
        $report->setInterestIncome(\App\Service\Math\MathUtility::formatDecimal($ctx->quarterlyInterestIncome, 4));
        $report->setBlendedRate(\App\Service\Math\MathUtility::formatDecimal($ctx->debtMetrics->blendedRate, 4));
        $report->setDynamicSpread(\App\Service\Math\MathUtility::formatDecimal($ctx->debtMetrics->dynamicSpread, 4));

        $report->setCapitalExpenditures(\App\Service\Math\MathUtility::formatDecimal($ctx->totalReportedCapex, 4));
        $report->setDepreciation(\App\Service\Math\MathUtility::formatDecimal($ctx->quarterlyDepreciation, 4));
        $report->setEbitda(\App\Service\Math\MathUtility::formatDecimal($ctx->ebitda, 4));
        $report->setGrossPpe($stock->getGrossPpe());
        $report->setNetPpe(\App\Service\Math\MathUtility::formatDecimal($stock->getNetPpe(), 4));
        $report->setReceivables(\App\Service\Math\MathUtility::formatDecimal($stock->getNetReceivables(), 4));
        // Carrying value: cost less the write-downs still held against it, the figure a balance sheet shows.
        $report->setInventory($stock->getInventory() === null ? null : \App\Service\Math\MathUtility::formatDecimal($stock->getNetInventory(), 4));
        $report->setPayables($stock->getPayables());
        $report->setInventoryWriteDown(\App\Service\Math\MathUtility::formatDecimal($ctx->inventoryWriteDown, 4));
        $report->setReceivablesProvision(\App\Service\Math\MathUtility::formatDecimal($ctx->receivablesProvision, 4));
        $report->setDeferredTaxExpense(\App\Service\Math\MathUtility::formatDecimal($ctx->deferredTaxExpense, 4));
        $report->setDeferredTaxLiability($stock->getDeferredTaxLiability());
        $report->setCashTaxPaid(\App\Service\Math\MathUtility::formatDecimal($ctx->cashTaxPaid, 4));

        // Balance sheet. The lease is computed the same way the leverage and solvency tests compute it, so
        // the statement agrees with the ratios rather than quietly using a second definition.
        $leaseLiability = \App\Service\Math\CorporateMetrics::getInstance()->calculateLeaseLiability(
            (float) $stock->getTotalRevenue(),
            $ctx->strategy->getLeaseIntensity()
        );
        $report->setCip($stock->getCipBalance());
        $report->setGoodwill($stock->getGoodwill());
        $report->setLeaseLiability(\App\Service\Math\MathUtility::formatDecimal($leaseLiability, 4));
        // The asset side exists once a ledger is open: the plant and trade cycle of an operating company, or
        // the loans and securities of a balance-sheet business. A firm that has never reported has neither,
        // and its report leaves the total unstated rather than publishing a proxy.
        $hasAssetSide = $stock->hasBalanceSheetLedger();
        $report->setTotalAssets($hasAssetSide ? \App\Service\Math\MathUtility::formatDecimal($stock->getTotalAssets($leaseLiability), 4) : null);
        $report->setTotalLiabilities(\App\Service\Math\MathUtility::formatDecimal($stock->getTotalLiabilities($leaseLiability), 4));
        $report->setAssetAge($stock->getGrossPpe() !== null ? \App\Service\Math\MathUtility::formatDecimal($stock->getAssetAge(), 4) : null);

        // The lender's side of the sheet: the book, the losses expected on it, what went bad, what was
        // written and what was sold, plus the two ratios a bank is actually judged on.
        $hasEarningAssets = $stock->hasEarningAssetLedger();
        $report->setEarningAssets($stock->getEarningAssets());
        $report->setCreditLossAllowance($hasEarningAssets ? $stock->getCreditLossAllowance() : null);
        $report->setCreditLossProvision($hasEarningAssets ? \App\Service\Math\MathUtility::formatDecimal($ctx->creditLossProvision, 4) : null);
        $report->setNetChargeOffs($hasEarningAssets ? \App\Service\Math\MathUtility::formatDecimal($ctx->netChargeOffs, 4) : null);
        $report->setNetLoanOriginations($hasEarningAssets ? \App\Service\Math\MathUtility::formatDecimal($ctx->netLoanOriginations, 4) : null);
        $report->setAssetSaleLoss($hasEarningAssets ? \App\Service\Math\MathUtility::formatDecimal($ctx->assetSaleLoss, 4) : null);
        // Disclosed whenever the firm carries a book, including the quarter the mark returns to zero: a
        // disclosure that vanishes when the loss heals reads as a filing that stopped mentioning it.
        $report->setUnrealizedSecuritiesMark(
            $ctx->strategy->resolveSecuritiesBook($stock) > 0.0 || $ctx->unrealizedSecuritiesMark !== 0.0
                ? \App\Service\Math\MathUtility::formatDecimal($ctx->unrealizedSecuritiesMark, 4)
                : null
        );
        $report->setCustomerDeposits($ctx->strategy->isFinancial() ? $stock->getCustomerDeposits() : null);
        $report->setCet1Ratio(
            $ctx->strategy instanceof \App\Service\Model\Sector\CommercialBankBusinessModel
                ? \App\Service\Math\MathUtility::formatDecimal($ctx->strategy->calculateCet1Ratio($stock), 4)
                : null
        );
        $netInterestMargin = null;
        if ($hasEarningAssets && isset($ctx->streamRevenue['net_interest_income']) && $stock->getNetEarningAssets() > 0.0) {
            // Interest earned less interest paid this quarter, annualized over the book that earned it.
            $quarterlyInterestExpense = $ctx->debtMetrics instanceof \App\DTO\DebtMetricsDTO ? $ctx->debtMetrics->interestExpense / 4.0 : 0.0;
            $netInterestMargin = (((float) $ctx->streamRevenue['net_interest_income'] - $quarterlyInterestExpense) / $stock->getNetEarningAssets()) * 4.0;
        }
        $report->setNetInterestMargin($netInterestMargin === null ? null : \App\Service\Math\MathUtility::formatDecimal($netInterestMargin, 4));

        // Cash flow statement, in the three sections whose signs classify the life-cycle stage.
        $report->setOperatingCashFlow(\App\Service\Math\MathUtility::formatDecimal($ctx->operatingCashFlow, 4));
        $report->setInvestingCashFlow(\App\Service\Math\MathUtility::formatDecimal($ctx->investingCashFlow, 4));
        $report->setFinancingCashFlow(\App\Service\Math\MathUtility::formatDecimal($ctx->financingCashFlow, 4));
        $report->setStockCompensation(\App\Service\Math\MathUtility::formatDecimal($ctx->stockCompensation, 4));
        $report->setGoodwillImpairment(\App\Service\Math\MathUtility::formatDecimal($ctx->goodwillImpairment, 4));
        $report->setLifecycleStage($ctx->lifecycleStage?->value);
        $report->setFreeCashFlow(\App\Service\Math\MathUtility::formatDecimal($ctx->trueQuarterlyFcf, 4));
        $report->setEquity($stock->getTotalEquity());
        $report->setTotalDebt($stock->getTotalDebt());
        $report->setTreasury($stock->getCorporateTreasury());

        $report->setRoic(\App\Service\Math\MathUtility::formatDecimal($ctx->truePostTaxReturn, 4));
        $report->setShares((string) $stock->getSharesOutstanding());
        $report->setWacc(\App\Service\Math\MathUtility::formatDecimal($ctx->wacc, 4));
        $report->setEva(\App\Service\Math\MathUtility::formatDecimal($ctx->quarterlyEconomicProfit, 4));
        $report->setDividendPaid(\App\Service\Math\MathUtility::formatDecimal((float) ($ctx->allocation['total_paid'] ?? 0.0), 4));
        $report->setStockBuybacks(\App\Service\Math\MathUtility::formatDecimal((float) ($ctx->allocation['total_cash_spent'] ?? 0.0), 4));
        
        if ($ctx->health) {
            $report->setCashYield(\App\Service\Math\MathUtility::formatDecimal($ctx->health->cashYield, 4));
            $report->setCostOfEquity(\App\Service\Math\MathUtility::formatDecimal((float) ($ctx->health->costOfEquity ?? 0.10), 4));
        }
        
        $report->setDepositApy(isset($ctx->allocation['bank_apy']) ? \App\Service\Math\MathUtility::formatDecimal((float) $ctx->allocation['bank_apy'], 4) : null);

        $finalEquity = (float) $stock->getTotalEquity();
        $finalTotalDebt = (float) $stock->getTotalDebt();

        $roe = $finalEquity > 0 ? ($ctx->reportedActualNetIncome / $finalEquity) * 4.0 : 0.0;
        $report->setReturnOnEquity(\App\Service\Math\MathUtility::formatDecimal($roe, 4));

        // The ratio the regulator closes the firm on (MarketOperator via DebtEngine::calculateAltmanZScore):
        // tangible equity over tangible assets. A firm the capital ratio does not govern discloses none.
        if ($ctx->strategy->requiresAlternativeZScore()) {
            $capitalBase = $this->debtEngine->resolveTangibleCapitalBase($stock, (float) $stock->getTotalRevenue());
            $report->setCapitalRatio(\App\Service\Math\MathUtility::formatDecimal($capitalBase['capital'] / $capitalBase['assets'], 4));
        }

        if (Sectors::isFinancial($ctx->businessModel) || (float) $stock->getCustomerDeposits() > 0) {
            $customerDeposits = (float) $stock->getCustomerDeposits();
            $depositRatio = $finalTotalDebt > 0 ? ($customerDeposits / $finalTotalDebt) : 0.0;
            $report->setCustomerDepositRatio(\App\Service\Math\MathUtility::formatDecimal($depositRatio, 4));
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
     * beside the input's actual reading resolved through App\Data\MacroFieldCatalog.
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
