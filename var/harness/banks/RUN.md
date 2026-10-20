# Lender NIM response harness

Question: how far does each seeded lender's net interest margin move per point of rates, over four quarters and in
the long run?

- Script: `bin/php-slot php var/harness/banks/nim_response.php > var/harness/banks/<name>.out` (~2 s, no database).
  - Opens the seeded board through `OpeningBoardBuilder` on the opening macro (loads `tests/bootstrap.php` for the
    in-memory Redis and the bcmath shim).
  - Carries each lender's book one quarter at the opening macro, then 4 and 40 more either at the opening macro or
    with the policy rate and the whole curve one point higher, on the same draw stream.
- Columns:
  - `inc 4q`: interest income change per unit of earning assets, from the book's own repricing plus the cash beside it.
  - `exp 4q`: the model's four-quarter expense beta on total assets, per unit of earning assets.
  - `dNIM 4q`, `dNIM 40q`: their difference, in bp per point. Real economies run about 5-30bp per point over one to
    two years (Australia ~5, euro area ~17, UK ~25, Spain ~30 in 2022-23).
  - `spread`, `float`: the struck loan spread and floating share.
- Runs: `nim-base.out` = HEAD 4bc145f4 (before batch D1); `nim-d1.out` = after D1.

## Credit losses over the District's cycle

- Script: `bin/php-slot php var/harness/banks/credit_at_trend.php <seeds> <years> [tpy] [burn-in years]` (6 seeds x 90
  years at 120 ticks/year ~20 s; macro only, `tests/Support/DistrictRun`, burn-in 10 years unless given).
- Reads the household and corporate default rates against their measured trends once a quarter and averages each
  loan segment's loss multiple (loss over its long-run PD) under the raw Vasicek reading (factor solved against the
  trend) and the trend-anchored one (`CreditRisk::calculateVasicekPdMultiple`). The mapping that averages one is the
  one whose book loses its through-the-cycle rate over the cycle.
- Runs: `credit-at-trend-6s90y.out`: household rate over trend mean 0.99, sd 0.28, skew +0.86; raw multiples 0.94-1.07
  (card 1.075, residential 0.942), anchored 0.986-1.015.
  `credit-at-trend-6s20y-noburn.out`: the first 20 years from the opening, no burn-in: household rate over trend 0.96,
  corporate 0.92, so books lose 3-8% under their through-the-cycle rate while the trends fall from their opening
  baselines toward the rates the economy settles at.
