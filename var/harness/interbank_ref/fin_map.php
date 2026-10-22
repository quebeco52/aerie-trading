<?php

declare(strict_types=1);

// Writes runs/fin_map.json: ticker => [industry, business model, isFinancial], for analyse.py.
require __DIR__ . '/../../../vendor/autoload.php';

use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;

$out = [];
foreach (InitialMarket::STOCKS as $row) {
    $industry = (string) $row['industry'];
    $model = Sectors::metricsFor($industry)['business_model'];
    $out[$row['ticker']] = [$industry, $model, Sectors::isFinancial($model)];
}
@mkdir(__DIR__ . '/runs');
file_put_contents(__DIR__ . '/runs/fin_map.json', json_encode($out));
echo count($out), " tickers, ", count(array_filter($out, fn($r) => $r[2])), " financial\n";
