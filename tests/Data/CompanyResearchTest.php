<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\AnchorHoldings;
use App\Data\CompanyResearch;
use App\Data\InitialMarket;
use App\Data\ModelParam;
use App\Data\StockModelTuning;
use App\Service\Model\Sector\DefenseContractorBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The long-form profiles are lore, and lore sets facts, never the model: a figure in the text must be the figure the
 * firm runs on, and a firm the text names must be on the board.
 */
class CompanyResearchTest extends TestCase
{
    /** @return iterable<string, array{string, array{headline: string, standfirst: string, sections: list<array{heading: string, body: string}>, related: list<string>}}> */
    public static function articleProvider(): iterable
    {
        foreach (CompanyResearch::ARTICLES as $ticker => $article) {
            yield $ticker => [$ticker, $article];
        }
    }

    /** @return array<string, mixed> */
    private static function seed(string $ticker): array
    {
        foreach (InitialMarket::STOCKS as $stock) {
            if ($stock['ticker'] === $ticker) {
                return $stock;
            }
        }
        self::fail("$ticker is not on the board.");
    }

    private static function text(array $article): string
    {
        return implode("\n\n", [$article['headline'], $article['standfirst'], ...array_map(
            static fn (array $section): string => $section['heading'] . "\n\n" . $section['body'],
            $article['sections']
        )]);
    }

    #[DataProvider('articleProvider')]
    public function testArticleReadsAsHouseCopy(string $ticker, array $article): void
    {
        self::seed($ticker);
        $text = self::text($article);
        $words = str_word_count(implode(' ', array_column($article['sections'], 'body')));

        $this->assertGreaterThanOrEqual(3, count($article['sections']), "$ticker: a profile has at least three sections.");
        $this->assertGreaterThanOrEqual(350, $words, "$ticker: a long-form profile runs 400-800 words.");
        $this->assertLessThanOrEqual(850, $words, "$ticker: a long-form profile runs 400-800 words.");
        $this->assertDoesNotMatchRegularExpression(StockInfoTest::AI_TELLS, $text, "$ticker: marketing adjectives and stock AI constructions.");
        $this->assertStringNotContainsString('—', $text, "$ticker: use a comma, colon or new sentence, not an em dash.");
        $this->assertDoesNotMatchRegularExpression('/\b(Rate Council|Lakebird Exchange|the Fed|Treasury|simulat\w*|tick)\b/', $text, "$ticker: stay inside the fiction and use the District's institution names.");
    }

    /** Every listed company the text names in full is linked from the page, and every link is named in the text. */
    #[DataProvider('articleProvider')]
    public function testRelatedCompaniesAreTheOnesTheTextNames(string $ticker, array $article): void
    {
        $text = self::text($article);
        $names = array_column(InitialMarket::STOCKS, 'name', 'ticker');

        foreach ($names as $listed => $name) {
            $shortName = implode(' ', array_slice(explode(' ', $name), 0, 2));
            if ($listed !== $ticker && str_contains($text, $shortName)) {
                $this->assertContains($listed, $article['related'], "$ticker: the text names $shortName; add $listed to 'related'.");
            }
        }

        foreach ($article['related'] as $related) {
            $this->assertArrayHasKey($related, $names, "$ticker: $related is not on the board.");
            $this->assertStringContainsString(explode(' ', $names[$related])[0], $text, "$ticker: $related is linked but never named.");
        }
    }

    /** @return iterable<string, array{string, string, float, float}> */
    public static function figureProvider(): iterable
    {
        $tuning = static fn (string $ticker, ModelParam $param): float => StockModelTuning::get($ticker, $param, NAN);

        yield 'LAKE payout' => ['LAKE', 'pays out about 40% of earnings', self::seed('LAKE')['target_payout_ratio'], 0.40];
        yield 'LAKE fees' => ['LAKE', 'add a further fifth of revenue', $tuning('LAKE', ModelParam::FeeRevenueWeight), 0.20];
        yield 'LAKE syndicate' => ['LAKE', 'a steady fifth of its revenue', $tuning('LAKE', ModelParam::ProprietaryDividendWeight), 0.20];
        yield 'LAKE mortgages' => ['LAKE', 'close to a third of the loan book', $tuning('LAKE', ModelParam::ResidentialMortgageShare), 0.30];
        yield 'LAKE float' => ['LAKE', 'About half the bank is still held that way', 1.0 - self::seed('LAKE')['public_float'], 0.50];
        yield 'SWAN fees' => ['SWAN', 'about 60% of revenue', $tuning('SWAN', ModelParam::HfManagementFeeWeight), 0.60];
        yield 'SWAN float' => ['SWAN', 'founding partners still hold about half the shares', 1.0 - self::seed('SWAN')['public_float'], 0.50];
        yield 'SAFE mutual' => ['SAFE', 'the mutual kept about 30% of the shares', 1.0 - self::seed('SAFE')['public_float'], 0.30];
        yield 'SAFE treaties' => ['SAFE', 'About 60% of revenue comes from these reinsurance treaties', $tuning('SAFE', ModelParam::TreatyReinsuranceWeight), 0.60];
        yield 'SAFE payout' => ['SAFE', 'pays out about 30% of earnings', self::seed('SAFE')['target_payout_ratio'], 0.30];
        yield 'SAFE beta' => ['SAFE', 'beta of about 0.2', self::seed('SAFE')['beta'], 0.20];
        yield 'BRKW foundation' => ['BRKW', 'holds about a quarter of the shares', 1.0 - self::seed('BRKW')['public_float'], 0.25];
        yield 'BRKW Crossbill' => ['BRKW', 'owns half of Crossbill', AnchorHoldings::forHolder('BRKW')['CBIL'], 0.50];
        yield 'BRKW Alca' => ['BRKW', 'half of Alca', AnchorHoldings::forHolder('BRKW')['ALCA'], 0.50];
        foreach (['ALBT', 'KSTL', 'BIRD', 'ERNE'] as $held) {
            yield "BRKW $held" => ['BRKW', 'just over a third of four more', AnchorHoldings::forHolder('BRKW')[$held], 0.35];
        }
        yield 'BRKW payout' => ['BRKW', 'pays out about 40% of what it earns', self::seed('BRKW')['target_payout_ratio'], 0.40];
        yield 'BRKW beta' => ['BRKW', 'beta of about 0.9', self::seed('BRKW')['beta'], 0.90];
        yield 'IBHI owners' => ['IBHI', 'hold about 55% of the shares between them', 1.0 - self::seed('IBHI')['public_float'], 0.55];
        yield 'IBHI civil' => ['IBHI', 'About 60% of revenue comes from civil infrastructure', $tuning('IBHI', ModelParam::CivilInfrastructureWeight), 0.60];
        yield 'IBHI commercial' => ['IBHI', 'A quarter comes from commercial work', $tuning('IBHI', ModelParam::CommercialEpcWeight), 0.25];
        yield 'IBHI maintenance' => ['IBHI', 'the remaining 15% from maintenance contracts', $tuning('IBHI', ModelParam::FacilitiesMaintenanceWeight), 0.15];
        yield 'IBHI margin' => ['IBHI', 'operating margin is about 9%', self::seed('IBHI')['operating_margin'], 0.09];
        yield 'IBHI beta' => ['IBHI', 'beta of about 1.8', self::seed('IBHI')['beta'], 1.80];
        yield 'PERE options' => ['PERE', 'About 60% of revenue comes from writing options', $tuning('PERE', ModelParam::OptionsPremiumIncomeWeight), 0.60];
        yield 'PERE trading' => ['PERE', 'The other 40% comes from trading', $tuning('PERE', ModelParam::TradingRevenueWeight), 0.40];
        yield 'PERE margin' => ['PERE', 'operating margin is about 35%', self::seed('PERE')['operating_margin'], 0.35];
        yield 'PERE payout' => ['PERE', 'pays out about 40% of earnings', self::seed('PERE')['target_payout_ratio'], 0.40];
        yield 'PERE beta' => ['PERE', 'beta of about -0.2', self::seed('PERE')['beta'], -0.20];
        yield 'CORV partners' => ['CORV', 'still hold about 15% of the shares', 1.0 - self::seed('CORV')['public_float'], 0.15];
        yield 'CORV advisory' => ['CORV', 'those fees are about a quarter of revenue', $tuning('CORV', ModelParam::AdvisoryRevenueWeight), 0.25];
        yield 'CORV trading' => ['CORV', 'The other 75% comes from its own trading desks', $tuning('CORV', ModelParam::TradingRevenueWeight), 0.75];
        yield 'CORV margin' => ['CORV', 'operating margin is about 45%', self::seed('CORV')['operating_margin'], 0.45];
        yield 'CORV payout' => ['CORV', 'pays out about 30% of earnings', self::seed('CORV')['target_payout_ratio'], 0.30];
        yield 'CORV beta' => ['CORV', 'beta of about -0.1', self::seed('CORV')['beta'], -0.10];
        yield 'WATCH concessions' => ['WATCH', 'about 40% of revenue', $tuning('WATCH', ModelParam::GovernmentContractWeight), 0.40];
        yield 'WATCH retainers' => ['WATCH', 'private retainers about half', $tuning('WATCH', ModelParam::RetainerWeight), 0.50];
        yield 'WATCH margin' => ['WATCH', 'operating margin is about 17%', self::seed('WATCH')['operating_margin'], 0.17];
        yield 'WATCH beta' => ['WATCH', 'about half the market\'s beta', self::seed('WATCH')['beta'], 0.50];
        yield 'TRIV industrial' => ['TRIV', 'About 60% of revenue comes from industrial products', $tuning('TRIV', ModelParam::IndustrialConglomerateWeight), 0.60];
        yield 'TRIV household' => ['TRIV', 'about 30% from household goods', $tuning('TRIV', ModelParam::DefensiveStaplesWeight), 0.30];
        yield 'TRIV margin' => ['TRIV', 'operating margin is about 16%', self::seed('TRIV')['operating_margin'], 0.16];
        yield 'TRIV payout' => ['TRIV', 'pays out about half its earnings', self::seed('TRIV')['target_payout_ratio'], 0.50];
        yield 'TRIV beta' => ['TRIV', 'beta of about 0.6', self::seed('TRIV')['beta'], 0.60];
        yield 'TICK subscriptions' => ['TICK', 'About 90% of revenue is subscriptions', $tuning('TICK', ModelParam::SubscriptionRevenueWeight), 0.90];
        yield 'TICK margin' => ['TICK', 'operating margin is about 55%', self::seed('TICK')['operating_margin'], 0.55];
        yield 'TICK beta' => ['TICK', 'beta of about 1.45', self::seed('TICK')['beta'], 1.45];
        yield 'GRIP float' => ['GRIP', 'Almost all of the shares now trade freely', self::seed('GRIP')['public_float'], 0.95];
        yield 'GRIP cost-plus' => ['GRIP', 'About 60% of revenue comes from cost-plus programmes', $tuning('GRIP', ModelParam::CostPlusWeight), 0.60];
        yield 'GRIP development' => ['GRIP', 'A fifth comes from fixed-price development', $tuning('GRIP', ModelParam::FixedPriceDevWeight), 0.20];
        yield 'GRIP exports' => ['GRIP', 'The last fifth is exports', $tuning('GRIP', ModelParam::ForeignMilitarySalesWeight), 0.20];
        yield 'GRIP margin' => ['GRIP', 'operating margin is about 14%', self::seed('GRIP')['operating_margin'], 0.14];
        yield 'GRIP payout' => ['GRIP', 'pays out about 45% of earnings', self::seed('GRIP')['target_payout_ratio'], 0.45];
        yield 'GRIP beta' => ['GRIP', 'beta of about 0.35', self::seed('GRIP')['beta'], 0.35];
        yield 'OSPR insiders' => ['OSPR', 'still hold about a tenth of the company', 1.0 - self::seed('OSPR')['public_float'], 0.10];
        yield 'OSPR deployments' => ['OSPR', 'About 80% of revenue comes from deployments abroad', $tuning('OSPR', ModelParam::ExpeditionaryWeight), 0.80];
        yield 'OSPR government' => ['OSPR', 'Government contracts and standing retainers make up a tenth each', $tuning('OSPR', ModelParam::GovernmentContractWeight), 0.10];
        yield 'OSPR retainers' => ['OSPR', 'Government contracts and standing retainers make up a tenth each', $tuning('OSPR', ModelParam::RetainerWeight), 0.10];
        yield 'OSPR margin' => ['OSPR', 'operating margin is about 22%', self::seed('OSPR')['operating_margin'], 0.22];
        yield 'OSPR payout' => ['OSPR', 'pays out about 45% of earnings', self::seed('OSPR')['target_payout_ratio'], 0.45];
        yield 'OSPR beta' => ['OSPR', 'beta of about -0.5', self::seed('OSPR')['beta'], -0.50];
        yield 'GRIP export ban' => ['GRIP', 'take half the export book', 1.0 - DefenseContractorBusinessModel::EXPORT_BAN_MULT, 0.50];
    }

    /** A figure the text quotes is the one the firm is seeded or tuned with, so an edit to either breaks here. */
    #[DataProvider('figureProvider')]
    public function testQuotedFiguresMatchTheModel(string $ticker, string $phrase, float $actual, float $quoted): void
    {
        $this->assertStringContainsString($phrase, self::text(CompanyResearch::ARTICLES[$ticker]));
        $this->assertEqualsWithDelta($quoted, $actual, 0.01, "$ticker: the profile says '$phrase'; the model disagrees.");
    }
}
