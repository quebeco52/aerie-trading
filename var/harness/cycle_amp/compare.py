#!/usr/bin/env python3
"""Paired comparison of two arms: per-seed targets and guards, B minus A with se and t.
Usage: python3 compare.py A B. Env SEEDS (1-16), BURN (5), YEARS (45)."""
import json, math, os, sys

H = os.path.dirname(os.path.abspath(__file__))
A, B = sys.argv[1], sys.argv[2]
BURN = int(os.environ.get('BURN', 5))
YEARS = int(os.environ.get('YEARS', 45))
ELB = 0.001 + 1e-4          # MacroEngine::EFFECTIVE_LOWER_BOUND + 1e-4
BS_ON = 0.0005              # MacroEngine::BALANCE_SHEET_ACTIVE_THRESHOLD
SAHM = 0.005                # MacroEngine::SAHM_RECESSION_THRESHOLD
per40 = 40.0 / (YEARS - BURN)


def seeds():
    out = []
    for part in os.environ.get('SEEDS', '1-16').split(','):
        a, _, b = part.partition('-')
        out += list(range(int(a), int(b or a) + 1))
    return out


def mean(x): return sum(x) / len(x) if x else float('nan')
def sd(x):
    m = mean(x); return math.sqrt(sum((v - m) ** 2 for v in x) / (len(x) - 1)) if len(x) > 1 else float('nan')


def metrics(arm, seed):
    rows = [json.loads(l) for l in open(f'{H}/runs/{arm}-{seed}.macro.jsonl')]
    if not rows or rows[-1]['t'] < YEARS - 1e-6:
        return None
    lvl = {}
    for r in rows:
        lvl.setdefault(math.ceil(r['t'] - 1e-9), []).append(r['pot'] * (1 + r['gap']))
    gv = [100 * (mean(lvl[y]) / mean(lvl[y - 1]) - 1) for y in range(BURN + 1, YEARS + 1)]
    m = {'growth sd %': sd(gv), 'negative years /40': per40 * sum(v < 0 for v in gv)}
    # Crisis gap fall: gap in the last quarter before the crisis minus the min gap over the next 8 quarters.
    falls, last = [], None
    for i, r in enumerate(rows):
        c = r['crisis_at']
        if c is not None and c > 0 and c != last and c > BURN:
            pre = max((j for j in range(len(rows)) if rows[j]['t'] < c - 1e-9), default=None)
            if pre is not None:
                nxt = [rows[k]['gap'] for k in range(pre + 1, min(pre + 9, len(rows)))]
                if nxt: falls.append(100 * (rows[pre]['gap'] - min(nxt)))
        last = c
    m['crisis gap fall pp'] = mean(falls) if falls else None
    q = [r for r in rows if r['t'] > BURN + 1e-9]
    gap = [r['gap'] for r in q]
    m['gap sd %'] = 100 * sd(gap); m['gap min %'] = 100 * min(gap)
    ua = [100 * mean([r['u'] for r in rows if math.ceil(r['t'] - 1e-9) == y]) for y in range(BURN + 1, YEARS + 1)]
    m['u annual sd pp'] = sd(ua); m['u max %'] = 100 * max(r['u'] for r in q)
    starts, armed = 0, True
    for r in q:
        if armed and r['sahm'] >= SAHM: starts += 1; armed = False
        elif r['sahm'] < SAHM: armed = True
    m['Sahm starts /40'] = per40 * starts
    m['balance sheet on share'] = mean([r['bs_i'] > BS_ON for r in q])
    m['policy at floor share'] = mean([r['policy'] <= ELB for r in q])
    m['inflation mean %'] = 100 * mean([r['infl'] for r in q])
    m['end fund/GDP'] = rows[-1]['fund']; m['end gross debt/GDP'] = rows[-1]['debt']
    return m


TARGETS = ['growth sd %', 'negative years /40', 'crisis gap fall pp']
data = {s: (metrics(A, s), metrics(B, s)) for s in seeds()}
ok = {s: v for s, v in data.items() if v[0] and v[1]}
L = [f'{B} minus {A}, paired by seed, years {BURN + 1}-{YEARS}, seeds ok {len(ok)}/{len(data)}'
     f' (short: {[s for s in data if s not in ok] or "none"})',
     f'{"quantity":26s} {A:>8s} {B:>8s} {"diff":>8s} {"se":>7s} {"t":>6s} {"n":>3s}']
keys = list(next(iter(ok.values()))[0].keys())
for k in keys:
    if k == TARGETS[0]: L.append('-- targets')
    if k == 'gap sd %': L.append('-- guards')
    pa = [v[0][k] for v in ok.values() if v[0][k] is not None]
    pb = [v[1][k] for v in ok.values() if v[1][k] is not None]
    d = [v[1][k] - v[0][k] for v in ok.values() if v[0][k] is not None and v[1][k] is not None]
    se = sd(d) / math.sqrt(len(d)) if len(d) > 1 else float('nan')
    t = mean(d) / se if se and se == se and se > 0 else float('nan')
    L.append(f'{k:26s} {mean(pa):8.3f} {mean(pb):8.3f} {mean(d):8.3f} {se:7.3f} {t:6.2f} {len(d):3d}'
             + (f'  (arm n {len(pa)}/{len(pb)})' if len(pa) != len(d) or len(pb) != len(d) else ''))
print('\n'.join(L))
