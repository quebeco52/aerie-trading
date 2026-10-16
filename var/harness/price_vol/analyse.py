#!/usr/bin/env python3
"""Price-channel variance decomposition from PriceVolHarnessTest runs.

analyse.py [seeds, default 1-4] [skip ticks, default 360] [tpy, default 360]
Prints: per-name realised vol by horizon, component variance shares at 1 and 21 ticks, the budget audit, and a
sector summary. Shares are var(component block sum) / var(total block sum); 'cov' is 1 - sum of shares.
"""
import csv, json, os, sys, math, statistics as st
from collections import defaultdict

H = os.path.dirname(os.path.abspath(__file__))
seeds = sys.argv[1] if len(sys.argv) > 1 else '1-4'
a, _, b = seeds.partition('-')
seeds = list(range(int(a), int(b or a) + 1))
SKIP = int(sys.argv[2]) if len(sys.argv) > 2 else 360
TPY = int(sys.argv[3]) if len(sys.argv) > 3 else 360
HOR = [1, 5, 21, 63]
COMP = ['mkt', 'sec', 'idio', 'pull', 'brk', 'jI', 'jS', 'mapol', 'impP', 'impT', 'earn']
FOCUS = ['PHIL', 'SGRB', 'CROP', 'LARK', 'PINT']


def var(xs):
    n = len(xs)
    if n < 3:
        return float('nan')
    m = sum(xs) / n
    return sum((x - m) ** 2 for x in xs) / (n - 1)


def blocks(xs, h):
    return [sum(xs[i:i + h]) for i in range(0, len(xs) - h + 1, h)]


# per ticker: list over seeds of {h: {comp: annualised var}}
res = defaultdict(list)
meta, audit = {}, defaultdict(list)
for s in seeds:
    j = json.load(open(f'{H}/runs/live-{s}.json'))
    meta.update(j['meta'])
    for tk, a_ in j['audit'].items():
        n = a_['n']
        audit[tk].append({k: v / n for k, v in a_.items()})
    series = defaultdict(lambda: defaultdict(list))
    with open(f'{H}/runs/live-{s}.csv') as f:
        for r in csv.DictReader(f):
            if int(r['t']) <= SKIP:
                continue
            d = series[r['tk']]
            d['tot'].append(float(r['tot']))
            for c in ('mkt', 'sec', 'idio', 'pull', 'brk', 'jI', 'jS', 'impP', 'impT'):
                d[c].append(float(r[c]))
            d['mapol'].append(float(r['ma']) + float(r['pol']))
            d['earn'].append(float(r['warn']) + float(r['rep']))
    for tk, d in series.items():
        if len(d['tot']) < 2 * TPY:
            continue
        out = {}
        for h in HOR:
            ann = TPY / h
            out[h] = {c: var(blocks(d[c], h)) * ann for c in ['tot'] + COMP}
        res[tk].append(out)


def pooled(tk, h, c):
    return st.mean(r[h][c] for r in res[tk])


rows = []
for tk in res:
    m = meta[tk]
    au = {k: st.mean(x[k] for x in audit[tk]) for k in audit[tk][0]}
    vol = {h: math.sqrt(pooled(tk, h, 'tot')) for h in HOR}
    v21s = [math.sqrt(r[21]['tot']) for r in res[tk]]
    sh = {h: {c: pooled(tk, h, c) / pooled(tk, h, 'tot') for c in COMP} for h in (1, 21)}
    rows.append(dict(tk=tk, sector=m['sector'], sigma=m['sigma'], beta=au['beta'], vol=vol, se21=(st.stdev(v21s) / math.sqrt(len(v21s)) if len(v21s) > 1 else float('nan')),
                     n=len(res[tk]), sh=sh, au=au, var21={c: pooled(tk, 21, c) for c in COMP}, var1={c: pooled(tk, 1, c) for c in COMP}))
rows.sort(key=lambda r: -(r['vol'][21] - r['sigma']))

P = lambda x: f'{100 * x:5.0f}'
print(f'seeds {seeds}, skip {SKIP} ticks, {TPY} ticks/yr; vol annualised from non-overlapping block log returns (total return)')
print('\n== realised vol by horizon, ranked by 21-tick excess ==')
print(f"{'tk':5} {'sector':22} {'sig':>5} {'beta':>5} {'v1':>5} {'v5':>5} {'v21':>5} {'se':>4} {'v63':>5} {'ex21':>5} n")
for r in rows:
    print(f"{r['tk']:5} {r['sector'][:22]:22} {r['sigma']:5.2f} {r['beta']:5.2f} {r['vol'][1]:5.2f} {r['vol'][5]:5.2f} {r['vol'][21]:5.2f} {r['se21']:4.2f} {r['vol'][63]:5.2f} {r['vol'][21] - r['sigma']:+5.2f} {r['n']}")

for h in (1, 21):
    print(f'\n== variance share (%) at {h} tick(s); cov = 1 - sum ==')
    print(f"{'tk':5} " + ' '.join(f'{c:>5}' for c in COMP) + '   cov')
    for r in rows:
        s = r['sh'][h]
        print(f"{r['tk']:5} " + ' '.join(P(s[c]) for c in COMP) + ' ' + P(1 - sum(s.values())))

print('\n== budget audit (time means; variances annualised; cap = 25% V_I) ==')
print(f"{'tk':5} {'sig2':>6} {'V_I':>6} {'flr':>4} {'scale':>5} {'sysJ':>6} {'ownJ':>6} {'impE':>6} {'annE':>6} {'cap':>6} {'fund':>6} {'bSys':>6} {'bAnch':>6} {'theta':>6} {'idioSt':>6} {'trans1':>6} {'trans21':>7} {'idio21':>6}")
for r in rows:
    a_ = r['au']
    print(f"{r['tk']:5} {r['sigma'] ** 2:6.4f} {a_['vi']:6.4f} {a_['floor']:4.2f} {a_['scale']:5.2f} {a_['sysJ']:6.4f} {a_['ownJ']:6.4f} {a_['imp']:6.4f} {a_['ann']:6.4f} {a_['cap']:6.4f} {a_['fund']:6.4f} {a_['shockSys']:6.4f} {a_['sysVarAnchor']:6.4f} {a_['theta']:6.4f} {a_['curIdio']:6.4f} {r['var1']['impT']:6.4f} {r['var21']['impT']:7.4f} {r['var21']['idio'] + r['var21']['sec']:6.4f}")

print('\n== component variance at 21 ticks (annualised), focus names and sector means ==')
hdr = f"{'name':22} {'sig2':>6} {'tot':>6} " + ' '.join(f'{c:>6}' for c in COMP) + f" {'cov':>6}"
print(hdr)


def line(label, rs):
    sig2 = st.mean(r['sigma'] ** 2 for r in rs)
    tot = st.mean(r['vol'][21] ** 2 for r in rs)
    cs = {c: st.mean(r['var21'][c] for r in rs) for c in COMP}
    print(f"{label[:22]:22} {sig2:6.4f} {tot:6.4f} " + ' '.join(f'{cs[c]:6.4f}' for c in COMP) + f" {tot - sum(cs.values()):+6.4f}")


for tk in FOCUS:
    rs = [r for r in rows if r['tk'] == tk]
    if rs:
        line(tk, rs)
bysec = defaultdict(list)
for r in rows:
    bysec[r['sector']].append(r)
for sec in sorted(bysec, key=lambda s: -st.mean(r['vol'][21] - r['sigma'] for r in bysec[s])):
    line(f'{sec} ({len(bysec[sec])})', bysec[sec])
line(f'ALL ({len(rows)})', rows)

print('\n== sector summary ==')
print(f"{'sector':24} {'n':>2} {'sig':>5} {'v1':>5} {'v21':>5} {'v63':>5} {'ex21':>5} {'flr':>4} {'beta':>5}")
for sec in sorted(bysec, key=lambda s: -st.mean(r['vol'][21] - r['sigma'] for r in bysec[s])):
    rs = bysec[sec]
    print(f"{sec[:24]:24} {len(rs):2} {st.mean(r['sigma'] for r in rs):5.2f} {st.mean(r['vol'][1] for r in rs):5.2f} {st.mean(r['vol'][21] for r in rs):5.2f} {st.mean(r['vol'][63] for r in rs):5.2f} {st.mean(r['vol'][21] - r['sigma'] for r in rs):+5.2f} {st.mean(r['au']['floor'] for r in rs):4.2f} {st.mean(r['beta'] for r in rs):5.2f}")


print('\n== excess variance attribution at 21 ticks: realised component minus what the budget allows it ==')
print('  sysMkt = mkt - (beta*anchor)^2 ; sysJ = jS - min(sysJ,cap) ; ownJ = jI - min(ownJ,cap) ; diff = idio+sec - theta')
print('  ann = earn - min(annE,cap) ; imp = impP+impT - min(impE,cap) ; viFlr = V_I - (sig2 - (beta*anchor)^2) ; rest = pull+brk+ma/pol+cov+scale')
AT = ['sysMkt', 'sysJ', 'ownJ', 'diff', 'ann', 'imp', 'viFlr', 'rest']


def attr(r):
    a_, v = r['au'], r['var21']
    cap = a_['cap']
    d = {
        'sysMkt': v['mkt'] - a_['sysVarAnchor'],
        'sysJ': v['jS'] - min(a_['sysJ'], cap),
        'ownJ': v['jI'] - min(a_['ownJ'], cap),
        'diff': v['idio'] + v['sec'] - a_['theta'],
        'ann': v['earn'] - min(a_['ann'], cap),
        'imp': v['impP'] + v['impT'] - min(a_['imp'], cap),
        'viFlr': a_['vi'] - (r['sigma'] ** 2 - a_['sysVarAnchor']),
    }
    d['rest'] = (r['vol'][21] ** 2 - r['sigma'] ** 2) - sum(d.values())
    return d


def aline(label, rs):
    ds = [attr(r) for r in rs]
    ex = st.mean(r['vol'][21] ** 2 - r['sigma'] ** 2 for r in rs)
    print(f"{label[:22]:22} {ex:+7.4f} " + ' '.join(f"{st.mean(d[k] for d in ds):+7.4f}" for k in AT))


print(f"{'name':22} {'excess':>7} " + ' '.join(f'{k:>7}' for k in AT))
for tk in FOCUS + ['FALC', 'HUMM', 'CORM', 'DIPR']:
    rs = [r for r in rows if r['tk'] == tk]
    if rs:
        aline(tk, rs)
for sec in sorted(bysec, key=lambda s: -st.mean(r['vol'][21] - r['sigma'] for r in bysec[s])):
    aline(f'{sec} ({len(bysec[sec])})', bysec[sec])
aline('floored V_I (>50% ticks)', [r for r in rows if r['au']['floor'] > 0.5])
aline('unfloored', [r for r in rows if r['au']['floor'] <= 0.5])
aline(f'ALL ({len(rows)})', rows)
