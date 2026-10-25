#!/usr/bin/env python3
"""SKYL idle plant, paired against the arm without it on the same seeds. Usage: idle.py idle learn."""
import io, contextlib, json, math, statistics as st, sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'lib'))
with contextlib.redirect_stdout(io.StringIO()):
    import per_seed as ps
from stats import paired, fmt


def spearman(x, y):
    rk = lambda v: [sorted(v).index(e) for e in v]
    return st.correlation(rk(x), rk(y))

P = Path(__file__).parent
a, b = (sys.argv[1:] + ['idle', 'learn'])[:2]


def info(arm, s):
    d = json.load(open(P / 'runs' / f'{arm}-s{s}.json'))
    q = d['q']['SKYL']
    M = next((r['regime']['state:broadband_market_size'] for r in q if 'state:broadband_market_size' in r['regime']), float('nan')) / 2.0
    idle = [r['kpi'].get('idle_plant_share', 0.0) for r in q]
    pr = ps.seed_row(arm, s)
    yr = lambda y: q[4 * (y - 1):4 * y]
    ni20 = sum(r['ni'] for r in yr(20))
    first_idle = next((round(q[i]['t'], 2) for i, x in enumerate(idle) if x > 0), None)
    return {
        'M': M, 'idle mean': st.fmean(idle), 'idle max': max(idle), 'idle years': sum(1 for x in idle if x > 0) / 4,
        'first idle t': first_idle, 'idle at max t': round(q[max(range(len(q)), key=lambda i: idle[i])]['t'], 2),
        'rd': pr['real/des y20'], 'capx': q[-1]['cap'] / d['cap1']['SKYL'], 'worst': pr['worst'], 'loss q': pr['loss q'],
        'distress': sum(1 for r in q if r['qid'] or r['pd']) + (d.get('defaults') or {}).get('SKYL', 0),
        'payout': sum(r['div'] for r in yr(20)) / ni20 if ni20 > 0 else float('nan'),
        'launch20': sum(r['streams']['launch_services'] for r in yr(20)) / 1e9,
        'om10': st.fmean(r['om'] for r in yr(10)), 'om20': st.fmean(r['om'] for r in yr(20)),
        'roic10': yr(10)[-1]['diag']['roic_ttm'], 'roic20': yr(20)[-1]['diag']['roic_ttm'],
        'capex/ocf idle': (sum(-r['diag']['icf'] for r, x in zip(q, idle) if x > 0) / max(1.0, sum(r['diag']['ocf'] for r, x in zip(q, idle) if x > 0))) if any(x > 0 for x in idle) else None,
        'capex/ocf busy': sum(-r['diag']['icf'] for r, x in zip(q, idle) if x == 0) / max(1.0, sum(r['diag']['ocf'] for r, x in zip(q, idle) if x == 0)),
    }


A = {s: info(a, s) for s in range(1, 9)}
B = {s: info(b, s) for s in range(1, 9)}
print(f'seed  M/2   idle mean/max/years first-t max-t | real/des {b}->{a} | cap x | worst | loss q | distress | payout y20 | launch y20 $B | om10/om20 | roic10/roic20 | capex/ocf idle vs busy')
for s in range(1, 9):
    x, y = A[s], B[s]
    cio = f"{x['capex/ocf idle']:.2f}" if x['capex/ocf idle'] is not None else '-'
    print(f"{s:4d} {x['M']:5.2f}   {x['idle mean']:.3f}/{x['idle max']:.3f}/{x['idle years']:.2f} {x['first idle t']} {x['idle at max t']} | "
          f"{y['rd']:.2f}->{x['rd']:.2f} | {y['capx']:.1f}->{x['capx']:.1f} | {y['worst']}->{x['worst']} | {y['loss q']}->{x['loss q']} | "
          f"{y['distress']}->{x['distress']} | {y['payout']:.2f}->{x['payout']:.2f} | {y['launch20']:.1f}->{x['launch20']:.1f} | "
          f"{y['om10'] * 100:.0f}/{y['om20'] * 100:.0f}->{x['om10'] * 100:.0f}/{x['om20'] * 100:.0f} | "
          f"{y['roic10'] * 100:.0f}/{y['roic20'] * 100:.0f}->{x['roic10'] * 100:.0f}/{x['roic20'] * 100:.0f} | {cio} vs {x['capex/ocf busy']:.2f}")
for lab, sel in (('all', range(1, 9)), ('small M<1', [s for s in range(1, 9) if A[s]['M'] < 1]), ('large M>=1', [s for s in range(1, 9) if A[s]['M'] >= 1])):
    rd = paired([B[s]['rd'] for s in sel], [A[s]['rd'] for s in sel])
    lc = paired([math.log(B[s]['capx']) for s in sel], [math.log(A[s]['capx']) for s in sel])
    print(f'{lab:11s} {a}-{b}: real/designed y20 {fmt(rd)}; log cap x {fmt(lc)}')
Ms = [A[s]['M'] for s in range(1, 9)]
print(f"Spearman(M, real/des y20) {spearman(Ms, [A[s]['rd'] for s in range(1, 9)]):+.2f}; Spearman(M, cap x) {spearman(Ms, [A[s]['capx'] for s in range(1, 9)]):+.2f}")
