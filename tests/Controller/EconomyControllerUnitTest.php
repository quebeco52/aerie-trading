<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\EconomyController;
use PHPUnit\Framework\TestCase;

/**
 * The economy page reads its charts' fixed parameters from one list the controller hands over, so a key the template
 * names must be on the list (the render stubs run without strict variables and would print a blank instead).
 */
final class EconomyControllerUnitTest extends TestCase
{
    public function testEveryChartConstantTheTemplateReadsIsHandedOverAndRead(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../templates/economy/index.html.twig');
        preg_match_all('/\bchartConstants\.(\w+)/', $source, $reads);
        $keys = array_keys(EconomyController::chartConstants());

        $this->assertNotEmpty($reads[1]);
        $this->assertSame([], array_values(array_diff(array_unique($reads[1]), $keys)), 'The template reads a key the controller does not hand over.');
        $this->assertSame([], array_values(array_diff($keys, $reads[1])), 'The controller hands over a key the template never reads.');
    }
}
