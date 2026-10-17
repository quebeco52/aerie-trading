<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Entity\User;
use App\Entity\UserBond;
use App\Entity\UserEtf;
use App\Entity\UserOption;
use App\Entity\UserStock;
use App\Repository\HoldingRepository;
use App\Repository\TradeOrderRepository;
use App\Service\Macro\MacroStateProvider;
use App\Service\Market\Trading\ForcedLiquidationService;
use App\Service\Market\Trading\MarginEngine;
use App\Service\Math\FinancialConstants;
use App\Service\User\CostBasisCalculator;
use App\Service\User\CouponIncomeCalculator;
use App\Service\User\DividendIncomeCalculator;
use App\Service\User\Portfolio;

/**
 * The player's dashboard: each position marked at the live price against what it cost and what it has paid, the
 * book's spread over sectors and asset classes, the open orders and the trade history.
 *
 * The headline and the allocation are the account's net worth on the statement every ranking uses
 * (Portfolio::netWorthOf()), so the dashboard, the history chart and the leaderboard show one number. The positions
 * table and its profit are struck from the holdings themselves.
 */
final class DashboardPageBuilder
{
    // --- Portfolio Display ---
    /** Settled orders listed in the trade history; older fills are portfolio history, not a working record. */
    private const TRADE_HISTORY_ROWS = 50;
    /** Colours the allocation's asset slices take in order; cash and the margin loan keep their own. */
    private const SERIES_COLOURS = ['bg-series-blue', 'bg-series-orange', 'bg-series-aqua', 'bg-series-yellow', 'bg-series-magenta'];

    public function __construct(
        private readonly HoldingRepository $holdings,
        private readonly TradeOrderRepository $orders,
        private readonly CostBasisCalculator $costBasis,
        private readonly DividendIncomeCalculator $dividendIncome,
        private readonly CouponIncomeCalculator $couponIncome,
        private readonly MarginEngine $marginEngine,
        private readonly MacroStateProvider $macroStates,
        private readonly PlayerPanelBuilder $playerPanels,
        private readonly Portfolio $portfolio,
    ) {}

    /**
     * @param int $requestedTradePage The trade history page asked for, clamped to the pages there are.
     *
     * @return array<string, mixed> The dashboard template's variables.
     */
    public function build(User $user, int $requestedTradePage): array
    {
        $userId = (int) $user->getId();

        $filledOrders = $this->orders->findFilledForUser($user);
        $costBasisMap = $this->costBasis->calculate($filledOrders);

        // Dividend cash received, per ticker and for the lifetime of the account. Keyed by ticker rather
        // than by position because it includes income from shares since sold: the cash was received and
        // belongs in total P&L even though the position behind it is gone.
        $dividendMap = $this->dividendIncome->totalsByTicker($user);

        // Coupon cash is income in exactly the same sense a dividend is, so it belongs in the same headline
        // total. Kept in its own map because the two come from different ledgers.
        $couponMap = $this->couponIncome->totalsByTicker($user);

        // The holdings queries fetch-join their assets, so the rows below do not issue a query per position.
        $holdings = [
            ...self::stockRows($this->holdings->findStockHoldings($user), $costBasisMap, $dividendMap),
            ...self::etfRows($this->holdings->findEtfHoldings($user), $costBasisMap, $dividendMap),
            ...self::bondRows($this->holdings->findBondHoldings($user), $costBasisMap, $couponMap),
            ...self::optionRows($this->holdings->findOptionHoldings($user)),
        ];
        $marked = self::markedTotals($holdings);

        $netWorth = $this->portfolio->netWorthOf($userId);
        $portfolioValue = $netWorth['total_value'];
        // Value working in open limit orders, which net worth counts: a BUY has already debited the cash to escrow
        // and a SELL has already removed the shares from the holdings.
        $escrow = $this->portfolio->openOrderEscrow($userId);

        // Summed in holdings order, before the table is sorted by size.
        $sectorBreakdown = self::sectorBreakdown($holdings, $portfolioValue);
        $holdings = self::weighted($holdings, $portfolioValue);

        $tradePages = max(1, (int) ceil($this->orders->countSettledForUser($user) / self::TRADE_HISTORY_ROWS));
        $tradePage = min($tradePages, max(1, $requestedTradePage));
        $macro = $this->macroStates->liveState();

        return [
            'user' => $user,
            'holdings' => $holdings,
            'portfolioValue' => $portfolioValue,
            'totalInvested' => $netWorth['stock_value'] + $netWorth['etf_value'] + $netWorth['bond_value'] + $netWorth['option_value'],
            'totalInvestedCost' => $marked['cost'],
            'totalUnrealizedPnL' => $marked['value'] - $marked['cost'],
            'totalUnrealizedPnLPercent' => $marked['cost'] > 0 ? (($marked['value'] - $marked['cost']) / $marked['cost']) * 100 : 0.0,
            'totalRealised' => array_sum($this->costBasis->realisedByTicker($filledOrders)),
            'cashBalance' => $netWorth['cash_balance'],
            'escrowedCash' => $escrow['cash'],
            'escrowedShareValue' => $escrow['shares'],
            'marginDebit' => $netWorth['margin_debit'],
            // Marked against live prices rather than the figures assembled above, so the risk panel and the
            // sweep that acts on it are reading the same numbers.
            'margin' => $this->marginEngine->status($user),
            'openOrders' => $this->orders->findOpenForUser($user),
            // Open orders show the price each one is waiting against.
            'livePrices' => $this->orders->livePricesForOpenOrders($user),
            'tradeHistory' => $this->orders->findSettledForUser($user, self::TRADE_HISTORY_ROWS, ($tradePage - 1) * self::TRADE_HISTORY_ROWS),
            'optionMultiplier' => FinancialConstants::OPTION_CONTRACT_MULTIPLIER,
            'sectorBreakdown' => $sectorBreakdown,
            'allocation' => self::allocation($netWorth),
            'cashShare' => $portfolioValue > 0 ? $netWorth['cash_balance'] / $portfolioValue : null,
            // The rates the ticker accrues at, from the same two functions it calls.
            'cashRate' => Portfolio::cashSweepRate($macro->policyRateEma),
            'marginRate' => ForcedLiquidationService::marginLoanRate($macro->policyRate),
            'totalDividendIncome' => array_sum($dividendMap) + array_sum($couponMap),
            'dividendPayments' => $this->dividendIncome->recentPayments($user),
            'couponPayments' => $this->couponIncome->recentPayments($user),
            'tradePage' => $tradePage,
            'tradePages' => $tradePages,
        ] + $this->playerPanels->build($user, $macro->totalTime, $filledOrders !== []);
    }

    /**
     * Each stock position, long or short. A short's quantity is negative, and the arithmetic needs no special case:
     * market value is the obligation, cost is the proceeds, and their difference is profit in either direction. What
     * does need care is the denominator of the percentage.
     *
     * @param list<UserStock>      $holdings
     * @param array<string, float> $costBasis Average cost per ticker.
     * @param array<string, float> $dividends Dividend cash received per ticker.
     *
     * @return list<array<string, mixed>>
     */
    public static function stockRows(array $holdings, array $costBasis, array $dividends): array
    {
        $rows = [];
        foreach ($holdings as $holding) {
            $stock = $holding->getStock();
            $ticker = $stock->getTicker();
            $currentPrice = (float) $stock->getPrice();
            $quantity = (int) $holding->getQuantity();
            if ($quantity === 0) {
                continue;
            }

            $marketValue = $currentPrice * $quantity;
            $avgCost = $costBasis[$ticker] ?? $currentPrice;
            $positionCost = $avgCost * $quantity;
            $unrealizedPnL = $marketValue - $positionCost;

            $rows[] = [
                'type' => 'STOCK',
                'ticker' => $ticker,
                'name' => $stock->getName(),
                'sector' => $stock->getSector() ?? 'General',
                'quantity' => $quantity,
                'price' => $currentPrice,
                'avgCost' => $avgCost,
                'totalCost' => $positionCost,
                'marketValue' => $marketValue,
                'unrealizedPnL' => $unrealizedPnL,
                'unrealizedPnLPercent' => abs($positionCost) > 0.0 ? ($unrealizedPnL / abs($positionCost)) * 100 : 0.0,
                'dividendsReceived' => $dividends[$ticker] ?? 0.0,
                'isBankrupt' => $stock->isBankrupt(),
                'isShort' => $quantity < 0,
                'borrowAccrued' => (float) $holding->getBorrowAccrued(),
                'weight' => 0.0,
            ];
        }

        return $rows;
    }

    /**
     * Each index fund position.
     *
     * @param list<UserEtf>        $holdings
     * @param array<string, float> $costBasis Average cost per ticker.
     * @param array<string, float> $dividends Distributions received per ticker.
     *
     * @return list<array<string, mixed>>
     */
    public static function etfRows(array $holdings, array $costBasis, array $dividends): array
    {
        $rows = [];
        foreach ($holdings as $holding) {
            $etf = $holding->getEtf();
            $ticker = $etf->getTicker();
            $currentPrice = (float) $etf->getPrice();
            $quantity = $holding->getQuantity();
            if ($quantity <= 0) {
                continue;
            }

            $marketValue = $currentPrice * $quantity;
            $avgCost = $costBasis[$ticker] ?? $currentPrice;
            $positionCost = $avgCost * $quantity;
            $unrealizedPnL = $marketValue - $positionCost;

            $rows[] = [
                'type' => 'ETF',
                'ticker' => $ticker,
                'name' => $etf->getName(),
                'sector' => 'Market Index',
                'quantity' => $quantity,
                'price' => $currentPrice,
                'avgCost' => $avgCost,
                'totalCost' => $positionCost,
                'marketValue' => $marketValue,
                'unrealizedPnL' => $unrealizedPnL,
                'unrealizedPnLPercent' => $positionCost > 0 ? ($unrealizedPnL / $positionCost) * 100 : 0.0,
                'dividendsReceived' => $dividends[$ticker] ?? 0.0,
                'isBankrupt' => false,
                'weight' => 0.0,
            ];
        }

        return $rows;
    }

    /**
     * Each bond position, marked at the dirty price, which is what the position would actually realise: a buyer pays
     * the holder for the coupon accrued since the last payment.
     *
     * @param list<UserBond>       $holdings
     * @param array<string, float> $costBasis Average cost per ticker.
     * @param array<string, float> $coupons   Coupon cash received per ticker.
     *
     * @return list<array<string, mixed>>
     */
    public static function bondRows(array $holdings, array $costBasis, array $coupons): array
    {
        $rows = [];
        foreach ($holdings as $holding) {
            $bond = $holding->getBond();
            $ticker = $bond->getTicker();
            $quantity = $holding->getQuantity();
            if ($quantity <= 0) {
                continue;
            }

            $currentPrice = (float) $bond->getPrice();
            $marketValue = $currentPrice * $quantity;
            $avgCost = $costBasis[$ticker] ?? $currentPrice;
            $positionCost = $avgCost * $quantity;
            $unrealizedPnL = $marketValue - $positionCost;

            $rows[] = [
                'type' => 'BOND',
                'ticker' => $ticker,
                'name' => $bond->getName(),
                'sector' => $bond->getIssuer() !== null ? 'Corporate debt' : 'Sovereign debt',
                'quantity' => $quantity,
                'price' => $currentPrice,
                'avgCost' => $avgCost,
                'totalCost' => $positionCost,
                'marketValue' => $marketValue,
                'unrealizedPnL' => $unrealizedPnL,
                'unrealizedPnLPercent' => $positionCost > 0 ? ($unrealizedPnL / $positionCost) * 100 : 0.0,
                'dividendsReceived' => $coupons[$ticker] ?? 0.0,
                'isBankrupt' => false,
                'weight' => 0.0,
                'yieldToMaturity' => (float) $bond->getYieldToMaturity(),
                'modifiedDuration' => (float) $bond->getModifiedDuration(),
                'couponRate' => (float) $bond->getCouponRate(),
            ];
        }

        return $rows;
    }

    /**
     * Each option position. Signed throughout: a written contract is a negative position whose market value is a
     * liability and whose gain is the premium decaying, so the same two subtractions that price a long position price
     * a short one. Premiums are carried per share, the convention the desk quotes in, so the multiplier appears
     * wherever currency does.
     *
     * @param list<UserOption> $holdings
     *
     * @return list<array<string, mixed>>
     */
    public static function optionRows(array $holdings): array
    {
        $rows = [];
        foreach ($holdings as $holding) {
            $contracts = (int) $holding->getQuantity();
            if ($contracts === 0) {
                continue;
            }

            $contract = $holding->getContract();
            $shares = $contracts * FinancialConstants::OPTION_CONTRACT_MULTIPLIER;
            $mark = (float) $contract->getPrice();
            $basis = (float) $holding->getAveragePremium();
            $marketValue = $mark * $shares;
            $positionCost = $basis * $shares;
            $unrealizedPnL = $marketValue - $positionCost;
            $underlying = $contract->getStock()->getTicker();

            $rows[] = [
                'type' => 'OPTION',
                'ticker' => $contract->getTicker(),
                'name' => sprintf('%s %s $%s', $underlying, $contract->isCall() ? 'Call' : 'Put', number_format((float) $contract->getStrike(), 2)),
                'sector' => 'Options',
                'quantity' => $contracts,
                'price' => $mark,
                'avgCost' => $basis,
                'totalCost' => $positionCost,
                'marketValue' => $marketValue,
                'unrealizedPnL' => $unrealizedPnL,
                // Measured against what the position tied up, not against a signed cost: a written contract has a
                // negative basis, and dividing by it would report a profitable short as a loss.
                'unrealizedPnLPercent' => abs($positionCost) > 0.0 ? ($unrealizedPnL / abs($positionCost)) * 100 : 0.0,
                'dividendsReceived' => 0.0,
                'isBankrupt' => false,
                'weight' => 0.0,
                'underlying' => $underlying,
                'expiresAtTime' => $contract->getExpiresAtTime(),
                'impliedVolatility' => (float) $contract->getImpliedVolatility(),
                'delta' => (float) $contract->getDelta(),
            ];
        }

        return $rows;
    }

    /**
     * What the positions are marked at and what they cost, summed class by class. An option's cost counts at what it
     * tied up, so premium written does not net against premium paid.
     *
     * @param list<array<string, mixed>> $holdings
     *
     * @return array{value: float, cost: float}
     */
    public static function markedTotals(array $holdings): array
    {
        $value = ['STOCK' => 0.0, 'ETF' => 0.0, 'BOND' => 0.0, 'OPTION' => 0.0];
        $cost = 0.0;
        foreach ($holdings as $row) {
            $value[$row['type']] += $row['marketValue'];
            $cost += $row['type'] === 'OPTION' ? abs($row['totalCost']) : $row['totalCost'];
        }

        return ['value' => $value['STOCK'] + $value['ETF'] + $value['BOND'] + $value['OPTION'], 'cost' => $cost];
    }

    /**
     * Each position's share of net worth, largest position first.
     *
     * @param list<array<string, mixed>> $holdings
     *
     * @return list<array<string, mixed>>
     */
    public static function weighted(array $holdings, float $portfolioValue): array
    {
        foreach ($holdings as &$row) {
            $row['weight'] = $portfolioValue > 0 ? ($row['marketValue'] / $portfolioValue) * 100 : 0.0;
        }
        unset($row);

        usort($holdings, static fn(array $a, array $b): int => $b['marketValue'] <=> $a['marketValue']);

        return $holdings;
    }

    /**
     * The book by sector, as a share of what is invested and of net worth. Gross, not net: a short or a written
     * contract is exposure, not an offset against a long, and a signed sum would let a paired book report itself as
     * holding nothing, or push every other sector's share past 100%.
     *
     * @param list<array<string, mixed>> $holdings
     *
     * @return list<array{name: string, value: float, percent: float, portfolioPercent: float}>
     */
    public static function sectorBreakdown(array $holdings, float $portfolioValue): array
    {
        $sectorValues = [];
        foreach ($holdings as $row) {
            $sector = $row['type'] === 'ETF' ? 'Market Index (ETF)' : (string) $row['sector'];
            $sectorValues[$sector] = ($sectorValues[$sector] ?? 0.0) + abs($row['marketValue']);
        }

        $investedTotal = array_sum($sectorValues);
        $breakdown = [];
        foreach ($sectorValues as $sectorName => $value) {
            $breakdown[] = [
                'name' => (string) $sectorName,
                'value' => $value,
                'percent' => $investedTotal > 0 ? ($value / $investedTotal) * 100 : 0.0,
                'portfolioPercent' => $portfolioValue > 0 ? ($value / $portfolioValue) * 100 : 0.0,
            ];
        }
        usort($breakdown, static fn(array $a, array $b): int => $b['value'] <=> $a['value']);

        return $breakdown;
    }

    /**
     * Net worth by asset class: the terms it is summed from, the margin loan as a negative slice, so the shares add up
     * to the whole. Stocks and cash always show; the rest only when held.
     *
     * @param array{cash_balance: float, margin_debit: float, stock_value: float, etf_value: float, bond_value: float, option_value: float, escrow_value: float, total_value: float} $netWorth
     *
     * @return list<array{label: string, value: float, share: float|null, colour: string}>
     */
    public static function allocation(array $netWorth): array
    {
        $slices = [
            ['label' => 'Stocks', 'value' => $netWorth['stock_value'], 'always' => true],
            ['label' => 'Index funds', 'value' => $netWorth['etf_value'], 'always' => false],
            ['label' => 'Bonds', 'value' => $netWorth['bond_value'], 'always' => false],
            ['label' => 'Options', 'value' => $netWorth['option_value'], 'always' => false],
            ['label' => 'In open orders', 'value' => $netWorth['escrow_value'], 'always' => false],
            ['label' => 'Cash', 'value' => $netWorth['cash_balance'], 'always' => true],
            ['label' => 'Margin loan', 'value' => -$netWorth['margin_debit'], 'always' => false],
        ];

        $colours = self::SERIES_COLOURS;
        $allocation = [];
        foreach ($slices as $slice) {
            if (!$slice['always'] && round($slice['value'], 2) === 0.0) {
                continue;
            }
            $allocation[] = [
                'label' => $slice['label'],
                'value' => $slice['value'],
                'share' => $netWorth['total_value'] > 0 ? $slice['value'] / $netWorth['total_value'] : null,
                'colour' => match ($slice['label']) {
                    'Cash' => 'bg-on-surface-faint',
                    'Margin loan' => 'bg-tertiary',
                    default => (string) array_shift($colours),
                },
            ];
        }

        return $allocation;
    }
}
