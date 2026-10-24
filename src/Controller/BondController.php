<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\MacroStateDTO;
use App\Entity\Bond;
use App\Entity\User;
use App\Repository\BondRepository;
use App\Repository\HoldingRepository;
use App\Repository\TradeOrderRepository;
use App\Service\Macro\MacroStateProvider;
use App\Service\Market\Bond\BondPricingEngine;
use App\Service\Market\Chart\PriceChangeFeed;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FixedIncome;
use App\Service\User\CostBasisCalculator;
use App\Service\User\CouponIncomeCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The sovereign bond desk: the outstanding ladder, and the page for one issue.
 *
 * Separate from StockController rather than a set of branches inside it. An equity page is built around
 * earnings, margins and a business model; a bond page is built around a maturity date, a coupon and two
 * risk measures, and the only thing the two genuinely share is a price chart and an order ticket.
 */
class BondController extends AbstractController
{
    // --- Rate Sensitivity ---
    /** The parallel yield shifts an issue's page prices from its duration and convexity: 50 and 100 bps each way. */
    private const RATE_SHOCKS = [-0.01, -0.005, 0.005, 0.01];

    // --- Retired Issues ---
    /** What an issue's page says once it stops trading: its badge, the badge's tone and the ticket's line. */
    public const RETIRED = [
        Bond::STATUS_MATURED => ['badge' => 'Redeemed', 'tone' => 'neutral', 'note' => 'This issue has redeemed at par and no longer trades.'],
        Bond::STATUS_CALLED => ['badge' => 'Called', 'tone' => 'neutral', 'note' => 'The issuer bought this issue back at the market price; it no longer trades.'],
        Bond::STATUS_DEFAULTED => ['badge' => 'In default', 'tone' => 'down', 'note' => 'The issuer failed and the claim was settled at its recovery; it no longer trades.'],
    ];

    /**
     * The outstanding ladder, grouped by original tenor, alongside the live curve.
     */
    #[Route('/bonds', name: 'app_bond_ladder')]
    public function ladder(
        BondRepository $bondRepository,
        HoldingRepository $holdingRepository,
        MacroStateProvider $macroStateProvider,
        BondPricingEngine $pricingEngine,
        PriceChangeFeed $priceChangeFeed,
    ): Response {
        $macro = $macroStateProvider->liveState();

        $bonds = $bondRepository->findActiveAlongTheCurve();

        $rows = [];
        foreach ($bonds as $bond) {
            $rows[(string) (float) $bond->getTenorYears()][] = [
                'bond' => $bond,
                'yearsToMaturity' => $bond->yearsToMaturity($macro->totalTime),
                'change' => $priceChangeFeed->changeForTicker($bond->getTicker(), (float) $bond->getCleanPrice()),
            ];
        }

        // The corporate market, read one borrower at a time. Grouped by issuer rather than by tenor: a
        // company's own ladder is what a credit reader compares, and its rating and spread belong to the
        // borrower rather than to any one of its issues.
        $corporate = [];
        foreach ($bondRepository->findActiveCorporate() as $bond) {
            $issuer = $bond->getIssuer();

            if ($issuer === null) {
                continue;
            }

            $ticker = $issuer->getTicker();

            if (!isset($corporate[$ticker])) {
                $corporate[$ticker] = [
                    'issuer' => $issuer,
                    'rating' => $issuer->getCreditRating(),
                    'spread' => (float) $issuer->getDynamicCreditSpread(),
                    'issues' => [],
                ];
            }

            $corporate[$ticker]['issues'][] = [
                'bond' => $bond,
                'yearsToMaturity' => $bond->yearsToMaturity($macro->totalTime),
                'change' => $priceChangeFeed->changeForTicker($bond->getTicker(), (float) $bond->getCleanPrice()),
            ];
        }

        // Every position the signed-in user holds, so the ladder can mark their own line items.
        $holdings = [];
        $user = $this->getUser();
        if ($user instanceof User) {
            foreach ($holdingRepository->findBondHoldings($user) as $holding) {
                $holdings[$holding->getBond()->getTicker()] = (int) $holding->getQuantity();
            }
        }

        return $this->render('bond/ladder.html.twig', [
            'rowsByTenor' => $rows,
            'corporateByIssuer' => $corporate,
            'holdings' => $holdings,
            'curve' => $this->sampleCurve($pricingEngine, $macro),
            'macro' => $macro,
            'benchmarks' => $this->benchmarkPoints($macro),
        ]);
    }

    /**
     * One issue: its chart, its risk measures, its remaining cash flows, and the order ticket.
     */
    #[Route('/bond/{ticker}', name: 'app_bond_view')]
    public function view(
        string $ticker,
        BondRepository $bondRepository,
        HoldingRepository $holdingRepository,
        TradeOrderRepository $orders,
        MacroStateProvider $macroStateProvider,
        BondPricingEngine $pricingEngine,
        PriceChangeFeed $priceChangeFeed,
        CostBasisCalculator $costBasis,
        CouponIncomeCalculator $couponIncome,
        #[Autowire('%app.ticks_per_year%')]
        int $ticksPerYear,
    ): Response {
        $bond = $bondRepository->findOneByTicker($ticker);
        if (!$bond instanceof Bond) {
            throw $this->createNotFoundException('Issue not found');
        }

        $macro = $macroStateProvider->liveState();
        $currentTime = $macro->totalTime;

        $userQuantity = 0;
        $userAvgCost = 0.0;
        $userCouponIncome = 0.0;
        $openOrders = [];

        $user = $this->getUser();
        if ($user instanceof User) {
            $holding = $holdingRepository->findBondHolding($user, $bond);
            $userQuantity = $holding ? (int) $holding->getQuantity() : 0;

            $userAvgCost = $costBasis->calculate($orders->findFilledForUser($user))[$ticker] ?? 0.0;
            $userCouponIncome = $couponIncome->totalsByTicker($user)[$ticker] ?? 0.0;

            $openOrders = $orders->findOpenForUserAndTicker($user, $ticker);
        }

        // Remaining flows are shown as dates and amounts rather than as a present value: the point of the
        // schedule is that a bond's return is contractual, and a discounted total hides exactly that. A called or
        // defaulted issue has none left, whatever its maturity date says.
        $isActive = $bond->getStatus() === Bond::STATUS_ACTIVE;
        $cashFlows = [];
        foreach ($isActive ? $pricingEngine->remainingCashFlows($bond, $currentTime) : [] as $flow) {
            $cashFlows[] = [
                'yearsAway' => $flow['time'],
                'amount' => $flow['amount'],
                'isRedemption' => $flow['amount'] > $bond->couponAmount(),
            ];
        }

        return $this->render('bond/view.html.twig', [
            'bond' => $bond,
            'yearsToMaturity' => $bond->yearsToMaturity($currentTime),
            'retired' => self::RETIRED[$bond->getStatus()] ?? null,
            'changePercent' => $isActive ? $priceChangeFeed->changeForTicker($ticker, (float) $bond->getCleanPrice()) : null,
            'cashFlows' => $cashFlows,
            'annualCouponIncome' => $bond->couponAmount() * FinancialConstants::BOND_COUPON_FREQUENCY,
            'userQuantity' => $userQuantity,
            'userAvgCost' => $userAvgCost,
            'userCouponIncome' => $userCouponIncome,
            'openOrders' => $openOrders,
            // The ticket starts from the price the desk deals at, off the live curve; the mark above is the day's.
            'dealingPrice' => $isActive ? $pricingEngine->dealingPrice($bond, $macro) : (float) $bond->getPrice(),
            'rateShocks' => self::rateShocks($bond),
            'ticksPerYear' => $ticksPerYear,
        ]);
    }

    /**
     * The issue's estimated price move at each of RATE_SHOCKS, from its own duration and convexity.
     *
     * @return list<array{shift: float, move: float}>
     */
    public static function rateShocks(Bond $bond): array
    {
        $rows = [];
        foreach (self::RATE_SHOCKS as $shift) {
            $rows[] = [
                'shift' => $shift,
                'move' => FixedIncome::priceChangeForShift((float) $bond->getModifiedDuration(), (float) $bond->getConvexity(), $shift),
            ];
        }

        return $rows;
    }

    /**
     * Samples the live curve at the display tenors.
     *
     * Read through BondPricingEngine rather than interpolated between the four published benchmark points,
     * so the drawn curve is the same function the desk discounts with. A chart fitted through the
     * benchmarks would be a picture of a different curve than the one pricing the bonds beneath it.
     *
     * @return list<array{tenor: float, yield: float}>
     */
    private function sampleCurve(BondPricingEngine $pricingEngine, MacroStateDTO $macro): array
    {
        $curve = $macro->sovereignCurve();

        $points = [];
        foreach (FinancialConstants::BOND_CURVE_SAMPLE_TENORS as $tenor) {
            $points[] = ['tenor' => (float) $tenor, 'yield' => $pricingEngine->zeroYield($curve, (float) $tenor)];
        }

        return $points;
    }

    /**
     * The benchmark points as the macro engine publishes them, for the readout beside the curve.
     *
     * @return list<array{label: string, tenor: float, yield: float}>
     */
    private function benchmarkPoints(MacroStateDTO $macro): array
    {
        return [
            ['label' => '2Y', 'tenor' => 2.0, 'yield' => $macro->yield2y],
            ['label' => '5Y', 'tenor' => 5.0, 'yield' => $macro->yield5y],
            ['label' => '10Y', 'tenor' => 10.0, 'yield' => $macro->yield10y],
            ['label' => '30Y', 'tenor' => 30.0, 'yield' => $macro->yield30y],
        ];
    }
}
