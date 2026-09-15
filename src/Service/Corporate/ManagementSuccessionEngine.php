<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\Data\ManagementStyle;
use App\Entity\Stock;
use App\Service\Event\MarketEventPublisher;
use App\Service\Math\MathUtility;

/**
 * Management turnover: the experiment Bertrand & Schoar (2003) actually ran.
 *
 * Their identification comes from managers MOVING — the same person carries a policy fixed effect from one
 * firm to the next, and a firm's investment, payout and financial policy shift when the person at the top
 * changes. A style frozen at seed for the life of the simulation is therefore the finding with its mechanism
 * removed: the bias persists, but nothing ever tests it, corrects it, or lets a player anticipate it.
 *
 * Two hazards run side by side, which is how the turnover literature separates them:
 *
 *  - A baseline departure rate (retirement, health, a better offer), independent of performance.
 *  - A forced component driven by the economic profit the board can see, after Weisbach (1988), "Outside
 *    directors and CEO turnover": boards dismiss managers following poor performance, and the sensitivity is
 *    the observable part of governance. It is written as a probit on the EVA spread because that is the
 *    standard specification for a discrete turnover decision, and because the spread is the same figure
 *    every other gate in this codebase already judges a firm by.
 *
 * The board here reacts to the TOTAL spread and does not strip out the part the manager did not cause. That
 * is deliberate and it is the empirical finding, not a simplification: Jenter & Kanaan (2015), "CEO Turnover
 * and Relative Performance Evaluation", show CEOs are dismissed after bad industry and market performance
 * too — boards fail to filter out luck. It falls out for free here, because the hurdle the spread is measured
 * against rises with the macro, so a downturn thins the ranks of incumbents on its own.
 *
 * The successor is drawn conditional on WHY the seat came open, which is where the persistence lives. An
 * internal, unforced succession mostly continues the house style; a dismissal is the board correcting, and
 * boards correct by reaching for the opposite of what just failed (Hayward & Hambrick 1997) — discipline
 * after an empire builder, growth after a custodian who ran the firm quietly into stagnation.
 */
class ManagementSuccessionEngine
{
    // --- Departure Hazards (annual) ---
    /** Baseline annual departure rate (~6%): retirement, health and outside offers, independent of performance. */
    public const BASE_DEPARTURE_HAZARD = 0.06;
    /** Probit intercept: Phi(-1.85) is a ~3.2% annual forced-turnover rate for a firm earning exactly its cost of capital. */
    public const FORCED_TURNOVER_INTERCEPT = -1.85;
    /** Probit loading on the EVA spread. At -800bps of economic profit the forced hazard reaches ~15% a year. */
    public const FORCED_TURNOVER_EVA_LOADING = 10.0;
    /** Ceiling on the combined annual hazard, so no board dismisses a manager mid-turnaround with certainty. */
    public const MAX_ANNUAL_HAZARD = 0.35;

    // --- Board Patience ---
    /** Years a new manager is judged on the predecessor's numbers before the forced hazard applies at all. */
    public const GRACE_PERIOD_YEARS = 2.0;
    /** Tenure (years) past which a departure is read as a retirement rather than a dismissal, whatever the spread. */
    public const RETIREMENT_TENURE_YEARS = 12.0;

    // --- Successor Draw ---
    /** Probability an unforced succession keeps the house style: the persistence Bertrand & Schoar measured. */
    public const CONTINUITY_PROBABILITY = 0.70;
    /** Probability a dismissal is answered with the corrective style rather than a merely competent operator. */
    public const CORRECTION_PROBABILITY = 0.60;

    public function __construct(
        private MarketEventPublisher $marketEvent,
        private MathUtility $mathUtility
    ) {}

    /**
     * Ages the incumbent by one tick and resolves a departure if the hazard fires.
     *
     * @param float $dt Time step in years.
     * @param float $evaSpread The firm's economic profit: its true return less its true cost of capital.
     *
     * @return array<string, mixed>|null The published event, or null when the incumbent survives the tick.
     */
    public function evaluateSuccession(Stock $stock, float $dt, float $evaSpread): ?array
    {
        if ($stock->isBankrupt() || $dt <= 0.0) {
            return null;
        }

        $tenure = $stock->getCeoTenureYears() + $dt;
        $stock->setCeoTenureYears($tenure);

        $annualHazard = $this->resolveAnnualHazard($tenure, $evaSpread);

        // A hazard quoted per year reaches a tick through the exponential survival function rather than by
        // multiplying it out, so the same rate produces the same turnover at any tick rate.
        $tickProbability = 1.0 - exp(-$annualHazard * $dt);
        if (!$this->mathUtility->checkProbability($tickProbability)) {
            return null;
        }

        $incumbent = $stock->getManagementStyle();
        $wasForced = $this->isDismissal($tenure, $evaSpread);
        $successor = $this->drawSuccessor($incumbent, $wasForced);

        $stock->setManagementStyle($successor);
        $stock->setCeoTenureYears(0.0);

        return $this->publishSuccession($stock, $incumbent, $successor, $wasForced, $tenure);
    }

    /**
     * The tenure a firm opens the simulation with.
     *
     * Seeding every incumbent at zero would say the whole market replaced its management on the same day,
     * and would then march the entire roster out of the grace period together. A memoryless hazard has an
     * exponential stationary age distribution with mean 1 / hazard, so drawing from it is what a market
     * that has been running for a while actually looks like: mostly young tenures, a long tail of
     * survivors. Capped at the retirement horizon, past which a manager would be leaving anyway.
     */
    public static function drawSeedTenure(MathUtility $mathUtility): float
    {
        $unconditionalHazard = self::BASE_DEPARTURE_HAZARD + $mathUtility->calculateNormalCDF(self::FORCED_TURNOVER_INTERCEPT);

        return min(self::RETIREMENT_TENURE_YEARS, $mathUtility->generateExponential($unconditionalHazard));
    }

    /**
     * The combined annual departure hazard: a flat baseline plus a probit in the economic profit the board
     * can see, suppressed while the new manager is still inside the grace period.
     */
    public function resolveAnnualHazard(float $tenure, float $evaSpread): float
    {
        return min(
            self::MAX_ANNUAL_HAZARD,
            self::BASE_DEPARTURE_HAZARD + $this->resolveForcedHazard($tenure, $evaSpread)
        );
    }

    /**
     * The dismissal hazard alone: a probit in the economic profit the board can see, and exactly zero while
     * the new manager is still inside the grace period.
     */
    public function resolveForcedHazard(float $tenure, float $evaSpread): float
    {
        if ($tenure < self::GRACE_PERIOD_YEARS) {
            return 0.0;
        }

        return $this->mathUtility->calculateNormalCDF(
            self::FORCED_TURNOVER_INTERCEPT - (self::FORCED_TURNOVER_EVA_LOADING * $evaSpread)
        );
    }

    /**
     * Whether a departure reads as a dismissal.
     *
     * Two hazards compete for the same seat, so which one actually took it is settled the way competing
     * risks always are: in proportion to the two intensities at the moment the exit fired. An earlier
     * version asked instead whether the spread was negative, which is not the same question — a firm can
     * be earning its cost of capital and still lose its manager to a board that wanted someone else, and
     * the sign of a spread is a cliff where the hazard is a slope. At the cost of capital this puts roughly
     * a third of departures in the forced column, which is where the turnover literature puts them.
     *
     * A manager in post long enough for the exit to read as a retirement leaves on their own terms.
     */
    private function isDismissal(float $tenure, float $evaSpread): bool
    {
        if ($tenure >= self::RETIREMENT_TENURE_YEARS) {
            return false;
        }

        $forcedHazard = $this->resolveForcedHazard($tenure, $evaSpread);
        $totalHazard = self::BASE_DEPARTURE_HAZARD + $forcedHazard;

        return $totalHazard > 0.0 && $this->mathUtility->checkProbability($forcedHazard / $totalHazard);
    }

    /**
     * Draws the successor's style.
     *
     * Unforced, the house style usually survives the handover — that persistence IS the Bertrand & Schoar
     * result, and an internal promotion is how it travels. A dismissal is the board correcting, and it
     * corrects toward the opposite failing: discipline after capital was spent below its cost, growth after
     * a firm was run for cash until there was nothing left to run.
     */
    public function drawSuccessor(ManagementStyle $incumbent, bool $wasForced): ManagementStyle
    {
        if (!$wasForced) {
            if ($this->mathUtility->checkProbability(self::CONTINUITY_PROBABILITY)) {
                return $incumbent;
            }

            return $this->drawFrom(array_values(array_filter(
                ManagementStyle::cases(),
                static fn (ManagementStyle $case): bool => $case !== $incumbent
            )));
        }

        if ($this->mathUtility->checkProbability(self::CORRECTION_PROBABILITY)) {
            return $this->drawFrom($this->correctionsFor($incumbent));
        }

        // The board could not agree on a direction and hired a safe pair of hands.
        return ManagementStyle::Operator;
    }

    /**
     * What a board reaches for after dismissing each kind of manager.
     *
     * @return list<ManagementStyle>
     */
    private function correctionsFor(ManagementStyle $incumbent): array
    {
        return match ($incumbent) {
            // Capital went out below its cost, so the mandate is to stop and return it.
            ManagementStyle::EmpireBuilder => [ManagementStyle::Steward, ManagementStyle::Fortress],
            // The firm was run for distribution or for the balance sheet, and it stopped growing.
            ManagementStyle::Steward, ManagementStyle::Fortress => [ManagementStyle::EmpireBuilder, ManagementStyle::Operator],
            // A balanced manager who still failed leaves no obvious direction to correct toward.
            ManagementStyle::Operator => [ManagementStyle::Steward, ManagementStyle::EmpireBuilder],
        };
    }

    /** @param list<ManagementStyle> $candidates */
    private function drawFrom(array $candidates): ManagementStyle
    {
        if ($candidates === []) {
            return ManagementStyle::Operator;
        }

        $index = (int) floor($this->mathUtility->generateUniform() * count($candidates));

        return $candidates[min($index, count($candidates) - 1)];
    }

    /**
     * Publishes the change with NO price shock attached.
     *
     * A CEO announcement is real news, but the value in it is the policy that follows, and every dial the
     * style moves — the hurdle, the payout, the leverage appetite, the appetite for deals — is already
     * priced by the engines downstream from here. Striking a shock as well would charge the price twice for
     * one piece of information, and it would spend variance the budgeted jump process has not allowed for.
     */
    private function publishSuccession(Stock $stock, ManagementStyle $outgoing, ManagementStyle $successor, bool $wasForced, float $tenure): array
    {
        $departure = $wasForced
            ? 'chief executive was removed by the board after'
            : 'chief executive stepped down after';
        $mandate = $successor === $outgoing
            ? 'An internal promotion inherits the mandate unchanged.'
            : sprintf('The incoming management is %s.', $this->describe($successor));

        $description = sprintf(
            '%s %s %s years. %s',
            $stock->getName() ?: (string) $stock->getTicker(),
            $departure,
            number_format($tenure, 1),
            $mandate
        );

        $event = $this->marketEvent->publish($stock, 'MANAGEMENT CHANGE', $description, 0.0);

        return [
            'event' => $event,
            'shock' => 0.0,
            'was_forced' => $wasForced,
            'outgoing' => $outgoing->value,
            'successor' => $successor->value,
        ];
    }

    private function describe(ManagementStyle $style): string
    {
        return match ($style) {
            ManagementStyle::Operator => 'expected to invest at the cost of capital and distribute the rest',
            ManagementStyle::EmpireBuilder => 'mandated to grow, and will fund projects a stricter board would refuse',
            ManagementStyle::Steward => 'expected to hold a high internal hurdle and return what it cannot beat',
            ManagementStyle::Fortress => 'expected to rebuild the balance sheet and hold cash against the cycle',
        };
    }
}
