<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Data\AerieCouncil;
use App\Data\DistrictMap;
use App\Data\Institutions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The house style in .agents/FRONTEND.md, enforced on the player pages (templates/, admin excluded) and the scripts
 * that paint them (assets/js, assets/controllers). Each test names the rule it guards; a failure lists file:line.
 */
final class TemplateStyleTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** The district scene paints its buildings, sky and water in literal colours; that art is not page chrome. */
    private const SCENE_ART = ['templates/district/index.html.twig'];

    /** The game's own front door, outside the fiction: it may say the capital is simulated. */
    private const FRAME_PAGES = ['templates/home/landing.html.twig', 'templates/registration/register.html.twig', 'templates/security/login.html.twig'];

    /** The one script allowed to hold colour literals: it reads the theme tokens and hands them to the charts. */
    private const PALETTE_SCRIPT = 'assets/js/utils/colors.js';

    // --- Ratchets: lower these as pages move onto the shared filters, never raise them ---
    /** Percentages still formatted by hand, `(x * 100)|number_format(n)`, where |pct does not fit yet. */
    private const HAND_PERCENT_CEILING = 40;
    /** Dollar figures still formatted by hand, `${{ ... }}`, where |money does not fit yet. */
    private const HAND_MONEY_CEILING = 19;

    /** Utilities whose suffix is not a colour, by prefix. */
    private const NON_COLOUR_SUFFIXES = [
        'text' => ['xs', 'sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl', '6xl', '7xl', '8xl', '9xl', '2xs', '3xs', '4xs', 'left', 'center', 'right', 'justify', 'start', 'end', 'wrap', 'nowrap', 'balance', 'pretty', 'clip', 'ellipsis'],
        'bg' => ['none', 'fixed', 'local', 'scroll', 'repeat', 'no-repeat', 'cover', 'contain', 'auto', 'center', 'top', 'bottom', 'left', 'right', 'clip-border', 'clip-padding', 'clip-content', 'clip-text'],
        // CSS property names (border-radius) turn up in script strings beside the utilities.
        'border' => ['0', '2', '4', '8', 'solid', 'dashed', 'dotted', 'double', 'hidden', 'none', 'collapse', 'separate', 't', 'r', 'b', 'l', 'x', 'y', 's', 'e', 'radius', 'width', 'style', 'color'],
        'ring' => ['0', '1', '2', '4', '8', 'inset'],
        'outline' => ['none', 'dashed', 'dotted', 'double', 'hidden', '0', '1', '2', '4', '8'],
        'divide' => ['x', 'y', 'solid', 'dashed', 'dotted', 'double', 'none', 'x-reverse', 'y-reverse'],
        'decoration' => ['solid', 'double', 'dotted', 'dashed', 'wavy', 'auto', 'from-font', 'clone', 'slice', '0', '1', '2', '4', '8'],
        'fill' => ['none'],
        // SVG attribute names (stroke-width) turn up in script strings beside the utilities.
        'stroke' => ['none', '0', '1', '2', 'width', 'linecap', 'linejoin', 'dasharray', 'dashoffset', 'opacity'],
    ];

    /** Out-of-world names: real agencies, markets, comparators and models, and names the District has retired. */
    private const OUT_OF_WORLD = [
        'US bodies and markets' => '/\b(BLS|BEA|EIA|FRED|FOMC|FDIC|NYSE|Nasdaq|Federal Reserve|the Fed|Dow Jones|Wall Street|S&P|Treasury|Treasuries)\b/',
        'real-world comparators' => '/\b(Singapore|Norway|Norwegian|Hong Kong|Japan|Japanese|IMF|OECD|GPFG|GPIF|Temasek|Prompt Corrective Action)\b/',
        'model and paper names' => '/\b(Merton|Taylor rule|Cournot|Heston|Black-Scholes|Brock-Hommes|Vasicek|Fernald|Leontief|Diebold|Nelson-Siegel|Schularick|Dornbusch|Zakraj\w+|et al\.?|GSCPI)\b/',
        'retired names' => '/\b(Rate Council|Lakebird Exchange|Exchange Floor)\b/',
    ];

    /** Words that break the fiction on an in-world page; the frame pages may use them. */
    private const SIM_JARGON = '/\b(simulation|simulated|simulator|harness)\b/i';

    public function testPagesUseThePaletteNotLiteralColours(): void
    {
        $hits = [];
        foreach ($this->templates() as $path => $source) {
            if (!in_array($path, self::SCENE_ART, true)) {
                $hits = [...$hits, ...$this->grep($path, $source, '/(?<![&\w])#[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?\b/')];
            }
        }
        foreach ($this->scripts() as $path => $source) {
            if ($path !== self::PALETTE_SCRIPT) {
                $hits = [...$hits, ...$this->grep($path, $this->stripJsComments($source), '/(?<![&\w])#[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?\b|\brgba?\(/')];
            }
        }

        $this->assertSame([], $hits, 'Colours come from the theme tokens in app.css (THEME_COLORS / SERIES in charts), never literals.');
    }

    public function testPagesNeverUseTailwindsOwnPalette(): void
    {
        $pattern = '/(?<![\w-])(?:[a-z-]+:)*(?:text|bg|border|ring|fill|stroke|from|via|to|decoration|outline|divide|accent|caret|shadow)-(?:red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|slate|gray|zinc|neutral|stone)-\d{2,3}\b/';
        $hits = [];
        foreach ([...$this->templates(), ...$this->scripts()] as $path => $source) {
            $hits = [...$hits, ...$this->grep($path, $source, $pattern)];
        }

        $this->assertSame([], $hits, 'Use the theme tokens (text-secondary for up, text-tertiary for down, text-warning for a caution).');
    }

    /** A colour class with no token behind it renders uncoloured; `text-error` did so for months. */
    public function testEveryColourClassNamesAThemeToken(): void
    {
        $tokens = $this->colourTokens();
        $pattern = '/(?<![\w\/-])(?:[a-z0-9-]+:)*(text|bg|border(?:-[trblxyse])?|ring|outline|divide|decoration|fill|stroke|from|via|to|accent|caret|placeholder)-([a-z0-9][a-z0-9-]*)(?:\/\d+)?(?![\w\/-])/';
        $hits = [];
        foreach ([...$this->templates(), ...$this->scripts()] as $path => $source) {
            foreach ($this->classStrings($path, $source) as [$line, $classes]) {
                preg_match_all($pattern, $classes, $matches, PREG_SET_ORDER);
                foreach ($matches as [$utility, $prefix, $suffix]) {
                    $family = str_starts_with($prefix, 'border') ? 'border' : $prefix;
                    // A token's own name ('outline-variant') held as data in a script is not a utility.
                    if (in_array("$prefix-$suffix", $tokens, true)) {
                        continue;
                    }
                    if (in_array($suffix, $tokens, true) || in_array($suffix, self::NON_COLOUR_SUFFIXES[$family] ?? [], true)) {
                        continue;
                    }
                    if (in_array($family, ['ring', 'outline'], true) && str_starts_with($suffix, 'offset')) {
                        continue;
                    }
                    $hits[] = "$path:$line $utility";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($hits)), 'Every colour utility must name a --color-* token in assets/styles/app.css.');
    }

    /** Faded greys drop under 4.5:1 on the cards; the solid on-surface-faint token exists for the third tone. */
    public function testTextIsNeverFaded(): void
    {
        $hits = [];
        foreach ([...$this->templates(), ...$this->scripts()] as $path => $source) {
            foreach ($this->grep($path, $source, '/\btext-on-surface(?:-variant|-faint)?\/\d+/', withLine: true) as [$hit, $text]) {
                if (!str_contains($text, 'aria-hidden="true"')) {
                    $hits[] = $hit;
                }
            }
        }

        $this->assertSame([], $hits, 'Use text-on-surface-faint; only aria-hidden separators may fade.');
    }

    /** Capitals are for column headings, which get them from .table-head; everything else is sentence case. */
    public function testCapitalsOnlyInTableHeads(): void
    {
        $hits = [];
        foreach ([...$this->templates(), ...$this->scripts()] as $path => $source) {
            $hits = [...$hits, ...$this->grep($path, $source, '/(?<![\w-])(?:[a-z-]+:)*(?:uppercase|tracking-wide|tracking-wider|tracking-widest)(?![\w-])/')];
        }

        $this->assertSame([], $hits, 'Put column headings on .table-head; badges and labels are sentence case.');
    }

    /** One rule for up, down and flat, from one helper on each side: |signed_class in Twig, signedClass() in JS. */
    public function testSignedFiguresUseTheSharedColour(): void
    {
        $handWritten = "/[<>]=?\s*0\s*\?\s*'text-(?:secondary|tertiary)'\s*:\s*'text-(?:secondary|tertiary)'/";
        $flatWithSign = "/>=\s*0\s*\?\s*'\+'/";
        $hits = [];
        foreach ([...$this->templates(), ...$this->scripts()] as $path => $source) {
            $hits = [...$hits, ...$this->grep($path, $source, $handWritten), ...$this->grep($path, $source, $flatWithSign)];
        }

        $this->assertSame([], $hits, 'Colour a signed figure with |signed_class / signedClass(); an unchanged figure is neutral and carries no "+".');
    }

    /** @return iterable<string, array{string}> */
    public static function outOfWorldRules(): iterable
    {
        foreach (array_keys(self::OUT_OF_WORLD) as $rule) {
            yield $rule => [$rule];
        }
    }

    #[DataProvider('outOfWorldRules')]
    public function testPlayerCopyStaysInWorld(string $rule): void
    {
        $hits = [];
        foreach ($this->templates() as $path => $source) {
            $hits = [...$hits, ...$this->grep($path, $this->stripTwigComments($source), self::OUT_OF_WORLD[$rule])];
        }
        foreach ($this->scripts() as $path => $source) {
            $hits = [...$hits, ...$this->grep($path, $this->stripJsComments($source), self::OUT_OF_WORLD[$rule])];
        }

        $this->assertSame([], $hits, "Player pages are the District's own sites: no $rule (citations live in docblocks and commits).");
    }

    public function testInWorldPagesDoNotTalkAboutTheSimulation(): void
    {
        $hits = [];
        foreach ($this->templates() as $path => $source) {
            if (!in_array($path, self::FRAME_PAGES, true)) {
                $hits = [...$hits, ...$this->grep($path, $this->stripTwigComments($source), self::SIM_JARGON)];
            }
        }

        $this->assertSame([], $hits, 'Only the landing, sign-in and registration pages stand outside the fiction.');
    }

    public function testSourceLinesNameKnownPublishers(): void
    {
        $hits = [];
        foreach ($this->templates() as $path => $source) {
            $source = $this->stripTwigComments($source);
            $hits = [...$hits, ...$this->grep($path, $source, '/Source:/')];
            preg_match_all("/\bsource_line\(([^)]*)\)/", $source, $calls);
            foreach ($calls[1] as $args) {
                preg_match_all("/'([^']+)'/", $args, $ids);
                foreach (array_diff($ids[1], array_keys(Institutions::PUBLISHERS)) as $unknown) {
                    $hits[] = "$path source_line('$unknown')";
                }
            }
        }

        $this->assertSame([], $hits, "Write source lines as {{ source_line('statistical-office') }} with ids from Institutions::PUBLISHERS.");
    }

    /** The Council's department list and the map's buildings print the same names as every source line. */
    public function testInstitutionNamesComeFromTheGlossary(): void
    {
        $known = array_values(Institutions::PUBLISHERS);
        foreach (AerieCouncil::DEPARTMENTS as $department) {
            $this->assertContains($department['name'], $known, 'AerieCouncil::DEPARTMENTS must use Institutions names.');
        }
        foreach (DistrictMap::INSTITUTIONS as $id => $institution) {
            $this->assertContains(preg_replace('/^The /', '', $institution['label']), $known, "DistrictMap institution '$id' must use an Institutions name.");
        }
    }

    public function testHandFormattedFiguresOnlyShrink(): void
    {
        $percent = $money = 0;
        foreach ($this->templates() as $source) {
            $percent += preg_match_all('/\*\s*100\s*\)\|number_format/', $source);
            $money += preg_match_all('/\$\{\{/', $source);
        }

        $this->assertLessThanOrEqual(self::HAND_PERCENT_CEILING, $percent, 'New percentages use |pct(n); move old ones over and lower HAND_PERCENT_CEILING.');
        $this->assertLessThanOrEqual(self::HAND_MONEY_CEILING, $money, 'New dollar figures use |money(n); move old ones over and lower HAND_MONEY_CEILING.');
    }

    /** @return array<string, string> repo-relative path => source, player templates only */
    private function templates(): array
    {
        return $this->files('templates', '.twig', static fn(string $path): bool => !str_starts_with($path, 'templates/admin/'));
    }

    /** @return array<string, string> repo-relative path => source */
    private function scripts(): array
    {
        return [...$this->files('assets/js', '.js'), ...$this->files('assets/controllers', '.js')];
    }

    /** @return array<string, string> */
    private function files(string $dir, string $extension, ?\Closure $keep = null): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = $dir . substr($file->getPathname(), strlen(self::ROOT . '/' . $dir));
            if (str_ends_with($path, $extension) && ($keep === null || $keep($path))) {
                $files[$path] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @return ($withLine is true ? list<array{string, string}> : list<string>) "path:line match", with the line's text
     */
    private function grep(string $path, string $source, string $pattern, bool $withLine = false): array
    {
        $hits = [];
        foreach (explode("\n", $source) as $i => $text) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[0] as $match) {
                    $hit = sprintf('%s:%d %s', $path, $i + 1, $match);
                    $hits[] = $withLine ? [$hit, $text] : $hit;
                }
            }
        }

        return $hits;
    }

    /**
     * The places a class list is written: class="..." attributes in templates (Twig expressions included), and string
     * literals in scripts.
     *
     * @return list<array{int, string}> line number and class text
     */
    private function classStrings(string $path, string $source): array
    {
        $pattern = str_ends_with($path, '.twig') ? '/class="([^"]*)"/' : "/'([^'\\n]*)'|\"([^\"\\n]*)\"|`([^`]*)`/";
        $source = str_ends_with($path, '.twig') ? $this->stripTwigComments($source) : $this->stripJsComments($source);
        $found = [];
        foreach (explode("\n", $source) as $i => $text) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[0] as $k => $_) {
                    $found[] = [$i + 1, implode(' ', array_filter([$matches[1][$k] ?? '', $matches[2][$k] ?? '', $matches[3][$k] ?? '']))];
                }
            }
        }

        return $found;
    }

    /** @return list<string> every --color-* token the theme defines, plus CSS keywords */
    private function colourTokens(): array
    {
        preg_match_all('/--color-([a-z0-9-]+):/', (string) file_get_contents(self::ROOT . '/assets/styles/app.css'), $matches);

        return [...$matches[1], 'white', 'black', 'transparent', 'current', 'inherit'];
    }

    /** Blanks comments but keeps their newlines, so line numbers still point at the source. */
    private function stripTwigComments(string $source): string
    {
        return (string) preg_replace_callback('/\{#.*?#\}|<!--.*?-->/s', static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")), $source);
    }

    private function stripJsComments(string $source): string
    {
        return (string) preg_replace_callback('#/\*.*?\*/|(?<![:\'"\\\\])//[^\n]*#s', static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")), $source);
    }
}
