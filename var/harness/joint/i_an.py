#!/usr/bin/env python3
"""i_an.py <seeds> <armA> <armB> [tk,...]: batch I (SWAN and POOL target payout raised so they stop out-retaining
their market). Per firm (default SWAN,POOL) and arm: TTM ROE from y2 (as f_an.py D) and in y2-3 vs the last 2 years,
equity and revenue CAGR, debt/equity y0/5/20, (div+bb)/NI over 20y, dividend yield (4 x div / cap, mean of quarters),
end and worst rating, default seeds, 'Issued $xB in bonds for recapitalization' and '... for expansion' count/$B per
run, EPS beat share (as beat_an.py). Values are mean [min..max] over seeds."""
import json, math, os, re, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
arms = sys.argv[2:4]
FIRMS = sys.argv[4].split(',') if len(sys.argv) > 4 else ['SWAN', 'POOL']
ORDER = ['AAA', 'AA', 'A', 'BBB', 'BB', 'B', 'CCC', 'CC', 'C', 'D']
SRC = open(f'{H}/../../../src/Data/Company/InitialMarket.php').read()


def lore(tk, key):
    i = SRC.index(f"'ticker' => '{tk}'"); j = SRC.find("'ticker' =>", i + 10)
    m = re.search(rf"'{key}' => ([0-9.]+)", SRC[i:j if j > 0 else None])
    return float(m.group(1)) if m else float('nan')


def rank(r):
    base = (r or 'D').rstrip('+-')
    return 3 * (ORDER.index(base) if base in ORDER else 9) + (r.endswith('+') * -1 + r.endswith('-') * 1 if r else 0)


def rng(xs, f='{:.2f}'):
    xs = [x for x in xs if x is not None and not (isinstance(x, float) and math.isnan(x))]
    if not xs:
        return 'n/a'
    return (f + ' [' + f + '..' + f + ']').format(st.mean(xs), min(xs), max(xs))


def ttm(q):
    out = []
    for i in range(7, len(q)):
        w = q[i - 3:i + 1]; me = st.mean(x['eq'] for x in w)
        if me > 0:
            out.append((q[i]['t'], sum(x['ni'] for x in w) / me))
    return out


def at(q, y):
    return min(q, key=lambda x: abs(x['t'] - y)) if y else q[0]


def cagr(a, b, yrs):
    return (b / a) ** (1 / yrs) - 1 if a > 0 and b > 0 and yrs > 0 else float('nan')


for tk in FIRMS:
    print(f"\n{tk} (lore ROE {lore(tk, 'baseline_roe'):.2f})")
    for arm in arms:
        d = {k: [] for k in ('roe', 'roe23', 'roeL', 'eqg', 'revg', 'de0', 'de5', 'de20', 'pay', 'dy', 'rc_n', 'rc_b',
                             'ex_n', 'ex_b', 'beat')}
        end, worst, dft = [], [], 0
        for s in seeds:
            r = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').readline())
            q = r['final'][tk]['q']; T = ttm(q); yrs = q[-1]['t'] - q[0]['t']
            d['roe'].append(st.mean(v for t, v in T))
            d['roe23'].append(st.mean([v for t, v in T if 2 <= t < 4] or [float('nan')]))
            d['roeL'].append(st.mean([v for t, v in T if t >= q[-1]['t'] - 2] or [float('nan')]))
            d['eqg'].append(cagr(q[0]['eq'], q[-1]['eq'], yrs))
            d['revg'].append(cagr(sum(x['rev'] for x in q[:4]), sum(x['rev'] for x in q[-4:]), q[-2]['t'] - q[1]['t']))
            for y in (0, 5, 20):
                x = at(q, y) if y < 20 else q[-1]
                d[f'de{y}'].append(x['debt'] / x['eq'] if x['eq'] > 0 else float('nan'))
            ni = sum(x['ni'] for x in q)
            d['pay'].append(sum(x['div'] + x['bb'] for x in q) / ni if ni > 0 else float('nan'))
            d['dy'].append(st.mean(4 * x['div'] / (x['price'] * x['shares']) for x in q if x['price'] * x['shares'] > 0))
            for key, pat in (('rc', 'recapitalization'), ('ex', 'expansion')):
                ev = [float(m.group(1)) for e in r['events'].get(tk, [])
                      for m in [re.match(rf'Issued \$([\d.]+)B in bonds for {pat}', e['d'])] if m]
                d[key + '_n'].append(len(ev)); d[key + '_b'].append(sum(ev))
            qb = [x for x in q if x['t'] > 1.0 and x.get('rev', 0) > 0 and x['ceps'] != 0]
            d['beat'].append(sum(x['reps'] > x['ceps'] for x in qb) / len(qb) if qb else float('nan'))
            end.append(q[-1]['rating']); worst.append(max((x['rating'] for x in q), key=rank))
            dft += tk in (r['dead'] or []) or tk in (r['reorgs'] or []) or tk in (r['defaults'] or [])
        p = lambda k: rng([100 * x for x in d[k]], '{:.1f}')
        print(f"  {arm:5s} TTM ROE y2+ {p('roe')}%  y2-3 {p('roe23')}%  last 2y {p('roeL')}%")
        print(f"        equity CAGR {p('eqg')}%  revenue CAGR {p('revg')}%  D/E y0 {rng(d['de0'])} y5 {rng(d['de5'])} "
              f"y20 {rng(d['de20'])}")
        print(f"        (div+bb)/NI {rng(d['pay'])}  div yield {p('dy')}%  EPS beat {rng([100 * x for x in d['beat']], '{:.0f}')}%")
        print(f"        ratings end {' '.join(end)} worst {' '.join(worst)}  default seeds {dft}/{len(seeds)}")
        print(f"        bonds recap n {rng(d['rc_n'], '{:.1f}')} ${rng(d['rc_b'], '{:.0f}')}B  expansion n "
              f"{rng(d['ex_n'], '{:.1f}')} ${rng(d['ex_b'], '{:.0f}')}B per run")
