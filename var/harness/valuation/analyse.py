#!/usr/bin/env python3
"""analyse.py [seeds]: rate betas by sector (base vs new), log P/E slope on the real 10Y, sanity, ROIC-gap persistence
and post-report drift, from var/harness/valuation/runs/{base,new}-<seed>.jsonl. Pure Python (no numpy here).
Prints tables only."""
import json, math, os, sys

H = os.path.dirname(os.path.abspath(__file__))
RUNS = os.path.join(H, 'runs')
W = int(os.environ.get('VW', 21))   # return window, ticks (VW env)
Q = 90          # ticks per quarter at 360/yr
NAMES = ['PHIL', 'SGRB', 'CROP', 'LARK', 'PINT']
GROUPS = {
    'Staples': {'ConsumerStaples'},
    'Utilities': {'Utility', 'IndependentPower'},
    'REITs': {'Reit'},
    'Cyclicals': {'Homebuilder', 'SteelManufacturing', 'HeavyManufacturing', 'AutoManufacturer', 'Construction', 'Mining',
                  'Chemical', 'Shipping', 'Semiconductor', 'OilGasProducer', 'Refining', 'SpecialtyIndustrialMachinery',
                  'Luxury', 'ResortsCasinos', 'ApparelManufacturing', 'Logistics', 'ComputerHardware',
                  'CommunicationEquipment', 'ToolsAndAccessories', 'Restaurant'},
    'Growth': {'Tech', 'InternetRetail', 'Biotech', 'FinancialData'},
    'Financials': {'CommercialBank', 'PrivateBank', 'InvestmentBank', 'CustodyBank', 'Brokerage', 'CreditServices',
                   'ShadowBank', 'HedgeFund', 'AssetManagement', 'PrivateEquity', 'InvestmentCompany', 'DistressedDebt',
                   'ClearingHouse', 'RetailInsurance', 'Reinsurance', 'HealthInsurer', 'MerchantHouse'},
}
GORDER = ['Staples', 'Utilities', 'REITs', 'Cyclicals', 'Growth', 'Financials', 'Other']
NAN = float('nan')
RHO = 0.08      # discount rate for the omega -> permanent-equivalent comparison (assumed, typical CoE)


def mean(v):
    v = [x for x in v if x is not None and math.isfinite(x)]
    return sum(v) / len(v) if v else NAN


def std(v):
    m = mean(v)
    return math.sqrt(sum((x - m) ** 2 for x in v) / len(v)) if v else NAN


def slope(x, y):
    mx, my = mean(x), mean(y)
    sxx = sum((a - mx) ** 2 for a in x)
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / sxx if sxx > 0 else NAN


def corr(x, y):
    mx, my = mean(x), mean(y)
    sxx = sum((a - mx) ** 2 for a in x); syy = sum((b - my) ** 2 for b in y)
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / math.sqrt(sxx * syy) if sxx > 0 and syy > 0 else NAN


def partial(y, x1, x2):
    """OLS y = a + b1 x1 + b2 x2; returns b1 (Frisch-Waugh on demeaned data)."""
    m1, m2, my = mean(x1), mean(x2), mean(y)
    a = [v - m1 for v in x1]; b = [v - m2 for v in x2]; c = [v - my for v in y]
    s11 = sum(p * p for p in a); s22 = sum(q * q for q in b); s12 = sum(p * q for p, q in zip(a, b))
    s1y = sum(p * r for p, r in zip(a, c)); s2y = sum(q * r for q, r in zip(b, c))
    det = s11 * s22 - s12 * s12
    return (s22 * s1y - s12 * s2y) / det if det > 0 else NAN


def group(model):
    m = model.replace('BusinessModel', '')
    for g, s in GROUPS.items():
        if m in s:
            return g
    return 'Other'


def split_adj(sh):
    """A step in shares by more than 1.4x (or under 0.7x) is a split; its log is added to later log prices."""
    c = [0.0] * len(sh)
    acc = 0.0
    for i in range(1, len(sh)):
        if sh[i - 1] > 0 and sh[i] > 0:
            r = sh[i] / sh[i - 1]
            if r > 1.4 or r < 0.7:
                acc += math.log(r)
        c[i] = acc
    return c


def load(arm, seed):
    p = os.path.join(RUNS, f'{arm}-{seed}.jsonl')
    return json.loads(open(p).readline()) if os.path.exists(p) else None


def per_run(d):
    tpy = d['tpy']
    r10 = d['v_r10']; idx = d['v_idx']
    T = len(r10)
    out = {'beta': {}, 'beta1': {}, 'fvb': {}, 'pe': {}, 'grp': {}, 'gap': {}, 'drift': [], 'bad': 0}
    deadt = {t: int(y * tpy) for t, y in (d['dead'] or {}).items()}
    ratio_at = {yr: [] for yr in (0, 1, 5, 10, 15)}
    for tk, px in d['v_px'].items():
        fv = d['v_fv'][tk]; eps = d['v_eps'][tk]; sh = d['v_sh'][tk]
        end = min(T, deadt.get(tk, T + Q) - Q)  # stop a quarter before a failure
        bad = sum(1 for p in px[:end] if not (math.isfinite(p) and p > 0))
        out['bad'] += bad
        if end < 4 * W or bad:
            continue
        c = split_adj(sh)
        lp = [math.log(px[i]) + c[i] for i in range(end)]
        g = group(d['v_model'][tk]); out['grp'][tk] = g
        ks = list(range(0, end - W, W))
        y = [lp[k + W] - lp[k] for k in ks]
        dr = [r10[k + W] - r10[k] for k in ks]
        mk = [idx[k + W] - idx[k] for k in ks]
        out['beta'][tk] = partial(y, dr, mk)       # log-return per unit real yield = % per +100bp
        out['beta1'][tk] = slope(dr, y)
        lf = [math.log(fv[k]) + c[k] if fv[k] > 0 else NAN for k in ks] + [math.log(fv[ks[-1] + W]) + c[ks[-1] + W] if fv[ks[-1] + W] > 0 else NAN]
        fy = [lf[i + 1] - lf[i] for i in range(len(ks))]
        okf = [i for i in range(len(ks)) if math.isfinite(fy[i])]
        out['fvb'][tk] = slope([dr[i] for i in okf], [fy[i] for i in okf])
        okk = [k for k in ks if eps[k] > 0]
        if len(okk) > 20:
            out['pe'][tk] = 100 * slope([100 * r10[k] for k in okk], [math.log(px[k] / eps[k]) for k in okk])
        for yr in ratio_at:
            i = min(T - 1, int(yr * tpy))
            if i < end and fv[i] > 0:
                ratio_at[yr].append(fv[i] / px[i])
        lfv = [math.log(fv[i]) + c[i] if fv[i] > 0 else NAN for i in range(end)]
        reps = [(r[1], r[4] if len(r) > 4 else r[2], r[3]) for r in d['v_rep'] if r[0] == tk and r[1] <= end]
        out['gap'][tk] = {t: roic - lrr for t, roic, lrr in reps if lrr is not None}
        for t, _, _ in reps:
            k = t - 1                                # array index of the report tick
            if k < 2 or k + 1 + 4 * Q >= end:
                continue
            rev = lfv[k + 1] - lfv[k - 1]
            if not math.isfinite(rev):
                continue
            dr_h = [lp[k + 1 + h * Q] - lp[k + 1] for h in range(1, 5)]
            mk_h = [idx[k + 1 + h * Q] - idx[k + 1] for h in range(1, 5)]
            out['drift'].append((g, tk, rev, dr_h, mk_h))
    out['fvpx'] = {yr: mean(v) for yr, v in ratio_at.items()}
    ks = list(range(0, T - W, W))
    iw = [idx[k + W] - idx[k] for k in ks]
    out['idx_ret'] = 100 * idx[-1] / (T / tpy)
    out['idx_vol'] = 100 * std(iw) * math.sqrt(tpy / W)
    out['dead'] = len(d['dead']); out['reorg'] = len(d['reorgs'])
    return out


def ms(v):
    v = [x for x in v if x is not None and math.isfinite(x)]
    if not v:
        return NAN, NAN, 0
    m = sum(v) / len(v)
    se = math.sqrt(sum((x - m) ** 2 for x in v) / (len(v) - 1) / len(v)) if len(v) > 1 else NAN
    return m, se, len(v)


def row(label, a, b):
    """a, b: dict seed -> value. Per-arm means and the seed-paired difference (same macro path per seed)."""
    seeds = sorted(set(a) & set(b))
    ma = ms([a[s] for s in seeds])[0]; mb = ms([b[s] for s in seeds])[0]
    md, sd, n = ms([b[s] - a[s] for s in seeds])
    t = md / sd if sd and sd > 0 else NAN
    return f'{label:<12} {ma:9.2f} {mb:9.2f} {md:+9.2f} ({sd:5.2f}) t={t:+5.1f} n={n}'


def main():
    seeds = [int(s) for s in sys.argv[1].split(',')] if len(sys.argv) > 1 else range(1, 49)
    B, N, pair = {}, {}, []
    for s in seeds:
        db, dn = load('base', s), load('new', s)
        if db is None or dn is None:
            continue
        B[s] = per_run(db); N[s] = per_run(dn)
        same = tot = 0
        for tk in db['final']:
            for qb, qn in zip(db['final'][tk]['q'][:8], dn['final'][tk]['q'][:8]):
                tot += 1
                same += abs(qb['rev'] - qn['rev']) <= 1e-9 * max(1.0, abs(qb['rev']))
        pair.append(same / max(1, tot))
    print(f'seeds {sorted(B)}; first-2y quarterly revenue identical across arms in {100 * mean(pair):.0f}% of reports')

    def gmean(r, key, g):
        v = [x for tk, x in r[key].items() if r['grp'].get(tk) == g]
        return mean(v) if v else None

    def contrast(r, key):
        return mean([x for tk, x in r[key].items() if r['grp'].get(tk) in ('Staples', 'Utilities', 'REITs')]) \
            - mean([x for tk, x in r[key].items() if r['grp'].get(tk) == 'Cyclicals'])

    for key, title in (('beta', 'Rate beta, market held: % return per +100bp real 10Y (21-tick windows)'),
                       ('beta1', 'Rate beta, rate only: % return per +100bp real 10Y'),
                       ('fvb', 'Fair-value rate beta, rate only: % FV change per +100bp real 10Y (21-tick windows)'),
                       ('pe', 'Slope of log P/E (price/trailing EPS) on real 10Y level: % per +100bp')):
        print(f'\n{title}\n{"":<12} {"base":>9} {"new":>9} {"new-base":>9} (se)')
        for g in GORDER:
            a = {s: v for s in B if (v := gmean(B[s], key, g)) is not None}
            b = {s: v for s in N if (v := gmean(N[s], key, g)) is not None}
            print(row(g, a, b))
        for tk in NAMES:
            print(row(tk, {s: B[s][key][tk] for s in B if tk in B[s][key]}, {s: N[s][key][tk] for s in N if tk in N[s][key]}))
        print(row('LongDur-Cyc', {s: contrast(B[s], key) for s in B}, {s: contrast(N[s], key) for s in N}))

    print(f'\nSanity\n{"":<12} {"base":>9} {"new":>9} {"new-base":>9} (se)')
    for k, lab in (('idx_ret', 'idx ret %/y'), ('idx_vol', 'idx vol %'), ('dead', 'failures'), ('reorg', 'reorgs'), ('bad', 'NaN/0 px')):
        print(row(lab, {s: B[s][k] for s in B}, {s: N[s][k] for s in N}))
    for yr in (0, 1, 5, 10, 15):
        print(row(f'FV/px y{yr}', {s: B[s]['fvpx'][yr] for s in B}, {s: N[s]['fvpx'][yr] for s in N}))

    print('\nReturn gap (true return: roicTtm, roeTtm for financials, - longRunReturn) 4 reports apart: omega = slope, corr, pairs, sd(gap)')
    for arm, R in (('base', B), ('new', N)):
        acc = {}
        for r in R.values():
            for tk, gs in r['gap'].items():
                gm = mean(list(gs.values()))
                for t, v in gs.items():
                    if t + 4 * Q in gs:
                        for key in (r['grp'][tk], 'Board') + ((tk,) if tk in NAMES else ()):
                            acc.setdefault(key, []).append((v, gs[t + 4 * Q], v - gm, gs[t + 4 * Q] - gm))
        for key in ['Board'] + GORDER + NAMES:
            if len(acc.get(key, [])) < 10:
                continue
            x = [p[0] for p in acc[key]]; y = [p[1] for p in acc[key]]
            xd = [p[2] for p in acc[key]]; yd = [p[3] for p in acc[key]]
            om = slope(x, y)
            f = lambda w: RHO * w / (1 + RHO - w)
            dret = std(x) * (f(om) - f(0.62)) * 1e4
            print(f'{arm:<5} {key:<11} omega={om:5.2f} within-firm={slope(xd, yd):5.2f} corr={corr(x, y):5.2f} n={len(x):5d} '
                  f'sd(gap)={std(x):.3f} mean(gap)={mean(x):+.3f} f(om)/f(.62)={f(om) / f(0.62):4.2f} dRet@1sd={dret:+5.0f}bp')

    print('\nPost-report drift: corr(FV revision k-1..k+1, return k+1..k+1+h quarters), raw|market-adjusted')
    for arm, R in (('base', B), ('new', N)):
        rows = [d for r in R.values() for d in r['drift']]
        for key in ('Board', 'Staples', 'Utilities', 'REITs', 'Cyclicals', 'Financials'):
            sel = [d for d in rows if key == 'Board' or d[0] == key]
            if len(sel) < 20:
                continue
            rev = [d[2] for d in sel]
            cells = []
            for h in range(4):
                raw = [d[3][h] for d in sel]; ex = [d[3][h] - d[4][h] for d in sel]
                cells.append(f'{corr(rev, raw):+.3f}|{corr(rev, ex):+.3f}')
            print(f'{arm:<5} {key:<11} n={len(sel):5d} h1..4: ' + '  '.join(cells) + f'  sd(rev)={std(rev):.3f}')


main()
