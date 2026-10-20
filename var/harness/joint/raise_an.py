#!/usr/bin/env python3
"""raise_an.py <seeds> <arm> [<arm> ...]: share raises and capital for firms with a capital target.
Raise = a quarter whose share count rose 2-20% over the previous report (>=20% is read as a split).
Each raise is classed by the previous quarter's equity/assets against the firm's target (banks: the tuned
TargetCapitalRatio, which for LAKE/RIVR/PLVR omits the requirement add-on; SAFE/DOVE: proxy = their own eq/ta at
the first report, since the insurer target lives in strategy state) and by a loss (NI < 0) in that or the prior
quarter. Also per firm: ROE = sum NI / mean equity per year, averaged; payout = sum(div+bb)/sum(NI); share change
(split-adjusted product of non-split ratios). Mean (se) over seeds, raises as count per arm."""
import json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
arms = sys.argv[2:]
TGT = {'LAKE': .084, 'RIVR': .1031, 'PLVR': .084, 'PERE': .2105, 'PTRL': .1111, 'KING': .2941, 'ROOK': .4667,
       'CORV': .236, 'ACC': .037, 'BNTH': .06, 'EGRT': .1143, 'SAFE': None, 'DOVE': None}


def ms(x, f='{:.3f}'):
    x = [v for v in x if v is not None and not math.isnan(v)]
    if not x:
        return 'n/a'
    s = st.stdev(x) / math.sqrt(len(x)) if len(x) > 1 else float('nan')
    return f.format(st.mean(x)) + ' (' + f.format(s) + ')'


res = {}
for arm in arms:
    for s in seeds:
        p = f'{H}/runs/{arm}-{s}.jsonl'
        if not os.path.exists(p):
            continue
        fin = json.loads(open(p).readline())['final']
        for tk, tgt in TGT.items():
            q = fin.get(tk, {}).get('q', [])
            if len(q) < 8:
                continue
            d = res.setdefault((arm, tk), {'n': 0, 'above': 0, 'below': 0, 'loss': 0, 'sz': [], 'roe': [], 'po': [],
                                           'sh': [], 'seeds': {}})
            t0 = tgt if tgt is not None else q[0]['eq'] / q[0]['ta']
            cum = 1.0
            for i in range(1, len(q)):
                r = q[i]['shares'] / q[i - 1]['shares'] if q[i - 1]['shares'] > 0 else 1.0
                if 0.83 < r < 1.2:
                    cum *= r
                if 1.02 <= r < 1.2:
                    d['n'] += 1
                    d['seeds'][s] = d['seeds'].get(s, 0) + 1
                    d['sz'].append(r - 1)
                    eqta = q[i - 1]['eq'] / q[i - 1]['ta'] if q[i - 1]['ta'] else 0
                    d['above' if eqta >= t0 else 'below'] += 1
                    if q[i]['ni'] < 0 or q[i - 1]['ni'] < 0:
                        d['loss'] += 1
            d['sh'].append(cum - 1)
            roes, ni, po = [], 0.0, 0.0
            for y in range(int(q[-1]['t'])):
                yq = [r for r in q if y <= r['t'] < y + 1]
                if len(yq) >= 3:
                    me = st.mean(r['eq'] for r in yq)
                    if me > 0:
                        roes.append(sum(r['ni'] for r in yq) / me * 4 / len(yq))
            for r in q:
                ni += r['ni']
                po += r['div'] + r['bb']
            d['roe'].append(st.mean(roes) if roes else float('nan'))
            d['po'].append(po / ni if ni > 0 else float('nan'))

print('raises: quarters with shares +2..20% (count over all seeds; above/below target by prior eq/ta; after loss)')
print(f"{'firm':6}" + ''.join(f'{a:>34}' for a in arms))
for tk in TGT:
    cells = []
    for a in arms:
        d = res.get((a, tk))
        cells.append('n/a' if d is None else
                     f"{d['n']} (a{d['above']}/b{d['below']}/L{d['loss']}) sz{(st.mean(d['sz']) if d['sz'] else 0):.3f} s{len(d['seeds'])}")
    print(f'{tk:6}' + ''.join(f'{c:>34}' for c in cells))
print()
print('ROE (NI / mean equity, yearly, averaged) | payout (div+bb)/NI over 20y | share change split-adj')
print(f"{'firm':6}" + ''.join(f'{a:>48}' for a in arms))
for tk in TGT:
    cells = []
    for a in arms:
        d = res.get((a, tk))
        cells.append('n/a' if d is None else f"{ms(d['roe'])} {ms(d['po'], '{:.2f}')} {ms(d['sh'], '{:+.2f}')}")
    print(f'{tk:6}' + ''.join(f'{c:>48}' for c in cells))
