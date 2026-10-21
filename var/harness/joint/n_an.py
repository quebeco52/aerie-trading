#!/usr/bin/env python3
"""n_an.py <arm:seeds> ... [-- <arm:seeds> ...]: where the board's slower market-cap growth in the SWAN-capacity arms
comes from. Board = every name but SWAN/POOL alive all run. Splits market-cap CAGR into TTM net-income, revenue and
book-equity growth and the P/E and P/B change (first vs last four quarters), next to macro means (nominal GDP CAGR,
10y yield, policy rate, erp, vol, spread, sovereign-fund ownership). Arms before '--' form group A, after it group B;
prints per-arm means and the B-A difference (unpaired, se from both groups' seed spread)."""
import json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))


def seedlist(s):
    out = []
    for part in s.split(','):
        a, _, b = part.partition('-')
        out += list(range(int(a), int(b or a) + 1))
    return out


def cagr(a, b, yrs):
    return 100 * ((b / a) ** (1 / yrs) - 1) if a > 0 and b > 0 else float('nan')


def run_row(arm, seed):
    r = json.loads(open(f'{H}/runs/{arm}-{seed}.jsonl').readline())
    names = {tk: v['q'] for tk, v in r['final'].items() if tk not in ('SWAN', 'POOL') and len(v['q']) >= 70}
    n = min(len(q) for q in names.values())
    agg = lambda k, sl: sum(sum((x[k] or 0) for x in q[sl]) for q in names.values())
    first, last = slice(0, 4), slice(n - 4, n)
    cap = lambda i: sum(q[i]['price'] * q[i]['shares'] for q in names.values())
    yrs = st.mean(q[n - 1]['t'] for q in names.values()) - st.mean(q[0]['t'] for q in names.values())
    c0, c1 = cap(3), cap(n - 1)
    ni0, ni1 = agg('ni', first), agg('ni', last)
    eq0, eq1 = sum(q[3]['eq'] for q in names.values()), sum(q[n - 1]['eq'] for q in names.values())
    sw = r['final']['SWAN']['q']
    macro = sorted((json.loads(l) for l in open(f'{H}/runs/{arm}-{seed}.macro.jsonl') if l.strip()), key=lambda m: m['t'])
    my = macro[-1]['t'] - macro[0]['t']
    mm = lambda k: st.mean(m[k] for m in macro if m.get(k) is not None)
    return {
        'mkt-cap CAGR %': cagr(c0, c1, yrs),
        'TTM NI CAGR %': cagr(ni0, ni1, yrs),
        'TTM revenue CAGR %': cagr(agg('rev', first), agg('rev', last), yrs),
        'book equity CAGR %': cagr(eq0, eq1, yrs),
        'P/E start': c0 / ni0, 'P/E end': c1 / ni1,
        'P/B start': c0 / eq0, 'P/B end': c1 / eq1,
        'board payout/NI': (agg('div', slice(0, n)) + agg('bb', slice(0, n))) / agg('ni', slice(0, n)),
        'SWAN cap share of board+SWAN end %': 100 * sw[-1]['price'] * sw[-1]['shares'] / (c1 + sw[-1]['price'] * sw[-1]['shares']),
        'SWAN payouts $B': sum((x['div'] or 0) + (x['bb'] or 0) for x in sw) / 1e9,
        'nominal GDP CAGR %': cagr(macro[0]['nominal_gdp'], macro[-1]['nominal_gdp'], my),
        'y10 mean %': 100 * mm('y10'), 'policy mean %': 100 * mm('policy'), 'erp mean %': 100 * mm('erp'),
        'vol mean': mm('vol'), 'spread mean %': 100 * mm('spread'), 'gap mean %': 100 * mm('gap'),
        'fund_own end': macro[-1].get('fund_own'), 'y10 end %': 100 * macro[-1]['y10'],
    }


args = sys.argv[1:]
split = args.index('--') if '--' in args else len(args)
groups = {'A': args[:split], 'B': args[split + 1:]}
rows = {g: [] for g in groups}
for g, specs in groups.items():
    for spec in specs:
        arm, s = spec.split(':')
        arm_rows = [run_row(arm, seed) for seed in seedlist(s) if os.path.exists(f'{H}/runs/{arm}-{seed}.jsonl')]
        rows[g] += arm_rows
        print(f'{g} {arm} {s}: n={len(arm_rows)}', ' '.join(f"{k.split()[0]}={st.mean(r[k] for r in arm_rows):.3g}" for k in ('mkt-cap CAGR %', 'TTM NI CAGR %', 'nominal GDP CAGR %')))
keys = list(rows['A'][0])
print('\n| quantity | A mean (sd) | B mean (sd) | B-A (se) | t |\n|---|---|---|---|---|')
for k in keys:
    a = [r[k] for r in rows['A'] if r[k] is not None and not math.isnan(r[k])]
    b = [r[k] for r in rows['B'] if r[k] is not None and not math.isnan(r[k])]
    if len(a) < 2 or len(b) < 2:
        continue
    d = st.mean(b) - st.mean(a); se = math.sqrt(st.variance(a) / len(a) + st.variance(b) / len(b))
    print(f'| {k} | {st.mean(a):.3g} ({st.stdev(a):.2g}) | {st.mean(b):.3g} ({st.stdev(b):.2g}) | {d:+.3g} ({se:.2g}) | {d / se if se else float("nan"):+.1f} |')
