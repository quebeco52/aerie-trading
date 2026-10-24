<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\MacroDriverAttribution;
use App\Service\Math\CreditRisk;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ConglomerateBusinessModel;

/**
 * A quarter with the IG spread held over its trend and moving again, run through a conglomerate-family model's drivers
 * panel: the two lines the panel prints for the spread on the float book, beside the two effects the float book's own
 * arithmetic books on it (the deployment premium on the level, the mark on the move). Everything else sits at neutral
 * and the draws carry no volatility, so the book earns nothing else.
 */
final class FloatBookCreditDrivers
{
    /** The spread's decade trend, set off the base the economy opens at so a neutral struck at the base shows. */
    public const SPREAD_TREND = 0.015;

    /** The smoothed spread over its trend (100 bps): the level dry powder is deployed at. */
    public const LEVEL_GAP = 0.010;

    /** The spot over the smoothed spread (40 bps): the move the held book is marked on. */
    public const MOVE_GAP = 0.004;

    private const EXPECTED_REVENUE = 100_000_000.0;
    private const VARIABLE_MARGIN = 0.40;
    private const FIXED_COSTS = 10_000_000.0;

    /**
     * @return array{level: float, move: float, deployment: float, mark: float} The panel's level and move lines on the
     *                                                                           float stream, and the book's own
     *                                                                           deployment premium and mark.
     */
    public static function measure(ConglomerateBusinessModel $model, Stock $stock): array
    {
        $macroState = new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: self::SPREAD_TREND + self::LEVEL_GAP + self::MOVE_GAP,
            macroCreditSpreadEma: self::SPREAD_TREND + self::LEVEL_GAP,
            macroCreditSpreadTrend: self::SPREAD_TREND,
            policyRateEma: (new MacroStateDTO())->perceivedNeutralRate,
        );

        mt_srand(17);
        $math = new MathUtility();
        $basis = clone $stock;
        $math->beginDrawLog();
        $actual = $model->computeActualFinancials($stock, self::EXPECTED_REVENUE, self::VARIABLE_MARGIN, self::FIXED_COSTS, 0.0, $macroState, $math);
        $draws = $math->endDrawLog();

        $states = MacroDriverAttribution::counterfactualStates($model, $macroState);
        $counterfactuals = [];
        foreach (['macro_credit_spread_ema', 'macro_credit_spread'] as $field) {
            $counterfactuals[$field] = [$states[$field], self::EXPECTED_REVENUE];
        }
        $effects = $model->measureStreamMacroEffects($basis, $draws, $counterfactuals, self::VARIABLE_MARGIN, self::FIXED_COSTS, 0.0, $actual->streamRevenue);

        return [
            'level' => $effects['financial_investments']['macro_credit_spread_ema'],
            'move' => $effects['financial_investments']['macro_credit_spread'],
            'deployment' => CreditRisk::calculateDiminishingDistressMultiplier(
                self::LEVEL_GAP * ConglomerateBusinessModel::FLOAT_SPREAD_BLOWOUT_SCALAR,
                ConglomerateBusinessModel::MAX_CONTRARIAN_FLOAT_EXPANSION,
                ConglomerateBusinessModel::CONTRARIAN_HALF_SATURATION_POINT,
            ),
            'mark' => CreditRisk::calculateCreditSpreadMarkToMarket(self::MOVE_GAP, ConglomerateBusinessModel::FLOAT_SPREAD_DURATION),
        ];
    }
}
