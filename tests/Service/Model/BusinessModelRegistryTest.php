<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Service\Model\BusinessModelRegistry;
use App\Service\Model\CommercialBankBusinessModel;
use App\Service\Model\StandardCorporateBusinessModel;
use App\Service\Model\TechBusinessModel;
use PHPUnit\Framework\TestCase;

class BusinessModelRegistryTest extends TestCase
{
    public function testRegistryResolutionAndAliases(): void
    {
        $bank = new CommercialBankBusinessModel();
        $tech = new TechBusinessModel();
        $corporate = new StandardCorporateBusinessModel();

        $registry = new BusinessModelRegistry([$bank, $tech, $corporate]);

        $this->assertTrue($registry->has('commercial_bank'));
        $this->assertTrue($registry->has('tech'));
        $this->assertTrue($registry->has('none'));
        $this->assertTrue($registry->has('standard_corporate'));

        $this->assertSame($bank, $registry->get('commercial_bank'));
        $this->assertSame($tech, $registry->get('tech'));
        $this->assertSame($corporate, $registry->get('none'));
        $this->assertSame($corporate, $registry->get('standard_corporate'));

        // Fallback for unknown model
        $fallback = $registry->get('unknown_business_model');
        $this->assertSame($corporate, $fallback);
    }
}
