<?php

namespace {
    if (!function_exists('bcadd')) {
        function bcadd(string $num1, string $num2, int $scale = 0): string {
            return number_format((float) $num1 + (float) $num2, $scale, '.', '');
        }
    }
    if (!function_exists('bcsub')) {
        function bcsub(string $num1, string $num2, int $scale = 0): string {
            return number_format((float) $num1 - (float) $num2, $scale, '.', '');
        }
    }
    if (!function_exists('bcmul')) {
        function bcmul(string $num1, string $num2, int $scale = 0): string {
            return number_format((float) $num1 * (float) $num2, $scale, '.', '');
        }
    }
    if (!function_exists('bcdiv')) {
        function bcdiv(string $num1, string $num2, int $scale = 0): string {
            $denom = (float) $num2;
            if ($denom === 0.0) {
                throw new \DivisionByZeroError('Division by zero in bcdiv');
            }
            return number_format((float) $num1 / $denom, $scale, '.', '');
        }
    }
    if (!function_exists('bccomp')) {
        function bccomp(string $num1, string $num2, int $scale = 0): int {
            $f1 = round((float) $num1, $scale);
            $f2 = round((float) $num2, $scale);
            return $f1 <=> $f2;
        }
    }
}

namespace App {
    use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
    use Symfony\Component\HttpKernel\Kernel as BaseKernel;

    class Kernel extends BaseKernel
    {
        use MicroKernelTrait;
    }
}

