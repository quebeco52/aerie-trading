"""Phase of the monetary restoring force, US vs engine: python3 phase_an.py <dir> <tag> [live.jsonl]

Stance s = policy rate - core inflation (yoy) - r*, the rule's own real-rate gap. Reported:
  - cross-correlation corr(s_{t+k}, gap_t), k in quarters (k > 0: stance lags the gap);
  - corr(T_t, gap_t) with T the stance through the engine's Pascal-2 lag (tau 0.4y), the drag's phase against the gap.
US: CBO gap, fed funds, core PCE, HLW r*, 1961-2008 (pre-ELB).
"""
import json, math, os, sys, glob
H = os.path.dirname(os.path.abspath(__file__))
src = open(os.path.join(H, 'policy_fit.py')).read()
exec(src[:src.index('out = {}')])


def corr(a, b):
    n = len(a); ma = sum(a) / n; mb = sum(b) / n
    sa = math.sqrt(sum((x - ma) ** 2 for x in a)); sb = math.sqrt(sum((y - mb) ** 2 for y in b))
    return sum((x - ma) * (y - mb) for x, y in zip(a, b)) / (sa * sb)


def pascal(s, tau=0.4, h=0.25):
    w = 1 - math.exp(-h / tau); a = b = s[0]; out = []
    for x in s:
        a += w * (x - a); b += w * (a - b); out.append(b)
    return out


def report(name, segments):
    """segments: list of (gap list, stance list) contiguous runs."""
    line = []
    for k in (-8, -4, -2, 0, 2, 4, 6, 8, 12):
        A, B = [], []
        for g, s in segments:
            for t in range(len(g)):
                if 0 <= t + k < len(g):
                    A.append(s[t + k]); B.append(g[t])
        line.append(f"{k:+d}q {corr(A, B):+.2f}")
    A, B = [], []
    for g, s in segments:
        T = pascal(s)
        A += T[8:]; B += g[8:]
    peak = max(range(-8, 13), key=lambda k: corr(*zip(*[(s[t + k], g[t]) for g, s in segments for t in range(len(g)) if 0 <= t + k < len(g)])))
    print(f"{name:10s} corr(stance lead/lag, gap): {'  '.join(line)} | peak at {peak:+d}q | corr(Pascal-lagged stance, gap) {corr(A, B):+.2f}")


# --- US
us = [k for k in qs if '1961-01' <= k <= '2008-10' and k in rstar]
report('US 61-08', [([gap[k] for k in us], [ff[k] - core_yoy[k] - rstar[k] for k in us])])
us2 = [k for k in qs if '1985-01' <= k <= '2008-10' and k in rstar]
report('US 85-08', [([gap[k] for k in us2], [ff[k] - core_yoy[k] - rstar[k] for k in us2])])

# --- engine harness
if len(sys.argv) > 2:
    segs = []
    for f in sorted(glob.glob(f"{sys.argv[1]}/{sys.argv[2]}-[0-9]*.json")):
        rows = json.load(open(f))
        by = {}
        for r in rows:
            by.setdefault(r['seed'], []).append(r)
        for rs in by.values():
            rs = rs[20:]
            core_e = [(0.55 * r['supercore_ema'] + 0.25 * r['goods_ema']) / 0.80 for r in rs]
            segs.append(([100 * r['gap'] for r in rs], [100 * (r['policy'] - c - r['rstar']) for r, c in zip(rs, core_e)]))
    report('engine', segs)
if len(sys.argv) > 3:
    rows = [json.loads(l) for l in open(sys.argv[3])][8:]
    core_e = [(0.55 * r['supercore_inflation_ema'] + 0.25 * r['core_goods_inflation_ema']) / 0.80 for r in rows]
    report('live', [([100 * r['output_gap'] for r in rows], [100 * (r['policy_rate'] - c - r['natural_rate']) for r, c in zip(rows, core_e)])])


# --- components: which leg of the stance lags?
def xc(segs):
    out = {}
    for k in range(-8, 13):
        A, B = [], []
        for g, s in segs:
            for t in range(len(g)):
                if 0 <= t + k < len(g):
                    A.append(s[t + k]); B.append(g[t])
        out[k] = corr(A, B)
    pk = max(out, key=lambda k: abs(out[k]))
    return f"0q {out[0]:+.2f}  4q {out[4]:+.2f}  8q {out[8]:+.2f}  peak {out[pk]:+.2f} at {pk:+d}q"


if os.environ.get('COMP'):
    comps = {'policy': lambda d: d['i'], 'core': lambda d: d['pi'], 'rstar': lambda d: d['r']}
    U = [{'g': gap[k], 'i': ff[k], 'pi': core_yoy[k], 'r': rstar[k]} for k in us2]
    E = []
    for f in sorted(glob.glob(f"{sys.argv[1]}/{sys.argv[2]}-[0-9]*.json")):
        by = {}
        for r in json.load(open(f)):
            by.setdefault(r['seed'], []).append(r)
        for rs in by.values():
            E.append([{'g': 100 * r['gap'], 'i': 100 * r['policy'], 'pi': 100 * (0.55 * r['supercore_ema'] + 0.25 * r['goods_ema']) / 0.80, 'r': 100 * r['rstar'], 'tgt': 100 * r['target']} for r in rs[20:]])
    for c, fn in comps.items():
        print(f"{c:7s} US85-08: {xc([([d['g'] for d in U], [fn(d) for d in U])])}   engine: {xc([([d['g'] for d in e], [fn(d) for d in e]) for e in E])}")
    print(f"target  engine: {xc([([d['g'] for d in e], [d['tgt'] for d in e]) for e in E])}")
    sd = lambda xs: math.sqrt(sum((x - sum(xs) / len(xs)) ** 2 for x in xs) / len(xs))
    for c, fn in comps.items():
        print(f"sd {c:6s} US85-08 {sd([fn(d) for d in U]):.2f}   engine {sum(sd([fn(d) for d in e]) for e in E) / len(E):.2f} (within-seed)")
