<?php

declare(strict_types=1);

namespace App\Tests\Service\News;

use App\Tests\Twig\TemplateStyleTest;
use PHPUnit\Framework\TestCase;

/**
 * The news is the District's own wire and the copy players read most, so the house rules the pages follow
 * (.agents/FRONTEND.md) run over every phrase the narrative engine and the systemic reporter can write. The phrases are
 * read straight from their source, every literal and every literal piece of an interpolated string, so a new phrase is
 * checked the moment it is written.
 */
class WireCopyStyleTest extends TestCase
{
    private const SOURCES = ['src/Service/News/NarrativeEngine.php', 'src/Service/News/SystemicEventReporter.php'];

    private const RULES = [
        'mainland bodies and programmes' => '/\b(?:Medicare|Medicaid|[Cc]ongress(?:ional)?|[Ff]ederal|FICC|ASC \d+|GAAP)\b/',
        'American spelling' => '/\b(?!\w*(?:size|seize|prize)\w*\b)\w+(?:iz|yz)(?:e|es|ed|ing|ation|ations|er|ers)\b|\b(?:labor\w*|defense|offense|favor\w*|color\w*|behavior\w*|neighbor\w*|center|centers|centered|theater|fiber|program|programs|license|licenses|fulfill\w*|enroll\w*|traveled|canceled|modeling|gray|aluminum|jewelry)\b/',
        'institution names' => '/\b(?:[Cc]entral bank|[Mm]onetary authority|statistics office|[Ss]tatistical office)\b/',
        'the place is "the District"' => '/\bthe district\b/',
    ];

    public function testTheWireFollowsTheHouseRules(): void
    {
        $hits = [];
        foreach (self::SOURCES as $source) {
            foreach (self::phrases($source) as [$line, $text]) {
                foreach ([...TemplateStyleTest::OUT_OF_WORLD, ...self::RULES] as $rule => $pattern) {
                    if (preg_match($pattern, $text, $match) === 1) {
                        $hits[] = "{$source}:{$line} {$rule}: \"{$match[0]}\"";
                    }
                }
            }
        }

        $this->assertSame([], $hits);
    }

    /** The guard sees the copy: a phrase the engine writes is among the literals it reads. */
    public function testTheCopyIsReadFromTheSource(): void
    {
        $text = implode("\n", array_column(self::phrases(self::SOURCES[0]), 1));

        $this->assertStringContainsString('Bond purchases begin: With its rate at the floor, the ', $text);
        $this->assertStringContainsString(' declares a recession after output fell ', $text);
    }

    /**
     * Every string literal in the file but an array key, and every literal piece of an interpolated one, with its line.
     *
     * @return list<array{int, string}>
     */
    private static function phrases(string $source): array
    {
        $phrases = [];
        foreach (token_get_all((string) file_get_contents(dirname(__DIR__, 3) . '/' . $source)) as $token) {
            // An array key ('labor_growth_pct') is a name, not copy.
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && preg_match('/^\'[a-z][a-z0-9_]*\'$/', $token[1]) !== 1) {
                $phrases[] = [$token[2], $token[1]];
            }
        }

        return $phrases;
    }
}
