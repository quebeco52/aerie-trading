#!/usr/bin/env python3
"""recession_dr.py <seeds> A B: does the board fall in recessions, and what does it cost in volatility?

Per seed, from runs/<arm>-<seed>.val.json (quarterly rows) and .macro.jsonl (quarterly macro), year 1 skipped:
- recessions: gap troughs below -2% that are the lowest within +-6 quarters; the board's float-weighted log price
  return from the gap's peak in the 8 quarters before to the trough (`rec ret`), the same less the seed's mean quarter
  times the quarters (`rec excess`), and the share of episodes with a negative excess (`rec fell`).
- cycle: corr of the board's quarterly return with the gap change (`corr q`), of overlapping 4-quarter returns with the
  4-quarter gap change (`corr y`), with the NEXT 4-quarter gap change (`lead`), and of the quarter's return with the
  last quarter's gap change (`predict`: public news the price should already hold).
- volatility: the index's daily-return vol (rvIndex), quarterly sd x 2, annual sd of calendar-year returns, the
  variance ratio annual / (4 x quarterly), quarterly AR(1) of the board return, and the macro market vol in
  recessions (gap < -1%) over expansions (gap > 0).
- discount-rate channel (dr arms): sd of its quarterly sum, its annual sd, and its daily-read vol (`drv`).
- `erp->next y`: corr of the premium at a year's start (the quarter before's macro row) with that year's board return.
Paired B - A over the seeds both arms ran.
"""
import json, math, os, statistics as st, sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import mean_se, paired, fmt  # noqa: E402

H = os.path.dirname(os.path.abspath(__file__))


def corr(a, b):
    if len(a) < 3:
        return math.nan
    ma, mb, sa, sb = st.fmean(a), st.fmean(b), st.pstdev(a), st.pstdev(b)
    return sum((x - ma) * (y - mb) for x, y in zip(a, b)) / len(a) / (sa * sb) if sa > 0 and sb > 0 else math.nan


def seeds(spec):
    out = []
    for part in spec.split(','):
        a, _, b = part.partition('-')
        out += list(range(int(a), int(b or a) + 1))
    return out


def board(arm, sd):
    val = json.load(open(f'{H}/runs/{arm}-{sd}.val.json'))
    macro = {round(r['t'] * 4): r for r in map(json.loads, open(f'{H}/runs/{arm}-{sd}.macro.jsonl'))}
    rows = val['rows']
    out = []
    for i in range(1, len(rows)):
        t, cur = rows[i]
        _, prv = rows[i - 1]
        k = round(t * 4)
        w_sum = d_p = 0.0
        for tk, r in cur.items():
            p = prv.get(tk)
            if not p or p[0] <= 0 or r[0] <= 0:
                continue
            w_sum += p[5]
            d_p += p[5] * math.log(r[0] / p[0])
        m, mp = macro.get(k), macro.get(k - 1)
        if not m or not mp or w_sum <= 0 or t < 1.0:
            continue
        out.append({'k': k, 't': t, 'dP': d_p / w_sum, 'gap': m['gap'], 'dgap': m['gap'] - mp['gap'], 'vol': m['vol'],
                    'drq': m.get('drq', 0.0), 'drv': m.get('drv', 0.0), 'erp': mp.get('erp', math.nan)})
    return out, val


def measures(arm, sd):
    B, val = board(arm, sd)
    mu = st.fmean(q['dP'] for q in B)
    eps = []
    for i in range(6, len(B) - 1):
        g = B[i]['gap']
        if g < -0.02 and all(g <= B[j]['gap'] for j in range(max(0, i - 6), min(len(B), i + 7))):
            pk = max(range(max(0, i - 8), i), key=lambda j: B[j]['gap'])
            ret = sum(B[j]['dP'] for j in range(pk + 1, i + 1))
            eps.append((ret, ret - (i - pk) * mu))
    a4, g4, f4 = [], [], []
    for i in range(4, len(B)):
        a4.append(sum(B[j]['dP'] for j in range(i - 3, i + 1)))
        g4.append(B[i]['gap'] - B[i - 4]['gap'])
        f4.append(B[i + 4]['gap'] - B[i]['gap'] if i + 4 < len(B) else None)
    lead = [(a, f) for a, f in zip(a4, f4) if f is not None]
    years = {}
    for q in B:
        years.setdefault(int(q['t'] - 1e-9), []).append(q['dP'])
    annual = [sum(v) for v in years.values() if len(v) == 4]
    dq = [q['dP'] for q in B]
    rv = val.get('rvIndex', [0.0, 0])
    tpy = 360.0
    rec = [q['vol'] for q in B if q['gap'] < -0.01]
    exp_ = [q['vol'] for q in B if q['gap'] > 0.0]
    dr = [q['drq'] for q in B]
    dr_years = {}
    for q in B:
        dr_years.setdefault(int(q['t'] - 1e-9), []).append(q['drq'])
    # the premium at a year's start against that year's board return (non-overlapping years)
    prem = [(B[i]['erp'], sum(B[j]['dP'] for j in range(i, i + 4))) for i in range(0, len(B) - 3, 4) if B[i]['erp'] == B[i]['erp']]
    return {
        'recessions': len(eps),
        'rec ret': st.median(e[0] for e in eps) if eps else math.nan,
        'rec excess': st.median(e[1] for e in eps) if eps else math.nan,
        'rec fell': sum(1 for e in eps if e[1] < 0) / len(eps) if eps else math.nan,
        'corr q': corr([q['dP'] for q in B], [q['dgap'] for q in B]),
        'corr y': corr(a4, g4),
        'lead': corr([a for a, _ in lead], [f for _, f in lead]),
        'predict': corr([B[i]['dP'] for i in range(1, len(B))], [B[i - 1]['dgap'] for i in range(1, len(B))]),
        'vol daily': math.sqrt(rv[0] / rv[1] * tpy) if rv[1] else math.nan,
        'vol q': st.pstdev(dq) * 2.0,
        'vol y': st.pstdev(annual),
        'VR y/q': st.pvariance(annual) / (4.0 * st.pvariance(dq)),
        'AR1 q': corr(dq[1:], dq[:-1]),
        'mvol rec/exp': st.fmean(rec) / st.fmean(exp_) if rec and exp_ else math.nan,
        'dr sd q': st.pstdev(dr),
        'dr sd y': st.pstdev([sum(v) for v in dr_years.values() if len(v) == 4]),
        'dr vol daily': math.sqrt(st.fmean(q['drv'] for q in B)),
        'erp->next y': corr([a for a, _ in prem], [b for _, b in prem]) if len(prem) > 3 else math.nan,
    }


def main():
    sds = seeds(sys.argv[1])
    arms = sys.argv[2:]
    per = {a: {sd: measures(a, sd) for sd in sds if os.path.exists(f'{H}/runs/{a}-{sd}.macro.jsonl')} for a in arms}
    keys = list(next(iter(per[arms[0]].values())).keys())
    print(f"{'measure':14s}" + ''.join(f'{a + " mean (se)":>20s}' for a in arms) + ''.join(f'{a + " - " + arms[0]:>32s}' for a in arms[1:]))
    for k in keys:
        line = f'{k:14s}'
        for a in arms:
            m = mean_se([x[k] for x in per[a].values() if x[k] == x[k]])
            line += f'{m.mean:>11.3f} ({m.se:.3f})'
        for a in arms[1:]:
            line += f"{fmt(paired({s: x[k] for s, x in per[arms[0]].items() if x[k] == x[k]}, {s: x[k] for s, x in per[a].items() if x[k] == x[k]})):>32s}"
        print(line)
    print('recession episodes per arm:', {a: sum(x['recessions'] for x in per[a].values()) for a in arms})


if __name__ == '__main__':
    main()
