#!/usr/bin/env python3
"""analyse_phase5.py: whole-board check of the industry-outlook rework (idle_evidence section i).

Arms: HEAD = `val` runs (-oc, seeds 1-8; identical dynamics to cbe58c09), `pre` (-oc, seeds 1-8), HEAD 40y =
`head5` (-y40, seeds 1-4). Categories from the drift table in src/Data/Company/IndustryOutlooks.php.
Items as numbered in section i. Paired items: n, delta, SE, t via lib/stats.py. Slopes: per-seed OLS, then mean and
SE across seeds.
"""
import json, math, os, re, statistics as st, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import paired, fmt

H = os.path.dirname(os.path.abspath(__file__))
P = os.path.abspath(os.path.join(H, '..', '..', '..'))
S8 = [int(x) for x in os.environ.get('SEEDS', '1,2,3,4,5,6,7,8').split(',')]
S40 = [1, 2, 3, 4]
HEAD, PRE, LONG = 'val', 'pre', 'head5'
BAD = {'B', 'B-', 'CCC+', 'CCC', 'CCC-', 'CC', 'C', 'D', 'SD'}
table = {m.group(1): float(m.group(2)) for m in re.finditer(r"'([^']+)' => \['drift_vol' => ([0-9.]+)",
                                                            open(f'{P}/src/Data/Company/IndustryOutlooks.php').read())}
CATS = [('staple', 0.025), ('steady', 0.04), ('shifting', 0.06), ('volatile', 0.09)]
CAT = {d: c for c, d in CATS}
cat = lambda ind: CAT.get(table.get(ind, 0.0), 'off')
SIGMA = dict(CATS)


def load(arm, s, tag):
    if arm == 'val' and not os.path.exists(f'{H}/runs/val-{s}{tag}.jsonl'):
        arm = 'head5'  # identical dynamics (section i check); seeds past 8 ran as head5
    r = json.loads(open(f'{H}/runs/{arm}-{s}{tag}.jsonl').readline())
    k = json.load(open(f'{H}/runs/{arm}-{s}{tag}.idle.json'))
    try:
        oq = json.load(open(f'{H}/runs/{arm}-{s}{tag}.outlook.json'))['quarters']
    except FileNotFoundError:
        oq = []
    return r, k, oq


def pct(xs, p):
    xs = sorted(xs)
    return xs[min(len(xs) - 1, max(0, int(math.ceil(p * len(xs))) - 1))] if xs else float('nan')


def ols(xs, ys):
    if len(xs) < 3:
        return float('nan')
    mx, my = st.fmean(xs), st.fmean(ys)
    sxx = sum((x - mx) ** 2 for x in xs)
    return sum((x - mx) * (y - my) for x, y in zip(xs, ys)) / sxx if sxx > 0 else float('nan')


def seed_mean(vals):
    v = [x for x in vals if not math.isnan(x)]
    if not v:
        return 'n/a'
    se = st.stdev(v) / math.sqrt(len(v)) if len(v) > 1 else float('nan')
    return f'{st.fmean(v):+.3f} ({se:.3f}) n{len(v)}'


def board(r, k, t0, t1):
    """Board moments over (t0, t1]; levels at t1."""
    cpi = {(x[1], x[0]): x[5] for x in k['rows']}
    out = {}
    live = {tk: f for tk, f in r['final'].items() if f['q'] and f['q'][-1]['t'] >= t1 - 0.3}
    cap = 0.0
    for f in live.values():
        y = [x for x in f['q'] if x['t'] <= t1]
        if y:
            cap += y[-1]['price'] * y[-1]['shares']
    out['cap end ($tn)'] = cap / 1e12
    w = [x for f in r['final'].values() for x in f['q'] if t0 < x['t'] <= t1]
    nf = [x for f in r['final'].values() if f['q'] and not f['q'][0]['fin'] for x in f['q'] if t0 < x['t'] <= t1]
    pe = [x['price'] / x['eps'] for x in w if x['eps'] > 0]
    out['median P/E'] = st.median(pe)
    if t0 == 0 or t1 == r['years']:
        rv = [math.sqrt(a / n * k['tpy']) for tk, (a, n, c, _) in k.get('rv', {}).items()
              if n >= k['tpy'] and not r['final'].get(tk, {}).get('dead')]
        out['median realised vol (run)'] = st.median(rv) if rv else float('nan')
    ev = 0
    for tk, q in (r['defaults'] or {}).items():
        t = next((x['t'] for x in r['final'][tk]['q'] if x['qid'] > 0), None)
        ev += t is not None and t0 < t <= t1
    ev += sum(1 for d in r['dead'] if t0 < r['final'][d]['q'][-1]['t'] <= t1 + 0.3)
    out['defaults+deaths'] = ev
    out['B-or-worse share'] = sum(1 for x in w if x['rating'] in BAD) / len(w)
    out['median op margin'] = st.median(x['om'] for x in w)
    nd, g = [], []
    for tk, f in r['final'].items():
        if not f['q'] or f['q'][0]['fin']:
            continue
        y = [x for x in f['q'] if t1 - 1 < x['t'] <= t1]
        if len(y) < 4:
            continue
        e = sum(x['ebitda'] for x in y)
        if e > 0:
            nd.append((y[-1]['debt'] - y[-1]['cash']) / e)
        op = k['opening'].get(tk, 0.0)
        c0 = cpi.get((tk, f['q'][0]['t']), 1.0)
        if op > 0:
            real = sum(x['rev'] for x in y) / st.fmean(cpi.get((tk, x['t']), 1.0) for x in y) * c0
            if real > 0:
                g.append(math.log(real / op) / t1)
    out['median ND/EBITDA end'] = st.median(nd)
    out['capex/revenue'] = sum(x['capex'] for x in nf) / sum(x['rev'] for x in nf)
    out['payout (div+bb)/NI'] = sum(x['div'] + x['bb'] for x in w) / sum(x['ni'] for x in w)
    out['median real revenue growth /yr'] = st.median(g)
    return out


def beliefs(oq):
    """(industry, quarter) -> (x, b, v, f); list of quarters."""
    m = {}
    for _, q, rows in oq:
        for ind, rec in rows.items():
            m[(ind, q)] = (rec[0], rec[1] + rec[2] / 2.0, rec[2], rec[3])
    return m, sorted({q for _, q, _ in oq})


# ---------------------------------------------------------------- 1. board HEAD vs pre
print('## 1. Board, HEAD vs pre, years 0-20 (n seeds paired)')
hb = {s: board(*load(HEAD, s, '-oc')[:2], 0, 20) for s in S8}
pb = {s: board(*load(PRE, s, '-oc')[:2], 0, 20) for s in S8}
print('quantity | pre | HEAD | HEAD-pre (se) t n')
for key in hb[S8[0]]:
    a = [pb[s][key] for s in S8]
    b = [hb[s][key] for s in S8]
    pe_note = ' (P/E: years 0-20)' if key == 'median P/E' else ''
    print(f'{key}{pe_note} | {st.fmean(a):.4g} | {st.fmean(b):.4g} | {fmt(paired(a, b), 4)}')
hb2 = {s: board(*load(HEAD, s, '-oc')[:2], 10, 20)['median P/E'] for s in S8}
pb2 = {s: board(*load(PRE, s, '-oc')[:2], 10, 20)['median P/E'] for s in S8}
print(f"median P/E years 10-20 | {st.fmean(pb2.values()):.4g} | {st.fmean(hb2.values()):.4g} | "
      f"{fmt(paired([pb2[s] for s in S8], [hb2[s] for s in S8]), 4)}")
for arm, seeds in ((PRE, S8), (HEAD, S8)):
    for s in seeds:
        r = load(arm, s, '-oc')[0]
        ev = [f'{tk} default {q}q' for tk, q in (r['defaults'] or {}).items()] + [f"{e['t']} reorg y{e['year']}" for e in r['reorgs']] + [f'{d} failed' for d in r['dead']]
        if ev:
            print(f'  event {arm} s{s}: ' + '; '.join(ev))

# ---------------------------------------------------------------- 2. outlook in game
print('\n## 2. Outlook in game (HEAD seeds 1-8, 20y; 40y seeds 1-4 for year-40 levels)')
err, sv, rev, ex20, stories, big, years = {}, {}, {}, {}, 0, 0, 0.0
lo20, hi20 = [], []
for s in S8:
    oq = load(HEAD, s, '-oc')[2]
    bm, qs = beliefs(oq)
    years += (qs[-1] - qs[0]) / 4.0
    for (ind, q), (x, b, v, f) in bm.items():
        c = cat(ind)
        err.setdefault(c, []).append(b - x)
        sv.setdefault(c, []).append(math.sqrt(v))
        if (ind, q - 1) in bm:
            d = b - bm[(ind, q - 1)][1]
            rev.setdefault(c, []).append(d)
            stories += abs(math.exp(d) - 1.0) >= 0.05
            big += abs(math.exp(d) - 1.0) >= 0.075
    for ind, rec in oq[-1][2].items():
        ex20.setdefault(cat(ind), []).append(math.exp(rec[0]))
    lv = [math.exp(rec[0]) for rec in oq[-1][2].values()]
    lo20.append(min(lv)); hi20.append(max(lv))
print('category | RMS(b-x) | mean sqrt(v) | revision SD | sigma*sqrt(.25) | SD exp(x) y20 | ref sigma*sqrt(20)')
for c, sig in CATS:
    print(f'{c} | {math.sqrt(st.fmean(e * e for e in err[c])):.4f} | {st.fmean(sv[c]):.4f} | {st.pstdev(rev[c]):.4f} | '
          f'{sig * 0.5:.4f} | {st.pstdev(ex20[c]):.3f} | {sig * math.sqrt(20):.3f}')
lo40, hi40 = [], []
for s in S40:
    oq = load(LONG, s, '-y40')[2]
    lv = [math.exp(rec[0]) for rec in oq[-1][2].values()]
    lo40.append(min(lv)); hi40.append(max(lv))
print(f'industry level y20 min {min(lo20):.3f} (seed mins {min(lo20):.2f}-{max(lo20):.2f}), max {max(hi20):.3f}; '
      f'y40 min {min(lo40):.3f}, max {max(hi40):.3f}')
print(f'stories/yr (|e^rev - 1| >= 5%): {stories / years:.1f}; '
      f'headline (>= 7.5%): {big / years:.1f}  [estimate 17 and 4.4]')

# ---------------------------------------------------------------- 3. revisions to value
print('\n## 3. Revision to value: slope of d log(y) over a report interval on the industry belief change')


def interval_pairs(arm, s, tag, t0=0.0, t1=99.0):
    r, k, oq = load(arm, s, tag)
    bm, qs = beliefs(oq)
    qrow = {(tk, x['t']): x for tk, f in r['final'].items() for x in f['q']}
    rows = sorted(k['rows'], key=lambda x: (x[1], x[0]))
    out = []
    for a, b in zip(rows, rows[1:]):
        if a[1] != b[1] or not (t0 < b[0] <= t1) or cat(b[2]) == 'off':
            continue
        qa, qb = int(a[0] * 4 + 1e-9), int(b[0] * 4 + 1e-9)
        if (b[2], qa) not in bm or (b[2], qb) not in bm or qb == qa:
            continue
        d = bm[(b[2], qb)][1] - bm[(b[2], qa)][1]
        xa, xb = qrow.get((a[1], a[0])), qrow.get((b[1], b[0]))
        if not xa or not xb:
            continue
        rec = {'cat': cat(b[2]), 'rev': d}
        if a[9] and b[9] and a[9] > 0 and b[9] > 0:
            rec['fv'] = math.log(b[9] / a[9])
        if xa['price'] > 0 and xb['price'] > 0:
            rec['price'] = math.log(xb['price'] / xa['price'])
        if len(b) > 10 and a[10] is not None and b[10] is not None:
            rec['filed belief'] = b[10] - a[10]
        if a[7] is not None and b[7] is not None:
            rec['capacity gap'] = b[7] - a[7]
        if a[8] is not None and b[8] is not None:
            rec['catch-up growth'] = b[8] - a[8]
        for key in ('x_sec', 'x_growthAn', 'x_longRun'):
            if key in xa and key in xb:
                rec[key] = xb[key] - xa[key]
        if xa.get('x_fv') and xb.get('x_fv') and xa['x_fv'] > 0 and xb['x_fv'] > 0 and xa.get('eps', 0) > 0 and xb.get('eps', 0) > 0:
            rec['FV/EPS (log)'] = math.log((xb['x_fv'] / xb['eps']) / (xa['x_fv'] / xa['eps']))
        out.append(rec)
    return out


def slopes(arm, seeds, tag, keys, t0=0.0, t1=99.0):
    per = {s: interval_pairs(arm, s, tag, t0, t1) for s in seeds}
    print(f'{arm}{tag} seeds {seeds[0]}-{seeds[-1]} | ' + ' | '.join(keys))
    for c, _ in CATS + [('all', 0)]:
        cells = []
        for key in keys:
            vals = []
            for s in seeds:
                pr = [p for p in per[s] if (c == 'all' or p['cat'] == c) and key in p]
                vals.append(ols([p['rev'] for p in pr], [p[key] for p in pr]))
            cells.append(seed_mean(vals))
        print(f'{c} | ' + ' | '.join(cells))


slopes(HEAD, S8, '-oc', ['fv', 'price', 'capacity gap', 'catch-up growth', 'x_sec', 'FV/EPS (log)'])
slopes(LONG, S40, '-y40', ['fv', 'price', 'filed belief', 'capacity gap', 'catch-up growth', 'x_sec', 'x_growthAn', 'FV/EPS (log)'], 0, 20)

# ---------------------------------------------------------------- 4. idle plant and gluts


def idle_stats(arm, seeds, tag, t0, t1):
    share, ids, pl, glut = {}, {}, {}, {}
    for s in seeds:
        k = load(arm, s, tag)[1]
        byq = {}
        for row in k['rows']:
            if not (t0 < row[0] <= t1) or row[4] is None:
                continue
            c = cat(row[2])
            share.setdefault(c, []).append((row[3] or 0.0) > 0.005)
            if row[3]:
                ids.setdefault(c, []).append(row[3])
            pl.setdefault(c, []).append(row[4])
            key = (row[2], int(row[0] * 4))
            byq[key] = byq.get(key, False) or bool(row[3])
        inds = {i for i, _ in byq}
        for ind in inds:
            qs = sorted(q for (i, q), g in byq.items() if i == ind and g)
            run = 0
            prev = None
            for q in qs:
                run = run + 1 if prev is not None and q == prev + 1 else 1
                if prev is not None and q != prev + 1:
                    glut.setdefault(cat(ind), []).append(last)
                last, prev = run, q
            if qs:
                glut.setdefault(cat(ind), []).append(last)
    print(f'{arm}{tag} years {t0}-{t1} | share fq idle>0.5% | idle share median / p90 / max (idle fq) | glut episodes n, '
          f'quarters median / p90 / max | price level min / p1 / p99 / max')
    for c, _ in CATS + [('off', 0)]:
        sh = share.get(c, [])
        i = ids.get(c, [])
        g = glut.get(c, [])
        p = pl.get(c, [])
        print(f'{c} | {sum(sh) / len(sh) if sh else 0:.4f} | ' + (f'{st.median(i):.3f} / {pct(i, .9):.3f} / {max(i):.3f}' if i else '-') +
              f' | {len(g)}, ' + (f'{st.median(g):.0f} / {pct(g, .9):.0f} / {max(g)}' if g else '-') +
              (f' | {min(p):.3f} / {pct(p, .01):.3f} / {pct(p, .99):.3f} / {max(p):.3f}' if p else ''))


print('\n## 4. Idle plant and gluts (glut = any listed firm idle that quarter; floor not logged separately)')
idle_stats(HEAD, S8, '-oc', 10, 20)
idle_stats(LONG, S40, '-y40', 20, 40)

# ---------------------------------------------------------------- 5. truth to firms
print('\n## 5. Firm real revenue growth to y20 on industry x and on belief (per-seed OLS, mean (se))')
sx, sb, n = [], [], 0
for s in S8:
    r, k, oq = load(HEAD, s, '-oc')
    bm, qs = beliefs(oq)
    q20 = qs[-1]
    cpi = {(x[1], x[0]): x[5] for x in k['rows']}
    ind = {x[1]: x[2] for x in k['rows']}
    X, Bv, Y = [], [], []
    for tk, f in r['final'].items():
        i = ind.get(tk)
        if f['dead'] or not f['q'] or f['q'][0]['fin'] or (i, q20) not in bm or k['opening'].get(tk, 0) <= 0:
            continue
        y = f['q'][-4:]
        real = sum(x['rev'] for x in y) / st.fmean(cpi.get((tk, x['t']), 1.0) for x in y) * cpi.get((tk, f['q'][0]['t']), 1.0)
        if real <= 0:
            continue
        X.append(bm[(i, q20)][0]); Bv.append(bm[(i, q20)][1]); Y.append(math.log(real / k['opening'][tk]))
    sx.append(ols(X, Y)); sb.append(ols(Bv, Y)); n += len(Y)
print(f'on x: {seed_mean(sx)} | on belief: {seed_mean(sb)} | firms {n}')

# ---------------------------------------------------------------- 6. 40 years
print('\n## 6. HEAD 40 years, seeds 1-4, every 10 years (window = the decade; levels at its end)')
dec = {}
for s in S40:
    r, k, oq = load(LONG, s, '-y40')
    bm, qs = beliefs(oq)
    for t in (10, 20, 30, 40):
        b = board(r, k, t - 10, t)
        q = max(x for x in qs if x <= t * 4)
        lv = [math.exp(bm[(i, q)][0]) for (i, qq) in bm if qq == q]
        fb = [abs(bm[(i, q)][3] - bm[(i, q)][1]) for (i, qq) in bm if qq == q]
        b['industry level min'] = min(lv)
        b['industry level max'] = max(lv)
        b['max |f - b|'] = max(fb)
        b['p90 ND/EBITDA end'] = float('nan')
        nd = []
        for tk, f in r['final'].items():
            if not f['q'] or f['q'][0]['fin']:
                continue
            y = [x for x in f['q'] if t - 1 < x['t'] <= t]
            e = sum(x['ebitda'] for x in y)
            if len(y) == 4 and e > 0:
                nd.append((y[-1]['debt'] - y[-1]['cash']) / e)
        b['p90 ND/EBITDA end'] = pct(nd, 0.9)
        for key, v in b.items():
            dec.setdefault(key, {}).setdefault(t, []).append(v)
print('quantity | y0-10 | y10-20 | y20-30 | y30-40')
for key, d in dec.items():
    print(f'{key} | ' + ' | '.join(f'{st.fmean(d[t]):.4g}' + (f' [{min(d[t]):.3g}-{max(d[t]):.3g}]' if 'level' in key or 'f -' in key else '') for t in (10, 20, 30, 40) if t in d))
