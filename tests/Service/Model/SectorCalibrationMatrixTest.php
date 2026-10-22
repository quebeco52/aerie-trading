<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Service\Model\BusinessModelInterface;
use App\Service\Model\Sector\ConstructionBusinessModel;
use App\Service\Model\Sector\ConsumerStaplesBusinessModel;
use App\Service\Model\Sector\DefenseContractorBusinessModel;
use App\Service\Model\Sector\LawFirmBusinessModel;
use App\Service\Model\Sector\LuxuryBusinessModel;
use App\Service\Model\Sector\OilGasProducerBusinessModel;
use App\Service\Model\Sector\RefiningBusinessModel;
use App\Service\Model\Sector\ReitBusinessModel;
use App\Service\Model\Sector\RestaurantBusinessModel;
use App\Service\Model\Sector\SemiconductorBusinessModel;
use App\Service\Model\Sector\ShippingBusinessModel;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use App\Service\Model\Sector\TelecomBusinessModel;
use App\Service\Model\Sector\UtilityBusinessModel;
use App\Tests\Support\Model\HookConstant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Cross-sectional calibration of the sector constants.
 *
 * The rest of the model suite is directional: it checks that a shock moves a number the right way. Nothing
 * checked the LEVEL a sector is tuned to, so a constant could be nudged to make one test pass and silently
 * leave the sector describing a different industry. This fixes each declared constant inside a band, and
 * then pins the orderings between sectors that carry actual economic content — a utility must be less
 * cyclical than a shipping line, a commodity producer must have less pricing power than a luxury house —
 * because a plausible-looking constant can still be wrong relative to its neighbours.
 *
 * These are the declared calibration, not a simulated steady state: they are what the physics is handed
 * before any quarter is run, which is where a mis-tuning enters.
 */
final class SectorCalibrationMatrixTest extends TestCase
{
    /**
     * Plausible range for each sector constant, with the real-world reading that anchors it.
     *
     * @var array<string, array{float, float, string}>
     */
    private const BANDS = [
        // A firm whose volumes barely move with the cycle (regulated power) up to one that swings well over
        // twice the output gap (dry bulk shipping, memory semiconductors).
        'OPERATING_CYCLICALITY' => [0.20, 2.00, 'elasticity of volumes to the output gap'],
        // A pure price taker at a spot exchange through to a house that sets its own price list.
        'PRICING_POWER_INDEX' => [0.00, 1.00, 'share of an input move recovered in selling price'],
        // Own-price elasticity: inelastic staples and patented drugs near zero, discretionary goods above one.
        'PRICE_ELASTICITY_OF_DEMAND' => [0.00, 2.50, 'volume lost per unit of real price increase'],
        // Purely domestic through to a firm whose entire book is priced in someone else's currency.
        'FX_REVENUE_EXPOSURE' => [0.00, 1.00, 'share of revenue exposed to the trade-weighted rate'],
        // A spot business feels the cycle immediately; the longest order books run a few years.
        'DEMAND_LAG_YEARS' => [0.00, 5.00, 'years for the output gap to reach the order book'],
        // Payroll share of the fixed cost base: capital-intensive property at the bottom, professional services at the
        // top.
        'FIXED_COST_LABOR_SHARE' => [0.10, 0.95, 'payroll share of the fixed cost base'],
        // Capitalised operating leases: an owner-operator carries none, a franchised restaurant estate carries a lot.
        'LEASE_LIABILITY_INTENSITY' => [0.00, 0.80, 'capitalised operating leases as a share of the asset base'],
        // Share of an idiosyncratic gain taken from same-industry peers rather than won from a larger market.
        'INDUSTRY_SUBSTITUTABILITY' => [0.00, 1.00, 'share of a revenue gain taken from direct peers'],
    ];

    /**
     * @return array<string, array{class-string<BusinessModelInterface>}>
     */
    public static function sectorModelProvider(): array
    {
        $dir = dirname(__DIR__, 3) . '/src/Service/Model/Sector';
        $files = glob($dir . '/*BusinessModel.php');
        self::assertNotFalse($files, "Could not list the sector models in {$dir}.");

        $models = [];
        foreach ($files as $file) {
            $className = 'App\\Service\\Model\\Sector\\' . basename($file, '.php');
            self::assertTrue(is_subclass_of($className, BusinessModelInterface::class), "{$className} is not a business model.");

            $reflection = new ReflectionClass($className);
            if ($reflection->isAbstract()) {
                continue;
            }

            $models[basename($file, '.php')] = [$className];
        }

        return $models;
    }

    /**
     * The financial models, whose earnings are a spread on a balance sheet rather than a price on a product.
     *
     * @return array<string, array{class-string<BusinessModelInterface>}>
     */
    public static function financialModelProvider(): array
    {
        $models = [];
        foreach (self::sectorModelProvider() as $name => [$modelClass]) {
            if ((new $modelClass())->isFinancial()) {
                $models[$name] = [$modelClass];
            }
        }

        return $models;
    }

    /**
     * Every constant a sector declares, and every default it inherits, has to describe an industry that could exist.
     *
     * @param class-string<BusinessModelInterface> $modelClass
     */
    #[DataProvider('sectorModelProvider')]
    public function testDeclaredConstantsSitInsideTheirPlausibleBand(string $modelClass): void
    {
        $declared = 0;

        foreach (self::BANDS as $constant => [$min, $max, $meaning]) {
            $value = constant("{$modelClass}::{$constant}");
            if ($value === null || is_array($value)) {
                continue;
            }

            if (HookConstant::isRedeclared($modelClass, $constant)) {
                $declared++;
            }
            $value = (float) $value;

            $this->assertGreaterThanOrEqual($min, $value, "{$modelClass}::{$constant} ({$meaning}) is below the plausible range.");
            $this->assertLessThanOrEqual($max, $value, "{$modelClass}::{$constant} ({$meaning}) is above the plausible range.");
        }

        // The standard corporate model is the base's defaults; every sector pins its identity with its own constants.
        if ($modelClass !== StandardCorporateBusinessModel::class) {
            $this->assertGreaterThan(0, $declared, "{$modelClass} declares no calibration constants at all, so nothing pins its sector identity.");
        }
    }

    /**
     * Financial models are excluded from the operating-physics constants by design: a bank's earnings come
     * from a spread on a balance sheet, not from a price it charges against an input basket. This pins that
     * exclusion so a sweep cannot quietly hand a bank a pricing-power index and change how it earns.
     *
     * @param class-string<BusinessModelInterface> $modelClass
     */
    #[DataProvider('financialModelProvider')]
    public function testFinancialModelsDeclareNoOperatingPriceConstants(string $modelClass): void
    {
        foreach (['PRICING_POWER_INDEX', 'PRICE_ELASTICITY_OF_DEMAND'] as $constant) {
            $this->assertFalse(
                HookConstant::isRedeclared($modelClass, $constant),
                "{$modelClass} is a financial model and must not declare {$constant}: its earnings are a spread, not a price.",
            );
        }
    }

    /**
     * A price setter faces less elastic demand than a price taker. The relationship is loose across a real
     * cross-section — a defence contractor is inelastic for reasons unrelated to pricing power — but its
     * sign is not negotiable, and a sweep that broke it would have inverted the demand response.
     */
    public function testPricingPowerAndDemandElasticityAreInverselyRelated(): void
    {
        $pairs = [];
        foreach (self::sectorModelProvider() as [$modelClass]) {
            if (HookConstant::isRedeclared($modelClass, 'PRICING_POWER_INDEX')) {
                $pairs[] = [(float) constant("{$modelClass}::PRICING_POWER_INDEX"), (float) constant("{$modelClass}::PRICE_ELASTICITY_OF_DEMAND")];
            }
        }

        $this->assertGreaterThan(20, count($pairs), 'The cross-section must be wide enough for the correlation to mean anything.');

        $count = count($pairs);
        $meanPower = array_sum(array_column($pairs, 0)) / $count;
        $meanElasticity = array_sum(array_column($pairs, 1)) / $count;

        $covariance = 0.0;
        $powerVariance = 0.0;
        $elasticityVariance = 0.0;
        foreach ($pairs as [$power, $elasticity]) {
            $covariance += ($power - $meanPower) * ($elasticity - $meanElasticity);
            $powerVariance += ($power - $meanPower) ** 2;
            $elasticityVariance += ($elasticity - $meanElasticity) ** 2;
        }

        $correlation = $covariance / sqrt($powerVariance * $elasticityVariance);

        $this->assertLessThan(-0.15, $correlation, 'Sectors with pricing power must, on the whole, face less elastic demand.');
    }

    /**
     * Cyclicality has to reproduce the sector ordering an analyst would recognise. Each pair below is a
     * claim about the real world, not a restatement of the constants.
     */
    public function testCyclicalityOrderingMatchesRealSectorBehaviour(): void
    {
        // Regulated power demand barely moves with the cycle; dry bulk freight is the textbook cyclical.
        $this->assertLessThan(
            ShippingBusinessModel::OPERATING_CYCLICALITY,
            UtilityBusinessModel::OPERATING_CYCLICALITY,
            'A regulated utility must be far less cyclical than a shipping line.',
        );
        // Groceries are bought in a recession; casino floors and new cars are not.
        $this->assertLessThan(
            LuxuryBusinessModel::OPERATING_CYCLICALITY,
            ConsumerStaplesBusinessModel::OPERATING_CYCLICALITY,
            'Staples must be more defensive than luxury goods.',
        );
        // Multi-year appropriated programmes do not track the private cycle.
        $this->assertLessThan(
            SemiconductorBusinessModel::OPERATING_CYCLICALITY,
            DefenseContractorBusinessModel::OPERATING_CYCLICALITY,
            'A defence contractor on appropriated programmes must be less cyclical than the memory cycle.',
        );
        // Long leases insulate a landlord from the quarter its tenants are having.
        $this->assertLessThan(
            RestaurantBusinessModel::OPERATING_CYCLICALITY,
            ReitBusinessModel::OPERATING_CYCLICALITY,
            'A leased property book must be steadier than the restaurants inside it.',
        );
        // A producer sells every barrel into a global pool at the going price; a refinery's runs follow the
        // fuel demand of the economy it supplies.
        $this->assertLessThan(
            RefiningBusinessModel::OPERATING_CYCLICALITY,
            OilGasProducerBusinessModel::OPERATING_CYCLICALITY,
            'An oil producer\'s volume must answer the domestic cycle less than a refinery\'s runs do.',
        );

        // The whole non-financial cross-section has to sit around unit cyclicality rather than drifting.
        $cyclicalities = [];
        foreach (self::sectorModelProvider() as [$modelClass]) {
            $cyclicalities[] = (float) constant("{$modelClass}::OPERATING_CYCLICALITY");
        }
        $mean = array_sum($cyclicalities) / count($cyclicalities);
        $this->assertGreaterThan(0.80, $mean, 'The average sector must move roughly with the economy.');
        $this->assertLessThan(1.30, $mean, 'The market as a whole cannot be systematically more cyclical than the cycle.');
    }

    /**
     * Substitutability, lease intensity and labour share each encode a structural fact about the sector,
     * and each has an unambiguous extreme that pins the scale.
     */
    public function testStructuralIntensitiesMatchTheirRealCounterparts(): void
    {
        // A regulated monopoly has no peer to lose a customer to; carriers do nothing but take each other's.
        $this->assertSame(0.0, (float) UtilityBusinessModel::INDUSTRY_SUBSTITUTABILITY, 'A regulated monopoly has no direct substitute.');
        $this->assertGreaterThan(
            (float) UtilityBusinessModel::INDUSTRY_SUBSTITUTABILITY,
            (float) TelecomBusinessModel::INDUSTRY_SUBSTITUTABILITY,
            'Telecom growth is almost entirely share taken from carriers.',
        );
        // "Conglomerates" is a listing convention, not a product market: listed groups sell nothing to each other's
        // customers.
        $this->assertSame(0.0, (float) \App\Service\Model\Sector\ConglomerateBusinessModel::INDUSTRY_SUBSTITUTABILITY, 'Unrelated groups take no share from one another.');

        // A REIT owns its property outright; a restaurant estate is leased almost in full.
        $this->assertSame(0.0, (float) ReitBusinessModel::LEASE_LIABILITY_INTENSITY, 'A landlord owns the building rather than leasing it.');
        $this->assertGreaterThan(
            (float) ReitBusinessModel::LEASE_LIABILITY_INTENSITY,
            (float) RestaurantBusinessModel::LEASE_LIABILITY_INTENSITY,
            'A restaurant estate is a lease book.',
        );
        $this->assertGreaterThan(
            (float) UtilityBusinessModel::LEASE_LIABILITY_INTENSITY,
            (float) RestaurantBusinessModel::LEASE_LIABILITY_INTENSITY,
            'A utility owns its grid; a restaurant rents its sites.',
        );

        // A law firm is people; a property book is capital with a small management team on top. Both shares are
        // BEA compensation over the industry's operating cost base (partners' draws are mixed income, outside it).
        $this->assertGreaterThan(
            (float) ReitBusinessModel::INPUT_COST_EXPOSURES['labor'],
            (float) LawFirmBusinessModel::INPUT_COST_EXPOSURES['labor'],
            'Professional services are payroll; a property book is capital.',
        );
        $this->assertGreaterThan(0.5, (float) LawFirmBusinessModel::INPUT_COST_EXPOSURES['labor'], 'Most of what a law firm spends is pay.');
        $this->assertLessThan(0.2, (float) ReitBusinessModel::INPUT_COST_EXPOSURES['labor'], 'Property upkeep is bought in, not staffed.');
    }

    /**
     * The transmission lag is the length of the order book: a spot seller feels the cycle the week it turns,
     * a builder is still delivering against contracts signed before it began.
     */
    public function testDemandTransmissionLagOrderingMatchesOrderBookLength(): void
    {
        $this->assertGreaterThan(
            (float) SemiconductorBusinessModel::DEMAND_LAG_YEARS,
            (float) ConstructionBusinessModel::DEMAND_LAG_YEARS,
            'A construction backlog outlasts a semiconductor order book by a wide margin.',
        );
        $this->assertGreaterThan(
            (float) SemiconductorBusinessModel::DEMAND_LAG_YEARS,
            (float) ReitBusinessModel::DEMAND_LAG_YEARS,
            'A signed lease ladder transmits the cycle more slowly than a chip order.',
        );

        // A restaurant is the spot case: it declares no lag at all and reads the macro gap directly.
        $this->assertFalse(
            HookConstant::isRedeclared(RestaurantBusinessModel::class, 'DEMAND_LAG_YEARS'),
            'A restaurant is a spot business and must read the cycle contemporaneously.',
        );
    }
}
