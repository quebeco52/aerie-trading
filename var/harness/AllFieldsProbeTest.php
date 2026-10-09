<?php
declare(strict_types=1);
use App\Service\Macro\MacroEngine;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
/** Every numeric macro field, quarter-end. Env: SEEDS YEARS TPY OUT */
final class AllFieldsProbeTest extends KernelTestCase
{
    protected static function getKernelClass(): string { return \HarnessKernel::class; }
    public function testRun(): void
    {
        $years = (int) getenv('YEARS'); $tpy = (int) getenv('TPY'); $dt = 1.0 / $tpy; $rows = [];
        foreach (explode(',', (string) getenv('SEEDS')) as $seed) {
            mt_srand((int) $seed);
            $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
            self::bootKernel(['environment' => 'test', 'debug' => false]);
            $macro = static::getContainer()->get(MacroEngine::class);
            for ($tick = 1; $tick <= $years * $tpy; $tick++) {
                $m = $macro->updateMacroState($dt, null);
                if ($tick % intdiv($tpy, 4) === 0 && $tick > 10 * $tpy) {
                    $row = ['seed' => (int) $seed];
                    foreach (get_object_vars($m) as $k => $v) { if (is_float($v) || is_int($v)) { $row[$k] = $v; } }
                    $rows[] = $row;
                }
            }
            self::ensureKernelShutdown();
        }
        file_put_contents((string) getenv('OUT'), json_encode($rows));
        $this->assertNotEmpty($rows);
    }
}
