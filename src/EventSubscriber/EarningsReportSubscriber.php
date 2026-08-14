<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Event\EarningsReportedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use App\Data\Sectors;

class EarningsReportSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
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

        $capitalRatio = ($finalEquity + $finalTotalDebt) > 0 ? ($finalEquity / ($finalEquity + $finalTotalDebt)) : 1.0;
        $report->setCapitalRatio(\App\Service\Math\MathUtility::formatDecimal($capitalRatio, 4));

        if (Sectors::isFinancial($ctx->businessModel) || (float) $stock->getCustomerDeposits() > 0) {
            $customerDeposits = (float) $stock->getCustomerDeposits();
            $depositRatio = $finalTotalDebt > 0 ? ($customerDeposits / $finalTotalDebt) : 0.0;
            $report->setCustomerDepositRatio(\App\Service\Math\MathUtility::formatDecimal($depositRatio, 4));
        }

        $this->entityManager->persist($report);
    }
}
