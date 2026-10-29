"""Line drifts (Stage 1): pilot weights against the closed form, other firms' weights untouched, paired revenue."""
import glob, json, math, statistics as st, sys

a, b = (sys.argv[1], sys.argv[2]) if len(sys.argv) > 2 else ('after_r', 'lines_r')
HL = 8.0
DRIFTS = {'advertising_agency': {'media_buying_commissions': -0.02, 'martech_consulting': 0.04},
          'security_protection': {'cash_services': -0.02}}
def faded(g, t):
    k = math.log(2) / HL
    return g * (1 - math.exp(-k * t)) / k
def load(arm):
    out = {}
    for p in sorted(glob.glob(f'runs/{arm}-s*.json')):
        d = json.load(open(p)); out[d['seed']] = d['firms']
    return out
A, B = load(a), load(b)
seeds = sorted(set(A) & set(B))

worst, touched = {}, set()
for s in seeds:
    for t, f in B[s].items():
        drifts = DRIFTS.get(f['model'])
        q = [r for r in f['q'] if isinstance(r['w'], dict) and r['w']]
        if drifts and q:
            # Opening mix backed out of the first quarter's weights.
            open_ = {k: w * math.exp(-faded(drifts.get(k, 0), q[0]['t'])) for k, w in q[0]['w'].items()}
            for r in q:
                lv = {k: w * math.exp(faded(drifts.get(k, 0), r['t'])) for k, w in open_.items()}
                tot = sum(lv.values())
                err = max(abs(r['w'][k] - lv[k] / tot) for k in lv)
                worst[t] = max(worst.get(t, 0), err)
        elif t in A[s]:
            qa = {r['t']: r['w'] for r in A[s][t]['q'] if isinstance(r['w'], dict)}
            for r in q:
                wa = qa.get(r['t'])
                if wa is not None and any(abs(wa.get(k, 0) - v) > 1e-9 for k, v in r['w'].items()):
                    touched.add((t, f['model']))
                    break
print('pilot weights vs closed form, worst abs error:', {k: f'{v:.1e}' for k, v in sorted(worst.items())})
print('other firms whose weights differ between arms (any seed):', sorted(touched))

def ms(x):
    return st.mean(x), st.stdev(x) / math.sqrt(len(x))
print(f'paired 20y revenue and net income, {b} vs {a}, n={len(seeds)}')
for t in ['LYRE', 'WATCH', 'OSPR', 'NJAR', 'NCKR', 'board']:
    rv, ni = [], []
    for s in seeds:
        fa = A[s] if t == 'board' else {t: A[s][t]}
        fb = B[s] if t == 'board' else {t: B[s][t]}
        ra = sum(r['rev'] for f in fa.values() for r in f['q']); rb = sum(r['rev'] for f in fb.values() for r in f['q'])
        na = sum(r['ni'] for f in fa.values() for r in f['q']); nb = sum(r['ni'] for f in fb.values() for r in f['q'])
        rv.append(math.log(rb / ra)); ni.append((nb - na) / abs(na))
    (m, se), (n, sn) = ms(rv), ms(ni)
    late = []
    if t != 'board':
        for s in seeds:
            late.append(math.log(sum(r['rev'] for r in B[s][t]['q'][-8:]) / sum(r['rev'] for r in A[s][t]['q'][-8:])))
    lm = f'   last 2y revenue {100*st.mean(late):+.1f}% ±{100*st.stdev(late)/2:.1f}' if late else ''
    print(f'{t:6} revenue {100*m:+6.2f}% ±{100*se:4.2f}  net income {100*n:+6.1f}% ±{100*sn:4.1f}{lm}')
