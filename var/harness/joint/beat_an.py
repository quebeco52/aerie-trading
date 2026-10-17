#!/usr/bin/env python3
"""beat_an.py <seeds> <arm>: EPS beat share (Street EPS above the published consensus) and each firm's surprise sd as a
share of quarterly revenue ((Street - consensus) x shares / revenue), by business model and for the board. Reports in
the first year are skipped. Needs runs/ticker_models.json (RUN.md)."""
import json, math, os, statistics as st, sys, collections

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
arm = sys.argv[2]
models = json.load(open(f"{H}/runs/ticker_models.json"))
beats, sds, margins = collections.defaultdict(list), collections.defaultdict(list), collections.defaultdict(list)
board_beats, firm_sd = [], {}
for s in seeds:
    fin = json.loads(open(f"{H}/runs/{arm}-{s}.jsonl").readline())['final']
    for t, v in fin.items():
        q = [r for r in v['q'] if r['t'] > 1.0 and r.get('rev', 0) > 0 and 'reps' in r and r['ceps'] != 0]
        if len(q) < 8:
            continue
        b = [1.0 if r['reps'] > r['ceps'] else 0.0 for r in q]
        u = [(r['reps'] - r['ceps']) * r['shares'] / r['rev'] for r in q]
        m = models.get(t, '?').replace('BusinessModel', '')
        beats[m].append(sum(b) / len(b)); board_beats.append(sum(b) / len(b))
        sds[m].append(st.pstdev(u)); firm_sd.setdefault(t, []).append(st.pstdev(u))
        margins[m].append(st.median([r['reps'] * r['shares'] / r['rev'] for r in q]))
print(f"{arm} seeds {sys.argv[1]}: board EPS beat share {100 * st.mean(board_beats):.1f}% (firm-seeds {len(board_beats)}), "
      f"median firm surprise sd {100 * st.median(x for v in sds.values() for x in v):.2f}% of revenue")
print(f"{'model':28s} {'beat %':>7s} {'beat min-max':>13s} {'surprise sd %rev':>17s} {'net margin %':>13s}")
for m in sorted(beats, key=lambda k: -st.mean(beats[k])):
    print(f"{m:28s} {100 * st.mean(beats[m]):7.1f} {100 * min(beats[m]):6.0f}-{100 * max(beats[m]):<6.0f} {100 * st.median(sds[m]):17.2f} {100 * st.median(margins[m]):13.1f}")
