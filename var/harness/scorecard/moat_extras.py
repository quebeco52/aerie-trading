"""Moat-ladder extras beside scorecard.py: return levels, valuation multiples and the five banks' P/B, paired.

python3 moat_extras.py base moat   (from this directory)
ROE = 4 x quarterly net income / equity. ROIC is a proxy: 4 x EBIT x (1 - 0.25) / (equity + debt - cash), operating
firms only. Firm level = the firm's median quarter over the window; board = median firm. P/E on trailing-4q income.
"""

import math
import statistics as st
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'lib'))
from stats import paired, mean_se  # noqa: E402
from scorecard import load  # noqa: E402

BANKS = ['LAKE', 'RIVR', 'PLVR', 'EGRT', 'BNTH']
TAX = 0.25


def pct(v, p):
    v = sorted(v)
    return v[min(len(v) - 1, int(p * len(v)))] if v else math.nan


def med(v):
    return st.median(v) if v else math.nan


def metrics(run):
    meta, final = run['meta'], run['final']
    out = {}
    roe = {'ops': {'all': [], 'y1': [], 'y20': []}, 'fin': {'all': [], 'y1': [], 'y20': []}}
    roic = {'all': [], 'y1': [], 'y20': []}
    pbs, pes, neg_ni = [], [], 0
    nq = 0
    for t, f in final.items():
        q = [r for r in f['q'] if r.get('rev', 0) > 0 or r.get('eq', 0) > 0]
        if len(q) < 8:
            continue
        grp = 'fin' if meta.get(t, {}).get('fin', False) else 'ops'
        r_all = [4 * r['ni'] / r['eq'] for r in q if r['eq'] > 0]
        if r_all:
            roe[grp]['all'].append(med(r_all))
            roe[grp]['y1'].append(med(r_all[:4]))
            roe[grp]['y20'].append(med(r_all[-4:]))
        if grp == 'ops':
            ic = [4 * r['ebit'] * (1 - TAX) / (r['eq'] + r['debt'] - r['cash']) for r in q
                  if r['eq'] + r['debt'] - r['cash'] > 0]
            if ic:
                roic['all'].append(med(ic))
                roic['y1'].append(med(ic[:4]))
                roic['y20'].append(med(ic[-4:]))
        for i, r in enumerate(q):
            if r['eq'] <= 0 or not r.get('shares') or r['price'] <= 0:
                continue
            nq += 1
            pb = r['price'] * r['shares'] / r['eq']
            pbs.append(pb)
            if i >= 3:
                ttm = sum(x['ni'] for x in q[i - 3:i + 1])
                if ttm > 0:
                    pes.append(r['price'] * r['shares'] / ttm)
                else:
                    neg_ni += 1
        if t in BANKS:
            bp = [r['price'] * r['shares'] / r['eq'] for r in q if r['eq'] > 0 and r.get('shares')]
            out[f'{t} P/B open'] = bp[0] if bp else math.nan
            out[f'{t} P/B median'] = med(bp)
            out[f'{t} ROE median'] = med(r_all)
    for g in ('ops', 'fin'):
        out[f'ROE {g} median firm, y1'] = med(roe[g]['y1'])
        out[f'ROE {g} median firm, whole run'] = med(roe[g]['all'])
        out[f'ROE {g} median firm, y20'] = med(roe[g]['y20'])
    out['ROIC ops median firm, y1'] = med(roic['y1'])
    out['ROIC ops median firm, whole run'] = med(roic['all'])
    out['ROIC ops median firm, y20'] = med(roic['y20'])
    out['P/B median'] = med(pbs)
    out['P/B p5'] = pct(pbs, 0.05)
    out['P/B p95'] = pct(pbs, 0.95)
    out['P/B max'] = max(pbs) if pbs else math.nan
    out['P/E median (ttm>0)'] = med(pes)
    out['P/E p5'] = pct(pes, 0.05)
    out['P/E p95'] = pct(pes, 0.95)
    out['share of quarters ttm NI<=0'] = neg_ni / max(1, nq)
    return out


def main():
    a, b = sys.argv[1:3]
    ra, rb = load(a), load(b)
    ma = {s: metrics(r) for s, r in ra.items()}
    mb = {s: metrics(r) for s, r in rb.items()}
    seeds = sorted(set(ma) & set(mb))
    keys = list(mb[seeds[0]].keys())
    print(f'{a} vs {b}, seeds {seeds}')
    print(f"{'quantity':34} {a:>15} {b:>15}   {'diff (se)':>18}  t")
    for k in keys:
        xa = {s: ma[s].get(k, math.nan) for s in seeds}
        xb = {s: mb[s].get(k, math.nan) for s in seeds}
        sa, sb = mean_se(xa.values()), mean_se(xb.values())
        p = paired(xa, xb)
        print(f'{k:34} {sa.mean:9.3f} ±{sa.se:4.3f} {sb.mean:9.3f} ±{sb.se:4.3f}   {p.diff:+8.3f} ({p.se:.3f})  {p.t:+.1f}')


if __name__ == '__main__':
    main()
