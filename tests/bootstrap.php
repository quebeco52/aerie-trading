<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Bypass missing php-redis extension in CLI for tests
if (!class_exists('Redis')) {
    class Redis {
        // Mirrors the real extension's pipeline mode flag; services select it by name.
        public const PIPELINE = 2;
        public const MULTI = 1;

        public function connect($host, $port = 6379, $timeout = 0.0, $reserved = null, $retry_interval = 0, $read_timeout = 0.0) {}
        public function get($key) {}
        public function mGet(array $keys) { return array_fill(0, count($keys), false); }
        // Declared with phpredis' real signature: a test that stubs Redis with the extension's own types
        // is only compatible with a parent that has them, and a narrower parameter type here is a fatal.
        public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { return true; }
        public function hGet($key, $field) { return false; }
        public function hSet($key, $field, $value) { return 1; }
        public function hGetAll($key) { return []; }
        public function setex($key, $ttl, $val) {}
        public function del($key) {}
        public function lPush($key, $value) {}
        public function lTrim($key, $start, $stop) {}
        public function lRange($key, $start, $end) { return []; }
        public function lIndex($key, $index) {}
        public function multi($mode = self::MULTI) { return $this; }
        public function exec() { return []; }
        public function publish($channel, $message) { return 0; }
    }
}

// Polyfill the missing bcmath extension in CLI for tests.
//
// Exact decimal string arithmetic, not float arithmetic. The previous polyfill routed every operation
// through `(float)` and `number_format`, which diverges from real bcmath in two ways that matter here:
// doubles carry about 16 significant digits while this schema stores share counts up to 9.2e18 and money
// at DECIMAL(20,4), and number_format ROUNDS where bcmath TRUNCATES. Tests calibrated against that
// polyfill were therefore not testing the arithmetic that runs in production, where the extension is
// present. These implementations work digit by digit, so they agree with bcmath at any magnitude.
if (!function_exists('bcadd')) {
    /**
     * Splits a decimal string into [sign, digit string, decimal places].
     *
     * @return array{0: int, 1: string, 2: int}
     */
    function __bcpoly_parse(string $num): array {
        $num = trim($num);

        // Reject what real bcmath rejects. Stripping the offending characters instead - which is what this
        // did - meant the polyfill answered where the extension throws, and answered WRONGLY: the sole
        // caller of `(string) $someFloat` reaching bcmath hands it "1.0E-5" for 0.00001, which parsed to
        // 1.05 here and a ValueError in production. A test suite that cannot see that divergence is not
        // testing the arithmetic that runs.
        if (!preg_match('/^[+-]?\d*(\.\d*)?$/', $num) || !preg_match('/\d/', $num)) {
            throw new \ValueError('is not well-formed');
        }

        $sign = 1;
        if (str_starts_with($num, '-')) {
            $sign = -1;
            $num = substr($num, 1);
        } elseif (str_starts_with($num, '+')) {
            $num = substr($num, 1);
        }

        $parts = explode('.', $num, 2);
        $integer  = $parts[0];
        $fraction = $parts[1] ?? '';
        $digits = $integer . $fraction;

        return [$sign, $digits === '' ? '0' : $digits, strlen($fraction)];
    }

    /** Compares two unsigned digit strings. */
    function __bcpoly_cmp_digits(string $a, string $b): int {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';

        return strlen($a) !== strlen($b) ? (strlen($a) <=> strlen($b)) : (strcmp($a, $b) <=> 0);
    }

    /** Adds two unsigned digit strings. */
    function __bcpoly_add_digits(string $a, string $b): string {
        $result = '';
        $carry = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $carry > 0; $i--, $j--) {
            $sum = $carry + ($i >= 0 ? (int) $a[$i] : 0) + ($j >= 0 ? (int) $b[$j] : 0);
            $result = ((string) ($sum % 10)) . $result;
            $carry = intdiv($sum, 10);
        }

        return $result === '' ? '0' : $result;
    }

    /** Subtracts unsigned digit string $b from $a, which must be the larger. */
    function __bcpoly_sub_digits(string $a, string $b): string {
        $result = '';
        $borrow = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0; $i--, $j--) {
            $digit = (int) $a[$i] - $borrow - ($j >= 0 ? (int) $b[$j] : 0);
            $borrow = $digit < 0 ? 1 : 0;
            $result = ((string) ($digit + ($borrow * 10))) . $result;
        }

        return ltrim($result, '0') ?: '0';
    }

    /** Multiplies two unsigned digit strings (schoolbook). */
    function __bcpoly_mul_digits(string $a, string $b): string {
        $lenA = strlen($a);
        $lenB = strlen($b);
        $columns = array_fill(0, $lenA + $lenB, 0);

        for ($i = $lenA - 1; $i >= 0; $i--) {
            for ($j = $lenB - 1; $j >= 0; $j--) {
                $columns[$i + $j + 1] += (int) $a[$i] * (int) $b[$j];
            }
        }
        for ($k = $lenA + $lenB - 1; $k > 0; $k--) {
            $columns[$k - 1] += intdiv($columns[$k], 10);
            $columns[$k] %= 10;
        }

        return ltrim(implode('', $columns), '0') ?: '0';
    }

    /** Integer quotient of two unsigned digit strings (long division). */
    function __bcpoly_div_digits(string $a, string $b): string {
        if (__bcpoly_cmp_digits($b, '0') === 0) {
            throw new \DivisionByZeroError('Division by zero');
        }

        $quotient = '';
        $remainder = '0';
        for ($i = 0, $len = strlen($a); $i < $len; $i++) {
            $remainder = ltrim($remainder . $a[$i], '0') ?: '0';
            $digit = 0;
            while (__bcpoly_cmp_digits($remainder, $b) >= 0) {
                $remainder = __bcpoly_sub_digits($remainder, $b);
                $digit++;
            }
            $quotient .= (string) $digit;
        }

        return ltrim($quotient, '0') ?: '0';
    }

    /**
     * Renders a parsed value at the requested scale, TRUNCATING toward zero exactly as bcmath does.
     *
     * @param array{0: int, 1: string, 2: int} $value
     */
    function __bcpoly_format(array $value, int $scale): string {
        [$sign, $digits, $places] = $value;
        $scale = max(0, $scale);

        if ($places > $scale) {
            $cut = $places - $scale;
            $digits = strlen($digits) > $cut ? substr($digits, 0, strlen($digits) - $cut) : '0';
        } elseif ($places < $scale) {
            $digits .= str_repeat('0', $scale - $places);
        }

        $digits = ltrim($digits, '0') ?: '0';
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);

        $rendered = $scale > 0
            ? substr($digits, 0, strlen($digits) - $scale) . '.' . substr($digits, -$scale)
            : $digits;

        return ($sign < 0 && trim($rendered, '0.') !== '' ? '-' : '') . $rendered;
    }

    /**
     * Signed addition of two parsed values.
     *
     * @param array{0: int, 1: string, 2: int} $x
     * @param array{0: int, 1: string, 2: int} $y
     * @return array{0: int, 1: string, 2: int}
     */
    function __bcpoly_add_values(array $x, array $y): array {
        $places = max($x[2], $y[2]);
        $dx = $x[1] . str_repeat('0', $places - $x[2]);
        $dy = $y[1] . str_repeat('0', $places - $y[2]);

        if ($x[0] === $y[0]) {
            return [$x[0], __bcpoly_add_digits($dx, $dy), $places];
        }

        $comparison = __bcpoly_cmp_digits($dx, $dy);
        if ($comparison === 0) {
            return [1, '0', $places];
        }

        return $comparison > 0
            ? [$x[0], __bcpoly_sub_digits($dx, $dy), $places]
            : [$y[0], __bcpoly_sub_digits($dy, $dx), $places];
    }

    function bcadd(string $num1, string $num2, int $scale = 0): string {
        return __bcpoly_format(__bcpoly_add_values(__bcpoly_parse($num1), __bcpoly_parse($num2)), $scale);
    }

    function bcsub(string $num1, string $num2, int $scale = 0): string {
        $y = __bcpoly_parse($num2);
        $y[0] = -$y[0];

        return __bcpoly_format(__bcpoly_add_values(__bcpoly_parse($num1), $y), $scale);
    }

    function bcmul(string $num1, string $num2, int $scale = 0): string {
        [$sx, $dx, $cx] = __bcpoly_parse($num1);
        [$sy, $dy, $cy] = __bcpoly_parse($num2);

        return __bcpoly_format([$sx * $sy, __bcpoly_mul_digits($dx, $dy), $cx + $cy], $scale);
    }

    function bcdiv(string $num1, string $num2, int $scale = 0): string {
        [$sx, $dx, $cx] = __bcpoly_parse($num1);
        [$sy, $dy, $cy] = __bcpoly_parse($num2);

        if (__bcpoly_cmp_digits($dy, '0') === 0) {
            throw new \DivisionByZeroError('Division by zero in bcdiv');
        }

        // (dx / 10^cx) / (dy / 10^cy) at `scale` decimals, truncated: scale the numerator by
        // 10^(cy + scale) and the denominator by 10^cx, then take the integer quotient.
        $scale = max(0, $scale);
        $numerator = $dx . str_repeat('0', $cy + $scale);
        $denominator = $dy . str_repeat('0', $cx);

        return __bcpoly_format([$sx * $sy, __bcpoly_div_digits($numerator, $denominator), $scale], $scale);
    }

    function bccomp(string $num1, string $num2, int $scale = 0): int {
        $x = __bcpoly_parse(bcadd($num1, '0', $scale));
        $y = __bcpoly_parse(bcadd($num2, '0', $scale));

        $isZeroX = __bcpoly_cmp_digits($x[1], '0') === 0;
        $isZeroY = __bcpoly_cmp_digits($y[1], '0') === 0;
        if ($isZeroX && $isZeroY) {
            return 0;
        }
        if ($x[0] !== $y[0]) {
            return $x[0] <=> $y[0];
        }

        return __bcpoly_cmp_digits($x[1], $y[1]) * $x[0];
    }
}

