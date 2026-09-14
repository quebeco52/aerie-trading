<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Sectors;
use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Entity\Stock;
use App\Service\Corporate\DebtEngine;
use App\Service\Market\MarketEngine;

/**
 * Builds the headline valuation block on a company's page: size, multiple, yield, and where the
 * analysts have it marked. Its place in its industry is IndustryPositionBuilder's.
 *
 * A delisted company keeps its page but none of these readings: the equity claim is gone, so a
 * market cap or a multiple would each be quoting a number for something that does not exist.
 */
class CompanySnapshotBuilder
{
    // --- Analyst Consensus ---

    /** Upside over the traded price above which the consensus reads as a buy rather than as fair. */
    private const OUTPERFORM_THRESHOLD = 1.05;

    /** Downside below the traded price under which the consensus reads as a sell. */
    private const UNDERPERFORM_THRESHOLD = 0.95;

    /** Quarters in a year: lastDividend is one Lintner step, and a yield is an annual rate. */
    private const DIVIDEND_PERIODS_PER_YEAR = 4.0;

    /** Multiple applied when an industry declares none of its own. */
    private const FALLBACK_INDUSTRY_PE = 20.0;

    public function __construct(
        private readonly MarketEngine $marketEngine,
        private readonly DebtEngine $debtEngine,
    ) {}

    /**
     * @return array<string, mixed> The valuation keys the stock page renders.
     */
    public function build(Stock $stock, MacroStateDTO $macroState): array
    {
        $businessModel = Sectors::INDUSTRY_METRICS[$stock->getIndustry() ?? 'General']['business_model'] ?? 'none';
        $isFinancial = Sectors::isFinancial($businessModel);
        $isBankrupt = $stock->isBankrupt();

        $price = (float) $stock->getPrice();
        $eps = $isBankrupt ? 0.0 : (float) $stock->getEarningsPerShare();
        $lastDividend = (float) $stock->getLastDividend();

        return [
            'isFinancial' => $isFinancial,
            'businessModel' => $businessModel,
            'marketCap' => $isBankrupt ? 0.0 : $price * (float) $stock->getSharesOutstanding(),
            'peRatio' => (!$isBankrupt && $eps > 0.0) ? $price / $eps : null,
            'targetPE' => self::FALLBACK_INDUSTRY_PE,
            'investedCapital' => $isBankrupt ? 0.0 : (float) $stock->getInvestedCapital(),
            // Dickinson (2011) stage stored by the last quarterly report; null until the first lands.
            'lifecycleStage' => $stock->getLifecycleStage(),
            'dividendYield' => (!$isBankrupt && $price > 0.0 && $lastDividend > 0.0)
                ? ($lastDividend * self::DIVIDEND_PERIODS_PER_YEAR) / $price
                : 0.0,
            'analystTargets' => $isBankrupt ? null : $this->analystTargets($stock, $macroState, $businessModel),
        ];
    }

    /**
     * Where the three analyst schools have the company marked, and what that says against the tape.
     *
     * @return array<string, mixed>
     */
    private function analystTargets(Stock $stock, MacroStateDTO $macroState, string $businessModel): array
    {
        $price = (float) $stock->getPrice();
        $shares = max(1.0, (float) $stock->getSharesOutstanding());
        $industry = $stock->getIndustry() ?: 'General';
        $strategy = Sectors::getBusinessModelStrategy($businessModel);
        $health = $this->debtEngine->analyzeDebtHealth($stock, $macroState);

        $netDebt = max(0.0, $strategy->getNetDebtCapital(
            (float) $stock->getTotalDebt(),
            (float) $stock->getWholesaleDebt(),
            (float) $stock->getCorporateTreasury()
        ));

        // dt is zero: this prices the company as it stands for display, and must not advance the path.
        $context = new MarketPricingContext(
            currentPrice: $price,
            currentVolatility: (float) ($stock->getCurrentVolatility() ?? $stock->getVolatility()),
            longTermVolatility: (float) $stock->getVolatility(),
            earningsPerShare: (float) $stock->getEarningsPerShare(),
            dt: 0.0,
            beta: (float) $stock->getBeta(),
            macroState: $macroState,
            fcfPerShare: $stock->getFreeCashFlowPerShare() !== null ? (float) $stock->getFreeCashFlowPerShare() : null,
            bookValuePerShare: (float) $stock->getBookValuePerShare(),
            currentRoic: (float) ($stock->getCurrentRoic() ?: $stock->getBaselineRoic()),
            roicTtm: (float) $stock->getRoicTtm(),
            dividendPerShare: (float) $stock->getLastDividend(),
            liveWacc: $health->wacc ?? 0.08,
            baselineIndustryPE: Sectors::INDUSTRY_METRICS[$industry]['pe_ratio'] ?? self::FALLBACK_INDUSTRY_PE,
            revenuePerShare: (float) $stock->getTotalRevenue() / $shares,
            businessModel: $businessModel,
            liveCostOfEquity: $health->costOfEquity ?? 0.10,
            netDebtPerShare: $netDebt / $shares,
            secularGrowth: $strategy->getSecularGrowthRate($stock),
            baselineRoic: (float) ($stock->getBaselineRoic() ?? 0.10),
            baselineMargin: (float) ($stock->getOperatingMargin() ?? 0.20),
            investedCapitalPerShare: $stock->getInvestedCapital() / $shares
        );

        // Priced once, not once per reading: the call draws, so a second one would hand the page a
        // consensus struck against a different fair value than the targets beside it.
        $pricing = $this->marketEngine->calculateNextPrice($context);

        $targets = $pricing['analyst_targets'];
        $fairValue = (float) ($pricing['perceived_fair_value'] ?? 0.0);
        $targets['consensus'] = $fairValue > 0.0
            ? $fairValue
            : max($targets['growth_analyst'], $targets['income_analyst'], $targets['value_analyst']);

        $targets['rating'] = match (true) {
            $targets['consensus'] > $price * self::OUTPERFORM_THRESHOLD => 'Outperform',
            $targets['consensus'] < $price * self::UNDERPERFORM_THRESHOLD => 'Underperform',
            default => 'Neutral',
        };
        $targets['upside_pct'] = $price > 0.0 ? (($targets['consensus'] - $price) / $price) * 100.0 : 0.0;

        return $targets;
    }
}
