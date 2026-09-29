<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Represents a publicly traded stock on the Aerie Exchange.
 * * Uses Absolute Values (Total Net Income, Total Equity) to maintain 
 * mathematically flawless accounting during splits, buyouts, and buybacks.
 */
#[ORM\Entity(repositoryClass: \App\Repository\StockRepository::class)]
#[ORM\Table(name: 'stocks')]
class Stock
{
    // --- Storage Bounds ---
    /** Largest dollar amount a DECIMAL(30, 4) money column holds; totals rebuilt from a per-share figure clamp here. */
    public const MAX_MONEY_AMOUNT = '99999999999999999999999999.9999';

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
     *
     * PER-TICK COLUMN, `updatable: false`. This and the five others so marked (current volatility, impact
     * variance, momentum trend, dynamic credit spread, corporate flow backlog) change on every name on
     * every tick, which made every stock a dirty entity at every flush and cost one UPDATE per stock per
     * flush. The unit of work never writes them; StockTickColumns writes them in bulk before each flush.
     * The INSERT still carries them, so seeding is unaffected.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, options: ['default' => '100.00000000'], updatable: false)]
    private string $price = '100.00000000';

    /**
     * @var int|string The total number of shares issued by the corporation.
     *                 Stored absolutely to maintain perfect math during splits/buybacks.
     */
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true, 'default' => 1000000])]
    private int|string $sharesOutstanding = '1000000';

    /**
     * @var float|null Simulated time (years) of the last forward or reverse split; null if the stock has never split.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $lastSplitAt = null;


    // THE BALANCE SHEET


    /**
     * @var string Absolute cash and liquid reserves held by the corporation.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, options: ['default' => '0.0000'])]
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
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, options: ['default' => '0.0000'])]
    private string $totalNetIncome = '0.0000';

    /**
     * @var string Absolute total revenue.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, options: ['default' => '0.0000'])]
    private string $totalRevenue = '0.0000';

    /**
     * @var string Quarter-over-quarter revenue tracker for calculating change in net working capital (ΔNWC).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, options: ['default' => '0.0000'])]
    private string $previousRevenue = '0.0000';

    /**
     * @var string|null Trade receivables: revenue billed and not yet collected. Null until first seeded.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, nullable: true)]
    private ?string $receivables = null;

    /**
     * @var string|null Inventory carried at cost. Null until first seeded.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, nullable: true)]
    private ?string $inventory = null;

    /**
     * @var string Lower-of-cost-or-net-realizable-value write-downs carried against inventory (ASC 330).
     *             A contra balance rather than a cut to the gross figure, because the gross figure is
     *             rebuilt from the trade cycle every quarter and a cut would be restored at once, with the
     *             restoration booked as a cash outflow the same quarter. Unwinds as the impaired stock turns.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, options: ['default' => '0.0000'])]
    private string $inventoryAllowance = '0.0000';

    /**
     * @var string|null Trade payables: input costs incurred and not yet paid, a source of funding.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, nullable: true)]
    private ?string $payables = null;

    /**
     * @var string Expected credit loss allowance held against receivables (ASC 326).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, options: ['default' => '0.0000'])]
    private string $receivablesAllowance = '0.0000';

    /**
     * @var string|null Absolute total free cash flow (FCF). Used to mathematically derive FCF per share.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, nullable: true)]
    private ?string $totalFreeCashFlow = null;

    /**
     * @var string Absolute total equity (Book Value).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, options: ['default' => '0.0000'])]
    private string $totalEquity = '0.0000';

    /**
     * @var string Accumulated retained earnings over the company's lifespan.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, options: ['default' => '0.0000'])]
    private string $retainedEarnings = '0.0000';

    /**
     * @var string Accumulated Net Operating Losses (NOLs) carried forward to shield future profits from taxes.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, options: ['default' => '0.0000'])]
    private string $netOperatingLoss = '0.0000';

    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, options: ['default' => '0.0000'])]
    private string $wholesaleDebt = '0.0000';

    /**
     * @var string The risk premium this company pays over the Central Bank policy rate.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, options: ['default' => '0.0100'])]
    private string $creditSpread = '0.0100';

    /**
     * The spread the firm's credit actually commands right now, over the sovereign curve.
     *
     * Distinct from $creditSpread above, which is the BASELINE the seed gave the firm and which DebtEngine
     * treats as a floor to build on. This is what that build produces each tick — baseline plus the Merton
     * spread plus the financial-accelerator premium — and it is persisted because the bond desk has to
     * discount the firm's listed issues at it. Recomputing it there would put a second authority on what a
     * company's credit costs, and the two would disagree the first time either was retuned.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 6, options: ['default' => '0.010000'], updatable: false)]
    private string $dynamicCreditSpread = '0.010000';

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
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, options: ['default' => '0.0000'])]
    private string $goodwill = '0.0000';

    /**
     * @var string Construction in Progress (CIP) Balance for continuous CapEx integration.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, options: ['default' => '0.0000'])]
    private string $cipBalance = '0.0000';

    /**
     * @var string|null Historical cost of property, plant and equipment placed in service; null until the
     *                  fixed-asset ledger is seeded on the first earnings report.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, nullable: true)]
    private ?string $grossPpe = null;

    /**
     * @var string Depreciation charged against gross PP&E to date; net book value is the difference.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, options: ['default' => '0.0000'])]
    private string $accumulatedDepreciation = '0.0000';

    /**
     * @var string|null Remaining tax basis of PP&E. Tax depreciation runs on its own accelerated schedule,
     *                  so this diverges from book net PP&E and the gap is what creates deferred tax.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, nullable: true)]
    private ?string $ppeTaxBasis = null;

    /**
     * @var string Deferred tax liability (ASC 740): tax deferred by depreciating faster for the tax
     *             authority than for shareholders. Payable eventually, interest free until then.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, options: ['default' => '0.0000'])]
    private string $deferredTaxLiability = '0.0000';

    /**
     * @var string|null CapEx-weighted price level at which the current plant was bought. Replacing a worn
     *                  asset costs today's price, not the vintage one, so the ratio of the two is what a
     *                  maintenance dollar has to stretch to cover.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?string $ppeVintageDeflator = null;

    /**
     * @var string|null Gross loans, securities and other earning assets a balance-sheet business (bank,
     *                  insurer, broker, fund) has deployed its funding into. Null until the ledger is seeded
     *                  on the first earnings report; a non-financial firm never opens it.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, nullable: true)]
    private ?string $earningAssets = null;

    /**
     * @var string Allowance for credit losses on the earning assets (ASC 326): the lifetime loss already
     *             expected, carried as a contra-asset. Provisions build it and charge-offs consume it.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, options: ['default' => '0.0000'])]
    private string $creditLossAllowance = '0.0000';

    /**
     * @var string|null The published twelve-month sell-side price target. Null until the first one is struck.
     *
     * Carried rather than recomputed, because a target that tracked fair value continuously would never be
     * revised and a revision is the only part of a target that carries information (Womack 1996). It moves
     * in steps, when the case for it has moved far enough to be worth restating.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
    private ?string $analystPriceTarget = null;

    /** @var string|null What a sphere's listed anchor stakes sit at on the balance sheet, remarked at every report. Null for the firms that hold none. */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, nullable: true)]
    private ?string $listedStakesCarrying = null;

    /**
     * @var float|null Macaulay duration in years of the firm's investment securities book. Null falls back to
     *                 the sector default. Seeded per firm on purpose: rate risk without a cross-section is a
     *                 market where every lender lives or dies together.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $securitiesDuration = null;

    /**
     * @var float|null Blended yield the securities book is carried at, walked toward the curve as maturities
     *                 are reinvested. Null until the first report strikes it at the prevailing curve.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $securitiesCarryingYield = null;

    /**
     * @var string Mark on the whole securities book against amortized cost (ASC 320); negative is a loss. The
     *             available-for-sale share of it is already inside total equity; the held-to-maturity share is
     *             disclosed here and nowhere else, which is how a firm stays adequately capitalized on every
     *             published figure until the quarter it is forced to sell.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, options: ['default' => '0.0000'])]
    private string $unrealizedSecuritiesMark = '0.0000';

    /**
     * @var bool Whether this institution elects to filter accumulated other comprehensive income out of
     *           regulatory capital (Basel III). A firm-level election, not a market-wide constant: it is the
     *           difference between a capital ratio that reacts to the curve and one that does not react at all
     *           until the securities are sold.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $aociFiltered = true;


    // CORPORATE POLICY & MARKET PHYSICS


    /**
     * @var string Baseline, long-term annualized volatility (Sigma).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, options: ['default' => '0.02'])]
    private string $volatility = '0.02';

    /**
     * @var string|null The current, dynamic instantaneous volatility (used in Heston/GARCH models).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true, updatable: false)]
    private ?string $currentVolatility = null;

    /**
     * @var float|null Volatility of the firm's assets (Merton sigma_V), solved once from the configured equity
     *                 volatility at the capital structure it was configured at; the business's risk, which debt
     *                 does not change.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $assetVolatility = null;

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
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, options: ['default' => '0.0000'])]
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

    /**
     * @var float|null Earnings shortfall management has already warned the market about, awaiting the report that confirms it; cleared once reported.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $preAnnouncedShortfall = null;

    /**
     * @var string|null Persistent management style biasing payout, reinvestment and the hurdle rate applied to growth (Bertrand & Schoar 2003); null is the balanced default.
     */
    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $managementStyle = null;

    /**
     * @var float|null Years the incumbent management has been in post, used for the succession hazard; null on a firm that has never had a turnover evaluated.
     *
     * A per-tick clock, and therefore written by StockTickColumns rather than by the unit of work: the
     * succession engine ages the incumbent on every firm on every tick, which made every company a dirty
     * entity at every flush and cost one UPDATE each for a float that had moved by a few ten-thousandths of
     * a year. See the note on $price.
     */
    #[ORM\Column(type: 'float', nullable: true, updatable: false)]
    private ?float $ceoTenureYears = 0.0;

    /**
     * @var float|null How firmly the incumbent holds their style, scaling every dial's distance from neutral; null is the archetype's published strength.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $managementIntensity = null;

    /**
     * @var float|null Book-to-bill disclosed in the last report (orders booked over revenue billed); null when the firm discloses no order book.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $lastBookToBill = null;

    /**
     * @var float|null Output gap as it has actually reached this firm's order book, behind the macro series by its own transmission lag; null until the first report.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $laggedDemandGap = null;

    /**
     * @var float|null Accruals borrowed from future quarters to hit consensus and not yet reversed (Burgstahler & Dichev 1997). Positive = earnings pulled forward and still owed back.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $managedAccrualBank = 0.0;

    /**
     * @var float|null Seasonally adjusted operating margin actually realized in the last report; null until the first report.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $reportedOperatingMargin = null;

    /**
     * @var float|null Exponentially weighted sum of log price returns (Jegadeesh-Titman formation trend); null until the first tick.
     */
    #[ORM\Column(type: 'float', nullable: true, updatable: false)]
    private ?float $priceMomentumTrend = 0.0;

    /**
     * @var float|null Annual share turnover as a fraction of the public float: a structural property of the
     *                 name, not a draw. Sets how much volume the market carries and therefore how much size
     *                 costs. Null falls back to the baseline.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $turnoverRatio = null;

    /**
     * @var float|null Exponentially weighted realized variance of permanent order-flow impact, annualized.
     *
     * The diffusion gives back exactly what order flow supplies, so the budget has to be drawn from what
     * flow ACTUALLY did rather than from an assumption about what it might do. Measured, so a name nobody
     * trades reclaims nothing and a heavily traded one reclaims in proportion.
     */
    #[ORM\Column(type: 'float', nullable: true, options: ['default' => 0.0], updatable: false)]
    private ?float $impactVarianceEma = 0.0;

    /**
     * @var float|null Exponentially weighted realized variance of the name's TOTAL return, annualized.
     *
     * What the tape actually printed, as opposed to `currentVolatility`, which is the instantaneous state
     * of the variance process — the forward-looking number the diffusion is about to draw from. The two
     * are different measurements and an index wants this one: a real volatility screen ranks on a trailing
     * window of realized returns, so a name is admitted for having BEEN quiet rather than for a variance
     * state that a single jump moves.
     */
    #[ORM\Column(type: 'float', nullable: true, options: ['default' => 0.0], updatable: false)]
    private ?float $realizedVarianceEma = 0.0;

    /**
     * @var float|null Shares the company itself still has to put through the market, signed: positive is a
     *                 repurchase program not yet executed, negative is issued stock (an offering, deal
     *                 consideration, vested compensation) not yet distributed. Worked off by the ticker
     *                 at the 10b-18 pace through the same order-flow channel every other trade uses, so a
     *                 buyback moves the price the way a buyer does rather than by an invented shock.
     */
    #[ORM\Column(type: 'float', nullable: true, options: ['default' => 0.0], updatable: false)]
    private ?float $corporateFlowBacklog = 0.0;

    /**
     * @var float|null Share of the public float that is actually available to borrow. The rest is held by
     *                 owners who do not lend, which is what makes a name hard to borrow long before its
     *                 whole float is shorted.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $lendableSupplyRatio = null;

    /**
     * @var string Shares currently sold short across every account. Utilization against the lendable
     *             supply is what prices the borrow, so this has to be a live total rather than derived on
     *             demand from a scan of every position.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, options: ['default' => '0.00'])]
    private string $shortInterestShares = '0.00';

    /**
     * @var float|null Lagged pass-through of expected inflation into selling prices; null until the first report seeds it.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $inflationPassThrough = null;

    /**
     * @var array<int, float>|null Reported net income of the last four quarters, oldest first, summed into the trailing twelve month figure.
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $quarterlyNetIncomeHistory = [];

    /**
     * @var list<array{revenue: float, ebit: float}>|null Revenue and operating income of the last four quarters,
     *      oldest first: the last-twelve-month basis lenders underwrite coverage and leverage on.
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $quarterlyOperatingHistory = null;

    /**
     * @var array<int, float>|null Blended earnings surprises of recent quarters, the sample the SUE denominator is estimated from.
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $earningsSurpriseHistory = [];

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

    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 2, nullable: true)]
    private ?string $customerDeposits = '0.00';

    /**
     * @var string|null Last quarter's analyst revenue consensus estimate, used for anchoring bias.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, nullable: true)]
    private ?string $lastAnalystRevenue = null;

    /**
     * @var string|null Variable cost ratio as it was last REPORTED, the anchor analysts forecast the next
     *                  quarter's cost base from. Null until the first report.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?string $lastReportedCostRatio = null;

    /**
     * @var bool Whether the company has collapsed into bankruptcy and is permanently defunct.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isBankrupt = false;

    /**
     * @var bool True while the firm is failing to repay maturing principal it can neither refinance nor
     *           fund. A payment default is an event of default in its own right, independent of whether the
     *           balance sheet is still notionally solvent — and it is CURABLE: the next quarter's cash, an
     *           asset sale or a rescue raise clears it. It is not, by itself, grounds for liquidation.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $paymentDefault = false;

    /**
     * @var int Consecutive quarters the firm has been in payment default. The grace clock a real indenture
     *          gives a late borrower: zero while current, and reset the moment the shortfall is funded.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $quartersInDefault = 0;

    /**
     * @var string Committed size of the revolving credit facility the firm's banks have contracted to fund.
     *             A revolver is a term commitment negotiated in good times: it ratchets UP as the business
     *             grows and does not shrink because a bad quarter shrank revenue, which is what makes it a
     *             backstop rather than a line that vanishes exactly when it is needed.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, options: ['default' => '0.0000'])]
    private string $revolverCommitment = '0.0000';

    /**
     * @var string Drawn balance on that facility. Held apart from wholesale debt because revolving credit
     *             does not amortise: booking a draw into the term ladder made every draw enlarge the next
     *             quarter's maturity wall, so covering one maturity manufactured a larger one.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 4, options: ['default' => '0.0000'])]
    private string $revolverDrawn = '0.0000';

    /**
     * @var string The committed fixed cost base the firm is actually resourced for, as a fraction of the
     *             cost base its structural capacity implies. One is a firm staffed for full capacity. It
     *             falls as a slump persists and recovers as volume returns, sluggishly downward and faster
     *             upward (Anderson, Banker & Janakiraman 2003), because capacity cost is committed, not
     *             chosen quarter by quarter.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 6, options: ['default' => '1.000000'])]
    private string $committedCostScale = '1.000000';



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

    /**
     * Returns every field but the row's identity to the value a new listing starts with, so a firm reopened on a
     * reset carries nothing of the market it left: no ledger, learned parameter or accumulator survives. Read off
     * a fresh instance rather than listed, so a field added to the entity is cleared without anyone remembering to.
     */
    public function resetToUnseeded(): void
    {
        foreach (get_object_vars(new self()) as $field => $unseeded) {
            if ($field !== 'id') {
                $this->$field = $unseeded;
            }
        }
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

    public function getLastSplitAt(): ?float
    {
        return $this->lastSplitAt;
    }

    public function setLastSplitAt(?float $lastSplitAt): static
    {
        $this->lastSplitAt = $lastSplitAt;
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

    public function getDynamicCreditSpread(): string
    {
        return $this->dynamicCreditSpread;
    }

    public function setDynamicCreditSpread(string $dynamicCreditSpread): static
    {
        $this->dynamicCreditSpread = self::cleanBcStr($dynamicCreditSpread, 6);

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

    public function getGrossPpe(): ?string
    {
        return $this->grossPpe;
    }

    public function setGrossPpe(?string $grossPpe): self
    {
        $this->grossPpe = $grossPpe === null ? null : self::cleanBcStr($grossPpe, 4);
        return $this;
    }

    public function getAccumulatedDepreciation(): string
    {
        return $this->accumulatedDepreciation;
    }

    public function getPpeTaxBasis(): ?string
    {
        return $this->ppeTaxBasis;
    }

    public function setPpeTaxBasis(?string $ppeTaxBasis): self
    {
        $this->ppeTaxBasis = $ppeTaxBasis === null ? null : self::cleanBcStr($ppeTaxBasis, 4);
        return $this;
    }

    public function getDeferredTaxLiability(): string
    {
        return $this->deferredTaxLiability;
    }

    public function setDeferredTaxLiability(string $deferredTaxLiability): self
    {
        $this->deferredTaxLiability = self::cleanBcStr($deferredTaxLiability, 4);
        return $this;
    }

    public function getPpeVintageDeflator(): ?string
    {
        return $this->ppeVintageDeflator;
    }

    public function setPpeVintageDeflator(?string $ppeVintageDeflator): self
    {
        $this->ppeVintageDeflator = $ppeVintageDeflator;
        return $this;
    }

    public function setAccumulatedDepreciation(string $accumulatedDepreciation): self
    {
        $this->accumulatedDepreciation = self::cleanBcStr($accumulatedDepreciation, 4);
        return $this;
    }

    public function getEarningAssets(): ?string
    {
        return $this->earningAssets;
    }

    public function setEarningAssets(?string $earningAssets): self
    {
        $this->earningAssets = $earningAssets === null ? null : self::cleanBcStr($earningAssets, 4);
        return $this;
    }

    public function getCreditLossAllowance(): string
    {
        return $this->creditLossAllowance;
    }

    public function setCreditLossAllowance(string $creditLossAllowance): self
    {
        $this->creditLossAllowance = self::cleanBcStr($creditLossAllowance, 4);
        return $this;
    }

    public function getListedStakesCarrying(): ?string
    {
        return $this->listedStakesCarrying;
    }

    public function setListedStakesCarrying(?string $listedStakesCarrying): self
    {
        $this->listedStakesCarrying = $listedStakesCarrying === null
            ? null
            : self::cleanBcStr($listedStakesCarrying, 4);
        return $this;
    }

    public function getAnalystPriceTarget(): ?string
    {
        return $this->analystPriceTarget;
    }

    public function setAnalystPriceTarget(?string $analystPriceTarget): self
    {
        $this->analystPriceTarget = $analystPriceTarget === null
            ? null
            : self::cleanBcStr($analystPriceTarget, 4);
        return $this;
    }

    /**
     * The published rating, which is a function of the STANDING target against today's price.
     *
     * So it can change without anybody revising anything: a name that rallies into its target is downgraded
     * by the rally itself, which is how a sell-side rating actually behaves. The bands are asymmetric
     * because the sell side downgrades late and reluctantly.
     */
    public function getAnalystRating(): string
    {
        $target = (float) ($this->analystPriceTarget ?? 0.0);
        $price = (float) $this->price;

        if ($target <= 0.0 || $price <= 0.0) {
            return 'Neutral';
        }

        return match (true) {
            $target > $price * \App\Service\Math\FinancialConstants::ANALYST_RATING_OUTPERFORM => 'Outperform',
            $target < $price * \App\Service\Math\FinancialConstants::ANALYST_RATING_UNDERPERFORM => 'Underperform',
            default => 'Neutral',
        };
    }

    /** Whether the earning-asset ledger a balance-sheet business carries has been opened. */
    public function hasEarningAssetLedger(): bool
    {
        return $this->earningAssets !== null;
    }

    public function getSecuritiesDuration(): ?float
    {
        return $this->securitiesDuration;
    }

    public function setSecuritiesDuration(?float $securitiesDuration): self
    {
        $this->securitiesDuration = $securitiesDuration === null ? null : max(0.0, $securitiesDuration);
        return $this;
    }

    public function getSecuritiesCarryingYield(): ?float
    {
        return $this->securitiesCarryingYield;
    }

    public function setSecuritiesCarryingYield(?float $securitiesCarryingYield): self
    {
        $this->securitiesCarryingYield = $securitiesCarryingYield;
        return $this;
    }

    public function getUnrealizedSecuritiesMark(): string
    {
        return $this->unrealizedSecuritiesMark;
    }

    public function setUnrealizedSecuritiesMark(string $unrealizedSecuritiesMark): self
    {
        $this->unrealizedSecuritiesMark = self::cleanBcStr($unrealizedSecuritiesMark, 4);
        return $this;
    }

    public function isAociFiltered(): bool
    {
        return $this->aociFiltered;
    }

    public function setAociFiltered(bool $aociFiltered): self
    {
        $this->aociFiltered = $aociFiltered;
        return $this;
    }

    /**
     * The available-for-sale share of the securities mark: the part that IS in reported total equity and on
     * the asset side, and therefore the part every published book-value figure already reflects.
     */
    public function getRecognizedSecuritiesMark(): float
    {
        return (float) $this->unrealizedSecuritiesMark * (1.0 - \App\Service\Math\FinancialConstants::DEFAULT_HTM_BOOK_SHARE);
    }

    /**
     * The held-to-maturity share of the securities mark: the part that is NOT in reported total equity.
     *
     * Reported equity already carries the available-for-sale mark, so this is the whole of what a reader of
     * the balance sheet is missing, and adding it to total equity gives economic equity.
     */
    public function getUnrecognizedSecuritiesMark(): float
    {
        return (float) $this->unrealizedSecuritiesMark * \App\Service\Math\FinancialConstants::DEFAULT_HTM_BOOK_SHARE;
    }

    /**
     * Total equity with the undisclosed held-to-maturity mark folded in: what the firm is actually worth
     * rather than what it reports being worth.
     */
    public function getEconomicEquity(): float
    {
        return (float) $this->totalEquity + $this->getUnrecognizedSecuritiesMark();
    }

    /**
     * Common equity as a regulator counts it.
     *
     * Basel III lets an institution elect to filter accumulated other comprehensive income out of tier 1
     * capital, and requires others to include it. That election is the difference between a capital ratio
     * that reacts to the curve and one that does not react at all until the securities are sold — it is a
     * firm-level choice and not a market-wide constant, which is why it is a column.
     *
     * Held-to-maturity never enters, because it is not in reported equity to begin with. Goodwill is
     * deducted, as Basel III deducts it from common equity tier 1.
     */
    public function getRegulatoryEquity(): float
    {
        return ($this->aociFiltered ? $this->getAmortizedCostEquity() : (float) $this->totalEquity) - $this->getGoodwillBalance();
    }

    /**
     * Equity less goodwill: the capital that can absorb a loss. Goodwill is the premium paid over the
     * identifiable assets of an acquisition (ASC 805); it cannot be sold to meet a claim, so every regulator
     * deducts it before measuring capital, and a write-off moves book equity without moving this.
     */
    public function getTangibleEquity(): float
    {
        return (float) $this->totalEquity - $this->getGoodwillBalance();
    }

    /**
     * Equity with the securities book put back at amortized cost: what a reader who does not mark the
     * portfolio sees. It is the arithmetic behind the Basel AOCI filter.
     */
    public function getAmortizedCostEquity(): float
    {
        return (float) $this->totalEquity - $this->getRecognizedSecuritiesMark();
    }

    /**
     * Statutory surplus for an insurer: equity with bonds at amortized cost and goodwill valued at nil, as
     * Solvency II values it. Statutory accounting carrying bonds at amortized cost is exactly why property and
     * casualty underwriters went on writing business through the 2022 selloff while their economic capital
     * was falling — the ratio their capacity is rationed by never moved.
     */
    public function getStatutorySurplus(): float
    {
        return $this->getAmortizedCostEquity() - $this->getGoodwillBalance();
    }

    /** Goodwill carried, never negative. */
    private function getGoodwillBalance(): float
    {
        return max(0.0, (float) $this->goodwill);
    }

    /**
     * Whether any asset ledger exists to draw a balance sheet from: the plant ledger of an operating
     * company or the earning-asset ledger of a financial one. A firm that has never reported has neither.
     */
    public function hasBalanceSheetLedger(): bool
    {
        return $this->grossPpe !== null || $this->earningAssets !== null;
    }

    /**
     * Earning assets net of the credit-loss allowance: the loans and securities the firm expects to
     * collect on, which is the base its yield is earned on. Zero until the ledger is seeded.
     */
    public function getNetEarningAssets(): float
    {
        if ($this->earningAssets === null) {
            return 0.0;
        }

        return max(0.0, (float) $this->earningAssets - (float) $this->creditLossAllowance);
    }

    /**
     * Net book value of property, plant and equipment: the base depreciation is charged on, and the only
     * asset account CapEx accumulates into. Zero until the ledger is seeded on the first earnings report.
     */
    public function getNetPpe(): float
    {
        if ($this->grossPpe === null) {
            return 0.0;
        }

        return max(0.0, (float) $this->grossPpe - (float) $this->accumulatedDepreciation);
    }

    /**
     * Accumulated depreciation as a share of gross PP&E, the standard proxy for the average age of the
     * asset base: near 0 is a freshly built plant, near 1 is one running on fully depreciated equipment.
     */
    public function getAssetAge(): float
    {
        $gross = (float) ($this->grossPpe ?? 0.0);
        if ($gross <= 0.0) {
            return 0.0;
        }

        return min(1.0, max(0.0, (float) $this->accumulatedDepreciation / $gross));
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

    public function getAssetVolatility(): ?float
    {
        return $this->assetVolatility;
    }

    public function setAssetVolatility(?float $assetVolatility): static
    {
        $this->assetVolatility = $assetVolatility;
        return $this;
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

    public function getManagedAccrualBank(): float
    {
        return (float) ($this->managedAccrualBank ?? 0.0);
    }

    public function setManagedAccrualBank(float $managedAccrualBank): static
    {
        $this->managedAccrualBank = $managedAccrualBank;

        return $this;
    }

    public function getLaggedDemandGap(): ?float
    {
        return $this->laggedDemandGap;
    }

    public function setLaggedDemandGap(float $laggedDemandGap): static
    {
        $this->laggedDemandGap = $laggedDemandGap;

        return $this;
    }

    public function getLastBookToBill(): ?float
    {
        return $this->lastBookToBill;
    }

    public function setLastBookToBill(?float $lastBookToBill): static
    {
        $this->lastBookToBill = $lastBookToBill;

        return $this;
    }

    public function getManagementStyle(): \App\Data\ManagementStyle
    {
        return \App\Data\ManagementStyle::tryFromNullable($this->managementStyle);
    }

    public function setManagementStyle(?\App\Data\ManagementStyle $style): static
    {
        $this->managementStyle = $style?->value;

        return $this;
    }

    /**
     * The individual running the firm: the archetype plus how firmly they hold it. Every engine reads its
     * dials through here, so a style and its strength can never be picked up separately by accident.
     */
    public function getManagementProfile(): \App\Data\ManagementProfile
    {
        return \App\Data\ManagementProfile::forStyle($this->getManagementStyle(), $this->managementIntensity);
    }

    public function getManagementIntensity(): ?float
    {
        return $this->managementIntensity;
    }

    public function setManagementIntensity(?float $managementIntensity): static
    {
        $this->managementIntensity = $managementIntensity;

        return $this;
    }

    public function getCeoTenureYears(): float
    {
        return (float) ($this->ceoTenureYears ?? 0.0);
    }

    public function setCeoTenureYears(?float $ceoTenureYears): static
    {
        $this->ceoTenureYears = $ceoTenureYears === null ? null : max(0.0, $ceoTenureYears);

        return $this;
    }

    public function getPreAnnouncedShortfall(): float
    {
        return (float) ($this->preAnnouncedShortfall ?? 0.0);
    }

    public function setPreAnnouncedShortfall(float $preAnnouncedShortfall): static
    {
        $this->preAnnouncedShortfall = $preAnnouncedShortfall;

        return $this;
    }

    public function getAccrualsRatio(): ?float
    {
        return $this->accrualsRatio;
    }

    public function setReportedOperatingMargin(?float $reportedOperatingMargin): static
    {
        $this->reportedOperatingMargin = $reportedOperatingMargin;
        return $this;
    }

    public function getReportedOperatingMargin(): ?float
    {
        return $this->reportedOperatingMargin;
    }

    public function setPriceMomentumTrend(?float $priceMomentumTrend): static
    {
        $this->priceMomentumTrend = $priceMomentumTrend;
        return $this;
    }

    public function getPriceMomentumTrend(): ?float
    {
        return $this->priceMomentumTrend;
    }

    public function setTurnoverRatio(?float $turnoverRatio): static
    {
        $this->turnoverRatio = $turnoverRatio;
        return $this;
    }

    public function getTurnoverRatio(): ?float
    {
        return $this->turnoverRatio;
    }

    public function setImpactVarianceEma(?float $impactVarianceEma): static
    {
        $this->impactVarianceEma = $impactVarianceEma;
        return $this;
    }

    public function getImpactVarianceEma(): ?float
    {
        return $this->impactVarianceEma;
    }

    public function setRealizedVarianceEma(?float $realizedVarianceEma): static
    {
        $this->realizedVarianceEma = $realizedVarianceEma === null ? null : max(0.0, $realizedVarianceEma);

        return $this;
    }

    public function getRealizedVarianceEma(): ?float
    {
        return $this->realizedVarianceEma;
    }

    /**
     * The volatility the name has actually realized over the trailing window, or null before it has
     * measured one.
     *
     * Null rather than zero for an unmeasured name, because zero is a legitimate reading — a name that has
     * not moved — and a screen that ranks on quiet would put an unmeasured name first on the strength of
     * never having traded.
     */
    public function getRealizedVolatility(): ?float
    {
        $variance = $this->realizedVarianceEma;

        return $variance !== null && $variance > 0.0 ? sqrt($variance) : null;
    }

    public function getCorporateFlowBacklog(): float
    {
        return (float) ($this->corporateFlowBacklog ?? 0.0);
    }

    public function setCorporateFlowBacklog(?float $corporateFlowBacklog): static
    {
        $this->corporateFlowBacklog = $corporateFlowBacklog;
        return $this;
    }

    /** Queues shares the company must trade itself: positive to buy back, negative to distribute. */
    public function addCorporateFlowBacklog(float $signedShares): static
    {
        $this->corporateFlowBacklog = $this->getCorporateFlowBacklog() + $signedShares;
        return $this;
    }

    public function setLendableSupplyRatio(?float $lendableSupplyRatio): static
    {
        $this->lendableSupplyRatio = $lendableSupplyRatio;
        return $this;
    }

    public function getLendableSupplyRatio(): ?float
    {
        return $this->lendableSupplyRatio;
    }

    public function setShortInterestShares(string $shortInterestShares): static
    {
        $this->shortInterestShares = $shortInterestShares;
        return $this;
    }

    public function getShortInterestShares(): string
    {
        return $this->shortInterestShares;
    }

    public function setInflationPassThrough(?float $inflationPassThrough): static
    {
        $this->inflationPassThrough = $inflationPassThrough;
        return $this;
    }

    public function getInflationPassThrough(): ?float
    {
        return $this->inflationPassThrough;
    }

    /**
     * @param array<int, float>|null $quarterlyNetIncomeHistory
     */
    public function setQuarterlyNetIncomeHistory(?array $quarterlyNetIncomeHistory): static
    {
        $this->quarterlyNetIncomeHistory = $quarterlyNetIncomeHistory;
        return $this;
    }

    /**
     * @return array<int, float>|null
     */
    public function getQuarterlyNetIncomeHistory(): ?array
    {
        return $this->quarterlyNetIncomeHistory;
    }

    /**
     * @return list<array{revenue: float, ebit: float}>|null
     */
    public function getQuarterlyOperatingHistory(): ?array
    {
        return $this->quarterlyOperatingHistory;
    }

    /**
     * @param list<array{revenue: float, ebit: float}>|null $quarterlyOperatingHistory
     */
    public function setQuarterlyOperatingHistory(?array $quarterlyOperatingHistory): static
    {
        $this->quarterlyOperatingHistory = $quarterlyOperatingHistory;
        return $this;
    }

    /**
     * @param array<int, float>|null $earningsSurpriseHistory
     */
    public function setEarningsSurpriseHistory(?array $earningsSurpriseHistory): static
    {
        $this->earningsSurpriseHistory = $earningsSurpriseHistory;
        return $this;
    }

    /**
     * @return array<int, float>|null
     */
    public function getEarningsSurpriseHistory(): ?array
    {
        return $this->earningsSurpriseHistory;
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
            if (\bccomp($totalNi, self::MAX_MONEY_AMOUNT, 4) > 0) {
                $totalNi = self::MAX_MONEY_AMOUNT;
            } elseif (\bccomp($totalNi, '-' . self::MAX_MONEY_AMOUNT, 4) < 0) {
                $totalNi = '-' . self::MAX_MONEY_AMOUNT;
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
            if (\bccomp($totalFcf, self::MAX_MONEY_AMOUNT, 4) > 0) {
                $totalFcf = self::MAX_MONEY_AMOUNT;
            } elseif (\bccomp($totalFcf, '-' . self::MAX_MONEY_AMOUNT, 4) < 0) {
                $totalFcf = '-' . self::MAX_MONEY_AMOUNT;
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

    public function getReceivables(): ?string
    {
        return $this->receivables;
    }

    public function setReceivables(?string $receivables): static
    {
        $this->receivables = $receivables === null ? null : self::cleanBcStr($receivables, 4);
        return $this;
    }

    public function getInventory(): ?string
    {
        return $this->inventory;
    }

    public function setInventory(?string $inventory): static
    {
        $this->inventory = $inventory === null ? null : self::cleanBcStr($inventory, 4);
        return $this;
    }

    public function getInventoryAllowance(): string
    {
        return $this->inventoryAllowance;
    }

    public function setInventoryAllowance(string $inventoryAllowance): static
    {
        $this->inventoryAllowance = self::cleanBcStr($inventoryAllowance, 4);
        return $this;
    }

    /**
     * Inventory at the lower of cost and net realizable value: the carrying amount the balance sheet shows.
     */
    public function getNetInventory(): float
    {
        return max(0.0, (float) ($this->inventory ?? 0.0) - (float) $this->inventoryAllowance);
    }

    public function getPayables(): ?string
    {
        return $this->payables;
    }

    public function setPayables(?string $payables): static
    {
        $this->payables = $payables === null ? null : self::cleanBcStr($payables, 4);
        return $this;
    }

    public function getReceivablesAllowance(): string
    {
        return $this->receivablesAllowance;
    }

    public function setReceivablesAllowance(string $receivablesAllowance): static
    {
        $this->receivablesAllowance = self::cleanBcStr($receivablesAllowance, 4);
        return $this;
    }

    /**
     * Receivables net of the expected credit loss allowance: what the firm actually expects to collect.
     */
    public function getNetReceivables(): float
    {
        return max(0.0, (float) ($this->receivables ?? 0.0) - (float) $this->receivablesAllowance);
    }

    /**
     * Total assets, derived from the balance sheet's asset side rather than stored: cash, the trade cycle,
     * the plant and what is still being built, the loans and securities a balance-sheet business holds
     * net of the losses it expects on them, the listed stakes a holding company carries at market, plus
     * the intangibles an acquisition left behind and the right-of-use asset that sits opposite a
     * capitalized lease.
     */
    public function getTotalAssets(float $leaseLiability = 0.0): float
    {
        return max(0.0, (float) $this->corporateTreasury)
            + $this->getNetReceivables()
            + $this->getNetInventory()
            + $this->getNetPpe()
            + $this->getNetEarningAssets()
            + max(0.0, (float) ($this->listedStakesCarrying ?? 0.0))
            + (float) $this->cipBalance
            + (float) $this->goodwill
            // Available-for-sale securities are carried at fair value, so the mark sits on the asset side
            // as well as in equity. Held-to-maturity is at amortized cost and is deliberately absent here:
            // that omission IS the unrecognised hole, and getEconomicEquity() is where it surfaces.
            + $this->getRecognizedSecuritiesMark()
            + max(0.0, $leaseLiability);
    }

    /**
     * Total liabilities: borrowings and deposits, the trade credit suppliers have extended, the postponed
     * tax bill, and the lease obligation that comes with the right-of-use asset.
     */
    public function getTotalLiabilities(float $leaseLiability = 0.0): float
    {
        return (float) $this->getTotalDebt()
            + (float) ($this->payables ?? 0.0)
            + (float) $this->deferredTaxLiability
            + max(0.0, $leaseLiability);
    }

    /**
     * Whether a complete working capital ledger exists.
     *
     * All three balances are written together every quarter, so a partial set is not a ledger — it is a
     * leftover. That distinction matters because the schema migration that introduced these columns was
     * generated as a RENAME of the old single net-working-capital column onto `receivables`, which leaves
     * a net figure (negative for float businesses) sitting in a gross balance with no matching inventory
     * or payables. Requiring the full trio makes such a row read as unseeded, so the engine rebuilds it
     * from the cash conversion cycle instead of differencing against a number that never meant this.
     */
    public function hasWorkingCapitalLedger(): bool
    {
        return $this->receivables !== null && $this->inventory !== null && $this->payables !== null;
    }

    /**
     * Net working capital, derived from the balances it is made of rather than stored alongside them.
     * Null until the ledger is seeded, which is what tells the cash-flow pass there is no prior quarter
     * to difference against.
     */
    public function getNetWorkingCapital(): ?string
    {
        if (!$this->hasWorkingCapitalLedger()) {
            return null;
        }

        return (string) ($this->getNetReceivables() + $this->getNetInventory() - (float) ($this->payables ?? 0.0));
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
    /**
     * Capital actually at work in the business: equity plus debt, less the cash sitting idle beside it.
     *
     * Deferred tax is included as an equity equivalent, the standard economic-profit adjustment. It is tax
     * the firm has postponed rather than paid, so the money is financing the business interest free, and
     * leaving it out would understate the capital the return is being earned on.
     */
    public function getInvestedCapital(): float
    {
        $equity = (float) $this->totalEquity;
        $debt = (float) $this->getTotalDebt();
        $cash = (float) $this->corporateTreasury;
        $deferredTax = (float) $this->deferredTaxLiability;

        return max(1.0, max($equity * 0.50, ($equity + $debt + $deferredTax - $cash)));
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
        // The drawn revolver is borrowed money like any other: it levers the firm, it is senior to equity and
        // it has to show up in coverage, the Altman test and the Merton default distance. Only its MATURITY
        // behaves differently, which is why the balance is held apart from the term ladder rather than here.
        $revolver = (float) $this->revolverDrawn;

        return self::cleanBcStr((string) number_format($wholesale + $deposits + $revolver, 4, '.', ''), 4);
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

    public function getLastReportedCostRatio(): ?string
    {
        return $this->lastReportedCostRatio;
    }

    public function setLastReportedCostRatio(?string $lastReportedCostRatio): self
    {
        $this->lastReportedCostRatio = $lastReportedCostRatio !== null ? self::cleanBcStr($lastReportedCostRatio, 6) : null;
        return $this;
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

    public function isPaymentDefault(): bool
    {
        return $this->paymentDefault;
    }

    public function setPaymentDefault(bool $paymentDefault): static
    {
        $this->paymentDefault = $paymentDefault;
        return $this;
    }

    public function getQuartersInDefault(): int
    {
        return $this->quartersInDefault;
    }

    public function setQuartersInDefault(int $quartersInDefault): static
    {
        $this->quartersInDefault = max(0, $quartersInDefault);
        return $this;
    }

    public function getRevolverCommitment(): string
    {
        return $this->revolverCommitment;
    }

    public function setRevolverCommitment(string $revolverCommitment): static
    {
        $this->revolverCommitment = self::cleanBcStr($revolverCommitment, 4);
        return $this;
    }

    public function getRevolverDrawn(): string
    {
        return $this->revolverDrawn;
    }

    public function setRevolverDrawn(string $revolverDrawn): static
    {
        $this->revolverDrawn = self::cleanBcStr($revolverDrawn, 4);
        return $this;
    }

    /** Facility still available to draw: the committed size less what is already outstanding on it. */
    public function getRevolverUndrawn(): float
    {
        return max(0.0, (float) $this->revolverCommitment - (float) $this->revolverDrawn);
    }

    public function getCommittedCostScale(): string
    {
        return $this->committedCostScale;
    }

    /** Bounded by the structural base above and by what no restructuring can remove below. */
    public function setCommittedCostScale(string $committedCostScale): static
    {
        $bounded = min(1.0, max(\App\Service\Math\FinancialConstants::MIN_COMMITTED_COST_SCALE, (float) $committedCostScale));
        $this->committedCostScale = self::cleanBcStr($bounded, 6);
        return $this;
    }
}

