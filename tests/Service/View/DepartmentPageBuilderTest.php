<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Data\District\DistrictCalendar;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Corporate\Deal\MergerReview;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Politics\Department\FairTradingBoard;
use App\Service\Politics\Department\Office;
use App\Service\View\DepartmentPageBuilder;
use PHPUnit\Framework\TestCase;

/** The Council's departments' pages, each read off its head and the laws it keeps. */
final class DepartmentPageBuilderTest extends TestCase
{
    /**
     * The Exchequer's panel: nothing before it has a head; then the head's maturity, the book moving toward it, what it
     * pays at its repricing share, and what its maturity adds to the ten-year.
     */
    public function testTheExchequerPanelReadsTheDebtAndItsHead(): void
    {
        $this->assertNull((new DepartmentPageBuilder())->exchequer(new PoliticsStateDTO(), new MacroStateDTO()));

        $politics = new PoliticsStateDTO(
            totalTime: 13.0,
            heads: [Office::ExchequerHead->value => ['name' => 'Rosalind Tate', 'birth' => -38.0, 'termStart' => 13.0, 'stance' => \App\Service\Politics\Department\Exchequer::stance(4.3)] + Office::VACANT],
            councilDebtStances: [-0.5, 0.0, 0.5],
        );
        $macro = new MacroStateDTO(
            sovereignRiskSpread: 0.001,
            sovereignRiskSpreadEma: 0.001,
            sovereignDebtToGdp: 0.9,
            sovereignNetDebtToGdp: 0.75,
            sovereignNetDebtToGdpEma: 0.75,
            sovereignDebtAverageMaturity: 4.7,
            sovereignDebtMaturityTarget: 4.3,
            policyRate: 0.04,
            sovereignCouponRate: 0.03,
        );
        $panel = (new DepartmentPageBuilder())->exchequer($politics, $macro);

        $this->assertNotNull($panel);
        $this->assertSame('Rosalind Tate', $panel['head']['name']);
        $this->assertSame(51, $panel['head']['age']);
        $this->assertSame('short', $panel['head']['stance']);
        $this->assertSame('Favours borrowing short', $panel['head']['stanceText'], 'The sentence the Exchequer\'s page opens with.');
        $this->assertSame('favouring short borrowing', $panel['head']['stanceClause'], 'The clause after the name on the front page.');
        $this->assertEqualsWithDelta(4.3, $panel['movingTo'], 1e-12);
        $share = CreditFiscalSubsystem::billShare(4.7);
        $this->assertEqualsWithDelta($share, $panel['repricingShare'], 1e-12);
        $this->assertEqualsWithDelta(($share * 0.04) + ((1.0 - $share) * 0.03), $panel['rate'], 1e-12);
        $this->assertEqualsWithDelta($panel['rate'] * 0.9, $panel['interestToGdp'], 1e-12);
        $this->assertLessThan(0, $panel['maturityBp'], 'A book shorter than the founding one takes duration off the ten-year.');
        $this->assertEqualsWithDelta(\App\Service\Politics\Department\Exchequer::averageMaturity(0.0), $panel['councilMaturity'], 1e-12);
        $this->assertSame(10, $panel['riskSpreadBp'], 'The default charge the market asks, in basis points.');
    }

    /**
     * The Fair Trading Board's panel: nothing before it has a head; then the head's screens, the passed-over
     * candidates' lines, the Council's median line and the record's two ends.
     */
    public function testTheFairTradingPanelReadsTheHeadsScreens(): void
    {
        $this->assertNull((new DepartmentPageBuilder())->fairTrading(new PoliticsStateDTO()));

        $politics = new PoliticsStateDTO(
            totalTime: 7.0,
            heads: [Office::FairTradingHead->value => [
                'name' => 'Odile Farrant',
                'birth' => -48.0,
                'termStart' => 6.0,
                'stance' => 0.8,
                'passedOver' => [['name' => 'Hal Brennan', 'birth' => -41.0, 'stance' => 0.0, 'regulation' => 0.0, 'fund' => 0.0, 'debt' => 0.0, 'competition' => -1.0, 'markets' => 0.0]],
            ] + Office::VACANT],
            councilCompetitionStances: [-0.2, 0.0, 0.4],
        );
        $panel = (new DepartmentPageBuilder())->fairTrading($politics);

        $this->assertNotNull($panel);
        $this->assertSame('Odile Farrant', $panel['head']['name']);
        $this->assertSame(55, $panel['head']['age']);
        $this->assertSame('strict', $panel['head']['stance']);
        $this->assertSame('A strict enforcer against concentration', $panel['head']['stanceText']);
        $this->assertSame('a strict enforcer', $panel['head']['stanceClause']);
        $this->assertFalse($panel['head']['opening']);
        $this->assertSame(MergerReview::reviewScreens(FairTradingBoard::leniency(0.8)), $panel['screens']);
        $this->assertEqualsWithDelta(0.25, $panel['head']['passedOver'][0]['line'], 1e-12, 'The lightest candidate would draw the 2010 line.');
        $this->assertEqualsWithDelta(MergerReview::reviewScreens(0.5)['concentrated'], $panel['councilLine'], 1e-12);
        $this->assertEqualsWithDelta(0.30, $panel['rules']['strictest']['shareCeiling'], 1e-12);
        $this->assertSame(1.0, $panel['rules']['lightest']['shareCeiling']);
    }

    /**
     * The Securities Commission's panel: the rules in force read off the economy, a new head's coming ones through
     * their notice, and none once it has run.
     */
    public function testTheSecuritiesPanelReadsTheRulesInForceAndComing(): void
    {
        $this->assertNull((new DepartmentPageBuilder())->securities(new PoliticsStateDTO(), new MacroStateDTO()));

        $politics = new PoliticsStateDTO(
            totalTime: 9.6,
            heads: [Office::SecuritiesHead->value => ['name' => 'Thea Lindqvist', 'birth' => -45.0, 'termStart' => 9.5, 'stance' => 1.0] + Office::VACANT],
            councilMarketsStances: [-0.5, 0.0, 0.5],
        );
        $panel = (new DepartmentPageBuilder())->securities($politics, new MacroStateDTO(initialMarginRequirement: 0.5));

        $this->assertNotNull($panel);
        $this->assertSame('strict', $panel['head']['stance']);
        $this->assertSame('A strict overseer of the markets', $panel['head']['stanceText']);
        $this->assertSame('strict', $panel['head']['stanceClause']);
        $this->assertSame(0.5, $panel['inForce']['initial']);
        $this->assertSame(FinancialConstants::MAINTENANCE_MARGIN_SHORT, $panel['inForce']['short']);
        $this->assertNotNull($panel['coming']);
        $this->assertEqualsWithDelta(0.80, $panel['coming']['initial'], 1e-12);
        $this->assertEqualsWithDelta(0.5, $panel['coming']['enforcement'], 1e-12);
        $this->assertSame(FinancialConstants::INITIAL_MARGIN_REQUIREMENT, $panel['councilMargin']);
        $this->assertSame(3, $panel['rules']['noticeMonths']);

        $later = new PoliticsStateDTO(totalTime: 10.0, heads: [Office::SecuritiesHead->value => ['name' => 'Thea Lindqvist', 'termStart' => 9.5, 'stance' => 1.0] + Office::VACANT]);
        $this->assertNull((new DepartmentPageBuilder())->securities($later, new MacroStateDTO())['coming'] ?? null, 'Once the notice has run the rules are in force.');
    }

    /**
     * Each guard's page carries its name and mandate before it has a commander, and the commander and whom the Council
     * passed over once it does.
     */
    public function testTheGuardPagesReadTheirCommanders(): void
    {
        $empty = (new DepartmentPageBuilder())->stateGuard(new PoliticsStateDTO());
        $this->assertSame(\App\Data\District\Institutions::STATE_GUARD, $empty['name']);
        $this->assertNull($empty['head']);
        $this->assertStringContainsString('only the Council can call it out', $empty['mandate']);

        $page = (new DepartmentPageBuilder())->coastGuard(new PoliticsStateDTO(
            totalTime: 4.0,
            heads: [Office::CoastGuardCommandant->value => [
                'name' => 'Imogen Hale',
                'birth' => -48.0,
                'termStart' => 2.5,
                'passedOver' => [['name' => 'Dorian Shaw', 'birth' => -45.5, 'stance' => 0.0, 'regulation' => 0.0, 'fund' => 0.0, 'debt' => 0.0, 'competition' => 0.0, 'markets' => 0.0]],
            ] + Office::VACANT],
        ));
        $this->assertSame('commandant', $page['headTitle']);
        $this->assertNotNull($page['head']);
        $this->assertSame(52, $page['head']['age']);
        $this->assertSame(DistrictCalendar::quarter(2.5), $page['head']['sinceLabel']);
        $this->assertFalse($page['head']['opening']);
        $this->assertSame([['name' => 'Dorian Shaw', 'age' => 48]], $page['head']['passedOver']);
        $this->assertSame(5.0, $page['rules']['termYears']);
    }

    /** The Security Bureau's page: its mandate and director; nothing before it has one. */
    public function testTheSecurityBureauPageReadsItsDirector(): void
    {
        $this->assertNull((new DepartmentPageBuilder())->securityBureau(new PoliticsStateDTO()));

        $page = (new DepartmentPageBuilder())->securityBureau(new PoliticsStateDTO(totalTime: 2.0, heads: [Office::SecurityDirector->value => ['name' => 'Raymond Okafor', 'birth' => -50.0, 'termStart' => -4.0] + Office::VACANT]));
        $this->assertNotNull($page);
        $this->assertSame('Before Year 1', $page['director']['sinceLabel']);
        $this->assertTrue($page['director']['opening']);
        $this->assertSame(52, $page['director']['age']);
        $this->assertStringContainsString('state police', $page['mandate']);
    }

    /**
     * The Trade & Migration Office shows its three laws as in force, as the next budget sets them and as the market
     * expects them, with what each does.
     */
    public function testTheTradeOfficeShowsItsThreeLaws(): void
    {
        $politics = new PoliticsStateDTO(importTariffRate: 0.05, foreignBuyerTaxRate: 0.15, sittingLevers: ['tariff' => 0.08], forecastLevers: ['tariff' => 0.02, 'foreignBuyerTax' => 0.0]);
        $office = (new DepartmentPageBuilder())->tradeOffice($politics, new MacroStateDTO(immigrationPopulationShift: 0.01));

        $this->assertSame(0.05, $office['tariff']['inForce']);
        $this->assertSame(0.08, $office['tariff']['next']);
        $this->assertSame(0.02, $office['tariff']['expected']);
        $this->assertEqualsWithDelta(MacroConstants::TARIFF_RETALIATION_RATIO * 0.05, $office['tariff']['retaliation'], 1e-12);
        $this->assertGreaterThan(0.0, $office['tariff']['revenueToGdp']);
        $this->assertNull($office['migration']['next']);
        $this->assertSame(0.01, $office['migration']['populationShift']);
        $this->assertEqualsWithDelta(1.0 - \App\Service\Macro\Subsystem\AssetMarketSubsystem::foreignBuyerPriceFactor(0.15), $office['foreignBuyers']['priceEffect'], 1e-12);
        $this->assertSame(0.0, $office['foreignBuyers']['expected']);
        $this->assertNotNull($office['expectedFrom']);
    }
}
