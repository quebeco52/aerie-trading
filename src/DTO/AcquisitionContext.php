<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Stock;
use App\Service\Model\BusinessModelInterface;

/**
 * Context object used to pass state through the private acquisition pipeline. The engine's initialize step sets
 * $health and $strategy before any later step reads them.
 */
class AcquisitionContext
{
    public function __construct(
        public readonly Stock $acquirer,
        public readonly MacroStateDTO $macroState,
        public readonly float $dt,
        /** Simulation tick; dates the industry ledger's peer records the merger review reads. */
        public readonly int $tickCount = 0,
        public readonly int $ticksPerYear = 252,
    ) {}

    // State Variables
    public float $treasury = 0.0;
    public float $price = 0.0;
    public float $shares = 0.0;
    public float $debtRatio = 0.0;

    public float $operatingBase = 0.0;
    public float $equity = 0.0;
    /** Invested capital before the deal is funded; funding moves cash, debt or shares and already adds the price. */
    public float $preDealInvestedCapital = 0.0;
    public float $currentDebt = 0.0;

    public DebtHealthDTO $health;
    public string $industry = 'General';
    public string $businessModel = 'none';
    public BusinessModelInterface $strategy;

    // Buying Power & Cash
    public float $borrowingCapacity = 0.0;
    public float $totalBuyingPower = 0.0;
    public float $targetCash = 0.0;
    public float $excessCash = 0.0;
    public bool $isHoarder = false;
    public bool $isMegaHoarder = false;
    public float $usableTreasury = 0.0;

    // Strategy evaluation
    public float $normalizedDebtUtilization = 0.0;
    public float $currentPE = 0.0;
    public float $trueReturn = 0.0;
    public float $hurdleRate = 0.0;
    public float $economicSpread = 0.0;
    public float $fairValuePE = 0.0;
    public float $bookValuePerShare = 0.0;
    public float $priceToBook = 0.0;
    public bool $isOvervalued = false;
    public bool $isEmpireBuilder = false;
    /** Fraction of the target's standalone value paid on top of it — the acquirer's overpayment (Roll 1986). */
    public float $hubrisPremium = 0.0;
    public float $costOfNewBorrowing = 0.0;

    // Deal Config
    /**
     * @var array{prob: float, spend: float, type: string, use_leverage: bool, use_stock: bool, style_priced?: bool} Set when a deal is struck; nothing reads it before.
     */
    public array $config;
    public bool $dealExecuted = false;
    public float $availableCapital = 0.0;
    public float $spendFraction = 0.0;
    public float $purchasePrice = 0.0;
    /** The last sizing step that cut the purchase price (AcquisitionEngine::LIMIT_*). */
    public string $bindingLimit = '';
    public array $target = [];
    /**
     * The industry a deal takes its buyer into when it buys outside its own markets (AcquisitionEngine): how close it
     * is to the buyer's, its incumbents' margin and capital turnover, the market they sell into, the share of it the
     * fringe holds and the sales of its largest listed firm. Null for a deal in a market the buyer is in.
     *
     * @var array{industry: string, closeness: float, margin: float, turnover: float, market: float, room: float, leader: float}|null
     */
    public ?array $entry = null;
    /** The segment a company that keeps plant by segment buys into (SegmentBooks); its main business otherwise. */
    public string $segment = \App\Data\Company\Segments::MAIN;

    // Funding results
    public float $costOfNewDebt = 0.0;
    public float $debtIssued = 0.0;
    public float $sharesIssued = 0.0;

    // Synergy & Accounting
    public float $synergyMultiplier = 1.0;
    /** The target's standalone after-tax return on its economic value, before synergy. */
    public float $targetRoic = 0.0;
    /** The target's operating margin; turns its operating income into the revenue it brings. */
    public float $targetMargin = 0.01;
    /**
     * The capital turnover of the segment a company that keeps plant by segment buys into, or of the incumbents of the
     * industry a deal enters, which sizes the target's identifiable assets; null for any other deal.
     */
    public ?float $targetTurnover = null;
    public float $synergyValueCreation = 0.0;
    /** Goodwill recognized on this deal (purchase price above the fair value of net identifiable assets). */
    public float $goodwillRecorded = 0.0;
    public float $newEquity = 0.0;

    public float $eventShock = 0.0;
}
