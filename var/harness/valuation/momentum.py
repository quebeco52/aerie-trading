#!/usr/bin/env python3
"""momentum.py <seeds> <arm> [<arm> ...]: momentum and post-earnings drift anatomy from runs/<arm>-<seed>.val.json,
with paired differences of each later arm against the first.

Per seed (year 1 skipped, quintile spreads of next-period simple return, top minus bottom):
- mom 12-0 / 12-1: sorted on the past 4 quarters' log return / the past 3 quarters' (the latest quarter skipped);
  mom>0 years: share of years with a positive 12-1 spread.
- pead 1q x-rep: sorted on the quarter's report-day move (channel rep), the next quarter's return without its report;
  pead 2q: the next two quarters' total return.
- slope news / noise: cross-sectional slope of next-year log return on the past year's report+warning moves / own
  moves (idio, own jumps, sector, flow); news share: their variance share of the past year's return.
- rho dF: cross-sectional slope of next-year d log F on past-year d log F (revision persistence).
- E/P spread: next-year return, cheapest minus dearest fifth by trailing E/P; gap sd: cross-sectional sd of log(P/F).
- blowups: names with any quarter's |log return| > 1.5, counted before CLEAN.
- CLEAN=<x> (env, diagnostic): drop names with any quarter's |log return| above x.
- momshare med / max: quarterly board median of the momentum agents' capital share, run mean / run max (agent_rows).
- rev autocorr: cross-sectional slope of next quarter's change in believed franchise power on this quarter's
  (franchise_rows; believed = power - belief gap), the consensus revision's persistence.
- vol med: median name's realised annual vol (daily); vol idx: index realised vol; defaults: count per run.
"""
import json, math, os, statistics as st, sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import mean_se, paired, fmt  # noqa: E402

H = os.path.dirname(os.path.abspath(__file__))
NAMES = ['mkt', 'sec', 'idio', 'pull', 'brk', 'jI', 'jS', 'ma', 'pol', 'flow', 'fund', 'warn', 'rep', 'betw', 'dfv']
IX = {n: 6 + i for i, n in enumerate(NAMES)}
PRICE = NAMES[:14]


def seeds_of(arg):
    if '-' in arg:
        a, b = arg.split('-')
        return list(range(int(a), int(b) + 1))
    return [int(x) for x in arg.split(',')]


def spread(xs, key, val):
    z = sorted(xs, key=key)
    k = len(z) // 5
    return st.fmean(val(x) for x in z[-k:]) - st.fmean(val(x) for x in z[:k])


def slope(xs, a, b):
    ma, mb = st.fmean(x[a] for x in xs), st.fmean(x[b] for x in xs)
    v = sum((x[a] - ma) ** 2 for x in xs)
    return sum((x[a] - ma) * (x[b] - mb) for x in xs) / v if v > 0 else 0.0


def measure(arm, seed):
    D = json.load(open(f'{H}/runs/{arm}-{seed}.val.json'))
    rows, tpy = D['rows'], D['tpy']
    T = len(rows)
    tks = sorted({tk for _, snap in rows for tk in snap})
    P = {tk: [None] * T for tk in tks}
    for i, (_, snap) in enumerate(rows):
        for tk, v in snap.items():
            P[tk][i] = v
    blowups = sum(1 for tk in tks if any(v is not None and abs(sum(v[6:20])) > 1.5 for v in P[tk]))
    if os.environ.get('CLEAN'):  # diagnostic: drop names with any quarter's |log return| > CLEAN
        lim = float(os.environ['CLEAN'])
        tks = [tk for tk in tks if all(v is None or abs(sum(v[6:20])) <= lim for v in P[tk])]
    q0 = next(i for i, (t, _) in enumerate(rows) if t >= 1.0 - 1e-6)

    def ch(tk, i, names):
        v = P[tk][i]
        return None if v is None else sum(v[IX[n]] for n in names)

    def dF(tk, i):
        a, b = P[tk][i - 1], P[tk][i]
        if not a or not b or a[2] <= 0 or b[2] <= 0:
            return None
        return math.log(b[2] * (b[0] / b[1]) / (a[2] * (a[0] / a[1])))

    out = {}
    p1, p2 = [], []
    for i in range(q0, T - 2):
        xs = []
        for tk in tks:
            r = ch(tk, i, ['rep'])
            n1x = ch(tk, i + 1, [n for n in PRICE if n != 'rep'])
            n1, n2 = ch(tk, i + 1, PRICE), ch(tk, i + 2, PRICE)
            if None in (r, n1x, n1, n2) or r == 0.0:
                continue
            xs.append((r, n1x, n1 + n2))
        if len(xs) >= 25:
            p1.append(spread(xs, lambda x: x[0], lambda x: math.exp(x[1]) - 1))
            p2.append(spread(xs, lambda x: x[0], lambda x: math.exp(x[2]) - 1))
    out['pead 1q x-rep'] = st.fmean(p1)
    out['pead 2q'] = st.fmean(p2)

    m0, m1, fn, fo, rho, share, ep, gsd = [], [], [], [], [], [], [], []
    for i in [i for i in range(q0 + 4, T - 4) if (i - q0) % 4 == 0]:
        xs = []
        for tk in tks:
            if any(P[tk][j] is None for j in range(i - 4, i + 5)):
                continue
            f0 = [dF(tk, j) for j in range(i - 3, i + 1)]
            f1 = [dF(tk, j) for j in range(i + 1, i + 5)]
            if None in f0 + f1 or P[tk][i][1] <= 0 or P[tk][i][2] <= 0:
                continue
            xs.append({
                'past': sum(ch(tk, j, PRICE) for j in range(i - 3, i + 1)),
                'skip': sum(ch(tk, j, PRICE) for j in range(i - 3, i)),
                'nxt': sum(ch(tk, j, PRICE) for j in range(i + 1, i + 5)),
                'news': sum(ch(tk, j, ['rep', 'warn']) for j in range(i - 3, i + 1)),
                'noise': sum(ch(tk, j, ['idio', 'jI', 'sec', 'flow', 'fund']) for j in range(i - 3, i + 1)),
                'dF': sum(f0), 'dF1': sum(f1),
                'ep': P[tk][i][3] / P[tk][i][1], 'g': math.log(P[tk][i][1] / P[tk][i][2]),
            })
        if len(xs) < 25:
            continue
        simple = lambda x: math.exp(x['nxt']) - 1
        m0.append(spread(xs, lambda x: x['past'], simple))
        m1.append(spread(xs, lambda x: x['skip'], simple))
        ep.append(spread(xs, lambda x: x['ep'], simple))
        fn.append(slope(xs, 'news', 'nxt'))
        fo.append(slope(xs, 'noise', 'nxt'))
        rho.append(slope(xs, 'dF', 'dF1'))
        share.append(st.pvariance([x['news'] for x in xs]) / st.pvariance([x['past'] for x in xs]))
        gsd.append(st.pstdev([x['g'] for x in xs]))
    out.update({'mom 12-0': st.fmean(m0), 'mom 12-1': st.fmean(m1), 'mom>0 years': sum(1 for x in m1 if x > 0) / len(m1),
                'slope news': st.fmean(fn), 'slope noise': st.fmean(fo), 'news share': st.fmean(share),
                'rho dF': st.fmean(rho), 'E/P spread': st.fmean(ep), 'gap sd': st.fmean(gsd)})
    vols = [math.sqrt(s / n * tpy) for s, n in D['rv'].values() if n > 0]
    out['vol med'] = st.median(vols)
    out['vol idx'] = math.sqrt(D['rvIndex'][0] / D['rvIndex'][1] * tpy) if D['rvIndex'][1] else math.nan
    ag = [r for r in D.get('agent_rows', []) if r.get('mom_med') is not None and r['t'] >= 1.0]
    out['momshare med'] = st.fmean(r['mom_med'] for r in ag) if ag else math.nan
    out['momshare max'] = max(r['mom_max'] for r in ag) if ag else math.nan
    fr = D.get('franchise_rows', [])
    rev = []
    for (t0, a), (t1, b), (t2, c) in zip(fr, fr[1:], fr[2:]):
        if t0 < 1.0:
            continue
        xs = [((b[k][0] - b[k][1]) - (a[k][0] - a[k][1]), (c[k][0] - c[k][1]) - (b[k][0] - b[k][1])) for k in a if k in b and k in c]
        if len(xs) >= 25:
            rev.append(slope([{'x': x, 'y': y} for x, y in xs], 'x', 'y'))
    out['rev autocorr'] = st.fmean(rev) if rev else math.nan
    J = json.load(open(f'{H}/runs/{arm}-{seed}.jsonl'))
    out['defaults'] = len(J.get('defaults', []))
    out['blowups'] = blowups
    return out


def main():
    seeds = seeds_of(sys.argv[1])
    arms = sys.argv[2:]
    per = {arm: {s: measure(arm, s) for s in seeds if os.path.exists(f'{H}/runs/{arm}-{s}.val.json')} for arm in arms}
    keys = list(next(iter(per[arms[0]].values())).keys())
    head = f"{'measure':16s}" + ''.join(f'{a + " mean (se)":>22s}' for a in arms) + ''.join(f'{a + " - " + arms[0]:>34s}' for a in arms[1:])
    print(head)
    for k in keys:
        line = f'{k:16s}'
        for a in arms:
            m = mean_se([v[k] for v in per[a].values()])
            line += f'{m.mean:+12.4f} ({m.se:.4f})'
        for a in arms[1:]:
            p = paired({s: v[k] for s, v in per[arms[0]].items()}, {s: v[k] for s, v in per[a].items()})
            line += f'{fmt(p, 4):>34s}'
        print(line)


if __name__ == '__main__':
    main()
