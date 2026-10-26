#!/usr/bin/env python3
"""cal0.py <seeds> ARM [HEAD]: market-entry calibration scorecard (5b-4) for one arm; HEAD (optional) for the
difference-in-difference price check. Reads runs/<arm>-<seed>.{seg.json,jsonl,idle.json}: deal events (buy entries:
', taking it into '), VENTURE rows (build entries: 'entry'; every x: segment's vk keys, kappa, streams, xrec), CAP_PROBE
rows (styles), the quarter log (EBIT, prices) and the idle rows (industry price levels). Prints the final tables only.

Definitions: closeness = max adjacency over the entrant's held industries (home, plan segments, x: held before);
venture operating margin = segment margin x (1 - learning gap); exit within 10 y = built venture abandoned, or bought
segment sold whole (kappa to 0 with a 'sold its' event) or harvested (kappa < 25% of entry kappa); growth industry =
listed firms' real revenue growth over the run above the board's (in HEAD when given, else ARM).
"""
import json, math, os, re, statistics as st, sys
from collections import Counter, defaultdict

H = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(H, '..', '..', '..', 'src')
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
ARM = sys.argv[2]
HEAD = sys.argv[3] if len(sys.argv) > 3 else None
YEARS = 20.0
nan = float('nan')
BAND = (0.05, 0.50)  # DivestitureEngine DIV_PREMIUM_FRACTION_MIN .. DIV_DYING_FRACTION_MAX (union of the three bands)


def med(xs):
    xs = [x for x in xs if x is not None and x == x]
    return st.median(xs) if xs else nan


def q(xs, p):
    xs = sorted(x for x in xs if x is not None and x == x)
    return xs[min(len(xs) - 1, int(p * len(xs)))] if xs else nan


def mse(xs):
    xs = [x for x in xs if x is not None and x == x]
    return (st.fmean(xs), st.stdev(xs) / math.sqrt(len(xs)), len(xs)) if len(xs) > 1 else (st.fmean(xs) if xs else nan, nan, len(xs))


# --- adjacency and plans from src ---
LEVEL = {'CLOSE': 0.8, 'RELATED': 0.5, 'DISTANT': 0.25}
CLOSE = {}
for m in re.finditer(r"\[\s*'([^']+)'\s*,\s*'([^']+)'\s*,\s*self::([A-Z]+)\s*\]", open(os.path.join(SRC, 'Data', 'Company', 'IndustryAdjacency.php')).read()):
    for pair in ((m.group(1), m.group(2)), (m.group(2), m.group(1))):
        CLOSE[pair] = max(CLOSE.get(pair, 0.0), LEVEL[m.group(3)])
seg_src = open(os.path.join(SRC, 'Data', 'Company', 'Segments.php')).read()
heads = [(m.start(), m.group(1)) for m in re.finditer(r"'([A-Z]{3,5})' => \['main' =>", seg_src)]
PLAN = defaultdict(set)
for (i, tk), (j, _) in zip(heads, heads[1:] + [(len(seg_src), '')]):
    PLAN[tk].update(re.findall(r"'industry' => '([^']+)'", seg_src[i:j]))
im = open(os.path.join(SRC, 'Data', 'Company', 'InitialMarket.php')).read()
SECTOR = {m.group(1): m.group(3) for m in re.finditer(r"'ticker' => '([A-Z]+)'(.*?)'sector' => '([^']+)'", im, re.S)}


def load(a, s):
    g = json.load(open(f'{H}/runs/{a}-{s}.seg.json'))
    for row in g.get('ventures', []):
        for key in ('kappa', 'vk', 'streams', 'xrec', 'segs'):
            row[key] = row.get(key) or {}
    return g, json.loads(open(f'{H}/runs/{a}-{s}.jsonl').read().splitlines()[-1]), json.load(open(f'{H}/runs/{a}-{s}.idle.json'))['rows']


D = {(ARM, s): load(ARM, s) for s in seeds}
if HEAD:
    D.update({(HEAD, s): load(HEAD, s) for s in seeds})
IND = {row[1]: row[2] for row in D[ARM, seeds[0]][2]}

# --- growth industries from the listed firms' real revenue growth ---
def growth_table(a):
    gi = defaultdict(list)
    board = []
    for s in seeds:
        g, r, k = D[a, s]
        cpi = lambda y: med([row[5] for row in k if y - 1 < row[0] <= y])
        infl = math.log(cpi(20) / cpi(1)) / 19
        tot0 = tot1 = 0.0
        for tk, v in r['final'].items():
            r0 = sum(x['rev'] for x in v['q'] if x['t'] <= 1)
            r1 = sum(x['rev'] for x in v['q'] if 19 < x['t'] <= 20)
            tot0 += r0
            tot1 += r1
            if r0 > 0 and r1 > 0:
                gi[IND.get(tk)].append(math.log(r1 / r0) / 19 - infl)
        board.append(math.log(tot1 / tot0) / 19 - infl)
    b = st.fmean(board)
    return {i: st.fmean(v) > b for i, v in gi.items()}, b
GROWTH, BOARDG = growth_table(HEAD or ARM)

# --- entries ---
entries = []
for s in seeds:
    g, r, k = D[ARM, s]
    V = g['ventures']
    for row in V:
        if row['entry']:
            seg = row['entry']['segment']
            prev = [x for x in V if x['tk'] == row['tk'] and x['t'] < row['t']]
            held = {row['industry']} | PLAN[row['tk']] | {kk[2:] for kk in (prev[-1]['kappa'] if prev else {}) if kk.startswith('x:')}
            entries.append({'kind': 'build', 's': s, 'tk': row['tk'], 't': row['t'], 'seg': seg, 'style': row['style'], 'home': row['industry'],
                            'close': row['entry']['closeness'], 'held': held})
    for d in g['deals']:
        if ', taking it into ' in d['d']:
            prev = [x for x in V if x['tk'] == d['tk'] and x['t'] < d['t']]
            before = set(prev[-1]['kappa']) if prev and d['t'] - prev[-1]['t'] < 0.3 else set()
            new = [kk for kk in d['kappa'] if kk.startswith('x:') and kk not in before] or [kk for kk in d['kappa'] if kk.startswith('x:')]
            seg = new[-1] if new else None
            held = {d['industry']} | PLAN[d['tk']] | {kk[2:] for kk in before if kk.startswith('x:')}
            c = max([CLOSE.get((h, seg[2:]), 0.0) for h in held] or [0.0]) if seg else 0.0
            entries.append({'kind': 'buy', 's': s, 'tk': d['tk'], 't': d['t'], 'seg': seg, 'style': d['style'], 'home': d['industry'], 'close': c, 'held': held})
for e in entries:
    V = D[ARM, e['s']][0]['ventures']
    e['path'] = [x for x in V if x['tk'] == e['tk'] and x['t'] >= e['t'] - 1e-9 and (e['seg'] in x['kappa'] or any(kk.endswith(':' + e['seg']) for kk in x['vk']))]
    first = next((x for x in e['path'] if x['kappa'].get(e['seg'], 0) > 0), None)
    e['k0'] = first['kappa'][e['seg']] if first else nan
    e['left'] = YEARS - e['t']

KINDS = ('build', 'buy')
CL = (0.8, 0.5, 0.25)


def split(fn, label, fmt='{:.3f}'):
    """fn(list of entries) -> string; printed for build/buy and by closeness."""
    for kind in KINDS:
        es = [e for e in entries if e['kind'] == kind]
        cells = [f'all: {fn(es)}'] + [f'{c}: {fn([e for e in es if e["close"] == c])}' for c in CL]
        print(f'{label} [{kind}] ' + ' | '.join(cells))


print(f'## Entry calibration scorecard, {ARM}, n={len(seeds)} x {YEARS:.0f} y' + (f' (prices and growth industries vs {HEAD})' if HEAD else ''))

print('\n### 1. Entries')
for kind in KINDS:
    es = [e for e in entries if e['kind'] == kind]
    per = [sum(1 for e in es if e['s'] == s) / YEARS for s in seeds]
    m, se, n = mse(per)
    print(f'{kind}: {len(es)} ({m:.3f}/yr, se {se:.3f} over seeds), companies {len({e["tk"] for e in es})}; closeness ' + ', '.join(f'{c}: {sum(1 for e in es if e["close"] == c)}' for c in CL)
          + f' (other {sum(1 for e in es if e["close"] not in CL)})')
tot = [sum(1 for e in entries if e['s'] == s) / YEARS for s in seeds]
m, se, n = mse(tot)
print(f'total {m:.3f}/yr (se {se:.3f}), target ~0.75')
fy = Counter()
for s in seeds:
    for row in D[ARM, s][0]['cap']:
        fy[row[11]] += 0.25
for kind in KINDS:
    c = Counter(e['style'] for e in entries if e['kind'] == kind)
    print(f'{kind} per firm-year by style: ' + ', '.join(f'{k} {c[k] / fy[k]:.4f} ({c[k]})' for k in sorted(fy)))

print('\n### 2. Build ventures vs Biggadike (loss ~4 y, profit by ~8, parent margin by 10-12)')
def venture_years(e, cond):
    for x in e['path']:
        L = x['vk'].get(f'carry:segment_learning:{e["seg"]}')
        mrg = x['vk'].get(f'carry:segment_margin:{e["seg"]}')
        if L is None and x['t'] > e['t'] + 0.3 and e['seg'] in x['kappa']:
            return x['t'] - e['t']  # learning key cleared: fully learned
        if L is not None and mrg is not None and cond(L):
            return x['t'] - e['t']
    return None
builds = [e for e in entries if e['kind'] == 'build']
for lab, cond in (('years to venture operating income >= 0 (gap < 1)', lambda L: L < 1.0), ('years to 90% of learned margin (gap <= 0.1)', lambda L: L <= 0.1)):
    def f(es, cond=cond):
        ys = [venture_years(e, cond) for e in es]
        got = [y for y in ys if y is not None]
        return f'median {med(got):.1f} p10 {q(got, .1):.1f} p90 {q(got, .9):.1f} (reached {len(got)} of {len(es)})' if es else 'n/a'
    for c in ('all',) + CL:
        es = [e for e in builds if c == 'all' or e['close'] == c]
        print(f'{lab} [build {c}]: {f(es)}')
share14 = []
for e in builds:
    fin = D[ARM, e['s']][1]['final'].get(e['tk'], {'q': []})['q']
    vs, ps = 0.0, 0.0
    for x in e['path']:
        if not (e['t'] < x['t'] <= e['t'] + 4):
            continue
        L = x['vk'].get(f'carry:segment_learning:{e["seg"]}', 0.0)
        mrg = x['vk'].get(f'carry:segment_margin:{e["seg"]}', 0.0)
        vs += x['streams'].get(e['seg'], 0.0) * mrg * (1 - L)
        par = next((y for y in fin if abs(y['t'] - x['t']) < 0.13), None)
        if par:
            ps += par['ebit']
    if ps:
        share14.append(vs / ps)
m, se, n = mse(share14)
print(f'venture operating income / parent EBIT over years 1-4 after entry: mean {m:+.4f} (se {se:.4f}, n {n}), median {med(share14):+.4f}, p10 {q(share14, .1):+.4f}')

print('\n### 3. Exits within 10 years of entry (entries with 10 years left)')
sold = defaultdict(list)
for s in seeds:
    for d in D[ARM, s][0]['deals']:
        if ' sold its ' in d['d']:
            sold[s, d['tk']].append(d)
def exit_info(e):
    for x in e['path']:
        if x['t'] - e['t'] > 10:
            break
        if f'carry:segment_venture_abandoned:{e["seg"]}' in x['vk']:
            return 'abandoned', x['t'] - e['t']
    for d in sold[e['s'], e['tk']]:
        if e['t'] < d['t'] <= e['t'] + 10 and d['kappa'].get(e['seg'], 0.0) == 0.0 and e['seg'] not in d['kappa'] or (e['t'] < d['t'] <= e['t'] + 10 and d['kappa'].get(e['seg'], 1.0) == 0.0):
            return 'sold', d['t'] - e['t']
    for x in e['path']:
        if x['t'] - e['t'] > 10:
            break
        if e['k0'] == e['k0'] and x['t'] > e['t'] + 0.5 and x['kappa'].get(e['seg'], 0.0) < 0.25 * e['k0']:
            return 'harvested', x['t'] - e['t']
    return None, None
for kind in KINDS:
    for c in ('all',) + CL:
        es = [e for e in entries if e['kind'] == kind and (c == 'all' or e['close'] == c) and e['left'] >= 10]
        ex = [exit_info(e) for e in es]
        cnt = Counter(x[0] for x in ex if x[0])
        print(f'[{kind} {c}] n {len(es)}: exited {sum(cnt.values())} ({sum(cnt.values()) / len(es):.0%})' if es else f'[{kind} {c}] n 0', end='')
        print(f" - {dict(cnt)}; median years {med([x[1] for x in ex if x[0]]):.1f}" if es else '')
inband = total = 0
ks = []
for e in entries:
    if e['kind'] != 'buy':
        continue
    for x in e['path']:
        k = x['kappa'].get(e['seg'])
        if k:
            total += 1
            inband += BAND[0] <= k <= BAND[1]
            ks.append(k)
print(f'bought segments\' kappa vs the whole-sale band {BAND[0]}-{BAND[1]} (DivestitureEngine premium min .. dying max): {inband} of {total} reports inside '
      f'({inband / max(1, total):.0%}); kappa median {med(ks):.3f} p90 {q(ks, .9):.3f}')

print('\n### 4. Bought segments after entry')
def bought(es):
    if not es:
        return 'n/a'
    rep = [(x['vk'].get(f'carry:segment_builds:{e["seg"]}'), x['vk'].get(f'carry:segment_pays:{e["seg"]}')) for e in es for x in e['path'] if x['t'] > e['t']]
    b0 = sum(1 for b, p in rep if b == 0)
    p0 = sum(1 for b, p in rep if p == 0)
    kp = {y: [x['kappa'].get(e['seg'], 0.0) for e in es for x in e['path'] if abs(x['t'] - e['t'] - y) <= 0.13] for y in (0.25, 4, 8, 12)}
    return (f'n {len(es)}; builds=0 {b0 / max(1, len(rep)):.0%}, pays=0 {p0 / max(1, len(rep)):.0%} of {len(rep)} reports; kappa med q1 {med(kp[0.25]):.3f}, +4 {med(kp[4]):.3f}, '
            f'+8 {med(kp[8]):.3f}, +12 {med(kp[12]):.3f} (p90 +8 {q(kp[8], .9):.3f})')
for c in ('all',) + CL:
    print(f'[buy {c}] ' + bought([e for e in entries if e['kind'] == 'buy' and (c == 'all' or e['close'] == c)]))

print(f'\n### 5. Entries into growth industries (listed firms\' real growth above the board\'s {BOARDG:+.4f}/yr{" in " + HEAD if HEAD else ""})')
for kind in KINDS:
    es = [e for e in entries if e['kind'] == kind and e['seg']]
    known = [e for e in es if e['seg'][2:] in GROWTH]
    gr = sum(1 for e in known if GROWTH[e['seg'][2:]])
    print(f'{kind}: {gr} of {len(known)} with listed peers ({gr / max(1, len(known)):.0%}); {len(es) - len(known)} into industries with no listed firm')

print('\n### 6. Entered industry\'s incumbent price level, mean over the 8 quarters after a build minus the 4 before')
def window(rows, ind, tk, t0):
    pre = [r[4] for r in rows if r[2] == ind and r[1] != tk and r[4] is not None and t0 - 1.0 <= r[0] < t0]
    post = [r[4] for r in rows if r[2] == ind and r[1] != tk and r[4] is not None and t0 < r[0] <= t0 + 2.0]
    return st.fmean(post) - st.fmean(pre) if pre and post else None
for kind in KINDS:
    act, plc, did = [], [], []
    for e in entries:
        if e['kind'] != kind or not e['seg']:
            continue
        ind = e['seg'][2:]
        rows = D[ARM, e['s']][2]
        a = window(rows, ind, e['tk'], e['t'])
        p = window(rows, ind, e['tk'], e['t'] - 3.0) if e['t'] - 3.0 >= 1.0 else None
        if a is not None:
            act.append(a)
        if p is not None:
            plc.append(p)
        if HEAD and a is not None:
            h = window(D[HEAD, e['s']][2], ind, e['tk'], e['t'])
            if h is not None:
                did.append(a - h)
    ma, sa, na = mse(act)
    mp, sp, np_ = mse(plc)
    md, sd, nd = mse(did)
    print(f'{kind}: after entry {ma:+.4f} (se {sa:.4f}, n {na}); placebo (same industry, 3 y earlier) {mp:+.4f} (se {sp:.4f}, n {np_})'
          + (f'; vs {HEAD} same dates {md:+.4f} (se {sd:.4f}, n {nd})' if HEAD else ''))

print('\n### 7. Front-running: entrant excess log return over its sector, reports 0-8 from the entry, vs one year earlier')
def adjusted(qs):
    out, f = [], 1.0
    for i, x in enumerate(qs):
        if i:
            r = x['shares'] / qs[i - 1]['shares'] if qs[i - 1]['shares'] else 1.0
            if (r >= 1.5 and abs(r - round(r)) < 1e-6) or (0 < r <= 0.67 and abs(1 / r - round(1 / r)) < 1e-6):
                f *= r
        out.append((x['t'], x['price'] * f))
    return out
def at(series, t):
    b = min(series, key=lambda p: abs(p[0] - t)) if series else None
    return b[1] if b and abs(b[0] - t) < 0.2 else None
def excess(fin, tk, t0, t1):
    a_, b_ = at(adjusted(fin[tk]['q']), t0), at(adjusted(fin[tk]['q']), t1)
    if not a_ or not b_ or a_ <= 0 or b_ <= 0:
        return None
    peers = []
    for p, v in fin.items():
        if p != tk and SECTOR.get(p) == SECTOR.get(tk) and not v['dead']:
            ps = adjusted(v['q'])
            x, y = at(ps, t0), at(ps, t1)
            if x and y and x > 0 and y > 0:
                peers.append(math.log(y / x))
    return math.log(b_ / a_) - st.fmean(peers) if peers else None
for kind in KINDS:
    ev, pl = [], []
    for e in entries:
        if e['kind'] != kind:
            continue
        fin = D[ARM, e['s']][1]['final']
        x = excess(fin, e['tk'], e['t'], e['t'] + 2.0)
        if x is not None:
            ev.append(x)
        if e['t'] > 1.0:
            p = excess(fin, e['tk'], e['t'] - 1.0, e['t'] + 1.0)
            if p is not None:
                pl.append(p)
    m, se, n = mse(ev)
    mp, sp, np_ = mse(pl)
    print(f'{kind}: entrant {m:+.4f} (se {se:.4f}, n {n}); placebo {mp:+.4f} (se {sp:.4f}, n {np_}); difference {m - mp:+.4f} (se {math.hypot(se, sp):.4f})')

print('\n### 8. Survivors at +12 y (no exit by then): kappa and revenue share; largest pivots at the last report')
for kind in KINDS:
    ks, ss = [], []
    for e in entries:
        if e['kind'] != kind or e['left'] < 12:
            continue
        x = next((x for x in e['path'] if abs(x['t'] - e['t'] - 12) <= 0.13), None)
        if x and x['kappa'].get(e['seg'], 0) > 0 and f'carry:segment_venture_abandoned:{e["seg"]}' not in x['vk']:
            tot = sum(x['streams'].values())
            ks.append(x['kappa'][e['seg']])
            ss.append(x['streams'].get(e['seg'], 0.0) / tot if tot else nan)
    print(f'{kind}: n {len(ks)}; kappa median {med(ks):.3f} p90 {q(ks, .9):.3f} max {max(ks, default=nan):.3f}; revenue share median {med(ss):.3f} p90 {q(ss, .9):.3f} max {max(ss, default=nan):.3f}')
piv = []
for e in entries:
    if e['path']:
        x = e['path'][-1]
        tot = sum(x['streams'].values())
        if tot:
            piv.append((x['streams'].get(e['seg'], 0.0) / tot, e, x['t']))
piv.sort(key=lambda p: -p[0])
for sh, e, t in piv[:5]:
    print(f"  {e['kind']} s{e['s']} {e['tk']}: {e['home']} -> {e['seg'][2:] if e['seg'] else '?'} ({e['close']}), entered y{e['t']:.1f}, revenue share {sh:.3f} at y{t:.1f}")
