"""compare.py <armA> <armB> <seeds>: paired per-seed differences B - A, years 5+.
Board: mean firm log(trend plant/plant) G, mean |log firm price level|; REITs and CASC: G, PP&E growth %/yr, debt raised
%/yr of PP&E, treasury-built %/yr of PP&E."""
import json, math, sys, statistics as st
a, b, seeds = sys.argv[1], sys.argv[2], [int(s) for s in sys.argv[3].split(',')]
T = ['ELDE', 'SHOR', 'PLZA', 'KITE', 'CASC', 'IBHI']
def load(arm, s):
    rows = json.load(open(f'runs/{arm}-{s}.short.json'))['rows']
    late = [r for r in rows if r[0] >= 5 and r[12] and r[11]]
    out = {'G': st.mean(math.log(r[12] / r[11]) for r in late), 'absP': st.mean(abs(math.log(r[3])) for r in late)}
    pg = [json.loads(l) for l in open(f'runs/{arm}-{s}.pg.jsonl')]
    for tk in T:
        rs = [r for r in pg if r['tk'] == tk and r['t'] >= 5 and r['ppe'] > 0]
        fr = [r for r in late if r[1] == tk]
        if len(rs) < 8 or not fr:
            continue
        P = sum(r['ppe'] for r in rs)
        out[tk + ' G'] = st.mean(math.log(r[12] / r[11]) for r in fr)
        out[tk + ' ppe%'] = 100 * math.log(rs[-1]['ppe'] / rs[0]['ppe']) / (rs[-1]['t'] - rs[0]['t'])
        out[tk + ' debt%'] = 400 * sum(r['debt'] for r in rs) / P
        out[tk + ' trs%'] = 400 * sum(r['org'] for r in rs) / P
        out[tk + ' P'] = st.mean(r[3] for r in fr)
    return out
A = {s: load(a, s) for s in seeds}; B = {s: load(b, s) for s in seeds}
print(f'{"quantity":14s} | {a:>8s} | diff (se) t, n={len(seeds)}')
for k in A[seeds[0]]:
    d = [B[s][k] - A[s][k] for s in seeds if k in A[s] and k in B[s]]
    if len(d) < 2:
        continue
    m = st.mean(d); se = st.stdev(d) / math.sqrt(len(d))
    print(f'{k:14s} | {st.mean(A[s][k] for s in seeds):8.4f} | {m:+.4f} ({se:.4f}) {m / se if se > 0 else float("nan"):+.1f}')
