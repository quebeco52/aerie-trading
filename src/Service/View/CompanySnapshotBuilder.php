<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\AnchorHoldings;
use App\Data\Sectors;
use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Entity\Stock;
use App\Repository\StockRepository;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Market\MarketEngine;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\InsuranceBusinessModel;

/**
 * Builds the headline valuation block on a company's page: size, multiple, yield, and where the
 * analysts have it marked. Its place in its industry is IndustryPositionBuilder's.
 *
 * A delisted company keeps its page but none of these readings: the equity claim is gone, so a
 * market cap or a multiple would each be quoting a number for something that does not exist.
 */
class CompanySnapshotBuilder
{
    /** Quarters in a year: lastDividend is one Lintner step, and a yield is an annual rate. */
    private const DIVIDEND_PERIODS_PER_YEAR = 4.0;

    /** Multiple applied when an industry declares none of its own. */
    private const FALLBACK_INDUSTRY_PE = 20.0;

    public function __construct(
        private readonly MarketEngine $marketEngine,
        private readonly DebtEngine $debtEngine,
        private readonly AnchorStakeLedger $anchorStakes,
        private readonly StockRepository $stocks,
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
            // Retail, reinsurance and specialty lines are all insurers: read on a combined ratio and a float.
            'isInsurer' => Sectors::getBusinessModelStrategy($businessModel) instanceof InsuranceBusinessModel,
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
            'netAssetValue' => $isBankrupt ? null : $this->netAssetValue($stock, $macroState, $businessModel, $price),
            'capitalThresholds' => $this->capitalThresholds($businessModel),
            'capital' => $isBankrupt ? null : $this->capitalPosition($stock, $businessModel, $price),
        ];
    }

    /**
     * What a firm the regulator governs is quoted on: its tangible book, its return on it, and the ratio it
     * would be closed on, with the zone that ratio sits in. Goodwill is deducted everywhere (Basel III CET1,
     * 12 USC 1831o), which is why price-to-tangible-book rather than price-to-book is how a bank is quoted.
     * Null for a firm its capital ratio does not govern.
     *
     * @return array{tangibleEquity: float, tangibleBookPerShare: float, priceToTangibleBook: float|null, returnOnTangibleEquity: float|null, goodwillShare: float|null, ratio: float, zone: string, cet1: float|null}|null
     */
    private function capitalPosition(Stock $stock, string $businessModel, float $price): ?array
    {
        $strategy = Sectors::getBusinessModelStrategy($businessModel);
        if (!$strategy->requiresAlternativeZScore()) {
            return null;
        }

        $tangibleEquity = $stock->getTangibleEquity();
        $tangibleBookPerShare = $tangibleEquity / max(1.0, (float) $stock->getSharesOutstanding());
        $equity = (float) $stock->getTotalEquity();
        // Trailing twelve months, as EarningsEngine keeps it: the last four reported quarters.
        $history = $stock->getQuarterlyNetIncomeHistory() ?? [];
        $revenue = (float) $stock->getTotalRevenue();
        $closure = $this->debtEngine->calculateAltmanZScore($stock, $revenue * (float) $stock->getOperatingMargin(), $revenue, $price);

        return [
            'tangibleEquity' => $tangibleEquity,
            'tangibleBookPerShare' => $tangibleBookPerShare,
            'priceToTangibleBook' => $tangibleBookPerShare > 0.0 ? $price / $tangibleBookPerShare : null,
            'returnOnTangibleEquity' => $tangibleEquity > 0.0 && count($history) >= 4 ? array_sum(array_slice($history, -4)) / $tangibleEquity : null,
            'goodwillShare' => $equity > 0.0 ? max(0.0, (float) $stock->getGoodwill()) / $equity : null,
            'ratio' => $closure['z_score'] / 100.0,
            'zone' => $closure['zone'],
            'cet1' => $strategy instanceof CommercialBankBusinessModel ? $strategy->calculateCet1Ratio($stock) : null,
        ];
    }

    /**
     * The lines the regulator acts on, in percent of tangible assets: above the warning line the firm is well
     * capitalized, below the distress line it is distressed, and below the closure line MarketOperator
     * liquidates it. Null for a firm its capital ratio does not govern.
     *
     * @return array{warning: float, distress: float, bankrupt: float}|null
     */
    private function capitalThresholds(string $businessModel): ?array
    {
        $strategy = Sectors::getBusinessModelStrategy($businessModel);
        if (!$strategy->requiresAlternativeZScore()) {
            return null;
        }

        return [
            'warning' => $strategy->getWarningEquityThreshold(),
            'distress' => $strategy->getDistressEquityThreshold(),
            'bankrupt' => $strategy->getBankruptEquityThreshold(),
        ];
    }

    /**
     * Net asset value per share and where the market has it against that.
     *
     * The headline number on every closed-end factsheet. Shown for any model declaring a standing
     * structural discount, which is what "closed-end structure" means in ValuationStrategyInterface.
     *
     * Priced off the board rather than read from the last filing, since the holdings are listed and have
     * moved since. The web process has its own ledger instance, primed here from the holdings alone.
     *
     * @return array{perShare: float, premium: float}|null
     */
    private function netAssetValue(Stock $stock, MacroStateDTO $macroState, string $businessModel, float $price): ?array
    {
        $strategy = Sectors::getBusinessModelStrategy($businessModel);

        if ($strategy->getStructuralValuationDiscount($macroState) <= 0.0) {
            return null;
        }

        $held = array_keys(AnchorHoldings::forHolder($stock->getTicker()));

        if ($held !== []) {
            $this->anchorStakes->beginTick($this->stocks->findBy(['ticker' => $held]));
        }

        $navPerShare = $this->anchorStakes->resolveMarkedBookValuePerShare($stock)
            ?? (float) $stock->getBookValuePerShare();

        if ($navPerShare <= 0.0 || $price <= 0.0) {
            return null;
        }

        // Signed the way a factsheet signs it: negative is a discount, positive a premium.
        return ['perShare' => $navPerShare, 'premium' => ($price / $navPerShare) - 1.0];
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
            tangibleBookValuePerShare: $stock->getTangibleEquity() / $shares,
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

        // The PUBLISHED target, which is a standing figure revised in steps, not the fair value recomputed
        // for this page view. A reader who refreshes twice must see the same target both times, or the
        // number is a live quote wearing an analyst's name and a revision means nothing.
        $published = $stock->getAnalystPriceTarget();

        if ($published !== null && (float) $published > 0.0) {
            $targets['consensus'] = (float) $published;
        }

        $targets['rating'] = $stock->getAnalystRating();
        $targets['upside_pct'] = $price > 0.0 ? (($targets['consensus'] - $price) / $price) * 100.0 : 0.0;

        return $targets;
    }
}
