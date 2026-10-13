#!/usr/bin/env python3
"""Output-gap bound binding: per seed and pooled. analyse.py [runs glob]"""
import glob, json, sys, statistics as st

FLOOR, CEIL = -0.12, 0.10
files = sorted(glob.glob(sys.argv[1] if len(sys.argv) > 1 else 'var/harness/gap_floor/runs/cur_*.jsonl'))

def pct(xs, p):
    s = sorted(xs); k = (len(s) - 1) * p; f = int(k)
    return s[f] + (s[min(f + 1, len(s) - 1)] - s[f]) * (k - f)

def episodes(flags):
    out, run = [], 0
    for i, f in enumerate(flags):
        if f: run += 1
        elif run: out.append((i - run, run)); run = 0
    if run: out.append((len(flags) - run, run))
    return out

pool = {'gap': [], 'fb': 0, 'cb': 0, 'n': 0, 'fe': [], 'ce': [], 'ftot': 0.0, 'fmax': 0.0, 'minpre': 0.0, 'years': 0.0}
print('seed  yrs  qtrs  floor%  ceil%  fl_eps(len)        ce_eps(len)   fl_tot_pp fl_max_pp  sd_pp  p1_pp  p5_pp  min_pp minpre_pp maxpre_pp')
details = []
for f in files:
    rows = [json.loads(l) for l in open(f)]
    seed = rows[0]['seed']
    gap = [r['gap'] for r in rows]
    fb = [r['floor'] > 0 or r['gap'] <= FLOOR + 0.001 for r in rows]
    cb = [r['ceil'] < 0 or r['gap'] >= CEIL - 0.001 for r in rows]
    fe, ce = episodes(fb), episodes(cb)
    ftot = sum(r['floor'] for r in rows); fmax = max(r['floor'] for r in rows)
    minpre = min(r['minpre'] for r in rows); maxpre = max(r['maxpre'] for r in rows)
    yrs = rows[-1]['t']
    print(f"{seed:4d} {yrs:4.0f} {len(rows):5d} {100*sum(fb)/len(rows):6.2f} {100*sum(cb)/len(rows):6.2f}  "
          f"{len(fe):2d} {str([l for _, l in fe]):14s} {len(ce):2d} {str([l for _, l in ce]):10s} "
          f"{100*ftot:9.2f} {100*fmax:9.2f} {100*st.pstdev(gap):6.2f} {100*pct(gap,.01):6.2f} {100*pct(gap,.05):6.2f} "
          f"{100*min(gap):6.2f} {100*minpre:9.2f} {100*maxpre:9.2f}")
    for s, l in fe:
        prior = rows[max(0, s - 8):s + l]
        t0 = rows[s]['t']
        crisis = max(r['crisis_at'] for r in prior)
        details.append(f"seed {seed} t={t0:6.2f} len={l}q  clamp={100*sum(r['floor'] for r in rows[s:s+l]):.2f}pp "
                       f"minpre={100*min(r['minpre'] for r in rows[s:s+l]):.2f}%  last crisis at t={crisis:.2f} "
                       f"(max drag {max(r['crisis_drag'] for r in prior):.4f})  worst disaster jump 8q before/during "
                       f"{100*min(r['disaster'] for r in prior):.2f}pp")
    pool['gap'] += gap; pool['fb'] += sum(fb); pool['cb'] += sum(cb); pool['n'] += len(rows)
    pool['fe'] += [l for _, l in fe]; pool['ce'] += [l for _, l in ce]; pool['ftot'] += ftot
    pool['fmax'] = max(pool['fmax'], fmax); pool['minpre'] = min(pool['minpre'], minpre); pool['years'] += yrs
g = pool['gap']
print(f"pool {pool['years']:4.0f} {pool['n']:5d} {100*pool['fb']/pool['n']:6.2f} {100*pool['cb']/pool['n']:6.2f}  "
      f"{len(pool['fe']):2d} eps ({len(pool['fe'])/pool['years']*100:.1f}/century), {len(pool['ce'])} ceil eps  "
      f"fl_tot {100*pool['ftot']:.2f}pp fl_max {100*pool['fmax']:.2f}pp  sd {100*st.pstdev(g):.2f} p1 {100*pct(g,.01):.2f} "
      f"p5 {100*pct(g,.05):.2f} min {100*min(g):.2f} minpre {100*pool['minpre']:.2f}")
print('floor episodes:')
for d in details: print('  ' + d)
print('reference: worst postwar CBO gap -8.8% (2009Q2)')
