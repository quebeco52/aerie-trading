#!/usr/bin/env python3
"""Per-seed quarterly moments from runs/<arm>-<seed>.macro.jsonl, paired B-A and C-A. Drops year 1."""
import json, math, glob, os, re, sys
H = os.path.dirname(os.path.abspath(__file__))
ELB = 0.0  # replaced below by the snapshot's EFFECTIVE_LOWER_BOUND + 5bp
elb = None
snap = os.environ.get('SNAP', '/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/fa6eb337-3fae-4393-a0c0-312404e10f42/scratchpad')
try:
    src = open(snap + '/cbtree/src/Service/Macro/MacroEngine.php').read()
    elb = float(re.search(r'EFFECTIVE_LOWER_BOUND\s*=\s*([-0-9.]+)', src).group(1))
except Exception:
    elb = 0.0

def acf(x, k):
    n = len(x); m = sum(x) / n
    v = sum((a - m) ** 2 for a in x)
    return sum((x[i] - m) * (x[i + k] - m) for i in range(n - k)) / v if v > 0 else float('nan')

def sd(x):
    m = sum(x) / len(x); return math.sqrt(sum((a - m) ** 2 for a in x) / (len(x) - 1))

def moments(path):
    rows = [json.loads(l) for l in open(path) if l.strip()]
    rows = [r for r in rows if r['t'] > 1.0 + 1e-9]
    if len(rows) < 40:
        return None
    g = [r['gap'] for r in rows]; p = [r['policy'] for r in rows]; y = [r['y10'] for r in rows]
    inf = [r['infl'] for r in rows]; u = [r['u'] for r in rows]
    return {
        'gap_acf4': acf(g, 4), 'pol_acf4': acf(p, 4), 'y10_acf4': acf(y, 4),
        'gap_sd%': 100 * sd(g), 'infl_mean%': 100 * sum(inf) / len(inf), 'infl_sd%': 100 * sd(inf),
        'u_sd%': 100 * sd(u), 'elb_share': sum(1 for a in p if a <= elb + 0.0005) / len(p),
        'pol_sd%': 100 * sd(p), 'y10_sd%': 100 * sd(y),
        'max|dpol|4q%': 100 * max(abs(p[i + 4] - p[i]) for i in range(len(p) - 4)),
    }

data = {}
for f in glob.glob(H + '/runs/*.macro.jsonl'):
    arm, seed = os.path.basename(f).split('.')[0].split('-')
    m = moments(f)
    if m is None:
        print(f'short run: {arm}-{seed}', file=sys.stderr); continue
    data.setdefault(arm, {})[int(seed)] = m

keys = list(next(iter(data['A'].values())).keys())
arms = sorted(data)
seeds = sorted(set.intersection(*(set(data[a]) for a in arms)))
n = len(seeds)
print(f'n={n} seeds paired, arms {arms}, ELB={elb}')
hdr = f"{'quantity':<14}" + ''.join(f'{a:>16}' for a in arms) + ''.join(f'{"d"+a+"-A (se) t":>26}' for a in arms if a != 'A')
print(hdr)
for k in keys:
    line = f'{k:<14}'
    for a in arms:
        xs = [data[a][s][k] for s in seeds]
        line += f'{sum(xs)/n:>9.3f} ({sd(xs)/math.sqrt(n):.3f})'
    for a in arms:
        if a == 'A':
            continue
        d = [data[a][s][k] - data['A'][s][k] for s in seeds]
        md = sum(d) / n; se = sd(d) / math.sqrt(n)
        line += f'{md:>+11.3f} ({se:.3f}) {md/se if se>0 else float("nan"):>+6.1f}'
    print(line)
for a in arms:
    rng = {k: (min(data[a][s][k] for s in seeds), max(data[a][s][k] for s in seeds)) for k in ('gap_acf4', 'pol_acf4', 'y10_acf4')}
    print(f'{a} range: ' + ', '.join(f'{k} [{lo:.2f},{hi:.2f}]' for k, (lo, hi) in rng.items()))
