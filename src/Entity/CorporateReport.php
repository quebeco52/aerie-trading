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

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $roic = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isAudited = false;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $revenueStreams = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $streamDetails = null;

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

    /** Depreciation expensed this quarter, the line separating EBITDA from EBIT. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $depreciation = null;

    /** Earnings before interest, tax, depreciation and amortization. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $ebitda = null;

    /** Historical cost of property, plant and equipment placed in service. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $grossPpe = null;

    /** Gross loans, securities and other earning assets of a balance-sheet business at quarter end. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $earningAssets = null;

    /** Allowance for credit losses carried against the earning assets (ASC 326). */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $creditLossAllowance = null;

    /** Provision for credit losses charged to earnings this quarter, net of any reserve release. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $creditLossProvision = null;

    /** Loans written off against the allowance this quarter. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $netChargeOffs = null;

    /** Cash deployed into new earning assets, net of assets sold: the investing flow of a lender. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $netLoanOriginations = null;

    /** Loss realized on earning assets sold below carrying value to meet withdrawals or maturities. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $assetSaleLoss = null;

    /** Customer deposits (or policyholder float) at quarter end, the part of total debt that is not wholesale. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $customerDeposits = null;

    /** Common equity tier 1 ratio, equity over risk-weighted assets, for a regulated bank. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $cet1Ratio = null;

    /** Annualized net interest margin: interest earned less interest paid, over net earning assets. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $netInterestMargin = null;

    /** Net book value of property, plant and equipment. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $netPpe = null;

    /** Trade receivables net of the expected credit loss allowance. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $receivables = null;

    /** Inventory carried at cost after any writedown. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $inventory = null;

    /** Trade payables outstanding. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $payables = null;

    /** Lower-of-cost-or-NRV writedown charged against inventory this quarter (ASC 330). */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $inventoryWriteDown = null;

    /** Expected credit loss provision charged against receivables this quarter (ASC 326). */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $receivablesProvision = null;

    /** Portion of the tax expense postponed by accelerated tax depreciation (ASC 740). */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $deferredTaxExpense = null;

    /** Accumulated deferred tax liability at the end of the quarter. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $deferredTaxLiability = null;

    /** Tax that actually left the company this quarter. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $cashTaxPaid = null;

    /** Construction in progress: capital committed but not yet earning. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $cip = null;

    /** Goodwill carried from past acquisitions. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $goodwill = null;

    /** Capitalized operating lease obligation (IFRS 16 / ASC 842). */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $leaseLiability = null;

    /** Total assets at the end of the quarter. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $totalAssets = null;

    /** Total liabilities at the end of the quarter. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $totalLiabilities = null;

    /** Net cash generated by operations. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $operatingCashFlow = null;

    /** Net cash used in investing; negative means the firm is a net investor. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $investingCashFlow = null;

    /** Net cash from financing; negative means capital was returned rather than raised. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $financingCashFlow = null;

    /** Non-cash equity compensation expensed this quarter (ASC 718). */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $stockCompensation = null;

    /** Goodwill written off in the annual impairment test (ASC 350). */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $goodwillImpairment = null;

    /** Dickinson (2011) life-cycle stage implied by this quarter's three cash-flow signs. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $lifecycleStage = null;

    /** Accumulated depreciation over gross PP&E: the average age of the plant, 0 new to 1 fully written off. */
    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 4, nullable: true)]
    private ?string $assetAge = null;

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

    public function getRevenueStreams(): ?array
    {
        return $this->revenueStreams;
    }

    public function setRevenueStreams(?array $revenueStreams): static
    {
        $this->revenueStreams = $revenueStreams;

        return $this;
    }

    public function getStreamDetails(): ?array
    {
        return $this->streamDetails;
    }

    public function setStreamDetails(?array $streamDetails): static
    {
        $this->streamDetails = $streamDetails;

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

    public function getDepreciation(): ?string
    {
        return $this->depreciation;
    }

    public function setDepreciation(?string $depreciation): static
    {
        $this->depreciation = $depreciation;
        return $this;
    }

    public function getEbitda(): ?string
    {
        return $this->ebitda;
    }

    public function setEbitda(?string $ebitda): static
    {
        $this->ebitda = $ebitda;
        return $this;
    }

    public function getGrossPpe(): ?string
    {
        return $this->grossPpe;
    }

    public function setGrossPpe(?string $grossPpe): static
    {
        $this->grossPpe = $grossPpe;
        return $this;
    }

    public function getNetPpe(): ?string
    {
        return $this->netPpe;
    }

    public function setNetPpe(?string $netPpe): static
    {
        $this->netPpe = $netPpe;
        return $this;
    }

    public function getReceivables(): ?string
    {
        return $this->receivables;
    }

    public function setReceivables(?string $receivables): static
    {
        $this->receivables = $receivables;
        return $this;
    }

    public function getInventory(): ?string
    {
        return $this->inventory;
    }

    public function setInventory(?string $inventory): static
    {
        $this->inventory = $inventory;
        return $this;
    }

    public function getPayables(): ?string
    {
        return $this->payables;
    }

    public function setPayables(?string $payables): static
    {
        $this->payables = $payables;
        return $this;
    }

    public function getInventoryWriteDown(): ?string
    {
        return $this->inventoryWriteDown;
    }

    public function setInventoryWriteDown(?string $inventoryWriteDown): static
    {
        $this->inventoryWriteDown = $inventoryWriteDown;
        return $this;
    }

    public function getReceivablesProvision(): ?string
    {
        return $this->receivablesProvision;
    }

    public function setReceivablesProvision(?string $receivablesProvision): static
    {
        $this->receivablesProvision = $receivablesProvision;
        return $this;
    }

    public function getDeferredTaxExpense(): ?string
    {
        return $this->deferredTaxExpense;
    }

    public function setDeferredTaxExpense(?string $deferredTaxExpense): static
    {
        $this->deferredTaxExpense = $deferredTaxExpense;
        return $this;
    }

    public function getDeferredTaxLiability(): ?string
    {
        return $this->deferredTaxLiability;
    }

    public function setDeferredTaxLiability(?string $deferredTaxLiability): static
    {
        $this->deferredTaxLiability = $deferredTaxLiability;
        return $this;
    }

    public function getCashTaxPaid(): ?string
    {
        return $this->cashTaxPaid;
    }

    public function setCashTaxPaid(?string $cashTaxPaid): static
    {
        $this->cashTaxPaid = $cashTaxPaid;
        return $this;
    }

    public function getCip(): ?string
    {
        return $this->cip;
    }

    public function setCip(?string $cip): static
    {
        $this->cip = $cip;
        return $this;
    }

    public function getGoodwill(): ?string
    {
        return $this->goodwill;
    }

    public function setGoodwill(?string $goodwill): static
    {
        $this->goodwill = $goodwill;
        return $this;
    }

    public function getLeaseLiability(): ?string
    {
        return $this->leaseLiability;
    }

    public function setLeaseLiability(?string $leaseLiability): static
    {
        $this->leaseLiability = $leaseLiability;
        return $this;
    }

    public function getTotalAssets(): ?string
    {
        return $this->totalAssets;
    }

    public function setTotalAssets(?string $totalAssets): static
    {
        $this->totalAssets = $totalAssets;
        return $this;
    }

    public function getTotalLiabilities(): ?string
    {
        return $this->totalLiabilities;
    }

    public function setTotalLiabilities(?string $totalLiabilities): static
    {
        $this->totalLiabilities = $totalLiabilities;
        return $this;
    }

    public function getOperatingCashFlow(): ?string
    {
        return $this->operatingCashFlow;
    }

    public function setOperatingCashFlow(?string $operatingCashFlow): static
    {
        $this->operatingCashFlow = $operatingCashFlow;
        return $this;
    }

    public function getInvestingCashFlow(): ?string
    {
        return $this->investingCashFlow;
    }

    public function setInvestingCashFlow(?string $investingCashFlow): static
    {
        $this->investingCashFlow = $investingCashFlow;
        return $this;
    }

    public function getFinancingCashFlow(): ?string
    {
        return $this->financingCashFlow;
    }

    public function setFinancingCashFlow(?string $financingCashFlow): static
    {
        $this->financingCashFlow = $financingCashFlow;
        return $this;
    }

    public function getStockCompensation(): ?string
    {
        return $this->stockCompensation;
    }

    public function setStockCompensation(?string $stockCompensation): static
    {
        $this->stockCompensation = $stockCompensation;
        return $this;
    }

    public function getGoodwillImpairment(): ?string
    {
        return $this->goodwillImpairment;
    }

    public function setGoodwillImpairment(?string $goodwillImpairment): static
    {
        $this->goodwillImpairment = $goodwillImpairment;
        return $this;
    }

    public function getLifecycleStage(): ?string
    {
        return $this->lifecycleStage;
    }

    public function setLifecycleStage(?string $lifecycleStage): static
    {
        $this->lifecycleStage = $lifecycleStage;
        return $this;
    }

    public function getAssetAge(): ?string
    {
        return $this->assetAge;
    }

    public function setAssetAge(?string $assetAge): static
    {
        $this->assetAge = $assetAge;
        return $this;
    }

    public function getEarningAssets(): ?string
    {
        return $this->earningAssets;
    }

    public function setEarningAssets(?string $earningAssets): static
    {
        $this->earningAssets = $earningAssets;
        return $this;
    }

    public function getCreditLossAllowance(): ?string
    {
        return $this->creditLossAllowance;
    }

    public function setCreditLossAllowance(?string $creditLossAllowance): static
    {
        $this->creditLossAllowance = $creditLossAllowance;
        return $this;
    }

    public function getCreditLossProvision(): ?string
    {
        return $this->creditLossProvision;
    }

    public function setCreditLossProvision(?string $creditLossProvision): static
    {
        $this->creditLossProvision = $creditLossProvision;
        return $this;
    }

    public function getNetChargeOffs(): ?string
    {
        return $this->netChargeOffs;
    }

    public function setNetChargeOffs(?string $netChargeOffs): static
    {
        $this->netChargeOffs = $netChargeOffs;
        return $this;
    }

    public function getNetLoanOriginations(): ?string
    {
        return $this->netLoanOriginations;
    }

    public function setNetLoanOriginations(?string $netLoanOriginations): static
    {
        $this->netLoanOriginations = $netLoanOriginations;
        return $this;
    }

    public function getAssetSaleLoss(): ?string
    {
        return $this->assetSaleLoss;
    }

    public function setAssetSaleLoss(?string $assetSaleLoss): static
    {
        $this->assetSaleLoss = $assetSaleLoss;
        return $this;
    }

    public function getCustomerDeposits(): ?string
    {
        return $this->customerDeposits;
    }

    public function setCustomerDeposits(?string $customerDeposits): static
    {
        $this->customerDeposits = $customerDeposits;
        return $this;
    }

    public function getCet1Ratio(): ?string
    {
        return $this->cet1Ratio;
    }

    public function setCet1Ratio(?string $cet1Ratio): static
    {
        $this->cet1Ratio = $cet1Ratio;
        return $this;
    }

    public function getNetInterestMargin(): ?string
    {
        return $this->netInterestMargin;
    }

    public function setNetInterestMargin(?string $netInterestMargin): static
    {
        $this->netInterestMargin = $netInterestMargin;
        return $this;
    }
}
