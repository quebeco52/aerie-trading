<?php

declare(strict_types=1);

namespace App\Service\News;

/** How the news desk decides whether a story is a headline (NewsDesk::isHeadline); each card names one. */
enum HeadlineTest
{
    /** A headline whatever the price did: a district story or a failure. */
    case ByKind;
    /** A headline when the deal is significant to the company. */
    case DealSize;
    /** A headline when the rating moves between investment grade and high yield. */
    case GradeCrossing;
    /** A headline when the price shock is beyond the circuit-breaker size. */
    case ShockMove;
    /** A headline when an industry's outlook is revised far. */
    case RevisionSize;
    /** A headline when the move is significant against the stock's own volatility. */
    case AbnormalReturn;
    /** Routine: never a headline. */
    case Never;
}
