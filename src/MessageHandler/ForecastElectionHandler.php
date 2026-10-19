<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ForecastElectionMessage;
use App\Service\Politics\Diet\ElectionForecast;
use App\Service\Politics\PoliticsEngine;
use App\Service\Politics\PoliticsState;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Works out the election forecast off the tick, on the politics as they stood when it was asked for and with the same
 * draws, so it is the forecast the tick would have made; the ticker publishes it when it reads it back.
 */
#[AsMessageHandler]
final readonly class ForecastElectionHandler
{
    public function __construct(
        private \Redis $redis,
    ) {}

    public function __invoke(ForecastElectionMessage $message): void
    {
        $state = PoliticsState::fromArray($message->state);
        $forecast = ElectionForecast::forecast($state, ElectionForecast::draws($state));

        $this->redis->set(PoliticsEngine::REDIS_POLITICS_FORECAST, json_encode([
            'requestedAt' => $state->forecastRequestedAt,
            'forecast' => $forecast,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
