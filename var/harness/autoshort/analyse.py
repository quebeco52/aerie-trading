"""analyse.py <arm> <seeds> [y0]: industry balance decomposition from runs/<arm>-<seed>.short.json."""
import json, math, sys
from collections import defaultdict
arm, seeds = sys.argv[1], [int(s) for s in sys.argv[2].split(',')]
y0 = float(sys.argv[3]) if len(sys.argv) > 3 else 5.0
E, ETA = 1.25, 1.0

def clear(r, f):
    lo, hi = 0.05, 20.0
    g = lambda p: r + f * p ** ETA - p ** (-E)
    for _ in range(60):
        mid = (lo + hi) / 2
        lo, hi = (lo, mid) if g(mid) > 0 else (mid, hi)
    return (lo + hi) / 2

ind = defaultdict(lambda: defaultdict(list))
firm = defaultdict(list)
board_p = []
for s in seeds:
    d = json.load(open(f'runs/{arm}-{s}.short.json'))
    seen = set()
    for r in d['rows']:
        t, tk, i, p, idle, S, G, u, L, R, F, cap, tp, rev, tg, fg, og, inf, anc = r
        firm[(s, tk)].append(r)
        if t < y0 or G is None:
            continue
        board_p.append(p)
        key = (s, i, round(t * 4))
        if key in seen:
            continue
        seen.add(key)
        lf = u - L  # m - f... x-f = L, x-m = u -> m-f = L-u
        lf = L - u
        P = clear(R, F)
        a = ind[i]
        a['P'].append(P); a['p'].append(p); a['S'].append(S); a['G'].append(G); a['u'].append(u); a['lf'].append(lf)
        a['noG'].append(clear(R * math.exp(G), F))
        a['noU'].append(clear(R * math.exp(u), F * math.exp(u)))
        a['noLf'].append(clear(R, F * math.exp(lf)))
        a['noAll'].append(clear(R * math.exp(G + u), F * math.exp(u + lf)))
mean = lambda x: sum(x) / len(x) if x else float('nan')
board_p.sort()
q = lambda x, f: x[int(f * (len(x) - 1))]
print(f'arm {arm} seeds {seeds} from y{y0}: board firm price level p5 {q(board_p,.05):.3f} p50 {q(board_p,.5):.3f} p95 {q(board_p,.95):.3f} mean {mean(board_p):.3f}')
print('industry | firm P | clear P | P noG | P noU | P noLf | P none | sumS | G (roster underbuild) | x-m | m-f (fringe lag)')
rows = sorted(ind.items(), key=lambda kv: -mean(kv[1]['P']))
for i, a in rows:
    print(f"{i[:26]:26s} | {mean(a['p']):.3f} | {mean(a['P']):.3f} | {mean(a['noG']):.3f} | {mean(a['noU']):.3f} | {mean(a['noLf']):.3f} | {mean(a['noAll']):.3f} | {mean(a['S']):.2f} | {mean(a['G']):+.3f} | {mean(a['u']):+.3f} | {mean(a['lf']):+.3f}")
allP = [x for a in ind.values() for x in a['P']]
for k in ('noG', 'noU', 'noLf', 'noAll'):
    print(f"board mean clear P {mean(allP):.3f}  with {k} zeroed {mean([x for a in ind.values() for x in a[k]]):.3f}")
# FALC/EIDR anatomy, per seed: annual log growth over GDP (y1 -> end) and its parts
print('firm seed | rev-tgdp %/yr | cap-tgdp | trendplant-tgdp | anchor share chg | own G end | mean firm P | mean own G | gate shut q (G>0.05, filed<=0)')
for (s, tk), rs in sorted(firm.items()):
    if tk not in ('FALC', 'EIDR'):
        continue
    a, b = rs[3], rs[-1]
    yrs = b[0] - a[0]
    if yrs <= 0:
        continue
    rv = lambda r: sum(x[13] for x in rs if r[0] - 1 < x[0] <= r[0])
    g = lambda i: 100 * math.log((b[i] / b[14]) / (a[i] / a[14])) / yrs
    late = [x for x in rs if x[0] >= y0]
    shut = sum(1 for x in late if x[15] is not None and x[11] > 0 and x[12] and math.log(x[12] / x[11]) > 0.05 and x[15] <= 0)
    print(f"{tk} s{s} {a[0]:.1f}-{b[0]:.1f} | {100*math.log((rv(b)/b[14])/(rv(a)/a[14]))/yrs:+.2f} | {g(11):+.2f} | {g(12):+.2f} | {100*math.log(b[18]/a[18]):+.1f}% | {math.log(b[12]/b[11]):+.3f} | {mean([x[3] for x in late]):.3f} | {mean([math.log(x[12]/x[11]) for x in late]):+.3f} | {shut}/{len(late)}")
