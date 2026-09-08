<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\DTO\MacroStateDTO;
use App\EventSubscriber\EarningsReportSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pins EarningsReportSubscriber::resolveStreamMacroDrivers() against the real stream keys each
 * financial business model emits (App\Service\Model\Sector\*BusinessModel::calculateSectorPhysics).
 *
 * This is a routing test, not a formula test: every (business model, stream key) pair a model can
 * legitimately produce must resolve to a model-specific driver set, never silently fall through to
 * the generic `default:` branch. Guards against two defects found in review: `case 'asset_management'`
 * being dead (the canonical registry identifier is `asset_manager`, see BusinessModelRegistry::CLASS_MAP)
 * and `hedge_fund` being absent from the switch entirely.
 */
#[AllowMockObjectsWithoutExpectations]
class StreamMacroDriverRoutingTest extends TestCase
{
    private const GENERIC_FALLBACK_LABEL = 'Macro Output Gap Demand';

    private EarningsReportSubscriber $subscriber;
    private ReflectionMethod $resolveDrivers;
    private MacroStateDTO $stressedMacro;

    protected function setUp(): void
    {
        /** @var EntityManagerInterface&MockObject $entityManager */
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $this->subscriber = new EarningsReportSubscriber($entityManager);
        $this->resolveDrivers = new ReflectionMethod(EarningsReportSubscriber::class, 'resolveStreamMacroDrivers');

        // Values chosen so every branch's conditional sub-drivers (credit spread, retail default,
        // inflation, etc.) also fire, not just the unconditional first driver in each case.
        $this->stressedMacro = new MacroStateDTO(
            outputGapEma: 0.03,
            inflationEma: 0.035,
            yield2yEma: 0.035,
            yield10yEma: 0.045,
            interbankLiquiditySpreadEma: 0.001,
            macroCreditSpreadEma: 0.03,
            retailDefaultRateEma: 0.03,
            marketVolatilityEma: 0.22,
            policyRateEma: 0.03,
            consumerSentimentIndexEma: 108.0,
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}> business model identifier => [model, stream key]
     */
    public static function financialStreamKeyProvider(): array
    {
        // The real stream keys each financial model emits, per calculateSectorPhysics(). Conditional
        // keys (marked in the model source as only present when a per-company ModelParam weight > 0)
        // are included: the routing bug does not depend on whether a given seeded stock happens to
        // trigger them.
        $streamsByModel = [
            'commercial_bank' => ['net_interest_income', 'fee_income', 'proprietary_dividend'],
            'credit_services' => ['lending', 'swipe'],
            'shadow_bank' => ['origination_fees', 'direct_lending'],
            'investment_bank' => ['advisory', 'trading', 'options_premium_income'],
            'brokerage' => ['trading', 'advisory'],
            'clearing_house' => ['clearing_fees', 'custody_float', 'data_licensing'],
            'asset_manager' => ['base_fee', 'alpha'],
            'private_equity' => ['management_fees', 'carried_interest', 'principal_investments'],
            'hedge_fund' => ['management_fees', 'directional_bets', 'quant_alpha'],
            'distressed_debt' => ['restructuring_advisory', 'turnaround_recovery', 'loan_to_own'],
            'insurance' => ['premium_revenue'],
            'reinsurance' => ['treaty_reinsurance', 'catastrophe_bonds'],
            'retail_insurance' => ['property_casualty_premiums', 'life_insurance_premiums'],
        ];

        $cases = [];
        foreach ($streamsByModel as $businessModel => $streamKeys) {
            foreach ($streamKeys as $streamKey) {
                $cases["{$businessModel}:{$streamKey}"] = [$businessModel, $streamKey];
            }
        }

        return $cases;
    }

    #[DataProvider('financialStreamKeyProvider')]
    public function testEveryRealFinancialStreamKeyRoutesToAModelSpecificDriver(string $businessModel, string $streamKey): void
    {
        $drivers = $this->resolveDrivers->invoke(
            $this->subscriber,
            $businessModel,
            $streamKey,
            $this->stressedMacro,
            1.0,
            0.20,
        );

        $this->assertNotEmpty(
            $drivers,
            "resolveStreamMacroDrivers() returned no drivers for {$businessModel}/{$streamKey} — it fell through to an unmatched branch."
        );

        $labels = array_column($drivers, 'label');
        $this->assertNotContains(
            self::GENERIC_FALLBACK_LABEL,
            $labels,
            "resolveStreamMacroDrivers() gave {$businessModel}/{$streamKey} the generic default-case driver instead of a model-specific one."
        );

        foreach ($drivers as $driver) {
            $this->assertArrayHasKey('label', $driver);
            $this->assertArrayHasKey('impact', $driver);
            $this->assertArrayHasKey('type', $driver);
            $this->assertSame('macro', $driver['type']);
            $this->assertIsFloat($driver['impact']);
            $this->assertTrue(is_finite($driver['impact']), "Driver impact for {$businessModel}/{$streamKey} must be finite.");
        }
    }

    /**
     * `asset_management` is only a BusinessModelRegistry alias for `asset_manager`
     * (see BusinessModelRegistry::__construct) — EarningsSimulationContext::$businessModel is always
     * populated with the canonical identifier, never the alias. A switch case keyed on the alias
     * would therefore be silently dead code; this pins that the alias correctly falls through to the
     * generic `default:` branch rather than being (re-)matched by a case of its own.
     */
    public function testAliasIdentifierAssetManagementFallsThroughToTheGenericDefaultCase(): void
    {
        $drivers = $this->resolveDrivers->invoke(
            $this->subscriber,
            'asset_management',
            'base_fee',
            $this->stressedMacro,
            1.0,
            0.20,
        );

        $labels = array_column($drivers, 'label');
        $this->assertContains(
            self::GENERIC_FALLBACK_LABEL,
            $labels,
            'The registry alias "asset_management" must never be used as a switch case — route on the canonical "asset_manager" identifier instead.'
        );
        $this->assertNotContains(
            'Committed AUM Capital Base',
            $labels,
            'The alias must not accidentally match the asset_manager-specific driver.'
        );
    }
}
