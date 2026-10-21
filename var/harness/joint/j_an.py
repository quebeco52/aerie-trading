#!/usr/bin/env python3
"""j_an.py <seeds> <armA> <armB>: batch J (POOL capital target, board-wide anti-dilution repurchase).
A SWAN/POOL: as i_an.py plus the equity bridge residual and split-adjusted share count end/start.
B every firm without a capital target (CAPT below): equity bridge residual share of d(eq), equity CAGR, NI/avg eq
  first 2y vs last 2y, cumulative buybacks $B, mean cash/revenue(4q), min cash/revenue; by business model, then the
  firms that moved most, then firms whose borrowing events or cash floor moved.
C board payout (sum div+bb / sum of positive per-firm NI), cap-weighted dividend yield (annual div / year-end cap).
Values are mean [min..max] over seeds unless stated."""
import collections, json, math, os, re, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
A, B = sys.argv[2:4]
MODELS = json.load(open(f'{H}/runs/ticker_models.json'))
FIN = json.load(open(f'{H}/runs/ticker_fin.json'))
# Tuned TargetCapitalRatio (StockModelTuning) plus the P&C insurers that strike a premium/surplus target.
CAPT = {'PERE', 'PTRL', 'KING', 'ROOK', 'CORV', 'LAKE', 'RIVR', 'PLVR', 'POOL', 'ACC', 'BNTH', 'EGRT', 'SAFE', 'DOVE'}
BORROW = {'bonds': 'Issued $', 'penalty': 'Forced to borrow', 'revolver': 'revolving credit facility to cover',
          'div borrow': 'to fund its dividend'}


def rng(xs, f='{:.2f}'):
    xs = [x for x in xs if x is not None and not (isinstance(x, float) and math.isnan(x))]
    if not xs:
        return 'n/a'
    return (f + ' [' + f + '..' + f + ']').format(st.mean(xs), min(xs), max(xs))


def mean(xs):
    xs = [x for x in xs if x is not None and not math.isnan(x)]
    return st.mean(xs) if xs else float('nan')


def cagr(a, b, yrs):
    return (b / a) ** (1 / yrs) - 1 if a > 0 and b > 0 and yrs > 0 else float('nan')


def share_ratio(q):
    """End/start share count with splits (an integer or 1/integer step between quarters) divided out."""
    r = 1.0
    for x, y in zip(q, q[1:]):
        k = y['shares'] / x['shares'] if x['shares'] else 1.0
        for n in (2, 3, 4, 5, 10):
            if abs(k / n - 1) < 0.03:
                k /= n; break
            if abs(k * n - 1) < 0.03:
                k *= n; break
        r *= k
    return r


def roe(q, lo, hi):
    w = [x for x in q if lo <= x['t'] < hi]
    me = mean([x['eq'] for x in w]) if w else float('nan')
    return sum(x['ni'] for x in w) / me / (len(w) / 4) if w and me > 0 else float('nan')


def firm(r, tk):
    v = r['final'][tk]; q = v['q']
    if len(q) < 16:
        return None
    T = q[-1]['t']; yrs = T - q[0]['t']
    de = q[-1]['eq'] - q[0]['eq']; ret = sum(x['ni'] - x['div'] - x['bb'] for x in q[1:])
    rev4 = [sum(x['rev'] for x in q[i - 3:i + 1]) for i in range(3, len(q))]
    cr = [q[i]['cash'] / rev4[i - 3] for i in range(3, len(q)) if rev4[i - 3] > 0]
    ev = collections.Counter()
    evb = collections.Counter()
    for e in r['events'].get(tk, []):
        for k, pat in BORROW.items():
            if pat in e['d'] and (k != 'bonds' or 'in bonds for' in e['d']):
                ev[k] += 1
                m = re.search(r'\$([0-9.]+)B', e['d']); evb[k] += float(m.group(1)) if m else 0.0
    ni = sum(x['ni'] for x in q)
    return dict(res=(de - ret) / abs(de) if de else float('nan'), res_b=(de - ret) / 1e9, eqg=cagr(q[0]['eq'], q[-1]['eq'], yrs),
                revg=cagr(sum(x['rev'] for x in q[:4]), sum(x['rev'] for x in q[-4:]), q[-2]['t'] - q[1]['t']),
                roeF=roe(q, 0, 2), roeL=roe(q, T - 2, T + 1), bb=sum(x['bb'] for x in q) / 1e9,
                div=sum(x['div'] for x in q) / 1e9, ni=ni / 1e9, cash=mean(cr), cmin=min(cr) if cr else float('nan'),
                sh=share_ratio(q), de20=q[-1]['debt'] / q[-1]['eq'] if q[-1]['eq'] > 0 else float('nan'),
                ev=ev, evb=evb, dead=v['dead'])


runs = {arm: {s: json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').readline()) for s in seeds} for arm in (A, B)}
tickers = sorted(set(runs[A][seeds[0]]['final']) & set(runs[B][seeds[0]]['final']))
F = {arm: {tk: [f for f in (firm(runs[arm][s], tk) for s in seeds) if f] for tk in tickers} for arm in (A, B)}
p = lambda xs: rng([100 * x for x in xs], '{:.1f}')

print('## A. SWAN and POOL bridge and shares (rest of A from i_an.py)')
for tk in ('SWAN', 'POOL'):
    for arm in (A, B):
        fs = F[arm][tk]
        print(f"{tk} {arm:5s} residual ${rng([f['res_b'] for f in fs], '{:.0f}')}B = {p([f['res'] for f in fs])}% of d eq | "
              f"shares end/start split-adj {rng([f['sh'] for f in fs])} | bb ${rng([f['bb'] for f in fs], '{:.0f}')}B "
              f"div ${rng([f['div'] for f in fs], '{:.0f}')}B NI ${rng([f['ni'] for f in fs], '{:.0f}')}B")

print(f'\n## B. Firms without a capital target ({len([t for t in tickers if t not in CAPT])}), {A} -> {B}, firm means over seeds')
rows = {}
for tk in tickers:
    if tk in CAPT or not F[A][tk] or not F[B][tk]:
        continue
    rows[tk] = {k: (mean([f[k] for f in F[A][tk]]), mean([f[k] for f in F[B][tk]]))
                for k in ('res', 'eqg', 'roeF', 'roeL', 'bb', 'cash', 'cmin', 'sh', 'de20', 'revg')}
    for k in BORROW:
        rows[tk]['n_' + k] = (mean([f['ev'][k] for f in F[A][tk]]), mean([f['ev'][k] for f in F[B][tk]]))
        rows[tk]['b_' + k] = (mean([f['evb'][k] for f in F[A][tk]]), mean([f['evb'][k] for f in F[B][tk]]))
bym = collections.defaultdict(list)
for tk in rows:
    bym[MODELS.get(tk, '?').replace('BusinessModel', '') + ('*' if FIN.get(tk) else '')].append(tk)
hdr = ['resid %dEq', 'eq CAGR %', 'ROE f2y %', 'ROE l2y %', 'bb $B', 'cash/rev', 'shares e/s']
print(f"{'model (n) [* fin]':26s} " + ' '.join(f'{h:>15s}' for h in hdr))
keys = [('res', 100, '{:.0f}'), ('eqg', 100, '{:.1f}'), ('roeF', 100, '{:.1f}'), ('roeL', 100, '{:.1f}'), ('bb', 1, '{:.0f}'),
        ('cash', 1, '{:.2f}'), ('sh', 1, '{:.2f}')]


def cell(tks, k, sc, f):
    a = mean([rows[t][k][0] for t in tks]) * sc; b = mean([rows[t][k][1] for t in tks]) * sc
    return f'{f.format(a):>7s}>{f.format(b):<7s}'


for m in sorted(bym):
    print(f"{m + ' (' + str(len(bym[m])) + ')':26s} " + ' '.join(cell(bym[m], k, sc, f) for k, sc, f in keys))
allt = list(rows)
print(f"{'ALL (' + str(len(allt)) + ')':26s} " + ' '.join(cell(allt, k, sc, f) for k, sc, f in keys))
print(f"median residual share of d eq: {100 * st.median(rows[t]['res'][0] for t in allt):.0f}% -> "
      f"{100 * st.median(rows[t]['res'][1] for t in allt):.0f}%; median eq CAGR {100 * st.median(rows[t]['eqg'][0] for t in allt):.2f}% -> "
      f"{100 * st.median(rows[t]['eqg'][1] for t in allt):.2f}%; total bb ${sum(rows[t]['bb'][0] for t in allt):.0f}B -> "
      f"${sum(rows[t]['bb'][1] for t in allt):.0f}B per run")

print('\nTop 10 by |d eq CAGR| (pp), with cash/rev, min cash/rev, ND... D/E y20, bb $B, shares e/s')
fmt = lambda tk: (f"{tk:5s} {MODELS.get(tk, '?').replace('BusinessModel', ''):22s} eqg {100 * rows[tk]['eqg'][0]:5.1f}>{100 * rows[tk]['eqg'][1]:5.1f}"
                  f" cash/rev {rows[tk]['cash'][0]:.2f}>{rows[tk]['cash'][1]:.2f} min {rows[tk]['cmin'][0]:.2f}>{rows[tk]['cmin'][1]:.2f}"
                  f" D/E20 {rows[tk]['de20'][0]:.2f}>{rows[tk]['de20'][1]:.2f} bb {rows[tk]['bb'][0]:.0f}>{rows[tk]['bb'][1]:.0f}"
                  f" sh {rows[tk]['sh'][0]:.2f}>{rows[tk]['sh'][1]:.2f} resid {100 * rows[tk]['res'][0]:.0f}>{100 * rows[tk]['res'][1]:.0f}%")
for tk in sorted(allt, key=lambda t: -abs(rows[t]['eqg'][1] - rows[t]['eqg'][0]))[:10]:
    print(' ' + fmt(tk))
print('Top 10 by |d mean cash/rev| relative to fixH level')
for tk in sorted(allt, key=lambda t: -abs(rows[t]['cash'][1] - rows[t]['cash'][0]) / max(rows[t]['cash'][0], 0.02))[:10]:
    print(' ' + fmt(tk))

print('\nBorrowing flags: firms whose min cash/rev fell >25% or borrowing events/run rose by >=0.5 (events/run, $B/run)')
for tk in allt:
    r = rows[tk]
    up = [k for k in BORROW if r['n_' + k][1] - r['n_' + k][0] >= 0.5]
    lowc = r['cmin'][0] > 0 and r['cmin'][1] < 0.75 * r['cmin'][0]
    if up or lowc:
        print(f" {tk:5s} min cash/rev {r['cmin'][0]:.3f}>{r['cmin'][1]:.3f} | " +
              ' '.join(f"{k} {r['n_' + k][0]:.1f}>{r['n_' + k][1]:.1f} (${r['b_' + k][0]:.0f}>{r['b_' + k][1]:.0f}B)" for k in BORROW))
tot = {k: (sum(rows[t]['n_' + k][0] for t in allt), sum(rows[t]['n_' + k][1] for t in allt),
           sum(rows[t]['b_' + k][0] for t in allt), sum(rows[t]['b_' + k][1] for t in allt)) for k in BORROW}
print(' board totals per run (no-target firms): ' + ' | '.join(f"{k} {v[0]:.1f}>{v[1]:.1f} (${v[2]:.0f}>{v[3]:.0f}B)" for k, v in tot.items()))

print('\n## C. Board payout and dividend yield (all firms)')
for arm in (A, B):
    pay, dy = [], []
    for s in seeds:
        fin = runs[arm][s]['final']; pd = 0.0; pn = 0.0
        yd, yc = collections.defaultdict(float), collections.defaultdict(float)
        for tk, v in fin.items():
            q = v['q']
            if not q:
                continue
            ni = sum(x['ni'] for x in q)
            if ni > 0:
                pn += ni; pd += sum(x['div'] + x['bb'] for x in q)
            for x in q:
                yd[int(x['t'])] += x['div']
            for y in set(int(x['t']) for x in q):
                last = [x for x in q if int(x['t']) == y][-1]; yc[y] += last['price'] * last['shares']
        pay.append(pd / pn); dy.append(mean([yd[y] / yc[y] for y in yc if yc[y] > 0 and y < 20]))
    print(f"{arm:5s} board (div+bb)/NI+ {rng(pay)} | CW dividend yield {p(dy)}%")
