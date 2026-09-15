<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\DTO\CapitalAllocationContext;

/**
 * Service responsible for managing the internal corporate balance sheet.
 * Handles CapEx, Debt Issuance, Deleveraging Sweeps, and Liquidity Crises.
 */
class TreasuryEngine
{
    // --- CapEx & Deployment Rates ---
    /** Base organic spend rate (15%) for retained cash hoards. */
    private const BASE_ORGANIC_SPEND_RATE = 0.15;
    /** Additional variable spend rate (35%) scaled by economic spread (ROIC - WACC). */
    private const VARIABLE_ORGANIC_SPEND_RATE = 0.35;

    // --- Lender Funding Deployment ---
    /** Cash held above the operating target before a lender treats the rest as deployable funding (same buffer the expansion path uses). */
    private const LIQUIDITY_BUFFER_MULTIPLIER = 1.20;

    // --- Committed Revolving Credit Facility ---
    /**
     * Committed revolver sized as a multiple of the firm's minimum operating cash. That base is what each
     * business model already scales its liquidity needs on, so a lender's facility is struck on its funding
     * book rather than on net interest income, which is a small number attached to an enormous balance sheet.
     */
    private const REVOLVER_COMMITMENT_OPERATING_CASH_MULTIPLE = 5.0;
    /** Drawn-revolver spread (+100 bps) over the issuer's market rate; a pre-negotiated facility prices inside emergency paper. */
    private const REVOLVER_DRAW_SPREAD_PENALTY = 0.01;

    // --- Physical Capacity Limits (Growth Speed Limits) ---
    private const FIN_MEGA_HOARDER_GROWTH_LIMIT = 0.35;
    private const FIN_HOARDER_GROWTH_LIMIT = 0.20;
    private const FIN_STANDARD_GROWTH_LIMIT = 0.12;
    private const STD_HOARDER_GROWTH_LIMIT = 0.15;
    private const STD_STANDARD_GROWTH_LIMIT = 0.08;

    public function __construct(
        private CorporateMetrics $corporateMetrics,
        private DebtEngine $debtEngine,
        private CapExEngine $capExEngine,
        private MathUtility $mathUtility
    ) {}

    /**
     * Phase 1 of Balance Sheet Update: Execute Corporate Strategy
     * Handles M2 growth, debt issuance (recapitalization/expansion), and organic CapEx.
     * This must run BEFORE buybacks so that newly issued debt cash can fund Leveraged Buybacks,
     * and Growth CapEx takes priority over share repurchases.
     */
    public function executeCorporateStrategy(CapitalAllocationContext $ctx): void
    {
        // SYSTEMIC M2 MONEY SUPPLY GROWTH
        $this->processPassiveLiabilityGrowth($ctx);

        // A withdrawal the cash could not cover is met by selling earning assets, at a discount, before the
        // treasury reaches for borrowing: a run is paid out of the book, and the book takes the loss.
        $this->liquidateEarningAssetsForCash($ctx, 0.0);

        // DEBT MANAGEMENT (MACRO TOLERANCE)
        $this->processDebtExpansion($ctx);

        // ORGANIC BUSINESS EXPANSION (Internal CapEx)
        $this->processOrganicCapex($ctx);

        // SAVE INTERMEDIATE TREASURY (so CapitalAllocationEngine can use it for buybacks)
        $ctx->stock->setCorporateTreasury((string) $ctx->newTreasury);
    }

    /**
     * Phase 2 of Balance Sheet Update: Finalize Liquidity & Equity
     * Handles emergency borrowing, deleveraging, and clean surplus accounting.
     * This must run AFTER buybacks to capture the true final cash balance.
     */
    public function finalizeLiquidity(CapitalAllocationContext $ctx): void
    {
        $stock = $ctx->stock;
        $totalCashSpent = $ctx->totalPaid + $ctx->totalCashSpent;

        // RETAINED EARNINGS
        $currentRetainedStr = $this->formatBc($stock->getRetainedEarnings());
        $netIncomeStr = $this->formatBc($ctx->quarterlyNetIncome);
        $divPaidStr = $this->formatBc($ctx->totalPaid);
        $newRetainedStr = \bcsub(\bcadd($currentRetainedStr, $netIncomeStr, 4), $divPaidStr, 4);
        $stock->setRetainedEarnings($newRetainedStr);

        // TOTAL EQUITY (Clean Surplus Accounting)
        // Equity moves only through earnings, paid-in capital and distributions. It is deliberately NOT
        // revalued with inflation: US GAAP carries plant at historical cost and never writes it up, so the
        // old revaluation created book value out of nothing, with no income and no cash behind it. Inflation
        // now reaches the balance sheet the way it really does, through replacement-cost maintenance CapEx:
        // the firm spends more cash to replace the same asset, and the plant ledger grows by what it spent.
        //
        // Stock-based compensation (ASC 718) is an expense inside net income whose credit side is additional
        // paid-in capital, not cash: without adding it back here equity fell by a charge that never left the
        // company, understating book value and invested capital a little more every quarter. It is added to
        // paid-in capital, not to retained earnings, which is why the retained-earnings roll-forward above
        // deliberately does not carry it.
        $currentEquityStr = $this->formatBc($stock->getTotalEquity());
        $stockCompStr = $this->formatBc($ctx->stockCompensation);
        $totalCashSpentStr = $this->formatBc($totalCashSpent);
        $newEquityStr = \bcsub(\bcadd(\bcadd($currentEquityStr, $netIncomeStr, 4), $stockCompStr, 4), $totalCashSpentStr, 4);
        $stock->setTotalEquity($newEquityStr);

        // THE MATURITY WALL (principal actually comes due)
        $this->processDebtMaturities($ctx);

        // A lender short of its operating cash sells from the book before it borrows at penalty rates.
        $this->liquidateEarningAssetsForCash(
            $ctx,
            $ctx->strategy->calculateMinOperatingCash($ctx->operatingBase, $ctx->customerDeposits, $ctx->wholesaleDebt)
        );

        // THE DEBT TRAP (Liquidity Crisis)
        $this->processEmergencyBorrowing($ctx);

        // COMMITTED REVOLVER (the facility a bank contracted to fund whatever the bond market is doing)
        $this->processRevolverDraw($ctx);

        // EQUITY ISSUANCE (Secondary Offerings / Death Spirals)
        $this->processEquityIssuance($ctx);

        // EVENT OF DEFAULT (a maturity nobody would fund)
        $this->processPaymentDefault($ctx);

        // ARBITRAGE PAYDOWN (Escape negative carry)
        $this->processArbitragePaydown($ctx);

        // THE DELEVERAGING SWEEP (Macro-Driven Cash Management)
        $this->processDeleveragingSweep($ctx, (float) $newEquityStr);

        // Earning assets sold below carrying value this quarter: the discount is a loss the shareholders
        // bear, taken to equity as other comprehensive loss (the securities were never in earnings) so
        // the book that shrank and the claims on it move together.
        if ($ctx->assetSaleLoss > 0.0) {
            $lossStr = $this->formatBc($ctx->assetSaleLoss);
            $stock->setTotalEquity(\bcsub($this->formatBc($stock->getTotalEquity()), $lossStr, 4));
            $stock->setRetainedEarnings(\bcsub($this->formatBc($stock->getRetainedEarnings()), $lossStr, 4));
        }

        // SAVE FINAL TREASURY. Cash is an asset and cannot be negative; the revolver draw above is what
        // closes an overdraft, and this is the failsafe behind it. Carrying a negative balance put a
        // liability in the asset column, where it flowed on into the Altman working-capital term, net asset
        // value and every screener built on the treasury.
        $ctx->newTreasury = max(0.0, $ctx->newTreasury);
        $stock->setCorporateTreasury((string) $ctx->newTreasury);
    }

    /**
     * Raises cash by selling earning assets when the balance has fallen below a floor.
     *
     * Securities go first and go below par; loans nobody bids for on the day cannot go at all, which is
     * why only a bounded share of the book can be sold in a quarter. The allowance attached to the slice
     * sold leaves with it, so the net book falls by exactly the carrying value given up: proceeds come in
     * as cash and the haircut is the loss. This is the mechanism a deposit run actually works through, and
     * the reason a solvent bank with a long-dated book can still be sunk by short-dated funding.
     */
    private function liquidateEarningAssetsForCash(CapitalAllocationContext $ctx, float $cashFloor): void
    {
        $stock = $ctx->stock;
        if (!$ctx->strategy->isFinancial() || !$stock->hasEarningAssetLedger() || $ctx->newTreasury >= $cashFloor) {
            return;
        }

        $grossBook = (float) $stock->getEarningAssets();
        $allowance = (float) $stock->getCreditLossAllowance();
        $netBook = max(0.0, $grossBook - $allowance);
        if ($netBook <= 0.0) {
            return;
        }

        $haircut = FinancialConstants::EARNING_ASSET_FIRE_SALE_HAIRCUT;
        $shortfall = $cashFloor - $ctx->newTreasury;
        $carryingValueSold = min(
            $netBook * FinancialConstants::MAX_QUARTERLY_ASSET_LIQUIDATION_RATIO,
            $shortfall / (1.0 - $haircut)
        );
        if ($carryingValueSold <= 0.0) {
            return;
        }

        $proceeds = $carryingValueSold * (1.0 - $haircut);
        $retained = 1.0 - ($carryingValueSold / $netBook);
        $stock->setEarningAssets((string) ($grossBook * $retained));
        $stock->setCreditLossAllowance((string) ($allowance * $retained));

        $ctx->newTreasury += $proceeds;
        $ctx->assetSaleProceeds += $proceeds;
        $ctx->assetSaleLoss += $carryingValueSold - $proceeds;

        if ($proceeds > 500_000_000.0) {
            $amtB = number_format($proceeds / 1_000_000_000, 2);
            $lossB = number_format(($carryingValueSold - $proceeds) / 1_000_000_000, 2);
            $ctx->events[] = ['description' => "Sold \${$amtB}B of securities and loans below carrying value to meet withdrawals, realizing a \${$lossB}B loss.", 'shock' => -3.0];
        }
    }

    private function processDebtExpansion(CapitalAllocationContext $ctx): void
    {
        $stock = $ctx->stock;

        $currentEquity = (float) $stock->getTotalEquity();
        $preBuybackEquity = $currentEquity + $ctx->quarterlyNetIncome + $ctx->stockCompensation - $ctx->totalPaid;

        $totalDebt = $ctx->wholesaleDebt + $ctx->customerDeposits;
        $liveInvestedCapital = $this->corporateMetrics->calculateLiveInvestedCapital($preBuybackEquity, $totalDebt, $ctx->newTreasury);

        $trueReturn = $ctx->strategy->getTrueReturn($stock);
        if ($trueReturn === 0.0) {
            $trueReturn = $ctx->strategy->calculateEconomicReturn($stock, $ctx->quarterlyNopat, $liveInvestedCapital);
        }
        // The hurdle a capital-deployment gate tests against is the one MANAGEMENT applies, not the firm's
        // true cost of capital — the same hurdle the earnings engine's growth-capex gate uses. Borrowing to
        // build is the same decision as building from cash, so one manager has to bring one hurdle to both;
        // reading the raw rate here left an empire builder disciplined about debt-funded plant and reckless
        // about cash-funded plant, which is not a persistent style but an accident of funding source.
        $manager = $stock->getManagementProfile();
        $hurdleRate = $manager->appliedHurdle($ctx->strategy->getHurdleRate($ctx->health));

        $evaluationCapital = $ctx->strategy->getEvaluationCapital($preBuybackEquity, $liveInvestedCapital);

        $saturationPenalty = $this->corporateMetrics->calculateMarketSaturationPenalty($stock, $evaluationCapital, $ctx->macroState);
        $marginalReturn = $this->corporateMetrics->calculateMarginalReturn($stock, $trueReturn, $saturationPenalty, $evaluationCapital, $ctx->macroState);

        $isUnderLeveraged = $ctx->health->isUnderLeveraged ?? false;
        $isUnderLeveragedForDebt = $isUnderLeveraged
            && $ctx->strategy->supportsUnderleveragedDebtExpansion()
            && !$ctx->health->isSevereNegativeCarry;

        if (($marginalReturn > $hurdleRate || $isUnderLeveragedForDebt) && $ctx->health->canIssueDebt) {
            $newBorrowingRate = $ctx->health->rawMetrics->currentMarketRate ?? 0.05;

            $ebit = $ctx->health->rawMetrics->ebit ?? 0.0;
            $depreciation = $ctx->health->rawMetrics->depreciation ?? 0.0;

            $trueExpansionCapacity = $ctx->strategy->calculateDebtExpansionCapacity(
                $preBuybackEquity,
                $totalDebt,
                $ctx->wholesaleDebt,
                $ctx->health,
                $newBorrowingRate,
                $ebit,
                $depreciation
            );

            // Subtract excess cash from the TOTAL balance sheet capacity first
            $targetCashReserves = $manager->appliedTargetCash($ctx->strategy->calculateTargetOperatingCash($ctx->operatingBase, $ctx->customerDeposits, $ctx->wholesaleDebt)) * 1.20;
            $excessCash = max(0.0, $ctx->newTreasury - $targetCashReserves);

            $trueExpansionCapacity = $ctx->strategy->getUnfundedExpansionCapacity($trueExpansionCapacity, $excessCash);

            // Max 25% of operations per quarter (Quarterly Flow Limit)
            $trueExpansionCapacity = min($trueExpansionCapacity, $liveInvestedCapital * 0.25);

            if ($trueExpansionCapacity > 0) {
                $rawSpread = max(0.0, $marginalReturn - $hurdleRate);
                $spreadMultiplier = min(1.0, $rawSpread * 10.0);

                $aggressionData = $ctx->strategy->getDebtExpansionAggressiveness(
                    $spreadMultiplier,
                    $totalDebt,
                    $ctx->customerDeposits,
                    $targetCashReserves,
                    $ctx->newTreasury
                );

                // How much of that capacity the firm is willing to draw is a manager's choice; the capacity
                // itself is not, so the style scales the appetite and never the limit. A fortress leaves
                // headroom unused through the cycle, an empire builder borrows into it.
                $borrowProbability = min(1.0, $aggressionData->probability * $manager->leverageBias());
                $aggressiveness = min(1.0, $aggressionData->aggressiveness * $manager->leverageBias());

                if ($isUnderLeveragedForDebt) {
                    $aggressiveness = max($aggressiveness, 0.50);
                    $borrowProbability = max($borrowProbability, 0.90);
                }

                if ((mt_rand() / mt_getrandmax()) < $borrowProbability) {
                    $newDebtIssued = $trueExpansionCapacity * $aggressiveness;

                    $this->debtEngine->issueDebt($stock, $newDebtIssued, $newBorrowingRate);

                    $ctx->wholesaleDebt = (float) $stock->getWholesaleDebt();
                    $ctx->newTreasury += $newDebtIssued;
                    $ctx->debtIssued = $newDebtIssued;
                    $ctx->debtActionTaken = true;
                    if ($isUnderLeveragedForDebt) {
                        $ctx->recapActionTaken = true;
                    }

                    if ($newDebtIssued > 500_000_000.0) {
                        $amtB = number_format($newDebtIssued / 1_000_000_000, 2);
                        $ctx->events[] = ['description' => "Issued \${$amtB}B in bonds for " . ($isUnderLeveragedForDebt ? "recapitalization" : "expansion") . ".", 'shock' => 0.5];
                    }
                }
            }
        }
    }

    private function processOrganicCapex(CapitalAllocationContext $ctx): void
    {
        $stock = $ctx->stock;
        if ($ctx->strategy->isFinancial() && $stock->hasEarningAssetLedger() && $ctx->strategy->deploysFundingIntoEarningAssets()) {
            $this->deployFundingIntoEarningAssets($ctx);

            return;
        }

        $currentEquity = (float) $stock->getTotalEquity();
        $preBuybackEquity = $currentEquity + $ctx->quarterlyNetIncome + $ctx->stockCompensation - $ctx->totalPaid;

        $totalDebt = $ctx->wholesaleDebt + $ctx->customerDeposits;
        $liveInvestedCapital = $this->corporateMetrics->calculateLiveInvestedCapital($preBuybackEquity, $totalDebt, $ctx->newTreasury);
        $trueReturn = $ctx->strategy->getTrueReturn($stock);
        if ($trueReturn === 0.0) {
            $trueReturn = $ctx->strategy->calculateEconomicReturn($stock, $ctx->quarterlyNopat, $liveInvestedCapital);
        }
        // Management's own hurdle again, the same one the debt-funded gate above and the earnings engine's
        // growth-capex gate apply.
        $manager = $stock->getManagementProfile();
        $hurdleRate = $manager->appliedHurdle($ctx->strategy->getHurdleRate($ctx->health));
        $evaluationCapital = $ctx->strategy->getEvaluationCapital($preBuybackEquity, $liveInvestedCapital);

        $saturationPenalty = $this->corporateMetrics->calculateMarketSaturationPenalty($stock, $evaluationCapital, $ctx->macroState);
        $marginalReturn = $this->corporateMetrics->calculateMarginalReturn($stock, $trueReturn, $saturationPenalty, $evaluationCapital, $ctx->macroState);

        $investmentProbability = min(0.95, max(0.10, 0.20 + ($marginalReturn * 2.0)));

        $isRecap = $ctx->recapActionTaken;
        $forcedExpansion = $ctx->debtActionTaken && !$isRecap;

        // Under-leveraged financials must shrink equity via buybacks, not grow assets.
        // Skipping organic capex entirely here ensures the FCF stays in treasury so
        // CapitalAllocationEngine::executeBuybacks() can deploy it for recapitalization.
        // Expanding the loan book when D/E is far below the regulatory target only worsens
        // equity bloat and suppresses ROE further.
        if (!$forcedExpansion && $ctx->strategy->isFinancial() && ($ctx->health->isUnderLeveraged ?? false)) {
            return;
        }

        if (!$forcedExpansion && (mt_rand(1, 1000) / 1000.0) > $investmentProbability) {
            return;
        }

        $targetCashReserves = $manager->appliedTargetCash($ctx->strategy->calculateTargetOperatingCash($ctx->operatingBase, $ctx->customerDeposits, $ctx->wholesaleDebt)) * 1.20;
        $hoardStatus = $ctx->strategy->evaluateHoardingStatus($ctx->newTreasury, $targetCashReserves, $manager->appliedHoardingBase($ctx->operatingBase), $totalDebt);
        $excessCash = $hoardStatus['excess_cash'];
        $isHoarder = $hoardStatus['is_hoarder'];
        $isMegaHoarder = $hoardStatus['is_mega_hoarder'];

        // The NPV test is on the MARGINAL return — what the next dollar of plant earns at this scale — the
        // same figure processDebtExpansion already borrows against. Testing the average return instead let
        // a firm whose marginal return had fallen below its hurdle keep deploying cash for as long as its
        // existing plant still earned above it, which for a saturated firm is forever. The hoarder path is
        // deliberately left on the average: that is Jensen's agency cost of free cash flow, management
        // spending what it will not return, and it is bounded below by the marginal-return zero check.
        if ((($marginalReturn > $hurdleRate || $isHoarder) && $excessCash > 0 && !$ctx->health->wantsToPaydownDebt) || $forcedExpansion) {
            $spreadMultiplier = $isHoarder ? 1.0 : min(1.0, max(0.0, ($marginalReturn - $hurdleRate) * 10.0));

            $baseExcessCash = max(0.0, $excessCash - $ctx->debtIssued);
            $organicSpend = $baseExcessCash * (self::BASE_ORGANIC_SPEND_RATE + (self::VARIABLE_ORGANIC_SPEND_RATE * $spreadMultiplier));

            $expansionSpend = $ctx->strategy->calculateOrganicCapexSpend($organicSpend, $ctx->debtIssued);
            $expansionSpend = min($expansionSpend, $excessCash);

            $maxGrowthSpeed = $ctx->strategy->getMaxOrganicGrowthSpeed($isHoarder, $isMegaHoarder);

            $expansionCapBasis = $ctx->strategy->getExpansionCapacityBasis($preBuybackEquity, $totalDebt, $liveInvestedCapital);
            $maxOrganicCapacity = $expansionCapBasis * $maxGrowthSpeed;

            $expansionSpend = min($expansionSpend, max($maxOrganicCapacity, $ctx->debtIssued));

            $currentCip = $stock->getTotalCipAmount();
            $maxCipAllowed = abs($liveInvestedCapital) * FinancialConstants::MAX_CIP_EXPANSION_THRESHOLD_RATIO;
            if ($currentCip >= $maxCipAllowed && !$forcedExpansion) {
                $expansionSpend = 0.0;
            }

            if ($marginalReturn <= 0.0 && !$forcedExpansion) {
                $expansionSpend = 0.0;
            }

            if ($expansionSpend > 0) {
                $ctx->organicCapex = $expansionSpend;
                $ctx->newTreasury -= $expansionSpend;

                if ($ctx->strategy->isFinancial() && $stock->hasEarningAssetLedger()) {
                    // A lender's expansion is loans made, not plant built: the cash goes straight onto the
                    // earning-asset ledger. Queuing it as construction parked it for a quarter and then dropped it.
                    $stock->setEarningAssets((string) ((float) $stock->getEarningAssets() + $expansionSpend));
                    $ctx->loanOriginations += $expansionSpend;
                } else {
                    $this->capExEngine->allocateGrowthCapEx($stock, $expansionSpend);
                }

                if ($expansionSpend > 1_000_000_000.0) {
                    $amtB = number_format($expansionSpend / 1_000_000_000, 2);
                    $actionText = match ($ctx->businessModel) {
                        'commercial_bank', 'credit_services', 'shadow_bank' => 'loan book expansion',
                        'insurance' => 'underwriting infrastructure and float expansion',
                        'brokerage', 'investment_bank' => 'trading desk and market-making capacity',
                        'asset_manager', 'private_equity' => 'fund seeding and AUM deployment',
                        'clearing_house' => 'clearing collateral and exchange margin reserves',
                        'distressed_debt' => 'distressed credit and turnaround equity acquisitions',
                        'reit' => 'property acquisitions and development',
                        default => 'organic expansion'
                    };
                    $ctx->events[] = ['description' => "Deployed \${$amtB}B in {$actionText}.", 'shock' => 0.5];
                }
            }
        }
    }

    /**
     * Lends out the funding a balance-sheet business holds beyond its liquidity target.
     *
     * Deposits are not a cash hoard waiting for a project with a positive spread: they are the raw material,
     * and a bank that leaves them idle is not a cautious bank but one whose loan book is shrinking against
     * its funding. So unlike physical expansion, which is probabilistic, rate-limited and gated on the
     * marginal return, deployment here is deterministic and complete every quarter: whatever sits above the
     * operating cash target goes into loans and securities, with two exceptions. The earnings retained this
     * quarter stay in cash, because capital return is funded from earnings and not from the deposit base;
     * and an institution its regulator has already stopped from distributing (capital ratio below the
     * conservation buffer, or leverage past its limit) cannot add risk-weighted assets either. Before this
     * the deposit inflow ran through the physical capex path, fired in about half the quarters and then
     * lent only half the excess, so cash ratcheted up quarter after quarter while the book stood still.
     *
     * Deliberately the ONE cash target the management style does not bend. A conservative lender expresses
     * itself in capital held, wholesale funding refused and distributions withheld — not in deposits left
     * sitting idle, which is a shrinking franchise rather than a prudent one. Biasing it here would also
     * starve the book against a consensus that still expects the full deployment, turning the archetype
     * into a chronic earnings miss.
     */
    private function deployFundingIntoEarningAssets(CapitalAllocationContext $ctx): void
    {
        $stock = $ctx->stock;

        $targetCashReserves = $ctx->strategy->calculateTargetOperatingCash($ctx->operatingBase, $ctx->customerDeposits, $ctx->wholesaleDebt) * self::LIQUIDITY_BUFFER_MULTIPLIER;
        $excessCash = max(0.0, $ctx->newTreasury - $targetCashReserves);
        $deployable = max(0.0, $excessCash - max(0.0, $ctx->retainedEarningsThisQuarter));
        if ($deployable <= 0.0) {
            return;
        }

        $regulatoryCap = $ctx->strategy->getRegulatoryDividendCap($stock, $ctx->newTreasury);
        if ($regulatoryCap !== null && $regulatoryCap <= 0.0) {
            return; // Capital-constrained: the regulator has the balance sheet frozen, not just the dividend.
        }

        $stock->setEarningAssets((string) ((float) $stock->getEarningAssets() + $deployable));
        $ctx->newTreasury -= $deployable;
        $ctx->organicCapex = $deployable;
        $ctx->loanOriginations += $deployable;

        if ($deployable > 1_000_000_000.0) {
            $amtB = number_format($deployable / 1_000_000_000, 2);
            $actionText = match ($ctx->businessModel) {
                'commercial_bank', 'credit_services', 'shadow_bank' => 'loan book expansion',
                'insurance', 'retail_insurance', 'reinsurance' => 'the investment portfolio backing the float',
                'brokerage', 'investment_bank' => 'trading desk and market-making capacity',
                'asset_manager', 'private_equity', 'hedge_fund' => 'fund seeding and AUM deployment',
                'clearing_house' => 'clearing collateral and exchange margin reserves',
                'distressed_debt' => 'distressed credit and turnaround equity acquisitions',
                default => 'earning assets'
            };
            $ctx->events[] = ['description' => "Deployed \${$amtB}B in {$actionText}.", 'shock' => 0.5];
        }
    }

    /**
     * Repays or refinances the principal that matured this quarter.
     *
     * Runs before emergency borrowing on purpose: a maturity the firm cannot refinance drains cash first,
     * and only then does the treasury reach for penalty-rate financing to plug the hole it left. That
     * ordering is what lets a solvent-but-illiquid firm fail the way real ones do.
     */
    private function processDebtMaturities(CapitalAllocationContext $ctx): void
    {
        if ($ctx->health === null) {
            return;
        }

        $roll = $this->debtEngine->rollMaturities(
            $ctx->stock,
            $ctx->health,
            $ctx->newTreasury,
            $ctx->strategy->calculateMinOperatingCash($ctx->operatingBase, $ctx->customerDeposits, $ctx->wholesaleDebt)
        );
        if ($roll->maturingPrincipal <= 0.0) {
            return;
        }

        $ctx->refinancingRefused = !$roll->refinanced;
        if ($roll->refinanced) {
            return;
        }

        $ctx->newTreasury -= $roll->principalRepaid;
        $ctx->principalRepaid = $roll->principalRepaid;
        $ctx->unfundedMaturity = $roll->unfundedShortfall;
        $ctx->wholesaleDebt = (float) $ctx->stock->getWholesaleDebt();

        if ($roll->principalRepaid > 500_000_000.0) {
            $amtB = number_format($roll->principalRepaid / 1_000_000_000, 2);
            $ctx->events[] = [
                'description' => "Shut out of the bond market and forced to repay \${$amtB}B of maturing notes in cash.",
                'shock' => -4.0
            ];
        }
    }

    private function processEmergencyBorrowing(CapitalAllocationContext $ctx): void
    {
        $stock = $ctx->stock;
        $minOperatingCash = $ctx->strategy->calculateMinOperatingCash($ctx->operatingBase, $ctx->customerDeposits, $ctx->wholesaleDebt);

        if ($ctx->newTreasury < $minOperatingCash) {
            $cashShortfall = $minOperatingCash - $ctx->newTreasury;

            // A firm the primary market just refused cannot turn around and issue emergency paper into the
            // same closed market, however willing its own coverage ratios look.
            if ($ctx->health->canIssueDebt && !$ctx->refinancingRefused) {
                $currentMarketRate = $ctx->health->rawMetrics->currentMarketRate ?? ($ctx->macroState->yield5yEma + (float) $stock->getCreditSpread());
                $costOfEmergencyDebt = $currentMarketRate + FinancialConstants::EMERGENCY_DEBT_SPREAD_PENALTY;

                $this->debtEngine->issueDebt($stock, $cashShortfall, $costOfEmergencyDebt);

                $ctx->wholesaleDebt = (float) $stock->getWholesaleDebt();
                $ctx->newTreasury = $minOperatingCash;
                $ctx->debtActionTaken = true;

                if ($cashShortfall > 10_000_000.0) {
                    $ctx->events[] = ['description' => "Forced to borrow \$" . number_format($cashShortfall / 1_000_000_000, 2) . "B at penalty rates due to cash shortfall.", 'shock' => -5.0];
                }
            } else {
                $ctx->failedEmergencyBorrow = true;
            }
        }
    }

    private function processEquityIssuance(CapitalAllocationContext $ctx): void
    {
        $stock = $ctx->stock;
        if ($ctx->currentPrice <= 0.0) return;

        if ($ctx->totalCashSpent > 0.0) return;

        $currentPE = $ctx->quarterlyEps > 0 ? ($ctx->currentPrice / ($ctx->quarterlyEps * 4)) : 9999.0;
        if ($ctx->actualAnnualEps > 0) {
            $currentPE = $ctx->currentPrice / $ctx->actualAnnualEps;
        }

        $trueReturn = $ctx->strategy->getTrueReturn($stock);
        $hurdleRate = $ctx->strategy->getHurdleRate($ctx->health);
        $economicSpread = $trueReturn - $hurdleRate;

        $fairValuePE = $this->mathUtility->calculateManagementFairValuePE(
            $hurdleRate,
            $trueReturn,
            $ctx->strategy->getSecularGrowthRate($stock),
            $ctx->macroState->outputGap,
            $ctx->health->leveredBeta,
            $ctx->macroState->inflation,
            $ctx->strategy->getMoatSpread(),
            \App\Data\Sectors::baselineIndustryPe($stock->getIndustry()),
            (float) ($stock->getAccrualsRatio() ?? 0.0)
        );

        $bookValuePerShare = max(0.01, (float) $stock->getTotalEquity() / max(1, $ctx->sharesOutstanding));
        $priceToBook = $ctx->currentPrice / $bookValuePerShare;

        $isBubble = $economicSpread > 0.0 && $currentPE > ($fairValuePE * 2.5) && $currentPE > 40.0 && $priceToBook > 3.0;
        $isDeathSpiral = $ctx->failedEmergencyBorrow;

        $executeIssuance = false;

        if ($isDeathSpiral) {
            $executeIssuance = $this->mathUtility->generateUniform() < 0.80;
        } elseif ($isBubble) {
            $bubbleSeverity = ($currentPE / max(1.0, $fairValuePE * 2.5)) - 1.0;
            $probBubble = min(0.90, 0.05 + ($bubbleSeverity * 0.20));
            $executeIssuance = $this->mathUtility->generateUniform() < $probBubble;
        }

        if ($executeIssuance) {
            $minOperatingCash = $ctx->strategy->calculateMinOperatingCash($ctx->operatingBase, $ctx->customerDeposits, $ctx->wholesaleDebt);

            $targetRaise = 0.0;
            $reason = "";
            $shock = 0.0;

            if ($isDeathSpiral) {
                $shortfall = max(0.0, $minOperatingCash - $ctx->newTreasury);
                $marketCap = max(1.0, $ctx->sharesOutstanding * $ctx->currentPrice);
                $maxEmergencyRaise = max(FinancialConstants::MIN_OPERATING_BASE_CASH, $marketCap * FinancialConstants::MAX_EMERGENCY_EQUITY_RAISE_RATIO);
                $targetRaise = min($shortfall * 1.5, $maxEmergencyRaise);
                $reason = "execute a highly dilutive emergency stock offering to stave off bankruptcy";
                $shock = -15.0;
            } elseif ($isBubble) {
                $marketCap = $ctx->sharesOutstanding * $ctx->currentPrice;
                $investedCapital = (float) $stock->getInvestedCapital();
                $maxRaise = abs($investedCapital) * 0.10;
                $targetRaise = min($marketCap * 0.05, $maxRaise);
                $reason = "exploit premium valuation with a secondary offering";
                $shock = -5.0;
            }

            if ($targetRaise > 10_000_000.0) {
                $offeringPrice = $ctx->currentPrice * 0.90;
                $sharesIssued = $targetRaise / max(0.01, $offeringPrice);

                $stock->setSharesOutstanding((string) ($ctx->sharesOutstanding + $sharesIssued));
                // The offering is placed at the discount above; the stock it put into investors' hands is
                // then sold down through the flow channel at the 10b-18 pace (the post-offering overhang).
                $stock->addCorporateFlowBacklog(-$sharesIssued);
                // The engine writes the context's share count back to the stock after allocation, so the
                // dilution has to reach the context too or the raise lands as cash with no shares behind it.
                $ctx->newShares = (float) $stock->getSharesOutstanding();
                $ctx->newTreasury += $targetRaise;
                $ctx->equityRaised += $targetRaise;

                $currentEquityStr = $this->formatBc($stock->getTotalEquity());
                $stock->setTotalEquity(\bcadd($currentEquityStr, $this->formatBc($targetRaise), 4));

                $amtB = number_format($targetRaise / 1_000_000_000, 2);
                $ctx->events[] = [
                    'description' => "Issued new shares to raise \${$amtB}B and {$reason}.",
                    'shock' => $shock
                ];

                $ctx->failedEmergencyBorrow = false;
            }
        }
    }

    /**
     * Draws on the committed revolving credit facility to close a negative cash balance.
     *
     * A revolver is contractually committed: the bank must fund a draw whatever the primary market is doing,
     * which is exactly why firms drew their facilities in March 2020 when commercial paper shut. So this runs
     * even for an issuer the bond market has refused, and it is the reason a cash balance cannot simply go
     * negative. The commitment is sized to the business, though: past it the bank is no longer bound, the
     * money prices at distress rates, and the firm is flagged into the death-spiral financing path.
     *
     * The facility also takes out a maturity the primary market refused to roll. That is the other thing a
     * revolver is for: a solvent issuer whose notes come due in a closed market draws the line and repays the
     * bondholders, which is why a refused refinancing is a funding problem and not, by itself, a default.
     * The maturity wall only repays principal down to the operating cash floor, so the balance is never
     * negative on that account and the overdraft test alone never saw it. An overdraft is cash already spent
     * and is funded first; the maturity takes whatever commitment is left, and only the part no committed
     * line will cover goes forward to the default test.
     */
    private function processRevolverDraw(CapitalAllocationContext $ctx): void
    {
        $overdraft = max(0.0, -$ctx->newTreasury);
        $need = $overdraft + max(0.0, $ctx->unfundedMaturity);
        if ($need <= 0.0) {
            return;
        }

        $stock = $ctx->stock;

        $commitment = max(
            FinancialConstants::MIN_OPERATING_BASE_CASH,
            $ctx->strategy->calculateMinOperatingCash($ctx->operatingBase, $ctx->customerDeposits, $ctx->wholesaleDebt)
                * self::REVOLVER_COMMITMENT_OPERATING_CASH_MULTIPLE
        );
        $drawn = min($need, $commitment);
        if ($drawn <= 0.0) {
            return;
        }

        $currentMarketRate = $ctx->health->rawMetrics->currentMarketRate
            ?? ($ctx->macroState->yield5yEma + (float) $stock->getCreditSpread());

        $this->debtEngine->issueDebt($stock, $drawn, $currentMarketRate + self::REVOLVER_DRAW_SPREAD_PENALTY);
        $ctx->wholesaleDebt = (float) $stock->getWholesaleDebt();
        $ctx->newTreasury += $drawn;
        $ctx->debtActionTaken = true;

        // The maturity is repaid out of the draw the moment it lands: the notes leave the ladder and the
        // revolver balance takes their place, which is a refinancing onto the committed line, not new
        // leverage. The cash passes straight through, so the treasury ends where it started.
        $maturityFunded = min(max(0.0, $ctx->unfundedMaturity), max(0.0, $drawn - $overdraft));
        if ($maturityFunded > 0.0) {
            $ctx->newTreasury -= $maturityFunded;
            $ctx->wholesaleDebt = max(0.0, $ctx->wholesaleDebt - $maturityFunded);
            $stock->setWholesaleDebt((string) $ctx->wholesaleDebt);
            $ctx->principalRepaid += $maturityFunded;
            $ctx->unfundedMaturity -= $maturityFunded;
        }

        // Past the commitment the bank is no longer contractually bound and the money costs what distress
        // costs. An overdraft is still funded - the cash was already spent, and booking it anywhere but the
        // liability side would balance the sheet by inventing money - but the firm is now visibly out of
        // liquidity, so it is flagged into the death-spiral path the equity issuance step already runs on.
        // A maturity is different: nothing has been spent yet, so past the commitment it stays unfunded and
        // the default test decides.
        $overCommitment = max(0.0, $overdraft - $drawn);
        if ($overCommitment > 0.0) {
            $this->debtEngine->issueDebt(
                $stock,
                $overCommitment,
                $currentMarketRate + FinancialConstants::EMERGENCY_DEBT_SPREAD_PENALTY
            );
            $ctx->wholesaleDebt = (float) $stock->getWholesaleDebt();
            $ctx->newTreasury += $overCommitment;
            $ctx->failedEmergencyBorrow = true;
        }

        if ($drawn > 500_000_000.0) {
            $amtB = number_format($drawn / 1_000_000_000, 2);
            $ctx->events[] = [
                'description' => "Drew \${$amtB}B on its committed revolving credit facility to cover a cash shortfall.",
                'shock' => -3.0
            ];
        }
    }

    /**
     * Records an event of default when principal came due that the firm could neither refinance, repay from
     * cash, nor cover with an emergency raise. This is a payment default, which is a separate failure mode
     * from balance-sheet insolvency: a firm can be worth more than it owes on paper and still fail because
     * the money was not there on the day. MarketOperator liquidates on either.
     */
    private function processPaymentDefault(CapitalAllocationContext $ctx): void
    {
        if ($ctx->unfundedMaturity <= 0.0) {
            return;
        }

        // The emergency equity raise, if it landed, may have covered the gap after all.
        $stillUnfunded = $ctx->newTreasury < $ctx->unfundedMaturity;
        if (!$stillUnfunded) {
            $ctx->newTreasury -= $ctx->unfundedMaturity;
            $ctx->wholesaleDebt = max(0.0, $ctx->wholesaleDebt - $ctx->unfundedMaturity);
            $ctx->stock->setWholesaleDebt((string) $ctx->wholesaleDebt);
            $ctx->principalRepaid += $ctx->unfundedMaturity;
            $ctx->unfundedMaturity = 0.0;

            return;
        }

        $ctx->stock->setPaymentDefault(true);
        $amtB = number_format($ctx->unfundedMaturity / 1_000_000_000, 2);
        $ctx->events[] = [
            'description' => "Failed to repay \${$amtB}B of maturing debt, triggering an event of default.",
            'shock' => -25.0
        ];
    }

    private function processArbitragePaydown(CapitalAllocationContext $ctx): void
    {
        $stock = $ctx->stock;
        $targetOperatingCash = $ctx->strategy->calculateTargetOperatingCash($ctx->operatingBase, $ctx->customerDeposits, $ctx->wholesaleDebt);

        if (!$ctx->debtActionTaken && $ctx->health->wantsToPaydownDebt && $ctx->wholesaleDebt > 0 && $ctx->newTreasury > $targetOperatingCash) {
            $distressThreshold = 1.0 + $ctx->strategy->getRequiredIcrBuffer();
            $isLiquidityCrisis = $ctx->health->interestCoverage < 1.0;
            $isDistressed = $ctx->health->interestCoverage < $distressThreshold;
            $paydownProbability = $isLiquidityCrisis ? 1.0 : ($isDistressed ? 0.50 : 0.15);

            if ((mt_rand() / mt_getrandmax()) < $paydownProbability) {
                $sweepPercentage = $isLiquidityCrisis ? 0.50 : 0.10;
                $arbitragePaydown = ($ctx->newTreasury - $targetOperatingCash) * $sweepPercentage;
                $maxRetireableDebt = $ctx->wholesaleDebt * ($isLiquidityCrisis ? 0.15 : 0.05);
                $actualPaydown = min($arbitragePaydown, $ctx->wholesaleDebt, $maxRetireableDebt);

                if ($actualPaydown > 0) {
                    $ctx->wholesaleDebt -= $actualPaydown;
                    $stock->setWholesaleDebt((string) $ctx->wholesaleDebt);
                    $ctx->newTreasury -= $actualPaydown;
                    $ctx->debtActionTaken = true;

                    if ($actualPaydown > 500_000_000.0) {
                        $amtB = number_format($actualPaydown / 1_000_000_000, 2);
                        $reason = $isLiquidityCrisis ? "survive a liquidity crisis" : "reduce debt burden and escape negative carry";
                        $ctx->events[] = [
                            'description' => "Paid down \${$amtB}B of debt to {$reason}.",
                            'shock' => 1.0
                        ];
                    }
                }
            }
        }
    }

    private function processDeleveragingSweep(CapitalAllocationContext $ctx, float $newEquity): void
    {
        $stock = $ctx->stock;
        $totalDebt = $ctx->wholesaleDebt + $ctx->customerDeposits;

        // The hoarding test below asks whether this manager is sitting on too much cash, which is the very
        // question the style answers, so it has to be measured against the balance the manager runs to.
        $targetOperatingCash = $ctx->stock->getManagementProfile()->appliedTargetCash(
            $ctx->strategy->calculateTargetOperatingCash($ctx->operatingBase, $ctx->customerDeposits, $ctx->wholesaleDebt)
        );

        if (!$ctx->debtActionTaken && $ctx->wholesaleDebt > 0.0 && $ctx->newTreasury > $targetOperatingCash) {
            $excessCash = $ctx->newTreasury - $targetOperatingCash;
            $macroDebtTolerance = $ctx->health->debtTolerance;

            $evalDebt = $ctx->strategy->getDeleveragingEvaluationDebt($totalDebt, $ctx->wholesaleDebt);
            $evalLimit = $ctx->strategy->getDeleveragingEvaluationLimit($macroDebtTolerance);

            $currentDebtRatio = $evalDebt / max(1.0, $newEquity);

            $hoardStatus = $ctx->strategy->evaluateHoardingStatus($ctx->newTreasury, $targetOperatingCash, $ctx->stock->getManagementProfile()->appliedHoardingBase($ctx->operatingBase), $totalDebt);

            $baselineSpread = (float) $stock->getCreditSpread();
            $dynamicSpread = $ctx->health->rawMetrics->dynamicSpread ?? $baselineSpread;
            $isJunkBondStatus = $dynamicSpread > ($baselineSpread + 0.0011);

            $shouldSweep = $currentDebtRatio > $evalLimit;

            // Financial institutions (Banks, Brokerages, Insurers) have structural, regulatory-driven balance sheets 
            // where "cash hoarding" is just normal float/deposits/trading buffers, and junk status on marginal debt 
            // shouldn't force them to liquidate their structural core funding.
            if ($ctx->strategy->shouldForceDeleveragingOnJunkOrHoarding()) {
                if ($isJunkBondStatus || $hoardStatus['is_hoarder']) {
                    $shouldSweep = true;
                }
            }

            if ($shouldSweep) {
                $targetRatio = $isJunkBondStatus ? max(0.10, $evalLimit * 0.75) : max(0.10, $evalLimit - 0.05);

                $targetTotalDebt = $newEquity * $targetRatio;
                $debtToPayOff = min($excessCash, max(0.0, $evalDebt - $targetTotalDebt));
                $debtToPayOff = min($debtToPayOff, $ctx->wholesaleDebt);

                if ($debtToPayOff > 0) {
                    $ctx->wholesaleDebt -= $debtToPayOff;
                    $stock->setWholesaleDebt((string) $ctx->wholesaleDebt);
                    $ctx->newTreasury -= $debtToPayOff;

                    if ($debtToPayOff > 500_000_000.0) {
                        $amtB = number_format($debtToPayOff / 1_000_000_000, 2);
                        $ctx->events[] = [
                            'description' => "Swept \${$amtB}B cash to aggressively deleverage.",
                            'shock' => 2.0
                        ];
                    }
                }
            }
        }
    }

    private function processPassiveLiabilityGrowth(CapitalAllocationContext $ctx): void
    {
        // Pack state for interface call
        $state = [
            'treasury' => $ctx->newTreasury,
            'wholesaleDebt' => $ctx->wholesaleDebt,
            'customerDeposits' => $ctx->customerDeposits,
            'debtIssued' => $ctx->debtIssued,
            'organicCapex' => $ctx->organicCapex,
            'debtActionTaken' => $ctx->debtActionTaken,
            'bank_apy' => $ctx->bankApy,
            'failed_emergency_borrow' => $ctx->failedEmergencyBorrow,
            'events' => $ctx->events
        ];

        $ctx->strategy->processPassiveLiabilityGrowth($ctx->stock, $ctx->macroState, $state, $this->mathUtility);

        // Unpack state
        $ctx->newTreasury = $state['treasury'];
        $ctx->wholesaleDebt = $state['wholesaleDebt'];
        $ctx->customerDeposits = $state['customerDeposits'];
        $ctx->debtIssued = $state['debtIssued'];
        $ctx->organicCapex = $state['organicCapex'];
        $ctx->debtActionTaken = $state['debtActionTaken'];
        $ctx->bankApy = $state['bank_apy'];
        $ctx->failedEmergencyBorrow = $state['failed_emergency_borrow'];
        $ctx->events = $state['events'];
    }

    private function formatBc(int|float|string|null $val, int $scale = 4): string
    {
        if ($val === null || $val === '') {
            return '0.' . str_repeat('0', $scale);
        }
        if (is_string($val)) {
            if (stripos($val, 'e') !== false && is_numeric($val)) {
                $val = (float) $val;
            } else {
                return $val;
            }
        }
        if (!is_finite((float) $val)) {
            return '0.' . str_repeat('0', $scale);
        }
        return sprintf('%.' . $scale . 'F', (float) $val);
    }
}
