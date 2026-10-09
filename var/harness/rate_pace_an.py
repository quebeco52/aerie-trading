"""Pace of policy-rate moves, engine vs US fed funds: python3 rate_pace_an.py <dir>/<tag> ... (CreditMacroProbeTest output). Quarter-end month vs quarter-end rate."""
import glob, json, math, sys
H = '/home/quebeco/Projects/Code/Private/aerie-trading/var/harness'
D = json.load(open(f'{H}/policy_data.json'))

def pct(xs, p):
    xs = sorted(xs); return xs[min(len(xs) - 1, int(p * len(xs)))]

def sd(xs):
    m = sum(xs) / len(xs); return math.sqrt(sum((x - m) ** 2 for x in xs) / len(xs))

def stats(name, segs):
    """segs: list of (policy list pp, gap list pp) quarterly."""
    d1, d4, g, dg = [], [], [], []
    for p, gp in segs:
        d1 += [p[i] - p[i - 1] for i in range(1, len(p))]
        d4 += [p[i] - p[i - 4] for i in range(4, len(p))]
        g += gp
    hikes = [x for x in d1 if x > 0]
    print(f"{name:22s} dq sd {sd(d1):.2f}  p95 {pct(d1,.95):+.2f}  p99 {pct(d1,.99):+.2f}  max {max(d1):+.2f} | "
          f"4q p95 {pct(d4,.95):+.2f} p99 {pct(d4,.99):+.2f} max {max(d4):+.2f}  >+3pp/4q {100*sum(x>3 for x in d4)/len(d4):.1f}% | "
          f"dq sd/gap sd {sd(d1)/sd(g):.2f}")

# US: fed funds in the quarter's last month, CBO gap
ff = {k[:7]: v for k, v in D['FEDFUNDS'].items() if int(k[5:7]) in (3, 6, 9, 12)}
gap = {}
for k, v in D['GDPC1'].items():
    if k in D['GDPPOT']:
        gap[f"{k[:4]}-{int(k[5:7]) + 2:02d}"] = 100 * (v / D['GDPPOT'][k] - 1)
def us(a, b):
    ks = sorted(k for k in ff if a <= k <= b and k in gap)
    return [ff[k] for k in ks], [gap[k] for k in ks]
stats('US 1985-2008', [us('1985-01', '2008-09')])
stats('US 1987-2019 ex-ELB', [us('1987-01', '2008-09'), us('2015-12', '2019-12')])
stats('US 1955-2008', [us('1955-01', '2008-09')])

# engine
for tag in sys.argv[1:]:
    segs, far, tgt_gap = [], [], []
    for f in sorted(glob.glob(f'{tag}-*.json')):
        q = [r for r in json.load(open(f)) if r['t'] > 10]
        segs.append(([100 * r['policy'] for r in q], [100 * r['gap_avg'] for r in q]))
        far += [100 * (r['target'] - r['policy']) for r in q]
    stats(f'engine {tag.split("/")[-1]}', segs)
    print(f"{'':22s} target - rate (pp): p5 {pct(far,.05):+.2f} p50 {pct(far,.5):+.2f} p95 {pct(far,.95):+.2f}; runs {len(segs)}, quarters {sum(len(s[0]) for s in segs)}")
