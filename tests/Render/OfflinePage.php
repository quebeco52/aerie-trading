<?php

declare(strict_types=1);

namespace App\Tests\Render;

/**
 * Turns a rendered page into a file a headless browser can open with no server and no network: the built stylesheet
 * inlined, scripts and web fonts dropped (Firefox hangs on a blocked font request). See .agents/FRONTEND.md.
 */
final class OfflinePage
{
    private const STYLESHEET = __DIR__ . '/../../var/tailwind/app.built.css';

    /** Where pages are written: $OUT, or var/render. */
    public static function outDir(): string
    {
        $out = getenv('OUT') ?: __DIR__ . '/../../var/render';
        if (!is_dir($out)) {
            mkdir($out, 0777, true);
        }

        return $out;
    }

    public static function write(string $name, string $html): string
    {
        if (!is_file(self::STYLESHEET)) {
            throw new \RuntimeException('Build the stylesheet first: php bin/console tailwind:build --env=test');
        }
        $html = (string) preg_replace('#<link rel="stylesheet" href="[^"]*app[^"]*\.css">#', '<style>' . file_get_contents(self::STYLESHEET) . '</style>', $html);
        $html = (string) preg_replace('#<script type="importmap".*?</script>|<script type="module".*?</script>|<link rel="modulepreload"[^>]*>#s', '', $html);
        $html = (string) preg_replace('#<link href="https://fonts[^>]*>|@import url\("https://fonts[^)]*\)[^;]*;#', '', $html);
        $html = (string) preg_replace('#<script[^>]*src="https?://[^"]*"[^>]*></script>#', '', $html);

        $path = self::outDir() . "/{$name}.html";
        file_put_contents($path, $html);

        return $path;
    }
}
