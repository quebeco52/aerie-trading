<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate\Industry;

use App\Data\Company\FinancialBooks;
use App\Data\Company\IndustryMarkets;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\Industry\InMemoryIndustryShareStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Lightweight ledger records for multi-market financials (FinancialBooks): bidirectional rivalry across shared book
 * markets, symmetric whole-market merger sizing without plant anchoring, and pro-rata deals, divestitures and failure.
 */
#[Group('unit')]
final class FinancialBooksLedgerTest extends TestCase
{
    public function testBidirectionalRivalryBetweenLifeBooksIgnoresPcBookAndStreamNames(): void
    {
        $store = new InMemoryIndustryShareStore();
        $ledger = new IndustryShareLedger($store);

        // WELL reports on different stream keys from DOVE; both still compete 1:1 in Insurance - Life.
        $well = (new Stock())
            ->setTicker('WELL')
            ->setIndustry('Insurance - Life')
            ->setTotalRevenue('90000000000')
            ->setEarningsMomentumZ(['share:protection' => 0.35, 'share:annuities' => 0.45, 'share:savings_fees' => 0.20]);
        $dove = (new Stock())
            ->setTicker('DOVE')
            ->setIndustry('Insurance - Diversified')
            ->setTotalRevenue('121000000000')
            ->setEarningsMomentumZ(['share:property_casualty_premiums' => 0.55, 'share:life_insurance_premiums' => 0.45]);

        $lifeMarket = IndustryMarkets::openingMarket('Insurance - Life');
        $pcMarket = IndustryMarkets::openingMarket('Insurance - Diversified');
        $wellRev = 90_000_000_000.0;
        $doveLifeRev = 121_000_000_000.0 * 0.45;
        $dovePcRev = 121_000_000_000.0 * 0.55;

        // Quarter 1: establish records and consumed ticks for both firms.
        $ledger->resolveRivalShareDrain($well, 16, 252);
        $ledger->recordIdiosyncraticGain($well, $wellRev, 0.0, $wellRev / $lifeMarket, 16);
        $ledger->resolveBookRivalDrain($dove, FinancialBooks::MAIN, $dovePcRev, 20, 252);
        $ledger->resolveBookRivalDrain($dove, 'life', $doveLifeRev, 20, 252);
        $ledger->recordBookGain($dove, FinancialBooks::MAIN, $dovePcRev, 0.0, $dovePcRev / $pcMarket, 20);
        $ledger->recordBookGain($dove, 'life', $doveLifeRev, 0.0, $doveLifeRev / $lifeMarket, 20);

        // Quarter 2: WELL beats by +10% in Insurance - Life.
        $ledger->recordIdiosyncraticGain($well, $wellRev, 0.10, $wellRev / $lifeMarket, 79);

        $lifeDrain = $ledger->resolveBookRivalDrain($dove, 'life', $doveLifeRev, 83, 252);
        $pcDrain = $ledger->resolveBookRivalDrain($dove, FinancialBooks::MAIN, $dovePcRev, 83, 252);
        $expectedDoveDrain = -($wellRev * 0.10) / ($lifeMarket - $wellRev);
        $this->assertEqualsWithDelta($expectedDoveDrain, $lifeDrain, 1e-12, 'mixOverlap = 1.0 across different model streams');
        $this->assertSame(0.0, $pcDrain, 'life rival gain stays out of Insurance - Diversified');

        // Quarter 2 report by DOVE: big P&C gain (+15%) and life beat (+10%); WELL absorbs only life's beat.
        $ledger->recordBookGain($dove, FinancialBooks::MAIN, $dovePcRev, 0.15, $dovePcRev / $pcMarket, 83);
        $ledger->recordBookGain($dove, 'life', $doveLifeRev, 0.10, $doveLifeRev / $lifeMarket, 83);

        $wellDrain = $ledger->resolveRivalShareDrain($well, 142, 252);
        $expectedWellDrain = -($doveLifeRev * 0.10) / ($lifeMarket - $doveLifeRev);
        $this->assertEqualsWithDelta($expectedWellDrain, $wellDrain, 1e-12, 'P&C surprise never leaks to WELL');
    }

    public function testSharedLifeMarketAgreementAndLifecycleOperations(): void
    {
        $store = new InMemoryIndustryShareStore();
        $ledger = new IndustryShareLedger($store);
        $macro = new MacroStateDTO(totalTime: 0.0, nominalGdpIndex: 1.0, potentialGdpIndex: 1.0, gdpDeflator: 1.0);

        $well = (new Stock())->setTicker('WELL')->setIndustry('Insurance - Life')->setTotalRevenue('90000000000');
        $dove = (new Stock())->setTicker('DOVE')->setIndustry('Insurance - Diversified')->setTotalRevenue('121000000000');

        $lifeMarket = IndustryMarkets::openingMarket('Insurance - Life');
        $pcMarket = IndustryMarkets::openingMarket('Insurance - Diversified');
        $wellRev = 90_000_000_000.0;
        $doveLifeRev = 54_450_000_000.0;
        $dovePcRev = 66_550_000_000.0;

        $ledger->recordIdiosyncraticGain($well, $wellRev, 0.0, $wellRev / $lifeMarket, 16);
        $ledger->recordBookGain($dove, FinancialBooks::MAIN, $dovePcRev, 0.0, $dovePcRev / $pcMarket, 20);
        $ledger->recordBookGain($dove, 'life', $doveLifeRev, 0.0, $doveLifeRev / $lifeMarket, 20);

        $fromWell = $ledger->describeMergerMarket($well, $macro, 0.0, 20, 252);
        $fromDoveLife = $ledger->describeMergerMarket($dove, $macro, 0.0, 20, 252, 'life');
        $this->assertNotNull($fromWell);
        $this->assertNotNull($fromDoveLife);
        $this->assertEqualsWithDelta($lifeMarket, $fromWell['market_revenue'], 1.0);
        $this->assertEqualsWithDelta($fromWell['market_revenue'], $fromDoveLife['market_revenue'], 1.0);
        $this->assertEqualsWithDelta($fromWell['herfindahl'], $fromDoveLife['herfindahl'], 1e-12);
        $this->assertEqualsWithDelta($fromWell['fringe_share'], $fromDoveLife['fringe_share'], 1e-12);
        $this->assertEqualsWithDelta($wellRev / $lifeMarket, $fromWell['acquirer_share'], 1e-9);
        $this->assertEqualsWithDelta($doveLifeRev / $lifeMarket, $fromDoveLife['acquirer_share'], 1e-9);

        // Acquisitions and divestitures slice each financial book record by revenue.
        $ledger->recordAcquiredCapacity($dove, 5_500_000_000.0, $macro, 0.0, FinancialBooks::MAIN);
        $ledger->recordAcquiredCapacity($dove, 4_500_000_000.0, $macro, 0.0, 'life');
        $this->assertEqualsWithDelta($dovePcRev + 5_500_000_000.0, $store->readIndustry('Insurance - Diversified')['DOVE']['revenue'], 1.0);
        $this->assertEqualsWithDelta($doveLifeRev + 4_500_000_000.0, $store->readIndustry('Insurance - Life')['DOVE/life']['revenue'], 1.0);

        $ledger->recordDivestedFraction($dove, 0.20, $macro, 0.0);
        $this->assertEqualsWithDelta(0.80 * ($dovePcRev + 5_500_000_000.0), $store->readIndustry('Insurance - Diversified')['DOVE']['revenue'], 1.0);
        $this->assertEqualsWithDelta(0.80 * ($doveLifeRev + 4_500_000_000.0), $store->readIndustry('Insurance - Life')['DOVE/life']['revenue'], 1.0);

        // Bankruptcy zeroes revenue/gain on every book record across industries.
        $ledger->retireFirm($dove);
        $this->assertSame(0.0, $store->readIndustry('Insurance - Diversified')['DOVE']['revenue']);
        $this->assertSame(0.0, $store->readIndustry('Insurance - Life')['DOVE/life']['revenue']);
    }
}
