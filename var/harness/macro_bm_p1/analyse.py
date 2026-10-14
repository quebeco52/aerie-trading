#!/usr/bin/env python3
"""analyse.py <lo-hi> [arms a b]: macro -> business-model Part 1 targets, paired b - a by seed.

Quarters reported after year BURN (2). Per seed a statistic per arm; the table gives mean A, mean B, paired mean
difference, its standard error (sd of differences / sqrt n) and t.
"""
import json, math, sys, os

H = os.path.dirname(os.path.abspath(__file__))
BURN = 2.0
lo, hi = map(int, sys.argv[1].split('-'))
arms = sys.argv[2:] or ['a', 'b']
SEEDS = list(range(lo, hi + 1))

REITS = ['SHOR', 'PLZA', 'ELDE', 'KITE']
INSURERS = ['DOVE', 'SAFE']
BANKS = ['LAKE', 'RIVR', 'PLVR']
IMPORTERS = ['FALC', 'EIDR', 'SHER', 'PENG', 'CBIL', 'MAGP', 'PHIL', 'SGRB', 'CROP', 'LARK', 'PINT', 'IBIS', 'PEAC']
UTILS = ['BIRD', 'WADE']
FINANCIAL = set(BANKS + INSURERS + ['CROW', 'ROOK', 'ACC', 'TALN', 'STRK', 'EGRT', 'VULT', 'PELI', 'SWAN', 'KING', 'PERE',
                                    'CORV', 'PTRL', 'BRKW', 'BNTH', 'TIER', 'POOL'])


def load(arm, seed):
    with open(f'{H}/runs/{arm}-{seed}.jsonl') as f:
        return json.loads(f.readline())


def rows(rec, t):
    return [q for q in rec['final'].get(t, {}).get('q', []) if q['t'] > BURN]


def mean(x):
    return sum(x) / len(x) if x else float('nan')


def ols(x, y):
    mx, my = mean(x), mean(y)
    sxx = sum((a - mx) ** 2 for a in x)
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / sxx if sxx > 0 else float('nan')


def corr(x, y):
    mx, my = mean(x), mean(y)
    sxx = sum((a - mx) ** 2 for a in x)
    syy = sum((b - my) ** 2 for b in y)
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / math.sqrt(sxx * syy) if sxx > 0 and syy > 0 else float('nan')


def sd(x):
    m = mean(x)
    return math.sqrt(sum((a - m) ** 2 for a in x) / (len(x) - 1)) if len(x) > 1 else float('nan')


def demean(v):
    m = mean(v)
    return [a - m for a in v]


recs = {a: {s: load(a, s) for s in SEEDS} for a in arms}
out = []


def line(label, fn, scale=1.0, fmt='{:+.3f}'):
    """fn(rec) -> per-seed statistic or None; paired over seeds where both arms have it."""
    va = {s: fn(recs[arms[0]][s]) for s in SEEDS}
    vb = {s: fn(recs[arms[1]][s]) for s in SEEDS}
    ok = [s for s in SEEDS if va[s] is not None and vb[s] is not None and not math.isnan(va[s]) and not math.isnan(vb[s])]
    a = [va[s] * scale for s in ok]
    b = [vb[s] * scale for s in ok]
    d = [y - x for x, y in zip(a, b)]
    se = sd(d) / math.sqrt(len(d)) if len(d) > 1 else float('nan')
    t = mean(d) / se if se and se > 0 else float('nan')
    out.append(f'{label:<44} {fmt.format(mean(a)):>9} {fmt.format(mean(b)):>9} {fmt.format(mean(d)):>9} {fmt.format(se):>8} {t:>+7.1f} {len(d):>3}')


def header(title):
    out.append('')
    out.append(f'== {title}')
    out.append(f'{"quantity":<44} {"A":>9} {"B":>9} {"B-A":>9} {"se":>8} {"t":>7} {"n":>3}')


# --- 1. REIT rents vs the real 10y ---
def reit_corr(key):
    def f(rec):
        xs, ys = [], []
        for t in REITS:
            q = rows(rec, t)
            x, y = [], []
            for p, c in zip(q, q[1:]):
                dr = (c['y10'] - c['be']) - (p['y10'] - p['be'])
                if key == 'lease':
                    lp, lc = p['str'].get('lease', 0), c['str'].get('lease', 0)
                    if lp <= 0 or lc <= 0:
                        continue
                    yv = math.log(lc / lp)
                else:
                    yv = c['kpi'].get('in_place_rent_index', 0) - p['kpi'].get('in_place_rent_index', 0)
                x.append(dr); y.append(yv)
            if len(x) > 4:
                xs += demean(x); ys += demean(y)
        return corr(xs, ys) if xs else None
    return f


header('1. REIT: corr(quarterly change, d real 10y), 4 REITs pooled within seed')
line('corr(d in_place_rent_index, d real10y)', reit_corr('ipr'))
line('corr(dlog lease revenue, d real10y)', reit_corr('lease'))


def reit_aux(kind):
    """SHOR only (the rent KPI is macro-driven and identical across REITs): level correlations and the partial
    correlation of the quarterly change given the change in the output-gap EMA."""
    def f(rec):
        q = rows(rec, 'SHOR')
        if len(q) < 8:
            return None
        rr = [c['y10'] - c['be'] for c in q]
        if kind == 'ipr_level':
            return corr(rr, [c['kpi'].get('in_place_rent_index', 0) for c in q])
        if kind == 'spread_level':
            return corr(rr, [c['kpi'].get('releasing_spread', 0) for c in q])
        dx = [b - a for a, b in zip(rr, rr[1:])]
        dy = [c['kpi'].get('in_place_rent_index', 0) - p['kpi'].get('in_place_rent_index', 0) for p, c in zip(q, q[1:])]
        dg = [c['gap'] - p['gap'] for p, c in zip(q, q[1:])]
        rxy, rxg, ryg = corr(dx, dy), corr(dx, dg), corr(dy, dg)
        return (rxy - rxg * ryg) / math.sqrt((1 - rxg ** 2) * (1 - ryg ** 2))
    return f


line('SHOR corr(level in_place_rent, level real10y)', reit_aux('ipr_level'))
line('SHOR corr(level releasing_spread, real10y)', reit_aux('spread_level'))
line('SHOR partial corr(d in_place, d real10y | d gap)', reit_aux('partial'))
for t in REITS:
    def one(rec, t=t):
        q = rows(rec, t)
        x = [(c['y10'] - c['be']) - (p['y10'] - p['be']) for p, c in zip(q, q[1:])]
        y = [c['kpi'].get('in_place_rent_index', 0) - p['kpi'].get('in_place_rent_index', 0) for p, c in zip(q, q[1:])]
        return corr(x, y) if len(x) > 4 else None
    line(f'  {t} corr(d in_place_rent, d real10y)', one)


# --- 2. Insurer loss costs vs claims-inflation excess ---
def excess(q):
    return 0.40 * q['cg'] + 0.60 * q['sc'] - 0.02


header('2. Insurers: slope of loss_ratio on claims-inflation excess at lag k quarters; means')
for t in INSURERS:
    for k in range(5):
        def f(rec, t=t, k=k):
            q = rows(rec, t)
            if len(q) <= k + 4:
                return None
            x = [excess(q[i - k]) for i in range(k, len(q))]
            y = [q[i]['kpi'].get('loss_ratio', 0) for i in range(k, len(q))]
            return ols(x, y)
        line(f'{t} slope loss_ratio ~ excess lag {k}', f)
    line(f'{t} mean loss_ratio', lambda r, t=t: mean([q['kpi'].get('loss_ratio', 0) for q in rows(r, t)]) or None, fmt='{:.4f}')
    line(f'{t} mean variable_cost_ratio', lambda r, t=t: mean([q['kpi'].get('variable_cost_ratio', 0) for q in rows(r, t)]) or None, fmt='{:.4f}')
    line(f'{t} mean EBIT margin', lambda r, t=t: mean([q['om'] for q in rows(r, t)]) if rows(r, t) else None, fmt='{:.4f}')
line('mean claims-inflation excess (macro)', lambda r: mean([excess(q) for q in rows(r, 'DOVE')]) if rows(r, 'DOVE') else None, fmt='{:+.4f}')


# --- 3. Commercial banks: NIM vs policy rate ---
def nim(q):
    return 4.0 * (q['str'].get('net_interest_income', 0.0) - q['int']) / q['ea'] if q['ea'] > 0 else None


header('3. Commercial banks: NIM = 4(NII stream - interest expense)/earning assets; ROE = 4 NI/equity')
for t in BANKS:
    def sl(rec, t=t):
        q = [r for r in rows(rec, t) if nim(r) is not None]
        return ols([r['pr'] for r in q], [nim(r) for r in q]) if len(q) > 4 else None
    line(f'{t} slope NIM ~ policyRateEma', sl)
    line(f'{t} mean NIM', lambda r, t=t: mean([nim(q) for q in rows(r, t) if nim(q) is not None]) if rows(r, t) else None, fmt='{:.4f}')
    line(f'{t} mean ROE', lambda r, t=t: mean([4 * q['ni'] / q['eq'] for q in rows(r, t) if q['eq'] > 0]) if rows(r, t) else None, fmt='{:.4f}')
line('mean systemDepositBetaEma (macro)', lambda r: mean([q['dbeta'] for q in rows(r, 'LAKE')]) if rows(r, 'LAKE') else None, fmt='{:.4f}')
line('mean policyRateEma (macro)', lambda r: mean([q['pr'] for q in rows(r, 'LAKE')]) if rows(r, 'LAKE') else None, fmt='{:.4f}')

# --- Also: importers ---
header('Importers: mean importPriceDeviation; slope of cost ratio (opex/revenue) on exchangeRateGap')
line('mean importPriceDeviation (macro)', lambda r: mean([q['ipd'] for q in rows(r, 'FALC')]) if rows(r, 'FALC') else None, fmt='{:+.4f}')


def imp_slope(rec):
    v = []
    for t in IMPORTERS:
        q = [r for r in rows(rec, t) if r['rev'] > 0]
        if len(q) > 4:
            v.append(ols([r['fxg'] for r in q], [r['opc'] / r['rev'] for r in q]))
    v = [a for a in v if not math.isnan(a)]
    return mean(v) if v else None


line('importer-average slope cost ratio ~ fxGap', imp_slope)
for t in IMPORTERS:
    def s1(rec, t=t):
        q = [r for r in rows(rec, t) if r['rev'] > 0]
        return ols([r['fxg'] for r in q], [r['opc'] / r['rev'] for r in q]) if len(q) > 4 else None
    line(f'  {t} slope cost ratio ~ fxGap', s1)

# --- Also: utilities ---
header('Utilities: slope of EBIT margin on yield30yEma')
for t in UTILS:
    line(f'{t} slope EBIT margin ~ y30', lambda r, t=t: ols([q['y30'] for q in rows(r, t)], [q['om'] for q in rows(r, t)]) if len(rows(r, t)) > 4 else None)
    line(f'{t} mean EBIT margin', lambda r, t=t: mean([q['om'] for q in rows(r, t)]) if rows(r, t) else None, fmt='{:.4f}')

# --- Also: PENG ---
header('PENG: shortage rate per reported quarter by GSCPI-EMA bin; mean EBIT margin')
BINS = [('<0', lambda g: g < 0), ('0-1', lambda g: 0 <= g <= 1), ('>1', lambda g: g > 1)]
for arm in arms:
    hits = {b: [0, 0] for b, _ in BINS}
    for s in SEEDS:
        rec = recs[arm][s]
        ev = {round(e['t'], 3) for e in rec['events'].get('PENG', []) if e['d'] == 'SHORTAGE'}
        for q in rows(rec, 'PENG'):
            for b, f in BINS:
                if f(q['gscpi']):
                    hits[b][1] += 1
                    hits[b][0] += 1 if round(q['t'], 3) in ev else 0
    out.append(f'  arm {arm}: ' + '  '.join(f'{b}: {h[0]}/{h[1]} = {h[0] / max(1, h[1]):.3f}' for b, h in hits.items()))
line('PENG shortage share of quarters', lambda r: len([e for e in r['events'].get('PENG', []) if e['d'] == 'SHORTAGE' and e['t'] > BURN]) / max(1, len(rows(r, 'PENG'))), fmt='{:.3f}')
line('PENG mean EBIT margin', lambda r: mean([q['om'] for q in rows(r, 'PENG')]) if rows(r, 'PENG') else None, fmt='{:.4f}')

# --- Also: housing starts ---
header('Housing starts index: sd of yoy log change (quarterly samples after year 2)')


def hs_sd(rec):
    q = [m for m in rec['macro_q'] if m['t'] > BURN - 1]
    y = [math.log(c['hs'] / p['hs']) for p, c in zip(q, q[4:]) if c['t'] > BURN]
    return sd(y)


line('sd yoy dlog housingStartsIndex', hs_sd, fmt='{:.4f}')

# --- Also: EBIT margin by non-financial firm ---
header('B - A mean EBIT margin, non-financial firms (pp); * = |diff| > 1pp')
tickers = sorted({t for s in SEEDS for t in recs[arms[0]][s]['final']} - FINANCIAL)
for t in tickers:
    line(f'{t}', lambda r, t=t: mean([q['om'] for q in rows(r, t)]) if rows(r, t) else None, scale=100.0, fmt='{:+.2f}')
    d = float(out[-1].split()[3])
    if abs(d) > 1.0:
        out[-1] += ' *'
dead = {a: sum(len(recs[a][s]['dead']) for s in SEEDS) for a in arms}
out.append('')
out.append(f'deaths over all seeds: ' + ', '.join(f'{a} {dead[a]}' for a in arms))
print(f'seeds {lo}-{hi}, arms {arms[0]} vs {arms[1]}, quarters after year {BURN:g}')
print('\n'.join(out))
