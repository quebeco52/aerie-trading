#!/usr/bin/env python3
"""Staples rework check: per-arm, per-ticker table from runs/<arm>-<seed>.jsonl. Usage: analyse.py [arms...]"""
import glob, json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
ARMS = sys.argv[1:] or ['base', 'new']
TICK = ['PHIL', 'SGRB', 'CROP', 'LARK', 'PINT']


def ols(x, y):
    mx, my = st.mean(x), st.mean(y)
    vx = sum((a - mx) ** 2 for a in x)
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / vx if vx > 0 else float('nan')


def corr(x, y):
    try:
        return st.correlation(x, y)
    except Exception:
        return float('nan')


def ms(v):
    v = [a for a in v if a == a]
    if not v:
        return 'nan'
    return f"{st.mean(v):+.3f}" + (f"({st.stdev(v) / math.sqrt(len(v)):.3f})" if len(v) > 1 else '')


# Share splits (CorporateActionEngine: 4-for-1 forward, possibly repeated; 1-for-10 reverse) print as one-tick log
# returns no price step can make (jumps cap at -36%/+26%, the breaker at 40% a day). Such a tick is taken back out by
# the nearest split factor, so the tick keeps its real move and the series is split-adjusted.
SPLIT_FACTORS = [4.0 ** -k for k in (1, 2, 3)] + [10.0 ** k for k in (1, 2)]
SPLIT_MIN_LOG = math.log(2.5)


def split_adjusted_log_returns(px):
    lr = []
    for a, b in zip(px, px[1:]):
        if a <= 0 or b <= 0:
            continue
        r = math.log(b / a)
        if abs(r) > SPLIT_MIN_LOG:
            r -= min((math.log(f) for f in SPLIT_FACTORS), key=lambda lf: abs(r - lf))
        lr.append(r)
    return lr


def runs(arm):
    out = []
    for f in sorted(glob.glob(f"{H}/runs/{arm}-*.jsonl")):
        if f.endswith('-99.jsonl'):
            continue
        out.append(json.loads(open(f).readline()))
    return out


for arm in ARMS:
    R = runs(arm)
    if not R:
        continue
    yrs = R[0]['years']
    print(f"== {arm}: n={len(R)} seeds, {yrs} y, tpy {R[0]['tpy']}  (cells: mean over seeds (se))")
    print(f"{'tick':5} {'revCAGR':>13} {'om_mean':>13} {'om_sd':>13} {'px_end/st':>13} {'surp/|E|':>13} {'beat%':>13} {'yoy_slope':>13} {'slumpΔg':>13} {'corr(om,agri)':>14} {'rvol':>13} {'cvol':>7}")
    flags = []
    evs = {'recall': [], 'fine': [], '': []}
    for t in TICK:
        cols = {k: [] for k in ['cagr', 'om', 'omsd', 'px', 'surp', 'beat', 'slope', 'slump', 'cagri', 'rvol', 'cvol']}
        for r in R:
            q = r['final'][t]['q']
            if len(q) < 12:
                continue
            rev = [a['rev'] for a in q]
            n4 = len(rev) // 4
            y0, y1 = sum(rev[:4]), sum(rev[4 * (n4 - 1):4 * n4])
            cols['cagr'].append((y1 / y0) ** (1 / max(1, n4 - 1)) - 1 if y0 > 0 and y1 > 0 else float('nan'))
            om = [a['om'] for a in q]
            cols['om'].append(st.mean(om)); cols['omsd'].append(st.stdev(om))
            if max(om) > 0.6:
                flags.append(f"{arm} s{r['seed']} {t}: om max {max(om):.2f}")
            run = best = 0
            for o in om:
                run = run + 1 if o < 0 else 0
                best = max(best, run)
            if best >= 4:
                flags.append(f"{arm} s{r['seed']} {t}: om<0 for {best} quarters")
            if y0 > 0 and y1 / y0 > 10:
                flags.append(f"{arm} s{r['seed']} {t}: revenue x{y1 / y0:.1f}")
            px = r['st_px'][t]
            lr = split_adjusted_log_returns(px)
            cols['px'].append(math.exp(sum(lr)) if px[0] > 0 else float('nan'))
            if min(px) <= 0 or any(p != p for p in px):
                flags.append(f"{arm} s{r['seed']} {t}: price <=0 or NaN")
            cols['rvol'].append(st.stdev(lr) * math.sqrt(r['tpy']))
            cols['cvol'].append(st.mean(a['cvol'] for a in q))
            eps = r['st_eps'].get(t, [])
            s = [(rep - c) / abs(c) for c, rep in eps if abs(c) > 1e-9]
            cols['surp'].append(st.median(s) if s else float('nan'))
            cols['beat'].append(sum(1 for c, rep in eps if rep > c) / max(1, len(eps)))
            g = [a['gap'] for a in q]
            dy = [math.log(rev[i] / rev[i - 4]) for i in range(4, len(rev)) if rev[i] > 0 and rev[i - 4] > 0]
            dg = [g[i] - g[i - 4] for i in range(4, len(rev)) if rev[i] > 0 and rev[i - 4] > 0]
            cols['slope'].append(ols(dg, dy))
            sl = [y for y, i in zip(dy, range(4, len(rev))) if g[i] < -0.02]
            ot = [y for y, i in zip(dy, range(4, len(rev))) if g[i] >= -0.02]
            cols['slump'].append(st.mean(sl) - st.mean(ot) if sl and ot else float('nan'))
            cols['cagri'].append(corr(om, [a['agri'] for a in q]))
            for e in r['st_earn'].get(t, []):
                evs[e['ev']].append(e['chg'])
        print(f"{t:5} " + ' '.join(f"{ms(cols[k]):>13}" for k in ['cagr', 'om', 'omsd', 'px', 'surp', 'beat', 'slope', 'slump'])
              + f" {ms(cols['cagri']):>14} {ms(cols['rvol']):>13} {st.mean(cols['cvol']):7.3f}")
    print(f"events (all 5 names, {len(R)} seeds): recall {len(evs['recall'])} mean move {ms(evs['recall'])}%, "
          f"fine {len(evs['fine'])} mean move {ms(evs['fine'])}%, other reports {len(evs[''])} mean move {ms(evs[''])}%")
    dead = [len(r['dead']) for r in R]; reorg = [len(r['reorgs']) for r in R]
    tr = [math.exp((r['idx_log'] + r['idx_div']) / r['years']) - 1 for r in R]
    pr = [math.exp(r['idx_log'] / r['years']) - 1 for r in R]
    bad = sum(1 for r in R for t, f in r['final'].items() if not (f['px'] == f['px']) or (f['px'] <= 0 and not f['dead']))
    print(f"market: failures/seed {st.mean(dead):.2f} {[r['dead'] for r in R]}; reorgs/seed {st.mean(reorg):.2f}; "
          f"index total return {ms(tr)}/y (price {ms(pr)}/y); bad final prices {bad}")
    for f in flags:
        print('FLAG', f)
    print()
