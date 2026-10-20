#!/usr/bin/env python3
"""committee_an.py <stage dir> [burn years]: paired NEW - HEAD rate moments over runs/<stage>/{new,head}/on_<seed>.jsonl."""
import json, sys, glob, os, statistics as st

stage = sys.argv[1]
burn = float(sys.argv[2]) if len(sys.argv) > 2 else 5.0
ELB = 0.001


def moments(path):
    rows = [json.loads(l) for l in open(path)]
    rows = [r for r in rows if r['t'] > burn]
    rate = [r['rate'] for r in rows]
    gap = [r['gap'] for r in rows]
    infl = [r['infl'] for r in rows]
    mm = [r.get('mmaj') for r in rows]
    out = {
        'sd rate': st.pstdev(rate),
        'sd gap': st.pstdev(gap),
        'mean infl': st.mean(infl),
        'mean rate': st.mean(rate),
        'elb share': sum(x <= ELB + 1e-9 for x in rate) / len(rate),
        'max rate': max(rate),
        'max |gap|': max(abs(g) for g in gap),
    }
    for lab, v in (('hawk', 1), ('dove', -1), ('none', 0)):
        known = [m for m in mm if m is not None]
        out[f'maj {lab}'] = sum(m == v for m in known) / len(known) if known else float('nan')
    return out


seeds = sorted(int(os.path.basename(p)[3:-6]) for p in glob.glob(f'{stage}/new/on_*.jsonl'))
seeds = [s for s in seeds if os.path.exists(f'{stage}/head/on_{s}.jsonl')]
new = {s: moments(f'{stage}/new/on_{s}.jsonl') for s in seeds}
head = {s: moments(f'{stage}/head/on_{s}.jsonl') for s in seeds}
n = len(seeds)
print(f'n={n} seeds {seeds}, burn {burn}y, quarterly rows')
print(f"{'quantity':<11}{'HEAD':>10}{'tree':>10}{'diff bp':>10}{'se bp':>9}{'t':>7}{'head rng':>20}{'tree rng':>20}")
for k in new[seeds[0]]:
    h = [head[s][k] for s in seeds]
    w = [new[s][k] for s in seeds]
    d = [a - b for a, b in zip(w, h)]
    se = st.stdev(d) / n ** 0.5 if n > 1 else float('nan')
    t = st.mean(d) / se if se > 0 else float('nan')
    print(f"{k:<11}{st.mean(h):>10.4f}{st.mean(w):>10.4f}{1e4*st.mean(d):>10.2f}{1e4*se:>9.2f}{t:>7.2f}"
          f"{f'{min(h):.4f}..{max(h):.4f}':>20}{f'{min(w):.4f}..{max(w):.4f}':>20}")
