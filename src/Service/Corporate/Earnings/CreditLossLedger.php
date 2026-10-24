<?php

declare(strict_types=1);

namespace App\Service\Corporate\Earnings;

use App\DTO\EarningsSimulationContext;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Math\FinancialConstants;

/**
 * A lender's earning-asset book and its allowance for credit losses (ASC 326): opens the ledger, rolls charge-offs
 * and the provision through it each quarter, and gives analysts the provision they can forecast on the disclosed
 * book.
 */
final class CreditLossLedger
{
    // --- Credit Loss Allowance (ASC 326) ---
    /**
     * Time constant of a lender's loss rate reverting to its through-the-cycle level: US bank charge-offs have an
     * annual autocorrelation of 0.79, ~4.2 years (FRED CORALACBN, 1985-2019).
     */
    public const CREDIT_LOSS_REVERSION_YEARS = 4.2;

    // --- Earning Asset Ledger (Financials) ---
    /**
     * Share of the gap between the credit-loss allowance and its lifetime target closed each quarter, in either
     * direction, so a build or release is a path and not a cliff.
     */
    public const CREDIT_ALLOWANCE_CONVERGENCE_RATIO = 0.25;

    public function __construct(
        private readonly CorporateMetrics $corporateMetrics,
    ) {}

    /**
     * Opens the earning-asset ledger for a balance-sheet business on its first report. The loan book is a balance:
     * originations grow it, charge-offs and fire sales shrink it, and an allowance for the losses already expected
     * sits against it.
     */
    public function seedEarningAssetLedgerIfNeeded(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        if ($stock->hasEarningAssetLedger() || !$ctx->strategy->isFinancial()) {
            return;
        }

        $this->corporateMetrics->seedEarningAssetLedger($stock, $this->resolveLifetimeCreditLossRate($ctx));
    }

    /**
     * The quarterly provision analysts carry for a lender: the allowance roll-forward run on the disclosed book with
     * the public quarter's charge-offs, so both the replenishment and the convergence toward a lifetime target that
     * moves with published spreads and recession odds are in the estimate. Only the losses the firm's own book takes
     * beyond them surprise. Nothing for a firm that keeps no loan book.
     */
    public function resolveExpectedCreditLossProvision(EarningsSimulationContext $ctx): float
    {
        if (!$ctx->stock->hasEarningAssetLedger()) {
            return 0.0;
        }

        return $this->advanceCreditLossAllowance(
            $ctx,
            (float) $ctx->stock->getEarningAssets(),
            (float) $ctx->stock->getCreditLossAllowance(),
            $ctx->publicActuals->creditLossProvision ?? 0.0,
            $ctx->publicActuals->netChargeOffs ?? 0.0,
        )['levelCharge'];
    }

    /**
     * Lifetime expected loss rate on gross earning assets over the CECL horizon. Two departures from the
     * through-the-cycle rate are added, not compounded: the forward outlook (spreads, recession risk) moves the
     * estimate by (multiplier - 1) x TTC, and the losses the book is taking now revert to TTC over the horizon
     * (ASC 326-20-30-9), adding (current - TTC) x (1 - e^(-H/tau)) / (H/tau). The outlook moves the TARGET the
     * allowance converges to, so a deteriorating forecast is booked as a build once and released when it clears,
     * rather than charged again every quarter it persists. Without a current rate (the opening seed) the book is
     * taken to lose at TTC.
     */
    private function resolveLifetimeCreditLossRate(EarningsSimulationContext $ctx, ?float $currentLossRate = null): float
    {
        $throughTheCycle = max(0.0, $ctx->strategy->getThroughTheCycleCreditLossRate($ctx->stock));
        $horizon = max(0.0, $ctx->strategy->getCreditLossHorizonYears());
        $reversionShare = $horizon > 0.0
            ? (1.0 - exp(-$horizon / self::CREDIT_LOSS_REVERSION_YEARS)) / ($horizon / self::CREDIT_LOSS_REVERSION_YEARS)
            : 1.0;
        $outlook = $throughTheCycle * max(0.0, $ctx->strategy->getForwardCreditLossMultiplier($ctx->stock, $ctx->macroState));
        $currentConditions = (($currentLossRate ?? $throughTheCycle) - $throughTheCycle) * $reversionShare;

        return max(0.0, $outlook + $currentConditions) * $horizon;
    }

    /**
     * Rolls the allowance for credit losses on the earning-asset book (ASC 326).
     *
     * Charge-offs are the loans that actually went bad: they leave the gross book and consume the allowance,
     * which is what the allowance was built for, so they hit no one's earnings on the way out. The provision
     * then puts back what they consumed and walks whatever gap is left a quarter-step toward the lifetime
     * expected loss on the book that remains. Both halves reach EBIT here; the excess the sector physics
     * already charged to the margin is carried alongside them and is not charged twice.
     *
     * The replenishment term is what makes the level correction a controller with no steady-state error. A
     * bare proportional step cannot close a gap that charge-offs reopen every quarter, so the allowance
     * settles permanently below target and the provision permanently below realized losses. Feeding the
     * charge-offs forward fixes the point exactly on target, and at steady state the provision equals the
     * losses — which is the whole content of "a bank earns its credit spread and pays it back out".
     *
     * Every part is non-cash. The full provision is added back to operating cash flow below, which is what
     * keeps cash out of the picture: the book falls by the provision and equity falls by the same amount.
     */
    public function rollForwardCreditLossAllowance(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        if (!$stock->hasEarningAssetLedger()) {
            $ctx->creditLossProvision = 0.0;
            $ctx->netChargeOffs = 0.0;

            return;
        }

        $explicitProvision = $ctx->creditLossProvision;
        [
            'levelCharge' => $levelCharge,
            'grossBook' => $grossBook,
            'allowance' => $allowance,
            'chargeOffs' => $chargeOffs,
        ] = $this->advanceCreditLossAllowance($ctx, (float) $stock->getEarningAssets(), (float) $stock->getCreditLossAllowance(), $explicitProvision, $ctx->netChargeOffs);

        $stock->setEarningAssets((string) max(0.0, $grossBook));
        $stock->setCreditLossAllowance((string) $allowance);

        $ctx->ebitda -= $levelCharge;
        $ctx->ebit -= $levelCharge;
        $ctx->impairmentCharges += $levelCharge;
        $ctx->creditLossProvision = $explicitProvision + $levelCharge;
        $ctx->netChargeOffs = $chargeOffs;
    }

    /**
     * One quarter of a lender's allowance (ASC 326): charge-offs leave the book against the reserve, the reserve is
     * put back and walks a quarter-step toward its lifetime target, and losses past it are charged at once.
     *
     * @return array{levelCharge: float, grossBook: float, allowance: float, chargeOffs: float}
     */
    private function advanceCreditLossAllowance(EarningsSimulationContext $ctx, float $grossBook, float $allowance, float $explicitProvision, float $reportedChargeOffs): array
    {
        // A model whose credit physics is expressed only as a margin shock reports no dollar charge-offs;
        // its realized losses then run at the through-the-cycle rate, or the allowance would be built every
        // quarter by a charge nothing ever consumed and released back as income that was never earned.
        $throughTheCycleCharge = max(0.0, $ctx->strategy->getThroughTheCycleCreditLossRate($ctx->stock)) / 4.0 * $grossBook;
        $chargeOffs = min($grossBook, $reportedChargeOffs > 0.0 ? $reportedChargeOffs : $throughTheCycleCharge);
        $currentLossRate = $grossBook > 0.0 ? $chargeOffs * FinancialConstants::QUARTERS_PER_YEAR / $grossBook : null;

        // Loans that went bad leave the book and consume the reserve held against them. No earnings effect:
        // the loss was recognised when the reserve was built.
        $allowance += $explicitProvision - $chargeOffs;
        $grossBook -= $chargeOffs;

        // Put back what the charge-offs consumed, then walk a quarter-step of whatever gap to the lifetime
        // target is left, in either direction — so a recovery releases reserves gradually and a depleted
        // allowance is rebuilt rather than left empty.
        $replenishment = $chargeOffs;
        $allowance += $replenishment;

        $target = $grossBook * $this->resolveLifetimeCreditLossRate($ctx, $currentLossRate);
        $convergence = ($target - max(0.0, $allowance)) * self::CREDIT_ALLOWANCE_CONVERGENCE_RATIO;
        $allowance += $convergence;

        $levelCharge = $replenishment + $convergence;

        if ($allowance < 0.0) {
            // Losses ran past the reserve: the uncovered part is charged now, not carried as a negative asset.
            $levelCharge -= $allowance;
            $allowance = 0.0;
        }

        return ['levelCharge' => $levelCharge, 'grossBook' => $grossBook, 'allowance' => $allowance, 'chargeOffs' => $chargeOffs];
    }
}
