<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use PHPUnit\Framework\TestCase;

/**
 * Guards the invariant that lets the desk stop marking five thousand contracts a sweep.
 *
 * THE INVARIANT: option_contracts.price is maintained ONLY for contracts somebody holds. A contract nobody
 * has a position in carries whatever mark was last written to it, which may be arbitrarily stale, and
 * nothing may read it.
 *
 * That is safe today because every SQL reader of the column reaches the row by joining THROUGH user_options,
 * so each of them is asking only about held contracts by construction — and the one surface that looks at
 * the rest of the chain, the option chain panel, prices it on read instead.
 *
 * It is also exactly the kind of invariant that decays silently. A new query that reads the column without
 * that join returns a number rather than an error, and the number looks plausible. So it is checked here
 * against the source itself rather than against behaviour, because behaviour cannot see the query that has
 * not been written yet.
 */
class OptionMarkInvariantTest extends TestCase
{
    /**
     * Files exempt from the join rule, each for a stated reason. Anything not listed here that touches the
     * table is a reader and must arrive through a holding.
     */
    private const EXEMPT = [
        // Declares the table and writes the mark; does not query it.
        'src/Entity/OptionContract.php',
        // Produce the mark rather than consuming a stored one.
        'src/Service/Market/OptionPricingEngine.php',
        'src/Service/Market/OptionSettlementEngine.php',
        // Owns the held-only marking gate this whole invariant rests on.
        'src/Service/Market/OptionDeskService.php',
        // Lists and relists contracts; reads symbols, never marks.
        'src/Service/Market/OptionChainService.php',
        // Truncates the table on a reset.
        'src/Command/MarketResetCommand.php',
    ];

    /**
     * @return array<int, string>
     */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src'));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function testEverySqlReaderOfTheStoredMarkJoinsThroughAHolding(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach ($this->sourceFiles() as $path) {
            $relative = str_replace($root . '/', '', $path);

            if (in_array($relative, self::EXEMPT, true)) {
                continue;
            }

            $source = file_get_contents($path);

            if ($source === false || !str_contains($source, 'option_contracts')) {
                continue;
            }

            // Every statement that names the table must also name the holdings table it has to arrive
            // through. Checked per file, which is coarse but cannot be argued with: a file that touches
            // option_contracts without ever mentioning user_options is reading marks for contracts that
            // may not be held.
            if (!str_contains($source, 'user_options')) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These read option_contracts without joining user_options, so they may be reading a stale mark "
            . "for a contract nobody holds. Either join through the holding, or price on read as "
            . 'OptionChainBuilder does.'
        );
    }

    public function testTheChainPanelPricesOnReadRatherThanReadingStoredColumns(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Service/View/OptionChainBuilder.php');

        $this->assertIsString($source);

        // It must ask the engine for a quote...
        $this->assertStringContainsString('quoteChain', $source);

        // ...and must not read the stored mark or greeks off the entity.
        foreach (['getPrice()', 'getImpliedVolatility()', 'getDelta()', 'getGamma()', 'getVega()', 'getTheta()'] as $accessor) {
            $this->assertStringNotContainsString(
                '$contract->' . $accessor,
                $source,
                "The chain panel reads {$accessor} off the contract. Those columns are only maintained for "
                . 'contracts somebody holds, so an unheld row would render a stale mark.'
            );
        }
    }

    public function testTheSweepMarksOnlyHeldContracts(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Service/Market/OptionDeskService.php');

        $this->assertIsString($source);

        // The gate itself. Without it the sweep is back to rewriting the whole chain every pass, which is
        // what made the ticker crawl.
        $this->assertStringContainsString('heldContractIds', $source);
        $this->assertStringContainsString('user_options', $source);
        $this->assertStringContainsString('isset($held[$contract->getId()])', $source);
    }

    public function testTheTradePathMarksAContractAsItBecomesHeld(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Service/Market/OptionTradeService.php');

        $this->assertIsString($source);

        // A contract that has just been bought is held, and net worth reads its mark through SQL before the
        // sweep will next reach its slice.
        $this->assertStringContainsString('applyMark', $source);
    }
}
