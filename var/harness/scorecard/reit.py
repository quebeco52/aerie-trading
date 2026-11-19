"""REIT opening books + target-capped debt capacity: the four trusts and the board, paired by seed (b - a).
Usage (from this directory): python3 reit.py <arm_a> <arm_b>
D/E = book debt / book equity; deH and lt are the engine's recap D/E and leverage target (rows from reit1 on).
FFO = net income + depreciation; payout = TTM dividends / TTM FFO. P/B = price x shares / book equity.
Shares growth excludes quarters whose share count moved by more than the split ratio. A cut = declared DPS down >0.5%
with no split that quarter. Covenant breach = ndE > covL.
"""
import math
import os
import re
import statistics as st
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from payout import load, at, SPLIT  # noqa: E402
from lend import gdp, row_at  # noqa: E402
from pay2 import tier  # noqa: E402
from stats import paired, fmt  # noqa: E402

REITS = ('SHOR', 'PLZA', 'ELDE', 'KITE')
YRS = (0, 5, 10, 20)


def ttm(q, r):
    return [x for x in q if r['t'] - 1.0 < x['t'] <= r['t'] + 1e-9]


def split_q(a, b):
    return a['shares'] > 0 and b['shares'] > 0 and max(b['shares'] / a['shares'], a['shares'] / b['shares']) > SPLIT


def share_growth(q, y):
    g = 0.0
    for a, b in zip(q, q[1:]):
        if b['t'] > y + 1e-6:
            break
        if not split_q(a, b) and a['shares'] > 0:
            g += math.log(b['shares'] / a['shares'])
    return math.exp(g) - 1.0


def reit(run):
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
            ni4 = sum(x['ni'] for x in w) * k
            ffo4 = sum(x['ni'] + x['dep'] for x in w) * k
            div4 = sum(x['div'] for x in w) * k
            g = gdp(run, r['t'])
            eq = r['eq']
            out[f'{tk} D/E y{y}'] = r['debt'] / eq if eq > 0 else float('nan')
            if r.get('lt') is not None:
                out[f'{tk} engine D/E y{y}'] = r['deH']
                out[f'{tk} lev target y{y}'] = r['lt']
            out[f'{tk} ndebt/EBITDA y{y}'] = r.get('ndE', float('nan'))
            if y in (0, 10, 20):
                out[f'{tk} rev/GDP% y{y}'] = 100 * r['rev'] * 4 / g
                out[f'{tk} eq/GDP% y{y}'] = 100 * eq / g
            out[f'{tk} ROE y{y}'] = ni4 / eq if eq > 0 else float('nan')
            out[f'{tk} P/B y{y}'] = r['price'] * r['shares'] / eq if eq > 0 else float('nan')
            out[f'{tk} FFO payout y{y}'] = div4 / ffo4 if ffo4 > 0 else float('nan')
        for y in (5, 10, 20):
            out[f'{tk} shares growth to y{y}'] = share_growth(q, y)
        out[f'{tk} covenant qtrs'] = sum(1 for x in q if x.get('covL') and x.get('ndE') is not None and x['ndE'] > x['covL'])
        rat = [x.get('rating') for x in q]
        out[f'{tk} rating changes'] = sum(1 for a, b in zip(rat, rat[1:]) if a != b)
        out[f'{tk} rating y0>y20'] = f"{rat[0]}>{rat[-1]}"
        out[f'{tk} icr<1 qtrs'] = sum(1 for x in q if x.get('icr') is not None and x['icr'] < 1)
        out[f'{tk} dead/default'] = int(bool(f.get('dead'))) + sum(tk in str(d) for d in list(run.get('defaults', [])) + list(run.get('reorgs', [])))
        cuts = 0
        for a, b in zip(q, q[1:]):
            da, db = a.get('dps') or 0, b.get('dps') or 0
            if da > 0 and db < da * 0.995 and not split_q(a, b):
                cuts += 1
        out[f'{tk} DPS cuts'] = cuts
        out[f'{tk} streak y20'] = (q[-1].get('streak') or 0)
        out[f'{tk} tier y0>y20'] = f"{tier(q[0].get('streak') or 0)}>{tier(q[-1].get('streak') or 0)}"
    return out


def board(run):
    out = {}
    fin = run['final']
    brk13 = set()
    fq = 0
    for tk, f in fin.items():
        for x in f['q']:
            if x.get('covL') and x.get('ndE') is not None and x['ndE'] > x['covL']:
                fq += 1
                if 1.0 <= x['t'] <= 3.0 + 1e-6:
                    brk13.add(tk)
    out['covenant breach firms y1-3'] = len(brk13)
    out['covenant breach firm-qtrs total'] = fq
    deals = [(t, x['d']) for t, ev in run.get('events', {}).items() for x in ev if re.search(r'executed a \$', x['d'])]
    out['acquisitions'] = len(deals)
    out['REIT acquisitions'] = sum(t in REITS for t, _ in deals)
    out['REIT acquisitions y1-3'] = sum(1 for t, ev in run.get('events', {}).items() if t in REITS for x in ev
                                        if re.search(r'executed a \$', x['d']) and x['t'] <= 3.0)
    out['deaths'] = len(run['dead'])
    out['defaulters'] = len(run['defaults'])
    return out


def firm_end(run):
    """Per firm: log equity and log revenue at y10 and y20 (for the non-REIT difference scan)."""
    out = {}
    for tk, f in run['final'].items():
        q = [x for x in f['q'] if x.get('rev', 0) > 0]
        for y in (10, 20):
            r = at(q, y)
            if r and r['eq'] > 0 and r['rev'] > 0:
                out[(tk, y, 'eq')] = math.log(r['eq'])
                out[(tk, y, 'rev')] = math.log(r['rev'])
    return out


def table(ra, rb, fn, title):
    seeds = sorted(set(ra) & set(rb))
    ma = {s: fn(ra[s]) for s in seeds}
    mb = {s: fn(rb[s]) for s in seeds}
    print(f'{title}  (n={len(seeds)})\n{"":36}{"a":>9}{"b":>9}   b-a (se) t n')
    for k in ma[seeds[0]]:
        xa = [ma[s].get(k) for s in seeds]
        xb = [mb[s].get(k) for s in seeds]
        if any(isinstance(x, str) or x is None for x in xa + xb):
            print(f'  {k:34} {" ".join(map(str, xa))} | {" ".join(map(str, xb))}')
            continue
        print(f'  {k:34}{st.mean(xa):9.3f}{st.mean(xb):9.3f}   {fmt(paired(xa, xb))}')


def scan(ra, rb):
    seeds = sorted(set(ra) & set(rb))
    fa = {s: firm_end(ra[s]) for s in seeds}
    fb = {s: firm_end(rb[s]) for s in seeds}
    keys = set.intersection(*[set(fa[s]) & set(fb[s]) for s in seeds])
    rows = []
    for k in keys:
        d = [fb[s][k] - fa[s][k] for s in seeds]
        m = st.mean(d)
        rows.append((abs(m), k, m, st.stdev(d) / math.sqrt(len(d)) if len(d) > 1 else 0.0))
    rows.sort(reverse=True)
    non = [r for r in rows if r[1][0] not in REITS]
    print('Non-REIT firm scan: mean |log diff| of equity, revenue at y10/y20 over all non-REIT firms')
    for y in (10, 20):
        for c in ('eq', 'rev'):
            v = [r[0] for r in non if r[1][1] == y and r[1][2] == c]
            print(f'  y{y} {c}: mean |d| {st.mean(v):.3f}, median {st.median(v):.3f}, max {max(v):.3f}, n firms {len(v)}')
    print('  largest non-REIT mean log diffs (firm, y, field, mean, se):')
    for _, k, m, se in non[:8]:
        print(f'    {k[0]:5} y{k[1]:<3} {k[2]:3} {m:+.3f} ({se:.3f})')


if __name__ == '__main__':
    a, b = sys.argv[1], sys.argv[2]
    ra, rb = load(a), load(b)
    table(ra, rb, reit, f'REITs {a} -> {b}')
    table(ra, rb, board, 'Board')
    scan(ra, rb)
