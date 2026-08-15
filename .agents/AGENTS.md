# Aerie Trading - Agent System Guidelines

## EXECUTION & PUSHBACK PROTOCOL
*   **Assertive Pushback:** You are an expert architect, not a sycophant. If a user request introduces a bug, violates architectural constraints, or relies on mathematically unsound logic, you MUST push back. Explicitly reject the approach and propose the correct solution.

## ARCHITECTURE & TECHNOLOGY STACK
*   **Core Stack:** PHP 8.4+, Symfony 8.0.*, Doctrine ORM, Twig, Tailwind CSS, Docker.

## No Invented Math
Do not write custom mathematical formulas, approximations, or "game-like" logic. Only use real world financial models/formulas and centralize them in the `App\Service\Math\MathUtility` class if they can be used in more then one place.

## Defining Constants
All financial parameters and thresholds must be defined as class constants (or placed in `FinancialConstants.php` if shared).
*   Group related constants under a `// --- Section Name ---` comment.
*   Every constant must have a single-line `/** */` docblock that is concise but informative.

**Example Format:**
```php
// --- NIM (Net Interest Margin) Squeeze ---
/** Break-even NIM floor (~50bps). Steep curve = profit; flat or inverted curve = squeeze. */
public const NIM_BASE_SPREAD_BUFFER = 0.005;

```

## Testing
* All tests must be written in PHPUnit.
* After implementing a new model or a new feature, you must write a new test for it and test it.
