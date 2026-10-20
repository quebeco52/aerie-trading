<?php

declare(strict_types=1);

/*
 * Net interest margin response of every seeded lender to a parallel point of rates.
 *
 * Opens the seeded board as the reset command does (OpeningBoardBuilder on the opening macro), carries each lender's
 * book one quarter at the opening macro, then four (and forty) more either at the opening macro or with the policy
 * rate and the whole curve one point higher. Reports the change in net interest income per unit of earning assets:
 * interest income from the book's own repricing plus the cash beside it, less the four-quarter interest expense the
 * model's own expense beta implies. Real economies run about 5-30bp per point over one to two years.
 *
 * Usage: bin/php-slot php var/harness/banks/nim_response.php
 */

// The test bootstrap: the autoloader plus the bcmath shim the entity needs on a host PHP without the extension.
require __DIR__ . '/../../../tests/bootstrap.php';

use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Market\Pricing\OpeningBoardBuilder;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\CommercialBankBusinessModel;

const POINT = 0.01;
const LENDERS = ['commercial_bank', 'custody_bank', 'private_bank', 'credit_services', 'shadow_bank'];

mt_srand(20261020);
$math = new MathUtility();
$builder = new OpeningBoardBuilder($math, new DebtEngine($math, CorporateMetrics::getInstance()), new MarketEngine($math), new AnchorStakeLedger());
$opening = new MacroStateDTO();
$board = [];
$spheres = [];
foreach (InitialMarket::STOCKS as $row) {
    $board[$row['ticker']] = (new Stock())->setTicker($row['ticker']);
    $sphere = $builder->open($board[$row['ticker']], $row, $opening);
    if ($sphere !== null) {
        $spheres[] = $sphere;
    }
}
$builder->openSpheres($spheres, $board, $opening);

$shifted = new MacroStateDTO(
    policyRateEma: $opening->policyRateEma + POINT,
    yield2yEma: $opening->yield2yEma + POINT,
    yield5yEma: $opening->yield5yEma + POINT,
    yield10yEma: $opening->yield10yEma + POINT,
    yield30yEma: $opening->yield30yEma + POINT,
);

/** Carries the book forward as the engine does, on a stream of its own so both paths draw the same. */
$carry = static function (CommercialBankBusinessModel $model, Stock $stock, MacroStateDTO $macro, int $quarters, int $seed): void {
    $draws = MathUtility::ownStream($seed);
    for ($quarter = 0; $quarter < $quarters; ++$quarter) {
        $result = $model->computeActualFinancials($stock, max(1.0, (float) $stock->getTotalRevenue() / 4.0), 0.50, 0.0, 0.0, $macro, $draws);
        $stock->setEarningsMomentumZ($result->streamZ);
    }
};

printf("%-5s %-16s %10s %10s %10s %12s %12s %10s\n", 'tick', 'model', 'spread', 'inc 4q bp', 'exp 4q bp', 'dNIM 4q bp', 'dNIM 40q bp', 'float');
foreach (InitialMarket::STOCKS as $row) {
    $modelId = Sectors::businessModelFor($row['industry'] ?? null);
    if (!in_array($modelId, LENDERS, true)) {
        continue;
    }
    $model = Sectors::getBusinessModelStrategy($modelId);
    if (!$model instanceof CommercialBankBusinessModel) {
        continue;
    }
    $treasuryIncome = new ReflectionMethod($model, 'resolveTreasuryIncome');
    $pricing = new ReflectionMethod($model, 'resolveFranchisePricing');

    $stock = clone $board[$row['ticker']];
    $carry($model, $stock, $opening, 1, 7);
    $row4 = [];
    foreach ([4, 40] as $horizon) {
        $base = clone $stock;
        $shock = clone $stock;
        $carry($model, $base, $opening, $horizon, 11);
        $carry($model, $shock, $shifted, $horizon, 11);

        $earningAssets = max(1.0, $model->resolveEarningAssets($base));
        $income = ($earningAssets * ($model->resolveInterestYield($shock, $shifted) - $model->resolveInterestYield($base, $opening)))
            + $treasuryIncome->invoke($model, $shock, $shifted) - $treasuryIncome->invoke($model, $base, $opening);
        $assets = $earningAssets + max(0.0, (float) $base->getCorporateTreasury());
        // Four quarters of expense beta; over forty every liability has repriced to its long-run pass-through.
        $expense = $horizon === 4
            ? $model->resolveExpenseBeta($base, $opening) * $assets * POINT
            : ((float) $base->getCustomerDeposits() * ((float) ($shock->getEarningsMomentumZ()[CommercialBankBusinessModel::STATE_DEPOSIT_RATE] ?? 0.0) - (float) ($base->getEarningsMomentumZ()[CommercialBankBusinessModel::STATE_DEPOSIT_RATE] ?? 0.0)))
                + ((float) $base->getWholesaleDebt() * POINT);
        $row4[$horizon] = [$income / $earningAssets, $expense / $earningAssets];
    }
    $struck = $pricing->invoke($model, $stock);
    printf(
        "%-5s %-16s %+9.2f%% %10.1f %10.1f %12.1f %12.1f %10.2f\n",
        $row['ticker'],
        $modelId,
        100 * $struck['loan_spread'],
        1e4 * $row4[4][0],
        1e4 * $row4[4][1],
        1e4 * ($row4[4][0] - $row4[4][1]),
        1e4 * ($row4[40][0] - $row4[40][1]),
        $struck['floating_share'],
    );
}
