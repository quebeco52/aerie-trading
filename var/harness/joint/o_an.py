#!/usr/bin/env python3
"""o_an.py <seeds> [armA armB live]: SWAN capacity rule on a replayed macro path. armB - armA paired by seed
(default rpSW - rpNS, both replaying runs/recNS-<seed>.macro.gz). Board = every name but SWAN/POOL alive with >= 70
quarters in that run (as n_an.py run_row: cap from quarter index 3 to the last common one). Prints the paired
table (n, mean A, mean B, mean diff, se, t, sd and range of the diff), the per-firm market-cap growth split, SWAN's
own state per arm, and the live recording's board CAGR next to the replayed armA."""
import json, math, os, re, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))


def seedlist(s):
    out = []
    for part in s.split(','):
        a, _, b = part.partition('-')
        out += list(range(int(a), int(b or a) + 1))
    return out


def cagr(a, b, yrs):
    return 100 * ((b / a) ** (1 / yrs) - 1) if a > 0 and b > 0 and yrs > 0 else float('nan')


def load(arm, seed):
    return json.loads(open(f'{H}/runs/{arm}-{seed}.jsonl').readline())


def board(r):
    names = {tk: v['q'] for tk, v in r['final'].items() if tk not in ('SWAN', 'POOL') and len(v['q']) >= 70}
    n = min(len(q) for q in names.values())
    agg = lambda k, sl: sum(sum((x[k] or 0) for x in q[sl]) for q in names.values())
    first, last = slice(0, 4), slice(n - 4, n)
    cap = lambda i: sum(q[i]['price'] * q[i]['shares'] for q in names.values())
    yrs = st.mean(q[n - 1]['t'] for q in names.values()) - st.mean(q[0]['t'] for q in names.values())
    c0, c1 = cap(3), cap(n - 1)
    ni1 = agg('ni', last)
    eq0, eq1 = sum(q[3]['eq'] for q in names.values()), sum(q[n - 1]['eq'] for q in names.values())
    firm = {tk: cagr(q[3]['price'] * q[3]['shares'], q[n - 1]['price'] * q[n - 1]['shares'],
                     q[n - 1]['t'] - q[3]['t']) for tk, q in names.items()}
    sw = r['final']['SWAN']['q']
    swc = sw[-1]['price'] * sw[-1]['shares']
    T = []
    for i in range(7, len(sw)):
        w = sw[i - 3:i + 1]; me = st.mean(x['eq'] for x in w)
        if me > 0 and sw[i]['t'] >= 2:
            T.append(sum(x['ni'] for x in w) / me)
    ni = sum(x['ni'] for x in sw)
    ex = [e for e in r['events'].get('SWAN', []) if re.match(r'Issued \$[\d.]+B in bonds for expansion', e['d'])]
    row = {
        'board mkt-cap CAGR %': cagr(c0, c1, yrs),
        'board TTM NI CAGR %': cagr(agg('ni', first), ni1, yrs),
        'board TTM revenue CAGR %': cagr(agg('rev', first), agg('rev', last), yrs),
        'board book equity CAGR %': cagr(eq0, eq1, yrs),
        'board P/E end': c1 / ni1, 'board P/B end': c1 / eq1,
        'SWAN TTM ROE mean y2+ %': 100 * st.mean(T) if T else float('nan'),
        'SWAN D/E y20': sw[-1]['debt'] / sw[-1]['eq'] if sw[-1]['eq'] > 0 else float('nan'),
        'SWAN (div+bb)/NI': sum(x['div'] + x['bb'] for x in sw) / ni if ni > 0 else float('nan'),
        'SWAN expansion bonds': len(ex),
        'SWAN cap share of board+SWAN end %': 100 * swc / (c1 + swc),
    }
    return row, firm


args = sys.argv[1:]
seeds = seedlist(args[0])
A, B, LIVE = (args[1:4] + ['rpNS', 'rpSW', 'recNS'][len(args[1:4]):])
seeds = [s for s in seeds if all(os.path.exists(f'{H}/runs/{a}-{s}.jsonl') for a in (A, B))]
ra, rb, fd, rl = {}, {}, [], {}
for s in seeds:
    ra[s], fa = board(load(A, s)); rb[s], fb = board(load(B, s))
    fd.append({tk: fb[tk] - fa[tk] for tk in fa if tk in fb and not math.isnan(fa[tk] - fb[tk])})
    if os.path.exists(f'{H}/runs/{LIVE}-{s}.jsonl'):
        rl[s] = board(load(LIVE, s))[0]

print(f'seeds {seeds[0]}-{seeds[-1]} (n={len(seeds)}), {B} - {A} paired by seed\n')
print('| quantity | A mean | B mean | B-A mean (se) | t | sd(diff) | diff range |\n|---|---|---|---|---|---|---|')
for k in ra[seeds[0]]:
    pairs = [(ra[s][k], rb[s][k]) for s in seeds if not (math.isnan(ra[s][k]) or math.isnan(rb[s][k]))]
    d = [b - a for a, b in pairs]
    if len(d) < 2:
        continue
    se = st.stdev(d) / math.sqrt(len(d))
    print(f'| {k} | {st.mean(a for a, _ in pairs):.3g} | {st.mean(b for _, b in pairs):.3g} | {st.mean(d):+.3g} ({se:.2g})'
          f' | {st.mean(d) / se if se else float("nan"):+.1f} | {st.stdev(d):.2g} | {min(d):+.3g}..{max(d):+.3g} |')

lower = [sum(v < 0 for v in f.values()) / len(f) for f in fd]
pooled = [v for f in fd for v in f.values()]
med = [st.median(f.values()) for f in fd]
print(f'\nper-firm mkt-cap CAGR, {B} - {A}: firms/seed {st.mean(len(f) for f in fd):.0f}; share lower in {B}'
      f' {100 * st.mean(lower):.0f}% (per-seed range {100 * min(lower):.0f}-{100 * max(lower):.0f}%, se'
      f' {100 * st.stdev(lower) / math.sqrt(len(lower)):.1f}); median diff pooled {st.median(pooled):+.3f}pp, mean of'
      f' per-seed medians {st.mean(med):+.3f} (se {st.stdev(med) / math.sqrt(len(med)):.3f})')
tks = sorted(set.intersection(*(set(f) for f in fd)))
avg = sorted(((st.mean(f[tk] for f in fd), tk) for tk in tks))
print('firms with the largest mean diff: lowest ' + ', '.join(f'{tk} {v:+.2f}' for v, tk in avg[:5])
      + '; highest ' + ', '.join(f'{tk} {v:+.2f}' for v, tk in avg[-5:]))

if rl:
    k = 'board mkt-cap CAGR %'
    ss = sorted(rl)
    d = [ra[s][k] - rl[s][k] for s in ss]
    print(f'\nreplay sanity, {k}: live {LIVE} {st.mean(rl[s][k] for s in ss):.3f} (sd {st.stdev(rl[s][k] for s in ss):.2f}),'
          f' replayed {A} {st.mean(ra[s][k] for s in ss):.3f} (sd {st.stdev(ra[s][k] for s in ss):.2f}); {A} - {LIVE}'
          f' {st.mean(d):+.3f} (se {st.stdev(d) / math.sqrt(len(d)):.3f}, range {min(d):+.2f}..{max(d):+.2f})')
    print('per seed live/replay: ' + ', '.join(f'{s}: {rl[s][k]:.2f}/{ra[s][k]:.2f}' for s in ss))
