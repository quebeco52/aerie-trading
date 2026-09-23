<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\DTO\GoingConcernDTO;
use App\DTO\MacroStateDTO;
use App\DTO\ReorganizationPlanDTO;
use App\Entity\Stock;

/**
 * Chapter 11: a viable business that cannot pay its debts is reorganized rather than broken up.
 *
 * Modelled as a prepackaged plan, negotiated before the filing and confirmed within the quarter. The firm keeps
 * operating throughout; what changes is who owns it and what it owes. Debt the reorganized business can
 * carry is reinstated as new notes, the rest is exchanged for its equity, and that equity is divided under
 * the absolute priority rule (11 U.S.C. §1129(b)): the creditors are made whole before the old shareholders
 * keep anything.
 */
class ReorganizationEngine
{
    // --- Emergence ---
    /** Lowest price the reorganized equity opens at: one cent, the floor the price engine itself holds. */
    private const MIN_OPENING_PRICE = 0.01;
    /** Old holders below this share of the reorganized equity trigger fresh-start accounting (ASC 852-10-45-19: under 50% of the voting shares). */
    private const FRESH_START_OWNERSHIP_THRESHOLD = 0.50;

    /**
     * The plan a court would confirm for this firm.
     *
     * Feasibility (§1129(a)(11)) sizes the exit debt: the most the reorganized firm can owe and still pass its
     * own lender test on the year it has just earned, which is coverage at the sector's minimum plus the
     * buffer new borrowing needs, and net debt within the sector's leverage covenant. No plan reinstates more
     * debt than the business is worth, nor more than was owed.
     */
    public function planReorganization(Stock $stock, MacroStateDTO $macroState, GoingConcernDTO $going): ReorganizationPlanDTO
    {
        $industry = $stock->getIndustry() ?: 'General';
        $metrics = \App\Data\Sectors::INDUSTRY_METRICS[$industry] ?? \App\Data\Sectors::INDUSTRY_METRICS['General'];
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($metrics['business_model'] ?? 'none');

        // The exit notes are fresh paper at the issuer's own spread; the Merton and recession legs reprice
        // on the reorganized balance sheet from the next tick.
        $exitRate = max(0.0, $macroState->yield5yEma + (float) $stock->getCreditSpread());
        $requiredCoverage = $strategy->getMinIcr() + $strategy->getRequiredIcrBuffer();
        $coverageCapacity = ($going->trailingEbit > 0.0 && $exitRate > 0.0 && $requiredCoverage > 0.0)
            ? $going->trailingEbit / ($exitRate * $requiredCoverage)
            : 0.0;

        $ebitdaLimit = (float) ($metrics['ebitda_limit'] ?? DebtEngine::DEFAULT_EBITDA_COVENANT_LIMIT);
        $covenantCapacity = $ebitdaLimit >= DebtEngine::EBITDA_COVENANT_EXEMPT_LIMIT
            ? $going->claims
            : max(0.0, ($ebitdaLimit * $going->trailingEbitda) + $going->cash);

        $exitDebt = max(0.0, min($going->claims, $coverageCapacity, $covenantCapacity, $going->assetValue));
        $reorganizedEquityValue = max(0.0, $going->assetValue - $exitDebt);

        // Whatever the assets are worth beyond every claim is the old shareholders'; the creditors hold the
        // rest of the reorganized equity for the claims they gave up.
        $residual = $going->assetValue - $going->claims;
        $oldEquityShare = $reorganizedEquityValue > 0.0
            ? max(0.0, min(1.0, $residual / $reorganizedEquityValue))
            : 0.0;

        return new ReorganizationPlanDTO(
            claims: $going->claims,
            exitDebt: $exitDebt,
            convertedClaims: max(0.0, $going->claims - $exitDebt),
            oldEquityShare: $oldEquityShare,
            reorganizedEquityValue: $reorganizedEquityValue,
            exitRate: $exitRate,
        );
    }

    /**
     * Puts a confirmed plan into effect on the firm's own books.
     *
     * The exchange is recorded at carrying value, so the balance sheet balances by construction: liabilities
     * fall by the converted claims and equity rises by the same amount. The revolver is the senior claim and
     * is reinstated first; the term notes take the exchange. When the old holders end up with under half of
     * the new equity, fresh-start accounting eliminates the accumulated deficit. Cancelling the old shares and
     * settling what referenced them is left to the caller, which owns the positions and the listed debt.
     *
     * @return float The price the reorganized equity opens at.
     */
    public function applyPlan(Stock $stock, ReorganizationPlanDTO $plan): float
    {
        $revolverDrawn = max(0.0, (float) $stock->getRevolverDrawn());
        $reinstatedRevolver = min($revolverDrawn, $plan->exitDebt);
        $stock->setRevolverDrawn((string) $reinstatedRevolver);
        $stock->setWholesaleDebt((string) max(0.0, $plan->exitDebt - $reinstatedRevolver));
        if ($plan->convertedClaims > 0.0) {
            $stock->setHistoricalFixedRate((string) $plan->exitRate);
        }

        $stock->setTotalEquity((string) ((float) $stock->getTotalEquity() + $plan->convertedClaims));
        if ($plan->oldEquityShare < self::FRESH_START_OWNERSHIP_THRESHOLD) {
            $stock->setRetainedEarnings('0.0000');
        }

        // Confirmation cures every default the plan deals with.
        $stock->setPaymentDefault(false);
        $stock->setQuartersInDefault(0);

        $shares = max(1.0, (float) $stock->getSharesOutstanding());
        if ($plan->oldEquityShare >= 1.0) {
            // A reinstatement: the creditors are paid in full on their own terms, and the shares are untouched.
            return (float) $stock->getPrice();
        }

        if ($plan->oldEquityShare > 0.0) {
            // The old holders keep their shares and the creditors are issued the rest; they hold paper they
            // did not choose and sell it down through the flow channel, as the buyers of an offering do.
            $issued = $shares * ((1.0 - $plan->oldEquityShare) / $plan->oldEquityShare);
            $shares += $issued;
            $stock->setSharesOutstanding((string) $shares);
            $stock->addCorporateFlowBacklog(-$issued);
        } else {
            // The old shares are cancelled and the creditors receive the same number of new ones. What the old
            // company had queued in the market, and every short against its shares, went with them.
            $stock->setCorporateFlowBacklog(0.0);
            $stock->setShortInterestShares('0.00');
        }

        $openingPrice = max(self::MIN_OPENING_PRICE, $plan->reorganizedEquityValue / $shares);
        $stock->setPrice((string) $openingPrice);

        return $openingPrice;
    }
}
