#!/usr/bin/env python3
"""pace.py <armA> <armB> [seeds]: paired table (B - A) for the debt-target pacing check (2026-10-14).
Targets from the quarter rows (year 1 dropped): primary-deficit max |4q change| and mean |4q change| (pp of GDP);
gap share < -2%, gap sd, deepest gap; debt/GDP mean, sd, max. Guards: analyse.py union/sahm_t plus compare.py's row
guards. Also debt-target changes >= 10pp per seed and whether the target paths differ between the arms."""
import io, json, math, os, re, sys, contextlib

H = os.path.dirname(os.path.abspath(__file__))
A, B = sys.argv[1], sys.argv[2]
SEEDS = None
if len(sys.argv) > 3:
    lo, _, hi = sys.argv[3].partition('-'); SEEDS = set(range(int(lo), int(hi or lo) + 1))


def mean(x): return sum(x) / len(x) if x else float('nan')
def sd(x): m = mean(x); return math.sqrt(sum((v - m) ** 2 for v in x) / (len(x) - 1))
def se(x): return sd(x) / math.sqrt(len(x)) if len(x) > 1 else float('nan')


def per_arm(arm):
    g = {'__name__': 'analyse_import', '__file__': f'{H}/analyse.py'}
    sys.argv = ['analyse.py', arm]
    with contextlib.redirect_stdout(io.StringIO()):
        exec(open(f'{H}/analyse.py').read(), g)
    per, paths = g['per'], {}
    for s, m in per.items():
        allrows = [json.loads(l) for l in open(f'{H}/runs/{arm}-{s}.macro.jsonl') if l.strip()]
        rows = [r for r in allrows if r['t'] > 1.0]
        p = [100 * r['primary'] for r in rows]
        d4 = [abs(p[i] - p[i - 4]) for i in range(4, len(p))]
        m['prim_max4q'] = max(d4); m['prim_mean4q'] = mean(d4)
        m['prim_mean'] = mean(p)
        gap = [100 * r['gap'] for r in rows]
        m['gap<-2'] = mean([1.0 if x < -2 else 0.0 for x in gap]); m['gap_sd'] = sd(gap); m['gap_min'] = min(gap)
        debt = [100 * r['debt'] for r in rows]
        m['debt_mean'] = mean(debt); m['debt_sd'] = sd(debt); m['debt_max'] = max(debt)
        tg = [r['debt_target'] for r in allrows]
        m['tgt_jumps10'] = float(sum(1 for i in range(1, len(tg)) if abs(tg[i] - tg[i - 1]) >= 0.10 - 1e-9))
        m['anchor_lag'] = mean([100 * abs(r['debt_target'] - r['anchor']) for r in rows])
        m['infl_mean%'] = 100 * mean([r['infl'] for r in rows]); m['infl_sd%'] = 100 * sd([r['infl'] for r in rows])
        m['policy%'] = 100 * mean([r['policy'] for r in rows]); m['y10%'] = 100 * mean([r['y10'] for r in rows])
        m['u_mean%'] = 100 * mean([r['u'] for r in rows])
        m['net_tax%'] = 100 * mean([r['net_tax'] for r in rows])
        m['spend/trend'] = mean([r['spend_idx'] / r['spend_trend'] for r in rows])
        pe = sorted(r['pe_med'] for r in rows); m['pe_med'] = pe[len(pe) // 2]
        log = open(f'{H}/runs/{arm}-{s}.log').read()
        dd = re.search(r'deaths (\[\]|\{[^}]*\})', log)
        m['deaths'] = float(len(json.loads(dd.group(1)))) if dd else float('nan')
        paths[s] = [round(x, 4) for x in tg]
    return per, paths


(pa, ta), (pb, tb) = per_arm(A), per_arm(B)
seeds = sorted(s for s in set(pa) & set(pb) if SEEDS is None or s in SEEDS)
targets = ['prim_max4q', 'prim_mean4q', 'gap<-2', 'gap_sd', 'gap_min', 'debt_mean', 'debt_sd', 'debt_max']
guards = ['union', 'sahm_t', 'infl_mean%', 'infl_sd%', 'policy%', 'y10%', 'u_mean%', 'deaths', 'pe_med',
          'prim_mean', 'net_tax%', 'spend/trend', 'anchor_lag', 'tgt_jumps10']

print(f'{A} vs {B} (diff = {B} - {A}): n={len(seeds)} paired seeds {seeds[0]}-{seeds[-1]}, quarter rows after year 1; pp unless share')
for title, ks in (('targets', targets), ('guards and context', guards)):
    print(f'\n{title}\n{"quantity":<14}{A:>9}{"(se)":>8}{B:>9}{"(se)":>8}{"diff":>9}{"(se)":>8}{"t":>7}{"n":>4}')
    for k in ks:
        xa = [pa[s][k] for s in seeds if not math.isnan(pa[s].get(k, float('nan')))]
        xb = [pb[s][k] for s in seeds if not math.isnan(pb[s].get(k, float('nan')))]
        dd = [pb[s][k] - pa[s][k] for s in seeds if k in pa[s] and k in pb[s] and not math.isnan(pb[s][k] - pa[s][k])]
        t = mean(dd) / se(dd) if len(dd) > 1 and se(dd) > 0 else float('nan')
        print(f'{k:<14}{mean(xa):>9.3f}{"(" + format(se(xa), ".3f") + ")":>8}{mean(xb):>9.3f}{"(" + format(se(xb), ".3f") + ")":>8}'
              f'{mean(dd):>9.3f}{"(" + format(se(dd), ".3f") + ")":>8}{t:>7.2f}{len(dd):>4}')

print('\ndebt-target changes >= 10pp per seed (A/B) and first quarter the target paths differ')
for s in seeds:
    a, b = ta[s], tb[s]
    first = next((i for i in range(min(len(a), len(b))) if a[i] != b[i]), None)
    print(f'  seed {s:>2}: {int(pa[s]["tgt_jumps10"])}/{int(pb[s]["tgt_jumps10"])}  '
          + ('same path' if first is None else f'diverge at row {first} (year ~{first / 4:.1f})'))
