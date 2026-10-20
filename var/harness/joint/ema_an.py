#!/usr/bin/env python3
"""ema_an.py <seeds> <arm>...: mean and sd of scp (supplyChainPressureIndexEma) and vol_ema (marketVolatilityEma)
over every quarterly MACRO_OUT row, pooled and as the seed sd of per-seed means."""
import json, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))


def seeds(s):
    out = []
    for p in s.split(','):
        a, _, b = p.partition('-')
        out += list(range(int(a), int(b or a) + 1))
    return out


print(f"{'arm':6} {'field':8} {'n':>5} {'mean':>9} {'sd':>9} {'seedSD(mean)':>13} {'min':>9} {'max':>9}")
for arm in sys.argv[2:]:
    for f in ('scp', 'vol_ema', 'vol'):
        allv, means = [], []
        for s in seeds(sys.argv[1]):
            p = f"{H}/runs/{arm}-{s}.macro.jsonl"
            v = [json.loads(l).get(f) for l in open(p)]
            v = [x for x in v if x is not None]
            if v:
                allv += v
                means.append(st.mean(v))
        if not allv:
            print(f"{arm:6} {f:8} absent")
            continue
        ssd = st.stdev(means) if len(means) > 1 else float('nan')
        print(f"{arm:6} {f:8} {len(allv):5d} {st.mean(allv):9.4f} {st.pstdev(allv):9.4f} {ssd:13.4f} {min(allv):9.4f} {max(allv):9.4f}")
