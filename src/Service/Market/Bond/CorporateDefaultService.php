<?php

declare(strict_types=1);

namespace App\Service\Market\Bond;

use App\DTO\MacroStateDTO;
use App\DTO\ReorganizationPlanDTO;
use App\Entity\Bond;
use App\Entity\Stock;
use App\Service\Math\CreditRisk;
use App\Service\Math\Decimal;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Settles a failed company's listed debt.
 *
 * This is where holding a bond stops being a slower way of holding the stock. When a firm fails its
 * shareholders are wiped to zero — that is already true here — but its bondholders are not: they are
 * creditors, they stand ahead of the equity, and they get back whatever the estate is worth. Without this
 * step a corporate bond would simply vanish along with the company, which would make credit strictly worse
 * than equity in the one scenario it exists to be better in.
 *
 * What comes back is not a constant. Recovery falls as defaults cluster (Altman, Brady, Resti & Sironi
 * 2005), so a firm failing alone in a good year settles far better than the same firm failing in the middle
 * of a credit cycle when every estate is being liquidated into the same market. That correlation is the
 * whole reason a diversified book of credit is not as safe as it looks: the losses arrive together AND each
 * one is worse than average.
 *
 * Seniority decides the order. A secured claim is ahead of an unsecured one, which is ahead of a
 * subordinated one, and at every point in the cycle.
 */
final class CorporateDefaultService
{
    // --- Corporate Credit: Recovery Given Default (Altman, Brady, Resti & Sironi 2005) ---
    /** Recovery on a senior SECURED claim in an average default year, as a share of face; collateral is what puts this claim ahead of the rest. */
    public const RECOVERY_SENIOR_SECURED = 0.62;
    /** Recovery on a senior UNSECURED claim, the ordinary public corporate bond. */
    public const RECOVERY_SENIOR_UNSECURED = 0.48;
    /** Recovery on a SUBORDINATED claim, which is paid only once everything above it is whole. */
    public const RECOVERY_SUBORDINATED = 0.28;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BondLedgerService $ledger,
    ) {}

    /**
     * Base recovery for a claim's place in the queue, before the cycle is applied.
     *
     * An issue with no seniority recorded is treated as senior unsecured, which is what an ordinary public
     * corporate bond is and therefore the right thing to assume about one that did not say.
     */
    public static function baseRecoveryFor(?string $seniority): float
    {
        return match ($seniority) {
            Bond::SENIORITY_SENIOR_SECURED => self::RECOVERY_SENIOR_SECURED,
            Bond::SENIORITY_SUBORDINATED => self::RECOVERY_SUBORDINATED,
            default => self::RECOVERY_SENIOR_UNSECURED,
        };
    }

    /**
     * Settles every listed issue of a failed company.
     *
     * A liquidation settles at the market's recovery for the claim's seniority and the cycle. A confirmed
     * Chapter 11 plan has already valued the estate, so its issues settle at what the plan gives them: the
     * creditors' value paid down the queue, the revolver first. A plan that leaves the old shareholders a stake
     * has made every creditor whole, and a fixed recovery would hand the bondholders' loss to the equity.
     *
     * @param ?ReorganizationPlanDTO $plan The confirmed plan the issues are exchanged under; null for a liquidation.
     * @return array<int, array{ticker: string, recovery: float, face: float}> One record per issue settled.
     */
    public function settle(Stock $issuer, MacroStateDTO $macroState, \DateTimeInterface $settledAt, ?ReorganizationPlanDTO $plan = null): array
    {
        $bonds = $this->em->getRepository(Bond::class)->findBy([
            'issuer' => $issuer,
            'status' => Bond::STATUS_ACTIVE,
        ]);

        if ($bonds === []) {
            return [];
        }

        // The cycle the estate is being liquidated into, not the long-run average. Reading the EMA rather
        // than the instantaneous rate is deliberate: what depresses a recovery is a SUSTAINED wave of
        // defaults competing for the same buyers, not one bad quarter.
        $defaultRate = $macroState->corporateDefaultRateEma;
        $planRecoveries = $plan !== null ? self::planRecoveries($bonds, $plan) : null;
        $settled = [];

        foreach ($bonds as $bond) {
            $recovery = $planRecoveries !== null
                ? $planRecoveries[self::seniorityClass($bond->getSeniority())]
                : CreditRisk::calculateRecoveryGivenDefault(
                    self::baseRecoveryFor($bond->getSeniority()),
                    $defaultRate
                );

            $face = (float) $bond->getFaceValue();
            $recoveredPerBond = $recovery * $face;

            $this->ledger->processDefaultSettlement($bond, $recoveredPerBond, $macroState->totalTime, $settledAt);

            $bond->setStatus(Bond::STATUS_DEFAULTED)
                ->setRecoveryRate(Decimal::format($recovery, 4))
                ->setIsOnTheRun(false)
                ->setPrice(Decimal::format($recoveredPerBond, 8))
                ->setCleanPrice(Decimal::format($recoveredPerBond, 8))
                ->setAccruedInterest('0.00000000')
                // A settled claim has no yield, no duration and no convexity: there is nothing left to
                // discount. Leaving the last live figures on the row would have the ladder quoting a
                // redemption that is never going to happen.
                ->setYieldToMaturity('0.000000')
                ->setModifiedDuration('0.000000')
                ->setConvexity('0.000000')
                ->setUpdatedAt($settledAt instanceof \DateTime ? $settledAt : new \DateTime());

            // Bond is mapped DEFERRED_EXPLICIT, so a change reaches the database only through persist().
            $this->em->persist($bond);

            $settled[] = [
                'ticker' => $bond->getTicker(),
                'recovery' => $recovery,
                'face' => (float) $bond->getOutstandingFace(),
            ];
        }

        return $settled;
    }

    /** The class a seniority settles in; an issue that recorded none is the ordinary senior unsecured bond. */
    private static function seniorityClass(?string $seniority): string
    {
        return match ($seniority) {
            Bond::SENIORITY_SENIOR_SECURED, Bond::SENIORITY_SUBORDINATED => $seniority,
            default => Bond::SENIORITY_SENIOR_UNSECURED,
        };
    }

    /**
     * Recovery per seniority under a confirmed plan. The queue is the revolver, then secured, unsecured and
     * subordinated term debt. The unsecured class is the firm's term debt less its listed secured and
     * subordinated issues, and never less than the unsecured issues listed against it.
     *
     * @param list<Bond> $bonds
     * @return array<string, float>
     */
    private static function planRecoveries(array $bonds, ReorganizationPlanDTO $plan): array
    {
        $listed = [
            Bond::SENIORITY_SENIOR_SECURED => 0.0,
            Bond::SENIORITY_SENIOR_UNSECURED => 0.0,
            Bond::SENIORITY_SUBORDINATED => 0.0,
        ];
        foreach ($bonds as $bond) {
            $listed[self::seniorityClass($bond->getSeniority())] += max(0.0, (float) $bond->getOutstandingFace());
        }

        $termClaims = max(0.0, $plan->claims - $plan->revolverClaim);
        $unsecured = max(
            $listed[Bond::SENIORITY_SENIOR_UNSECURED],
            $termClaims - $listed[Bond::SENIORITY_SENIOR_SECURED] - $listed[Bond::SENIORITY_SUBORDINATED]
        );

        [, $secured, $senior, $subordinated] = CreditRisk::absolutePriorityRecoveries($plan->creditorValue(), [
            $plan->revolverClaim,
            $listed[Bond::SENIORITY_SENIOR_SECURED],
            $unsecured,
            $listed[Bond::SENIORITY_SUBORDINATED],
        ]);

        return [
            Bond::SENIORITY_SENIOR_SECURED => $secured,
            Bond::SENIORITY_SENIOR_UNSECURED => $senior,
            Bond::SENIORITY_SUBORDINATED => $subordinated,
        ];
    }
}
