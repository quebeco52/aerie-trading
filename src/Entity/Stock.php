<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Represents a publicly traded stock on the Aerie Exchange.
 * * Uses Absolute Values (Total Net Income, Total Equity) to maintain 
 * mathematically flawless accounting during splits, buyouts, and buybacks.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stocks')]
class Stock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @var string The unique trading symbol (e.g., 'LAKE').
     */
    #[ORM\Column(length: 10, unique: true)]
    private string $ticker;

    /**
     * @var string The full corporate name of the company.
     */
    #[ORM\Column(length: 255)]
    private string $name;

    /**
     * @var string The macroeconomic sector this stock belongs to (e.g., 'Financials').
     */
    #[ORM\Column(length: 50, options: ['default' => 'General'])]
    private string $sector = 'General';

    /**
     * @var string The current share price of the stock.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, options: ['default' => '100.00000000'])]
    private string $price = '100.00000000';

    /**
     * @var int|string The total number of shares issued by the corporation.
     *                 Stored absolutely to maintain perfect math during splits/buybacks.
     */
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true, 'default' => 1000000])]
    private int|string $sharesOutstanding = '1000000';


    // THE BALANCE SHEET


    /**
     * @var string Absolute cash and liquid reserves held by the corporation.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, options: ['default' => '0.0000'])]
    private string $corporateTreasury = '0.0000';

    /**
     * @var string The operating profit margin (e.g., 0.15 for 15%).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 4, options: ['default' => '0.1500'])]
    private string $operatingMargin = '0.2000';

    /**
     * @var string The percentage of shares available for public trading.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 4, options: ['default' => '1.0000'])]
    private string $publicFloatPercentage = '1.0000';

    /**
     * @var string Absolute total net income. Used to mathematically derive EPS dynamically.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, options: ['default' => '0.0000'])]
    private string $totalNetIncome = '0.0000';

    /**
     * @var string Absolute total revenue.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, options: ['default' => '0.0000'])]
    private string $totalRevenue = '0.0000';

    /**
     * @var string|null Absolute total free cash flow (FCF). Used to mathematically derive FCF per share.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $totalFreeCashFlow = null;

    /**
     * @var string Absolute total equity (Book Value).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, options: ['default' => '0.0000'])]
    private string $totalEquity = '0.0000';

    /**
     * @var string Accumulated retained earnings over the company's lifespan.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, options: ['default' => '0.0000'])]
    private string $retainedEarnings = '0.0000';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, options: ['default' => '0.0000'])]
    private string $wholesaleDebt = '0.0000';

    /**
     * @var string The risk premium this company pays over the Central Bank policy rate.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, options: ['default' => '0.0100'])]
    private string $creditSpread = '0.0100';

    /**
     * @var string The percentage of Total Debt that is subject to variable/floating interest rates.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, options: ['default' => '0.3000'])]
    private string $floatingDebtRatio = '0.3000';

    #[ORM\Column(type: 'decimal', precision: 5, scale: 4, options: ['default' => '0.0200'])]
    private string $historicalFixedRate = '0.0200';

    /**
     * @var string Intangible assets and premiums paid during M&A (Goodwill).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, options: ['default' => '0.0000'])]
    private string $goodwill = '0.0000';


    // CORPORATE POLICY & MARKET PHYSICS


    /**
     * @var string Baseline, long-term annualized volatility (Sigma).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, options: ['default' => '0.02'])]
    private string $volatility = '0.02';

    /**
     * @var string|null The current, dynamic instantaneous volatility (used in Heston/GARCH models).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $currentVolatility = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true, options: ['default' => '1.00'])]
    private ?string $beta = '1.00';

    /**
     * @var string The personality and behavioral archetype of the company's CEO.
     */
    #[ORM\Column(length: 50, options: ['default' => \App\Data\CeoArchetypes::OPPORTUNIST])]
    private string $ceoArchetype = \App\Data\CeoArchetypes::OPPORTUNIST;

    /**
     * @var string|null Jump intensity (Lambda) - expected number of market shocks per year (Merton Jump Diffusion).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true, options: ['default' => '2.00'])]
    private ?string $jumpIntensity = '2.00';

    /**
     * @var string The mean size of a market shock/jump.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, nullable: true, options: ['default' => '-0.01'])]
    private string $jumpMean = '-0.01';

    /**
     * @var string|null The standard deviation (volatility) of the jump size.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, nullable: true, options: ['default' => '0.10'])]
    private ?string $jumpVol = '0.10';

    /**
     * @var string|null Lore and background information for the stock.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * @var string Determines the bailout tier and market gravity strength (e.g., 'titan', 'systemic', 'none').
     */
    #[ORM\Column(length: 255)]
    private string $systemicImportance;

    /**
     * @var string The target percentage of net income paid out as dividends.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, options: ['default' => '0.30'])]
    private string $targetPayoutRatio = '0.30';

    /**
     * @var string How quickly the company adjusts its dividend towards the target payout ratio (Lintner model).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, options: ['default' => '0.20'])]
    private string $dividendSpeed = '0.20';

    /**
     * @var string The absolute value of the last paid dividend per share.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, options: ['default' => '0.00'])]
    private string $lastDividend = '0.00';

    /**
     * @var string The baseline Return on Invested Capital.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, options: ['default' => '0.10'])]
    private string $baselineRoic = '0.10';

    /**
     * @var string The baseline Return on Equity (used for Leveraged Industries like Banks).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, options: ['default' => '0.10'])]
    private string $baselineRoe = '0.10';

    /**
     * @var string The ratio of operating cash flow allocated to Capital Expenditures.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, options: ['default' => '0.20'])]
    private string $capexRatio = '0.20';

    /**
     * @var string The annualized rate at which the company's physical equity (assets) depreciates.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, options: ['default' => '0.0500'])]
    private string $depreciationRate = '0.0500';

    /**
     * @var string The dynamic, current Return on Invested Capital.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 4, options: ['default' => '0.0000'])]
    private string $currentRoic = '0.0000';

    /**
     * @var string The Trailing Twelve Months (TTM) Return on Invested Capital.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 4, options: ['default' => '0.0000'])]
    private string $roicTtm = '0.0000';

    /**
     * @var string The dynamic, current Return on Equity.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 4, options: ['default' => '0.0000'])]
    private string $currentRoe = '0.0000';

    /**
     * @var string The Trailing Twelve Months (TTM) Return on Equity.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 4, options: ['default' => '0.0000'])]
    private string $roeTtm = '0.0000';

    /**
     * @var string Absolute dollar amount remaining in the board-authorized buyback program.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, options: ['default' => '0.0000'])]
    private string $buybackAuthorization = '0.0000';

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $fixedCostRatio = null;

    /**
     * @var string|null The Serviceable Addressable Market (SAM) multiplier.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true, options: ['default' => '1.00'])]
    private ?string $samRatio = '1.00';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $industry = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $customerDeposits = '0.00';


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicker(): string
    {
        return $this->ticker;
    }
    public function setTicker(string $ticker): static
    {
        $this->ticker = $ticker;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }
    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getSector(): string
    {
        return $this->sector;
    }
    public function setSector(string $sector): static
    {
        $this->sector = $sector;
        return $this;
    }

    public function getPrice(): string
    {
        return $this->price;
    }
    public function setPrice(string $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getSharesOutstanding(): int|string
    {
        return $this->sharesOutstanding;
    }
    public function setSharesOutstanding(int|string $sharesOutstanding): static
    {
        $this->sharesOutstanding = $sharesOutstanding;
        return $this;
    }

    public function getTotalNetIncome(): string
    {
        return $this->totalNetIncome;
    }
    public function setTotalNetIncome(string $totalNetIncome): static
    {
        $this->totalNetIncome = $totalNetIncome;
        return $this;
    }

    public function getTotalEquity(): string
    {
        return $this->totalEquity;
    }
    public function setTotalEquity(string $totalEquity): static
    {
        $this->totalEquity = $totalEquity;
        return $this;
    }

    public function getTotalFreeCashFlow(): ?string
    {
        return $this->totalFreeCashFlow;
    }
    public function setTotalFreeCashFlow(?string $totalFreeCashFlow): static
    {
        $this->totalFreeCashFlow = $totalFreeCashFlow;
        return $this;
    }

    public function getRetainedEarnings(): string
    {
        return $this->retainedEarnings;
    }
    public function setRetainedEarnings(string $retainedEarnings): static
    {
        $this->retainedEarnings = $retainedEarnings;
        return $this;
    }

    // --- STANDARD PROPERTIES ---

    public function getCorporateTreasury(): ?string
    {
        return $this->corporateTreasury;
    }
    public function setCorporateTreasury(string $corporateTreasury): static
    {
        $this->corporateTreasury = $corporateTreasury;
        return $this;
    }

    public function getOperatingMargin(): ?string
    {
        return $this->operatingMargin;
    }
    public function setOperatingMargin(string $operatingMargin): static
    {
        $this->operatingMargin = $operatingMargin;
        return $this;
    }

    public function getPublicFloatPercentage(): ?string
    {
        return $this->publicFloatPercentage;
    }
    public function setPublicFloatPercentage(string $publicFloatPercentage): static
    {
        $this->publicFloatPercentage = $publicFloatPercentage;
        return $this;
    }

    public function getVolatility(): string
    {
        return $this->volatility;
    }
    public function setVolatility(string $volatility): static
    {
        $this->volatility = $volatility;
        return $this;
    }

    public function getCurrentVolatility(): ?string
    {
        return $this->currentVolatility;
    }
    public function setCurrentVolatility(?string $currentVolatility): static
    {
        $this->currentVolatility = $currentVolatility;
        return $this;
    }

    public function getBeta(): ?string
    {
        return $this->beta;
    }
    public function setBeta(?string $beta): static
    {
        $this->beta = $beta;
        return $this;
    }

    public function getJumpIntensity(): ?string
    {
        return $this->jumpIntensity;
    }
    public function setJumpIntensity(?string $jumpIntensity): static
    {
        $this->jumpIntensity = $jumpIntensity;
        return $this;
    }

    public function getJumpMean(): ?string
    {
        return $this->jumpMean;
    }
    public function setJumpMean(?string $jumpMean): static
    {
        $this->jumpMean = $jumpMean;
        return $this;
    }

    public function getJumpVol(): ?string
    {
        return $this->jumpVol;
    }
    public function setJumpVol(?string $jumpVol): static
    {
        $this->jumpVol = $jumpVol;
        return $this;
    }

    public function getCeoArchetype(): ?string
    {
        return $this->ceoArchetype;
    }

    public function setCeoArchetype(?string $ceoArchetype): static
    {
        $this->ceoArchetype = $ceoArchetype;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getSystemicImportance(): string
    {
        return $this->systemicImportance;
    }
    public function setSystemicImportance(string $systemicImportance): static
    {
        $this->systemicImportance = $systemicImportance;
        return $this;
    }

    public function getTargetPayoutRatio(): ?string
    {
        return $this->targetPayoutRatio;
    }
    public function setTargetPayoutRatio(string $targetPayoutRatio): static
    {
        $this->targetPayoutRatio = $targetPayoutRatio;
        return $this;
    }

    public function getDividendSpeed(): ?string
    {
        return $this->dividendSpeed;
    }
    public function setDividendSpeed(string $dividendSpeed): static
    {
        $this->dividendSpeed = $dividendSpeed;
        return $this;
    }

    public function getLastDividend(): ?string
    {
        return $this->lastDividend;
    }
    public function setLastDividend(string $lastDividend): static
    {
        $val = min(999999.9999, max(0.0, (float) $lastDividend));
        $this->lastDividend = (string) $val;
        return $this;
    }

    public function getBaselineRoic(): ?string
    {
        return $this->baselineRoic;
    }
    public function setBaselineRoic(string $baselineRoic): self
    {
        $this->baselineRoic = $baselineRoic;
        return $this;
    }

    public function getCapexRatio(): ?string
    {
        return $this->capexRatio;
    }
    public function setCapexRatio(string $capexRatio): self
    {
        $this->capexRatio = $capexRatio;
        return $this;
    }

    public function getCurrentRoic(): ?string
    {
        return $this->currentRoic;
    }
    public function setCurrentRoic(string $currentRoic): self
    {
        $this->currentRoic = $currentRoic;
        return $this;
    }

    public function getBaselineRoe(): ?string
    {
        return $this->baselineRoe;
    }
    
    public function setBaselineRoe(string $baselineRoe): self
    {
        $this->baselineRoe = $baselineRoe;
        return $this;
    }

    public function getCurrentRoe(): ?string
    {
        return $this->currentRoe;
    }

    public function setCurrentRoe(string $currentRoe): self
    {
        $this->currentRoe = $currentRoe;
        return $this;
    }

    public function getCreditSpread(): ?string
    {
        return $this->creditSpread;
    }

    public function setCreditSpread(string $creditSpread): static
    {
        $this->creditSpread = $creditSpread;

        return $this;
    }

    public function getGoodwill(): ?string
    {
        return $this->goodwill;
    }

    public function setGoodwill(string $goodwill): static
    {
        $this->goodwill = $goodwill;

        return $this;
    }

    public function getBuybackAuthorization(): ?string
    {
        return $this->buybackAuthorization;
    }

    public function setBuybackAuthorization(string $buybackAuthorization): static
    {
        $this->buybackAuthorization = $buybackAuthorization;

        return $this;
    }

       public function getDepreciationRate(): ?string
    {
        return $this->depreciationRate;
    }

    public function setDepreciationRate(string $depreciationRate): static
    {
        $this->depreciationRate = $depreciationRate;

        return $this;
    }


    public function getFloatingDebtRatio(): ?string
    {
        return $this->floatingDebtRatio;
    }

    public function setFloatingDebtRatio(string $floatingDebtRatio): static
    {
        $this->floatingDebtRatio = $floatingDebtRatio;

        return $this;
    }

    public function setFixedCostRatio(?float $fixedCostRatio): self
    {
        $this->fixedCostRatio = $fixedCostRatio;
        return $this;
    }

    public function getFixedCostRatio(): float
    {
        // If we specifically set a ratio for this company in the DB, use it!
        if ($this->fixedCostRatio !== null) {
            return $this->fixedCostRatio;
        }

        // Otherwise, fall back to the macroeconomic reality of their Sector
        return match ($this->getSector()) {
            'Information Technology', 'Communication Services' => 0.75, // Heavy R&D, servers
            'Utilities', 'Real Estate' => 0.65,                         // Heavy infrastructure
            'Health Care' => 0.55,                                       // Pharma R&D vs Pill manufacturing
            'Financials' => 0.40,                                       // Moderate fixed overhead
            'Industrials', 'Materials', 'Energy' => 0.30,               // Heavy variable material costs
            'Consumer Discretionary', 'Consumer Staples' => 0.15,       // Buying and selling physical inventory
            default => 0.35,
        };
    }

    public function getSamRatio(): ?string
    {
        return $this->samRatio;
    }

    public function setSamRatio(?string $samRatio): static
    {
        $this->samRatio = $samRatio;
        return $this;
    }

    // --- BRIDGE METHODS ---

    /**
     * Calculates Earnings Per Share (EPS) dynamically from Absolute Total Net Income.
     * 
     * @return string|null The calculated EPS.
     */
    public function getEarningsPerShare(): ?string
    {
        $shares = max(1.0, (float) $this->sharesOutstanding);
        return (string) round((float) $this->totalNetIncome / $shares, 8);
    }

    /**
     * Sets the Earnings Per Share (EPS) by calculating and updating the Absolute Total Net Income.
     * 
     * @param string|null $earningsPerShare The target EPS to reverse-engineer into Net Income.
     */
    public function setEarningsPerShare(?string $earningsPerShare): static
    {
        if ($earningsPerShare !== null) {
            $shares = max(1.0, (float) $this->sharesOutstanding);
            $totalNi = (float) $earningsPerShare * $shares;
            $this->totalNetIncome = (string) max(-999999999999999.0, min(999999999999999.0, $totalNi));
        } else {
            $this->totalNetIncome = '0.0000';
        }
        return $this;
    }

    /**
     * Calculates Free Cash Flow (FCF) Per Share dynamically from Absolute Total FCF.
     * 
     * @return string|null The calculated FCF per share.
     */
    public function getFreeCashFlowPerShare(): ?string
    {
        if ($this->totalFreeCashFlow === null) return null;
        $shares = max(1.0, (float) $this->sharesOutstanding);
        return (string) round((float) $this->totalFreeCashFlow / $shares, 8);
    }

    /**
     * Sets the Free Cash Flow (FCF) Per Share by updating the Absolute Total FCF.
     * 
     * @param string|null $freeCashFlowPerShare The target FCF per share.
     */
    public function setFreeCashFlowPerShare(?string $freeCashFlowPerShare): self
    {
        if ($freeCashFlowPerShare !== null) {
            $shares = max(1.0, (float) $this->sharesOutstanding);
            $totalFcf = (float) $freeCashFlowPerShare * $shares;
            // Add this clamp to prevent SQL 1264 out of range errors
            $this->totalFreeCashFlow = (string) max(-999999999999999.0, min(999999999999999.0, $totalFcf));
        } else {
            $this->totalFreeCashFlow = null;
        }
        return $this;
    }

    /**
     * Calculates Book Value Per Share dynamically from Absolute Total Equity.
     * 
     * @return string The calculated Book Value Per Share.
     */
    public function getBookValuePerShare(): string
    {
        $shares = max(1.0, (float) $this->sharesOutstanding);
        return (string) round((float) $this->totalEquity / $shares, 4);
    }

    /**
     * Sets the Book Value Per Share by updating the Absolute Total Equity.
     * 
     * @param string $bookValuePerShare The target Book Value Per Share.
     */
    public function setBookValuePerShare(string $bookValuePerShare): static
    {
        $shares = max(1.0, (float) $this->sharesOutstanding);
        $this->totalEquity = (string) ((float) $bookValuePerShare * $shares);
        return $this;
    }

    /**
     * Derives Absolute Total Revenue mathematically using Total Net Income and Operating Margin.
     * 
     * @return string The computed Total Revenue.
     */
    public function getTotalRevenue(): string 
    {
        return $this->totalRevenue;
    }

    public function setTotalRevenue(string $totalRevenue): static
    {
        $this->totalRevenue = $totalRevenue;
        return $this;
    }

    /**
     * Calculates Revenue Per Share based on computed Total Revenue.
     * 
     * @return string The calculated Revenue Per Share.
     */
    public function getRevenuePerShare(): string
    {
        $shares = max(1, (int) $this->sharesOutstanding);
        $totalRevenue = (float) $this->getTotalRevenue();
        return (string) round($totalRevenue / $shares, 4);
    }

    /**
     * Calculates the True Size of the Operating Business (Invested Capital).
     * Core Business Floor: Assumes at least 50% of Equity is driving operations, even for mega-hoarders.
     */
    public function getInvestedCapital(): float
    {
        $equity = (float) $this->totalEquity;
        $debt = (float) $this->getTotalDebt();
        $cash = (float) $this->corporateTreasury;
        
        // 10% to prevent penalizing cash-rich "lean" tech companies,
        // while still maintaining a minimum physical asset base (desks, servers) to avoid Division by Zero.
        return max($equity * 0.10, ($equity + $debt - $cash));
    }

    /**
     * Calculates the Debt-to-Equity Ratio dynamically based on Absolute Debt and Equity.
     * * @return string The calculated Debt-to-Equity Ratio.
     */
    public function getDebtToEquityRatio(): string
    {
        $equity = max(1.0, (float) $this->totalEquity);
        $debt = (float) $this->getTotalDebt();
        
        return (string) round($debt / $equity, 4);
    }

    public function getHistoricalFixedRate(): ?string
    {
        return $this->historicalFixedRate;
    }

    public function setHistoricalFixedRate(string $historicalFixedRate): static
    {
        $this->historicalFixedRate = $historicalFixedRate;

        return $this;
    }

    public function getIndustry(): ?string
    {
        return $this->industry;
    }

    public function setIndustry(?string $industry): static
    {
        $this->industry = $industry;

        return $this;
    }

    public function getWholesaleDebt(): string
    {
        return $this->wholesaleDebt;
    }

    public function setWholesaleDebt(string $wholesaleDebt): static
    {
        $this->wholesaleDebt = $wholesaleDebt;
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

    public function getTotalDebt(): string
    {
        $wholesale = (float) $this->wholesaleDebt;
        $deposits = (float) $this->customerDeposits;
        
        return (string) number_format($wholesale + $deposits, 4, '.', '');
    }

    public function getRoicTtm(): string
    {
        return $this->roicTtm;
    }

    public function setRoicTtm(string $roicTtm): self
    {
        $this->roicTtm = $roicTtm;
        return $this;
    }

    public function getRoeTtm(): string
    {
        return $this->roeTtm;
    }

    public function setRoeTtm(string $roeTtm): self
    {
        $this->roeTtm = $roeTtm;
        return $this;
    }
}
