#!/usr/bin/env python3
"""Recession-regime split: python3 regime_diag.py <arm>. Quarter-end samples of runs/<arm>-*.macro.jsonl, year 1 dropped.
Per seed: Sahm-only / latch-only / both shares (sahm >= 0.005; latch = the engine's 'stress' field, or the harness's
per-tick counters sahm_t/latch_t/both_t when present), EBP mean/sd/share >= 1pp, NBER-like share (technical recession
on real GDP = nominal_gdp deflated by cumulated infl/4, peak-to-trough), and the latch share a quarter-sampled
re-run of the latch would give at other entry/exit thresholds. Prints means over seeds with se."""
import json, math, glob, os, sys
H = os.path.dirname(os.path.abspath(__file__))
SAHM = 0.005
ENTRIES = [0.010, 0.0125, 0.015, 0.0175, 0.020, 0.025]
EXITS = [0.0, 0.0025, 0.005]

def mean(x): return sum(x) / len(x)
def sd(x):
    m = mean(x); return math.sqrt(sum((a - m) ** 2 for a in x) / (len(x) - 1))

def latch(series, entry, exit_):
    on, out = False, []
    for v in series:
        on = v >= entry or (on and v > exit_)
        out.append(on)
    return out

def nber(rows):
    p, rgdp = 1.0, []
    for r in rows:
        p *= 1.0 + r['infl'] / 4.0
        rgdp.append(r['nominal_gdp'] / p)
    g = [rgdp[k] / rgdp[k - 1] - 1.0 for k in range(1, len(rgdp))]
    rec = [False] * len(rows)
    k = 0
    while k < len(g) - 1:
        if g[k] < 0 and g[k + 1] < 0:
            j = k
            while j < len(g) and g[j] < 0:
                rec[j + 1] = True; j += 1
            k = j
        else:
            k += 1
    return rec

per = {}
for f in sorted(glob.glob(f'{H}/runs/{sys.argv[1]}-*.macro.jsonl')):
    rows = [json.loads(l) for l in open(f) if l.strip()]
    if not rows or rows[-1]['t'] < 20.7: continue
    rec = nber(rows)
    keep = [k for k, r in enumerate(rows) if r['t'] > 1.0 + 1e-9]
    rows = [rows[k] for k in keep]; rec = [rec[k] for k in keep]
    n = len(rows)
    s = [r['sahm'] >= SAHM for r in rows]; L = [r['stress'] > 0 for r in rows]; e = [r['ebp'] for r in rows]
    m = {}
    if 'sahm_t' in rows[0]:
        m['sahm_only'] = mean([r['sahm_t'] - r['both_t'] for r in rows]); m['latch_only'] = mean([r['latch_t'] - r['both_t'] for r in rows])
        m['both'] = mean([r['both_t'] for r in rows]); m['ebp_ge1_t'] = mean([r['ebp1_t'] for r in rows])
    else:
        m['sahm_only'] = mean([a and not b for a, b in zip(s, L)]); m['latch_only'] = mean([b and not a for a, b in zip(s, L)])
        m['both'] = mean([a and b for a, b in zip(s, L)])
    m['sahm'] = m['sahm_only'] + m['both']; m['latch'] = m['latch_only'] + m['both']
    m['union'] = m['sahm_only'] + m['latch_only'] + m['both']; m['regime_t'] = mean([r['regime_t'] for r in rows])
    m['ebp_mean%'] = 100 * mean(e); m['ebp_sd%'] = 100 * sd(e); m['ebp_ge1'] = mean([x >= 0.010 for x in e])
    m['ebp_ge0.5'] = mean([x >= 0.005 for x in e]); m['nber'] = mean(rec)
    m['ebp_acf1q'] = (lambda mu: sum((e[k] - mu) * (e[k + 1] - mu) for k in range(n - 1)) / sum((x - mu) ** 2 for x in e))(mean(e))
    for en in ENTRIES:
        for ex in EXITS:
            Lq = latch(e, en, ex)
            m[f'q_latch {en:.4f}/{ex:.4f}'] = mean(Lq)
            m[f'q_union {en:.4f}/{ex:.4f}'] = mean([a or b for a, b in zip(s, Lq)])
    per[int(os.path.basename(f).split('.')[0].split('-')[1])] = m

seeds = sorted(per)
print(f'arm {sys.argv[1]}: n={len(seeds)} seeds, quarters after year 1')
for k in per[seeds[0]]:
    xs = [per[s][k] for s in seeds]
    print(f'{k:<26}{mean(xs):>8.3f} ({sd(xs) / math.sqrt(len(xs)):.3f})  range {min(xs):.3f}..{max(xs):.3f}')
