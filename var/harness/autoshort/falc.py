"""falc.py <arm> <seeds>: FALC/EIDR revenue growth, margin, leverage, valuation from the OUT records."""
import json, math, sys
arm, seeds = sys.argv[1], [int(s) for s in sys.argv[2].split(',')]
for s in seeds:
    d = json.loads(open(f'runs/{arm}-{s}.jsonl').readline())
    print(s, 'dead', d['dead'], 'reorgs', str(d['reorgs'])[:80], 'defaults', str(d['defaults'])[:80])
    for tk in ('FALC', 'EIDR'):
        q = d['final'][tk]['q']
        a, b = q[0:4], q[-4:]
        ra, rb = sum(r['rev'] for r in a), sum(r['rev'] for r in b)
        yrs = b[-1]['t'] - a[-1]['t']
        mc = lambda r: r['price'] * r['shares']
        pe = [r['price'] / r['eps'] for r in q[20:] if r['eps'] > 0]
        print(f"  {tk} rev {100*math.log(rb/ra)/yrs:+.2f}%/yr | om {sum(r['ebit'] for r in a)/ra:.3f}->{sum(r['ebit'] for r in b)/rb:.3f} | ND/EBITDA end {(b[-1]['debt']-b[-1]['cash'])/max(1,sum(r['ebitda'] for r in b)):.2f} | capex/rev {sum(r['capex'] for r in q[20:])/sum(r['rev'] for r in q[20:]):.3f} | med P/E y5+ {sorted(pe)[len(pe)//2] if pe else float('nan'):.1f} | mcap x{mc(b[-1])/mc(a[0]):.2f} | gw/ta {b[-1]['gw']/b[-1]['ta']:.2f} | {b[-1]['rating']}")
