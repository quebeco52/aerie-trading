<?php

declare(strict_types=1);

// Rewraps PHP comments at 120 characters, the line the codebase writes to.
//
//   php bin/reflow-comments.php <file>...           rewrap in place
//   php bin/reflow-comments.php --check <file>...   list the files a rewrap would change; exit 1 if any
//
// Only comments move: prose is refilled, a tag keeps its type and variable on its first line and wraps its
// description under them, a list item hangs under its marker, and a trailing comment that runs past the line
// moves above its code. Hand-laid-out lines (tables, indented blocks) and a tag whose type alone is too long
// stay as they are, since breaking a type breaks the tag.

const REFLOW_WIDTH = 120;

$check = false;
$files = [];
$arguments = $_SERVER['argv'] ?? [];
foreach (array_slice(is_array($arguments) ? $arguments : [], 1) as $arg) {
    if (!is_string($arg)) {
        continue;
    }
    if ($arg === '--check') {
        $check = true;
    } else {
        $files[] = $arg;
    }
}

$changed = [];
foreach ($files as $file) {
    $src = file_get_contents($file);
    if ($src === false) {
        fwrite(STDERR, "Cannot read {$file}\n");
        exit(2);
    }
    $out = reflowComments($src);
    if ($out === $src) {
        continue;
    }
    $changed[] = $file;
    if (!$check) {
        file_put_contents($file, $out);
    }
}

if ($check) {
    foreach ($changed as $file) {
        echo "   {$file}\n";
    }
    exit($changed === [] ? 0 : 1);
}

/** The source with every comment that runs past the width rewrapped; code, strings and short comments untouched. */
function reflowComments(string $src): string
{
    $tokens = token_get_all($src);
    $lines = explode("\n", $src);
    /** @var list<array{int, int, list<string>}> $edits Physical lines [from, to] and what replaces them. */
    $edits = [];

    // Consecutive own-line // comments at one indentation are refilled as one block.
    /** @var array<int, string> $run line index => indentation */
    $run = [];
    $flushRun = static function () use (&$run, &$edits, $lines): void {
        $indexes = array_keys($run);
        if ($indexes === [] || max(array_map(static fn(int $i): int => mb_strlen($lines[$i]), $indexes)) <= REFLOW_WIDTH) {
            $run = [];

            return;
        }
        $body = array_map(static fn(int $i): string => stripPrefix('#^\s*//\s?#', $lines[$i]), $indexes);
        $edits[] = [$indexes[0], $indexes[count($indexes) - 1], wrapBody($body, $run[$indexes[0]] . '// ')];
        $run = [];
    };

    $previousRunLine = null;
    foreach ($tokens as $token) {
        if (!is_array($token)) {
            continue;
        }
        [$id, $text, $startLine] = $token;
        $i = $startLine - 1;
        $indent = substr($lines[$i], 0, strlen($lines[$i]) - strlen(ltrim($lines[$i])));
        $ownLine = str_starts_with(ltrim($lines[$i]), ltrim(explode("\n", $text)[0]));

        if ($id === T_COMMENT && str_starts_with($text, '//')) {
            if (!$ownLine) {
                $flushRun();
                if (mb_strlen($lines[$i]) > REFLOW_WIDTH && str_ends_with($lines[$i], $text) && lineStartsWithToken($tokens, $startLine)) {
                    $code = rtrim(substr($lines[$i], 0, strlen($lines[$i]) - strlen($text)));
                    $edits[] = [$i, $i, [...wrapBody([stripPrefix('#^//\s?#', $text)], $indent . '// '), $code]];
                }

                continue;
            }
            if (($previousRunLine !== null && $i !== $previousRunLine + 1) || ($run !== [] && $run[array_key_first($run)] !== $indent)) {
                $flushRun();
            }
            $run[$i] = $indent;
            $previousRunLine = $i;

            continue;
        }
        if ($id !== T_WHITESPACE) {
            $flushRun();
            $previousRunLine = null;
        }

        $isBlock = $id === T_DOC_COMMENT || ($id === T_COMMENT && str_starts_with($text, '/*'));
        if (!$isBlock || !$ownLine) {
            continue;
        }
        $to = $i + substr_count($text, "\n");
        $closing = rtrim($lines[$to]);
        $tooLong = false;
        for ($k = $i; $k <= $to; $k++) {
            $tooLong = $tooLong || mb_strlen($lines[$k]) > REFLOW_WIDTH;
        }
        // Code after the closing */ keeps the comment where it is.
        if (!$tooLong || !str_ends_with($closing, '*/')) {
            continue;
        }
        $opener = str_starts_with($text, '/**') ? '/**' : '/*';
        $inner = substr($text, strlen($opener), -2);
        $body = [];
        foreach (explode("\n", $inner) as $k => $bodyLine) {
            $bodyLine = stripPrefix('#^\s*\* ?#', $bodyLine);
            $body[] = rtrim($k === 0 ? ltrim($bodyLine) : $bodyLine);
        }
        while ($body !== [] && trim($body[0]) === '') {
            array_shift($body);
        }
        while ($body !== [] && trim($body[count($body) - 1]) === '') {
            array_pop($body);
        }
        $edits[] = [$i, $to, [$indent . $opener, ...wrapBody($body, $indent . ' * '), $indent . ' */']];
    }
    $flushRun();

    usort($edits, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
    foreach ($edits as [$from, $to, $new]) {
        array_splice($lines, $from, $to - $from + 1, $new);
    }

    return implode("\n", $lines);
}

/**
 * Rewraps comment body lines under a line prefix. A paragraph of prose holding a long line is joined and refilled;
 * anything laid out by hand is kept.
 *
 * @param list<string> $body
 * @return list<string>
 */
function wrapBody(array $body, string $prefix): array
{
    $width = REFLOW_WIDTH - mb_strlen($prefix);
    $out = [];
    $paragraph = [];

    foreach ($body as $line) {
        $trim = trim($line);
        $isTag = str_starts_with($trim, '@');
        $isList = preg_match('/^\s*(?:[-•]|\d+[.)]|\([a-z0-9]+\))\s+/u', $line, $marker) === 1;
        $isLayout = $trim === '' || str_starts_with($line, '  ') || str_starts_with($trim, '|') || str_starts_with($trim, '```')
            || preg_match('/\s{3,}\S/', $trim) === 1;
        if (!$isTag && !$isList && !$isLayout) {
            $paragraph[] = $line;

            continue;
        }
        $out = [...$out, ...paragraphLines($paragraph, $prefix, $width)];
        $paragraph = [];

        if (mb_strlen($line) <= $width) {
            $out[] = rtrim($prefix . $line);

            continue;
        }
        if ($isTag) {
            $split = tagHead($trim);
            if ($split === null || mb_strlen($split[0]) > 70) {
                $out[] = rtrim($prefix . $line);

                continue;
            }
            [$head, $description] = $split;
            $wrapped = fill($description, $width - mb_strlen($head));
            $out[] = rtrim($prefix . $head . array_shift($wrapped));
            $hang = str_repeat(' ', mb_strlen($head));
            foreach (fill(implode(' ', $wrapped), $width - mb_strlen($head)) as $rest) {
                $out[] = rtrim($prefix . $hang . $rest);
            }

            continue;
        }
        if ($isList) {
            $wrapped = fill(substr($line, strlen($marker[0])), $width - mb_strlen($marker[0]));
            $out[] = rtrim($prefix . $marker[0] . array_shift($wrapped));
            $hang = str_repeat(' ', mb_strlen($marker[0]));
            foreach ($wrapped as $rest) {
                $out[] = rtrim($prefix . $hang . $rest);
            }

            continue;
        }
        $out[] = rtrim($prefix . $line);
    }

    return [...$out, ...paragraphLines($paragraph, $prefix, $width)];
}

/**
 * A paragraph of prose under the prefix: as written when every line fits, refilled when one does not.
 *
 * @param list<string> $paragraph
 * @return list<string>
 */
function paragraphLines(array $paragraph, string $prefix, int $width): array
{
    if ($paragraph === []) {
        return [];
    }
    $lines = max(array_map('mb_strlen', $paragraph)) > $width ? fill(implode(' ', array_map('trim', $paragraph)), $width) : $paragraph;

    return array_map(static fn(string $line): string => rtrim($prefix . $line), $lines);
}

/**
 * Greedy fill of words into lines of at most the width; a word longer than the width gets a line of its own.
 *
 * @return list<string>
 */
function fill(string $text, int $width): array
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($text === '') {
        return [];
    }
    // An @ inside prose must never start a line, where a docblock parser would read it as a tag.
    $words = preg_split('/ (?!@)/u', $text) ?: [$text];
    $lines = [];
    $current = '';
    foreach ($words as $word) {
        if ($current === '') {
            $current = $word;
        } elseif (mb_strlen($current) + 1 + mb_strlen($word) <= $width) {
            $current .= ' ' . $word;
        } else {
            $lines[] = $current;
            $current = $word;
        }
    }
    $lines[] = $current;

    return $lines;
}

/**
 * Splits a tag line into its head (tag, a type with its brackets balanced, a variable, and the padding after them)
 * and its description; null when there is no description.
 *
 * @return array{string, string}|null
 */
function tagHead(string $line): ?array
{
    if (preg_match('/^@\S+\s+/u', $line, $tag) !== 1) {
        return null;
    }
    $pos = strlen($tag[0]);
    if (in_array(trim($tag[0]), ['@param', '@param-out', '@return', '@var', '@property', '@property-read', '@throws'], true)) {
        $depth = 0;
        for ($length = strlen($line); $pos < $length; $pos++) {
            $c = $line[$pos];
            if (str_contains('<{([', $c)) {
                $depth++;
            } elseif (str_contains('>})]', $c)) {
                $depth--;
            } elseif ($depth === 0 && ctype_space($c) && !str_ends_with(rtrim(substr($line, 0, $pos)), ':')) {
                // A colon keeps a callable's return type in the type: `callable(float): string`.
                break;
            }
        }
        if (preg_match('/\G\s+(?:\.\.\.|&)?\$\S+/u', $line, $variable, 0, $pos) === 1) {
            $pos += strlen($variable[0]);
        }
    }
    if (preg_match('/\G\s+/u', $line, $space, 0, $pos) !== 1) {
        return null;
    }
    $pos += strlen($space[0]);
    $description = substr($line, $pos);

    return $description === '' ? null : [substr($line, 0, $pos), $description];
}

/** Whether a token begins at the line's first non-blank character, so no string or heredoc runs into the line. */
function lineStartsWithToken(array $tokens, int $line): bool
{
    $current = 1;
    foreach ($tokens as $token) {
        $text = is_array($token) ? $token[1] : $token;
        $start = is_array($token) ? $token[2] : $current;
        $isWhitespace = is_array($token) && $token[0] === T_WHITESPACE;
        if ($start === $line && !$isWhitespace) {
            return true;
        }
        $current = $start + substr_count($text, "\n");
        if ($start < $line && $current >= $line && !$isWhitespace) {
            return false;
        }
    }

    return false;
}

function stripPrefix(string $pattern, string $line): string
{
    return preg_replace($pattern, '', $line, 1) ?? $line;
}
