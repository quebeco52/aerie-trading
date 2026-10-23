<?php

declare(strict_types=1);

/*
 * The float each seeded insurer opens at its steady state on, and the return it earns there.
 *
 * Opens each insurer as the seed does (OpeningBoardBuilder on the opening macro) at a trial float (treasury =
 * deposits), reads the premium it opens writing, and moves the float to the reserve ratio times that premium until the
 * two agree. At the fixed point it prints the written premium and the structural return: underwriting at the operating
 * margin, plus the net investment income the model books (after life crediting), less interest, after tax, on equity.
 *
 * Usage: bin/php-slot php var/harness/insurers/steady_float.php [TICKER:seed_key=value ...]
 * e.g. SAFE:operating_margin=0.02 to read a seed row at another value.
 */

require __DIR__ . '/../../../tests/bootstrap.php';

use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\OpeningBoardBuilder;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\InsuranceBusinessModel;

const ITERATIONS = 40;
/** The board's cap-weighted dividend yield the sleeve is paid, about 2.4% (joint harness, RUN.md). */
const BOARD_DIVIDEND_YIELD = 0.024;

$overrides = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^([A-Z]+):([a-z_]+)=(.+)$/', $argument, $match) === 1) {
        $overrides[$match[1]][$match[2]] = (float) $match[3];
    }
}

$opening = new MacroStateDTO();
foreach (InitialMarket::STOCKS as $row) {
    $model = Sectors::getBusinessModelStrategy(Sectors::businessModelFor($row['industry'] ?? null));
    if (!$model instanceof InsuranceBusinessModel) {
        continue;
    }

    $row = ($overrides[$row['ticker']] ?? []) + $row;
    $float = (float) $row['customer_deposits'];
    $open = static function (float $float) use ($row, $opening): Stock {
        mt_srand(20261020);
        $math = new MathUtility();
        $builder = new OpeningBoardBuilder($math, new DebtEngine($math, CorporateMetrics::getInstance()), new MarketEngine($math), new AnchorStakeLedger());
        $stock = (new Stock())->setTicker($row['ticker']);
        $builder->open($stock, ['corporate_treasury' => $float, 'customer_deposits' => $float] + $row, $opening);

        return $stock;
    };
    for ($iteration = 0; $iteration < ITERATIONS; ++$iteration) {
        $stock = $open($float);
        $float = $model->resolveReserveToPremiumRatio($stock) * (float) $stock->getTotalRevenue();
    }

    $stock = $open($float);
    $premium = (float) $stock->getTotalRevenue();
    $tax = $model->getEffectiveTaxRate($opening->corporateTaxRate);
    // The structural margin carries the average catastrophe year; the cover costs its loading over what it pays back,
    // and the reinstatements its recoveries buy, on the covered book (the reinsurer's bond shield takes 40%, with
    // none).
    $params = (new ReflectionMethod($model, 'resolveModelParameters'))->invoke($model, $stock, [
        App\Service\Model\ModelParam::CatastropheZThreshold->value => $model::CATASTROPHE_Z_THRESHOLD,
        App\Service\Model\ModelParam::CatastropheLossScalar->value => $model::CATASTROPHE_LOSS_SCALAR,
    ]);
    $layer = $model::catastropheLayer($params[App\Service\Model\ModelParam::CatastropheZThreshold], $params[App\Service\Model\ModelParam::CatastropheLossScalar], $model::DISTRICT_CATASTROPHE_LOAD);
    $price = $layer['expected_recovery'] * (1.0 + InsuranceBusinessModel::CATASTROPHE_COVER_RATE_LOADING);
    $isReinsurer = $model instanceof App\Service\Model\Sector\ReinsuranceBusinessModel;
    $bookShare = $isReinsurer ? App\Service\Model\Sector\ReinsuranceBusinessModel::TREATY_REINSURANCE_WEIGHT : 1.0 - $model->resolveLifePremiumShare($stock);
    $coverDrag = $bookShare * ($isReinsurer
        ? App\Service\Model\Sector\ReinsuranceBusinessModel::CAT_BOND_ATTACHMENT_SHIELD_SHARE * $layer['expected_recovery'] * InsuranceBusinessModel::CATASTROPHE_COVER_RATE_LOADING
        : ($layer['expected_recovery'] * InsuranceBusinessModel::CATASTROPHE_COVER_RATE_LOADING) + ($price * $layer['expected_recovery'] / $layer['limit']));
    $underwriting = $premium * ((float) $stock->getOperatingMargin() - $coverDrag);
    if ($isReinsurer) {
        // The ILS fees carry only the expense share of the variable cost line, so they keep the rest of it.
        $variableCostRatio = 1.0 - (float) $stock->getOperatingMargin() - (float) $row['fixed_cost_ratio'];
        $feeShare = App\Service\Model\Sector\ReinsuranceBusinessModel::CAT_BOND_WEIGHT
            / (App\Service\Model\Sector\ReinsuranceBusinessModel::CAT_BOND_WEIGHT + App\Service\Model\Sector\ReinsuranceBusinessModel::TREATY_REINSURANCE_WEIGHT);
        $underwriting += $premium * $feeShare * $variableCostRatio * (1.0 - InsuranceBusinessModel::BASE_EXPENSE_RATIO_SHARE);
    }
    // Reported earnings carry the equity sleeve's dividends; its price gains go to book value (the mark).
    $sleeve = $model->resolveEquitySleeve($stock, $opening);
    $investment = $model->calculateInterestIncome($stock, $opening) - ($sleeve['value'] * ($sleeve['expected_return'] - BOARD_DIVIDEND_YIELD));
    $interest = (float) $stock->getWholesaleDebt() * ((float) $row['historical_fixed_rate'] + (float) $row['credit_spread']);
    $return = ($underwriting + $investment - $interest) * (1.0 - $tax) / max(1.0, (float) $stock->getTotalEquity());

    printf(
        "%-5s seeded float %8.1fB  steady float %8.1fB  premium %7.1fB  reserve ratio %.3f  ROE %.2f%% (lore %.2f%%)\n",
        $row['ticker'],
        (float) $row['customer_deposits'] / 1e9,
        $float / 1e9,
        $premium / 1e9,
        $model->resolveReserveToPremiumRatio($stock),
        100 * $return,
        100 * (float) $row['baseline_roe'],
    );
}
