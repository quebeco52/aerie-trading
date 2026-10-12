#!/usr/bin/env python3
"""analyse.py <arm> <seeds a-b>: where gross debt settles under the fiscal rule (one arm, quarterly MACRO_OUT rows).

Targets: median/mean gross debt over years 30-100 pooled and per seed; OLS slope years 20-100 (pp/decade).
Context: debt at y50/y100, fund/GDP, primary balance by tariff on/off, floor/brake shares, sanity checks.
"""
import json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
arm = sys.argv[1] if len(sys.argv) > 1 else 'after'
a, b = (sys.argv[2] if len(sys.argv) > 2 else '1-4').split('-')
seeds = range(int(a), int(b) + 1)
FLOOR, BRAKE = 0.20, 0.90


def load(seed):
    rows = []
    with open(f'{H}/runs/{arm}-{seed}.macro.jsonl') as f:
        for line in f:
            r = json.loads(line)
            if r['t'] >= 0.2:  # drop the tick-1 founding row
                rows.append(r)
    return rows


def at(rows, y):
    return min(rows, key=lambda r: abs(r['t'] - y))


def slope(rows, lo, hi):
    xs = [r['t'] for r in rows if lo <= r['t'] <= hi]
    ys = [r['debt'] for r in rows if lo <= r['t'] <= hi]
    mx, my = st.mean(xs), st.mean(ys)
    return sum((x - mx) * (y - my) for x, y in zip(xs, ys)) / sum((x - mx) ** 2 for x in xs) * 10 * 100


pool, pb_on, pb_off, allq = [], [], [], []
bad = []
print(f'arm={arm} seeds={a}-{b}')
print(f"{'seed':>4} {'yrs':>5} {'med30+':>7} {'mean30+':>7} {'d50':>6} {'d100':>6} {'slope20+':>8} {'nfl100':>6} {'fund50':>6} {'fund100':>7} {'crises':>6} {'tarQ%':>6} {'ntax min/max':>13}")
for s in seeds:
    rows = load(s)
    late = [r['debt'] for r in rows if 30 <= r['t'] <= 100]
    pool += late
    allq += rows
    crises = {r['crisis_at'] for r in rows if r.get('crisis_at') not in (None, 0, -1) and r['crisis_at'] > 0}
    for r in rows:
        (pb_on if r['tariff'] > 0 else pb_off).append(-r['primary'])
        for k in ('debt', 'nfl', 'fund', 'primary', 'net_tax', 'y10', 'spread', 'u', 'gap'):
            v = r.get(k)
            if v is None or (isinstance(v, float) and not math.isfinite(v)):
                bad.append((s, r['t'], k, v))
        if r['net_tax'] < 0 or r['debt'] < 0:
            bad.append((s, r['t'], 'neg', r['debt'], r['net_tax']))
    nt = [r['net_tax'] for r in rows]
    tq = sum(r['tariff'] > 0 for r in rows) / len(rows) * 100
    print(f"{s:>4} {rows[-1]['t']:>5.1f} {st.median(late):>7.3f} {st.mean(late):>7.3f} {at(rows, 50)['debt']:>6.3f} {at(rows, 100)['debt']:>6.3f} "
          f"{slope(rows, 20, 100):>+8.2f} {at(rows, 100)['nfl']:>6.3f} {at(rows, 50)['fund']:>6.3f} {at(rows, 100)['fund']:>7.3f} {len(crises):>6} {tq:>6.1f} {min(nt):>6.3f}/{max(nt):.3f}")

print(f"\npooled y30-100: median {st.median(pool):.3f} mean {st.mean(pool):.3f} sd {st.pstdev(pool):.3f} n={len(pool)} quarters")
d50 = [at(load(s), 50)['debt'] for s in seeds]
d100 = [at(load(s), 100)['debt'] for s in seeds]
print(f"median across seeds: y50 {st.median(d50):.3f}  y100 {st.median(d100):.3f}")
se = lambda xs: st.stdev(xs) / math.sqrt(len(xs)) if len(xs) > 1 else float('nan')
print(f"primary balance (pp GDP, + = surplus): tariff>0 {100*st.mean(pb_on) if pb_on else float('nan'):+.2f} (n={len(pb_on)})  "
      f"tariff=0 {100*st.mean(pb_off):+.2f} (n={len(pb_off)})")
lev = [r['levies'] for r in allq if r.get('levies') is not None and r['tariff'] > 0]
if lev:
    print(f"policy levies in tariff quarters: mean {100*st.mean(lev):.2f} pp GDP; tariff rate mean {100*st.mean([r['tariff'] for r in allq if r['tariff'] > 0]):.2f}%")
print(f"share of quarters at floor (<= {FLOOR + 0.005:.3f}): {100*sum(r['debt'] <= FLOOR + 0.005 for r in allq)/len(allq):.1f}%   above {BRAKE}: {100*sum(r['debt'] > BRAKE for r in allq)/len(allq):.1f}%")
print(f"ranges all quarters: debt {min(r['debt'] for r in allq):.3f}..{max(r['debt'] for r in allq):.3f}  net_tax {min(r['net_tax'] for r in allq):.3f}..{max(r['net_tax'] for r in allq):.3f}  "
      f"spread {min(r['spread'] for r in allq):.4f}..{max(r['spread'] for r in allq):.4f}  y10 {min(r['y10'] for r in allq):.3f}..{max(r['y10'] for r in allq):.3f}  u {min(r['u'] for r in allq):.3f}..{max(r['u'] for r in allq):.3f}")
print(f"sanity: {len(bad)} bad rows" + (f", first {bad[:3]}" if bad else ""))
