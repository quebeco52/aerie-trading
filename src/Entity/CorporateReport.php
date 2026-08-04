<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'corporate_report')]
#[ORM\Index(name: 'idx_report_recorded', columns: ['stock_id', 'recorded_at'])]
class CorporateReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Stock::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Stock $stock;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $netIncome = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $equity = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $totalDebt = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $treasury = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 4, nullable: true)]
    private ?string $roic = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $shares = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $recordedAt;

    #[ORM\Column(type: 'decimal', precision: 20, scale: 4)]
    private string $interestExpense = '0.0000';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 6)]
    private string $blendedRate = '0.000000';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 6)]
    private string $dynamicSpread = '0.000000';

    #[ORM\Column(type: 'decimal', precision: 20, scale: 4)]
    private string $revenue = '0.0000';

    #[ORM\Column(type: 'decimal', precision: 20, scale: 4)]
    private string $interestIncome = '0.0000';

    #[ORM\Column(type: 'decimal', precision: 20, scale: 4)]
    private string $capitalExpenditures = '0.0000';

    #[ORM\Column(type: 'decimal', precision: 20, scale: 4, options: ['default' => '0.0000'])]
    private string $operatingCosts = '0.0000';

    #[ORM\Column(type: 'decimal', precision: 20, scale: 4, options: ['default' => '0.0000'])]
    private string $ebit = '0.0000';

    #[ORM\Column(type: 'decimal', precision: 20, scale: 4, options: ['default' => '0.0000'])]
    private string $preTaxIncome = '0.0000';

    #[ORM\Column(type: 'decimal', precision: 20, scale: 4, options: ['default' => '0.0000'])]
    private string $taxPaid = '0.0000';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $wacc = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $eva = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, options: ['default' => '0.0000'])]
    private string $dividendPaid = '0.0000';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, options: ['default' => '0.0000'])]
    private string $stockBuybacks = '0.0000';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $returnOnEquity = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $costOfEquity = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $capitalRatio = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $customerDepositRatio = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $operatingMargin = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $depositApy = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $cashYield = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $freeCashFlow = null;

    public function __construct()
    {
        $this->recordedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStock(): Stock
    {
        return $this->stock;
    }

    public function setStock(Stock $stock): static
    {
        $this->stock = $stock;
        return $this;
    }

    public function getNetIncome(): ?string
    {
        return $this->netIncome;
    }

    public function setNetIncome(?string $netIncome): static
    {
        $this->netIncome = $netIncome;
        return $this;
    }

    public function getEquity(): ?string
    {
        return $this->equity;
    }

    public function setEquity(?string $equity): static
    {
        $this->equity = $equity;
        return $this;
    }

    public function getTreasury(): ?string
    {
        return $this->treasury;
    }

    public function setTreasury(?string $treasury): static
    {
        $this->treasury = $treasury;
        return $this;
    }

    public function getRoic(): ?string
    {
        return $this->roic;
    }

    public function setRoic(?string $roic): static
    {
        $this->roic = $roic;
        return $this;
    }

    public function getShares(): ?string
    {
        return $this->shares;
    }

    public function setShares(?string $shares): static
    {
        $this->shares = $shares;
        return $this;
    }

    public function getRecordedAt(): \DateTimeInterface
    {
        return $this->recordedAt;
    }

    public function setRecordedAt(\DateTimeInterface $recordedAt): static
    {
        $this->recordedAt = $recordedAt;
        return $this;
    }

    public function getTotalDebt(): ?string
    {
        return $this->totalDebt;
    }

    public function setTotalDebt(?string $totalDebt): static
    {
        $this->totalDebt = $totalDebt;

        return $this;
    }

    public function getInterestExpense(): ?string
    {
        return $this->interestExpense;
    }

    public function setInterestExpense(string $interestExpense): static
    {
        $this->interestExpense = $interestExpense;

        return $this;
    }

    public function getBlendedRate(): ?string
    {
        return $this->blendedRate;
    }

    public function setBlendedRate(string $blendedRate): static
    {
        $this->blendedRate = $blendedRate;

        return $this;
    }

    public function getDynamicSpread(): ?string
    {
        return $this->dynamicSpread;
    }

    public function setDynamicSpread(string $dynamicSpread): static
    {
        $this->dynamicSpread = $dynamicSpread;

        return $this;
    }

    public function getRevenue(): ?string
    {
        return $this->revenue;
    }

    public function setRevenue(string $revenue): static
    {
        $this->revenue = $revenue;

        return $this;
    }

    public function getInterestIncome(): ?string
    {
        return $this->interestIncome;
    }

    public function setInterestIncome(string $interestIncome): static
    {
        $this->interestIncome = $interestIncome;

        return $this;
    }

    public function getCapitalExpenditures(): ?string
    {
        return $this->capitalExpenditures;
    }

    public function setCapitalExpenditures(string $capitalExpenditures): static
    {
        $this->capitalExpenditures = $capitalExpenditures;

        return $this;
    }

    public function getWacc(): ?string
    {
        return $this->wacc;
    }

    public function setWacc(string $wacc): static
    {
        $this->wacc = $wacc;

        return $this;
    }

    public function getEva(): ?string
    {
        return $this->eva;
    }

    public function setEva(?string $eva): static
    {
        $this->eva = $eva;

        return $this;
    }

    public function getDividendPaid(): string
    {
        return $this->dividendPaid;
    }

    public function setDividendPaid(string $dividendPaid): static
    {
        $this->dividendPaid = $dividendPaid;

        return $this;
    }

    public function getStockBuybacks(): string
    {
        return $this->stockBuybacks;
    }

    public function setStockBuybacks(string $stockBuybacks): static
    {
        $this->stockBuybacks = $stockBuybacks;

        return $this;
    }

    public function getReturnOnEquity(): ?string
    {
        return $this->returnOnEquity;
    }

    public function setReturnOnEquity(?string $returnOnEquity): static
    {
        $this->returnOnEquity = $returnOnEquity;

        return $this;
    }

    public function getCostOfEquity(): ?string
    {
        return $this->costOfEquity;
    }

    public function setCostOfEquity(?string $costOfEquity): static
    {
        $this->costOfEquity = $costOfEquity;

        return $this;
    }

    public function getCapitalRatio(): ?string
    {
        return $this->capitalRatio;
    }

    public function setCapitalRatio(?string $capitalRatio): static
    {
        $this->capitalRatio = $capitalRatio;

        return $this;
    }

    public function getCustomerDepositRatio(): ?string
    {
        return $this->customerDepositRatio;
    }

    public function setCustomerDepositRatio(?string $customerDepositRatio): static
    {
        $this->customerDepositRatio = $customerDepositRatio;
        return $this;
    }

    public function getOperatingMargin(): ?string
    {
        return $this->operatingMargin;
    }

    public function setOperatingMargin(?string $operatingMargin): static
    {
        $this->operatingMargin = $operatingMargin;

        return $this;
    }

    public function getDepositApy(): ?string
    {
        return $this->depositApy;
    }

    public function setDepositApy(?string $depositApy): static
    {
        $this->depositApy = $depositApy;

        return $this;
    }

    public function getCashYield(): ?string
    {
        return $this->cashYield;
    }

    public function setCashYield(?string $cashYield): static
    {
        $this->cashYield = $cashYield;

        return $this;
    }

    public function getFreeCashFlow(): ?string
    {
        return $this->freeCashFlow;
    }

    public function setFreeCashFlow(?string $freeCashFlow): static
    {
        $this->freeCashFlow = $freeCashFlow;
        return $this;
    }

    public function getOperatingCosts(): string
    {
        return $this->operatingCosts;
    }

    public function setOperatingCosts(string $operatingCosts): static
    {
        $this->operatingCosts = $operatingCosts;
        return $this;
    }

    public function getEbit(): string
    {
        return $this->ebit;
    }

    public function setEbit(string $ebit): static
    {
        $this->ebit = $ebit;
        return $this;
    }

    public function getPreTaxIncome(): string
    {
        return $this->preTaxIncome;
    }

    public function setPreTaxIncome(string $preTaxIncome): static
    {
        $this->preTaxIncome = $preTaxIncome;
        return $this;
    }

    public function getTaxPaid(): string
    {
        return $this->taxPaid;
    }

    public function setTaxPaid(string $taxPaid): static
    {
        $this->taxPaid = $taxPaid;
        return $this;
    }
}