"""Raise funding check: the dividend roll, opening champions, dividend-funded borrowing, SHRK and the three lender rows.
Usage (from this directory): python3 lend.py <arm_a> <arm_b> [--firms]   (paired by seed, b - a)
Tiers as pay2.py; champion+ = champion or king; shares are of payers (div > 0). Opening champions: st0 >= 25; a break is
a streak >= 5 falling; `cut` when DPS fell over 0.5% that quarter with no split in the last year, else `flat`.
Borrowing: "Borrowed $X to fund its dividend" events (only newsworthy ones are logged).
"""
import os
import re
import statistics as st
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from payout import load, at, SPLIT  # noqa: E402
from pay2 import tier  # noqa: E402
from stats import paired, fmt  # noqa: E402

YEARS = (0, 5, 10, 20)
LENDERS = ('TALN', 'STRK', 'POOL')
MULT = {'K': 1e3, 'M': 1e6, 'B': 1e9, 'T': 1e12}


def row_at(q, y):
    return q[0] if y == 0 and q else at(q, y)


def money(s):
    m = re.search(r'\$([\d.,]+)\s*([KMBT]?)', s)
    return float(m.group(1).replace(',', '')) * MULT.get(m.group(2), 1.0) if m else 0.0


def split_in(win):
    return any(x['shares'] > 0 and y['shares'] > 0 and (y['shares'] / x['shares'] > SPLIT or x['shares'] / y['shares'] > SPLIT) for x, y in zip(win, win[1:]))


def measures(run):
    out = {}
    fin = run['final']
    for y in YEARS:
        rows = [row_at(f['q'], y) for f in fin.values()]
        pay = [r for r in rows if r and r['div'] > 0]
        out[f'champ+ % payers y{y}'] = sum(tier(r.get('streak') or 0) in ('champion', 'king') for r in pay) / max(1, len(pay))
        out[f'roll % payers y{y}'] = sum(tier(r.get('streak') or 0) != 'none' for r in pay) / max(1, len(pay))
    champs = [(tk, m['st0']) for tk, m in run['meta'].items() if (m.get('st0') or 0) >= 25]
    for y in (5, 20):
        ok = 0
        for tk, s0 in champs:
            r = at(fin[tk]['q'], y)
            ok += r is not None and (r.get('streak') or 0) >= s0 + y - 1
        out[f'opening champs unbroken y{y}'] = ok / max(1, len(champs))
    flat = cut = 0
    for tk, _ in champs:
        q = fin[tk]['q']
        for i in range(1, len(q)):
            a, b = q[i - 1], q[i]
            if not ((a.get('streak') or 0) >= 5 and (b.get('streak') or 0) < (a.get('streak') or 0)):
                continue
            da, db = a['div'] / max(a['shares'], 1), b['div'] / max(b['shares'], 1)
            if db < da * 0.995 and not split_in(q[max(0, i - 4):i + 1]):
                cut += 1
            else:
                flat += 1
    out['champ breaks: flat'] = flat
    out['champ breaks: cut'] = cut
    bor = [money(e['d']) for v in run['events'].values() for e in v if 'to fund its dividend' in e['d']]
    out['div borrowing events'] = len(bor)
    out['div borrowing $bn'] = sum(bor) / 1e9
    sh = fin.get('SHRK')
    if sh:
        q = sh['q']
        for y in (5, 10, 20):
            r = at(q, y)
            out[f'SHRK streak y{y}'] = (r.get('streak') or 0) if r else 0
        brk = next((r['t'] for a, r in zip(q, q[1:]) if (a.get('streak') or 0) >= 5 and (r.get('streak') or 0) < (a.get('streak') or 0)), 20.0)
        out['SHRK first break y'] = brk
        out['SHRK div borrowing events'] = sum('to fund its dividend' in e['d'] for e in run['events'].get('SHRK', []))
        r = at(q, 20)
        out['SHRK net D/E y20'] = (r['debt'] - r['cash']) / r['eq'] if r and r['eq'] > 0 else 0.0
    return out


def gdp(run, t):
    mac = run['macro']
    idx0 = mac[0][1]
    pt = min(mac, key=lambda m: abs(m[0] - t))
    return float(run['gdp_usd']) * pt[1] / idx0


def lenders(run):
    out = {}
    fin = run['final']
    for tk in LENDERS:
        f = fin.get(tk)
        if not f:
            continue
        q = f['q']
        for y in (0, 1, 5, 10, 20):
            r = row_at(q, y)
            if not r:
                continue
            ttm = [x for x in q if r['t'] - 1.0 < x['t'] <= r['t'] + 1e-9]
            ni4 = sum(x['ni'] for x in ttm) * (4.0 / max(1, len(ttm)))
            out[f'{tk} ROE y{y}'] = ni4 / r['eq'] if r['eq'] > 0 else float('nan')
            out[f'{tk} margin y{y}'] = r['om']
            out[f'{tk} eq/GDP% y{y}'] = 100 * r['eq'] / gdp(run, r['t'])
            out[f'{tk} D/E y{y}'] = r['debt'] / r['eq'] if r['eq'] > 0 else float('nan')
        out[f'{tk} covenant qtrs'] = sum(1 for x in q if x.get('ndE') is not None and x.get('covL') and x['ndE'] > x['covL'])
        out[f'{tk} crisis qtrs (icr<1)'] = sum(1 for x in q if x.get('icr') is not None and x['icr'] < 1)
        out[f'{tk} dead'] = int(bool(f.get('dead')))
        out[f'{tk} default/reorg'] = sum(tk in str(d) for d in list(run.get('defaults', [])) + list(run.get('reorgs', [])))
        out[f'{tk} rating y20'] = (at(q, 20) or {}).get('rating')
    return out


def table(a, b, fn, title):
    ra, rb = load(a), load(b)
    seeds = sorted(set(ra) & set(rb))
    ma = {s: fn(ra[s]) for s in seeds}
    mb = {s: fn(rb[s]) for s in seeds}
    print(f'{title}\n{"":38}{a:>9}{b:>9}   {b}-{a} (se) t n')
    for k in ma[seeds[0]]:
        xa = [ma[s].get(k) for s in seeds]
        xb = [mb[s].get(k) for s in seeds]
        if any(isinstance(x, str) or x is None for x in xa + xb):
            print(f'  {k:36} {" ".join(map(str, xa)):>9} | {" ".join(map(str, xb))}')
            continue
        print(f'  {k:36}{st.mean(xa):9.3f}{st.mean(xb):9.3f}   {fmt(paired(xa, xb))}')


if __name__ == '__main__':
    args = [x for x in sys.argv[1:] if not x.startswith('--')]
    if '--firms' in sys.argv:
        table(args[0], args[1], lenders, 'Lender rows TALN/STRK/POOL (per-seed mean)')
    else:
        table(args[0], args[1], measures, 'Roll, champions, dividend borrowing, SHRK')
