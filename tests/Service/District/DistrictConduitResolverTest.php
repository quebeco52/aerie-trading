<?php

declare(strict_types=1);

namespace App\Tests\Service\District;

use App\Data\DistrictMap;
use App\Service\District\DistrictConduitResolver;
use App\Service\Model\BusinessModelInterface;
use App\Service\Model\BusinessModelRegistryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Pins the derivation rule in isolation, with a stub model whose declared fields are fully
 * controlled — App\Tests\Data\DistrictConduitTopologyTest exercises this same resolver against
 * every real business model.
 */
class DistrictConduitResolverTest extends TestCase
{
    private function resolverFor(array $operatingMacroFields): DistrictConduitResolver
    {
        $model = $this->createStub(BusinessModelInterface::class);
        $model->method('getOperatingMacroFields')->willReturn($operatingMacroFields);

        $registry = $this->createStub(BusinessModelRegistryInterface::class);
        $registry->method('get')->willReturn($model);

        return new DistrictConduitResolver($registry);
    }

    public function testModelReadingAnInstitutionsFieldDrawsItsConduit(): void
    {
        $resolver = $this->resolverFor(['policy_rate_ema']); // a rate-council field

        $this->assertContains('rate-council', $resolver->conduitsForBusinessModel('test'));
    }

    public function testModelReadingNoFieldsDrawsNoConduits(): void
    {
        $resolver = $this->resolverFor([]);

        $this->assertSame([], $resolver->conduitsForBusinessModel('test'));
    }

    public function testModelReadingOnlyUbiquitousFieldsDrawsNoConduits(): void
    {
        $resolver = $this->resolverFor(DistrictMap::UBIQUITOUS_MACRO_FIELDS);

        $this->assertSame(
            [],
            $resolver->conduitsForBusinessModel('test'),
            'A model reading only near-universal fields should not be wired to any institution'
        );
    }

    public function testModelReadingAFieldNoInstitutionPublishesDrawsNoConduits(): void
    {
        $resolver = $this->resolverFor(['some_field_no_institution_publishes']);

        $this->assertSame([], $resolver->conduitsForBusinessModel('test'));
    }

    public function testConduitsAreReturnedInInstitutionDeclarationOrder(): void
    {
        // land-registry is declared after rate-council in DistrictMap::INSTITUTIONS.
        $resolver = $this->resolverFor(['policy_rate_ema', 'commercial_property_index_ema']);

        $this->assertSame(
            ['rate-council', 'land-registry'],
            $resolver->conduitsForBusinessModel('test')
        );
    }
}
