<?php

declare(strict_types=1);

namespace App\Service\Market\Pricing;

use App\DTO\DebtHealthDTO;
use App\Entity\Stock;
use App\Service\Model\BusinessModelInterface;

/**
 * One company's tick as StockTracker steps it: what it opened at and what each step leaves for the next, in the order
 * the steps run (StockTracker::updateStock()).
 */
final class StockTick
{
    /** Shares outstanding before anything this tick can issue or retire stock. */
    public readonly float $sharesAtStart;
    /** The free float at the same moment. */
    public readonly float $floatSharesAtStart;

    // --- Deals and credit ---
    /** The price move a deal announced this tick carries into the price step. */
    public float $dealShock = 0.0;
    /** Whether an acquisition or a divestiture was struck this tick. */
    public bool $struckDeal = false;
    public DebtHealthDTO $health;
    public BusinessModelInterface $strategy;
    /** Shares outstanding after the deals, before the report. */
    public float $sharesBeforeReport;
    /** The operating return the wire shows: ROIC, or ROE for a bank. */
    public float $effectiveReturn;

    // --- The price step ---
    /** Price as the price step opens. */
    public float $priceAtStart;
    /** Float cap as the price step opens, which the fund's slice and the board's return are weighted on. */
    public float $floatCapAtStart;
    /**
     * @var array{price: float, shock: float|null, next_volatility: float, analyst_targets: array<string, float>, perceived_fair_value: float, dynamic_reversion: float, policy_repricing: float}
     */
    public array $pricing;
    /** Price after the diffusion and the tick's order flow. */
    public float $price;
    public float $volatility;
    /** Net shares the tick's order flow traded, the company's own program included. */
    public float $flowShares = 0.0;
    /** The sovereign fund's slice of its rebalance in this name, in shares. */
    public float $fundShares = 0.0;

    // --- The report and the return ---
    /** @var list<array<string, mixed>>|null The report's events; null on a tick the name does not report. */
    public ?array $reportEvents = null;
    public float $priceAfterReport;
    /** The tick's total return, dividend added back, in logs. */
    public float $logReturn = 0.0;

    // --- The share count ---
    /** New shares per old share a split leaves; 1.0 when nothing happened. */
    public float $splitRatio = 1.0;
    public float $finalPrice;
    public float $shares;
    public float $perceivedFairValue;
    /** @var array<string, float> */
    public array $analystTargets;

    public function __construct(public readonly Stock $stock)
    {
        // Float shares before anything this tick can issue or retire stock: an acquisition paid in stock is struck
        // ahead of the earnings engine's buybacks, offerings and vested awards.
        $this->sharesAtStart = (float) $stock->getSharesOutstanding();
        $this->floatSharesAtStart = $this->sharesAtStart * max(0.0, min(1.0, (float) $stock->getPublicFloatPercentage()));
    }
}
