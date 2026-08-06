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

        $report->setRevenue((string) $ctx->actualRevenue);
        $report->setNetIncome((string) $ctx->reportedActualNetIncome);
        $report->setOperatingMargin((string) $ctx->trueOperatingMargin);
        $report->setOperatingCosts((string) $ctx->operatingCosts);
        $report->setEbit((string) $ctx->ebit);
        $report->setPreTaxIncome((string) $ctx->preTaxIncome);
        $report->setTaxPaid((string) $ctx->taxPaid);

        $report->setInterestExpense((string) ($ctx->debtMetrics->interestExpense / 4.0)); // Quarterly report
        $report->setInterestIncome((string) $ctx->quarterlyInterestIncome);
        $report->setBlendedRate((string) $ctx->debtMetrics->blendedRate);
        $report->setDynamicSpread((string) $ctx->debtMetrics->dynamicSpread);

        $report->setCapitalExpenditures((string) $ctx->totalReportedCapex);
        $report->setFreeCashFlow((string) $ctx->trueQuarterlyFcf);
        $report->setEquity($stock->getTotalEquity());
        $report->setTotalDebt($stock->getTotalDebt());
        $report->setTreasury($stock->getCorporateTreasury());

        $report->setRoic((string) $ctx->truePostTaxReturn);
        $report->setShares((string) $stock->getSharesOutstanding());
        $report->setWacc((string) $ctx->wacc);
        $report->setEva((string) $ctx->quarterlyEconomicProfit);
        $report->setDividendPaid((string) $ctx->allocation['total_paid']);
        $report->setStockBuybacks((string) $ctx->allocation['total_cash_spent']);
        
        if ($ctx->health) {
            $report->setCashYield((string) $ctx->health->cashYield);
            $report->setCostOfEquity((string) ($ctx->health->costOfEquity ?? 0.10));
        }
        
        $report->setDepositApy(isset($ctx->allocation['bank_apy']) ? (string) $ctx->allocation['bank_apy'] : null);

        $finalEquity = (float) $stock->getTotalEquity();
        $finalTotalDebt = (float) $stock->getTotalDebt();

        $roe = $finalEquity > 0 ? ($ctx->reportedActualNetIncome / $finalEquity) * 4.0 : 0.0;
        $report->setReturnOnEquity((string) $roe);

        $capitalRatio = ($finalEquity + $finalTotalDebt) > 0 ? ($finalEquity / ($finalEquity + $finalTotalDebt)) : 1.0;
        $report->setCapitalRatio((string) $capitalRatio);

        if (Sectors::isFinancial($ctx->businessModel) || (float) $stock->getCustomerDeposits() > 0) {
            $customerDeposits = (float) $stock->getCustomerDeposits();
            $depositRatio = $finalTotalDebt > 0 ? ($customerDeposits / $finalTotalDebt) : 0.0;
            $report->setCustomerDepositRatio((string) $depositRatio);
        }

        $this->entityManager->persist($report);
    }
}
