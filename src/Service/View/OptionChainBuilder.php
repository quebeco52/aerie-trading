<?php

declare(strict_types=1);

namespace App\Service\View;

use App\DTO\MacroStateDTO;
use App\DTO\OptionQuoteDTO;
use App\Entity\OptionContract;
use App\Entity\Stock;
use App\Entity\User;
use App\Entity\UserOption;
use App\Repository\OptionContractRepository;
use App\Service\Market\Gamma\DealerGammaStoreInterface;
use App\Service\Market\OptionChainService;
use App\Service\Market\OptionPricingEngine;
use App\Service\Math\FinancialConstants;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the option chain panel: the ladder as a desk lays it out, and what the desk is carrying because
 * of it.
 *
 * A chain reads as one table per expiry with calls on one side of the strike and puts on the other, because
 * what a trader compares is the two sides of the SAME strike — a call and a put struck together are one
 * position seen from two directions, and splitting them into separate tables hides that. The rows are the
 * strike ladder, so a row is empty on one side only when a contract has not been listed there.
 *
 * The chain is PRICED ON READ, the way candle bars are built on read. Nothing is stored for it: the desk
 * persists a mark only for contracts somebody actually holds, because those are the ones whose value has to
 * reach net worth and the margin requirement through SQL. Storing a mark for the other five thousand meant
 * rewriting every one of them every sweep — a guaranteed UPDATE per row, since a premium moves whenever the
 * underlying does — to populate a table that is only ever read one chain at a time.
 *
 * Quoting a chain here costs a fraction of a millisecond: it is one surface per expiry and then closed-form
 * Black-Scholes per strike, which is cheaper than the query that would have fetched the stored answer.
 */
class OptionChainBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OptionContractRepository $contracts,
        private readonly OptionChainService $chainService,
        private readonly OptionPricingEngine $pricingEngine,
        private readonly DealerGammaStoreInterface $gammaStore,
    ) {}

    /**
     * @return array<string, mixed> The chain keys the stock page renders.
     */
    public function build(Stock $stock, ?User $viewer, MacroStateDTO $macroState): array
    {
        $currentTime = $macroState->totalTime;
        $chain = $this->contracts->findChain($stock);

        if ($chain === []) {
            return [
                'optionsListed' => false,
                'optionsReason' => $this->whyNotListed($stock),
                'optionExpiries' => [],
                'optionDealerGamma' => 0.0,
                'optionDealerGammaPerPercent' => 0.0,
                'optionOpenInterest' => 0,
                'optionMultiplier' => FinancialConstants::OPTION_CONTRACT_MULTIPLIER,
            ];
        }

        $positions = $this->viewerPositions($viewer);
        $underlying = (float) $stock->getPrice();

        // One surface per expiry, then a closed form per strike. Struck against the live underlying, so the
        // table a player reads is the table the trade path would fill them at.
        $quotes = $this->pricingEngine->quoteChain($chain, $macroState->sovereignCurve(), $currentTime);

        $expiries = [];
        $openInterest = 0;

        foreach ($chain as $contract) {
            $quote = $quotes[$contract->getTicker()] ?? null;

            if ($quote === null) {
                continue;
            }

            $serial = $contract->getExpirySerial();

            if (!isset($expiries[$serial])) {
                $expiries[$serial] = [
                    'serial' => $serial,
                    'expiresAtTime' => $contract->getExpiresAtTime(),
                    'yearsToExpiry' => max(0.0, $contract->getExpiresAtTime() - $currentTime),
                    'daysToExpiry' => max(0.0, ($contract->getExpiresAtTime() - $currentTime) * 365.0),
                    'atmVolatility' => null,
                    'atmDistance' => null,
                    'rows' => [],
                ];
            }

            $strike = (float) $contract->getStrike();
            $strikeKey = (string) $strike;

            if (!isset($expiries[$serial]['rows'][$strikeKey])) {
                $expiries[$serial]['rows'][$strikeKey] = [
                    'strike' => $strike,
                    'call' => null,
                    'put' => null,
                ];
            }

            $expiries[$serial]['rows'][$strikeKey][$contract->isCall() ? 'call' : 'put']
                = $this->leg($contract, $quote, $positions, $underlying);

            $openInterest += abs($contract->customerOpenInterest());

            // The at-the-money volatility of an expiry is read off whichever listed strike the spot is
            // actually nearest, rather than assumed to be on the ladder: the spot is almost never exactly
            // on a round strike, and the nearest one is what a desk quotes the expiry from.
            $distance = abs($strike - $underlying);

            if ($expiries[$serial]['atmDistance'] === null || $distance < $expiries[$serial]['atmDistance']) {
                $expiries[$serial]['atmDistance'] = $distance;
                $expiries[$serial]['atmVolatility'] = $quote->impliedVolatility;
            }
        }

        foreach ($expiries as $serial => $expiry) {
            ksort($expiry['rows'], SORT_NUMERIC);

            $expiries[$serial]['rows'] = $this->markNearestStrike(array_values($expiry['rows']), $underlying);
        }

        ksort($expiries, SORT_NUMERIC);

        $gamma = $this->gammaStore->read($stock->getTicker());
        $dealerGamma = $gamma['gamma'] ?? 0.0;

        return [
            'optionsListed' => true,
            'optionsReason' => null,
            'optionExpiries' => array_values($expiries),
            // Shares of stock the desk must trade per 1.00 move. The desk is SHORT this, so a positive
            // number means it buys into strength and sells into weakness.
            'optionDealerGamma' => $dealerGamma,
            // The same figure in the unit a reader can weigh against daily volume: shares per 1% move.
            'optionDealerGammaPerPercent' => $dealerGamma * $underlying * 0.01,
            'optionOpenInterest' => $openInterest,
            'optionMultiplier' => FinancialConstants::OPTION_CONTRACT_MULTIPLIER,
        ];
    }

    /**
     * Flags the strike the spot is sitting nearest, which is where the chain is read from.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function markNearestStrike(array $rows, float $underlying): array
    {
        $nearest = null;
        $distance = null;

        foreach ($rows as $index => $row) {
            $rows[$index]['isNearestStrike'] = false;
        }

        foreach ($rows as $index => $row) {
            $gap = abs((float) $row['strike'] - $underlying);

            if ($distance === null || $gap < $distance) {
                $distance = $gap;
                $nearest = $index;
            }
        }

        if ($nearest !== null) {
            $rows[$nearest]['isNearestStrike'] = true;
        }

        return $rows;
    }

    /**
     * One side of one strike.
     *
     * @param array<int, int> $positions Viewer contracts held, keyed by contract id.
     * @return array<string, mixed>
     */
    private function leg(OptionContract $contract, OptionQuoteDTO $quote, array $positions, float $underlying): array
    {
        $strike = (float) $contract->getStrike();

        // A contract that has not been persisted has no id to look a position up by, and using the null as
        // an offset would both warn and quietly collide every such contract onto one bucket.
        $id = $contract->getId();

        return [
            'ticker' => $contract->getTicker(),
            'mark' => $quote->mark,
            'impliedVolatility' => $quote->impliedVolatility,
            'delta' => $quote->delta,
            'gamma' => $quote->gamma,
            'theta' => $quote->theta,
            'openInterest' => $contract->customerOpenInterest(),
            'position' => $id !== null ? ($positions[$id] ?? 0) : 0,
            'inTheMoney' => $contract->isCall() ? $underlying > $strike : $underlying < $strike,
        ];
    }

    /**
     * The viewer's contracts, keyed by contract id.
     *
     * One query for the whole book rather than one per row: a chain is a hundred contracts and the viewer
     * holds at most a handful of them.
     *
     * @return array<int, int>
     */
    private function viewerPositions(?User $viewer): array
    {
        if (!$viewer instanceof User) {
            return [];
        }

        $rows = $this->em->getRepository(UserOption::class)->findBy(['user' => $viewer]);
        $positions = [];

        foreach ($rows as $row) {
            $id = $row->getContract()->getId();

            if ($id !== null) {
                $positions[$id] = (int) $row->getQuantity();
            }
        }

        return $positions;
    }

    /**
     * Why a name carries no contracts, in the terms the listing standard is actually written in.
     */
    private function whyNotListed(Stock $stock): string
    {
        if ($stock->isBankrupt()) {
            return 'The company is bankrupt. Its contracts have been settled and the class is closed.';
        }

        if ((float) $stock->getPrice() < FinancialConstants::OPTION_LISTING_MIN_PRICE) {
            return sprintf(
                'Listed contracts require a share price of at least $%s. A ladder struck on a lower price has no usable strikes.',
                number_format(FinancialConstants::OPTION_LISTING_MIN_PRICE, 2)
            );
        }

        if (!$this->chainService->isListable($stock)) {
            return sprintf(
                'The exchange lists contracts against a trading record. This name turns over less than %s shares a day.',
                number_format(FinancialConstants::OPTION_LISTING_MIN_ADV)
            );
        }

        return 'The desk has not opened a class on this name yet.';
    }
}
