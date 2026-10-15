<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * The Exchequer's accounts at one step (App\Service\Macro\Subsystem\CreditFiscalSubsystem::publicAccounts()): every
 * line the debt moves by, and the stocks it is measured against, so the budget is booked from one statement and the
 * pages read the same one. Flows are annual rates as shares of GDP; the stocks are shares of GDP.
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
        /** The reserve fund's draw, revenue the budget spends. */
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
        // Stocks.
        public float $grossDebt,
        /** The fund's bonds, the debt instruments the government holds. */
        public float $fundBonds,
        /** The whole fund, bonds and equities. */
        public float $fundAssets,
    ) {}

    public function revenue(): float
    {
        return $this->netTaxes + $this->corporateTax + $this->policyLevies() + $this->fundContribution + $this->centralBankRemittance;
    }

    /** What the Diet's policy levies raise beyond the founding budget: the tariff, carbon price, bank levy, dividend tax shift and foreign buyer tax. */
    public function policyLevies(): float
    {
        return $this->tariffs + $this->carbonPrice + $this->bankLevy + $this->dividendTax + $this->foreignBuyerTax;
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
        return ($this->primaryDeficit() + $this->interest - $this->growthErosion) * $dt;
    }

    /** IMF GFSM 2014 net debt: gross debt less the debt instruments held, the fund's bonds. */
    public function netDebt(): float
    {
        return $this->grossDebt - $this->fundBonds;
    }

    /** Net financial worth: everything the government holds less what it owes. */
    public function netFinancialWorth(): float
    {
        return $this->fundAssets - $this->grossDebt;
    }
}
