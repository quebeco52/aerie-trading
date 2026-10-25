"""Real (chain-type quantity) detail-level gross output shares against real GDP, same Aerie mapping; 1997-2019 stats.

Real GO lines are not additive across industries; a multi-line Aerie industry sums them as an approximation.
python3 -I real_shares.py
"""
import json, math, os, sys
HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from xlsx import sheet_grid  # noqa: E402
from us_shares import AERIE, YEARS, gap_series  # noqa: E402
from fit_momentum import growth_resid, stats  # noqa: E402

g = sheet_grid(os.path.join(HERE, 'data', 'UGrossOutput.xlsx'), 'UGO306-A')
header, rows = None, {}
for r in g:
    v = [r[k] for k in sorted(r)]
    if v and v[0] == 'Line':
        header = v
        continue
    if header and len(v) == len(header) + 1:
        vals = []
        for x in v[2:]:
            try:
                vals.append(float(x))
            except ValueError:
                vals.append(None)
        rows[v[1].strip()] = vals
va = sheet_grid(os.path.join(HERE, 'data', 'ValueAdded.xlsx'), 'TVA106-A')
gdp = None
for r in va:
    v = [r[k] for k in sorted(r)]
    if len(v) > 3 and v[1].strip() == 'Gross domestic product':
        gdp = [float(x) for x in v[2:]]
        break
cut = YEARS.index(2019) + 1
gap = gap_series()[:cut]
nom = json.load(open(os.path.join(HERE, 'data', 'us_shares.json')))['industries']
out = {}
print(f"{'industry':42s} {'nom sd1':>7s} {'nom lr5':>7s} {'real sd1':>8s} {'real lr5':>8s}")
for ind, lines in AERIE.items():
    if not lines or any(ln not in rows for ln in lines):
        continue
    s = []
    for i in range(cut):
        vals = [rows[ln][i] for ln in lines]
        s.append(math.log(sum(vals) / gdp[i]) if all(x for x in vals) else None)
    if any(x is None for x in s):
        continue
    sr = stats(growth_resid(s, gap))
    sn = stats(growth_resid(nom[ind][:cut], gap))
    out[ind] = {'nom_sd1': sn['sd1'], 'nom_lr5': sn['lr'][5], 'real_sd1': sr['sd1'], 'real_lr5': sr['lr'][5]}
    print(f"{ind:42s} {sn['sd1']:7.3f} {sn['lr'][5]:7.3f} {sr['sd1']:8.3f} {sr['lr'][5]:8.3f}")
json.dump(out, open(os.path.join(HERE, 'data', 'real_vs_nominal.json'), 'w'), indent=1)
