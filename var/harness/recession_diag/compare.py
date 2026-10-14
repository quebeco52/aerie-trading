#!/usr/bin/env python3
"""compare.py <armA> <armB> [seeds]: paired table of analyse.py per-seed moments plus macro-row guards.
Targets: techrec, union, sahm_t, latch_t, latch_entry/c, latch_dur_m, rgdp_g%, pot_g%.
Guards from the quarter rows (year 1 dropped): inflation mean/sd, policy, 10Y, u mean, gap sd, debt/GDP at the end,
median P/E and board cap/NI (rows from 2026-10-14 on only), deaths per seed (from the log's last line)."""
import io, json, math, os, re, sys, contextlib

H = os.path.dirname(os.path.abspath(__file__))
A, B = sys.argv[1], sys.argv[2]
SEEDS = None
if len(sys.argv) > 3:
    lo, _, hi = sys.argv[3].partition('-'); SEEDS = set(range(int(lo), int(hi or lo) + 1))


def per_arm(arm):
    g = {'__name__': 'analyse_import', '__file__': f'{H}/analyse.py'}
    sys.argv = ['analyse.py', arm]
    with contextlib.redirect_stdout(io.StringIO()):
        exec(open(f'{H}/analyse.py').read(), g)
    per = g['per']
    for s, m in per.items():
        rows = [json.loads(l) for l in open(f'{H}/runs/{arm}-{s}.macro.jsonl') if l.strip()]
        rows = [r for r in rows if r['t'] > 1.0]
        mu = lambda k: sum(r[k] for r in rows) / len(rows)
        m['infl_mean%'] = 100 * mu('infl')
        mi = m['infl_mean%'] / 100
        m['infl_sd%'] = 100 * math.sqrt(sum((r['infl'] - mi) ** 2 for r in rows) / (len(rows) - 1))
        m['policy%'] = 100 * mu('policy'); m['y10%'] = 100 * mu('y10'); m['u_mean%'] = 100 * mu('u')
        m['debt_end%'] = 100 * rows[-1]['debt']
        if 'pe_med' in rows[0]:
            pe = sorted(r['pe_med'] for r in rows); m['pe_med'] = pe[len(pe) // 2]
            bp = sorted(r['board_pe'] for r in rows); m['board_pe'] = bp[len(bp) // 2]
            m['neg_eps'] = sum(r['neg_eps'] for r in rows) / len(rows)
        log = open(f'{H}/runs/{arm}-{s}.log').read()
        d = re.search(r'deaths (\[\]|\{[^}]*\})', log)
        m['deaths'] = float(len(json.loads(d.group(1)))) if d else float('nan')
    return per


pa, pb = per_arm(A), per_arm(B)
seeds = sorted(s for s in set(pa) & set(pb) if SEEDS is None or s in SEEDS)
targets = ['techrec', 'union', 'sahm_t', 'latch_t', 'latch_entry/c', 'latch_dur_m', 'rgdp_g%', 'pot_g%']
guards = ['infl_mean%', 'infl_sd%', 'policy%', 'y10%', 'u_mean%', 'gap_sd%', 'debt_end%', 'deaths', 'rgdp_sd%', 'rgdp_acf1',
          'pe_med', 'board_pe', 'neg_eps']


def mean(x): return sum(x) / len(x)
def se(x): return math.sqrt(sum((v - mean(x)) ** 2 for v in x) / (len(x) - 1) / len(x)) if len(x) > 1 else float('nan')


print(f'{A} vs {B}: n={len(seeds)} paired seeds {seeds[0]}-{seeds[-1]} x 20 years after year 1')
for title, ks in (('targets', targets), ('guards', guards)):
    print(f'\n{title}\n{"quantity":<15}{A:>9}{"(se)":>8}{B:>9}{"(se)":>8}{"diff":>9}{"(se)":>8}{"t":>7}{"n":>4}')
    for k in ks:
        xa = [pa[s][k] for s in seeds if k in pa[s] and not math.isnan(pa[s][k])]
        xb = [pb[s][k] for s in seeds if k in pb[s] and not math.isnan(pb[s][k])]
        fa = f'{mean(xa):>9.3f}{"(" + format(se(xa), ".3f") + ")":>8}' if xa else f'{"--":>9}{"":>8}'
        fb = f'{mean(xb):>9.3f}{"(" + format(se(xb), ".3f") + ")":>8}' if xb else f'{"--":>9}{"":>8}'
        dd = [pb[s][k] - pa[s][k] for s in seeds if k in pa[s] and k in pb[s]]
        dd = [x for x in dd if not math.isnan(x)]
        fd = f'{mean(dd):>9.3f}{"(" + format(se(dd), ".3f") + ")":>8}{mean(dd) / se(dd) if se(dd) > 0 else float("nan"):>7.2f}' if len(dd) > 1 else f'{"":>24}'
        print(f'{k:<15}{fa}{fb}{fd}{len(dd) if dd else len(xb):>4}')
