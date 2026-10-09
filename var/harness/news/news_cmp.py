#!/usr/bin/env python3
"""BEFORE vs AFTER: divestiture announcement returns, seller book effect, failures. Usage: news_cmp.py [Y0 Y1]."""
import json, glob, math, sys, statistics as st
B = '/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/news/'
Y0 = float(sys.argv[1]) if len(sys.argv) > 1 else 0.0
Y1 = float(sys.argv[2]) if len(sys.argv) > 2 else 10.0
YEARS = Y1 - Y0
def q(xs, p):
    xs = sorted(xs); k = (len(xs) - 1) * p; i = int(k); return xs[i] + (xs[min(i + 1, len(xs) - 1)] - xs[i]) * (k - i)
def mse(xs):
    return sum(xs) / len(xs), (st.stdev(xs) / math.sqrt(len(xs)) if len(xs) > 1 else float('nan'))
out = {}
for arm in ('before', 'after'):
    files = sorted(glob.glob(B + f'runs_{arm}/news-*.jsonl'))
    per_n, ch, gpct, neg, negpre, fails, n = [], [], [], 0, 0, 0, 0
    for f in files:
        rows = [json.loads(l) for l in open(f)]
        if not rows or rows[-1]['y'] < Y1 - 0.05: continue
        n += 1
        rows = [r for r in rows if Y0 < r['y'] <= Y1]
        d = [r for r in rows if r['ty'] == 'DIVESTITURE']
        per_n.append(len(d) / YEARS)
        for r in d:
            ch.append(r['ch'])
            if r.get('eq0', 0) > 0 and 'gain' in r: gpct.append(100 * r['gain'] / r['eq0'])
            if r.get('eq1', 0) < 0:
                neg += 1
                negpre += r.get('eq0', 0) < 0
        fails += sum(1 for r in rows if r['c'] in ('bankruptcy', 'reorganization'))
    out[arm] = dict(n=n, per=mse(per_n), ch=ch, g=gpct, neg=neg, negpre=negpre, fails=fails)
print(f'years {Y0:g}-{Y1:g}; n seeds: before {out["before"]["n"]}, after {out["after"]["n"]}')
print(f"{'quantity':42s} {'BEFORE':>18s} {'AFTER':>18s}")
f2 = lambda m: f'{m[0]:.2f} +- {m[1]:.2f}'
print(f"{'divestitures / yr (mean +- se)':42s} {f2(out['before']['per']):>18s} {f2(out['after']['per']):>18s}")
for lab, fn in (('ann. return % mean (se, pooled)', lambda x: f'{st.mean(x):.2f} ({st.stdev(x)/math.sqrt(len(x)):.2f})'),
                ('ann. return % p10', lambda x: f'{q(x,.1):.2f}'), ('ann. return % p50', lambda x: f'{q(x,.5):.2f}'),
                ('ann. return % p90', lambda x: f'{q(x,.9):.2f}'), ('ann. return % max', lambda x: f'{max(x):.2f}'),
                ('share ann. return > +10%', lambda x: f'{sum(1 for v in x if v > 10)/len(x):.1%}'), ('deals pooled', lambda x: str(len(x)))):
    print(f"{lab:42s} {fn(out['before']['ch']):>18s} {fn(out['after']['ch']):>18s}")
for lab, fn in (('gain on sale % pre-sale book: mean (se)', lambda x: f'{st.mean(x):.2f} ({st.stdev(x)/math.sqrt(len(x)):.2f})'),
                ('gain on sale % book: p10 / p50 / p90', lambda x: f'{q(x,.1):.1f}/{q(x,.5):.1f}/{q(x,.9):.1f}'),
                ('share of sales booking a loss', lambda x: f'{sum(1 for v in x if v < 0)/len(x):.1%}')):
    print(f"{lab:42s} {fn(out['before']['g']):>18s} {fn(out['after']['g']):>18s}")
print(f"{'sales leaving negative book (of which neg before)':42s} {str(out['before']['neg'])+' ('+str(out['before']['negpre'])+')':>18s} {str(out['after']['neg'])+' ('+str(out['after']['negpre'])+')':>18s}")
print(f"{'bankruptcies + reorganizations (total)':42s} {out['before']['fails']:>18d} {out['after']['fails']:>18d}")
