<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\DTO\PoliticsStateDTO;
use App\Service\Macro\MacroEngine;

/**
 * The Council's debt brake: above a line of debt to GDP it holds any budget that widens the deficit, unless a
 * supermajority that could remove councillors passes it (PoliticsEngine::budget()). The line is not written in the
 * Charter; it is the Council's own judgement of where debt starts to cost growth.
 *
 * Every councillor believes one published estimate of that threshold for advanced economies, each as likely, and the
 * Council's line is its median member's. A Council that turns over one seat at a time moves its line slowly, and only
 * when a new member shifts the median.
 */
final class DebtBrake
{
    // --- Published Debt Thresholds (advanced economies, gross general government debt over GDP) ---
    /**
     * Point estimates of the debt beyond which growth suffers, sorted: Lee, Park, Seo & Shin 2017 (30%), Elmeskov &
     * Sutherland 2012 (66%), Cecchetti, Mohanty & Zampolli 2011 (85%), Padoan, Sila & van den Noord 2012 (86.5%),
     * Mencinger, Aristovnik & Verbic 2014 (87%), Reinhart & Rogoff 2010 and Kumar & Woo 2010 (90%), Checherita-Westphal &
     * Rother 2012 and Baum, Checherita-Westphal & Rother 2013 (95%). Studies finding no common threshold (Herndon, Ash &
     * Pollin 2014; Eberhardt & Presbitero 2015; Chudik et al. 2017) give no line to hold.
     */
    public const PUBLISHED_THRESHOLDS = [0.30, 0.66, 0.85, 0.865, 0.87, 0.90, 0.90, 0.95, 0.95];

    /** The threshold a councillor believes, drawn from a uniform: any published estimate, each as likely. */
    public static function drawLine(float $uniform): float
    {
        $count = count(self::PUBLISHED_THRESHOLDS);

        return self::PUBLISHED_THRESHOLDS[min($count - 1, (int) floor($uniform * $count))];
    }

    /** The Council's line: its sitting members' median belief; before the Council has formed, Reinhart & Rogoff's 90%. */
    public static function councilLine(PoliticsState|PoliticsStateDTO $state): float
    {
        return $state->councilDebtLines === [] ? MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD : CouncilAppointments::median($state->councilDebtLines);
    }
}
