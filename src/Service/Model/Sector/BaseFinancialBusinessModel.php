<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\DebtCostDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Market\Bond\RatingMethodology;
use App\Service\Math\CreditRisk;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Model\AbstractBusinessModel;
use App\Service\Model\ModelParam;

/**
 * Base class for all financial institutions (banks, insurers, asset managers, brokerages, etc.).
 *
 * Overrides the standard physics where a balance-sheet firm differs from an operating one: measured on return on
 * equity, held to regulatory leverage limits, its debt its inventory rather than its financing (no Hamada re-levering).
 */
abstract class BaseFinancialBusinessModel extends AbstractBusinessModel
{
    // --- Credit Rating ---
    /** A financial institution is rated on its capital, not its cash flow; asset managers declare their own. */
    public const RATING_METHODOLOGY = RatingMethodology::CapitalRated;

    // --- Bank Levy (UK Finance Act 2011, Schedule 19; FDIC) ---
    /**
     * Whether the institution is a bank the levy is charged on: deposit-takers and investment banks are; brokers,
     * clearinghouses, insurers, asset managers and non-bank lenders are not.
     */
    public const PAYS_BANK_LEVY = false;
    /**
     * Share of deposits a deposit-insurance scheme covers, which the levy leaves out: 60.4% of US domestic deposits in
     * 2024 (FDIC, estimated insured deposits).
     */
    public const INSURED_DEPOSIT_SHARE = 0.604;

    // --- Industry Share Dynamics ---
    /**
     * Share of a financial institution's idiosyncratic gain taken from peers (0.35): deposits, mandates and AUM move
     * between houses, but much of the swing is market volume.
     */
    public const INDUSTRY_SUBSTITUTABILITY = 0.35;

    // --- Firm-Level Common Factor ---
    /**
     * One-factor loading of fee and spread streams on the institution-wide franchise innovation (rho^2 = 16% shared
     * variance).
     */
    public const FIRM_FACTOR_LOADING = 0.40;
    /**
     * Two-factor loading of fee and spread streams on the persistent macro-sector demand factor (rho_s^2 = 12% variance
     * shared with sector peers).
     */
    public const SECTOR_FACTOR_LOADING = 0.35;

    // --- Return Reversion ---
    /** Pull of the trailing return toward the required return plus the moat (0.18 a year). */
    public const ROIC_REVERSION_SPEED = 0.18;

    // --- Capital Cycle ---
    /** Share of construction in progress completed into plant each quarter (100%): branches and systems, no build. */
    public const CAPEX_COMPLETION_RATE = 1.0;
    /**
     * Net working capital as a share of revenue: none, a balance sheet holds loans and securities, not a trade cycle.
     */
    public const WORKING_CAPITAL_INTENSITY = 0.0;

    // --- Credit Thresholds ---
    /** Minimum interest coverage the firm is held to (1.05x): debt is a financial's raw material, not its financing. */
    public const MIN_ICR = 1.05;
    /** Interest coverage below which a firm without a cash buffer is in a dividend crisis (1.05x). */
    public const DIVIDEND_CRISIS_ICR = 1.05;
    /** Interest coverage below which the firm stops repurchasing stock (1.15x). */
    public const BUYBACK_MIN_ICR = 1.15;
    /** Capital ratio, in percent of assets, below which the institution is bankrupt (2%). */
    public const BANKRUPT_EQUITY_THRESHOLD = 2.0;
    /** Capital ratio, in percent of assets, below which the institution is in distress (4%). */
    public const DISTRESS_EQUITY_THRESHOLD = 4.0;
    /** Capital ratio, in percent of assets, below which the institution is in the grey zone (6%). */
    public const WARNING_EQUITY_THRESHOLD = 6.0;

    /**
     * The bank levy on the balance sheet at the rate in force: wholesale funding split by how much of it reprices
     * within a year (the floating share), the revolver as short-term funding, and the deposits deposit insurance does
     * not cover at the long-term rate; equity, which is Tier 1, and insured deposits are left out.
     */
    public function calculateAnnualBankLevy(Stock $stock, MacroStateDTO $macroState): float
    {
        if (!static::PAYS_BANK_LEVY || $macroState->bankLevyRate <= 0.0) {
            return 0.0;
        }

        return $macroState->bankLevyRate * $this->annualBankLevyBase($stock);
    }

    public function annualBankLevyBase(Stock $stock): float
    {
        if (!static::PAYS_BANK_LEVY) {
            return 0.0;
        }

        $wholesale = (float) $stock->getWholesaleDebt();
        $floating = max(0.0, min(1.0, (float) ($stock->getFloatingDebtRatio() ?? 0.0)));
        $uninsuredDeposits = (float) ($stock->getCustomerDeposits() ?? 0.0) * (1.0 - self::INSURED_DEPOSIT_SHARE);

        return MacroTransmission::calculateAnnualBankLevy(
            shortTermFunding: ($wholesale * $floating) + (float) $stock->getRevolverDrawn(),
            longTermFunding: ($wholesale * (1.0 - $floating)) + $uninsuredDeposits,
            shortTermRate: 1.0,
        );
    }

    /**
     * An institution's leverage converges to its own time-invariant target (Gropp & Heider 2010), the ratio it
     * was built with. One without a tuned target runs none.
     */
    public function getTargetCapitalRatio(Stock $stock, ?MacroStateDTO $macroState = null): ?float
    {
        $target = $this->resolveModelParameters($stock, [ModelParam::TargetCapitalRatio->value => 0.0])[ModelParam::TargetCapitalRatio];

        return $target > 0.0 ? $target : null;
    }

    /**
     * A financial's scale is its equity, so one that closes at capacity holds its market share by handing back what
     * it earns past it. One without a tuned capacity grows wherever its returns clear its hurdle.
     */
    public function getCapacityShare(Stock $stock): ?float
    {
        $share = $this->resolveModelParameters($stock, [ModelParam::CapacityShare->value => 0.0])[ModelParam::CapacityShare];

        return $share > 0.0 ? $share : null;
    }

    public function getEffectiveReturn(Stock $stock): float
    {
        // Cast before the test: a stored zero reads "0" or "0.0000", and only the first is falsy as a string.
        return (float) $stock->getCurrentRoe() ?: (float) $stock->getBaselineRoe();
    }

    public function getTrueReturn(Stock $stock): float
    {
        return (float) $stock->getRoeTtm();
    }

    /** A balance-sheet firm is measured on equity; its baselineRoic column is a seed placeholder nothing fills. */
    public function getBaselineReturn(Stock $stock): float
    {
        return (float) $stock->getBaselineRoe();
    }

    /**
     * The return a balance-sheet firm's revenue capacity is struck at: its structural ROE less the saturation
     * of its market, never below what its equity would earn idle. Trailing results are not an input: a book does not
     * yield less because last year lost money.
     */
    protected function resolveStructuralTargetRoe(Stock $stock, MacroStateDTO $macroState): float
    {
        $saturationPenalty = \App\Service\Corporate\CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, max(1.0, (float) $stock->getTotalEquity()), $macroState);

        return max($this->idleEquityReturn($macroState), max(0.01, (float) $stock->getBaselineRoe()) - $saturationPenalty);
    }

    /**
     * What equity a saturated market has no use for earns: the money-market yield after tax. A financial grown past
     * its market earns that on the excess, not its cost of equity, which no asset pays it.
     */
    protected function idleEquityReturn(MacroStateDTO $macroState): float
    {
        return $this->calculateCashYield($macroState) * (1.0 - $macroState->corporateTaxRate);
    }

    public function calculateEconomicReturn(Stock $stock, float $quarterlyNopatOrIncome, float $investedCapital): float
    {
        $equity = (float) $stock->getTotalEquity();
        return $equity > 0 ? ($quarterlyNopatOrIncome / $equity) * 4.0 : 0.0;
    }

    /**
     * A balance-sheet firm is measured on its return on equity, required to earn its cost of equity. The quarter
     * reports net income as filed; the return state blends it before one-off charges, as an operating company's
     * blends EBIT, so a fine or a write-down is not read as a lower return to come.
     */
    public function updateDynamicRoic(
        Stock $stock,
        float $actualTotalNetIncome,
        float $investedCapital,
        float $ebit,
        float $corporateTaxRate,
        float $wacc = 0.08,
        float $costOfEquity = 0.10,
        ?MacroStateDTO $macroState = null,
        float $depreciation = 0.0,
        float $afterTaxSpecialCharges = 0.0,
    ): float {
        $equity = (float) $stock->getTotalEquity();
        $truePostTaxReturn = $equity > 0 ? ($actualTotalNetIncome / $equity) * FinancialConstants::QUARTERS_PER_YEAR : 0.0;
        $underlyingReturn = $equity > 0 ? (($actualTotalNetIncome + $afterTaxSpecialCharges) / $equity) * FinancialConstants::QUARTERS_PER_YEAR : 0.0;
        $reportedReturn = max(FinancialConstants::MIN_REPORTED_RETURN, min(FinancialConstants::MAX_REPORTED_RETURN, $truePostTaxReturn));

        $stock->setCurrentRoe((string) $reportedReturn);
        $stock->setRoeTtm((string) $this->resolveReturnState($stock, (float) $stock->getRoeTtm(), $underlyingReturn, $costOfEquity, max(1.0, $equity), $macroState));

        return $reportedReturn;
    }

    /**
     * The return state a financial is measured on: the quarter's underlying return blended into the trailing state at
     * the shared weight, with no pull toward the cost of equity. Revenue is struck on the structural ROE, never on
     * this state.
     */
    protected function resolveReturnState(Stock $stock, float $trailing, float $realised, float $costOfEquity, float $equity, ?MacroStateDTO $macroState): float
    {
        $blended = $trailing === 0.0
            ? $realised
            : ($realised * FinancialConstants::TTM_SMOOTHING_NEW_WEIGHT) + ($trailing * (1.0 - FinancialConstants::TTM_SMOOTHING_NEW_WEIGHT));

        return max(FinancialConstants::MIN_REPORTED_RETURN, min(FinancialConstants::MAX_REPORTED_RETURN, $blended));
    }

    public function getLeverageTarget(float $targetDebtTolerance): float
    {
        $limit = $targetDebtTolerance > 0.0 ? $targetDebtTolerance : $this->getWholesaleLeverageLimit();
        return $limit * 0.50;
    }

    public function isUnderLeveraged(float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr): bool
    {
        return $currentDebtRatio < $this->getLeverageTarget($targetDebtTolerance);
    }

    public function getHurdleRate(\App\DTO\DebtHealthDTO $health): float
    {
        return $health->costOfEquity ?? 0.10;
    }

    public function getEvaluationCapital(float $equity, float $investedCapital): float
    {
        return $equity;
    }

    public function getExpansionCapacityBasis(float $equity, float $totalDebt, float $investedCapital): float
    {
        return $equity + $totalDebt;
    }

    public function getMaxOrganicGrowthSpeed(bool $isHoarder, bool $isMegaHoarder): float
    {
        return $isMegaHoarder
            ? FinancialConstants::FIN_MEGA_HOARDER_GROWTH_LIMIT
            : ($isHoarder ? FinancialConstants::FIN_HOARDER_GROWTH_LIMIT : FinancialConstants::FIN_STANDARD_GROWTH_LIMIT);
    }

    public function calculateDebtExpansionCapacity(float $equity, float $totalDebt, float $wholesaleDebt, \App\DTO\DebtHealthDTO $health, float $newBorrowingRate, float $ebit, float $depreciation): float
    {
        $bankEquityLimit = $health->debtTolerance;

        // The wholesale limit is the most corporate debt the model carries against its equity, and zero means
        // it funds itself without the bond market. Read as "no limit", zero handed a clearinghouse the 50x
        // tolerance that belongs to its members' margin pool.
        $wholesaleCapacity = max(0.0, ($equity * $this->getWholesaleLeverageLimit()) - $wholesaleDebt);
        $totalCapacity = max(0.0, ($equity * $bankEquityLimit) - $totalDebt);

        return min($wholesaleCapacity, $totalCapacity);
    }

    public function getRequiredIcrBuffer(): float
    {
        return 0.05; // Financials run on razor thin ICRs because debt is their raw material
    }

    public function getDeleveragingEvaluationDebt(float $totalDebt, float $wholesaleDebt): float
    {
        return $wholesaleDebt;
    }

    public function getDeleveragingEvaluationLimit(float $macroDebtTolerance): float
    {
        return $this->getWholesaleLeverageLimit() > 0 ? $this->getWholesaleLeverageLimit() : $macroDebtTolerance;
    }

    public function isFinancial(): bool
    {
        return true;
    }

    /** Financial revenue is a yield on a book, not a unit price times a volume: no own-price volume response. */
    public function getPriceElasticityOfDemand(): float
    {
        return 0.0;
    }

    /**
     * The loans, securities and other assets the institution earns its yield on, net of the losses it
     * already expects. The ledger is read only where the model actually keeps one: TreasuryEngine gates
     * deployment on deploysFundingIntoEarningAssets(), so a house that never deploys leaves the balance
     * frozen at whatever it was seeded with on the first report, and reading it would price today's book
     * off a years-old snapshot. Everywhere else the book is the identity the ledger is seeded from:
     * equity plus all funding, less the cash still idle and any goodwill an acquisition left behind,
     * neither of which earns a spread.
     */
    public function resolveEarningAssets(Stock $stock, ?float $currentTreasury = null): float
    {
        if ($stock->hasEarningAssetLedger() && $this->deploysFundingIntoEarningAssets()) {
            return max(1.0, $stock->getNetEarningAssets());
        }

        $treasury = $currentTreasury ?? (float) $stock->getCorporateTreasury();
        $tangibleEquity = max(1.0, $stock->getTangibleEquity());

        return max($tangibleEquity, $tangibleEquity + (float) $stock->getTotalDebt() - $treasury);
    }

    /**
     * The loans a quarter's defaults are struck on: the gross balances, before the allowance held against them. Struck
     * on the net book, a lender charged off a lifetime reserve's worth (7% for a card book) less than its
     * through-the-cycle rate on the book the allowance roll-forward carries.
     */
    public function resolveGrossEarningAssets(Stock $stock): float
    {
        if ($stock->hasEarningAssetLedger() && $this->deploysFundingIntoEarningAssets()) {
            return max(1.0, (float) $stock->getEarningAssets());
        }

        return $this->resolveEarningAssets($stock);
    }

    /**
     * The annual credit provision the allowance roll-forward charges a lender through the cycle on a net book of this
     * size, growing with the economy's trend nominal income as its deposits do: charge-offs on the gross balances and
     * the lifetime reserve each year's growth carries. A return target reverse-engineered from an ROE is earned after
     * this charge.
     */
    public function resolveThroughTheCycleCreditProvision(?Stock $stock, float $netEarningAssets): float
    {
        return CreditRisk::calculateSteadyStateProvisionRate(
            $this->getThroughTheCycleCreditLossRate($stock),
            $this->getCreditLossHorizonYears(),
            MacroConstants::TARGET_INFLATION + MacroConstants::TREND_REAL_GROWTH,
        ) * max(0.0, $netEarningAssets);
    }

    public function getPhysicalCapital(Stock $stock): float
    {
        return (float) $stock->getTotalEquity();
    }

    /**
     * A balance sheet business has no trade cycle: it holds loans and securities, not receivables and stock.
     *
     * @return array{dso: float, dio: float, dpo: float}
     */
    public function getWorkingCapitalDays(Stock $stock): array
    {
        return ['dso' => 0.0, 'dio' => 0.0, 'dpo' => 0.0];
    }

    /**
     * A bank's branches and core systems are immaterial next to its balance sheet, so financial models keep
     * depreciating the equity proxy rather than maintaining a plant ledger they would never use.
     */
    public function getDepreciableBase(Stock $stock): float
    {
        return $this->getPhysicalCapital($stock);
    }

    public function allowsPhysicalOrganicCapex(): bool
    {
        return false;
    }

    public function shouldForceDeleveragingOnJunkOrHoarding(): bool
    {
        return false;
    }

    public function calculateStructuralEps(float $bookValuePerShare, float $structuralRoic, float $revenuePerShare, float $riskFreeRate, float $afterTaxCostOfDebt, ?float $investedCapitalPerShare = null): float
    {
        return $bookValuePerShare * $structuralRoic;
    }

    /**
     * A financial's return is already struck on equity (getTrueReturn() is ROE); its debt is its inventory, not its
     * financing.
     */
    public function getEquityReturn(float $returnOnCapital, float $investedCapital, float $equity, float $afterTaxCostOfDebt): float
    {
        return $returnOnCapital;
    }

    public function getRegulatoryDividendCap(Stock $stock, float $currentTreasury, ?MacroStateDTO $macroState = null): ?float
    {
        $leverageOvershoot = $this->resolveTangibleLeverage($stock) / $this->resolveBufferedEquityLimit($stock, $macroState);

        if ($leverageOvershoot >= FinancialConstants::REGULATORY_BUFFER_TIER_3_THRESHOLD) {
            return 0.0;
        } elseif ($leverageOvershoot >= FinancialConstants::REGULATORY_BUFFER_TIER_2_THRESHOLD) {
            return FinancialConstants::REGULATORY_BUFFER_TIER_2_PAYOUT_CAP;
        } elseif ($leverageOvershoot >= FinancialConstants::REGULATORY_BUFFER_TIER_1_THRESHOLD) {
            return FinancialConstants::REGULATORY_BUFFER_TIER_1_PAYOUT_CAP;
        }

        return null;
    }

    public function checkBuybackRegulatoryLockout(Stock $stock, float $currentTreasury, ?MacroStateDTO $macroState = null): ?bool
    {
        $regulatoryCap = $this->getRegulatoryDividendCap($stock, $currentTreasury, $macroState);
        if ($regulatoryCap !== null && $regulatoryCap <= 0.0) {
            return true;
        }

        $buybackLockoutThreshold = max(1.0, $this->resolveBufferedEquityLimit($stock, $macroState) - 1.0) + 0.5;

        if ($this->resolveTangibleLeverage($stock) > $buybackLockoutThreshold) {
            return true;
        }

        return false;
    }

    /** Debt over tangible equity: the leverage a regulator measures, with goodwill deducted from the capital. */
    private function resolveTangibleLeverage(Stock $stock): float
    {
        return (float) $stock->getTotalDebt() / max(1.0, $stock->getTangibleEquity());
    }

    /** The sector's debt-to-equity cap, tightened by the countercyclical buffer in force. */
    private function resolveBufferedEquityLimit(Stock $stock, ?MacroStateDTO $macroState): float
    {
        $equityLimit = \App\Data\Company\Sectors::equityLimit($stock->getIndustry());
        if ($macroState !== null && $macroState->countercyclicalBufferRate > 0.0) {
            $equityLimit = CreditRisk::calculateBufferedLeverageLimit($equityLimit, $macroState->countercyclicalBufferRate);
        }

        return $equityLimit;
    }

    /** A financial funds itself at the wholesale rate, which already reprices with its own Merton and BGG spread. */
    public function getMarginalCostOfDebt(\App\DTO\DebtMetricsDTO $debtMetrics): float
    {
        return $debtMetrics->wholesaleRate;
    }

    public function getDebtCostMetrics(\App\DTO\DebtMetricsDTO $debtMetrics, float $currentDebt, float $wholesaleDebt, float $interestExpense): DebtCostDTO
    {
        // Use the already-computed wholesale rate from the debt metrics DTO.
        // DO NOT divide total interestExpense by wholesaleDebt — that attributes deposit interest to wholesale,
        // inflating cost-of-debt to junk bond levels for deposit-heavy banks.
        $wholesaleRate = $debtMetrics->wholesaleRate;
        $wholesaleInterest = $wholesaleRate * $wholesaleDebt;
        return new DebtCostDTO(grossCostOfDebt: $wholesaleRate, totalInterestCost: $wholesaleInterest);
    }

    public function getNetDebtCapital(float $currentDebt, float $wholesaleDebt, float $treasury): float
    {
        return $wholesaleDebt;
    }

    public function calculateLeveredBeta(float $baseBeta, float $impliedTaxShieldRate, float $effectiveDebtToEquity): float
    {
        // Banks and Financials inherently price their massive structural leverage into their baseline Beta.
        // Re-levering a bank using the Hamada equation creates a Cost of Equity death spiral!
        return $baseBeta;
    }

    public function getAcquisitionType(string $defaultType): string
    {
        return 'STRATEGIC ACQUISITION'; // Financials don't do LBOs or Conglomerate Expansion
    }

    /**
     * A financial buys with regulatory capital, and the goodwill a deal books is deducted from it, so the cap
     * binds whoever is buying: a style bends what management chooses, never the capital rules it is held to.
     */
    public function applyMaSpendCap(float $purchasePrice, float $equity): float
    {
        return min($purchasePrice, max(0.0, $equity) * \App\Service\Corporate\MergerAndAcquisitionEngine::MA_FINANCIAL_EQUITY_CAP);
    }

    public function blendAcquisitionDNA(Stock $acquirer, float $oldCapitalBase, float $purchasePrice, float $effectiveTargetRoic, float $totalNewCapital): void
    {
        $oldBaselineRoe = (float) $acquirer->getBaselineRoe();
        $blendedBaselineRoe = (($oldCapitalBase * $oldBaselineRoe) + ($purchasePrice * $effectiveTargetRoic)) / $totalNewCapital;
        $acquirer->setBaselineRoe((string) max(0.01, $blendedBaselineRoe));
    }

    public function calculateDivestedEquity(Stock $seller, float $divestedFraction, float $currentEquity, float $currentDebt, float $treasury, float $investedCapital, float $lostDebt): float
    {
        $currentDeposits = (float) $seller->getCustomerDeposits();
        $totalLoans = $currentEquity + $currentDebt + $currentDeposits - $treasury;
        $lostLoans = $totalLoans * $divestedFraction;
        return $lostLoans - $lostDebt;
    }

    public function shedDivestedLiabilities(Stock $seller, float $divestedFraction, float $currentTreasury): void
    {
        $currentDeposits = (float) $seller->getCustomerDeposits();
        $lostDeposits = $currentDeposits * $divestedFraction;
        $seller->setCustomerDeposits((string) max(0.0, $currentDeposits - $lostDeposits));

        // In fractional reserve banking, deposits are backed by the loan book, not pure cash.
        // We transfer the proportional share of the existing cash reserves, not the absolute deposit value.
        // Note: We use the pre-sale $currentTreasury to calculate the divested portion.
        $lostCashReserves = $currentTreasury * $divestedFraction;
        $seller->setCorporateTreasury((string) max(0.0, ((float) $seller->getCorporateTreasury()) - $lostCashReserves));
    }
}
