#!/usr/bin/env python3
"""analyse.py <arm> <seeds> [tag] [focus tickers]: realised vs configured vol and, if the probe ran, the channel split.

Reads runs/<arm>-<seed><tag>.pv.json. Pools each name's windows over seeds. Prints:
  1. realised/configured by horizon (1, 21, 63 ticks): board median ratio (per-seed mean and se), share > 1.1x,
     the market index's vol against MACRO_VOL_BASE_ANCHOR, mean and rms macro marketVolatility;
  2. (probe) excess variance by channel group at 1 and 21 ticks: realised Cov(channel, total) less the variance the
     engine budgets for it, board mean / median and its share of the board's excess, plus focus names.
"""
import json
import math
import statistics as st
import sys
from pathlib import Path

H = Path(__file__).resolve().parent
sys.path.insert(0, str(H.parent / 'lib'))
from stats import mean_se  # noqa: E402

arm = sys.argv[1]
seeds = []
for part in sys.argv[2].split(','):
    if '-' in part:
        a, b = part.split('-')
        seeds += list(range(int(a), int(b) + 1))
    else:
        seeds.append(int(part))
tag = sys.argv[3] if len(sys.argv) > 3 else ''
focus = sys.argv[4].split(',') if len(sys.argv) > 4 else ['TERN']

runs = {}
for s in seeds:
    p = H / 'runs' / f'{arm}-{s}{tag}.pv.json'
    if p.exists():
        runs[s] = json.loads(p.read_text())
if not runs:
    sys.exit('no runs')
r0 = next(iter(runs.values()))
tpy = r0['tpy']
sigma = r0['sigma']
anchor = r0['anchor'] or 0.15
hs = sorted(int(h) for h in r0['lite'])


def wvar(n, s, ss, h):
    return (ss / n - (s / n) ** 2) * tpy / h if n > 1 else float('nan')


print(f'arm {arm}  seeds {",".join(map(str, runs))}{tag}  tpy {tpy}  skip {r0["skip"]} ticks  anchor {anchor}')
print('\n1. Realised / configured vol, end-of-tick prices (split-adjusted), windows pooled per name over seeds')
print(f'{"h":>4} {"med ratio":>9} {"seed-mean (se)":>16} {">1.1x":>6} {"med rv":>7} {"med cfg":>7} {"names":>5} {"mkt vol":>8} {"mkt/anchor":>10}')
ratios_by_h = {}
for h in hs:
    pooled = {}
    per_seed_med = []
    mk = [0, 0.0, 0.0]
    for s, r in runs.items():
        lite = r['lite'][str(h)]
        rs = []
        for tk, w in lite.items():
            if tk == '__MKT__':
                mk[0] += w['n']; mk[1] += w['s']; mk[2] += w['ss']
                continue
            if w['n'] < 4 or tk not in sigma:
                continue
            a = pooled.setdefault(tk, [0, 0.0, 0.0])
            a[0] += w['n']; a[1] += w['s']; a[2] += w['ss']
            rs.append(math.sqrt(max(0.0, wvar(w['n'], w['s'], w['ss'], h))) / sigma[tk])
        per_seed_med.append(st.median(rs))
    rat = {tk: math.sqrt(max(0.0, wvar(*a, h))) / sigma[tk] for tk, a in pooled.items()}
    ratios_by_h[h] = rat
    n, m, se = mean_se(per_seed_med)
    rv = [math.sqrt(max(0.0, wvar(*a, h))) for a in pooled.values()]
    mv = math.sqrt(wvar(*mk, h))
    print(f'{h:>4} {st.median(rat.values()):>9.3f} {m:>9.3f} ({se:.3f}) {sum(x > 1.1 for x in rat.values()) / len(rat):>6.0%} '
          f'{st.median(rv):>7.3f} {st.median(sigma[t] for t in pooled):>7.3f} {len(rat):>5} {mv:>8.3f} {mv / anchor:>10.2f}')
mv_n = sum(r['mvol']['n'] for r in runs.values())
mv_mean = sum(r['mvol']['s'] for r in runs.values()) / mv_n
mv_rms = math.sqrt(sum(r['mvol']['ss'] for r in runs.values()) / mv_n)
print(f'macro marketVolatility: mean {mv_mean:.4f}, rms {mv_rms:.4f}  (anchor {anchor})')
for tk in focus:
    print(f'  {tk}: cfg {sigma.get(tk, float("nan")):.3f}  ratio ' + '  '.join(f'h{h} {ratios_by_h[h].get(tk, float("nan")):.2f}' for h in hs))

probes = {s: r['probe'] for s, r in runs.items() if r.get('probe')}
if not probes:
    sys.exit(0)
names = next(iter(probes.values()))['names']
K = 14
D = len(names)
idx = {n: i for i, n in enumerate(names)}
GROUPS = [
    ('sys diff+fund', ['mkt', 'fund'], 'sysAnchor'),
    ('mkt jump', ['jS'], 'sysJ'),
    ('idio+sector', ['idio', 'sec'], 'idioB'),
    ('own jump', ['jI'], 'ownJc'),
    ('flow+pol+deal', ['flow', 'pol', 'ma'], 'impc'),
    ('warn+report', ['warn', 'rep'], 'annc'),
    ('pull', ['pull'], None),
    ('breaker', ['brk'], None),
    ('between', ['betw'], None),
]

# Pool windows and audit per name over seeds.
audit = {}
win = {}
meta = {}
for s, p in probes.items():
    meta.update(p['meta'])
    for tk, a in p['audit'].items():
        A = audit.setdefault(tk, {})
        for k, v in a.items():
            A[k] = A.get(k, 0.0) + v
    for h, byt in p['win'].items():
        for tk, w in byt.items():
            W = win.setdefault(int(h), {}).setdefault(tk, [0, [0.0] * D, [0.0] * D * D])
            W[0] += w['n']
            W[1] = [x + y for x, y in zip(W[1], w['S'])]
            W[2] = [x + y for x, y in zip(W[2], w['M'])]
am = {tk: {k: v / a['n'] for k, v in a.items()} for tk, a in audit.items()}
for tk, a in am.items():
    a['idioB'] = a['vi'] - a['ownJc'] - a['impc'] - a['annc']
    a['budget'] = a['sysAnchor'] + a['sysJ'] + a['vi']


def channel_cov(h, tk):
    n, S, M = win[h][tk]
    cov = [[(M[i * D + j] / n - S[i] * S[j] / n / n) * tpy / h for j in range(D)] for i in range(D)]
    tot = sum(cov[i][j] for i in range(K) for j in range(K))
    ctot = [sum(cov[i][j] for j in range(K)) for i in range(D)]
    return n, tot, ctot, cov


print('\n2. Excess variance by channel: Cov(channel, total) less the engine\'s budget for it (annual variance units)')
print('   budget: sys = (beta anchor)^2, mkt jump = systemic jump var, idio = V_I - own sources (capped),')
print('   own jump / flow / announcements = capped amounts deducted from theta; pull, breaker, between = 0')
for h in (1, 21):
    if h not in win:
        continue
    rows = {}
    for tk in win[h]:
        if tk not in am or win[h][tk][0] < 20:
            continue
        n, tot, ctot, cov = channel_cov(h, tk)
        a = am[tk]
        g = {}
        for gname, chans, bkey in GROUPS:
            real = sum(ctot[idx[c]] for c in chans)
            g[gname] = (real, a[bkey] if bkey else 0.0)
        rows[tk] = (tot, a['budget'], meta[tk]['sigma'] ** 2, g, cov, ctot)
    ex = [r[0] - r[2] for r in rows.values()]
    print(f'\n h={h}: names {len(rows)}; board mean realised var {st.mean(r[0] for r in rows.values()):.4f}, configured sigma^2 '
          f'{st.mean(r[2] for r in rows.values()):.4f}, engine budget {st.mean(r[1] for r in rows.values()):.4f}; '
          f'mean excess over sigma^2 {st.mean(ex):+.4f} (median {st.median(ex):+.4f})')
    print(f'   {"group":<15} {"realised":>9} {"budget":>8} {"excess":>8} {"median":>8} {"share":>6}' + ''.join(f'  {t + " ex":>9}' for t in focus))
    tot_ex = st.mean(r[0] - r[1] for r in rows.values())
    for gname, _, _ in GROUPS:
        re = [r[3][gname][0] for r in rows.values()]
        bu = [r[3][gname][1] for r in rows.values()]
        e = [x - y for x, y in zip(re, bu)]
        fc = ''.join(f'  {rows[t][3][gname][0] - rows[t][3][gname][1]:>+9.4f}' if t in rows else f'  {"-":>9}' for t in focus)
        print(f'   {gname:<15} {st.mean(re):>9.4f} {st.mean(bu):>8.4f} {st.mean(e):>+8.4f} {st.median(e):>+8.4f} {st.mean(e) / tot_ex if tot_ex else 0:>6.0%}{fc}')
    fc = ''.join(f'  {rows[t][0] - rows[t][1]:>+9.4f}' if t in rows else '' for t in focus)
    print(f'   {"total vs budget":<15} {st.mean(r[0] for r in rows.values()):>9.4f} {st.mean(r[1] for r in rows.values()):>8.4f} {tot_ex:>+8.4f}{"":>16}{fc}')
    fcs = ''.join(f'  {rows[t][1] - rows[t][2]:>+9.4f}' if t in rows else '' for t in focus)
    print(f'   {"budget-sigma^2":<15} {"":>9} {"":>8} {st.mean(r[1] - r[2] for r in rows.values()):>+8.4f}{"":>16}{fcs}')
    # Context: own variances of the big transient-free channels and the d log FV column.
    print(f'   own Var:' + ''.join(f' {c} {st.mean(r[4][idx[c]][idx[c]] for r in rows.values()):.4f}' for c in ('pull', 'rep', 'warn', 'flow', 'pol', 'ma', 'jI', 'jS', 'dfv')))
    print(f'   Cov(dFV, total) {st.mean(r[5][idx["dfv"]] for r in rows.values()):.4f}  Var(dFV) {st.mean(r[4][idx["dfv"]][idx["dfv"]] for r in rows.values()):.4f}')

print('\n3. Budget inputs, board means (time-averaged, annual variance)')
keys = ['vi', 'cap', 'scale', 'sysAnchor', 'sysVar', 'sysDiff', 'sysJ', 'ownJ', 'ownJc', 'imp', 'impc', 'ann', 'annc', 'fundV', 'theta', 'idioVar', 'mvol', 'brkB', 'floorB']
print('   board ' + '  '.join(f'{k} {st.mean(a[k] for a in am.values()):.4g}' for k in keys))
print('   share of names with uncapped > cap: ann {:.0%}, imp {:.0%}, ownJ {:.0%}'.format(
    st.mean(a['ann'] > a['cap'] for a in am.values()), st.mean(a['imp'] > a['cap'] for a in am.values()), st.mean(a['ownJ'] > a['cap'] for a in am.values())))
for tk in focus:
    if tk in am:
        print(f'   {tk:<6}' + '  '.join(f'{k} {am[tk][k]:.4g}' for k in keys))
