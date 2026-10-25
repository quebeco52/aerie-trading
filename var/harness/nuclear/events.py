#!/usr/bin/env python3
"""FIRE accident event study pooled over seeds, against a placebo of non-accident quarters. Usage: events.py [arm].

Revenue lines at the onset quarter (h 0) and 4 quarters later (h 4) as a ratio to their own 4-quarter pre-onset mean,
less trend growth (the firm's revenue CAGR in that seed over the elapsed time); margin in pp against the pre mean;
price over the board index from the tick before the onset report to +1, +2, +4, +8 quarters. The placebo takes every
quarter whose window holds no accident quarter. Also: accident and overrun spells, driver means by seed.
"""
import json, math, statistics as st, sys
from pathlib import Path

arm = sys.argv[1] if len(sys.argv) > 1 else 'fix'
P = Path(__file__).parent
files = sorted(P.glob(f'runs/{arm}-s[0-9]*.json'), key=lambda p: int(p.stem.split('-s')[-1]))
S3 = ('fuel', 'plant_services', 'new_build')
EV, PL = {}, {}
spells, ovr_spells, per_seed = [], [], []
n_acc = []


def ms(xs):
    xs = [x for x in xs if x == x]
    return (st.fmean(xs), st.stdev(xs) / math.sqrt(len(xs)), len(xs)) if len(xs) > 1 else (xs[0] if xs else float('nan'), float('nan'), len(xs))


def measures(q, daily, tpy, i, g, om, orders):
    out = {}
    pre = range(i - 4, i)
    for h in (0, 4):
        if i + h >= len(q):
            continue
        defl = (1 + g) ** ((h + 2.5) / 4)
        for k in S3 + ('rev',):
            xs = [r['streams'][k] if k != 'rev' else r['rev'] for r in q]
            out[f'{k} h{h}'] = xs[i + h] / st.fmean(xs[j] for j in pre) / defl - 1
        out[f'om pp h{h}'] = (om[i + h] - st.fmean(om[j] for j in pre)) * 100
    k0 = max(0, round(q[i]['t'] * tpy) - 2)
    for hq in (1, 2, 4, 8):
        k1 = k0 + hq * tpy // 4
        if k1 < len(daily):
            out[f'xret +{hq}q'] = (daily[k1]['FIRE'] / daily[k0]['FIRE']) / (daily[k1]['i'] / daily[k0]['i']) - 1
    return out


for p in files:
    d = json.load(open(p))
    q, daily = d['q']['FIRE'], d['daily']
    ny = len(q) // 4
    tpy = round(len(daily) / ny)
    ann = [sum(r['rev'] for r in q[4 * y:4 * y + 4]) for y in range(ny)]
    g = (ann[-1] / ann[0]) ** (1 / (ny - 1)) - 1
    om = [r['om'] for r in q]
    orders = [r['kpi']['book_to_bill'] > 0 for r in q]
    acc = [r['regime'].get('state:regime:reactor_accident', 0) for r in q]
    ovr = [r['regime'].get('state:regime:plant_overrun', 0) for r in q]
    onsets = [i for i in range(len(q)) if acc[i] == 1]
    n_acc.append(len(onsets))
    for arr, out in ((acc, spells), (ovr, ovr_spells)):
        run = 0
        for a in arr + [0]:
            if a > 0:
                run += 1
            elif run:
                out.append(run); run = 0
    for i in onsets:
        if i < 4:
            continue
        m = measures(q, daily, tpy, i, g, om, orders)
        dur = [j for j in range(i, len(q)) if acc[j] > 0][:8]
        pre8 = range(max(0, i - 8), i)
        m['orders/yr before'] = 4 * st.fmean(1.0 if orders[j] else 0.0 for j in pre8)
        m['orders/yr during'] = 4 * st.fmean(1.0 if orders[j] else 0.0 for j in dur)
        for k, v in m.items():
            EV.setdefault(k, []).append(v)
    for i in range(4, len(q) - 8):
        if any(acc[j] > 0 for j in range(i - 8, min(len(q), i + 9))):
            continue
        for k, v in measures(q, daily, tpy, i, g, om, orders).items():
            PL.setdefault(k, []).append(v)
    dr = [r['drivers'] for r in q if r.get('drivers')]
    b2b = [r['kpi']['book_to_bill'] for r in q]
    per_seed.append((d['seed'], st.fmean(x['energy'] for x in dr), st.pstdev([x['energy'] for x in dr]),
                     st.fmean(x['financing'] for x in dr), st.pstdev([x['financing'] for x in dr]),
                     st.fmean(x['p'] for x in dr), sum(1 for x in b2b if x > 0) / ny, st.fmean(b2b),
                     st.fmean(r['kpi']['backlog_quarters'] for r in q), st.fmean(1.0 if a else 0.0 for a in acc),
                     st.fmean(1.0 if a else 0.0 for a in ovr)))

print(f'arm {arm}, {len(files)} seeds; accident onsets per run {n_acc} (mean {st.fmean(n_acc):.2f}); '
      f'accident spells (q) {sorted(spells)}; overrun spells (q) {sorted(ovr_spells)}')
print(f'\n{"FIRE accident event study":24s} {"accident mean (se) n":>26s} {"placebo mean (se) n":>26s}')
for k in EV:
    a = ms(EV[k]); b = ms(PL.get(k, []))
    fa = f'{a[0]:+.3f} ({a[1]:.3f}) {a[2]}'
    fb = f'{b[0]:+.3f} ({b[1]:.3f}) {b[2]}' if PL.get(k) else '-'
    print(f'{k:24s} {fa:>26s} {fb:>26s}')
print('\nseed  energy mean/sd  financing mean/sd  p mean  orders/yr  b2b mean  backlog q  accident share  overrun share')
for r in per_seed:
    print(f'{r[0]:4d}  {r[1]:+.3f}/{r[2]:.3f}    {r[3]:+.3f}/{r[4]:.3f}      {r[5]:.3f}   {r[6]:.2f}       {r[7]:.2f}      {r[8]:.2f}       {r[9]:.2f}            {r[10]:.2f}')
cols = list(zip(*per_seed))
print(f'mean  {st.fmean(cols[1]):+.3f}/{st.fmean(cols[2]):.3f}    {st.fmean(cols[3]):+.3f}/{st.fmean(cols[4]):.3f}      '
      f'{st.fmean(cols[5]):.3f}   {st.fmean(cols[6]):.2f}       {st.fmean(cols[7]):.2f}      {st.fmean(cols[8]):.2f}       '
      f'{st.fmean(cols[9]):.2f}            {st.fmean(cols[10]):.2f}')
