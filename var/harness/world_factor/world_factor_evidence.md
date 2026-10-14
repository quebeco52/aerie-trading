# World factor evidence

## 2026-10-14 Oil demand in the spot: ENERGY_DEMAND_ELASTICITY x ENERGY_VOLATILITY sweep

Question: with oil demand moved into the spot (`base * exp(ENERGY_DEMAND_ELASTICITY * globalDemandGapEma)`), which
(elasticity, sigma) hits corr_oil_global 0.55 and oil_yoy_sd 0.314 (WTI vs G6 IP, 1993-2019)?
Harness: `world.php` via `run.sh O<e>v<sigma> <seed>` (OVR copies from `make_oil_ovr.sh`), B = cached runs of the
previous working tree. Seeds 1-4, 30 years, 360 ticks/year. Batch:
`bin/harness-batch var/harness/world_factor/run.sh 1-4 O12v0.21 O12v0.24 O16v0.21 O8v0.24`, then
`analyse.py <arm> 1-4 B`. Full output: `oil_sweep_s4.out`.

| arm      | corr_oil_global | corr_oil_world | oil_yoy_sd | cpi_sd | ppi_sd | gscpi_ema_sd | freight_yoy_sd |
|----------|-----------------|----------------|------------|--------|--------|--------------|----------------|
| B        | -0.026          | 0.001          | 0.299      | 0.0092 | 0.0251 | 0.404        | 0.222          |
| O8v0.24  | 0.290 (t 15.9)  | 0.220          | 0.287 (t -3.1) | 0.0094 | 0.0275 | 0.398 | 0.220 |
| O12v0.21 | 0.471 (t 13.1)  | 0.338          | 0.291 (t -1.3) | 0.0096 | 0.0290 | 0.398 | 0.220 |
| O12v0.24 | 0.438 (t 12.4)  | 0.320          | 0.306 (t 1.1)  | 0.0100 | 0.0296 | 0.399 | 0.220 |
| O16v0.21 | 0.589 (t 12.7)  | 0.416          | 0.318 (t 1.9)  | 0.0105 | 0.0315 | 0.399 | 0.220 |

O16v0.21 is within 0.04 of both targets; picked. Caveats: n=4; oil_yoy_sd se ~0.01; cpi_sd +14% and ppi_sd +25% vs B
(t 7.1, 4.6) are the cost; corr against the rest-of-world gap alone (corr_oil_world) is 0.42.

## 2026-10-14 Oil demand confirmation (ENERGY_DEMAND_ELASTICITY 15, ENERGY_VOLATILITY 0.21), W vs cached B, 8 seeds

Question: does the working tree hit corr(oil yoy, global gap yoy) 0.55 and oil yoy log sd 0.314 without moving metals.
Run: `bin/harness-batch var/harness/world_factor/run.sh 1-8 W && python3 var/harness/world_factor/analyse.py W 1-8 B`,
30 y, 360 ticks/yr. Output `oil_verify_W_B_8.out`.
- corr_oil_global 0.526 (se 0.028) vs B 0.006, t +10.8; target 0.55 within 1 se.
- oil_yoy_sd 0.3139 (se 0.006) vs B 0.310, t +0.7; target 0.314.
- metals rows, freight, world gap, gscpi_ema_sd unchanged (|t| <= 1.9).
- cpi_sd +0.0006 (t +2.8), ppi_sd 0.0256 -> 0.0299 (t +5.0): oil now co-moves with demand, so PPI variance rises.
