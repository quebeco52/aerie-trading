<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\DTO\SovereignCurveDTO;
use App\Entity\Bond;
use App\Service\Math\FinancialConstants;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rotates the on-the-run sovereign issues.
 *
 * Every auction sells a fresh bond at each offered tenor and demotes the previous holder of that tenor to
 * off-the-run. That rotation is what keeps a benchmark maturity available to trade: without it the only
 * ten-year on the desk is the one sold at the start of the simulation, which is a nine-year a year later
 * and never a ten-year again.
 *
 * The coupon is struck at the par rate for the tenor and rounded to the auction's eighth of a percent, so
 * a new issue prices near par but not exactly at it — the same small premium or discount a real auction
 * leaves on the table.
 */
class TreasuryAuctionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BondPricingEngine $pricingEngine,
    ) {}

    /**
     * Auctions per year, from the tick rate.
     *
     * @param int $ticksPerYear Configured simulation tick rate.
     * @return int Ticks between auctions, at least one.
     */
    public static function auctionIntervalTicks(int $ticksPerYear): int
    {
        return (int) max(1, $ticksPerYear / FinancialConstants::BOND_AUCTIONS_PER_YEAR);
    }

    /**
     * Sells one new issue at every offered tenor and demotes the outgoing on-the-runs.
     *
     * @param SovereignCurveDTO $curve       The fitted term structure the coupons are struck off.
     * @param float             $currentTime Simulation time in years.
     * @param array<int, Bond>  $outstanding The caller's working set, so its entities are demoted too.
     * @return array<int, Bond> The issues created, in tenor order.
     */
    public function conductAuction(SovereignCurveDTO $curve, float $currentTime, array $outstanding = []): array
    {
        $issued = [];

        foreach (FinancialConstants::BOND_AUCTION_TENORS as $tenor) {
            $this->demoteOnTheRun((float) $tenor, $outstanding);
            $issued[] = $this->issue($curve, (float) $tenor, $currentTime);
        }

        return $issued;
    }

    /**
     * Creates one issue at par-struck coupon for a tenor.
     *
     * @param SovereignCurveDTO $curve       The fitted term structure.
     * @param float             $tenor       Original maturity in years.
     * @param float             $currentTime Simulation time in years at auction.
     */
    public function issue(SovereignCurveDTO $curve, float $tenor, float $currentTime): Bond
    {
        $face = FinancialConstants::BOND_FACE_VALUE;
        $couponRate = $this->pricingEngine->parCouponRate($curve, $tenor, $face);

        $bond = new Bond();
        $bond->setTicker($this->nextTicker($tenor))
            ->setName($this->issueName($tenor, $couponRate, $currentTime + $tenor))
            ->setTenorYears((string) $tenor)
            ->setCouponRate((string) $couponRate)
            ->setFaceValue((string) $face)
            ->setIssuedAtTime($currentTime)
            ->setMaturesAtTime($currentTime + $tenor)
            ->setLastCouponTime($currentTime)
            ->setIsOnTheRun(true)
            ->setStatus(Bond::STATUS_ACTIVE)
            ->setOutstandingFace((string) FinancialConstants::BOND_ISSUE_SIZE);

        $valuation = $this->pricingEngine->value($bond, $curve, $currentTime);
        $bond->setPrice((string) $valuation->dirtyPrice)
            ->setCleanPrice((string) $valuation->cleanPrice)
            ->setAccruedInterest((string) $valuation->accruedInterest)
            ->setYieldToMaturity((string) $valuation->yieldToMaturity)
            ->setModifiedDuration((string) $valuation->modifiedDuration)
            ->setConvexity((string) $valuation->convexity)
            ->setUpdatedAt(new \DateTime());

        $this->entityManager->persist($bond);

        return $bond;
    }

    /**
     * Clears the on-the-run flag from the current benchmark at a tenor.
     *
     * Both halves are needed. The bulk UPDATE is the authority for the table, including rows the caller has
     * not loaded — the seed and reset paths hold no working set at all. But a bulk UPDATE goes around
     * Doctrine's identity map, so the ticker's in-memory bonds would keep claiming to be on-the-run until
     * the next EntityManager clear, and it publishes that flag in the tick payload. The row would be right
     * and the quote wrong, for as long as the working set survives.
     *
     * @param float            $tenor       Original maturity in years.
     * @param array<int, Bond> $outstanding The caller's working set.
     */
    private function demoteOnTheRun(float $tenor, array $outstanding): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE bonds SET is_on_the_run = 0 WHERE tenor_years = :tenor AND is_on_the_run = 1',
            ['tenor' => $tenor]
        );

        foreach ($outstanding as $bond) {
            if ($bond->isOnTheRun() && (float) $bond->getTenorYears() === $tenor) {
                $bond->setIsOnTheRun(false);
            }
        }
    }

    /**
     * Next free ticker at a tenor, as G<tenor>-<sequence>.
     *
     * The sequence comes from the row count at that tenor rather than from an in-memory counter, so a
     * restarted ticker does not reissue a ticker that already exists and collide on the unique index.
     *
     * @param float $tenor Original maturity in years.
     */
    private function nextTicker(float $tenor): string
    {
        $existing = (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM bonds WHERE tenor_years = :tenor',
            ['tenor' => $tenor]
        );

        return sprintf('G%02d-%03d', (int) $tenor, $existing + 1);
    }

    /**
     * Human-readable issue name, in the market's own convention: coupon then maturity.
     *
     * @param float $tenor        Original maturity in years.
     * @param float $couponRate   Struck annual coupon.
     * @param float $maturityTime Simulation time in years at redemption.
     */
    private function issueName(float $tenor, float $couponRate, float $maturityTime): string
    {
        return sprintf('%.3f%% Sovereign %dY due Y%.2f', $couponRate * 100.0, (int) $tenor, $maturityTime);
    }
}
