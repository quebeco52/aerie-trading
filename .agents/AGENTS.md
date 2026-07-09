# Aerie Trading - Agent System Guidelines

This file dictates the strict architectural constraints, domain logic, and execution protocols for AI agents operating within the Aerie Trading codebase. 

## 1. Execution Protocol (Think -> Plan -> Execute)
- **Mandatory Scratchpad:** For any task involving architectural changes, complex financial math, or multi-file debugging, you MUST invoke the `sequential-thinking` tool to structure your logic before generating a final response.
- **Explicit Planning:** For complex tasks, output a step-by-step technical plan. Wait for user confirmation before executing massive file edits or refactors.
- **Assertive Pushback:** Do not act as a sycophant. If a requested change will introduce a bug, violate architectural constraints, or rely on mathematically unsound logic, you MUST push back. Explicitly explain why the approach is flawed and propose the correct technical solution. Do not blindly write broken code just because the user asked for it.

## 2. Technology Stack
- **Core:** PHP 8.4+ (Strict Types enforced), Symfony 8.0.*, Doctrine ORM
- **State & Async:** MariaDB, Redis, Symfony Messenger, Workerman (WebSockets)
- **Frontend:** Twig, Tailwind CSS
- **Infrastructure:** Docker

## 3. Architecture & Coding Standards
- **Strict Typing:** Every PHP file must declare `declare(strict_types=1);`. Fully type-hint all properties, arguments, and return types. 
- **Modern PHP/Symfony:** Use PHP 8 Attributes exclusively for routing (`#[Route]`), security (`#[IsGranted]`), and ORM mapping (`#[ORM\Entity]`). Zero YAML or annotation configurations.
- **Financial Math (CRITICAL):** Never use standard floating-point numbers for money or high-precision financial math. Always use `Types::DECIMAL` or `Types::BIGINT` in Doctrine, and rely on PHP's `bcmath` extension or string representations for calculations.
- **Query Optimization:** Prevent N+1 queries by explicitly defining `JOIN` clauses in DQL or QueryBuilder when fetching related entities (e.g., `User` and `UserStock`).
- **Async First:** Heavy market calculations or order executions (e.g., Limit Orders) must be dispatched to Symfony Messenger queues, never processed synchronously in HTTP requests.

## 4. Domain Logic & Market Physics
- **Theoretical Rigor (NO INVENTED MATH):** All pricing, trading logic, and market mechanics must be strictly derived from well-established financial theories, standard accounting principles, and recognized econometric models (e.g., GBM, Merton Jump Diffusion, Black-Scholes). Do not invent proprietary or "game-like" formulas.
- **Absolute Accounting:** To maintain flawless accounting during splits, buyouts, and buybacks, store **absolute values** in the database (e.g., `totalNetIncome`, `totalEquity`, `corporateTreasury`). Per-share metrics must be derived dynamically.
- **No Magic Numbers:** All IMPORTANT mathematical constants, thresholds, and corporate financial parameters (THAT ARE NOT OBVIOUS) should be defined as class constants or pulled from `FinancialConstants.php` if it could be used in different functions.
- **Constant Documentation Style:** Group related constants under a `// --- Section Name ---` comment. Every constant must have a single-line `/** */` docblock — concise but informative. Not a wall of text, not bare. Example:
  ```php
  // --- NIM (Net Interest Margin) Squeeze ---
  /** Break-even NIM floor (~50bps). Steep curve = profit; flat or inverted curve = squeeze. */
  private const NIM_BASE_SPREAD_BUFFER = 0.005;
  /** Calibrated so a -100bps inversion produces ~10% variable cost add-on. Tune with NIM_QUADRATIC_COEFF. */
  private const NIM_INVERSION_SENSITIVITY = 10.0;
  ```
- **Testing Standard:** All market formulas must be backed by PHPUnit tests. Do not commit complex execution logic without corresponding assertions.
