<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\Sectors;
use App\Service\Model\BusinessModelRegistry;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use PHPUnit\Framework\TestCase;

class BusinessModelRegistryTest extends TestCase
{
    /** The injected registry and the static lookup are one registry: same identifiers, same instances. */
    public function testTheInjectedRegistryHandsOutTheStaticInstance(): void
    {
        $registry = new BusinessModelRegistry();

        $this->assertSame(array_keys(Sectors::BUSINESS_MODELS), array_keys($registry->all()));

        foreach (array_keys(Sectors::BUSINESS_MODELS) as $identifier) {
            $this->assertTrue($registry->has($identifier));
            $this->assertSame(Sectors::getBusinessModelStrategy($identifier), $registry->get($identifier));
        }
    }

    public function testAnUnknownIdentifierIsNotRegisteredAndRunsStandardCorporatePhysics(): void
    {
        $registry = new BusinessModelRegistry();

        $this->assertFalse($registry->has('unknown_business_model'));
        $this->assertInstanceOf(StandardCorporateBusinessModel::class, $registry->get('unknown_business_model'));
    }

    /** Identifiers follow the class name, snake-cased, so a model is found where its name says it is. */
    public function testIdentifiersFollowTheClassName(): void
    {
        foreach (Sectors::BUSINESS_MODELS as $identifier => $class) {
            $shortName = (new \ReflectionClass($class))->getShortName();
            $expected = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', (string) preg_replace('/BusinessModel$/', '', $shortName)));
            $expected = match ($shortName) {
                'StandardCorporateBusinessModel' => 'none',
                'AssetManagementBusinessModel' => 'asset_manager',
                default => $expected,
            };

            $this->assertSame($expected, $identifier, "{$class} is registered under an identifier its name does not give");
        }
    }
}
