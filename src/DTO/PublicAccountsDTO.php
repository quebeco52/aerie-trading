<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * The Exchequer's accounts at one step (App\Service\Macro\Subsystem\CreditFiscalSubsystem::publicAccounts()): every
 * line the debt moves by, and the stocks it is measured against, so the budget is booked from one statement and the
 * pages read the same one. Flows are annual rates as shares of GDP; the one-off adjustments are this step's amounts,
 * shares of GDP; the stocks are shares of GDP.
 */
final readonly class PublicAccountsDTO
{
    public function __construct(
        // Revenue.
        public float $netTaxes,
        public float $corporateTax,
        public float $tariffs,
        public float $carbonPrice,
        public float $bankLevy,
        public float $dividendTax,
        public float $foreignBuyerTax,
        /** The reserve fund's draw and the stabilisation it pays for, revenue the budget spends. */
        public float $fundContribution,
        /** The Monetary Authority's profit paid over, nothing while it makes up past losses. */
        public float $centralBankRemittance,
        // Spending.
        public float $purchases,
        /** What the fund's contribution pays for, the same amount as it. */
        public float $fundFinancedSpending,
        // Debt service.
        public float $interest,
        /** The fall in the debt ratio nominal growth brings. */
        public float $growthErosion,
        // One-off adjustments this step (stock-flow adjustments, IMF GFSM 2014).
        public float $bankRescueOutlay,
        public float $bankRescueSales,
        // Stocks.
        public float $grossDebt,
        /** The fund's bonds, the debt instruments the government holds. */
        public float $fundBonds,
        /** The whole fund, bonds and equities. */
        public float $fundAssets,
        /** Bank shares and loans the Exchequer still holds from its rescues. */
        public float $bankRescueHoldings,
    ) {}

    public function revenue(): float
    {
        return $this->netTaxes + $this->corporateTax + $this->tariffs + $this->carbonPrice + $this->bankLevy
            + $this->dividendTax + $this->foreignBuyerTax + $this->fundContribution + $this->centralBankRemittance;
    }

    public function spending(): float
    {
        return $this->purchases + $this->fundFinancedSpending;
    }

    public function primaryDeficit(): float
    {
        return $this->spending() - $this->revenue();
    }

    /** The change in gross debt to GDP over a step of $dt years, before the floor. */
    public function debtChange(float $dt): float
    {
        return (($this->primaryDeficit() + $this->interest - $this->growthErosion) * $dt) + $this->bankRescueOutlay - $this->bankRescueSales;
    }

    /** IMF GFSM 2014 net debt: gross debt less the debt instruments held, the fund's bonds. */
    public function netDebt(): float
    {
        return $this->grossDebt - $this->fundBonds;
    }

    /** Net financial worth: everything the government holds less what it owes. */
    public function netFinancialWorth(): float
    {
        return $this->fundAssets + $this->bankRescueHoldings - $this->grossDebt;
    }
}
