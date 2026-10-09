"""Pools forecast_calibration.php rows: calibration of each party's chance of leading the next government, Brier score
by months to the vote, and the forecast's cost. Run: python forecast_calibration.py runs/cal_*.jsonl"""
import json, sys, statistics as st
rows, cost = [], []
for f in sys.argv[1:]:
    for line in open(f):
        r = json.loads(line)
        if 'cost' in r:
            cost += r['cost']
        else:
            rows.append(r)
bins = [[0.0, 0.0, 0] for _ in range(10)]
brier = {}
for r in rows:
    score = 0.0
    for party, p in r['leaders'].items():
        hit = 1.0 if party == r['leader'] else 0.0
        b = bins[min(9, int(p * 10))]
        b[0] += p; b[1] += hit; b[2] += 1
        score += (p - hit) ** 2
    brier.setdefault(r['out'], []).append(score)
print(f"forecasts scored: {len(rows)}")
print("chance bin   mean chance   led     n")
for i, (p, h, n) in enumerate(bins):
    if n:
        print(f"  {i / 10:.1f}-{(i + 1) / 10:.1f}     {p / n:.3f}       {h / n:.3f}  {n}")
print("months out  Brier (sum over parties)  n")
for m in [1, 2, 3, 6, 9, 12, 18, 24, 36, 47]:
    if m in brier:
        print(f"  {m:3d}       {st.mean(brier[m]):.3f}                  {len(brier[m])}")
cost.sort()
print(f"cost ms: median {cost[len(cost) // 2]:.1f}, p90 {cost[int(0.9 * len(cost))]:.1f}, max {cost[-1]:.1f}")
