<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Pricing;

use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\CommunicationEquipmentBusinessModel;
use App\Service\Model\Sector\DefenseContractorBusinessModel;
use App\Service\Model\Sector\TelecomBusinessModel;
use App\Service\Model\StreamContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the last report disclosed is in the public quarter: a subscriber base below its norm, a backlog above steady
 * state, a rollout wave already running. Each moves the public quarter by as much as it moves the quarter the firm
 * goes on to report, so analysts forecast it rather than being surprised by it.
 */
final class PublicQuarterConsensusTest extends TestCase
{
    private const SCENARIOS = 400;

    /** @return iterable<string, array{string, string, float}> */
    public static function disclosedBooks(): iterable
    {
        yield 'a thinner subscriber base' => ['telecom', TelecomBusinessModel::STATE_SUBSCRIBER_INDEX, 0.90];
        yield 'a deep procurement backlog' => [
            'defense_contractor',
            StreamContext::BACKLOG_STATE_PREFIX . 'cost_plus_procurement',
            1.5 * (1.0 - DefenseContractorBusinessModel::COST_PLUS_BACKLOG_BURN_RATE) / DefenseContractorBusinessModel::COST_PLUS_BACKLOG_BURN_RATE,
        ];
        yield 'a running rollout wave' => [
            'communication_equipment',
            StreamContext::REGIME_STATE_PREFIX . CommunicationEquipmentBusinessModel::REGIME_ROLLOUT_WAVE,
            2.0,
        ];
    }

    #[DataProvider('disclosedBooks')]
    public function testADisclosedBookMovesThePublicQuarterAsItMovesTheReport(string $model, string $state, float $value): void
    {
        $strategy = Sectors::getBusinessModelStrategy($model);
        $macro = new MacroStateDTO(exchangeRateIndexEma: 100.0);
        $plain = (new Stock())->setTicker('BOOK')->setBeta('1.0')->setTotalEquity('500000000');
        $disclosed = (clone $plain)->setEarningsMomentumZ([$state => $value]);
        $public = static fn(Stock $stock): float => $strategy->measurePublicFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.10, $macro, 0, 11)->actualRevenue;

        $draws = MathUtility::ownStream(29);
        $reported = 0.0;
        for ($scenario = 0; $scenario < self::SCENARIOS; ++$scenario) {
            $reported += $strategy->computeActualFinancials(clone $disclosed, 100_000_000.0, 0.40, 20_000_000.0, 0.10, $macro, $draws, 0)->actualRevenue / self::SCENARIOS;
        }

        $this->assertGreaterThan(0.02, abs(($public($disclosed) / $public($plain)) - 1.0), 'the book must move the quarter for the test to have power');
        $this->assertEqualsWithDelta(1.0, $reported / $public($disclosed), 0.01);
    }
}
