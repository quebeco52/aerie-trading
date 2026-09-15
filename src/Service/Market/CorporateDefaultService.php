<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\DTO\MacroStateDTO;
use App\Entity\Bond;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
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
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BondLedgerService $ledger,
        private readonly MathUtility $mathUtility,
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
            Bond::SENIORITY_SENIOR_SECURED => FinancialConstants::RECOVERY_SENIOR_SECURED,
            Bond::SENIORITY_SUBORDINATED => FinancialConstants::RECOVERY_SUBORDINATED,
            default => FinancialConstants::RECOVERY_SENIOR_UNSECURED,
        };
    }

    /**
     * Settles every listed issue of a failed company.
     *
     * @return array<int, array{ticker: string, recovery: float, face: float}> One record per issue settled.
     */
    public function settle(Stock $issuer, MacroStateDTO $macroState, \DateTimeInterface $settledAt): array
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
        $settled = [];

        foreach ($bonds as $bond) {
            $recovery = $this->mathUtility->calculateRecoveryGivenDefault(
                self::baseRecoveryFor($bond->getSeniority()),
                $defaultRate
            );

            $face = (float) $bond->getFaceValue();
            $recoveredPerBond = $recovery * $face;

            $this->ledger->processDefaultSettlement($bond, $recoveredPerBond, $macroState->totalTime, $settledAt);

            $bond->setStatus(Bond::STATUS_DEFAULTED)
                ->setRecoveryRate(MathUtility::formatDecimal($recovery, 4))
                ->setIsOnTheRun(false)
                ->setPrice(MathUtility::formatDecimal($recoveredPerBond, 8))
                ->setCleanPrice(MathUtility::formatDecimal($recoveredPerBond, 8))
                ->setAccruedInterest('0.00000000')
                // A settled claim has no yield, no duration and no convexity: there is nothing left to
                // discount. Leaving the last live figures on the row would have the ladder quoting a
                // redemption that is never going to happen.
                ->setYieldToMaturity('0.000000')
                ->setModifiedDuration('0.000000')
                ->setConvexity('0.000000')
                ->setUpdatedAt($settledAt instanceof \DateTime ? $settledAt : new \DateTime());

            $settled[] = [
                'ticker' => $bond->getTicker(),
                'recovery' => $recovery,
                'face' => (float) $bond->getOutstandingFace(),
            ];
        }

        return $settled;
    }
}
