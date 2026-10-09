<?php

declare(strict_types=1);

namespace App\Service\Market\Bond;

use App\DTO\MacroStateDTO;
use App\DTO\SovereignCurveDTO;
use App\Entity\Bond;
use App\Service\Math\FinancialConstants;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Market\Ticker\BulkRowUpdate;

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
 *
 * THE MARK IS WRITTEN AS DATA, NOT THROUGH THE ENTITY. Every issue on the ladder is revalued on the mark
 * cadence, and a ladder that has aged for a few decades of quarterly auctions is a couple of hundred
 * issues deep. Setting seven fields on each of them made Doctrine send one UPDATE per bond on every flush
 * — more synchronous round trips than the whole tick budget, for numbers that are the same shape on every
 * row. The valuation therefore goes to the database in a few bulk statements (BulkRowUpdate) and the
 * entity's mark fields are left alone, so the flush has nothing to say about them. Coupons, redemptions
 * and maturity still go through the entity: those are rare, and they are real state changes.
 *
 * Nothing in the ticker process reads a bond's mark back off the entity — the quote, the history row and
 * the wire all come from the valuation — so the entity's mark fields may lag until the ticker's next
 * working-set reload; every reader that wants the mark reads the row. Between marks the tracker quotes
 * the valuation it last struck, kept per ticker in memory: a price nothing has re-marked is by
 * definition the last one.
 *
 * The ladder is marked once a trading day, so two events cannot wait for the mark. A new issue has no
 * valuation to quote. A coupon paid since the mark leaves the dirty price still carrying the accrual the
 * holder has just been paid in cash, which would count the coupon twice in every portfolio holding the issue
 * until the next day. Either one strikes that issue on the tick it happens, and a coupon strike writes its row.
 */
class BondTracker
{
    // --- Mark Columns ---
    /** The columns one mark writes, in the order the valuation row carries them. */
    private const MARK_COLUMNS = [
        'price',
        'clean_price',
        'accrued_interest',
        'yield_to_maturity',
        'modified_duration',
        'convexity',
        'credit_spread',
        'updated_at',
    ];

    /**
     * The last valuation struck per ticker, and the wire row built from it, reused on the ticks between marks.
     *
     * @var array<string, array{valuation: \App\DTO\BondValuationDTO, update: array<string, mixed>}>
     */
    private array $lastMarks = [];

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
     * @return array{updates: array<int, array<string, mixed>>, struck: array<int, array<string, mixed>>, history: array<int, array<string, mixed>>, matured: array<int, Bond>, curve: array<int, array{tenor: float, yield: float}>}
     *         `updates` quotes every active issue; `struck` is the subset valued this pass, the only quotes
     *         that can have moved since the last one.
     * @param array<int, float> $issuerSpreads Live credit spread per issuer id, so marking the corporate
     *                                         ladder does not lazy-load a company for every bond on it.
     * @param bool              $mark          Whether the ladder is revalued and written this pass. Off, every
     *                                         issue quotes the valuation it was last marked at, except one
     *                                         that is new or has just paid a coupon.
     */
    public function updateBonds(
        array $bonds,
        MacroStateDTO $macroState,
        bool $recordHistory = false,
        array $issuerSpreads = [],
        bool $mark = true
    ): array {
        $curve = $macroState->sovereignCurve();
        $currentTime = $macroState->totalTime;
        $now = new \DateTime();

        $updates = [];
        $struckUpdates = [];
        $history = [];
        $matured = [];

        /** @var array<int, array<int, mixed>> $markRows Primary key => mark columns, for the bulk write. */
        $markRows = [];
        $markedAt = $now->format('Y-m-d H:i:s');

        foreach ($bonds as $bond) {
            if ($bond->getStatus() !== Bond::STATUS_ACTIVE) {
                continue;
            }

            $couponPaid = $this->payDueCoupons($bond, $currentTime, $now);

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

                // Bond is mapped DEFERRED_EXPLICIT, so a change reaches the database only through persist().
                $this->entityManager->persist($bond);

                $matured[] = $bond;
                unset($this->lastMarks[$bond->getTicker()]);
                continue;
            }

            $ticker = $bond->getTicker();
            $struck = $mark || $couponPaid || !isset($this->lastMarks[$ticker]);

            if ($struck) {
                // The spread comes from the issuer map the caller already holds rather than from the bond's
                // association, so marking the ladder does not lazy-load a company per bond.
                $spread = 0.0;

                if (!$bond->isSovereign()) {
                    $issuerId = $bond->getIssuer()?->getId();
                    $spread = $issuerId !== null
                        ? (float) ($issuerSpreads[$issuerId] ?? (float) $bond->getCreditSpread())
                        : (float) $bond->getCreditSpread();
                }

                $valuation = $this->pricingEngine->value($bond, $curve, $currentTime, $spread);

                // Wire precision: what a screen shows, not what the valuation carries. The history row below
                // keeps the full figure.
                $update = [
                    'ticker' => $ticker,
                    'asset_type' => 'BOND',
                    'name' => $bond->getName(),
                    'price' => round($valuation->dirtyPrice, 4),
                    'clean_price' => round($valuation->cleanPrice, 4),
                    'accrued_interest' => round($valuation->accruedInterest, 4),
                    'yield_to_maturity' => round($valuation->yieldToMaturity, 6),
                    'modified_duration' => round($valuation->modifiedDuration, 4),
                    'convexity' => round($valuation->convexity, 2),
                    'tenor_years' => (float) $bond->getTenorYears(),
                    'years_to_maturity' => round($bond->yearsToMaturity($currentTime), 4),
                    'coupon_rate' => (float) $bond->getCouponRate(),
                    'is_on_the_run' => $bond->isOnTheRun(),
                    'issuer' => $bond->getIssuer()?->getTicker(),
                    'credit_spread' => round($spread, 6),
                ];
                $this->lastMarks[$ticker] = ['valuation' => $valuation, 'update' => $update];
                $struckUpdates[] = $update;

                // Written below in bulk; an issue not yet flushed has no row to write to and is picked up
                // by the next mark, after the flush has given it one. A new issue quoted between marks is
                // not written: its row still holds the price it was issued at, which is the right one.
                if (($mark || $couponPaid) && $bond->getId() !== null) {
                    $markRows[$bond->getId()] = [
                        (string) $valuation->dirtyPrice,
                        (string) $valuation->cleanPrice,
                        (string) $valuation->accruedInterest,
                        (string) $valuation->yieldToMaturity,
                        (string) $valuation->modifiedDuration,
                        (string) $valuation->convexity,
                        (string) $spread,
                        $markedAt,
                    ];
                }
            } else {
                // The mark's own row, with the two fields that move without one: time, and the on-the-run
                // flag an auction takes away from the issue it replaces.
                $valuation = $this->lastMarks[$ticker]['valuation'];
                $update = $this->lastMarks[$ticker]['update'];
                $update['years_to_maturity'] = round($bond->yearsToMaturity($currentTime), 4);
                $update['is_on_the_run'] = $bond->isOnTheRun();
            }

            $updates[] = $update;

            // An issue sold since the last flush has no id for bond_history to key on yet.
            if ($recordHistory && $bond->getId() !== null) {
                $history[] = [
                    'bond_id' => $bond->getId(),
                    'clean_price' => $valuation->cleanPrice,
                    'yield_to_maturity' => $valuation->yieldToMaturity,
                    'sim_time' => $currentTime,
                ];
            }
        }

        if ($markRows !== []) {
            BulkRowUpdate::apply($this->entityManager->getConnection(), 'bonds', self::MARK_COLUMNS, $markRows);
        }

        return [
            'updates' => $updates,
            'struck' => $struckUpdates,
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
     * FixedIncome::calculateSovereignZeroYield exists to prevent — and its copied lambda constants would
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
     * @return bool Whether a coupon was paid, which leaves the last mark's dirty price holding it twice.
     */
    private function payDueCoupons(Bond $bond, float $currentTime, \DateTimeInterface $now): bool
    {
        $period = $bond->couponPeriodYears();

        if ($period <= 0.0) {
            return false;
        }

        // Every issue is checked every tick and almost none is due, so the date test comes before anything
        // that has to read the coupon off its decimal columns.
        $nextCouponTime = $bond->getLastCouponTime() + $period;

        if ($nextCouponTime > $currentTime + FinancialConstants::BOND_MATURITY_EPSILON) {
            return false;
        }

        $couponAmount = $bond->couponAmount();

        if ($couponAmount <= 0.0) {
            return false;
        }

        $finalCouponCutoff = $bond->getMaturesAtTime() - FinancialConstants::BOND_MATURITY_EPSILON;
        $paid = false;

        while ($nextCouponTime <= $currentTime + FinancialConstants::BOND_MATURITY_EPSILON) {
            if ($nextCouponTime >= $finalCouponCutoff) {
                break;
            }

            $this->ledger->processCouponPayment($bond, $couponAmount, $nextCouponTime, $now);
            $bond->setLastCouponTime($nextCouponTime);

            // Bond is mapped DEFERRED_EXPLICIT: without this the coupon is paid and the issue forgets it,
            // so the next tick pays it again.
            $this->entityManager->persist($bond);

            $nextCouponTime += $period;
            $paid = true;
        }

        return $paid;
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
            $values[] = '(?, ?, ?, ?, ?)';
            $params[] = $row['bond_id'];
            $params[] = $row['clean_price'];
            $params[] = $row['yield_to_maturity'];
            $params[] = $now;
            $params[] = $row['sim_time'] ?? null;
        }

        $conn->executeStatement(
            'INSERT INTO bond_history (bond_id, clean_price, yield_to_maturity, recorded_at, sim_time) VALUES '
            . implode(', ', $values),
            $params
        );
    }
}
