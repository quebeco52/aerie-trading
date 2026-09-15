<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\OptionContract;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Decides what is listed: which names carry a class, which expiries are open, and which strikes exist.
 *
 * Listing is a market rule rather than a modelling choice, and it is the rule that keeps the chain finite.
 * Exchanges open a class against a float and a trading record, list a handful of near expiries on a shared
 * monthly grid, and add strikes around the money as the underlying moves — they do not list every strike on
 * every name forever. Following that gives roughly a hundred live contracts per optionable name instead of
 * an unbounded surface, which is what makes repricing the whole market inside a tick possible at all.
 *
 * Strikes are never withdrawn once listed. A contract with open interest has to stay tradable so the holder
 * can close it, and the ladder empties on its own when the expiry settles.
 */
final class OptionChainService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LiquidityEngine $liquidityEngine,
    ) {}

    /**
     * The monthly listing serial a moment in simulation time falls in.
     *
     * Expiries sit on a grid shared by every name, so an expiry is a market-wide event — one day when the
     * whole market's hedges roll — rather than 52 unrelated ones.
     */
    public static function expirySerial(float $currentTime): int
    {
        return (int) floor($currentTime * 12.0);
    }

    /** Simulation time, in years, at which a serial expires. */
    public static function expiryTime(int $serial): float
    {
        return $serial / 12.0;
    }

    /**
     * The serials open for listing as of now.
     *
     * @return array<int, int> Ascending.
     */
    public static function listedSerials(float $currentTime): array
    {
        $current = self::expirySerial($currentTime);

        return array_map(
            static fn (int $months): int => $current + $months,
            FinancialConstants::OPTION_EXPIRY_MONTHS
        );
    }

    /**
     * The round increment a name's ladder is struck on.
     *
     * A ladder spaced at a flat fraction of spot would put strikes on numbers nobody quotes. Real ladders
     * snap to round figures, and which round figure depends on the price level, so the increment is the
     * smallest listed one that is at least the target spacing.
     */
    public static function strikeIncrement(float $spot): float
    {
        $target = $spot * FinancialConstants::OPTION_STRIKE_SPACING_FRACTION;
        $increments = FinancialConstants::OPTION_STRIKE_INCREMENTS;

        foreach ($increments as $increment) {
            if ($increment >= $target) {
                return $increment;
            }
        }

        return (float) end($increments);
    }

    /**
     * The strikes listed around a spot price.
     *
     * @return array<int, float> Ascending, on the round increment, inside the ladder width.
     */
    public static function strikeLadder(float $spot): array
    {
        if ($spot <= 0.0) {
            return [];
        }

        $increment = self::strikeIncrement($spot);
        $width = FinancialConstants::OPTION_STRIKE_LADDER_WIDTH;

        $lowest = max($increment, ceil(($spot * (1.0 - $width)) / $increment) * $increment);
        $highest = floor(($spot * (1.0 + $width)) / $increment) * $increment;

        $strikes = [];
        for ($strike = $lowest; $strike <= $highest + ($increment * 1.0e-9); $strike += $increment) {
            $strikes[] = round($strike, 4);
        }

        return $strikes;
    }

    /**
     * Whether a name meets the listing standard.
     *
     * A bankrupt shell, a sub-dollar name whose ladder would be one strike wide, and a name nobody trades
     * all fail for the same reason: there is no market to write contracts against.
     */
    public function isListable(Stock $stock): bool
    {
        if ($stock->isBankrupt()) {
            return false;
        }

        if ((float) $stock->getPrice() < FinancialConstants::OPTION_LISTING_MIN_PRICE) {
            return false;
        }

        return $this->liquidityEngine->structuralDailyVolume($stock) >= FinancialConstants::OPTION_LISTING_MIN_ADV;
    }

    /**
     * The symbol one contract trades under: underlying, expiry serial, side, strike.
     *
     * Readable rather than the OCC's fixed-width encoding, because a player types it.
     */
    public static function contractTicker(string $underlying, int $serial, string $optionType, float $strike): string
    {
        $strikeText = rtrim(rtrim(number_format($strike, 2, '.', ''), '0'), '.');

        return sprintf(
            '%s-%d%s%s',
            $underlying,
            $serial,
            $optionType === OptionContract::TYPE_CALL ? 'C' : 'P',
            $strikeText
        );
    }

    /**
     * Opens whatever is missing from a name's chain and returns every contract now listed on it.
     *
     * Idempotent: called each listing sweep, it adds the strikes the underlying has moved into and leaves
     * everything already open untouched.
     *
     * @return array<int, OptionContract> Newly listed contracts only.
     */
    public function listChain(Stock $stock, float $currentTime): array
    {
        if (!$this->isListable($stock)) {
            return [];
        }

        // Symbols only. Listing needs to know which contracts already exist, not what they are worth, and
        // hydrating a hundred entities to read one string off each of them meant every sweep loaded the
        // whole chain twice — once here and once to mark it.
        $existing = array_fill_keys(
            $this->em->createQuery(
                'SELECT o.ticker FROM ' . OptionContract::class . ' o WHERE o.stock = :stock AND o.status = :status'
            )
                ->setParameter('stock', $stock)
                ->setParameter('status', OptionContract::STATUS_ACTIVE)
                ->getSingleColumnResult(),
            true
        );

        $strikes = self::strikeLadder((float) $stock->getPrice());
        $listed = [];

        foreach (self::listedSerials($currentTime) as $serial) {
            $expiresAt = self::expiryTime($serial);

            foreach ($strikes as $strike) {
                foreach ([OptionContract::TYPE_CALL, OptionContract::TYPE_PUT] as $optionType) {
                    $ticker = self::contractTicker($stock->getTicker(), $serial, $optionType, $strike);

                    if (isset($existing[$ticker])) {
                        continue;
                    }

                    $contract = (new OptionContract())
                        ->setTicker($ticker)
                        ->setStock($stock)
                        ->setOptionType($optionType)
                        ->setStrike((string) $strike)
                        ->setExpirySerial($serial)
                        ->setExpiresAtTime($expiresAt)
                        ->setListedAtTime($currentTime)
                        ->setStatus(OptionContract::STATUS_ACTIVE);

                    $this->em->persist($contract);
                    $existing[$ticker] = true;
                    $listed[] = $contract;
                }
            }
        }

        return $listed;
    }
}
