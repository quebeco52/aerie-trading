"""Labor round A/B on identical macro paths: lab0 (committed firm code + the new macro; replays) vs rec7 (new firm code; recorded).

Both arms carry the wage error correction, so every difference is the firms reading the real wage gap as a LEVEL
with measured labor shares, against the old wage-growth-as-level channel with hand-set shares.
"""
import glob, json, math, statistics as st, sys
from collections import Counter
H = sys.argv[1] if len(sys.argv) > 1 else '.'
def load(arm): return {int(f.rsplit('-', 1)[1].split('.')[0]): json.loads(open(f).readline()) for f in glob.glob(f'{H}/runs2/{arm}-*.jsonl')}
def corr(x, y):
    mx, my = st.mean(x), st.mean(y); den = math.sqrt(sum((a - mx) ** 2 for a in x) * sum((b - my) ** 2 for b in y))
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / den if den > 0 else float('nan')
def pct(v, p): s = sorted(v); return s[min(len(s) - 1, max(0, int(round(p * (len(s) - 1)))))]
A, B = load('lab0'), load('rec7')
seeds = sorted(set(A) & set(B))
print(f'paired seeds: {len(seeds)}')
qa = A[seeds[0]]['final']['WING']['q']; qb = B[seeds[0]]['final']['WING']['q']
print('macro path identical (seed', seeds[0], '):', all(abs(x['crude'] - y['crude']) < 1e-9 and abs(x['rwg'] - y['rwg']) < 1e-9 for x, y in zip(qa, qb)))
rwg = [x['rwg'] for s in seeds for x in B[s]['final']['WING']['q']]
print(f'real wage gap over the reports: mean {st.mean(rwg) * 100:+.2f}%  sd {st.pstdev(rwg) * 100:.2f}%  p5 {pct(rwg, .05) * 100:+.2f}%  p95 {pct(rwg, .95) * 100:+.2f}%')
rows = []
for t in sorted(A[seeds[0]]['final']):
    def stats(D):
        q = [x for s in seeds for x in D[s]['final'][t]['q'] if x['rev'] > 0]
        if not q:
            return None
        om = [x['om'] for x in q]
        c = [corr([x['rwg'] for x in D[s]['final'][t]['q'] if x['rev'] > 0], [x['om'] for x in D[s]['final'][t]['q'] if x['rev'] > 0]) for s in seeds]
        c = [v for v in c if not math.isnan(v)]
        return (st.mean(om), pct(om, .05), pct(om, .95), sum(x['ebit'] < 0 for x in q) / len(q), sum(1 for s in seeds if D[s]['final'][t]['dead']), st.median(c) if c else float('nan'))
    a, b = stats(A), stats(B)
    if a and b:
        rows.append((t, a, b))
rows.sort(key=lambda r: -abs(r[2][0] - r[1][0]))
print(f"\n{'ticker':6s} {'mean om':>17s} {'p5':>15s} {'p95':>15s} {'loss share':>15s} {'corr(om,rwg)':>15s} deaths")
for t, a, b in rows:
    print(f"{t:6s} {a[0]:7.3f} -> {b[0]:6.3f} {a[1]:6.3f}->{b[1]:6.3f} {a[2]:6.3f}->{b[2]:6.3f} {a[3]:6.3f}->{b[3]:6.3f} {a[5]:+6.2f}->{b[5]:+6.2f}  {a[4]}->{b[4]}")
shift = [b[0] - a[0] for _, a, b in rows]
print(f'\nmean-margin shift across {len(rows)} firms: mean {st.mean(shift) * 100:+.2f}pp  min {min(shift) * 100:+.2f}pp  max {max(shift) * 100:+.2f}pp')
for D, lab in ((A, 'control'), (B, 'labor')):
    deaths = Counter(t for s in seeds for t in (D[s]['dead'] or {})); reorgs = Counter(x['t'] for s in seeds for x in (D[s]['reorgs'] or []))
    print(f'{lab:8s} deaths {sum(deaths.values())} {dict(deaths)}  reorgs {sum(reorgs.values())} {dict(reorgs)}')
