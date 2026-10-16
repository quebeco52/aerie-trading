#!/usr/bin/env python3
"""path_an.py <seeds> <arm>...: pinned-price pathology counts per arm (summed over seeds, per-seed in brackets)."""
import csv, json, os, sys

H = os.path.dirname(os.path.abspath(__file__))
a, _, b = sys.argv[1].partition('-')
seeds = list(range(int(a), int(b or a) + 1))
arms = sys.argv[2:]


def one(arm, s):
    out = json.loads(open(f"{H}/runs/{arm}-{s}.jsonl").readline())
    fin = out['final']
    evt = {t: set(v) for t, v in (out.get('event_ticks') or {}).items()}
    nq, names = 0, set()
    for t, v in fin.items():
        q = v['q']
        for i, r in enumerate(q):
            if i < 3 or r['eq'] <= 0 or r['price'] <= 0:
                continue
            if sum(x['ni'] for x in q[i - 3:i + 1]) > 0 and r['price'] * r['shares'] / r['eq'] < 0.15:
                nq += 1
                names.add(t)
        for r in q:
            evt.setdefault(t, set()).add(int(round(r['t'] * out['tpy'])))
    with open(f"{H}/runs/{arm}-{s}.ticks.csv") as f:
        rd = csv.reader(f)
        hdr = next(rd)
        tk = [h[:-2] for h in hdr[1::2]]
        prev = None
        jumps, jumpn, rsplit, alljumps = 0, set(), 0, 0
        for row in rd:
            v = list(map(float, row))
            tick = int(v[0])
            if prev is not None:
                for j, nm in enumerate(tk):
                    pa, pb, na, nb = prev[1 + 2 * j], v[1 + 2 * j], prev[2 + 2 * j], v[2 + 2 * j]
                    if pa <= 0 or pb <= 0 or na <= 0:
                        continue
                    if abs(nb / na - 1) > 0.2:
                        if nb < na:
                            rsplit += 1
                        continue
                    if pb / pa > 1.5 or pb / pa < 0.5:
                        alljumps += 1
                    if (pb / pa > 1.5 or pb / pa < 0.5) and tick not in evt.get(nm, ()):
                        jumps += 1
                        jumpn.add(nm)
            prev = v
    deaths = len(out['dead'] or {}) + len(out['reorgs'])
    return nq, len(names), jumps, alljumps, rsplit, deaths, names, jumpn


print('| arm | P/B<0.15 & profitable name-qtrs | names in that state | unexplained >50% ticks | all >50% ticks | reverse splits | liquidations+Ch11 |')
print('|---|---|---|---|---|---|---|')
detail = []
for arm in arms:
    res = [one(arm, s) for s in seeds]
    cols = []
    for k in range(6):
        cols.append(f"{sum(r[k] for r in res)} [{'/'.join(str(r[k]) for r in res)}]")
    print(f"| {arm} | " + ' | '.join(cols) + ' |')
    for s, r in zip(seeds, res):
        detail.append(f"- {arm} s{s}: pinned {sorted(r[6])}; jumps {sorted(r[7])}")
print()
print('\n'.join(detail))
