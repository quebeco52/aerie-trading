"""compare.py <armA> <armB> <seeds>: paired per-seed effects (B - A) on the industry balance, y5 on."""
import json, math, sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'lib'))
from stats import paired, fmt
A, B, seeds = sys.argv[1], sys.argv[2], [int(s) for s in sys.argv[3].split(',')]

def metrics(arm, s):
    d = json.load(open(f'runs/{arm}-{s}.short.json'))
    o = json.loads(open(f'runs/{arm}-{s}.jsonl').readline())
    late = [r for r in d['rows'] if r[0] >= 5]
    ps = sorted(r[3] for r in late)
    auto = [r[3] for r in late if r[2] == 'Auto Manufacturers']
    G = [r[6] for r in late if r[6] is not None]
    lf = [r[8] - r[7] for r in late]
    q = o['final']['FALC']['q']
    yrs = q[-1]['t'] - q[3]['t']
    rv = lambda qs: sum(x['rev'] for x in qs)
    return {
        'auto firm P': sum(auto) / len(auto),
        'auto q share P>1.05': sum(p > 1.05 for p in auto) / len(auto),
        'auto q share P<0.95': sum(p < 0.95 for p in auto) / len(auto),
        'board P p5': ps[int(.05 * len(ps))], 'board P p95': ps[int(.95 * len(ps))],
        'board mean |log P|': sum(abs(math.log(p)) for p in ps) / len(ps),
        'board mean G': sum(G) / len(G), 'board mean m-f': sum(lf) / len(lf),
        'FALC rev %/yr': 100 * math.log(rv(q[-4:]) / rv(q[0:4])) / yrs,
        'deaths+reorgs': len(o['dead']) + len(o['reorgs']),
    }
ma = {s: metrics(A, s) for s in seeds}
mb = {s: metrics(B, s) for s in seeds}
print(f'{B} - {A}, seeds {seeds}')
for k in ma[seeds[0]]:
    a = [ma[s][k] for s in seeds]; b = [mb[s][k] for s in seeds]
    print(f'{k:22s} {A} {sum(a)/len(a):.4f}  {B} {sum(b)/len(b):.4f}  diff {fmt(paired(a, b))}')
