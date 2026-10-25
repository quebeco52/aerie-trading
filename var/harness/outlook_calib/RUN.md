# outlook_calib: industry outlook drift vol and learning half-life from sector share data

Question: annual log-vol of an industry's demand share around its expected path (IndustryOutlooks drift_vol), and a
learning half-life, by category. No game runs; pure data analysis, Python stdlib only.

Data (untracked, in data/): BEA GDP by Industry, apps.bea.gov/industry/Release/XLS/GDPxInd/{GrossOutput,ValueAdded}.xlsx
and the underlying detail apps.bea.gov/industry/Release/XLS/UGdpxInd/GrossOutput.xlsx (UGO305-A nominal, UGO306-A real);
Eurostat nama_10_a64 output P1, CP_MEUR, ten EU economies (eu_a64.json via the dissemination API).

    python3 -I us_shares.py        # detail-level log shares for the Aerie mapping -> data/us_shares.json
    python3 -I fit_us.py main      # RW + noise Kalman (annual): q/r pins at the upper bound -> no white noise
    python3 -I fit_quarterly.py    # same on quarterly 71-industry shares: also pure random walk
    python3 -I fit_momentum.py us  # growth autocorrelation, corrected k-year variance ratios (also: eu, us71)
    python3 -I real_shares.py      # nominal vs real share vol (commodity lines are price-driven)
    python3 -I eu_shares.py        # EU A64 + US 71-level series for the cross-check
    python3 -I final_table.py      # numbers behind the FINAL categories in categories.py
