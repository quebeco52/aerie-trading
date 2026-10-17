<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;

/**
 * Earnings strategy for private banks: banks that manage wealthy clients' money, keep their cash and lend against
 * their portfolios.
 *
 * Financial Physics:
 * - Most revenue is fees on client assets, as at a custody bank, but most of those assets belong to clients abroad:
 *   the foreign share is marked on the foreign equity market and translated into District money at the exchange
 *   rate, so a strong currency cuts fees as a weak market does.
 * - Lombard loans against client portfolios are about half of earning assets, against a custodian's fifth; they are
 *   margin-called, so credit losses stay small.
 * - Inherits the custody bank's fast-repricing client cash and its securities book.
 */
class PrivateBankBusinessModel extends CustodyBankBusinessModel
{
    // --- Franchise Pricing (Julius Baer, 2019) ---
    /**
     * Fee, trading and other income per unit of assets: CHF 2,591M of operating income less net interest income on CHF
     * 102.0B of total assets (2019 results release).
     */
    public const NON_INTEREST_INCOME_TO_EARNING_ASSETS = 0.0254;
    /** Operating cost per unit of assets: adjusted operating expenses of CHF 2,466M on the same base. */
    public const OPERATING_COST_TO_EARNING_ASSETS = 0.0242;
    /** Loans as a share of assets: CHF 48.4B, of which CHF 39.5B Lombard and CHF 8.9B mortgages, on CHF 102.0B. */
    public const LOAN_SHARE_OF_EARNING_ASSETS = 0.475;

    // --- Client Domicile ---
    /**
     * Share of client wealth booked for clients abroad: CHF 2,300B of the CHF 3,700B of private assets managed in
     * Switzerland was cross-border in 2018 (Swiss Bankers Association, Banking Barometer 2019).
     */
    public const FOREIGN_CLIENT_SHARE = 0.62;

    public function getLoanShareOfEarningAssets(): float
    {
        return self::LOAN_SHARE_OF_EARNING_ASSETS;
    }

    /**
     * Fees as client assets are marked: the District's clients on the home market, as a custodian's, and the foreign
     * clients' on the foreign market's valuation, worth less in District money as the currency rises above its
     * fundamental (home value is foreign units over the exchange rate index).
     */
    protected function resolveFeeVolumeShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $foreignEquityMark = exp($macroState->foreignEquityValuation) - 1.0;
        $translation = $macroState->exchangeRateDeviation > 0.0 ? (1.0 / $macroState->exchangeRateDeviation) - 1.0 : 0.0;
        $foreignMark = (self::EQUITY_FEE_ELASTICITY * $foreignEquityMark) + $translation;

        return ((1.0 - self::FOREIGN_CLIENT_SHARE) * parent::resolveFeeVolumeShift($stock, $macroState))
            + (self::FOREIGN_CLIENT_SHARE * $foreignMark);
    }

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return array_values(array_unique([...parent::getOperatingMacroFields(), 'foreign_equity_valuation', 'exchange_rate_deviation']));
    }
}
