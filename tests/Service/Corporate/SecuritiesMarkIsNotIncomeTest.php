<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\DTO\EarningsSimulationContext;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\InsuranceBusinessModel;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The governing invariant of the whole mechanism: an unrealized mark is not income.
 *
 * The mark belongs to the balance sheet. It moves equity, it moves what a buyer of the shares is buying and
 * it moves regulatory capacity — and it touches no line of the income statement until a sale crystallizes
 * it. Wired into earnings instead it would make every move in the curve an earnings event, double-count
 * against the NIM squeeze that already charges the duration gap as a margin cost, and break the accrual
 * calibration that reads net income against cash flow.
 *
 * If any assertion here fails, the mark has leaked into earnings and the phase is wrong.
 */
final class SecuritiesMarkIsNotIncomeTest extends TestCase
{
    /** Twelve quarters of a three-hundred point selloff, which is enough to mark a long book down hard. */
    private const QUARTERS = 12;

    private EarningsEngine $engine;
    private ReflectionMethod $roll;

    protected function setUp(): void
    {
        // Only the securities roll is under test, and it collaborates with nothing but its own service,
        // which the engine defaults. The rest of the pipeline is deliberately not constructed.
        $this->engine = (new ReflectionClass(EarningsEngine::class))->newInstanceWithoutConstructor();
        $securities = new \ReflectionProperty(EarningsEngine::class, 'securitiesBook');
        $securities->setValue($this->engine, new \App\Service\Corporate\SecuritiesBookService(
            new \App\Service\Market\Bond\BondPricingEngine()
        ));

        $this->roll = new ReflectionMethod(EarningsEngine::class, 'rollForwardSecuritiesBook');
    }

    private function macro(float $policyRate, float $level): MacroStateDTO
    {
        return new MacroStateDTO(
            policyRate: $policyRate,
            policyRateEma: $policyRate,
            yield10y: $level,
            yield10yEma: $level,
            nsLevel: $level,
            nsBeta1: $policyRate - $level,
            nsBaseTermPremium: 0.0125,
        );
    }

    private function insurer(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('INSR');
        $stock->setIndustry('Insurance - Property & Casualty');
        $stock->setTotalEquity('100000000000');
        $stock->setCorporateTreasury('400000000000');
        $stock->setCustomerDeposits('400000000000');
        $stock->setSecuritiesDuration(7.0);

        return $stock;
    }

    private function context(Stock $stock, MacroStateDTO $macro, object $strategy): EarningsSimulationContext
    {
        $ctx = new EarningsSimulationContext($stock, $macro, $strategy, 'insurance');
        $ctx->ebit = 5_000_000_000.0;
        $ctx->ebitda = 6_000_000_000.0;
        $ctx->impairmentCharges = 0.0;
        $ctx->creditLossProvision = 0.0;

        return $ctx;
    }

    /**
     * A hiking cycle carves a fifth off a long float portfolio and leaves the income statement exactly
     * where it was.
     */
    public function testAThreeHundredPointSelloffMovesEquityAndLeavesEarningsUntouched(): void
    {
        $stock = $this->insurer();
        $strategy = new InsuranceBusinessModel();

        $opening = $this->context($stock, $this->macro(0.03, 0.0425), $strategy);
        $this->roll->invoke($this->engine, $opening);

        $equityAfterOpen = (float) $stock->getTotalEquity();
        self::assertSame(0.0, (float) $stock->getUnrealizedSecuritiesMark(), 'A book opens marked flat.');

        $hiked = $this->macro(0.06, 0.0725);
        $ctx = $this->context($stock, $hiked, $strategy);

        for ($quarter = 0; $quarter < self::QUARTERS; $quarter++) {
            $ctx = $this->context($stock, $hiked, $strategy);
            $this->roll->invoke($this->engine, $ctx);
        }

        $mark = (float) $stock->getUnrealizedSecuritiesMark();
        self::assertLessThan(0.0, $mark, 'The curve went against the book.');

        // The balance sheet moved, by exactly the recognised tranche and nothing else.
        self::assertEqualsWithDelta(
            $equityAfterOpen + $stock->getRecognizedSecuritiesMark(),
            (float) $stock->getTotalEquity(),
            1.0,
            'Equity carries the available-for-sale mark and only that.'
        );
        self::assertLessThan($equityAfterOpen, (float) $stock->getTotalEquity());

        // The income statement did not.
        self::assertSame(5_000_000_000.0, $ctx->ebit, 'EBIT must not see the mark.');
        self::assertSame(6_000_000_000.0, $ctx->ebitda, 'EBITDA must not see the mark.');
        self::assertSame(0.0, $ctx->impairmentCharges, 'A valuation is not an impairment charge.');
        self::assertSame(0.0, $ctx->creditLossProvision, 'A rate mark is not a credit provision.');
    }

    /**
     * Equity must move by the CHANGE each quarter. Booking the level again every quarter would charge the
     * same loss over and over until an otherwise healthy insurer was wiped out by a curve that stood still.
     */
    public function testHoldingTheCurveStillDoesNotChargeTheSameLossTwice(): void
    {
        $stock = $this->insurer();
        $strategy = new InsuranceBusinessModel();
        $hiked = $this->macro(0.06, 0.0725);

        $this->roll->invoke($this->engine, $this->context($stock, $this->macro(0.03, 0.0425), $strategy));
        $this->roll->invoke($this->engine, $this->context($stock, $hiked, $strategy));

        $afterFirstShock = (float) $stock->getTotalEquity();
        $markAfterFirst = (float) $stock->getUnrealizedSecuritiesMark();

        $this->roll->invoke($this->engine, $this->context($stock, $hiked, $strategy));

        $afterSecond = (float) $stock->getTotalEquity();

        // The second quarter at the same curve is a partial RECOVERY, because the book rolled down into it.
        self::assertGreaterThan($afterFirstShock, $afterSecond);
        self::assertGreaterThan($markAfterFirst, (float) $stock->getUnrealizedSecuritiesMark());
    }

    /** An operating company's treasury is cash. It has no book, so nothing about it may move. */
    public function testANonFinancialIsUntouched(): void
    {
        $stock = new Stock();
        $stock->setTicker('OPCO');
        $stock->setIndustry('Conglomerates');
        $stock->setTotalEquity('50000000000');
        $stock->setCorporateTreasury('10000000000');

        $ctx = new EarningsSimulationContext($stock, $this->macro(0.06, 0.0725), new StandardCorporateBusinessModel(), 'standard_corporate');
        $this->roll->invoke($this->engine, $ctx);

        self::assertSame(50_000_000_000.0, (float) $stock->getTotalEquity(), 'Equity is not merely unchanged in value; the roll returned before touching it.');
        self::assertSame(0.0, (float) $stock->getUnrealizedSecuritiesMark());
        self::assertNull($stock->getSecuritiesCarryingYield(), 'A firm with no book strikes no carrying yield.');
    }

    /** A bank's book is its loans and securities, and its own hedge takes part of the duration out. */
    public function testABankMarksLessThanAnInsurerOnTheSameCurve(): void
    {
        $bank = new Stock();
        $bank->setTicker('BANK');
        $bank->setIndustry('Banks - Diversified');
        $bank->setTotalEquity('100000000000');
        $bank->setCorporateTreasury('50000000000');
        $bank->setEarningAssets('400000000000');
        $bank->setFloatingDebtRatio('0.30');
        $bank->setSecuritiesDuration(4.5);

        $insurer = $this->insurer();

        $base = $this->macro(0.03, 0.0425);
        $hiked = $this->macro(0.06, 0.0725);

        $this->roll->invoke($this->engine, $this->context($bank, $base, new CommercialBankBusinessModel()));
        $this->roll->invoke($this->engine, $this->context($insurer, $base, new InsuranceBusinessModel()));
        $this->roll->invoke($this->engine, $this->context($bank, $hiked, new CommercialBankBusinessModel()));
        $this->roll->invoke($this->engine, $this->context($insurer, $hiked, new InsuranceBusinessModel()));

        $bankRatio = (float) $bank->getUnrealizedSecuritiesMark() / 400_000_000_000.0;
        $insurerRatio = (float) $insurer->getUnrealizedSecuritiesMark() / 300_000_000_000.0;

        self::assertLessThan(0.0, $bankRatio);
        self::assertLessThan($bankRatio, $insurerRatio, 'The long, unhedged float book takes the harder hit.');
    }
}
