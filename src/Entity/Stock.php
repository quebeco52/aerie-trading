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
     * @var string Quarter-over-quarter revenue tracker for calculating change in net working capital (ΔNWC).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, options: ['default' => '0.0000'])]
    private string $previousRevenue = '0.0000';

    /**
     * @var string|null Balance sheet net working capital (NWC) stock for calculating cash flow change in NWC (ΔNWC).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $netWorkingCapital = null;

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

    /**
     * @var string Accumulated Net Operating Losses (NOLs) carried forward to shield future profits from taxes.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, options: ['default' => '0.0000'])]
    private string $netOperatingLoss = '0.0000';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, options: ['default' => '0.0000'])]
    private string $wholesaleDebt = '0.0000';

    /**
     * @var string The risk premium this company pays over the Central Bank policy rate.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, options: ['default' => '0.0100'])]
    private string $creditSpread = '0.0100';

    /**
     * @var string Alphanumeric credit rating assigned by CreditRatingAgency (e.g. AAA, BBB, CCC, D).
     */
    #[ORM\Column(type: Types::STRING, length: 4, options: ['default' => 'BBB'])]
    private string $creditRating = 'BBB';

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

    /**
     * @var string Construction in Progress (CIP) Balance for continuous CapEx integration.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, options: ['default' => '0.0000'])]
    private string $cipBalance = '0.0000';


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
     * @var string|null Jump intensity (Lambda) - expected number of market shocks per year (Merton Jump Diffusion).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true, options: ['default' => '2.00'])]
    private ?string $jumpIntensity = '2.00';


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
    #[ORM\Column(length: 255, options: ['default' => 'none'])]
    private string $systemicImportance = 'none';

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
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, options: ['default' => '0.0000'])]
    private string $currentRoic = '0.0000';

    /**
     * @var string The Trailing Twelve Months (TTM) Return on Invested Capital.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, options: ['default' => '0.0000'])]
    private string $roicTtm = '0.0000';

    /**
     * @var string|null Annual capital turnover (revenue / invested capital), the DuPont component fixing how much
     *                  revenue a dollar of physical capital can generate. Seeded once from baseline ROIC and margin,
     *                  then held structural; null until the first earnings report seeds it.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $assetTurnover = null;

    /**
     * @var string The dynamic, current Return on Equity.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, options: ['default' => '0.0000'])]
    private string $currentRoe = '0.0000';

    /**
     * @var string The Trailing Twelve Months (TTM) Return on Equity.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, options: ['default' => '0.0000'])]
    private string $roeTtm = '0.0000';

    /**
     * @var string Absolute dollar amount remaining in the board-authorized buyback program.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, options: ['default' => '0.0000'])]
    private string $buybackAuthorization = '0.0000';

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $fixedCostRatio = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $structuralVariableMargin = null;

    /**
     * @var float|null Accruals anomaly ratio (Net Income - FCF) / Total Assets (Sloan 1996).
     */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $accrualsRatio = 0.0;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $earningsMomentumZ = [];

    /**
     * @var string|null The Serviceable Addressable Market (SAM) multiplier.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true, options: ['default' => '1.00'])]
    private ?string $samRatio = '1.00';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $industry = null;

    /** Dickinson (2011) life-cycle stage classified from last quarter's cash-flow signs (App\Data\LifecycleStage value). */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $lifecycleStage = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $customerDeposits = '0.00';

    /**
     * @var string|null Last quarter's analyst revenue consensus estimate, used for anchoring bias.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $lastAnalystRevenue = null;

    /**
     * @var bool Whether the company has collapsed into bankruptcy and is permanently defunct.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isBankrupt = false;



    public static function cleanBcStr(int|float|string|null $val, int $scale = 4): string
    {
        if ($val === null || $val === '') {
            return '0.' . str_repeat('0', $scale);
        }
        if (is_int($val) || is_float($val)) {
            return number_format((float) $val, $scale, '.', '');
        }
        $str = trim((string) $val);
        if (stripos($str, 'e') !== false) {
            return number_format((float) $str, $scale, '.', '');
        }
        return $str;
    }

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
        $this->price = self::cleanBcStr($price, 8);
        return $this;
    }

    public function getSharesOutstanding(): int|string
    {
        return $this->sharesOutstanding;
    }
    public function setSharesOutstanding(int|string $sharesOutstanding): static
    {
        $this->sharesOutstanding = is_int($sharesOutstanding) ? $sharesOutstanding : (string) (int) self::cleanBcStr($sharesOutstanding, 0);
        return $this;
    }

    public function getTotalNetIncome(): string
    {
        return $this->totalNetIncome;
    }
    public function setTotalNetIncome(string $totalNetIncome): static
    {
        $this->totalNetIncome = self::cleanBcStr($totalNetIncome, 4);
        return $this;
    }

    public function getTotalEquity(): string
    {
        return $this->totalEquity;
    }
    public function setTotalEquity(string $totalEquity): static
    {
        $this->totalEquity = self::cleanBcStr($totalEquity, 4);
        return $this;
    }

    public function getTotalFreeCashFlow(): ?string
    {
        return $this->totalFreeCashFlow;
    }
    public function setTotalFreeCashFlow(?string $totalFreeCashFlow): static
    {
        $this->totalFreeCashFlow = $totalFreeCashFlow !== null ? self::cleanBcStr($totalFreeCashFlow, 4) : null;
        return $this;
    }

    public function getRetainedEarnings(): string
    {
        return $this->retainedEarnings;
    }
    public function setRetainedEarnings(string $retainedEarnings): static
    {
        $this->retainedEarnings = self::cleanBcStr($retainedEarnings, 4);
        return $this;
    }

    public function getNetOperatingLoss(): string
    {
        return $this->netOperatingLoss;
    }
    public function setNetOperatingLoss(string $netOperatingLoss): static
    {
        $this->netOperatingLoss = self::cleanBcStr($netOperatingLoss, 4);
        return $this;
    }

    // --- STANDARD PROPERTIES ---

    public function getCorporateTreasury(): ?string
    {
        return $this->corporateTreasury;
    }
    public function setCorporateTreasury(string $corporateTreasury): static
    {
        $this->corporateTreasury = self::cleanBcStr($corporateTreasury, 4);
        return $this;
    }

    public function getOperatingMargin(): ?string
    {
        return $this->operatingMargin;
    }
    public function setOperatingMargin(string $operatingMargin): static
    {
        $this->operatingMargin = self::cleanBcStr($operatingMargin, 4);
        return $this;
    }

    public function getPublicFloatPercentage(): ?string
    {
        return $this->publicFloatPercentage;
    }
    public function setPublicFloatPercentage(string $publicFloatPercentage): static
    {
        $this->publicFloatPercentage = self::cleanBcStr($publicFloatPercentage, 4);
        return $this;
    }

    public function getVolatility(): string
    {
        return $this->volatility;
    }
    public function setVolatility(string $volatility): static
    {
        $this->volatility = self::cleanBcStr($volatility, 4);
        return $this;
    }

    public function getCurrentVolatility(): ?string
    {
        return $this->currentVolatility;
    }
    public function setCurrentVolatility(?string $currentVolatility): static
    {
        $this->currentVolatility = $currentVolatility !== null ? self::cleanBcStr($currentVolatility, 4) : null;
        return $this;
    }

    public function getBeta(): ?string
    {
        return $this->beta;
    }
    public function setBeta(?string $beta): static
    {
        $this->beta = $beta !== null ? self::cleanBcStr($beta, 2) : null;
        return $this;
    }

    public function getJumpIntensity(): ?string
    {
        return $this->jumpIntensity;
    }
    public function setJumpIntensity(?string $jumpIntensity): static
    {
        $this->jumpIntensity = $jumpIntensity !== null ? self::cleanBcStr($jumpIntensity, 2) : null;
        return $this;
    }


    public function getJumpVol(): ?string
    {
        return $this->jumpVol;
    }
    public function setJumpVol(?string $jumpVol): static
    {
        $this->jumpVol = $jumpVol !== null ? self::cleanBcStr($jumpVol, 4) : null;
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
    public function getLifecycleStage(): ?\App\Data\LifecycleStage
    {
        return $this->lifecycleStage !== null ? \App\Data\LifecycleStage::tryFrom($this->lifecycleStage) : null;
    }

    public function setLifecycleStage(?\App\Data\LifecycleStage $stage): static
    {
        $this->lifecycleStage = $stage?->value;

        return $this;
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
        $this->targetPayoutRatio = self::cleanBcStr($targetPayoutRatio, 4);
        return $this;
    }

    public function getDividendSpeed(): ?string
    {
        return $this->dividendSpeed;
    }
    public function setDividendSpeed(string $dividendSpeed): static
    {
        $this->dividendSpeed = self::cleanBcStr($dividendSpeed, 4);
        return $this;
    }

    public function getLastDividend(): ?string
    {
        return $this->lastDividend;
    }
    public function setLastDividend(string $lastDividend): static
    {
        $val = min(999999.9999, max(0.0, (float) self::cleanBcStr($lastDividend, 4)));
        $this->lastDividend = self::cleanBcStr($val, 4);
        return $this;
    }

    public function getBaselineRoic(): ?string
    {
        return $this->baselineRoic;
    }
    public function setBaselineRoic(string $baselineRoic): self
    {
        $this->baselineRoic = self::cleanBcStr($baselineRoic, 4);
        return $this;
    }

    public function getCapexRatio(): ?string
    {
        return $this->capexRatio;
    }
    public function setCapexRatio(string $capexRatio): self
    {
        $this->capexRatio = self::cleanBcStr($capexRatio, 4);
        return $this;
    }

    public function getCurrentRoic(): ?string
    {
        return $this->currentRoic;
    }
    public function setCurrentRoic(string $currentRoic): self
    {
        $this->currentRoic = self::cleanBcStr($currentRoic, 4);
        return $this;
    }

    public function getBaselineRoe(): ?string
    {
        return $this->baselineRoe;
    }

    public function setBaselineRoe(string $baselineRoe): self
    {
        $this->baselineRoe = self::cleanBcStr($baselineRoe, 4);
        return $this;
    }

    public function getCurrentRoe(): ?string
    {
        return $this->currentRoe;
    }

    public function setCurrentRoe(string $currentRoe): self
    {
        $this->currentRoe = self::cleanBcStr($currentRoe, 4);
        return $this;
    }

    public function getCreditSpread(): ?string
    {
        return $this->creditSpread;
    }

    public function setCreditSpread(string $creditSpread): static
    {
        $this->creditSpread = self::cleanBcStr($creditSpread, 4);

        return $this;
    }

    public function getCreditRating(): string
    {
        return $this->creditRating;
    }

    public function setCreditRating(string $creditRating): static
    {
        $this->creditRating = $creditRating;

        return $this;
    }

    public function getGoodwill(): ?string
    {
        return $this->goodwill;
    }

    public function setGoodwill(?string $goodwill): self
    {
        $this->goodwill = $goodwill === null ? '0.0000' : self::cleanBcStr($goodwill, 4);
        return $this;
    }

    public function getCipBalance(): string
    {
        return $this->cipBalance;
    }

    public function getTotalCipAmount(): float
    {
        return (float) $this->cipBalance;
    }

    public function setCipBalance(string $cipBalance): self
    {
        $this->cipBalance = self::cleanBcStr($cipBalance, 4);
        return $this;
    }

    public function getBuybackAuthorization(): ?string
    {
        return $this->buybackAuthorization;
    }

    public function setBuybackAuthorization(string $buybackAuthorization): static
    {
        $this->buybackAuthorization = self::cleanBcStr($buybackAuthorization, 4);

        return $this;
    }

    public function getDepreciationRate(): ?string
    {
        return $this->depreciationRate;
    }

    public function setDepreciationRate(string $depreciationRate): static
    {
        $this->depreciationRate = self::cleanBcStr($depreciationRate, 4);

        return $this;
    }


    public function getFloatingDebtRatio(): ?string
    {
        return $this->floatingDebtRatio;
    }

    public function setFloatingDebtRatio(string $floatingDebtRatio): static
    {
        $this->floatingDebtRatio = self::cleanBcStr($floatingDebtRatio, 4);

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

    public function setStructuralVariableMargin(?float $structuralVariableMargin): static
    {
        $this->structuralVariableMargin = $structuralVariableMargin;
        return $this;
    }

    public function getStructuralVariableMargin(): ?float
    {
        return $this->structuralVariableMargin;
    }

    public function setAccrualsRatio(?float $accrualsRatio): static
    {
        $this->accrualsRatio = $accrualsRatio;
        return $this;
    }

    public function getAccrualsRatio(): ?float
    {
        return $this->accrualsRatio;
    }

    public function setEarningsMomentumZ(?array $earningsMomentumZ): static
    {
        $this->earningsMomentumZ = $earningsMomentumZ;
        return $this;
    }

    public function getEarningsMomentumZ(): ?array
    {
        return $this->earningsMomentumZ;
    }

    public function getSamRatio(): ?string
    {
        return $this->samRatio;
    }

    public function setSamRatio(?string $samRatio): static
    {
        $this->samRatio = $samRatio !== null ? self::cleanBcStr($samRatio, 2) : null;
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
        $sharesStr = self::cleanBcStr((float) $this->sharesOutstanding < 1.0 ? '1.00000000' : $this->sharesOutstanding, 8);
        return \bcdiv(self::cleanBcStr($this->totalNetIncome, 4), $sharesStr, 8);
    }

    /**
     * Sets the Earnings Per Share (EPS) by calculating and updating the Absolute Total Net Income.
     * 
     * @param string|null $earningsPerShare The target EPS to reverse-engineer into Net Income.
     */
    public function setEarningsPerShare(?string $earningsPerShare): static
    {
        if ($earningsPerShare !== null) {
            $sharesStr = self::cleanBcStr((float) $this->sharesOutstanding < 1.0 ? '1.00000000' : $this->sharesOutstanding, 8);
            $totalNi = \bcmul(self::cleanBcStr($earningsPerShare, 8), $sharesStr, 4);
            if (\bccomp($totalNi, '999999999999999.0000', 4) > 0) {
                $totalNi = '999999999999999.0000';
            } elseif (\bccomp($totalNi, '-999999999999999.0000', 4) < 0) {
                $totalNi = '-999999999999999.0000';
            }
            $this->totalNetIncome = self::cleanBcStr($totalNi, 4);
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
        $sharesStr = self::cleanBcStr((float) $this->sharesOutstanding < 1.0 ? '1.00000000' : $this->sharesOutstanding, 8);
        return \bcdiv(self::cleanBcStr($this->totalFreeCashFlow, 4), $sharesStr, 8);
    }

    /**
     * Sets the Free Cash Flow (FCF) Per Share by updating the Absolute Total FCF.
     * 
     * @param string|null $freeCashFlowPerShare The target FCF per share.
     */
    public function setFreeCashFlowPerShare(?string $freeCashFlowPerShare): self
    {
        if ($freeCashFlowPerShare !== null) {
            $sharesStr = self::cleanBcStr((float) $this->sharesOutstanding < 1.0 ? '1.00000000' : $this->sharesOutstanding, 8);
            $totalFcf = \bcmul(self::cleanBcStr($freeCashFlowPerShare, 8), $sharesStr, 4);
            if (\bccomp($totalFcf, '999999999999999.0000', 4) > 0) {
                $totalFcf = '999999999999999.0000';
            } elseif (\bccomp($totalFcf, '-999999999999999.0000', 4) < 0) {
                $totalFcf = '-999999999999999.0000';
            }
            $this->totalFreeCashFlow = self::cleanBcStr($totalFcf, 4);
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
        $sharesStr = self::cleanBcStr((float) $this->sharesOutstanding < 1.0 ? '1.00000000' : $this->sharesOutstanding, 8);
        return \bcdiv(self::cleanBcStr($this->totalEquity, 4), $sharesStr, 4);
    }

    /**
     * Sets the Book Value Per Share by updating the Absolute Total Equity.
     * 
     * @param string $bookValuePerShare The target Book Value Per Share.
     */
    public function setBookValuePerShare(string $bookValuePerShare): static
    {
        $sharesStr = self::cleanBcStr((float) $this->sharesOutstanding < 1.0 ? '1.00000000' : $this->sharesOutstanding, 8);
        $this->totalEquity = self::cleanBcStr(\bcmul(self::cleanBcStr($bookValuePerShare, 4), $sharesStr, 4), 4);
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
        $this->totalRevenue = self::cleanBcStr($totalRevenue, 4);
        return $this;
    }

    public function getPreviousRevenue(): string
    {
        return $this->previousRevenue;
    }

    public function setPreviousRevenue(string $previousRevenue): static
    {
        $this->previousRevenue = self::cleanBcStr($previousRevenue, 4);
        return $this;
    }

    public function getNetWorkingCapital(): ?string
    {
        return $this->netWorkingCapital;
    }

    public function setNetWorkingCapital(?string $netWorkingCapital): static
    {
        $this->netWorkingCapital = $netWorkingCapital !== null ? self::cleanBcStr($netWorkingCapital, 4) : null;
        return $this;
    }

    /**
     * Calculates Revenue Per Share based on computed Total Revenue.
     * 
     * @return string The calculated Revenue Per Share.
     */
    public function getRevenuePerShare(): string
    {
        $sharesStr = (float) $this->sharesOutstanding < 1.0 ? '1.00000000' : (string) $this->sharesOutstanding;
        return \bcdiv((string) $this->getTotalRevenue(), $sharesStr, 4);
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

        return max(1.0, max($equity * 0.50, ($equity + $debt - $cash)));
    }

    /**
     * Calculates the Debt-to-Equity Ratio dynamically based on Absolute Debt and Equity.
     * * @return string The calculated Debt-to-Equity Ratio.
     */
    public function getDebtToEquityRatio(): string
    {
        $equityStr = (float) $this->totalEquity < 1.0 ? '1.0000' : (string) $this->totalEquity;
        $debtStr = (string) $this->getTotalDebt();
        return \bcdiv($debtStr, $equityStr, 4);
    }

    public function getHistoricalFixedRate(): ?string
    {
        return $this->historicalFixedRate;
    }

    public function setHistoricalFixedRate(string $historicalFixedRate): static
    {
        $this->historicalFixedRate = self::cleanBcStr($historicalFixedRate, 4);

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
        $this->wholesaleDebt = self::cleanBcStr($wholesaleDebt, 4);
        return $this;
    }

    public function getCustomerDeposits(): ?string
    {
        return $this->customerDeposits;
    }

    public function setCustomerDeposits(?string $customerDeposits): static
    {
        $this->customerDeposits = $customerDeposits !== null ? self::cleanBcStr($customerDeposits, 4) : null;
        return $this;
    }

    public function getTotalDebt(): string
    {
        $wholesale = (float) $this->wholesaleDebt;
        $deposits = (float) $this->customerDeposits;

        return self::cleanBcStr((string) number_format($wholesale + $deposits, 4, '.', ''), 4);
    }

    public function getRoicTtm(): string
    {
        return $this->roicTtm;
    }

    public function getAssetTurnover(): ?string
    {
        return $this->assetTurnover;
    }

    public function setAssetTurnover(?string $assetTurnover): static
    {
        $this->assetTurnover = $assetTurnover !== null ? self::cleanBcStr($assetTurnover, 4) : null;
        return $this;
    }

    public function setRoicTtm(string $roicTtm): self
    {
        $this->roicTtm = self::cleanBcStr($roicTtm, 4);
        return $this;
    }

    public function getRoeTtm(): string
    {
        return $this->roeTtm;
    }

    public function setRoeTtm(string $roeTtm): self
    {
        $this->roeTtm = self::cleanBcStr($roeTtm, 4);
        return $this;
    }
    public function getLastAnalystRevenue(): ?string
    {
        return $this->lastAnalystRevenue;
    }

    public function setLastAnalystRevenue(?string $lastAnalystRevenue): self
    {
        $this->lastAnalystRevenue = $lastAnalystRevenue !== null ? self::cleanBcStr($lastAnalystRevenue, 4) : null;
        return $this;
    }

    public function isBankrupt(): bool
    {
        return $this->isBankrupt;
    }

    public function setIsBankrupt(bool $isBankrupt): static
    {
        $this->isBankrupt = $isBankrupt;
        return $this;
    }
}

