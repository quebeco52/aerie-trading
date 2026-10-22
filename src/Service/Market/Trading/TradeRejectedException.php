<?php

declare(strict_types=1);

namespace App\Service\Market\Trading;

/**
 * An order the desk refuses, with the reason written for the player. The trade pages show only this message; any
 * other failure is logged and shown as a generic line.
 */
final class TradeRejectedException extends \DomainException {}
