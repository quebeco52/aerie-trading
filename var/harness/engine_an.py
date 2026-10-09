"""Engine-wide A/B of the sticky-cost fix: nofix vs fix, paired on the same replayed macro paths."""
import glob
import json
import math
import statistics as st
import sys
from collections import Counter

H = sys.argv[1] if len(sys.argv) > 1 else '.'
ARMS = ['nofix', 'fix']
PRICE_DRIVEN = ['SINK', 'CASC', 'CNDR', 'ALBT', 'GANN', 'WING', 'CANV', 'FULM']
DRIVER = {'SINK': 'crude', 'CASC': 'crack', 'CNDR': 'metals', 'ALBT': 'freight', 'GANN': 'freight', 'WING': 'metals', 'CANV': 'freight', 'FULM': 'crude'}
model = {t: v[1] for t, v in json.load(open(f'{H}/tickers.json')).items()}


def load(arm):
    out = {}
    for f in glob.glob(f'{H}/runs/{arm}-*.jsonl'):
        seed = int(f.rsplit('-', 1)[1].split('.')[0])
        out[seed] = json.loads(open(f).readline())
    return out


def ac1(xs):
    m = st.mean(xs)
    den = sum((x - m) ** 2 for x in xs)
    return sum((xs[i] - m) * (xs[i - 1] - m) for i in range(1, len(xs))) / den if den > 0 else float('nan')


def corr(x, y):
    mx, my = st.mean(x), st.mean(y)
    den = math.sqrt(sum((a - mx) ** 2 for a in x) * sum((b - my) ** 2 for b in y))
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / den if den > 0 else float('nan')


def pct(v, p):
    s = sorted(v)
    return s[min(len(s) - 1, max(0, int(round(p * (len(s) - 1)))))]


data = {a: load(a) for a in ARMS}
seeds = sorted(set(data['nofix']) & set(data['fix']))
print(f'paired paths: {len(seeds)}  (runtime per run ~{st.mean(data["fix"][s]["secs"] for s in seeds):.0f}s)')

print('\n--- failures (pooled over paths) ---')
for a in ARMS:
    deaths = Counter(t for s in seeds for t in (data[a][s]['dead'] or {}))
    reorgs = Counter(x['t'] for s in seeds for x in (data[a][s]['reorgs'] or []))
    defaults = Counter(t for s in seeds for t in (data[a][s]['defaults'] or {}))
    print(f'{a:6s} deaths {sum(deaths.values()):2d} {dict(sorted(deaths.items()))}')
    print(f'{"":6s} reorgs {sum(reorgs.values()):2d} {dict(sorted(reorgs.items()))}')
    print(f'{"":6s} firms in default at an audit (firm-paths) {sum(1 for s in seeds for _ in (data[a][s]["defaults"] or {}))}')

# --- per-firm stats, pooled over paths ---
stats = {}
for a in ARMS:
    for s in seeds:
        for t, f in data[a][s]['final'].items():
            q = [x for x in f['q'] if x['rev'] > 0]
            if len(q) < 20:
                continue
            d = stats.setdefault(t, {}).setdefault(a, {'om': [], 'ac': [], 'eq': [], 'loss': 0, 'n': 0, 'corr': []})
            d['om'] += [x['om'] for x in q]
            d['ac'].append(ac1([x['om'] for x in q]))
            d['eq'].append(f['eq_x'])
            d['loss'] += sum(1 for x in q if x['ebit'] < 0)
            d['n'] += len(q)
            if t in DRIVER:
                d['corr'].append(corr([x[DRIVER[t]] for x in q], [x['om'] for x in q]))

firms = [t for t, d in stats.items() if all(a in d for a in ARMS)]
shift = {t: st.median(stats[t]['fix']['om']) - st.median(stats[t]['nofix']['om']) for t in firms}
print(f'\n--- all {len(firms)} surviving-long-enough firms ---')
print(f'median operating margin, per-firm shift: mean {100 * st.mean(shift.values()):+.2f}pp  median {100 * st.median(shift.values()):+.2f}pp  p10 {100 * pct(list(shift.values()), .1):+.2f}  p90 {100 * pct(list(shift.values()), .9):+.2f}')
for label, key in [('margin lag-1 autocorrelation', 'ac')]:
    print(f'{label} (median across firms): ' + ' -> '.join(f'{st.median([st.median([v for v in stats[t][a][key] if v == v]) for t in firms]):.3f}' for a in ARMS))
print('equity multiple over 20y (median across firms): ' + ' -> '.join(f'{st.median([st.median(stats[t][a]["eq"]) for t in firms]):.2f}' for a in ARMS))
print('loss-quarter share (mean across firms): ' + ' -> '.join(f'{st.mean([stats[t][a]["loss"] / stats[t][a]["n"] for t in firms]):.4f}' for a in ARMS))

print('\nlargest median-margin movers:')
for t in sorted(firms, key=lambda t: abs(shift[t]), reverse=True)[:10]:
    n, f = stats[t]['nofix'], stats[t]['fix']
    print(f'  {t:5s} {model.get(t, "?"):24s} om {100 * st.median(n["om"]):6.2f}% -> {100 * st.median(f["om"]):6.2f}%  eq_x {st.median(n["eq"]):5.2f} -> {st.median(f["eq"]):5.2f}')

print('\nprice-driven firms: om p5/p50/p95, corr(margin, own price driver), eq_x')
for t in PRICE_DRIVEN:
    if t not in firms:
        continue
    n, f = stats[t]['nofix'], stats[t]['fix']
    fmt = lambda d: f'{100 * pct(d["om"], .05):5.1f}/{100 * st.median(d["om"]):5.1f}/{100 * pct(d["om"], .95):5.1f}  corr {st.median(d["corr"]):+.2f}  eq_x {st.median(d["eq"]):4.2f}'
    print(f'  {t:5s} {model[t]:18s} {fmt(n)}  ->  {fmt(f)}')
