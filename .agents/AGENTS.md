# Aerie Trading - Agent Rules and Guidelines

Welcome to the Aerie Trading project. This file contains rules, architectural guidelines, and behavioral constraints for all AI agents assisting with this codebase.

## AI Policy & Behavioral Constraints
- **Planning is Required:**  First analys the relevant code, plan out a well decided solution, the execute the plan with user agreement.
- **Provide Snippets and Edits with Care:** When asked to create or modify code, provide clear explanations. If making file edits, ensure they are precise and adhere to the guidelines below. 

## Technology Stack
- **Backend:** PHP 8.4+, Symfony 8.0.*, Doctrine ORM
- **Real-Time / State:** Workerman (WebSockets), Redis
- **Database:** MariaDB
- **Frontend:** Tailwind CSS, Twig
- **Environment:** Docker (`make` is used for orchestration)

## Architecture & Coding Standards
- **PHP 8 Attributes:** Use PHP 8 attributes for routing (`#[Route]`), security (`#[IsGranted]`), and Doctrine ORM mappings (`#[ORM\Entity]`, `#[ORM\Column]`). Do not use legacy annotations or YAML configurations.
- **Type Safety & Precision:** The project relies heavily on precise math, especially for financial calculations. Use string representations for large numbers or high-precision decimals (`Types::DECIMAL`, `Types::BIGINT`) to avoid floating-point errors.
- **N+1 Query Prevention:** Always write efficient Doctrine queries. Use explicitly defined `JOIN` clauses in DQL when fetching entities with their relations (e.g., `User` and `UserStock`) to prevent lazy-loading bottlenecks.

## Domain Logic & Market Physics
- **Absolute Values for Accounting:** To maintain mathematically flawless accounting during splits, buyouts, and buybacks, store **absolute values** (e.g., `totalNetIncome`, `totalEquity`, `corporateTreasury`) in the database rather than per-share metrics. Per-share metrics are derived dynamically.
- **Realistic Mathematical Models:** The project utilizes advanced formulas such as Geometric Brownian Motion (GBM), Jump Diffusion (Merton Model), and the Heston Stochastic Volatility Model. When modifying market physics, ensure changes are grounded in realistic economic and mathematical principles. Minimize the use of "magic numbers."
- **Testing:** All market formulas must be strictly tested and pass in PHPUnit. Do not introduce untested logic into the core market simulator.
