<?php
// Prints the SAM growth factor exp(faded secular excess) at years 10/20/30 for the named tickers (seed data, no container).
require __DIR__ . '/../../../vendor/autoload.php';

use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;

$want = array_slice($argv, 1) ?: ['WEAV', 'TIER', 'SHOR'];
foreach (\App\Data\Company\InitialMarket::STOCKS as $row) {
    if (!in_array($row['ticker'], $want, true)) {
        continue;
    }
    $s = new Stock();
    $s->setTicker($row['ticker']);
    $s->setIndustry($row['industry']);
    printf("%s %-28s %s\n", $row['ticker'], $row['industry'], implode(' ', array_map(
        static fn (int $y) => sprintf('y%d=%.3f', $y, CorporateMetrics::secularMarketGrowth($s, (float) $y)),
        [10, 20, 30],
    )));
}
