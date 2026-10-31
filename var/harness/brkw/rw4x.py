#!/usr/bin/env python3
"""rw4x.py <seeds> A B: held-firm and redemption extras for the pro-rata redemption change (rw4). Per held firm from
the OUT record's report rows (split-adjusted): cash returned beyond the dividend per quarter / pre-ex price (redemption
in rw4, buyback cash in earlier arms), TTM EPS and DPS CAGR y1->y25, treasury, total-return vol, split-adjusted price
y10/y25, TSR (price + dividends + redemptions) annualised, equity roll residual |deq - (ni - div - bb)| / eq. BRKW end
row: redemption received / fraction x cash (clean ticks), tax on redemptions. Means over the six held firms per seed;
paired B - A. Final table only."""
import json, math, sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'lib'))
from stats import paired, fmt
from analyse import seeds, H

HELD = ['ALBT', 'CBIL', 'ALCA', 'KSTL', 'BIRD', 'ERNE']


def split_adjust(q):
    """Report rows with a cumulative split factor: a share count jump over 1.4x between reports is a split."""
    f, out, prev = 1.0, [], None
    for r in q:
        if prev is not None and r['shares'] > 1.4 * prev['shares']:
            f *= r['shares'] / prev['shares']
        out.append((r, f))
        prev = r
    return out


def at(q, y):
    return min(q, key=lambda x: abs(x[0]['t'] - y))


def firm(q, redeems):
    q = [r for r in q if r.get('price', 0) > 0 and r.get('shares', 0) > 0]
    sq = split_adjust(q)
    o = {}
    rr, tr, prev = [], [], None
    for r, f in sq:
        cps = (r['div'] + (r['bb'] if redeems else 0.0)) / r['shares']
        extra = r['bb'] / r['shares']
        rr.append(extra / (r['price'] + cps))
        if prev is not None:
            pp = prev[0]['price'] * prev[1] / f  # previous price in this row's share units
            tr.append(math.log((r['price'] + cps) / pp))
        prev = (r, f)
    o['ret/px q mean'] = sum(rr) / len(rr)
    o['ret/px q max'] = max(rr)
    def ttm(i, k):
        rows = sq[i:i + 4]
        return sum(r[k] / r['shares'] * f for r, f in rows)  # per opening-unit share
    yrs = sq[-4][0]['t'] - sq[0][0]['t']
    for k, name in (('ni', 'EPS'), ('div', 'DPS')):
        a, b = ttm(0, k), ttm(len(sq) - 4, k)
        o[f'{name} CAGR'] = (b / a) ** (1 / yrs) - 1 if a > 0 and b > 0 else math.nan
    o['cash/eq mean'] = sum(r['cash'] / r['eq'] for r, _ in sq if r['eq'] > 0) / len(sq)
    o['cash y25 $bn'] = sq[-1][0]['cash'] / 1e9
    m = sum(tr) / len(tr)
    o['TR vol'] = math.sqrt(sum((x - m) ** 2 for x in tr) / (len(tr) - 1) * 4)
    p0 = sq[0][0]['price']
    o['px y10/p0'] = at(sq, 10)[0]['price'] * at(sq, 10)[1] / p0
    o['px y25/p0'] = sq[-1][0]['price'] * sq[-1][1] / p0
    o['TSR ann'] = math.exp(sum(tr) / (sq[-1][0]['t'] - sq[0][0]['t'])) - 1
    res = [abs(b['eq'] - a['eq'] - (b['ni'] - b['div'] - b['bb'])) / a['eq'] for (a, _), (b, _) in zip(sq, sq[1:]) if a['eq'] > 0]
    o['eq roll |resid|/eq'] = sum(res) / len(res)
    return o


def one(arm, s):
    out = json.loads(open(H / f'{arm}-{s}.jsonl').readline())
    end, hrep, red, redt = {}, [], [], []
    for line in open(H / f'{arm}-{s}.brkw.jsonl'):
        r = json.loads(line)
        if r['kind'] == 'end':
            end = r
        elif r['kind'] == 'hrep':
            hrep.append(r)
        elif r['kind'] == 'red':
            red.append(r)
        elif r['kind'] == 'redtick':
            redt.append(r)
    redeems = 'red_exp' in end
    per = [firm(out['final'][t]['q'], redeems) for t in HELD if t in out['final'] and not out['final'][t].get('dead')]
    o = {k: sum(p[k] for p in per) / len(per) for k in per[0]}
    o['TSR ann ALBT'] = firm(out['final']['ALBT']['q'], redeems)['TSR ann']
    # Per clean tick from the redtick rows; a held firm's split on the tick (paid_in > 5x expected) is dropped, as
    # the harness before 10-31 (6b) read the split's shares as a subscription.
    rt = [x for x in redt if x['paid_in'] < 5 * x['exp'] or abs((x['dcarry'] + x['paid_in']) / x['exp'] - 1) < 1e-6]
    o['red recv/exp'] = sum(x['dcarry'] + x['paid_in'] for x in rt) / sum(x['exp'] for x in rt) if rt else math.nan
    o['red recv/exp worst tick'] = max((abs((x['dcarry'] + x['paid_in']) / x['exp'] - 1) for x in rt), default=math.nan)
    o['red split ticks dropped'] = len(redt) - len(rt) if redeems else math.nan
    o['red tax $bn'] = end['red_tax'] / 1e9 if redeems else math.nan
    o['red tax/exp'] = end['red_tax'] / end['red_exp_all'] if end.get('red_exp_all') else math.nan
    o['red exp all $bn'] = end['red_exp_all'] / 1e9 if redeems else math.nan
    o['red recv kept $bn'] = sum(x['dcarry'] + x['paid_in'] for x in rt) / 1e9 if redeems else math.nan
    o['red ticks skipped'] = end['red_skip'] / (end['red_skip'] + end['red_n']) if redeems and end['red_n'] else math.nan
    # Redemption cash = per-share fact x shares of record; equity roll with stock comp (rw4 hrep rows).
    o['red fact/cash'] = sum(r['ps'] * r['sh_rec'] for r in red if r.get('ps')) / sum(r['cash'] for r in red if r.get('ps')) if red else math.nan
    o['red w/o fact'] = sum(1 for r in red if not r.get('ps')) if redeems else math.nan
    by = {}
    for r in hrep:
        by.setdefault(r['ticker'], []).append(r)
    res = [abs(b['eq'] - a['eq'] - (b['ni'] + b['sbc'] - b['div'] - b['bb'])) / max(1.0, b['bb'] + b['div'])
           for rows in by.values() for a, b in zip(rows, rows[1:]) if b['sh'] < 1.4 * a['sh']]
    o['eq roll+sbc resid/payout'] = sorted(res)[len(res) // 2] if res else math.nan
    o['eq roll+sbc resid p90'] = sorted(res)[int(len(res) * 0.9)] if res else math.nan
    return o


def main():
    ss, (A, B) = seeds(sys.argv[1]), sys.argv[2:4]
    r = {a: {s: one(a, s) for s in ss} for a in (A, B)}
    print(f'held firms (mean of 6) + redemptions, seeds {sys.argv[1]}; mean {A} | {B}; {B} per seed; paired {B}-{A}: d (se) t n')
    for k in r[B][ss[0]]:
        a = [r[A][s][k] for s in ss]
        b = [r[B][s][k] for s in ss]
        ma = sum(a) / len(a)
        mb = sum(b) / len(b)
        per = ' '.join(f'{x:.3g}' for x in b)
        d = paired(a, b) if not any(math.isnan(x) for x in a + b) else None
        print(f'{k:24s} {ma:9.3g} {mb:9.3g}  {per:34s} {fmt(d) if d else "-"}')


if __name__ == '__main__':
    main()
