#!/usr/bin/env python3
"""Output-gap growth decomposition: python3 gap_decomp.py [arm=G1].

Reads runs/<arm>-*.gap.jsonl (the engine's OutputGapProbe rolled every tick: per-channel rate*dt, diffusion, clamp,
unexplained, external) and runs/<arm>-*.tick.csv (gap, potential). TPY 360, year 1 dropped.

Alignment: C_i(t) = cumulative sum of piece i's per-tick increments; gap(t) = gap(0) + sum_i C_i(t). The quarterly
average gap is mean over the quarter's 90 ticks, so piece_i(q) = mean_q C_i - mean_{q-1} C_i, and sum_i piece_i(q) =
quarterly-average Delta gap exactly (a triangular-weighted sum of the increments over the two quarters).
Stats are per seed (demeaned within seed), then mean and se across seeds.
  covshare_i = sum_t p_i(t) D(t) / sum_t D(t)^2             (sums to 1)
  acfpart_i  = sum_{t>=2} p_i(t) D(t-1) / sum_t D(t)^2      (sums to ACF1 of D)
"""
import glob, json, math, os, sys

H = os.path.dirname(os.path.abspath(__file__))
ARM = sys.argv[1] if len(sys.argv) > 1 else 'G1'
TPY, TPQ = 360, 90
EXTRA = ['diffusion', 'clamp', 'unexplained', 'external']


def mean(x):
    return sum(x) / len(x)


def dm(x):
    m = mean(x)
    return [v - m for v in x]


def sd(x):
    d = dm(x)
    return math.sqrt(sum(v * v for v in d) / (len(d) - 1))


def acf1(x):
    d = dm(x)
    den = sum(v * v for v in d)
    return sum(a * b for a, b in zip(d[1:], d[:-1])) / den if den > 1e-30 else float('nan')


def qavg(x):
    n = len(x) // TPQ
    return [sum(x[q * TPQ:(q + 1) * TPQ]) / TPQ for q in range(n)]


def diff(x):
    return [b - a for a, b in zip(x[:-1], x[1:])]


def cumsum(x):
    out, s = [], 0.0
    for v in x:
        s += v
        out.append(s)
    return out


per = []
names = None
for f in sorted(glob.glob(f'{H}/runs/{ARM}-*.gap.jsonl')):
    rows = [json.loads(l) for l in open(f) if l.strip()]
    if len(rows) < 21 * TPY:
        continue
    if names is None:
        names = list(rows[0]['c'].keys()) + EXTRA
    K = len(names)
    cols = [[r['c'].get(k, 0.0) for r in rows] for k in names[:-4]] + [[r[k] for r in rows] for k in ('d', 'cl', 'u', 'x')]
    g = [r['g'] for r in rows]
    C = [cumsum(c) for c in cols]
    chk = max(abs(rows[0]['o'] + sum(C[i][t] for i in range(K)) - g[t]) for t in range(len(g)))
    tk = {}
    for line in open(f.replace('.gap.jsonl', '.tick.csv')):
        a = line.strip().split(',')
        if len(a) >= 9:
            tk[int(a[0])] = (float(a[1]), float(a[8]))
    ticks = [r['t'] for r in rows]
    gtick = [tk[t][0] for t in ticks]
    pot = [tk[t][1] for t in ticks]
    tickchk = max(abs(a - b) for a, b in zip(gtick, g))
    # quarters q=0..; drop year 1; Delta from quarter 5 so both quarters are in years 2+
    P = [diff(qavg(C[i]))[4:] for i in range(K)]
    Gq = qavg(g)
    D = diff(Gq)[4:]
    nq = len(D)
    resid = max(abs(sum(P[i][t] for i in range(K)) - D[t]) for t in range(nq))
    Yq = qavg([pp * (1 + gg) for pp, gg in zip(pot, g)])
    Pq = qavg(pot)
    y = diff([math.log(v) for v in Yq])[4:]
    p = diff([math.log(v) for v in Pq])[4:]
    Dd = dm(D)
    v = sum(a * a for a in Dd)
    Pd = [dm(P[i]) for i in range(K)]
    yd, pd = dm(y), dm(p)
    vy = sum(a * a for a in yd)
    per.append(dict(
        chk=chk, tickchk=tickchk, resid=resid,
        sd=[sd(P[i]) for i in range(K)],
        cov=[sum(a * b for a, b in zip(Pd[i], Dd)) / v for i in range(K)],
        acf=[acf1(P[i]) for i in range(K)],
        part=[sum(a * b for a, b in zip(Pd[i][1:], Dd[:-1])) / v for i in range(K)],
        D_sd=sd(D), D_acf=acf1(D), y_sd=sd(y), y_acf=acf1(y), p_sd=sd(p), p_acf=acf1(p),
        p_cov=sum(a * b for a, b in zip(pd, yd)) / vy, p_part=sum(a * b for a, b in zip(pd[1:], yd[:-1])) / vy,
        gap_sd=sd(Gq[4:]), nq=nq))


def ms(key, idx=None):
    a = [d[key] if idx is None else d[key][idx] for d in per]
    a = [x for x in a if not math.isnan(x)]
    if not a:
        return float('nan'), float('nan')
    return mean(a), (sd(a) / math.sqrt(len(a)) if len(a) > 1 else float('nan'))


n = len(per)
print(f'arm {ARM}: n={n} seeds x 20 years after year 1 ({per[0]["nq"]} quarterly Deltas/seed), TPY {TPY}')
print(f'checks (max abs, fractions of potential): probe chain vs gap {max(d["chk"] for d in per):.1e}; '
      f'probe gap vs tick.csv gap {max(d["tickchk"] for d in per):.1e}; sum of pieces - Delta gap {max(d["resid"] for d in per):.1e}')
print()
print('Growth moments (quarterly, pp; mean over seeds, se)                US')
for lab, k, us in [('Delta gap (quarterly avg) sd', 'D_sd', '0.52'), ('Delta gap ACF1', 'D_acf', ''),
                   ('real GDP growth sd [pot x (1+gap)]', 'y_sd', ''), ('real GDP growth ACF1', 'y_acf', '0.37'),
                   ('potential growth sd', 'p_sd', ''), ('potential growth ACF1', 'p_acf', ''),
                   ('potential: cov share of var(real growth)', 'p_cov', ''),
                   ('potential: part of real-growth ACF1', 'p_part', ''), ('gap level sd', 'gap_sd', '1.62')]:
    m, s = ms(k)
    sc = 100 if k in ('D_sd', 'y_sd', 'p_sd', 'gap_sd') else 1
    print(f'  {lab:42s} {m * sc:8.3f} ({s * sc:.3f})  {us}')
print()
order = sorted(range(len(names)), key=lambda i: -abs(ms('part', i)[0] if not math.isnan(ms('part', i)[0]) else 0))
print(f'{"channel":26s} {"sd pp":>7s} {"cov share":>15s} {"own ACF1":>14s} {"part of ACF1(Dgap)":>20s}')
tot_c = tot_p = 0.0
for i in order:
    sdi, _ = ms('sd', i)
    if sdi * 100 < 5e-5:
        continue
    c, cs = ms('cov', i); a, as_ = ms('acf', i); pp, ps = ms('part', i)
    tot_c += c; tot_p += pp
    print(f'{names[i]:26s} {sdi * 100:7.4f} {c:7.3f} ({cs:.3f}) {a:6.2f} ({as_:.2f}) {pp:9.3f} ({ps:.3f})')
print(f'{"sum":26s} {"":7s} {tot_c:7.3f} {"":8s} {"":14s} {tot_p:9.3f}   [= ACF1 Delta gap {ms("D_acf")[0]:.3f}]')
