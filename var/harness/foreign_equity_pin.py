#!/usr/bin/env python3
"""Pins SovereignFundSubsystem::FOREIGN_EQUITY_VOLATILITY and ::FOREIGN_EQUITY_MARKET_CORRELATION.

Data: Ken French Data Library, monthly "Developed ex US 3 Factors" and "F-F Research Data Factors"
(total return = Mkt-RF + RF), both in USD. Download and unzip into $TMPDIR/ff:
  https://mba.tuck.dartmouth.edu/pages/faculty/ken.french/ftp/Developed_ex_US_3_Factors_CSV.zip
  https://mba.tuck.dartmouth.edu/pages/faculty/ken.french/ftp/F-F_Research_Data_Factors_CSV.zip

The USD series is the foreign market as a US-based holder sees it: local-currency equity plus the currency.
The engine translates the foreign sleeve through its own exchange rate (AssetMarketSubsystem, Schwartz noise
MacroEngine::EXCHANGE_RATE_VOLATILITY, independent of the equity shocks), so the LOCAL-currency process is backed out:
  sigma_local^2 = sigma_usd^2 - sigma_fx^2
  rho_local     = rho_usd * sigma_usd / sigma_local     (cov with the home market is carried by the equity leg)
Result 2026-09-26 (1990-07..2026-08, 434 months): sigma_usd 0.1644, rho_usd 0.7734 -> sigma_local 0.1436, rho_local 0.885.
"""
import math
import os
import statistics as st

FX_VOL = 0.08  # MacroEngine::EXCHANGE_RATE_VOLATILITY
DIR = os.environ.get('TMPDIR', '/tmp') + '/ff/'


def load(name):
    out = {}
    for line in open(DIR + name):
        parts = [p.strip() for p in line.split(',')]
        if len(parts) >= 5 and parts[0].isdigit() and len(parts[0]) == 6:
            out[parts[0]] = (float(parts[1]) + float(parts[4])) / 100.0
        elif 'Annual' in line and out:
            break
    return out


ex_us = load('Developed_ex_US_3_Factors.csv')
us = load('F-F_Research_Data_Factors.csv')
months = sorted(k for k in ex_us if k in us)
a = [math.log1p(ex_us[k]) for k in months]
b = [math.log1p(us[k]) for k in months]
sd_a, sd_b = st.stdev(a), st.stdev(b)
ma, mb = st.mean(a), st.mean(b)
rho_usd = sum((x - ma) * (y - mb) for x, y in zip(a, b)) / ((len(a) - 1) * sd_a * sd_b)
sigma_usd = sd_a * math.sqrt(12.0)
sigma_local = math.sqrt(sigma_usd ** 2 - FX_VOL ** 2)
rho_local = rho_usd * sigma_usd / sigma_local
print(f'sample {months[0]}..{months[-1]} ({len(months)} months)')
print(f'sigma_usd {sigma_usd:.4f} rho_usd {rho_usd:.4f} -> sigma_local {sigma_local:.4f} rho_local {rho_local:.4f}')
