<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\DTO\MacroStateDTO;
use App\Message\ForecastElectionMessage;
use App\MessageHandler\ForecastElectionHandler;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Politics\ElectionForecast;
use App\Service\Politics\PoliticsEngine;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The election forecast worked out off the tick: the ticker asks for it on a poll's tick and publishes what the
 * forecaster sends back, the forecast the tick would have made, on the tick it reads it.
 */
class ForecastElectionHandlerTest extends TestCase
{
    private const DAY = 1.0 / 360.0;

    public function testTheForecastSentBackIsTheOneTheTickWouldHaveMade(): void
    {
        $bus = new RecordingBus();
        $redis = $this->inMemoryRedis();
        $offTick = new PoliticsEngine(MathUtility::ownStream(3), $redis, null, $bus);
        $onTick = new PoliticsEngine(MathUtility::ownStream(3), $this->inMemoryRedis());

        [$day, $inline, $asked] = $this->toTheFirstPoll($onTick, $offTick, $bus);
        $this->assertSame($inline->totalTime, $inline->forecastAt, 'On the tick, the poll is forecast from at once.');
        $this->assertSame($inline->totalTime, $asked->forecastRequestedAt, 'Off it, the forecast is asked for');
        $this->assertNotSame($asked->totalTime, $asked->forecastAt, 'and nothing is published yet.');

        (new ForecastElectionHandler($redis))($bus->sent[0]);
        $inline = $onTick->updatePolitics($this->economy($day * self::DAY), self::DAY);
        $received = $offTick->updatePolitics($this->economy($day * self::DAY), self::DAY, forecastOffTick: true);

        $this->assertSame($received->totalTime, $received->forecastAt, 'Published on the tick it is read back.');
        $this->assertSame(-1.0, $received->forecastRequestedAt);
        foreach (['forecastLevers', 'forecastCabinets', 'forecastLeaders', 'forecastSeats', 'forecastFor'] as $field) {
            $this->assertSame($inline->$field, $received->$field, $field);
        }
    }

    /** A forecaster that never answers holds the forecast back a week at most: the tick then works it out itself. */
    public function testAnUnansweredRequestIsWorkedOutOnTheTickOnceOverdue(): void
    {
        $bus = new RecordingBus();
        $offTick = new PoliticsEngine(MathUtility::ownStream(3), $this->inMemoryRedis(), null, $bus);

        [$day, , $asked] = $this->toTheFirstPoll(new PoliticsEngine(MathUtility::ownStream(3), $this->inMemoryRedis()), $offTick, $bus);
        $requestedAt = $asked->forecastRequestedAt;
        do {
            $politics = $offTick->updatePolitics($this->economy($day++ * self::DAY), self::DAY, forecastOffTick: true);
            $waited = ($politics->totalTime - $requestedAt) * FinancialConstants::DAYS_PER_YEAR;
            $this->assertLessThan(ElectionForecast::OFF_TICK_PATIENCE_DAYS + 2.0, $waited);
        } while ($politics->forecastAt !== $politics->totalTime);

        $this->assertGreaterThanOrEqual(ElectionForecast::OFF_TICK_PATIENCE_DAYS, $waited);
        $this->assertSame(-1.0, $politics->forecastRequestedAt);
        $this->assertNotEmpty($politics->forecastLevers);
    }

    /** Routed to the forecaster's own transport: unrouted, Messenger would work it out in the ticker after all. */
    public function testTheRequestIsRoutedToTheForecastersTransport(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2) . '/config/packages/messenger.yaml');

        $this->assertSame('politics', $config['framework']['messenger']['routing'][ForecastElectionMessage::class] ?? null);
    }

    /**
     * Runs both engines a day at a time to the first poll.
     *
     * @return array{0: int, 1: \App\DTO\PoliticsStateDTO, 2: \App\DTO\PoliticsStateDTO} The next day, and each politics on the poll.
     */
    private function toTheFirstPoll(PoliticsEngine $onTick, PoliticsEngine $offTick, RecordingBus $bus): array
    {
        $day = 0;
        do {
            ++$day;
            $this->assertLessThan(60, $day, 'A poll within two months.');
            $inline = $onTick->updatePolitics($this->economy($day * self::DAY), self::DAY);
            $asked = $offTick->updatePolitics($this->economy($day * self::DAY), self::DAY, forecastOffTick: true);
        } while ($bus->sent === []);

        return [$day + 1, $inline, $asked];
    }

    private function economy(float $totalTime): MacroStateDTO
    {
        return new MacroStateDTO(
            totalTime: $totalTime,
            sovereignDebtToGdp: 0.6,
            potentialGdpIndex: exp((MacroConstants::STRUCTURAL_LABOR_GROWTH_RATE + MacroConstants::TFP_DRIFT) * $totalTime),
            consumerPriceLevel: exp(MacroConstants::TARGET_INFLATION * $totalTime),
        );
    }

    private function inMemoryRedis(): \Redis
    {
        return new class extends \Redis {
            /** @var array<string, string> */
            private array $store = [];

            public function get(mixed $key): mixed
            {
                return $this->store[(string) $key] ?? false;
            }

            public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool
            {
                $this->store[$key] = (string) $value;

                return true;
            }
        };
    }
}

/** Keeps what is dispatched to it instead of sending it, so the test plays the forecaster. */
final class RecordingBus implements MessageBusInterface
{
    /** @var list<ForecastElectionMessage> */
    public array $sent = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if ($message instanceof ForecastElectionMessage) {
            $this->sent[] = $message;
        }

        return new Envelope($message);
    }
}
