<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Bond;

use App\DTO\MacroStateDTO;
use App\DTO\ReorganizationPlanDTO;
use App\Entity\Bond;
use App\Entity\Stock;
use App\Service\Market\Bond\BondLedgerService;
use App\Service\Market\Bond\CorporateDefaultService;
use App\Service\Math\CreditRisk;
use App\Service\Math\FinancialConstants;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * What a bondholder gets when the company fails.
 *
 * The point of the whole credit layer is that a creditor is not a shareholder: equity goes to zero and the
 * claim does not. So the properties worth pinning are that something always comes back, that where the claim
 * sits in the queue decides how much, and that a firm failing in the middle of a credit cycle settles worse
 * than the same firm failing alone.
 */
#[Group('unit')]
class CorporateDefaultServiceTest extends TestCase
{
    /** Recovery for a claim, as the service computes it. */
    private function recovery(?string $seniority, float $defaultRate): float
    {
        return CreditRisk::calculateRecoveryGivenDefault(
            CorporateDefaultService::baseRecoveryFor($seniority),
            $defaultRate,
        );
    }

    // --- The Queue ---

    public function testSeniorityDecidesHowMuchComesBack(): void
    {
        $secured = $this->recovery(Bond::SENIORITY_SENIOR_SECURED, 0.018);
        $unsecured = $this->recovery(Bond::SENIORITY_SENIOR_UNSECURED, 0.018);
        $subordinated = $this->recovery(Bond::SENIORITY_SUBORDINATED, 0.018);

        $this->assertGreaterThan($unsecured, $secured);
        $this->assertGreaterThan($subordinated, $unsecured);
    }

    public function testAnIssueThatRecordedNoSeniorityIsTreatedAsTheOrdinaryCorporateBond(): void
    {
        // Senior unsecured is what a public corporate bond IS, so it is the right thing to assume about one
        // that did not say — and assuming anything safer would quietly flatter every such claim.
        $this->assertSame(
            CorporateDefaultService::RECOVERY_SENIOR_UNSECURED,
            CorporateDefaultService::baseRecoveryFor(null),
        );
        $this->assertSame(
            CorporateDefaultService::RECOVERY_SENIOR_UNSECURED,
            CorporateDefaultService::baseRecoveryFor('SOMETHING_UNKNOWN'),
        );
    }

    public function testASovereignSeniorityMapsToNothingExotic(): void
    {
        $this->assertSame(
            CorporateDefaultService::RECOVERY_SENIOR_SECURED,
            CorporateDefaultService::baseRecoveryFor(Bond::SENIORITY_SENIOR_SECURED),
        );
        $this->assertSame(
            CorporateDefaultService::RECOVERY_SUBORDINATED,
            CorporateDefaultService::baseRecoveryFor(Bond::SENIORITY_SUBORDINATED),
        );
    }

    // --- The Cycle ---

    public function testFailingDuringACreditCycleSettlesWorseThanFailingAlone(): void
    {
        // The correlation that makes a diversified credit book less safe than it looks: the losses arrive
        // together AND each one is worse than average.
        $alone = $this->recovery(Bond::SENIORITY_SENIOR_UNSECURED, 0.008);
        $inACycle = $this->recovery(Bond::SENIORITY_SENIOR_UNSECURED, 0.090);

        $this->assertGreaterThan($inACycle, $alone);
    }

    public function testTheOrderOfTheQueueSurvivesTheCycle(): void
    {
        // Seniority must not be overtaken by the cycle: a secured claim in a crisis still beats a
        // subordinated one in a boom would be too strong a claim, but within any one year the order holds.
        foreach ([0.005, 0.018, 0.05, 0.12] as $rate) {
            $this->assertGreaterThan(
                $this->recovery(Bond::SENIORITY_SUBORDINATED, $rate),
                $this->recovery(Bond::SENIORITY_SENIOR_SECURED, $rate),
                "at a default rate of {$rate}",
            );
        }
    }

    // --- Something Always Comes Back ---

    public function testACreditorNeverRecoversNothing(): void
    {
        // This is the line between a bond and the equity beneath it. Even in the worst year modelled, the
        // claim settles at something; the shares settle at zero.
        foreach ([Bond::SENIORITY_SENIOR_SECURED, Bond::SENIORITY_SENIOR_UNSECURED, Bond::SENIORITY_SUBORDINATED] as $seniority) {
            $this->assertGreaterThanOrEqual(
                CreditRisk::MIN_RECOVERY_RATE,
                $this->recovery($seniority, 0.99),
                $seniority,
            );
        }
    }

    public function testACreditorNeverRecoversMoreThanTheClaim(): void
    {
        foreach ([1.0e-9, 0.001, 0.018] as $rate) {
            $this->assertLessThanOrEqual(1.0, $this->recovery(Bond::SENIORITY_SENIOR_SECURED, $rate));
        }
    }

    // --- Chapter 11 ---

    /**
     * A plan that leaves the old shareholders a stake has made every creditor whole. The listed notes settle at
     * par: at the market's 48 cents the bondholders would have funded the stake the equity kept.
     */
    public function testAPlanThatLeavesTheEquityAStakeSettlesTheNotesAtPar(): void
    {
        // 10B of claims (1B revolver, 9B notes) against 12B of assets; 6B reinstated, so the 6B of reorganized
        // equity splits 2B to the old holders (a third) and 4B to the creditors, who receive 6 + 4 = 10B.
        $plan = new ReorganizationPlanDTO(claims: 10.0e9, exitDebt: 6.0e9, convertedClaims: 4.0e9, oldEquityShare: 1.0 / 3.0, reorganizedEquityValue: 6.0e9, exitRate: 0.08, revolverClaim: 1.0e9);
        [$service, $paid] = $this->serviceSettling([$this->listedNote('NOTE-A', 2.0e9), $this->listedNote('NOTE-B', 1.5e9)]);

        $settled = $service->settle(new Stock(), new MacroStateDTO(corporateDefaultRateEma: 0.05), new \DateTime(), $plan);

        $this->assertEqualsWithDelta(1.0, $settled[0]['recovery'], 1e-12);
        $this->assertEqualsWithDelta(1.0, $settled[1]['recovery'], 1e-12);
        $this->assertEqualsWithDelta(FinancialConstants::BOND_FACE_VALUE, $paid['NOTE-A'], 1e-6, 'each bond is paid its face');
    }

    /** An insolvent plan gives the creditors the whole firm, the revolver first; the notes take what is left of it. */
    public function testAnInsolventPlanPaysTheRevolverFirstAndTheNotesTheRest(): void
    {
        // 5B of assets against 2B of revolver and 8B of notes: the revolver is whole and the notes split 3B.
        $plan = new ReorganizationPlanDTO(claims: 10.0e9, exitDebt: 3.0e9, convertedClaims: 7.0e9, oldEquityShare: 0.0, reorganizedEquityValue: 2.0e9, exitRate: 0.08, revolverClaim: 2.0e9);
        [$service] = $this->serviceSettling([$this->listedNote('NOTE-A', 4.0e9)]);

        $settled = $service->settle(new Stock(), new MacroStateDTO(corporateDefaultRateEma: 0.018), new \DateTime(), $plan);

        $this->assertEqualsWithDelta(3.0 / 8.0, $settled[0]['recovery'], 1e-12);
    }

    /** A liquidation has no plan to value the estate, so it settles at the market's recovery for the cycle. */
    public function testALiquidationStillSettlesAtTheCyclesRecovery(): void
    {
        [$service] = $this->serviceSettling([$this->listedNote('NOTE-A', 4.0e9)]);

        $settled = $service->settle(new Stock(), new MacroStateDTO(corporateDefaultRateEma: 0.05), new \DateTime());

        $this->assertEqualsWithDelta($this->recovery(Bond::SENIORITY_SENIOR_UNSECURED, 0.05), $settled[0]['recovery'], 1e-12);
    }

    private function listedNote(string $ticker, float $outstandingFace): Bond
    {
        return (new Bond())
            ->setTicker($ticker)
            ->setSeniority(Bond::SENIORITY_SENIOR_UNSECURED)
            ->setFaceValue((string) FinancialConstants::BOND_FACE_VALUE)
            ->setOutstandingFace((string) $outstandingFace)
            ->setStatus(Bond::STATUS_ACTIVE);
    }

    /**
     * @param list<Bond> $bonds
     * @return array{0: CorporateDefaultService, 1: \ArrayObject<string, float>} The service, and the cash it paid per bond by ticker.
     */
    private function serviceSettling(array $bonds): array
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturn($bonds);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        /** @var \ArrayObject<string, float> $paid */
        $paid = new \ArrayObject();
        $ledger = $this->createStub(BondLedgerService::class);
        $ledger->method('processDefaultSettlement')->willReturnCallback(static function (Bond $bond, float $perBond) use ($paid): void {
            $paid[$bond->getTicker()] = $perBond;
        });

        return [new CorporateDefaultService($em, $ledger), $paid];
    }

    public function testRecoveryIsAShareOfFaceSoTheCashIsFaceTimesIt(): void
    {
        $recovery = $this->recovery(Bond::SENIORITY_SENIOR_UNSECURED, \App\Service\Math\MacroConstants::CORPORATE_DEFAULT_LONG_RUN_MEAN);
        $face = FinancialConstants::BOND_FACE_VALUE;

        $this->assertEqualsWithDelta($face * CorporateDefaultService::RECOVERY_SENIOR_UNSECURED, $face * $recovery, 1e-6);
        $this->assertLessThan($face, $face * $recovery);
        $this->assertGreaterThan(0.0, $face * $recovery);
    }
}
