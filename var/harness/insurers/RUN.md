# Insurer opening float

Question: what float does each seeded insurer's book settle at, and what does it earn there?

- Script: `bin/php-slot php var/harness/insurers/steady_float.php` (well under a second, no database; loads
  `tests/bootstrap.php`).
  - Opens each insurer through `OpeningBoardBuilder` at a trial float (treasury = deposits), reads the premium it opens
    writing, and moves the float to the reserve ratio times that premium until the two agree.
  - Prints the steady float, the premium, the reserve ratio and the structural return: underwriting at the operating
    margin less the cover's expected cost (loading and reinstatements on the covered book), plus the net investment
    income the model books after life crediting, less interest, after tax.
- The seed's `customer_deposits`, `corporate_treasury` and `baseline_roe` for SAFE and DOVE were set from it (batch D2);
  `tests/Service/Model/InsurerOpeningFloatTest.php` holds the floats to within 3%.
