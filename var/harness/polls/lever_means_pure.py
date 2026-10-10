# Pure-Python lever_means.py (no numpy on this machine): python3 lever_means_pure.py runs_x/levers_*.jsonl
# G0 is the founding purchases share the spending measure is struck on: 0.15 now; G0=0.21 for runs before runs_size15.
# Pure-Python port of var/harness/polls/lever_means.py (same mean, 16-quarter persistence and seed spreads).
import json, sys, math, statistics as st
TFP_LOSS, SEMI, DUTY0, G0 = 0.048, 52.68, 0.0005, float(__import__("os").environ.get("G0", "0.15"))
M = {'corporateTax': lambda x: x, 'bankLevyRate': lambda x: x,
     'extractionStringency': lambda s: 1.0 / max(0.01, 1.0 - TFP_LOSS * s),
     'stampDutyRate': lambda t: math.exp(-SEMI * 2.0 * (t - DUTY0)),
     'publicSpending': lambda g: g / G0 - 1.0, 'dividendTax': lambda x: x}
rows = [json.loads(l) for f in sys.argv[1:] for l in open(f)]
rows = {r['seed']: r for r in rows}; rows = [rows[s] for s in sorted(rows)]
for lever, f in M.items():
    xs = [[f(v) for v in r[lever]] for r in rows]
    allx = [v for x in xs for v in x]; n = len(allx); mean = sum(allx) / n
    num = sum((x[i] - mean) * (x[i - 16] - mean) for x in xs for i in range(16, len(x)))
    den = sum((v - mean) ** 2 for v in allx); pairs = sum(len(x) - 16 for x in xs)
    rho = (num / pairs) / (den / n)
    means = [sum(x) / len(x) for x in xs]; rhos = []
    for x in xs:
        m = sum(x) / len(x); d = sum((v - m) ** 2 for v in x) / len(x)
        if d > 0: rhos.append(sum((x[i] - m) * (x[i - 16] - m) for i in range(16, len(x))) / (len(x) - 16) / d)
    sd = math.sqrt(den / n)
    print(f"{lever}: seeds {len(xs)}, quarters {n}, mean {mean:.6f} (se {st.pstdev(means) / math.sqrt(len(means)):.6f}), sd {sd:.6f}, term persistence {rho:.3f} (sd across seeds {st.pstdev(rhos):.2f})")
