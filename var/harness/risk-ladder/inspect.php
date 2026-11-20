<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests/bootstrap.php';

use App\Data\Company\IndustryBetas;
use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Corporate\Segment\SegmentBooks;
use App\Service\Market\Bond\CreditRatingAgency;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\OpeningBoardBuilder;
use App\Service\Market\Pricing\VarianceBudget;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\OptionPricing;
use App\Service\Math\Valuation;
use App\Service\Corporate\Earnings\OperatingCapacityEngine;

mt_srand(1);

$math = new MathUtility();
$corporateMetrics = CorporateMetrics::getInstance();
$ratingAgency = new CreditRatingAgency();
$debtEngine = new DebtEngine($math, $corporateMetrics, $ratingAgency);
$marketEngine = new MarketEngine($math);
$anchorStakes = new AnchorStakeLedger();
$builder = new OpeningBoardBuilder($math, $debtEngine, $marketEngine, $anchorStakes);

$openingMacro = new MacroStateDTO();

$seeded = [];
$spheres = [];
$seedRowsByTicker = [];

foreach (InitialMarket::STOCKS as $stockData) {
    $ticker = (string) $stockData['ticker'];
    $seedRowsByTicker[$ticker] = $stockData;

    $stock = new Stock();
    $stock->setTicker($ticker);
    $sphere = $builder->open($stock, $stockData, $openingMacro);
    if ($sphere !== null) {
        $spheres[] = $sphere;
    }
    $seeded[$ticker] = $stock;
}

$builder->openSpheres($spheres, $seeded, $openingMacro);

$m2 = OptionPricing::calculateKouJumpMoment(2, VarianceBudget::jumpProbabilityUp(), 1.0, 1.0 / 1.25);
$marketVolAnchor = MacroConstants::MACRO_VOL_BASE_ANCHOR;

$rows = [];
$byIndustry = [];

foreach (InitialMarket::STOCKS as $stockData) {
    $ticker = (string) $stockData['ticker'];
    $stock = $seeded[$ticker];
    $industry = (string) ($stock->getIndustry() ?: 'General');
    $model = Sectors::businessModelFor($industry);
    $isFinancial = Sectors::isFinancial($model);
    $strategy = Sectors::strategyFor($industry);

    $health = $debtEngine->analyzeDebtHealth($stock, $openingMacro);
    $leveredBeta = $health->leveredBeta;
    $baseBeta = (float) $stock->getBeta();

    // Compute L directly using DebtEngine's exact Hamada + cash-blend law, and cross-check against leveredBeta / baseBeta.
    $currentDebt = (float) $stock->getTotalDebt();
    $wholesaleDebt = (float) $stock->getWholesaleDebt();
    $treasury = (float) $stock->getCorporateTreasury();
    $signedNetDebt = $strategy->getNetDebtCapital($currentDebt, $wholesaleDebt, $treasury);
    $netDebtCapital = max(0.0, $signedNetDebt);
    $netCash = max(0.0, -$signedNetDebt);

    $ebit = $health->rawMetrics->ebit;
    $totalInterestCost = $strategy->getDebtCostMetrics($health->rawMetrics, $currentDebt, $wholesaleDebt, $health->rawMetrics->interestExpense)->totalInterestCost;
    $corpTax = $openingMacro->corporateTaxRate;
    $taxSavings = (max(0.0, $ebit) * $corpTax) - (max(0.0, $ebit - $totalInterestCost) * $corpTax);
    $impliedTaxShieldRate = $totalInterestCost > 0.0 ? ($taxSavings / $totalInterestCost) : ($ebit > 0.0 ? $corpTax : 0.0);

    $assetCostOfCapital = DebtEngine::assetCostOfCapital($stock, $openingMacro);
    $businessValue = $strategy->resolveOperatingBusinessValue(
        $stock,
        $strategy->getValuationReturn(\App\DTO\MarketPricingContext::trailingReturn($stock, $strategy), $strategy->resolveLongRunReturn($stock, $openingMacro), $assetCostOfCapital)
            + $strategy->getExpectedHoldingReturn($stock, $assetCostOfCapital),
        $assetCostOfCapital,
        Valuation::calculateExpectedNominalGrowth(SegmentBooks::fadedSecularGrowth($strategy, $stock, $openingMacro->totalTime), $openingMacro->tipsBreakeven, $assetCostOfCapital),
        $openingMacro,
    );
    $supportedEquity = $businessValue - (1.0 - $impliedTaxShieldRate) * $netDebtCapital;
    $debtToEquity = $supportedEquity > 0.0 ? ($netDebtCapital / $supportedEquity) : 2.5;
    $effectiveDebtToEquity = min(2.5, $debtToEquity);

    $hamadaL = $strategy->calculateLeveredBeta(1.0, $impliedTaxShieldRate, $effectiveDebtToEquity);
    $L = Valuation::cashBlendedBeta($hamadaL, $businessValue, $netCash);
    $betaRatio = abs($baseBeta) > 1e-12 ? ($leveredBeta / $baseBeta) : $L;
    if (abs($L - $betaRatio) > 1e-9) {
        fwrite(STDERR, sprintf("MISMATCH L for %s: L=%.9f betaRatio=%.9f\n", $ticker, $L, $betaRatio));
    }

    $vol = (float) $stock->getVolatility();
    $sysVar = ($leveredBeta * $marketVolAnchor) ** 2;
    $sysJumpVar = VarianceBudget::systemicJumpMoments($leveredBeta)['variance'];
    $rawIdioVar = ($vol * $vol) - $sysVar - $sysJumpVar;
    $floorVar = ($vol * $vol) * 0.20;
    $floorBinds = $rawIdioVar < $floorVar;
    $idioVarBudgeted = VarianceBudget::longTermIdiosyncraticVariance($vol, $leveredBeta);

    $impliedBizIdioVol = sqrt(max(0.0, $rawIdioVar)) / $L;
    $flooredBizIdioVol = sqrt(max(0.0, $idioVarBudgeted)) / $L;

    $lambda = (float) $stock->getJumpIntensity();
    $statedJv = (float) $stock->getJumpVol();
    $cal = VarianceBudget::calibratedJumpParameters($vol, $leveredBeta, $lambda, $statedJv);
    $budgetedScale = $cal['scale'];

    $rawJumpVar = $lambda * $m2 * ($statedJv ** 2);
    $rawJumpShare = $idioVarBudgeted > 0.0 ? ($rawJumpVar / $idioVarBudgeted) : 0.0;
    $cappedJumpShare = min(0.25, $rawJumpShare);

    $fundJumpToday = $statedJv * OperatingCapacityEngine::FUNDAMENTAL_JUMP_VOL_SCALE;
    $fundJumpBudgeted = $budgetedScale * OperatingCapacityEngine::FUNDAMENTAL_JUMP_VOL_SCALE;

    $row = [
        'ticker' => $ticker,
        'industry' => $industry,
        'damodaran' => IndustryBetas::damodaranIndustryFor($industry),
        'model' => $model,
        'financial' => $isFinancial,
        'base_beta' => $baseBeta,
        'levered_beta' => $leveredBeta,
        'L' => $L,
        'hamada_L' => $hamadaL,
        'net_cash' => $netCash,
        'net_debt' => $netDebtCapital,
        'stated_vol' => $vol,
        'sys_var' => $sysVar,
        'sys_jump_var' => $sysJumpVar,
        'raw_idio_var' => $rawIdioVar,
        'floor_binds' => $floorBinds,
        'implied_biz_idio_vol' => $impliedBizIdioVol,
        'floored_biz_idio_vol' => $flooredBizIdioVol,
        'lambda' => $lambda,
        'stated_jv' => $statedJv,
        'budgeted_scale' => $budgetedScale,
        'raw_jump_share' => $rawJumpShare,
        'capped_jump_share' => $cappedJumpShare,
        'fund_jump_today' => $fundJumpToday,
        'fund_jump_budgeted' => $fundJumpBudgeted,
        'rating' => $stock->getCreditRating(),
        'asset_vol' => $stock->getAssetVolatility(),
    ];

    $rows[] = $row;
    $byIndustry[$industry][] = $row;
}

$median = static function (array $vals): float {
    sort($vals);
    $n = count($vals);
    if ($n === 0) {
        return 0.0;
    }
    $mid = intdiv($n, 2);

    return ($n % 2 === 1) ? $vals[$mid] : 0.5 * ($vals[$mid - 1] + $vals[$mid]);
};

$industrySummary = [];
foreach ($byIndustry as $industry => $items) {
    $industrySummary[$industry] = [
        'industry' => $industry,
        'damodaran' => IndustryBetas::damodaranIndustryFor($industry),
        'count' => count($items),
        'financial' => $items[0]['financial'],
        'tickers' => array_column($items, 'ticker'),
        'median_biz_idio_vol' => $median(array_column($items, 'implied_biz_idio_vol')),
        'median_lambda' => $median(array_column($items, 'lambda')),
        'median_jump_share' => $median(array_column($items, 'capped_jump_share')),
        'median_stated_vol' => $median(array_column($items, 'stated_vol')),
    ];
}

ksort($industrySummary);

$outPath = __DIR__ . '/step0_board.json';
file_put_contents($outPath, json_encode([
    'm2' => $m2,
    'market_vol_anchor' => $marketVolAnchor,
    'firm_count' => count($rows),
    'industry_count' => count($industrySummary),
    'firms' => $rows,
    'industries' => array_values($industrySummary),
], JSON_PRETTY_PRINT) . "\n");

echo sprintf("Wrote %d firms across %d industries to %s (m2=%.6f)\n", count($rows), count($industrySummary), $outPath, $m2);

$floorRows = array_values(array_filter($rows, static fn(array $r): bool => $r['floor_binds']));
echo sprintf("Rows where 20%% idio floor binds: %d (%s)\n", count($floorRows), implode(', ', array_column($floorRows, 'ticker')));

$rescaledRows = array_values(array_filter($rows, static fn(array $r): bool => $r['raw_jump_share'] > 0.25 + 1e-9));
echo sprintf("Rows where jump_vol is rescaled by 25%% cap: %d / %d\n", count($rescaledRows), count($rows));

$netCashRows = array_values(array_filter($rows, static fn(array $r): bool => $r['L'] < 0.999));
echo sprintf("Rows with L < 1 (net cash blend): %d (%s)\n", count($netCashRows), implode(', ', array_map(static fn(array $r): string => sprintf('%s=%.3f', $r['ticker'], $r['L']), $netCashRows)));
