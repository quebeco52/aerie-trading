<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\DTO\GovernmentPolicyDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroTransmission;
use App\Service\Math\Valuation;
use App\Service\Model\Sector\UtilityBusinessModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Covers what the economy does with the policy the government hands it: the levers go into force as handed over, a
 * tariff's change costs productivity (Furceri, Hannan, Ostry & Rose 2018).
 */
class GovernmentPolicyTest extends TestCase
{
    private MacroEngine $engine;
    private ReflectionMethod $enactPolicy;

    protected function setUp(): void
    {
        // The policy step reads and writes MacroState only, so no collaborators need to be wired up.
        $this->engine = (new ReflectionClass(MacroEngine::class))->newInstanceWithoutConstructor();
        $this->enactPolicy = new ReflectionMethod(MacroEngine::class, 'enactPolicy');
    }

    public function testTheLeversAndThePulseGoIntoForceAsHandedOver(): void
    {
        $state = new MacroState();

        $this->enact($state, tariff: 0.0, corporateTax: -0.02, laborGrowth: 0.007, leniency: 0.6, pulse: 0.4, spending: 0.23);

        $this->assertSame(0.23, $state->publicSpendingShare);
        $this->assertSame(-0.02, $state->corporateTaxPolicyShift);
        $this->assertSame(0.007, $state->laborForceGrowthRate);
        $this->assertSame(0.6, $state->mergerReviewLeniency);
        $this->assertSame(0.4, $state->electionPulse);
        $this->assertSame(0.0, $state->tfpShockLevel, 'No tariff moved, so no productivity did.');
    }

    /** The Regulator's mortgage cap goes into force as handed over, and a policy without one lifts it. */
    public function testTheMortgageCapGoesIntoForceAsHandedOver(): void
    {
        $state = new MacroState();
        $this->assertNull($state->mortgageLtvCap, 'The District opens with no cap, as the US runs none.');

        $this->enactPolicyDto($state, new GovernmentPolicyDTO(
            publicSpendingShare: MacroEngine::PUBLIC_PURCHASES_TO_GDP,
            corporateTaxPolicyShift: 0.0,
            importTariffRate: 0.0,
            laborForceGrowthRate: MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE,
            foreignBuyerTaxRate: 0.0,
            mergerReviewLeniency: 0.0,
            greenBeltStringency: 0.0,
            carbonPrice: 0.0,
            extractionStringency: 0.0,
            stampDutyRate: FinancialConstants::STAMP_DUTY_RATE,
            bankLevyRate: 0.0,
            reserveDrawShare: MacroEngine::RESERVE_DRAW_CEILING,
            consolidationTaxShare: CreditFiscalSubsystem::FOUNDING_CONSOLIDATION_TAX_SHARE,
            reserveHomeEquityShare: SovereignFundSubsystem::OPENING_HOME_EQUITY_SHARE,
            utilityRateCaseGrantShare: UtilityBusinessModel::APPOINTED_COMMISSION_GRANTED_SHARE,
            dividendTaxRate: Valuation::FOUNDING_DIVIDEND_TAX_RATE,
            electionPulse: 0.0,
            mortgageLtvCap: 0.85,
        ));
        $this->assertSame(0.85, $state->mortgageLtvCap);

        $this->enact($state, tariff: 0.0);
        $this->assertNull($state->mortgageLtvCap);
    }

    public function testATariffCostsProductivityAndItsRepealGivesItBack(): void
    {
        $state = new MacroState();
        $index = $state->totalFactorProductivityIndex;

        $this->enact($state, tariff: 0.1);
        $loss = -MacroEngine::TARIFF_OUTPUT_LOSS * 0.1;
        $this->assertSame(0.1, $state->importTariffRate);
        $this->assertEqualsWithDelta($loss, $state->tfpShockLevel, 1e-15, 'The level potential absorbs falls by the output loss,');
        $this->assertEqualsWithDelta($index * exp($loss), $state->totalFactorProductivityIndex, 1e-9, 'and productivity with it.');

        $this->enact($state, tariff: 0.1);
        $this->assertEqualsWithDelta($loss, $state->tfpShockLevel, 1e-15, 'A tariff already in force costs nothing more.');

        $this->enact($state, tariff: 0.0);
        $this->assertEqualsWithDelta(0.0, $state->tfpShockLevel, 1e-15, 'Repeal gives it back.');
        $this->assertEqualsWithDelta($index, $state->totalFactorProductivityIndex, 1e-9);
    }

    /** The Securities Commission's rules go in as handed over, and with none from it the founding ones stand, not the last in force. */
    public function testTheCommissionsRulesGoIntoForceAndNoneMeansTheFoundingOnes(): void
    {
        $state = new MacroState();
        $rules = new GovernmentPolicyDTO(
            publicSpendingShare: MacroEngine::PUBLIC_PURCHASES_TO_GDP,
            corporateTaxPolicyShift: 0.0,
            importTariffRate: 0.0,
            laborForceGrowthRate: MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE,
            foreignBuyerTaxRate: 0.0,
            mergerReviewLeniency: 0.0,
            greenBeltStringency: 0.0,
            carbonPrice: 0.0,
            extractionStringency: 0.0,
            stampDutyRate: FinancialConstants::STAMP_DUTY_RATE,
            bankLevyRate: 0.0,
            reserveDrawShare: MacroEngine::RESERVE_DRAW_CEILING,
            consolidationTaxShare: CreditFiscalSubsystem::FOUNDING_CONSOLIDATION_TAX_SHARE,
            reserveHomeEquityShare: SovereignFundSubsystem::OPENING_HOME_EQUITY_SHARE,
            utilityRateCaseGrantShare: UtilityBusinessModel::APPOINTED_COMMISSION_GRANTED_SHARE,
            dividendTaxRate: Valuation::FOUNDING_DIVIDEND_TAX_RATE,
            electionPulse: 0.0,
            initialMarginRequirement: 0.7,
            earningsManagementScale: 0.6,
        );
        $this->enactPolicyDto($state, $rules);
        $this->assertSame(0.7, $state->initialMarginRequirement);
        $this->assertSame(0.6, $state->earningsManagementScale);

        $this->enact($state, tariff: 0.0);
        $this->assertSame(FinancialConstants::INITIAL_MARGIN_REQUIREMENT, $state->initialMarginRequirement);
        $this->assertSame(1.0, $state->earningsManagementScale);
    }

    /** The market's forecast goes in as handed over, the one before it kept for the tick, so a revision can be seen; with no forecast none is carried. */
    public function testTheMarketsForecastGoesInWithTheOneBefore(): void
    {
        $state = new MacroState();
        $forecast = static fn(?array $levers, ?float $from): GovernmentPolicyDTO => new GovernmentPolicyDTO(
            publicSpendingShare: MacroEngine::PUBLIC_PURCHASES_TO_GDP,
            corporateTaxPolicyShift: 0.01,
            importTariffRate: 0.0,
            laborForceGrowthRate: MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE,
            foreignBuyerTaxRate: 0.0,
            mergerReviewLeniency: 0.0,
            greenBeltStringency: 0.0,
            carbonPrice: 0.0,
            extractionStringency: 0.0,
            stampDutyRate: FinancialConstants::STAMP_DUTY_RATE,
            bankLevyRate: 0.0005,
            reserveDrawShare: 0.55,
            consolidationTaxShare: 0.30,
            reserveHomeEquityShare: 0.10,
            utilityRateCaseGrantShare: 0.40,
            dividendTaxRate: 0.30,
            electionPulse: 0.0,
            expectedLevers: $levers,
            expectedPolicyFrom: $from,
        );

        $this->enactPolicyDto($state, $forecast(null, null));
        $this->assertSame(0.55, $state->reserveDrawShare, 'The share of the fund\'s return spent goes in as passed.');
        $this->assertSame(0.30, $state->consolidationTaxShare, 'So does the share of a deficit cut raised by tax.');
        $this->assertSame(0.10, $state->reserveHomeEquityShare, 'And the fund\'s home share.');
        $this->assertSame(0.40, $state->utilityRateCaseGrantShare, 'And what the utility commission grants.');
        $this->assertSame(0.30, $state->dividendTaxRate, 'And the tax on dividends.');
        $this->assertSame([], $state->expectedLevers);
        $this->assertSame(-1.0, $state->expectedPolicyFrom);

        $levers = ['corporateTax' => 0.04, 'bankLevyRate' => 0.002, 'extractionStringency' => 0.5, 'stampDutyRate' => 0.001];
        $this->enactPolicyDto($state, $forecast($levers, 8.5));
        $this->assertSame($levers, $state->expectedLevers);
        $this->assertSame(8.5, $state->expectedPolicyFrom);
        $this->assertSame([], $state->previousExpectedLevers);
        $this->assertSame(-1.0, $state->previousExpectedPolicyFrom);

        $this->enactPolicyDto($state, $forecast($levers, 8.5));
        $this->assertSame($levers, $state->previousExpectedLevers, 'No revision, nothing to reprice.');
        $this->assertSame(8.5, $state->previousExpectedPolicyFrom);

        // The lever maps survive the trip through Redis.
        $this->assertSame($levers, MacroState::fromArray($state->toArray())->previousExpectedLevers);
    }

    /** A state from before the lags has its earnings already carrying the laws in force. */
    public function testAStateFromBeforeTheLagsCarriesTheLawsInForce(): void
    {
        $payload = (new MacroState())->toArray();
        $payload['corporate_tax_policy_shift'] = 0.03;
        $payload['bank_levy_rate'] = 0.001;
        $payload['extraction_stringency'] = 0.5;
        $payload['stamp_duty_rate'] = 0.002;
        unset(
            $payload['corporate_tax_shift_realized'],
            $payload['corporate_tax_shift_embodied'],
            $payload['bank_levy_embodied'],
            $payload['extraction_cost_factor_embodied'],
            $payload['stamp_duty_volume_factor_embodied'],
        );

        $state = MacroState::fromArray($payload);
        $this->assertSame(0.03, $state->corporateTaxShiftRealized);
        $this->assertSame(0.03, $state->corporateTaxShiftEmbodied);
        $this->assertSame(0.001, $state->bankLevyEmbodied);
        $this->assertSame(FirmEconomics::calculateExtractionCostFactor(0.5), $state->extractionCostFactorEmbodied);
        $this->assertSame(MacroTransmission::calculateStampDutyVolumeFactor(0.002), $state->stampDutyVolumeFactorEmbodied);
    }

    private function enactPolicyDto(MacroState $state, GovernmentPolicyDTO $policy): void
    {
        $this->enactPolicy->invokeArgs($this->engine, [$state, $policy]);
    }

    private function enact(MacroState $state, float $tariff, float $corporateTax = 0.0, float $laborGrowth = MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE, float $leniency = 0.0, float $pulse = 0.0, float $spending = MacroEngine::PUBLIC_PURCHASES_TO_GDP): void
    {
        $this->enactPolicy->invokeArgs($this->engine, [$state, new GovernmentPolicyDTO(
            publicSpendingShare: $spending,
            corporateTaxPolicyShift: $corporateTax,
            importTariffRate: $tariff,
            laborForceGrowthRate: $laborGrowth,
            foreignBuyerTaxRate: 0.0,
            mergerReviewLeniency: $leniency,
            greenBeltStringency: 0.0,
            carbonPrice: 0.0,
            extractionStringency: 0.0,
            stampDutyRate: FinancialConstants::STAMP_DUTY_RATE,
            bankLevyRate: 0.0,
            reserveDrawShare: MacroEngine::RESERVE_DRAW_CEILING,
            consolidationTaxShare: CreditFiscalSubsystem::FOUNDING_CONSOLIDATION_TAX_SHARE,
            reserveHomeEquityShare: SovereignFundSubsystem::OPENING_HOME_EQUITY_SHARE,
            utilityRateCaseGrantShare: UtilityBusinessModel::APPOINTED_COMMISSION_GRANTED_SHARE,
            dividendTaxRate: Valuation::FOUNDING_DIVIDEND_TAX_RATE,
            electionPulse: $pulse,
        )]);
    }
}
