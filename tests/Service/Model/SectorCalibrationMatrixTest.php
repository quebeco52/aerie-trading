<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Service\Model\Sector\CommodityBusinessModel;
use App\Service\Model\Sector\ConsumerStaplesBusinessModel;
use App\Service\Model\Sector\ConstructionBusinessModel;
use App\Service\Model\Sector\DefenseContractorBusinessModel;
use App\Service\Model\Sector\FinancialDataBusinessModel;
use App\Service\Model\Sector\LawFirmBusinessModel;
use App\Service\Model\Sector\LuxuryBusinessModel;
use App\Service\Model\Sector\ReitBusinessModel;
use App\Service\Model\Sector\RestaurantBusinessModel;
use App\Service\Model\Sector\SemiconductorBusinessModel;
use App\Service\Model\Sector\ShippingBusinessModel;
use App\Service\Model\Sector\TechBusinessModel;
use App\Service\Model\Sector\TelecomBusinessModel;
use App\Service\Model\Sector\UtilityBusinessModel;
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
        // Structural margin ceiling: a distributor is a few points, property NOI and index data are most of revenue.
        'MAX_OPERATING_MARGIN_CEILING' => [0.05, 0.85, 'operating margin a modernized plant converges toward'],
        // Structural floor under sustained under-investment; a going concern still clears zero.
        'MIN_OPERATING_MARGIN_FLOOR' => [0.00, 0.25, 'operating margin a starved plant decays toward'],
        // Payroll share of the fixed cost base: capital-intensive property at the bottom, professional services at the top.
        'FIXED_COST_LABOR_SHARE' => [0.10, 0.95, 'payroll share of the fixed cost base'],
        // Capitalised operating leases: an owner-operator carries none, a franchised restaurant estate carries a lot.
        'LEASE_LIABILITY_INTENSITY' => [0.00, 0.80, 'capitalised operating leases as a share of the asset base'],
        // Share of an idiosyncratic gain taken from same-industry peers rather than won from a larger market.
        'INDUSTRY_SUBSTITUTABILITY' => [0.00, 1.00, 'share of a revenue gain taken from direct peers'],
    ];

    /**
     * @return array<string, array{class-string}>
     */
    public static function sectorModelProvider(): array
    {
        $models = [];
        foreach (glob(dirname(__DIR__, 3) . '/src/Service/Model/Sector/*BusinessModel.php') ?: [] as $file) {
            $className = 'App\\Service\\Model\\Sector\\' . basename($file, '.php');
            if (!class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);
            if ($reflection->isAbstract()) {
                continue;
            }

            $models[basename($file, '.php')] = [$className];
        }

        return $models;
    }

    /**
     * Models that declare both decay rails, so the bracket test has something real to check.
     *
     * @return array<string, array{class-string}>
     */
    public static function marginRailModelProvider(): array
    {
        return array_filter(
            self::sectorModelProvider(),
            static fn (array $row): bool => defined($row[0] . '::MIN_OPERATING_MARGIN_FLOOR') && defined($row[0] . '::MAX_OPERATING_MARGIN_CEILING')
        );
    }

    /**
     * The financial models, whose earnings are a spread on a balance sheet rather than a price on a product.
     *
     * @return array<string, array{class-string}>
     */
    public static function financialModelProvider(): array
    {
        return array_filter(
            self::sectorModelProvider(),
            static fn (array $row): bool => (new $row[0]())->isFinancial()
        );
    }

    /**
     * Every constant a sector declares has to describe an industry that could exist.
     */
    #[DataProvider('sectorModelProvider')]
    public function testDeclaredConstantsSitInsideTheirPlausibleBand(string $modelClass): void
    {
        $declared = 0;

        foreach (self::BANDS as $constant => [$min, $max, $meaning]) {
            if (!defined("{$modelClass}::{$constant}")) {
                continue;
            }

            $value = constant("{$modelClass}::{$constant}");
            if (is_array($value)) {
                continue;
            }

            $declared++;
            $value = (float) $value;

            $this->assertGreaterThanOrEqual($min, $value, "{$modelClass}::{$constant} ({$meaning}) is below the plausible range.");
            $this->assertLessThanOrEqual($max, $value, "{$modelClass}::{$constant} ({$meaning}) is above the plausible range.");
        }

        $this->assertGreaterThan(0, $declared, "{$modelClass} declares no calibration constants at all, so nothing pins its sector identity.");
    }

    /**
     * The margin rails must bracket a real range: a floor at or above the ceiling makes the decay physics
     * either a no-op or a one-way ratchet, depending on which branch the reinvestment ratio takes.
     */
    #[DataProvider('marginRailModelProvider')]
    public function testMarginRailsBracketARealRange(string $modelClass): void
    {
        $floor = (float) constant("{$modelClass}::MIN_OPERATING_MARGIN_FLOOR");
        $ceiling = (float) constant("{$modelClass}::MAX_OPERATING_MARGIN_CEILING");

        $this->assertLessThan($ceiling, $floor, "{$modelClass} declares a margin floor at or above its ceiling.");
        $this->assertGreaterThan(0.05, $ceiling - $floor, "{$modelClass} leaves the decay physics almost no room to move the margin.");
    }

    /**
     * Financial models are excluded from the operating-physics constants by design: a bank's earnings come
     * from a spread on a balance sheet, not from a price it charges against an input basket. This pins that
     * exclusion so a sweep cannot quietly hand a bank a pricing-power index and change how it earns.
     */
    #[DataProvider('financialModelProvider')]
    public function testFinancialModelsDeclareNoOperatingPriceConstants(string $modelClass): void
    {
        foreach (['PRICING_POWER_INDEX', 'PRICE_ELASTICITY_OF_DEMAND', 'MAX_OPERATING_MARGIN_CEILING', 'MIN_OPERATING_MARGIN_FLOOR'] as $constant) {
            $this->assertFalse(
                defined("{$modelClass}::{$constant}"),
                "{$modelClass} is a financial model and must not declare {$constant}: its earnings are a spread, not a price."
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
            if (defined("{$modelClass}::PRICING_POWER_INDEX") && defined("{$modelClass}::PRICE_ELASTICITY_OF_DEMAND")) {
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
            'A regulated utility must be far less cyclical than a shipping line.'
        );
        // Groceries are bought in a recession; casino floors and new cars are not.
        $this->assertLessThan(
            LuxuryBusinessModel::OPERATING_CYCLICALITY,
            ConsumerStaplesBusinessModel::OPERATING_CYCLICALITY,
            'Staples must be more defensive than luxury goods.'
        );
        // Multi-year appropriated programmes do not track the private cycle.
        $this->assertLessThan(
            SemiconductorBusinessModel::OPERATING_CYCLICALITY,
            DefenseContractorBusinessModel::OPERATING_CYCLICALITY,
            'A defence contractor on appropriated programmes must be less cyclical than the memory cycle.'
        );
        // Long leases insulate a landlord from the quarter its tenants are having.
        $this->assertLessThan(
            RestaurantBusinessModel::OPERATING_CYCLICALITY,
            ReitBusinessModel::OPERATING_CYCLICALITY,
            'A leased property book must be steadier than the restaurants inside it.'
        );

        // The whole non-financial cross-section has to sit around unit cyclicality rather than drifting.
        $cyclicalities = [];
        foreach (self::sectorModelProvider() as [$modelClass]) {
            if (defined("{$modelClass}::OPERATING_CYCLICALITY")) {
                $cyclicalities[] = (float) constant("{$modelClass}::OPERATING_CYCLICALITY");
            }
        }
        $mean = array_sum($cyclicalities) / count($cyclicalities);
        $this->assertGreaterThan(0.80, $mean, 'The average sector must move roughly with the economy.');
        $this->assertLessThan(1.30, $mean, 'The market as a whole cannot be systematically more cyclical than the cycle.');
    }

    /**
     * Structural margin ceilings have to reproduce where operating margin actually lives by industry.
     */
    public function testStructuralMarginCeilingsMatchRealSectorEconomics(): void
    {
        // Property NOI and index/data licensing are most of revenue; a defence programme is cost-plus.
        $this->assertGreaterThan(
            DefenseContractorBusinessModel::MAX_OPERATING_MARGIN_CEILING,
            ReitBusinessModel::MAX_OPERATING_MARGIN_CEILING,
            'Property net operating income must clear a cost-plus defence margin comfortably.'
        );
        $this->assertGreaterThan(
            TelecomBusinessModel::MAX_OPERATING_MARGIN_CEILING,
            FinancialDataBusinessModel::MAX_OPERATING_MARGIN_CEILING,
            'A data and index licensing business must out-earn a capital-intensive network.'
        );
        // Software has no unit cost; a restaurant buys food for every cover it serves.
        $this->assertGreaterThan(
            RestaurantBusinessModel::MAX_OPERATING_MARGIN_CEILING,
            TechBusinessModel::MAX_OPERATING_MARGIN_CEILING,
            'Zero marginal cost must beat a food cost line.'
        );
        // A spot producer's ceiling is set by the marginal cost of the last barrel it sells.
        $this->assertLessThan(
            LuxuryBusinessModel::MAX_OPERATING_MARGIN_CEILING,
            CommodityBusinessModel::MAX_OPERATING_MARGIN_CEILING,
            'A price taker cannot hold a luxury house margin.'
        );
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
            'Telecom growth is almost entirely share taken from carriers.'
        );

        // A REIT owns its property outright; a restaurant estate is leased almost in full.
        $this->assertSame(0.0, (float) ReitBusinessModel::LEASE_LIABILITY_INTENSITY, 'A landlord owns the building rather than leasing it.');
        $this->assertGreaterThan(
            (float) ReitBusinessModel::LEASE_LIABILITY_INTENSITY,
            (float) RestaurantBusinessModel::LEASE_LIABILITY_INTENSITY,
            'A restaurant estate is a lease book.'
        );
        $this->assertGreaterThan(
            (float) UtilityBusinessModel::LEASE_LIABILITY_INTENSITY,
            (float) RestaurantBusinessModel::LEASE_LIABILITY_INTENSITY,
            'A utility owns its grid; a restaurant rents its sites.'
        );

        // A law firm is people; a property book is capital with a small management team on top.
        $this->assertGreaterThan(
            (float) ReitBusinessModel::FIXED_COST_LABOR_SHARE,
            (float) LawFirmBusinessModel::FIXED_COST_LABOR_SHARE,
            'Professional services are payroll; a property book is capital.'
        );
        $this->assertGreaterThan(0.75, (float) LawFirmBusinessModel::FIXED_COST_LABOR_SHARE, 'A partnership is almost entirely payroll.');
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
            'A construction backlog outlasts a semiconductor order book by a wide margin.'
        );
        $this->assertGreaterThan(
            (float) SemiconductorBusinessModel::DEMAND_LAG_YEARS,
            (float) ReitBusinessModel::DEMAND_LAG_YEARS,
            'A signed lease ladder transmits the cycle more slowly than a chip order.'
        );

        // A restaurant is the spot case: it declares no lag at all and reads the macro gap directly.
        $this->assertFalse(
            defined(RestaurantBusinessModel::class . '::DEMAND_LAG_YEARS'),
            'A restaurant is a spot business and must read the cycle contemporaneously.'
        );
    }
}
