<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Corporate life-cycle stage classified from the signs of the three cash-flow statement sections,
 * after Dickinson (2011), "Cash Flow Patterns as a Proxy for Firm Life Cycle", The Accounting Review.
 *
 * Operating (CFO), investing (CFI) and financing (CFF) cash flows map onto the eight sign combinations:
 *   Introduction  CFO -  CFI -  CFF +    burning cash, investing, funded externally
 *   Growth        CFO +  CFI -  CFF +    profitable, investing, still raising capital
 *   Mature        CFO +  CFI -  CFF -    profitable, investing, returning capital
 *   Shake-out     the mixed patterns (- - -), (+ + +), (+ + -)
 *   Decline       CFO -  CFI +  CFF +/-  burning cash while liquidating assets
 */
enum LifecycleStage: string
{
    case Introduction = 'introduction';
    case Growth = 'growth';
    case Mature = 'mature';
    case ShakeOut = 'shake_out';
    case Decline = 'decline';

    /**
     * @param bool $operatingPositive Net cash from operations is positive.
     * @param bool $investingPositive Net cash from investing is positive (net asset sales, not net investment).
     * @param bool $financingPositive Net cash from financing is positive (net capital raised, not returned).
     */
    public static function fromCashFlowSigns(bool $operatingPositive, bool $investingPositive, bool $financingPositive): self
    {
        if ($investingPositive) {
            return $operatingPositive ? self::ShakeOut : self::Decline;
        }

        if ($operatingPositive) {
            return $financingPositive ? self::Growth : self::Mature;
        }

        return $financingPositive ? self::Introduction : self::ShakeOut;
    }

    /** Pre-profit firms funded by outside capital do not initiate distributions. */
    public function initiatesDistributions(): bool
    {
        return $this !== self::Introduction;
    }

    /** Stages in which management is under pressure to shed assets. */
    public function isShedding(): bool
    {
        return $this === self::Decline || $this === self::ShakeOut;
    }

    /** One-sentence reading of the stage for display. */
    public function description(): string
    {
        return match ($this) {
            self::Introduction => 'Operations burn cash while the firm invests, and outside capital covers the gap.',
            self::Growth => 'Operations generate cash, the firm keeps investing, and it still raises capital on top.',
            self::Mature => 'Operations fund investment with room to spare, and the surplus is returned to shareholders.',
            self::ShakeOut => 'A mixed cash-flow pattern: the firm is neither clearly reinvesting nor clearly harvesting.',
            self::Decline => 'Operations burn cash and assets are being sold to cover the shortfall.',
        };
    }

    /** Dickinson sign pattern as "CFO / CFI / CFF"; ± marks a sign the stage admits either way, shake-out lists its three patterns. */
    public function cashFlowSignature(): string
    {
        return match ($this) {
            self::Introduction => '− / − / +',
            self::Growth => '+ / − / +',
            self::Mature => '+ / − / −',
            self::ShakeOut => '− / − / −  ·  + / + / ±',
            self::Decline => '− / + / ±',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Introduction => 'Introduction',
            self::Growth => 'Growth',
            self::Mature => 'Mature',
            self::ShakeOut => 'Shake-out',
            self::Decline => 'Decline',
        };
    }
}
