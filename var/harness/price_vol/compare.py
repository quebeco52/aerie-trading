#!/usr/bin/env python3
"""Before/after comparison of two probe arms (per-arm means; micro draws do not pair).

compare.py [armA=live] [armB=fix] [seeds=1-4] [skip=360] [tpy=360]
"""
import csv, json, os, sys, math, statistics as st
from collections import defaultdict

H = os.path.dirname(os.path.abspath(__file__))
A = sys.argv[1] if len(sys.argv) > 1 else 'live'
B = sys.argv[2] if len(sys.argv) > 2 else 'fix'
s_ = sys.argv[3] if len(sys.argv) > 3 else '1-4'
lo, _, hi = s_.partition('-')
SEEDS = list(range(int(lo), int(hi or lo) + 1))
SKIP = int(sys.argv[4]) if len(sys.argv) > 4 else 360
TPY = int(sys.argv[5]) if len(sys.argv) > 5 else 360
COMP = ['mkt', 'sec', 'idio', 'pull', 'brk', 'jI', 'jS', 'mapol', 'impP', 'impT', 'earn']
FOCUS = ['PHIL', 'SGRB', 'CROP', 'LARK', 'PINT']
ANCHOR2 = 0.15 ** 2


def var(xs):
    n = len(xs)
    if n < 3:
        return float('nan')
    m = sum(xs) / n
    return sum((x - m) ** 2 for x in xs) / (n - 1)


def blocks(xs, h):
    return [sum(xs[i:i + h]) for i in range(0, len(xs) - h + 1, h)]


def load(arm):
    res, meta, audit = defaultdict(list), {}, defaultdict(list)
    idx = []  # per seed: (annual mean, 21-tick vol) of the equal-weight board
    nan_rows = 0
    dead, badpx = [], 0
    for s in SEEDS:
        j = json.load(open(f'{H}/runs/{arm}-{s}.json'))
        meta.update(j['meta'])
        for tk, a in j['audit'].items():
            audit[tk].append({k: v / a['n'] for k, v in a.items()})
        rec = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').readline())
        dead.append(len(rec['dead']))
        badpx += sum(1 for f in rec['final'].values() if not (f['px'] == f['px']) or (f['px'] <= 0 and not f['dead']))
        series = defaultdict(lambda: defaultdict(list))
        board = defaultdict(list)
        with open(f'{H}/runs/{arm}-{s}.csv') as f:
            for r in csv.DictReader(f):
                if any(v.lower() in ('nan', 'inf', '-inf') for v in r.values()):
                    nan_rows += 1
                    continue
                t = int(r['t'])
                if t <= SKIP:
                    continue
                d = series[r['tk']]
                d['tot'].append(float(r['tot']))
                board[t].append(float(r['tot']))
                for c in ('mkt', 'sec', 'idio', 'pull', 'brk', 'jI', 'jS', 'impP', 'impT'):
                    d[c].append(float(r[c]))
                d['mapol'].append(float(r['ma']) + float(r['pol']))
                d['earn'].append(float(r['warn']) + float(r['rep']))
        ew = [st.mean(board[t]) for t in sorted(board)]
        idx.append((st.mean(ew) * TPY, math.sqrt(var(blocks(ew, 21)) * TPY / 21)))
        for tk, d in series.items():
            if len(d['tot']) < 2 * TPY:
                continue
            res[tk].append({h: {c: var(blocks(d[c], h)) * TPY / h for c in ['tot'] + COMP} for h in (1, 21)})
    rows = {}
    for tk in res:
        au = {k: st.mean(x.get(k, float('nan')) for x in audit[tk]) for k in audit[tk][0]}
        au.setdefault('sysJallow', min(au['sysJ'], au['cap']))
        au.setdefault('sysBlock', au['sysVarAnchor'])
        v = {h: {c: st.mean(r[h][c] for r in res[tk]) for c in ['tot'] + COMP} for h in (1, 21)}
        rows[tk] = dict(tk=tk, sector=meta[tk]['sector'], sigma=meta[tk]['sigma'], au=au, v=v)
    return rows, dict(idx=idx, nan=nan_rows, dead=dead, badpx=badpx)


AT = ['sysMkt', 'sysJ', 'ownJ', 'diff', 'ann', 'imp', 'viFlr', 'rest']


def attr(r):
    a, v = r['au'], r['v'][21]
    cap = a['cap']
    d = {'sysMkt': v['mkt'] - a['sysVarAnchor'], 'sysJ': v['jS'] - a['sysJallow'], 'ownJ': v['jI'] - min(a['ownJ'], cap),
         'diff': v['idio'] + v['sec'] - a['theta'], 'ann': v['earn'] - min(a['ann'], cap),
         'imp': v['impP'] + v['impT'] - min(a['imp'], cap), 'viFlr': a['vi'] - (r['sigma'] ** 2 - a['sysBlock'])}
    d['rest'] = (v['tot'] - r['sigma'] ** 2) - sum(d.values())
    return d


RA, SA = load(A)
RB, SB = load(B)
names = [t for t in RA if t in RB]


def groups():
    g = [(t, [t]) for t in FOCUS if t in names]
    sec = defaultdict(list)
    for t in names:
        sec[RA[t]['sector']].append(t)
    g += [(f'{s} ({len(ts)})', ts) for s, ts in sorted(sec.items())]
    g.append((f'floored after ({sum(1 for t in names if RB[t]["au"]["floor"] > 0.5)})', [t for t in names if RB[t]['au']['floor'] > 0.5]))
    g.append(('unfloored after', [t for t in names if RB[t]['au']['floor'] <= 0.5]))
    g.append((f'ALL ({len(names)})', names))
    return g


print(f'arms {A} -> {B}, seeds {SEEDS}, skip {SKIP}, {TPY} ticks/yr; per-arm means over seeds and names')
print('\n== realised vol vs configured (vol = sqrt of mean variance over names) ==')
print(f"{'group':26} {'sig':>5} | {A + ' v1':>8} {'v21':>5} {'ex21':>6} | {B + ' v1':>8} {'v21':>5} {'ex21':>6}")
for lab, ts in groups():
    if not ts:
        continue
    sig = math.sqrt(st.mean(RA[t]['sigma'] ** 2 for t in ts))
    out = f"{lab[:26]:26} {sig:5.3f} |"
    for R in (RA, RB):
        v1 = math.sqrt(st.mean(R[t]['v'][1]['tot'] for t in ts))
        v21 = math.sqrt(st.mean(R[t]['v'][21]['tot'] for t in ts))
        ex = st.mean(math.sqrt(R[t]['v'][21]['tot']) - R[t]['sigma'] for t in ts)
        out += f" {v1:8.3f} {v21:5.3f} {ex:+6.3f} |"
    print(out)

print('\n== 21-tick excess variance attribution (realised component minus its budget allowance) ==')
print(f"{'group':26} {'arm':>4} {'excess':>7} " + ' '.join(f'{k:>7}' for k in AT))
for lab, ts in groups():
    if not ts:
        continue
    for arm, R in ((A, RA), (B, RB)):
        ds = [attr(R[t]) for t in ts]
        ex = st.mean(R[t]['v'][21]['tot'] - R[t]['sigma'] ** 2 for t in ts)
        print(f"{lab[:26]:26} {arm[:4]:>4} {ex:+7.4f} " + ' '.join(f"{st.mean(d[k] for d in ds):+7.4f}" for k in AT))

print(f'\n== names with V_I floored in {B} (beta^2 (anchor^2 + market jump var) >= 0.8 sigma^2, >50% of ticks) ==')
print(f"{'tk':5} {'sector':22} {'sig':>5} {'beta':>5} {'sysBlk':>7} {'0.8sig2':>7} {'sig_feas':>8} {'v21':>5}")
for t in sorted((t for t in names if RB[t]['au']['floor'] > 0.5), key=lambda t: -RB[t]['au']['sysBlock'] / RB[t]['sigma'] ** 2):
    r = RB[t]
    print(f"{t:5} {r['sector'][:22]:22} {r['sigma']:5.2f} {r['au']['beta']:5.2f} {r['au']['sysBlock']:7.4f} {0.8 * r['sigma'] ** 2:7.4f} {math.sqrt(r['au']['sysBlock'] / 0.8):8.3f} {math.sqrt(r['v'][21]['tot']):5.3f}")

print('\n== whole market ==')
for arm, R, S in ((A, RA, SA), (B, RB, SB)):
    ir = [x[0] for x in S['idx']]
    iv = [x[1] for x in S['idx']]
    th = st.mean(R[t]['au']['theta'] for t in names)
    cs = st.mean(R[t]['au']['curIdio'] for t in names)
    ratio = st.mean(R[t]['au']['curIdio'] / R[t]['au']['theta'] for t in names)
    print(f"{arm:5} EW index log return {st.mean(ir):+.3f}/y (se {st.stdev(ir) / 2:.3f}), 21-tick vol {st.mean(iv):.3f} (se {st.stdev(iv) / 2:.3f}); "
          f"failures/seed {st.mean(S['dead']):.2f} {S['dead']}; NaN rows {S['nan']}; bad final px {S['badpx']}; "
          f"idio state {cs:.4f} vs theta {th:.4f} (mean ratio {ratio:.2f})")
