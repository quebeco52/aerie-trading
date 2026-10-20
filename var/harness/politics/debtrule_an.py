#!/usr/bin/env python3
"""Debt-rule overshoot: python3 debtrule_an.py runs/<stage> [burn_years]. Reads {new,head}/on_<seed>.jsonl.
Per arm and paired (new - head): overshoot past a constant debt target once debt reaches it, |debt - target| stats,
primary balance, debt level, and cycle moments (gap SD, policy-rate SD, mean inflation)."""
import json, sys, glob, os, statistics as st

stage = sys.argv[1]
burn = float(sys.argv[2]) if len(sys.argv) > 2 else 5.0


def load(path):
    rows = [json.loads(l) for l in open(path)]
    return [r for r in rows if r['t'] > burn]


def overshoots(rows):
    """Spells of constant target; once debt reaches the target (from either side), furthest it goes past it (pp)."""
    out, spell = [], []
    def close(sp):
        if len(sp) < 2:
            return
        tgt = sp[0]['dtgt']
        side0 = sp[0]['debt'] - tgt
        if side0 == 0:
            return
        sign = 1 if side0 > 0 else -1
        crossed, worst = False, 0.0
        for r in sp:
            d = r['debt'] - tgt
            if not crossed and d * sign <= 0:
                crossed = True
            if crossed:
                worst = max(worst, -d * sign)
        if crossed:
            out.append(100 * worst)
    for r in rows:
        if spell and abs(r['dtgt'] - spell[0]['dtgt']) > 1e-9:
            close(spell); spell = []
        spell.append(r)
    close(spell)
    return out


def metrics(rows):
    ov = overshoots(rows)
    dev = [abs(r['debt'] - r['dtgt']) * 100 for r in rows]
    pb = [-r['def'] * 100 for r in rows]
    return {
        'overshoot mean pp': st.mean(ov) if ov else 0.0,
        'overshoot max pp': max(ov) if ov else 0.0,
        'spells reached': float(len(ov)),
        'share |dev|>2pp': sum(d > 2 for d in dev) / len(dev),
        'mean |dev| pp': st.mean(dev),
        'primary bal mean %': st.mean(pb),
        'primary bal sd pp': st.pstdev(pb),
        'debt/GDP mean %': st.mean(r['debt'] * 100 for r in rows),
        'debt/GDP max %': max(r['debt'] * 100 for r in rows),
        'gap sd %': st.pstdev(r['gap'] * 100 for r in rows),
        'rate sd pp': st.pstdev(r['rate'] * 100 for r in rows),
        'inflation mean %': st.mean(r['infl'] * 100 for r in rows),
    }


arms = {}
for arm in ('head', 'new'):
    for f in sorted(glob.glob(os.path.join(stage, arm, 'on_*.jsonl'))):
        seed = int(f.rsplit('_', 1)[1].split('.')[0])
        arms.setdefault(arm, {})[seed] = metrics(load(f))
seeds = sorted(set(arms['head']) & set(arms['new']))
keys = list(arms['head'][seeds[0]].keys())
print(f"n={len(seeds)} seeds, burn {burn:g}y; mean over seeds (overshoot max = max over seeds)")
print(f"{'quantity':22s} {'HEAD':>9s} {'tree':>9s} {'diff':>9s} {'se':>7s} {'t':>6s}")
for k in keys:
    h = [arms['head'][s][k] for s in seeds]
    n = [arms['new'][s][k] for s in seeds]
    d = [b - a for a, b in zip(h, n)]
    se = st.stdev(d) / len(d) ** 0.5 if len(d) > 1 else float('nan')
    agg = max if k == 'overshoot max pp' else st.mean
    t = st.mean(d) / se if se and se == se else float('nan')
    print(f"{k:22s} {agg(h):9.3f} {agg(n):9.3f} {st.mean(d):9.3f} {se:7.3f} {t:6.2f}")
