import glob, json, math, statistics as st, sys
D = sys.argv[1]

def worth(r):
    return r['net_debt'] - (r['fund'] - (r['debt'] - r['net_debt'])) - r['rescue_assets']

def load(arm):
    out = {}
    for f in glob.glob(f'{D}/{arm}-*.macro.jsonl'):
        rows = [json.loads(l) for l in open(f)]
        if not rows:
            continue
        def m(lo, hi, fn):
            v = [fn(r) for r in rows if lo <= r['t'] < hi]
            return st.mean(v) if v else float('nan')
        out[rows[0]['seed']] = {
            'tmax': rows[-1]['t'],
            'gross 30-40': m(30, 40, lambda r: r['debt']),
            'gross 40-50': m(40, 50, lambda r: r['debt']),
            'gross 50+': m(50, 99, lambda r: r['debt']),
            'worth 40-50': m(40, 50, lambda r: worth(r)),
            'worth 50+': m(50, 99, worth),
            'fund 50+': m(50, 99, lambda r: r['fund']),
            'ceiling% 50+': 100 * m(50, 99, lambda r: float(r['debt'] > 2.49)),
        }
    return out

a, b = load('net'), load('worth')
seeds = sorted(set(a) & set(b))
print('tmax min net %.2f worth %.2f, n=%d' % (min(a[s]['tmax'] for s in seeds), min(b[s]['tmax'] for s in seeds), len(seeds)))
print(f"{'quantity':<14s} {'NET':>8s} {'WORTH':>8s} {'diff':>8s} {'se':>7s} {'t':>6s}")
for k in a[seeds[0]]:
    if k == 'tmax':
        continue
    d = [b[s][k] - a[s][k] for s in seeds]
    se = st.stdev(d) / math.sqrt(len(d))
    print(f"{k:<14s} {st.mean(a[s][k] for s in seeds):8.3f} {st.mean(b[s][k] for s in seeds):8.3f} {st.mean(d):8.3f} {se:7.3f} {st.mean(d)/se if se else 0:6.2f}")
# within-arm drift: decade 50+ minus 40-50, per arm
for arm, x in (('net', a), ('worth', b)):
    for k1, k2 in (('gross 40-50', 'gross 50+'), ('worth 40-50', 'worth 50+')):
        d = [x[s][k2] - x[s][k1] for s in seeds]
        se = st.stdev(d) / math.sqrt(len(d))
        print(f"{arm:<5s} {k2} - {k1}: {st.mean(d):+.3f} (se {se:.3f}, t {st.mean(d)/se:.2f}), rising in {sum(v > 0 for v in d)}/{len(d)}")
