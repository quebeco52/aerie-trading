<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\Bond;
use App\Entity\Etf;
use App\Entity\Stock;
use App\Entity\User;
use App\Entity\UserBond;
use App\Entity\UserEtf;
use App\Entity\UserStock;
use App\Service\Market\AssetResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Ticker-to-instrument resolution and holding mutation.
 *
 * The behaviour under test is the one that used to be a nullable-entity chain repeated at four call sites in
 * TradeExecutionService. A wrong branch there does not throw: it treats one asset class as another and looks
 * up a holding that can never exist, so the failure surfaces as a trade that silently does nothing.
 */
#[AllowMockObjectsWithoutExpectations]
class AssetResolverTest extends TestCase
{
    /** @var array<string, object> */
    private array $repositories = [];

    private function resolver(): AssetResolver
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            fn (string $class): object => $this->repositories[$class] ?? $this->emptyRepository()
        );

        return new AssetResolver($em);
    }

    private function emptyRepository(): object
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        return $repo;
    }

    private function repositoryReturning(string $class, ?object $entity): void
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($entity);

        $this->repositories[$class] = $repo;
    }

    public function testResolvesAStockAheadOfTheOtherClasses(): void
    {
        $stock = (new Stock())->setTicker('ACME');
        $this->repositoryReturning(Stock::class, $stock);

        $resolved = $this->resolver()->resolve('ACME');

        $this->assertNotNull($resolved);
        $this->assertSame('STOCK', $resolved->type);
        $this->assertSame($stock, $resolved->entity);
        $this->assertSame('ACME', $resolved->ticker());
    }

    public function testResolvesAnEtfWhenNoStockMatches(): void
    {
        $etf = (new Etf())->setTicker('LBI');
        $this->repositoryReturning(Stock::class, null);
        $this->repositoryReturning(Etf::class, $etf);

        $resolved = $this->resolver()->resolve('LBI');

        $this->assertNotNull($resolved);
        $this->assertSame('ETF', $resolved->type);
    }

    public function testResolvesABondWhenNoStockOrEtfMatches(): void
    {
        $bond = (new Bond())->setTicker('G10-001');
        $this->repositoryReturning(Stock::class, null);
        $this->repositoryReturning(Etf::class, null);
        $this->repositoryReturning(Bond::class, $bond);

        $resolved = $this->resolver()->resolve('G10-001');

        $this->assertNotNull($resolved);
        $this->assertSame('BOND', $resolved->type);
        $this->assertSame($bond, $resolved->entity);
    }

    public function testReturnsNullForAnUnknownTicker(): void
    {
        $this->assertNull($this->resolver()->resolve('NOPE'));
    }

    // --- Halts ---

    public function testABankruptStockIsHalted(): void
    {
        $stock = (new Stock())->setTicker('DEAD');
        $stock->setIsBankrupt(true);
        $this->repositoryReturning(Stock::class, $stock);

        $this->assertSame(
            'Trading is halted for DEAD. The company is bankrupt.',
            $this->resolver()->resolve('DEAD')?->haltReason()
        );
    }

    public function testAMaturedBondIsHalted(): void
    {
        // A redeemed issue still has rows but no market. An order against it could never be honoured.
        $bond = (new Bond())->setTicker('G02-001')->setStatus(Bond::STATUS_MATURED);
        $this->repositoryReturning(Stock::class, null);
        $this->repositoryReturning(Etf::class, null);
        $this->repositoryReturning(Bond::class, $bond);

        $this->assertSame(
            'Trading is halted for G02-001. The issue has matured.',
            $this->resolver()->resolve('G02-001')?->haltReason()
        );
    }

    public function testAnActiveInstrumentIsNotHalted(): void
    {
        $this->repositoryReturning(Stock::class, (new Stock())->setTicker('ACME'));

        $this->assertNull($this->resolver()->resolve('ACME')?->haltReason());
    }

    // --- Holdings ---

    public function testFindHoldingLooksUpTheRightTableForEachClass(): void
    {
        $userStock = new UserStock();
        $userEtf = new UserEtf();
        $userBond = new UserBond();

        $this->repositoryReturning(UserStock::class, $userStock);
        $this->repositoryReturning(UserEtf::class, $userEtf);
        $this->repositoryReturning(UserBond::class, $userBond);

        $resolver = $this->resolver();
        $user = new User();

        $this->assertSame($userStock, $resolver->findHolding($user, new \App\DTO\ResolvedAssetDTO(new Stock(), 'STOCK')));
        $this->assertSame($userEtf, $resolver->findHolding($user, new \App\DTO\ResolvedAssetDTO(new Etf(), 'ETF')));
        $this->assertSame($userBond, $resolver->findHolding($user, new \App\DTO\ResolvedAssetDTO(new Bond(), 'BOND')));
    }

    public function testAddToHoldingOpensTheCorrectHoldingTypeWhenNoneExists(): void
    {
        $resolver = $this->resolver();
        $user = new User();
        $bond = new Bond();

        $holding = $resolver->addToHolding($user, new \App\DTO\ResolvedAssetDTO($bond, 'BOND'), null, 25);

        $this->assertInstanceOf(UserBond::class, $holding);
        $this->assertSame($bond, $holding->getBond());
        $this->assertSame(25, (int) $holding->getQuantity());
    }

    public function testAddToHoldingAccumulatesOntoAnExistingPosition(): void
    {
        $existing = (new UserBond())->setQuantity(10);

        $holding = $this->resolver()->addToHolding(new User(), new \App\DTO\ResolvedAssetDTO(new Bond(), 'BOND'), $existing, 15);

        $this->assertSame($existing, $holding);
        $this->assertSame(25, (int) $holding->getQuantity());
    }

    public function testRemoveFromHoldingReducesTheQuantity(): void
    {
        $holding = (new UserStock())->setQuantity(40);

        $this->resolver()->removeFromHolding($holding, 15);

        $this->assertSame(25, (int) $holding->getQuantity());
    }

    public function testAnUnknownAssetTypeIsRejectedRatherThanSilentlyIgnored(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resolver()->addToHolding(new User(), new \App\DTO\ResolvedAssetDTO(new Stock(), 'FUTURE'), null, 1);
    }
}
