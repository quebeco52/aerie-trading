#!/usr/bin/env python3
"""svc_analyse.py A B C D: services repricing shock arms (runs/S<arm>-<seed>.macro.jsonl).
Env SEEDS (1-4), BURN (2 years), YEARS (33). Quarterly samples; '4q' = mean of the last 4 quarter-end samples."""
import json, math, os, sys

H = os.path.dirname(os.path.abspath(__file__))
ARMS = sys.argv[1:] or ['A', 'B', 'C', 'D']
BURN = float(os.environ.get('BURN', 2))


def seeds():
    s = os.environ.get('SEEDS', '1-4')
    out = []
    for p in s.split(','):
        if '-' in p:
            a, b = p.split('-'); out += range(int(a), int(b) + 1)
        else:
            out.append(int(p))
    return out


def mean(x): return sum(x) / len(x)
def sd(x):
    m = mean(x); return math.sqrt(sum((v - m) ** 2 for v in x) / (len(x) - 1))
def corr(a, b):
    ma, mb = mean(a), mean(b)
    num = sum((x - ma) * (y - mb) for x, y in zip(a, b))
    den = math.sqrt(sum((x - ma) ** 2 for x in a) * sum((y - mb) ** 2 for y in b))
    return num / den if den > 0 else float('nan')
def ac(x, k): return corr(x[k:], x[:-k])
def roll4(x): return [mean(x[i - 3:i + 1]) for i in range(3, len(x))]


def load(arm, seed):
    p = f'{H}/runs/S{arm}-{seed}.macro.jsonl'
    rows = [json.loads(l) for l in open(p)]
    return rows


def stats(rows):
    # 4q averages need 3 quarters before the burn point
    allr = rows
    i0 = next(i for i, r in enumerate(allr) if r['t'] > BURN)
    col = lambda k: [r[k] for r in allr]
    out = {}
    sc, cg, hl = col('supercore'), col('core_goods'), col('infl')
    sc4, cg4, hl4 = roll4(sc)[i0 - 3:], roll4(cg)[i0 - 3:], roll4(hl)[i0 - 3:]
    gap = col('gap')[i0:]
    out['svc_mean'] = 100 * mean(sc4); out['svc_sd'] = 100 * sd(sc4)
    out['svc_ac4'] = ac(sc4, 4); out['svc_ac1'] = ac(sc4, 1)
    out['svc_corr_gap-4'] = corr(sc4[4:], gap[:-4])
    out['cg_mean'] = 100 * mean(cg4); out['cg_sd'] = 100 * sd(cg4)
    out['hl_mean'] = 100 * mean(hl4); out['hl_sd'] = 100 * sd(hl4)
    sh = col('svc_shock')[i0:]
    out['shock_sd'] = 100 * sd(sh); out['shock_mean'] = 100 * mean(sh)
    pol = col('policy')[i0:]
    out['pol_mean'] = 100 * mean(pol); out['pol_sd'] = 100 * sd(pol)
    out['floor_share'] = sum(1 for p in pol if p <= 0.0025) / len(pol)
    d4 = [pol[i] - pol[i - 4] for i in range(4, len(pol))]
    out['max_cut4'] = 100 * min(d4); out['max_hike4'] = 100 * max(d4)
    out['gap_mean'] = 100 * mean(gap); out['gap_sd'] = 100 * sd(gap); out['gap_ac4'] = ac(gap, 4)
    out['u-nairu'] = 100 * mean([r['u'] - r['nairu'] for r in allr[i0:]])
    wg = col('wg')[i0:]
    out['wg_mean'] = 100 * mean(wg); out['wg_sd'] = 100 * sd(wg)
    out['be_sd'] = 100 * sd(col('be')[i0:])
    scq = sc[i0:]
    out['sc_clamp_share'] = sum(1 for v in scq if v <= -0.00999 or v >= 0.19999) / len(scq)
    out['sc_min'] = 100 * min(scq); out['sc_max'] = 100 * max(scq)
    out['hl_max'] = 100 * max(hl[i0:]); out['hl_min'] = 100 * min(hl[i0:])
    out['n_q'] = len(gap)
    return out


S = seeds()
res = {a: {s: stats(load(a, s)) for s in S} for a in ARMS}
keys = list(next(iter(res[ARMS[0]].values())).keys())
print(f'services shock arms, seeds {S[0]}-{S[-1]} (n={len(S)}), burn {BURN} y, quarterly samples; pp unless ratio')
print(f"{'quantity':16s}" + ''.join(f"{a:>22s}" for a in ARMS))
for k in keys:
    line = f'{k:16s}'
    for a in ARMS:
        v = [res[a][s][k] for s in S]
        line += f'  {mean(v):7.3f} [{min(v):6.2f},{max(v):6.2f}]'
    print(line)
print()
print('paired vs ' + ARMS[0] + ': mean delta (se) t, n=' + str(len(S)))
PK = ['svc_sd', 'svc_ac4', 'hl_sd', 'pol_sd', 'gap_sd', 'gap_mean', 'floor_share']
print(f"{'quantity':16s}" + ''.join(f"{a:>26s}" for a in ARMS[1:]))
for k in PK:
    line = f'{k:16s}'
    for a in ARMS[1:]:
        d = [res[a][s][k] - res[ARMS[0]][s][k] for s in S]
        se = sd(d) / math.sqrt(len(d)) if len(d) > 1 else float('nan')
        t = mean(d) / se if se > 0 else float('nan')
        line += f'   {mean(d):+8.3f} ({se:6.3f}) {t:+5.1f}'
    print(line)

# extra pairs from env PAIRS, e.g. "C:D" (second minus first)
for pr in filter(None, os.environ.get('PAIRS', '').split(',')):
    a, b = pr.split(':')
    print()
    print(f'paired {b} - {a}: mean delta (se) t, n={len(S)}')
    for k in ['svc_sd', 'svc_ac4']:
        d = [res[b][s][k] - res[a][s][k] for s in S]
        se = sd(d) / math.sqrt(len(d))
        print(f'{k:16s}   {mean(d):+8.3f} ({se:6.3f}) {mean(d) / se:+5.1f}')
