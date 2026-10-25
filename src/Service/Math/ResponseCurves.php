<?php

declare(strict_types=1);

namespace App\Service\Math;

/**
 * Shapes that map a driver to a response: the logistic on the unit interval, the Bass adoption curve, the kinked
 * asymmetric response, simplex weight normalisation and the convex penalty. Pure functions of their arguments.
 */
final class ResponseCurves
{
    /**
     * Maps a real value onto the open interval (0, 1) with a logistic curve.
     * Strictly monotonic, so inputs past the calibration window keep differentiating
     * instead of flat-lining against a clamp. At a midpoint of 0 and a steepness of 1 it is a
     * logit model's probability from its log-odds.
     *
     * @param float $x         Input value.
     * @param float $midpoint  Input that maps to 0.5.
     * @param float $steepness Logistic growth rate; larger = sharper transition.
     * @return float A value in (0, 1).
     */
    public static function logisticUnitInterval(float $x, float $midpoint, float $steepness): float
    {
        return 1.0 / (1.0 + exp(-$steepness * ($x - $midpoint)));
    }

    /**
     * Share of its eventual market a new product has won $years after launch under the Bass (1969) diffusion model:
     * each year a share $innovation of those yet to adopt does so on its own, and a share $imitation times the share
     * already won follows them. An S-curve, slow while few have adopted, fastest at ln(q / p) / (p + q) years, then
     * saturating. Zero before launch.
     *
     * @param float $years      Years since launch.
     * @param float $innovation Coefficient of innovation p, per year.
     * @param float $imitation  Coefficient of imitation q, per year.
     */
    public static function bassAdoption(float $years, float $innovation, float $imitation): float
    {
        if ($years <= 0.0 || $innovation <= 0.0) {
            return 0.0;
        }
        $decay = exp(-($innovation + $imitation) * $years);

        return (1.0 - $decay) / (1.0 + (($imitation / $innovation) * $decay));
    }

    /**
     * Adoption per year under the Bass (1969) model, the slope of bassAdoption(): p at launch, peaking where imitation
     * takes over, zero before launch.
     *
     * @param float $years      Years since launch.
     * @param float $innovation Coefficient of innovation p, per year.
     * @param float $imitation  Coefficient of imitation q, per year.
     */
    public static function bassAdoptionRate(float $years, float $innovation, float $imitation): float
    {
        if ($years < 0.0 || $innovation <= 0.0) {
            return 0.0;
        }
        $total = $innovation + $imitation;
        $decay = exp(-$total * $years);
        $spread = 1.0 + (($imitation / $innovation) * $decay);

        return ($total * $total / $innovation) * $decay / ($spread * $spread);
    }

    /**
     * Kinked linear response to a signed driver: one slope above zero, another below.
     *
     * The reduced form of an occasionally binding constraint (Mendoza 2010; Guerrieri & Iacoviello 2017): tight
     * conditions bind and cut spending at one rate, loose ones relax a slack constraint and lift it at another.
     * Continuous at zero, so the response has no jump where the driver changes sign.
     *
     * @param float $driver     Signed driver, zero at neutral.
     * @param float $slopeAbove Response per unit of a positive driver.
     * @param float $slopeBelow Response per unit of a negative driver.
     * @return float The response.
     */
    public static function calculateAsymmetricResponse(float $driver, float $slopeAbove, float $slopeBelow): float
    {
        return $driver >= 0.0 ? $slopeAbove * $driver : $slopeBelow * $driver;
    }

    /**
     * Normalizes an array of weights to sum strictly to 1.0 (simplex projection).
     * Clamps weights to non-negative values to ensure valid probability simplex.
     *
     * @param array<string, float> $weights
     * @return array<string, float>
     */
    public static function normalizeWeightsSimplex(array $weights): array
    {
        $clamped = array_map(fn($w) => max(0.0, (float) $w), $weights);
        $total = array_sum($clamped);
        if ($total <= 0.0) {
            $count = count($clamped);
            $equal = $count > 0 ? 1.0 / $count : 1.0;
            return array_map(fn() => $equal, $clamped);
        }

        $normalized = [];
        foreach ($clamped as $key => $weight) {
            $normalized[$key] = $weight / $total;
        }

        return $normalized;
    }

    /**
     * Calculates a convex penalty using power-law scaling to model non-linear demand destruction,
     * bullwhip supply chain freezes, or aggressive promotional inventory markdowns.
     *
     * Formula: Penalty = (max(0, Shock))^Convexity * Scalar
     *
     * @param float $shock     The magnitude of the shock/contraction (e.g. abs(outputGap)).
     * @param float $convexity The degree of convexity (e.g. 1.5 or 2.0).
     * @param float $scalar    The scaling factor.
     * @return float The non-linear convex penalty.
     */
    public static function calculateConvexPenalty(float $shock, float $convexity = 1.5, float $scalar = 1.0): float
    {
        if ($shock <= 0.0) {
            return 0.0;
        }

        return pow($shock, $convexity) * $scalar;
    }
}
