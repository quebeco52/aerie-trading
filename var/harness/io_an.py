"""Input-output weights A/B: rec6 (hand-set baskets; recorded the macro paths) vs io (BEA baskets; replays those paths)."""
import glob, json, math, statistics as st, sys
from collections import Counter
H = sys.argv[1] if len(sys.argv) > 1 else '.'
def load(arm): return {int(f.rsplit('-', 1)[1].split('.')[0]): json.loads(open(f).readline()) for f in glob.glob(f'{H}/runs2/{arm}-*.jsonl')}
def corr(x, y):
    mx, my = st.mean(x), st.mean(y); den = math.sqrt(sum((a - mx) ** 2 for a in x) * sum((b - my) ** 2 for b in y))
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / den if den > 0 else float('nan')
def pct(v, p): s = sorted(v); return s[min(len(s) - 1, max(0, int(round(p * (len(s) - 1)))))]
A, B = load('rec6'), load('io')
seeds = sorted(set(A) & set(B))
print(f'paired seeds: {len(seeds)}')
# macro path identity check: same crude/gas/metals EMA at the same report times for one firm
qa = A[seeds[0]]['final']['WING']['q']; qb = B[seeds[0]]['final']['WING']['q']
print('macro path identical (seed', seeds[0], '):', all(abs(x['crude'] - y['crude']) < 1e-9 and abs(x['metals'] - y['metals']) < 1e-9 for x, y in zip(qa, qb)))
rows = []
for t in sorted(A[seeds[0]]['final']):
    def stats(D):
        om = [x['om'] for s in seeds for x in D[s]['final'][t]['q'] if x['rev'] > 0]
        loss = [x['ebit'] < 0 for s in seeds for x in D[s]['final'][t]['q'] if x['rev'] > 0]
        dead = sum(1 for s in seeds if D[s]['final'][t]['dead'])
        return (st.mean(om), pct(om, .05), pct(om, .95), sum(loss) / len(loss), dead) if om else None
    a, b = stats(A), stats(B)
    if a and b:
        rows.append((t, a, b))
rows.sort(key=lambda r: -abs(r[2][0] - r[1][0]))
print(f"\n{'ticker':6s} {'mean om':>17s} {'p5':>15s} {'p95':>15s} {'loss share':>15s} deaths")
for t, a, b in rows[:25]:
    print(f"{t:6s} {a[0]:7.3f} -> {b[0]:6.3f} {a[1]:6.3f}->{b[1]:6.3f} {a[2]:6.3f}->{b[2]:6.3f} {a[3]:6.3f}->{b[3]:6.3f}  {a[4]}->{b[4]}")
print('\nchannel coupling, median over seeds of corr(margin, channel EMA):')
for t, chans in [('SILC', ['crude', 'power', 'metals']), ('WING', ['crude', 'metals', 'freight']), ('BREW', ['crude', 'agri']), ('CANV', ['crude']),
                 ('SHER', ['agri', 'freight']), ('FALC', ['metals', 'freight']), ('BIRD', ['gas', 'power']), ('CNDR', ['crude', 'metals']), ('SGRB', ['agri'])]:
    out = []
    for ch in chans:
        v = []
        for D, lab in ((A, 'old'), (B, 'new')):
            c = [corr([x[ch] for x in D[s]['final'][t]['q'] if x['rev'] > 0], [x['om'] for x in D[s]['final'][t]['q'] if x['rev'] > 0]) for s in seeds]
            v.append(st.median(c))
        out.append(f'{ch} {v[0]:+.2f}->{v[1]:+.2f}')
    print(f'  {t}: ' + ' | '.join(out))
for D, lab in ((A, 'control'), (B, 'io')):
    deaths = Counter(t for s in seeds for t in (D[s]['dead'] or {})); reorgs = Counter(x['t'] for s in seeds for x in (D[s]['reorgs'] or []))
    print(f'{lab:8s} deaths {sum(deaths.values())} {dict(deaths)}  reorgs {sum(reorgs.values())} {dict(reorgs)}')
