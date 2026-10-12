<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Model\ModelParam;

/**
 * Earnings strategy for custody banks: banks that hold and service other institutions' securities and keep their cash.
 *
 * Financial Physics:
 * - Most revenue is servicing fees charged in basis points on assets under custody, which are marked to market: fees
 *   rise and fall with the equity market's level, and the share earned on the Sovereign Reserve Fund's book with the
 *   fund's size against the economy.
 * - The balance sheet is the clients' operational cash, invested mostly in securities; loans are a small share, so
 *   the credit cycle barely reaches it.
 * - Institutional cash reprices fast: the deposit beta is far above a retail bank's, so net interest income gains less
 *   from rising rates.
 * - Inherits the deposit bank's balance sheet, capital rules and securities book from CommercialBankBusinessModel.
 */
class CustodyBankBusinessModel extends CommercialBankBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle. The cycle reaches custody fees through the asset values this model reads, so the engine's output-gap terms would count it twice (band floor). */
    public const OPERATING_CYCLICALITY = 0.20;

    // --- Franchise Pricing (State Street, 2019) ---
    /** Fee revenue per unit of earning assets: $9.15B of fees in 2019 on $179.1B of average interest-earning assets (State Street 4Q19 release; 1Q19 10-Q). */
    public const NON_INTEREST_INCOME_TO_EARNING_ASSETS = 0.0511;
    /** Operating cost per unit of earning assets: $9.03B of expenses in 2019 on the same base. */
    public const OPERATING_COST_TO_EARNING_ASSETS = 0.0504;
    /** Loans as a share of loans and securities: 9.2% and 41.5% of average assets in 4Q19 (State Street 4Q19 presentation). */
    public const LOAN_SHARE_OF_EARNING_ASSETS = 0.18;

    // --- Institutional Deposits ---
    /** Deposit beta of institutional cash: about 75% on US interest-bearing deposits over the 2022-23 cycle (State Street 4Q23 call), on the 82.3% of deposits that bear interest (4Q19 averages). */
    public const CUSTODY_DEPOSIT_BETA = 0.75 * 0.823;

    // --- Assets Under Custody ---
    /** Servicing fees per unit of equity-market value: a 10% move in world equity valuations moves total revenue about 2% (State Street 10-K, 2010-2013), on servicing and management fees of about 60% of revenue. Fixed-income valuations move it half as much; the macro carries no measured trend for bond prices, so that leg is left out. */
    public const EQUITY_FEE_ELASTICITY = 0.33;
    /** Default share of servicing fees earned on the Sovereign Reserve Fund's book. */
    public const FUND_CUSTODY_SHARE = 0.0;

    public function getLoanShareOfEarningAssets(): float
    {
        return self::LOAN_SHARE_OF_EARNING_ASSETS;
    }

    /** Institutional cash is paid its own beta, whatever the retail system's. */
    protected function resolveDepositRate(float $policyRate, float $systemDepositBeta): float
    {
        return max(self::MIN_DEPOSIT_RATE, $policyRate * self::CUSTODY_DEPOSIT_BETA);
    }

    /** The custody book's interest income moves with rates and deposits, not with loan origination. */
    protected function resolveInterestVolumeShift(MacroStateDTO $macroState): float
    {
        return 0.0;
    }

    /**
     * Servicing fees as the assets they are charged on are marked: the equity market against its trend at the fee
     * elasticity, and the fund's book at its size against the economy, which already carries its returns.
     */
    protected function resolveFeeVolumeShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $fundShare = $this->resolveFundCustodyShare($stock);
        $equityMark = $macroState->equityWealthTrend > 0.0 ? ($macroState->equityWealthRatio / $macroState->equityWealthTrend) - 1.0 : 0.0;
        $fundMark = $macroState->sovereignFundToGdp > 0.0 ? ($macroState->sovereignFundToGdp / MacroEngine::OPENING_FUND_TO_GDP) - 1.0 : 0.0;

        return ((1.0 - $fundShare) * self::EQUITY_FEE_ELASTICITY * $equityMark) + ($fundShare * $fundMark);
    }

    /** Share of servicing fees earned on the fund's book, ticker override first. */
    public function resolveFundCustodyShare(Stock $stock): float
    {
        $params = $this->resolveModelParameters($stock, [ModelParam::FundCustodyShare->value => self::FUND_CUSTODY_SHARE]);

        return max(0.0, min(1.0, (float) $params[ModelParam::FundCustodyShare]));
    }

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        $fields = array_diff(parent::getOperatingMacroFields(), [
            'credit_to_gdp_gap_ema',
            'housing_starts_index_ema',
            'money_supply_growth_ema',
            'money_supply_growth_trend',
            'sloos_tightening_index_ema',
        ]);

        return array_values(array_unique([...$fields, 'equity_wealth_ratio', 'equity_wealth_trend', 'sovereign_fund_to_gdp']));
    }
}
