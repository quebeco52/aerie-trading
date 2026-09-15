<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\DTO\MacroStateDTO;
use App\DTO\SovereignCurveDTO;
use App\Entity\Bond;
use App\Service\Math\FinancialConstants;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Per-tick maintenance of the bond desk: mark every outstanding issue against the live curve, pay coupons
 * that have fallen due, and redeem what has matured.
 *
 * The equity counterpart is StockTracker, and the shape is deliberately the same — a per-asset loop that
 * returns update rows for the pub/sub payload plus history rows for the bulk insert — so the ticker treats
 * both desks alike.
 *
 * Coupon dates are derived from the issue date and checked against elapsed simulation time rather than
 * scheduled on a wall clock. A tick can span more than one coupon period at a high tick rate or after a
 * restart, so the check is a loop, not an equality: a missed coupon is a permanent cash shortfall to every
 * holder and there is nothing later to detect it.
 */
class BondTracker
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BondPricingEngine $pricingEngine,
        private readonly BondLedgerService $ledger,
    ) {}

    /**
     * Marks, pays and redeems the whole desk for one tick.
     *
     * @param array<int, Bond> $bonds         Outstanding issues.
     * @param MacroStateDTO    $macroState    Live macro state carrying the fitted curve.
     * @param bool             $recordHistory Whether this tick writes a history point.
     * @return array{updates: array<int, array<string, mixed>>, history: array<int, array<string, mixed>>, matured: array<int, Bond>, curve: array<int, array{tenor: float, yield: float}>}
     * @param array<int, float> $issuerSpreads Live credit spread per issuer id, so marking the corporate
     *                                         ladder does not lazy-load a company for every bond on it.
     * @param bool              $markCorporate Whether corporate issues are revalued this pass.
     */
    public function updateBonds(
        array $bonds,
        MacroStateDTO $macroState,
        bool $recordHistory = false,
        array $issuerSpreads = [],
        bool $markCorporate = true
    ): array {
        $curve = $macroState->sovereignCurve();
        $currentTime = $macroState->totalTime;
        $now = new \DateTime();

        $updates = [];
        $history = [];
        $matured = [];

        foreach ($bonds as $bond) {
            if ($bond->getStatus() !== Bond::STATUS_ACTIVE) {
                continue;
            }

            $this->payDueCoupons($bond, $currentTime, $now);

            if ($bond->isMatured($currentTime)) {
                $this->ledger->processRedemption($bond, $currentTime, $now);
                $bond->setStatus(Bond::STATUS_MATURED)
                    ->setIsOnTheRun(false)
                    ->setPrice($bond->getFaceValue())
                    ->setCleanPrice($bond->getFaceValue())
                    ->setAccruedInterest('0.00000000')
                    ->setYieldToMaturity('0.000000')
                    ->setModifiedDuration('0.000000')
                    ->setConvexity('0.000000')
                    ->setUpdatedAt($now);

                $matured[] = $bond;
                continue;
            }

            // A corporate issue is remarked on the slower cadence. Its two drivers — the curve and its
            // issuer's credit — both move far slower than a tick, and the ladder is large enough that
            // revaluing all of it every tick is a measurable share of the tick budget for a price that has
            // not meaningfully changed. Coupons and maturity above are NOT on that cadence: those are dates,
            // and a date cannot be approximately reached.
            if (!$bond->isSovereign() && !$markCorporate) {
                continue;
            }

            // The spread comes from the issuer map the caller already holds rather than from the bond's
            // association, so marking the ladder does not lazy-load a company per bond.
            $spread = 0.0;

            if (!$bond->isSovereign()) {
                $issuerId = $bond->getIssuer()?->getId();
                $spread = $issuerId !== null
                    ? (float) ($issuerSpreads[$issuerId] ?? (float) $bond->getCreditSpread())
                    : (float) $bond->getCreditSpread();

                $bond->setCreditSpread((string) $spread);
            }

            $valuation = $this->pricingEngine->value($bond, $curve, $currentTime, $spread);

            $bond->setPrice((string) $valuation->dirtyPrice)
                ->setCleanPrice((string) $valuation->cleanPrice)
                ->setAccruedInterest((string) $valuation->accruedInterest)
                ->setYieldToMaturity((string) $valuation->yieldToMaturity)
                ->setModifiedDuration((string) $valuation->modifiedDuration)
                ->setConvexity((string) $valuation->convexity)
                ->setUpdatedAt($now);

            $updates[] = [
                'ticker' => $bond->getTicker(),
                'asset_type' => 'BOND',
                'name' => $bond->getName(),
                'price' => $valuation->dirtyPrice,
                'clean_price' => $valuation->cleanPrice,
                'accrued_interest' => $valuation->accruedInterest,
                'yield_to_maturity' => $valuation->yieldToMaturity,
                'modified_duration' => $valuation->modifiedDuration,
                'convexity' => $valuation->convexity,
                'tenor_years' => (float) $bond->getTenorYears(),
                'years_to_maturity' => $bond->yearsToMaturity($currentTime),
                'coupon_rate' => (float) $bond->getCouponRate(),
                'is_on_the_run' => $bond->isOnTheRun(),
                'issuer' => $bond->getIssuer()?->getTicker(),
                'credit_spread' => $spread,
            ];

            if ($recordHistory) {
                $history[] = [
                    'bond_id' => $bond->getId(),
                    'clean_price' => $valuation->cleanPrice,
                    'yield_to_maturity' => $valuation->yieldToMaturity,
                ];
            }
        }

        return [
            'updates' => $updates,
            'history' => $history,
            'matured' => $matured,
            'curve' => $this->sampleCurve($curve),
        ];
    }

    /**
     * The fitted curve sampled at the display tenors, published with the tick.
     *
     * Sent down with the quotes rather than recomputed in the browser. A JavaScript reimplementation of
     * the Svensson evaluation would be a second authority on the curve — the exact thing
     * MathUtility::calculateSovereignZeroYield exists to prevent — and its copied lambda constants would
     * drift silently from MacroEngine's the first time those were retuned.
     *
     * @param SovereignCurveDTO $curve The fitted term structure.
     * @return array<int, array{tenor: float, yield: float}>
     */
    private function sampleCurve(SovereignCurveDTO $curve): array
    {
        $points = [];

        foreach (FinancialConstants::BOND_CURVE_SAMPLE_TENORS as $tenor) {
            $points[] = ['tenor' => (float) $tenor, 'yield' => $this->pricingEngine->zeroYield($curve, (float) $tenor)];
        }

        return $points;
    }

    /**
     * Pays every coupon whose date has passed since the last one this issue paid.
     *
     * The redemption date's coupon is deliberately excluded: processRedemption pays face, and the final
     * coupon rides along with it in the same cash flow the pricing engine discounts, so paying it here as
     * well would credit it twice.
     *
     * @param Bond               $bond        The issue.
     * @param float              $currentTime Simulation time in years.
     * @param \DateTimeInterface $now         Timestamp for the ledger rows.
     */
    private function payDueCoupons(Bond $bond, float $currentTime, \DateTimeInterface $now): void
    {
        $period = $bond->couponPeriodYears();
        $couponAmount = $bond->couponAmount();

        if ($couponAmount <= 0.0 || $period <= 0.0) {
            return;
        }

        $nextCouponTime = $bond->getLastCouponTime() + $period;
        $finalCouponCutoff = $bond->getMaturesAtTime() - FinancialConstants::BOND_MATURITY_EPSILON;

        while ($nextCouponTime <= $currentTime + FinancialConstants::BOND_MATURITY_EPSILON) {
            if ($nextCouponTime >= $finalCouponCutoff) {
                break;
            }

            $this->ledger->processCouponPayment($bond, $couponAmount, $nextCouponTime, $now);
            $bond->setLastCouponTime($nextCouponTime);
            $nextCouponTime += $period;
        }
    }

    /**
     * Writes a batch of history points in one statement, matching the stock history insert.
     *
     * @param array<int, array<string, mixed>> $history Rows from updateBonds().
     */
    public function recordHistory(array $history): void
    {
        if ($history === []) {
            return;
        }

        $conn = $this->entityManager->getConnection();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $values = [];
        $params = [];

        foreach ($history as $row) {
            $values[] = '(?, ?, ?, ?)';
            $params[] = $row['bond_id'];
            $params[] = $row['clean_price'];
            $params[] = $row['yield_to_maturity'];
            $params[] = $now;
        }

        $conn->executeStatement(
            'INSERT INTO bond_history (bond_id, clean_price, yield_to_maturity, recorded_at) VALUES '
            . implode(', ', $values),
            $params
        );
    }
}
