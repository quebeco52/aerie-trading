<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\DTO\SovereignCurveDTO;
use App\Entity\Bond;
use App\Entity\Stock;
use App\Service\Corporate\DebtEngine;
use App\Service\Math\FinancialConstants;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Brings companies to the public bond market and keeps their listed ladder in step with what they owe.
 *
 * THE MODELLING DECISION THIS CLASS RESTS ON. A firm's debt in this simulation is a SCALAR —
 * Stock::$wholesaleDebt with a blended rate — and that scalar is read by the earnings engine, the Altman
 * Z-score, the leverage covenant and the cost of capital. A listed bond therefore cannot BE the debt without
 * rewriting all of them. It is a TRANCHE of it: the balance sheet still carries the whole obligation, and the
 * issues here are the publicly traded share of exactly that obligation.
 *
 * Three consequences follow, and all three are the point rather than a compromise:
 *
 *   - Issuing a bond creates NO new debt. The desk reconciles what is listed against a share of what the firm
 *     already owes; it never adds to the scalar. If it did, every deal would double-count the borrowing and
 *     lever the firm up for free.
 *   - The coupon paid to holders is not a new expense. DebtEngine already charges the firm interest on the
 *     whole scalar, and the public tranche is part of the scalar, so the cash reaching bondholders is a
 *     portion of an expense the income statement has already recognised.
 *   - A maturing issue is redeemed to its holders and the scalar does not move. That is not an inconsistency:
 *     it is precisely what DebtEngine::rollMaturities() models when a firm has primary market access — the
 *     principal survives and only its coupon reprices. The desk then relists to refill the tranche, which is
 *     the same firm rolling the same debt in public.
 *
 * Who gets to issue is the market's rule, not a modelling convenience: a firm needs enough debt for a tranche
 * to be worth one deal, and it needs the primary market to be open to it at all — the same rating floor
 * DebtEngine applies when it asks whether a maturity can be rolled.
 */
final class CorporateBondDesk
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BondPricingEngine $pricingEngine,
    ) {}

    /** Ticks between reconciliations, given a tick rate. */
    public static function issuanceIntervalTicks(int $ticksPerYear): int
    {
        return max(1, (int) ($ticksPerYear / FinancialConstants::CORPORATE_ISSUANCE_PER_YEAR));
    }

    /**
     * Brings whatever deals the market is short of, across every firm that can issue.
     *
     * @param array<int, Stock> $stocks
     * @return array<int, Bond> Newly listed issues.
     */
    public function reconcile(array $stocks, SovereignCurveDTO $curve, float $currentTime): array
    {
        $outstanding = $this->outstandingByIssuer();
        $issued = [];

        foreach ($stocks as $stock) {
            $bond = $this->bringDeal($stock, $curve, $currentTime, $outstanding[$stock->getId()] ?? ['face' => 0.0, 'count' => 0]);

            if ($bond !== null) {
                $issued[] = $bond;
            }
        }

        return $issued;
    }

    /**
     * The face and issue count already listed for each issuer.
     *
     * One aggregate for the whole market rather than a query per firm: this runs over every name on the
     * roster, and the answer is two numbers per issuer.
     *
     * @return array<int, array{face: float, count: int}>
     */
    private function outstandingByIssuer(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT issuer_id, SUM(outstanding_face) AS face, COUNT(*) AS issues
             FROM bonds
             WHERE issuer_id IS NOT NULL AND status = :active
             GROUP BY issuer_id',
            ['active' => Bond::STATUS_ACTIVE]
        );

        $byIssuer = [];

        foreach ($rows as $row) {
            $byIssuer[(int) $row['issuer_id']] = [
                'face' => (float) $row['face'],
                'count' => (int) $row['issues'],
            ];
        }

        return $byIssuer;
    }

    /**
     * Whether the public market is open to this issuer at all.
     *
     * The rating floor is DebtEngine's own: a firm the primary market will not refinance is not a firm that
     * can bring a new deal, and having two different answers to that question would let a company be shut
     * out of rolling its maturities while still selling fresh paper.
     */
    public function canIssue(Stock $stock): bool
    {
        if ($stock->isBankrupt()) {
            return false;
        }

        if ((float) $stock->getWholesaleDebt() < FinancialConstants::CORPORATE_MIN_PUBLIC_DEBT) {
            return false;
        }

        $ranks = CreditRatingAgency::RATING_RANKS;

        return ($ranks[$stock->getCreditRating()] ?? 0)
            >= ($ranks[DebtEngine::REFINANCING_RATING_FLOOR] ?? 1);
    }

    /** The face a firm should have listed: its public share of what it already owes. */
    public function targetPublicFace(Stock $stock): float
    {
        return max(0.0, (float) $stock->getWholesaleDebt()) * FinancialConstants::CORPORATE_PUBLIC_DEBT_SHARE;
    }

    /**
     * Brings one deal for a firm, or nothing if it has no room for one.
     *
     * @param array{face: float, count: int} $outstanding What the firm already has listed.
     */
    private function bringDeal(Stock $stock, SovereignCurveDTO $curve, float $currentTime, array $outstanding): ?Bond
    {
        if (!$this->canIssue($stock)) {
            return null;
        }

        if ($outstanding['count'] >= FinancialConstants::CORPORATE_LADDER_ISSUES) {
            return null;
        }

        $gap = $this->targetPublicFace($stock) - $outstanding['face'];

        if ($gap < FinancialConstants::CORPORATE_MIN_ISSUE_FACE) {
            return null;
        }

        // The tranche is brought in equal pieces rather than in one deal, so a firm ends up with a ladder
        // spread across the curve instead of a single cliff it has to refinance in one day.
        $size = min($gap, $this->targetPublicFace($stock) / FinancialConstants::CORPORATE_LADDER_ISSUES);

        if ($size < FinancialConstants::CORPORATE_MIN_ISSUE_FACE) {
            return null;
        }

        return $this->issue($stock, $curve, $currentTime, $this->nextTenor($outstanding['count']), $size);
    }

    /**
     * The tenor a firm's next deal is brought at.
     *
     * Cycled through the listed maturities by how many issues the firm already has, so the ladder fills out
     * across the curve rather than stacking every deal on the same point of it.
     */
    private function nextTenor(int $existingIssues): float
    {
        $tenors = FinancialConstants::CORPORATE_ISSUE_TENORS;

        return (float) $tenors[$existingIssues % count($tenors)];
    }

    /**
     * Sells one issue for a firm, struck at par against its own credit.
     *
     * The coupon is solved on the curve PLUS the issuer's spread, so a weaker firm pays more for the same
     * money and the bond opens at par rather than immediately at a discount to what it was sold for.
     */
    public function issue(Stock $stock, SovereignCurveDTO $curve, float $currentTime, float $tenor, float $issueFace): Bond
    {
        $face = FinancialConstants::BOND_FACE_VALUE;
        $spread = (float) $stock->getDynamicCreditSpread();
        $couponRate = $this->pricingEngine->parCouponRate($curve, $tenor, $face, $spread);

        $bond = new Bond();
        $bond->setTicker($this->nextTicker($stock, $tenor))
            ->setName($this->issueName($stock, $tenor, $couponRate, $currentTime + $tenor))
            ->setIssuer($stock)
            ->setSeniority(Bond::SENIORITY_SENIOR_UNSECURED)
            ->setTenorYears((string) $tenor)
            ->setCouponRate((string) $couponRate)
            ->setFaceValue((string) $face)
            ->setIssuedAtTime($currentTime)
            ->setMaturesAtTime($currentTime + $tenor)
            ->setLastCouponTime($currentTime)
            ->setIsOnTheRun(false)
            ->setStatus(Bond::STATUS_ACTIVE)
            ->setCreditSpread((string) $spread)
            ->setOutstandingFace((string) $issueFace);

        $valuation = $this->pricingEngine->value($bond, $curve, $currentTime, $spread);
        $bond->setPrice((string) $valuation->dirtyPrice)
            ->setCleanPrice((string) $valuation->cleanPrice)
            ->setAccruedInterest((string) $valuation->accruedInterest)
            ->setYieldToMaturity((string) $valuation->yieldToMaturity)
            ->setModifiedDuration((string) $valuation->modifiedDuration)
            ->setConvexity((string) $valuation->convexity)
            ->setUpdatedAt(new \DateTime());

        $this->em->persist($bond);

        return $bond;
    }

    /**
     * The symbol one issue trades under: the issuer, its tenor, and how many it has brought before.
     *
     * Counted over every issue the firm has EVER brought, matured ones included, so a symbol is never reused
     * for a different bond — a redeemed issue still has coupon rows and holdings pointing at its history.
     */
    private function nextTicker(Stock $stock, float $tenor): string
    {
        $existing = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM bonds WHERE issuer_id = :issuer',
            ['issuer' => $stock->getId()]
        );

        return sprintf('%s-%02dY%02d', $stock->getTicker(), (int) $tenor, $existing + 1);
    }

    /** Human-readable issue name, in the market's own convention: issuer, coupon, then maturity. */
    private function issueName(Stock $stock, float $tenor, float $couponRate, float $maturityTime): string
    {
        return sprintf(
            '%s %.3f%% Senior Notes %dY due Y%.2f',
            $stock->getTicker(),
            $couponRate * 100.0,
            (int) $tenor,
            $maturityTime
        );
    }
}
