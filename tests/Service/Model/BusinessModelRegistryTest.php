<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Service\Model\BusinessModelRegistry;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use App\Service\Model\Sector\TechBusinessModel;
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

    public function testAllSectorModelsResolveCorrectly(): void
    {
        $dir = dirname(__DIR__, 3) . '/src/Service/Model/Sector';
        $files = glob($dir . '/*BusinessModel.php');

        $instances = [];
        foreach ($files as $file) {
            $class = 'App\\Service\\Model\\Sector\\' . basename($file, '.php');
            $this->assertTrue(class_exists($class), "Class $class should exist");
            $ref = new \ReflectionClass($class);
            if ($ref->isAbstract()) {
                continue;
            }
            $instances[] = new $class();
        }

        $registry = new BusinessModelRegistry($instances);

        $this->assertGreaterThanOrEqual(44, count($instances));
        foreach ($files as $file) {
            $class = 'App\\Service\\Model\\Sector\\' . basename($file, '.php');
            $ref = new \ReflectionClass($class);
            if ($ref->isAbstract()) {
                continue;
            }
            $shortName = basename($file, '.php');
            $expectedKey = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', preg_replace('/BusinessModel$/', '', $shortName)));

            if ($shortName === 'StandardCorporateBusinessModel') {
                $expectedKey = 'none';
            } elseif ($shortName === 'AssetManagementBusinessModel') {
                $expectedKey = 'asset_manager';
            }

            $this->assertTrue($registry->has($expectedKey), "Registry should have key: $expectedKey");
            $resolved = $registry->get($expectedKey);
            $this->assertInstanceOf($class, $resolved);
        }
    }
}
