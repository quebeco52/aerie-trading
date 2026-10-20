#!/usr/bin/env python3
"""ins_margin.py <seeds> <arm>... [--models A,B]: per model, quarterly EBIT margin quantiles pooled over firm-seeds,
share of quarters below -10%, and the worst quarter (seed:ticker t margin)."""
import json, os, sys

H = os.path.dirname(os.path.abspath(__file__))
models = json.load(open(f"{H}/runs/ticker_models.json"))
want = ['Insurance', 'RetailInsurance', 'Reinsurance']


def seeds(s):
    out = []
    for p in s.split(','):
        a, _, b = p.partition('-')
        out += list(range(int(a), int(b or a) + 1))
    return out


def q(v, p):
    return v[min(len(v) - 1, int(p * len(v)))]


print(f"{'arm':6} {'model':28} {'n':>5} {'p1':>7} {'p10':>7} {'p50':>7} {'p90':>7} {'<-10%':>6}  worst")
for arm in sys.argv[2:]:
    recs = [json.loads(open(f"{H}/runs/{arm}-{s}.jsonl").read().splitlines()[-1]) for s in seeds(sys.argv[1])]
    for m in want:
        mg, worst = [], (9, '')
        for s, rec in zip(seeds(sys.argv[1]), recs):
            for t, v in rec['final'].items():
                if models.get(t, '').replace('BusinessModel', '') != m:
                    continue
                for r in v['q']:
                    if r['rev'] > 0:
                        x = r['ebit'] / r['rev']
                        mg.append(x)
                        if x < worst[0]:
                            worst = (x, f"{s}:{t} t={r['t']:.2f}")
        mg.sort()
        if not mg:
            print(f'{arm:6} {m:28} no firms'); continue
        print(f"{arm:6} {m.replace('BusinessModel',''):28} {len(mg):5d} " + ' '.join(f"{100*q(mg,p):7.1f}" for p in (.01, .1, .5, .9))
              + f" {100*sum(x < -0.1 for x in mg)/len(mg):6.1f}  {100*worst[0]:.0f}% {worst[1]}")
