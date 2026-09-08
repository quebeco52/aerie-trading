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

        $streamDetails = $this->buildStreamDetails($ctx, $stock);
        $report->setStreamDetails($streamDetails);

        $this->entityManager->persist($report);
    }

    /**
     * Constructs a structured attribution dictionary for each revenue stream.
     */
    private function buildStreamDetails(\App\DTO\EarningsSimulationContext $ctx, \App\Entity\Stock $stock): array
    {
        $previousReport = $this->entityManager->getRepository(\App\Entity\CorporateReport::class)->findOneBy(
            ['stock' => $stock],
            ['recordedAt' => 'DESC']
        );
        $previousStreams = $previousReport ? ($previousReport->getRevenueStreams() ?? []) : [];

        $streamDetails = [];
        $totalRevenue = max(0.0001, (float) $ctx->actualRevenue);
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $macro = $ctx->macroState;
        $beta = abs((float) $stock->getBeta());
        $vol = (float) $stock->getVolatility();
        $bm = $ctx->businessModel;

        foreach ($ctx->streamRevenue as $streamKey => $revenue) {
            $prevRev = (float) ($previousStreams[$streamKey] ?? 0.0);
            $qoqDelta = $prevRev > 0.0 ? ($revenue - $prevRev) / $prevRev : 0.0;
            $share = $revenue / $totalRevenue;

            $drivers = $this->resolveStreamMacroDrivers($bm, $streamKey, $macro, $beta, $vol);

            // Operational Momentum / AR(1) Z-Score
            $z = (float) ($momentum[$streamKey] ?? 0.0);
            if (abs($z) >= 0.15) {
                $impact = $z * $vol * 0.25;
                $label = $z >= 1.0 ? 'Strong Operational Execution' : ($z <= -1.0 ? 'Operational Headwinds' : 'Operational Drift');
                $drivers[] = [
                    'label'  => $label,
                    'impact' => round($impact, 4),
                    'z'      => round($z, 2),
                    'type'   => 'momentum',
                ];
            }

            $entry = [
                'revenue'   => round($revenue, 2),
                'share'     => round($share, 4),
                'qoq_delta' => round($qoqDelta, 4),
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
     * Resolves precise macro drivers for any business model and revenue stream.
     *
     * @return array<int, array{label: string, impact: float, type: string}>
     */
    private function resolveStreamMacroDrivers(string $bm, string $streamKey, \App\DTO\MacroStateDTO $macro, float $beta, float $vol): array
    {
        $drivers = [];

        switch ($bm) {
            case 'commercial_bank':
            case 'shadow_bank':
            case 'credit_services':
                // Real stream keys: net_interest_income (bank), lending (credit_services),
                // origination_fees + direct_lending (shadow_bank).
                if (in_array($streamKey, ['net_interest_income', 'lending', 'origination_fees', 'direct_lending'])) {
                    $bankSpread = $macro->yield10yEma - ($macro->yield2yEma + $macro->interbankLiquiditySpreadEma);
                    $spreadBps = (int) round($bankSpread * 10000);
                    $drivers[] = [
                        'label'  => "Yield Curve & NIM Spread ({$spreadBps} bps)",
                        'impact' => round(($bankSpread - 0.005) * 1.5, 4),
                        'type'   => 'macro',
                    ];
                    $drivers[] = [
                        'label'  => 'Commercial Loan Demand',
                        'impact' => round($macro->outputGapEma * 0.50, 4),
                        'type'   => 'macro',
                    ];
                    $creditDelta = $macro->macroCreditSpreadEma - 0.02;
                    if (abs($creditDelta) >= 0.002) {
                        $drivers[] = [
                            'label'  => 'Credit Spread & CECL Reserves',
                            'impact' => round(-$creditDelta * 1.5, 4),
                            'type'   => 'macro',
                        ];
                    }
                    if ($macro->retailDefaultRateEma > 0.025) {
                        $drivers[] = [
                            'label'  => 'Retail Credit Default Losses',
                            'impact' => round(-($macro->retailDefaultRateEma - 0.025) * 2.0, 4),
                            'type'   => 'macro',
                        ];
                    }
                } elseif (in_array($streamKey, ['fee_income', 'swipe'])) {
                    $drivers[] = [
                        'label'  => 'Payment & Transaction Activity',
                        'impact' => round($macro->outputGapEma * 0.35, 4),
                        'type'   => 'macro',
                    ];
                    $sentShift = ($macro->consumerSentimentIndexEma - 100.0) / 100.0;
                    $drivers[] = [
                        'label'  => 'Consumer Confidence Index',
                        'impact' => round($sentShift * 0.25, 4),
                        'type'   => 'macro',
                    ];
                } elseif ($streamKey === 'proprietary_dividend') {
                    $drivers[] = [
                        'label'  => 'Syndicate Corporate Dividend Yields',
                        'impact' => round($macro->outputGapEma * 0.70, 4),
                        'type'   => 'macro',
                    ];
                }
                break;

            case 'investment_bank':
                // Real stream keys: advisory, trading, options_premium_income (conditional).
                // The model itself folds M&A, ECM and DCM stimuli into a single 'advisory'
                // number, so both underlying demand drivers are surfaced against that one key.
                if ($streamKey === 'advisory') {
                    $drivers[] = [
                        'label'  => 'Corporate M&A Deal Flow',
                        'impact' => round(($macro->outputGapEma * 0.80) - (($macro->macroCreditSpreadEma - 0.02) * 2.0), 4),
                        'type'   => 'macro',
                    ];
                    $drivers[] = [
                        'label'  => 'Debt & Equity Underwriting Appetite',
                        'impact' => round(($macro->outputGapEma * 0.50) - (($macro->policyRateEma - 0.02) * 1.0), 4),
                        'type'   => 'macro',
                    ];
                } elseif ($streamKey === 'trading') {
                    $drivers[] = [
                        'label'  => 'FICC & Equities Desk Volatility',
                        'impact' => round(($macro->marketVolatilityEma - 0.15) * 2.0, 4),
                        'type'   => 'macro',
                    ];
                } elseif ($streamKey === 'options_premium_income') {
                    $drivers[] = [
                        'label'  => 'Options & Hedging Premium Capture',
                        'impact' => round(($macro->marketVolatilityEma - 0.15) * 1.5, 4),
                        'type'   => 'macro',
                    ];
                }
                break;

            case 'brokerage':
            case 'clearing_house':
                // Real stream keys: trading, advisory (brokerage); clearing_fees, custody_float,
                // data_licensing (clearing_house).
                if (in_array($streamKey, ['trading', 'clearing_fees'])) {
                    $drivers[] = [
                        'label'  => 'Market Volatility & Trading Activity',
                        'impact' => round(($macro->marketVolatilityEma - 0.15) * 2.2, 4),
                        'type'   => 'macro',
                    ];
                } elseif ($streamKey === 'custody_float') {
                    $drivers[] = [
                        'label'  => 'Benchmark Margin Lending Yield',
                        'impact' => round(($macro->policyRateEma * 1.2) + ($macro->outputGapEma * 0.4), 4),
                        'type'   => 'macro',
                    ];
                } elseif ($streamKey === 'data_licensing') {
                    $drivers[] = [
                        'label'  => 'Institutional Market Data & AUM',
                        'impact' => round($macro->outputGapEma * 0.40, 4),
                        'type'   => 'macro',
                    ];
                } elseif ($streamKey === 'advisory') {
                    $drivers[] = [
                        'label'  => 'Corporate Advisory Mandate Flow',
                        'impact' => round($macro->outputGapEma * 0.50, 4),
                        'type'   => 'macro',
                    ];
                }
                break;

            case 'asset_manager':
                // Real stream keys: base_fee, alpha.
                if ($streamKey === 'base_fee') {
                    $drivers[] = [
                        'label'  => 'Committed AUM Capital Base',
                        'impact' => round($macro->outputGapEma * 0.35, 4),
                        'type'   => 'macro',
                    ];
                } elseif ($streamKey === 'alpha') {
                    $drivers[] = [
                        'label'  => 'Active Management Alpha Capture',
                        'impact' => round(($macro->outputGapEma * 0.60) + (($macro->marketVolatilityEma - 0.15) * 0.80), 4),
                        'type'   => 'macro',
                    ];
                }
                break;

            case 'private_equity':
                // Real stream keys: management_fees, carried_interest, principal_investments (conditional).
                if ($streamKey === 'management_fees') {
                    $drivers[] = [
                        'label'  => 'Committed AUM Capital Base',
                        'impact' => round($macro->outputGapEma * 0.35, 4),
                        'type'   => 'macro',
                    ];
                } elseif (in_array($streamKey, ['carried_interest', 'principal_investments'])) {
                    $drivers[] = [
                        'label'  => 'LBO Exit & Performance Hurdle Realization',
                        'impact' => round(($macro->outputGapEma * 1.10) - (($macro->macroCreditSpreadEma - 0.02) * 2.0), 4),
                        'type'   => 'macro',
                    ];
                }
                break;

            case 'hedge_fund':
                // Real stream keys: management_fees, directional_bets, quant_alpha.
                if ($streamKey === 'management_fees') {
                    $drivers[] = [
                        'label'  => 'Committed AUM Capital Base',
                        'impact' => round($macro->outputGapEma * 0.35, 4),
                        'type'   => 'macro',
                    ];
                } elseif ($streamKey === 'directional_bets') {
                    $drivers[] = [
                        'label'  => 'Directional Macro Positioning',
                        'impact' => round(($macro->marketVolatilityEma - 0.15) * 1.8, 4),
                        'type'   => 'macro',
                    ];
                } elseif ($streamKey === 'quant_alpha') {
                    $drivers[] = [
                        'label'  => 'Quantitative Arbitrage Capture',
                        'impact' => round(($macro->marketVolatilityEma - 0.15) * 1.2, 4),
                        'type'   => 'macro',
                    ];
                }
                break;

            case 'distressed_debt':
                // Real stream keys: restructuring_advisory, turnaround_recovery, loan_to_own (conditional).
                if ($streamKey === 'restructuring_advisory') {
                    $drivers[] = [
                        'label'  => 'Corporate Default Restructuring Wave',
                        'impact' => round((($macro->macroCreditSpreadEma - 0.02) * 3.0) + (($macro->retailDefaultRateEma - 0.025) * 2.0), 4),
                        'type'   => 'macro',
                    ];
                } elseif (in_array($streamKey, ['turnaround_recovery', 'loan_to_own'])) {
                    $drivers[] = [
                        'label'  => 'Distressed Asset Recovery Value',
                        'impact' => round(($macro->outputGapEma * 0.90) - (($macro->macroCreditSpreadEma - 0.02) * 1.5), 4),
                        'type'   => 'macro',
                    ];
                }
                break;

            case 'insurance':
            case 'reinsurance':
            case 'retail_insurance':
                // Real stream keys: premium_revenue (insurance); treaty_reinsurance,
                // catastrophe_bonds (reinsurance); property_casualty_premiums,
                // life_insurance_premiums (retail_insurance).
                if (in_array($streamKey, ['premium_revenue', 'treaty_reinsurance', 'property_casualty_premiums', 'life_insurance_premiums'])) {
                    $drivers[] = [
                        'label'  => 'Policy Underwriting Demand',
                        'impact' => round($macro->outputGapEma * 0.30, 4),
                        'type'   => 'macro',
                    ];
                    if ($macro->inflationEma > 0.02) {
                        $drivers[] = [
                            'label'  => 'Claims Replacement Cost Pass-Through',
                            'impact' => round(($macro->inflationEma - 0.02) * 0.75, 4),
                            'type'   => 'macro',
                        ];
                    }
                } elseif ($streamKey === 'catastrophe_bonds') {
                    $drivers[] = [
                        'label'  => 'Sovereign Float Investment Yield',
                        'impact' => round($macro->yield10yEma * 1.5, 4),
                        'type'   => 'macro',
                    ];
                }
                break;

            case 'security_protection':
            case 'defense_contractor':
                if (in_array($streamKey, ['government_contracts', 'government_defense_procurement', 'defense_systems', 'defense_procurement'])) {
                    $govShift = ($macro->governmentSpendingIndexEma - 100.0) / 100.0;
                    $drivers[] = [
                        'label'  => 'Fiscal & Defense Appropriations',
                        'impact' => round($govShift * 0.45, 4),
                        'type'   => 'macro',
                    ];
                    if ($macro->inflationEma > 0.02) {
                        $drivers[] = [
                            'label'  => 'Cost-Plus Inflation Escalation',
                            'impact' => round(($macro->inflationEma - 0.02) * 0.80, 4),
                            'type'   => 'macro',
                        ];
                    }
                } elseif ($streamKey === 'corporate_retainers') {
                    $drivers[] = [
                        'label'  => 'Corporate Facility Expansion',
                        'impact' => round($macro->outputGapEma * 0.40 * $beta, 4),
                        'type'   => 'macro',
                    ];
                } elseif (in_array($streamKey, ['expeditionary_ops', 'foreign_military_sales'])) {
                    $fearPremium = max(0.0, ($macro->marketVolatilityEma - 0.15) * 2.0);
                    $drivers[] = [
                        'label'  => 'VIX Geopolitical Fear Premium',
                        'impact' => round($fearPremium, 4),
                        'type'   => 'macro',
                    ];
                    $stressPremium = max(0.0, ($macro->macroCreditSpreadEma - 0.02) * 3.0);
                    if ($stressPremium > 0) {
                        $drivers[] = [
                            'label'  => 'Credit Distress Demand',
                            'impact' => round($stressPremium, 4),
                            'type'   => 'macro',
                        ];
                    }
                }
                break;

            case 'reit':
                $creShift = ($macro->commercialPropertyIndexEma - 100.0) / 100.0;
                $drivers[] = [
                    'label'  => 'Commercial Property Valuations',
                    'impact' => round($creShift * 0.45, 4),
                    'type'   => 'macro',
                ];
                $drivers[] = [
                    'label'  => 'Tenant Leasing & Occupancy Rate',
                    'impact' => round($macro->outputGapEma * 0.60, 4),
                    'type'   => 'macro',
                ];
                $rateDrag = ($macro->yield10yEma - 0.04) * -1.0;
                $drivers[] = [
                    'label'  => 'Cap Rate vs Refinancing Spread',
                    'impact' => round($rateDrag, 4),
                    'type'   => 'macro',
                ];
                break;

            case 'shipping':
            case 'logistics':
            case 'railroad':
                if (in_array($streamKey, ['spot_charter_rates', 'spot_charter', 'freight_forwarding', 'intermodal_freight', 'spot_freight_brokerage'])) {
                    $freightShift = ($macro->freightRateIndexEma - 100.0) / 100.0;
                    $drivers[] = [
                        'label'  => 'Spot Freight Charter Rates',
                        'impact' => round($freightShift * 0.50, 4),
                        'type'   => 'macro',
                    ];
                    $fxShift = ($macro->exchangeRateIndexEma - 100.0) / 100.0;
                    $drivers[] = [
                        'label'  => 'Global Trade FX Currency Index',
                        'impact' => round($fxShift * 0.30, 4),
                        'type'   => 'macro',
                    ];
                } elseif (in_array($streamKey, ['time_charter_contracts', 'warehousing_fulfillment', 'bulk_commodities', 'industrial_carloads', 'dedicated_fleet_contracts', 'value_added_warehousing'])) {
                    $drivers[] = [
                        'label'  => 'Industrial Bulk Supply Chain Volume',
                        'impact' => round($macro->outputGapEma * 0.60 * $beta, 4),
                        'type'   => 'macro',
                    ];
                }
                $energyShift = ($macro->energyPriceIndexEma - 100.0) / 100.0;
                if (abs($energyShift) >= 0.01) {
                    $fuelLabel = $bm === 'shipping' ? 'Bunker Fuel Surcharge Impact' : 'Diesel Fuel Surcharge Impact';
                    $drivers[] = [
                        'label'  => $fuelLabel,
                        'impact' => round(-$energyShift * 0.25, 4),
                        'type'   => 'macro',
                    ];
                }
                break;

            case 'commodity':
            case 'steel_manufacturing':
                $metalsShift = ($macro->industrialMetalsIndexEma - 100.0) / 100.0;
                $drivers[] = [
                    'label'  => 'Industrial Metals Benchmark Price',
                    'impact' => round($metalsShift * 0.60, 4),
                    'type'   => 'macro',
                ];
                $drivers[] = [
                    'label'  => 'Global Industrial Production Demand',
                    'impact' => round($macro->outputGapEma * 1.40 * $beta, 4),
                    'type'   => 'macro',
                ];
                $energyCost = ($macro->energyPriceIndexEma - 100.0) / 100.0;
                if (abs($energyCost) >= 0.01) {
                    $drivers[] = [
                        'label'  => 'Energy Smelting & Extraction Cost',
                        'impact' => round(-$energyCost * 0.30, 4),
                        'type'   => 'macro',
                    ];
                }
                break;

            case 'biotech':
                if ($streamKey === 'commercial_therapeutics') {
                    $drivers[] = [
                        'label'  => 'Biologic Prescription Demand & Pricing',
                        'impact' => round(($macro->inflationEma - 0.02) * 0.50, 4),
                        'type'   => 'macro',
                    ];
                } elseif ($streamKey === 'pipeline_licensing_milestones') {
                    $drivers[] = [
                        'label'  => 'Clinical Trial Readout & Milestone Velocity',
                        'impact' => round($vol * 0.40, 4),
                        'type'   => 'macro',
                    ];
                }
                break;

            case 'construction':
            case 'heavy_manufacturing':
            case 'specialty_industrial_machinery':
            case 'tools_and_accessories':
                if (in_array($streamKey, ['civil_infrastructure', 'infrastructure_components', 'specialty_alloys'])) {
                    $govShift = ($macro->governmentSpendingIndexEma - 100.0) / 100.0;
                    $drivers[] = [
                        'label'  => 'Public Infrastructure Appropriations',
                        'impact' => round($govShift * 0.45, 4),
                        'type'   => 'macro',
                    ];
                } else {
                    $creShift = ($macro->commercialPropertyIndexEma - 100.0) / 100.0;
                    $drivers[] = [
                        'label'  => 'Commercial Real Estate Construction Demand',
                        'impact' => round(($creShift * 0.40) + ($macro->outputGapEma * 0.70 * $beta), 4),
                        'type'   => 'macro',
                    ];
                }
                $metalsShift = ($macro->industrialMetalsIndexEma - 100.0) / 100.0;
                if (abs($metalsShift) >= 0.01) {
                    $drivers[] = [
                        'label'  => 'Industrial Raw Materials Price',
                        'impact' => round($metalsShift * 0.35, 4),
                        'type'   => 'macro',
                    ];
                }
                break;

            case 'semiconductor':
            case 'computer_hardware':
            case 'tech':
                if (in_array($streamKey, ['enterprise_systems', 'enterprise_saas', 'enterprise_software', 'fab_silicon_wafers', 'cloud_hosting', 'cloud_infrastructure'])) {
                    $drivers[] = [
                        'label'  => 'Corporate Tech & Cloud CapEx Cycle',
                        'impact' => round($macro->outputGapEma * 1.25 * $beta, 4),
                        'type'   => 'macro',
                    ];
                } else {
                    $sentShift = ($macro->consumerSentimentIndexEma - 100.0) / 100.0;
                    $drivers[] = [
                        'label'  => 'Consumer Device & Digital Demand',
                        'impact' => round(($sentShift * 0.35) + ($macro->outputGapEma * 0.60), 4),
                        'type'   => 'macro',
                    ];
                }
                break;

            case 'internet_retail':
            case 'consumer_staples':
            case 'restaurant':
            case 'luxury':
            case 'resorts_casinos':
                $sentShift = ($macro->consumerSentimentIndexEma - 100.0) / 100.0;
                $drivers[] = [
                    'label'  => 'Consumer Sentiment & Confidence',
                    'impact' => round($sentShift * 0.35, 4),
                    'type'   => 'macro',
                ];
                $drivers[] = [
                    'label'  => 'Consumer Discretionary Spending',
                    'impact' => round($macro->outputGapEma * 1.50 * $beta, 4),
                    'type'   => 'macro',
                ];
                if ($bm === 'consumer_staples' || $bm === 'restaurant') {
                    $agriShift = ($macro->agriculturalCommodityIndexEma - 100.0) / 100.0;
                    if (abs($agriShift) >= 0.01) {
                        $drivers[] = [
                            'label'  => 'Agricultural Commodity Input Prices',
                            'impact' => round($agriShift * 0.30, 4),
                            'type'   => 'macro',
                        ];
                    }
                }
                break;

            case 'auto_manufacturer':
                $drivers[] = [
                    'label'  => 'Auto Loan Interest Rates & Financing Demand',
                    'impact' => round((-$macro->policyRateEma * 1.5) + (($macro->consumerSentimentIndexEma - 100.0) * 0.003), 4),
                    'type'   => 'macro',
                ];
                if ($macro->retailDefaultRateEma > 0.025) {
                    $drivers[] = [
                        'label'  => 'Auto Loan Default Reserves',
                        'impact' => round(-($macro->retailDefaultRateEma - 0.025) * 2.5, 4),
                        'type'   => 'macro',
                    ];
                }
                break;

            case 'utility':
                $energyShift = ($macro->energyPriceIndexEma - 100.0) / 100.0;
                $drivers[] = [
                    'label'  => 'Energy Commodity Fuel Costs',
                    'impact' => round($energyShift * 0.30, 4),
                    'type'   => 'macro',
                ];
                $drivers[] = [
                    'label'  => 'Industrial & Commercial Power Load',
                    'impact' => round($macro->outputGapEma * 0.60, 4),
                    'type'   => 'macro',
                ];
                break;

            case 'telecom':
            case 'waste_management':
            case 'medical_care_facility':
            case 'education':
            case 'law_firm':
            case 'advertising_agency':
            case 'conglomerate':
            default:
                $outputGapShift = $macro->outputGapEma * max(0.5, $beta);
                $drivers[] = [
                    'label'  => 'Macro Output Gap Demand',
                    'impact' => round($outputGapShift, 4),
                    'type'   => 'macro',
                ];
                if ($macro->inflationEma > 0.02) {
                    $drivers[] = [
                        'label'  => 'Inflation & Pricing Power',
                        'impact' => round(($macro->inflationEma - 0.02) * 0.60, 4),
                        'type'   => 'macro',
                    ];
                }
                $sentShift = ($macro->consumerSentimentIndexEma - 100.0) / 100.0;
                if (abs($sentShift) >= 0.02) {
                    $drivers[] = [
                        'label'  => 'Consumer Sentiment & Demand',
                        'impact' => round($sentShift * 0.25, 4),
                        'type'   => 'macro',
                    ];
                }
                break;
        }

        return $drivers;
    }
}
