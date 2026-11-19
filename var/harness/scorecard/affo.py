"""AFFO valuation check for the four REITs, paired by seed (b - a), on top of reit.py's tables.
Usage (from this directory): python3 affo.py <arm_a> <arm_b>
A REIT's reported ni is FFO (EarningsEngine adds depreciation back); taxable income = ni - dep; AFFO = FFO - dep.
P/FFO = cap / TTM FFO; yield = TTM dividends / cap. Floor ratio = TTM dividends / (0.85 x TTM taxable income); a year
binds when the ratio is under 1.02. Trace (affo1 harness on): `reit_trace` [t, price, perceived fair value] every 9
ticks and each of the first 30; opening move = log(px at tick 30 / px at tick 1); mispricing = log(px / fv) over y1-20.
"""
import math
import os
import re
import statistics as st
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from payout import load  # noqa: E402
from lend import row_at  # noqa: E402
from reit import REITS, YRS, ttm, table, reit, board, scan  # noqa: E402

FLOOR = 0.85


def extra(run):
    out = {}
    fin = run['final']
    for tk in REITS:
        f = fin.get(tk)
        if not f:
            continue
        q = [x for x in f['q'] if x.get('rev', 0) > 0]
        for y in YRS:
            r = row_at(q, y)
            if not r:
                continue
            w = ttm(q, r)
            k = 4.0 / max(1, len(w))
            ffo4 = sum(x['ni'] for x in w) * k
            affo4 = sum(x['ni'] - x['dep'] for x in w) * k
            div4 = sum(x['div'] for x in w) * k
            cap = r['price'] * r['shares']
            out[f'{tk} P/FFO y{y}'] = cap / ffo4 if ffo4 > 0 else float('nan')
            out[f'{tk} P/AFFO y{y}'] = cap / affo4 if affo4 > 0 else float('nan')
            out[f'{tk} div yield% y{y}'] = 100 * div4 / cap if cap > 0 else float('nan')
        # 85%-of-taxable-income floor: each fiscal year end y1..y20.
        ratios = []
        for y in range(1, 21):
            r = row_at(q, y)
            if not r:
                continue
            w = ttm(q, r)
            tax = sum(x['ni'] - x['dep'] for x in w)
            div = sum(x['div'] for x in w)
            if tax > 0:
                ratios.append(div / (FLOOR * tax))
        out[f'{tk} floor ratio mean'] = st.mean(ratios) if ratios else float('nan')
        out[f'{tk} floor binding yrs/20'] = sum(1 for x in ratios if x < 1.02)
        gw = [x.get('gw') or 0.0 for x in q]
        out[f'{tk} goodwill booked $bn'] = sum(max(0.0, b - a) for a, b in zip(gw, gw[1:])) / 1e9
        out[f'{tk} goodwill/eq y20'] = gw[-1] / q[-1]['eq'] if q[-1]['eq'] > 0 else float('nan')
        tr = run.get('reit_trace', {}).get(tk)
        if tr:
            px = [p for _, p, _ in tr]
            out[f'{tk} opening move log(30t/1t)'] = math.log(tr[min(29, len(tr) - 1)][1] / tr[0][1])
            out[f'{tk} fv/px tick1'] = tr[0][2] / tr[0][1]
            peak, mdd = px[0], 0.0
            for p in px:
                peak = max(peak, p)
                mdd = min(mdd, math.log(p / peak))
            out[f'{tk} max drawdown log'] = mdd
            later = [(t, p, v) for t, p, v in tr if t >= 1.0 and v > 0]
            yr = [math.log(b[1] / a[1]) for a, b in zip(later, later[40:])]
            out[f'{tk} worst 1y log return'] = min(yr) if yr else float('nan')
            mis = [math.log(p / v) for _, p, v in later]
            out[f'{tk} log(px/fv) mean y1-20'] = st.mean(mis)
            out[f'{tk} |log(px/fv)| mean'] = st.mean(abs(m) for m in mis)
            out[f'{tk} share |log(px/fv)|>.25'] = sum(abs(m) > 0.25 for m in mis) / len(mis)
            run_len, best = 0, 0
            for m in mis:
                run_len = run_len + 1 if abs(m) > 0.25 else 0
                best = max(best, run_len)
            out[f'{tk} longest |mis|>.25 yrs'] = best / 40.0
    deals = [(t, x['t'], x['d']) for t, ev in run.get('events', {}).items() if t in REITS for x in ev
             if re.search(r'executed a \$', x['d'])]
    out['REIT acquisitions'] = len(deals)
    out['REIT goodwill booked $bn'] = sum(out.get(f'{tk} goodwill booked $bn', 0.0) for tk in REITS)
    return out


if __name__ == '__main__':
    a, b = sys.argv[1], sys.argv[2]
    ra, rb = load(a), load(b)
    table(ra, rb, reit, f'REITs {a} -> {b}')
    table(ra, rb, extra, f'REIT AFFO extras {a} -> {b}')
    table(ra, rb, board, 'Board')
    scan(ra, rb)
