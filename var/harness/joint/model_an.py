"""model_an.py <seeds> <armA> <armB> [model,...]: per business model, revenue over the Street's expected revenue
(mean of rev/exp - 1 per quarter from year 2, the standing beat a term with a non-zero mean books) and revenue growth
(log, first to last four quarters, per year), averaged over the model's names and then over seeds, A against B with
the seed-paired difference's SE (seeds share a number, not a path). Needs runs/ticker_models.json (RUN.md)."""
import json, math, sys

seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
arm_a, arm_b = sys.argv[2], sys.argv[3]
models = json.load(open('var/harness/joint/runs/ticker_models.json'))
only = set(sys.argv[4].split(',')) if len(sys.argv) > 4 else None


def per_model(arm, seed):
    rec = json.loads(open(f'var/harness/joint/runs/{arm}-{seed}.jsonl').readline())
    out = {}
    for ticker, f in rec['final'].items():
        model = models.get(ticker, '?').replace('BusinessModel', '')
        if only and model not in only:
            continue
        q = [x for x in f['q'] if x['t'] >= 2.0 and x['exp'] > 0 and x['rev'] > 0]
        if len(q) < 8:
            continue
        beat = sum(x['rev'] / x['exp'] - 1.0 for x in q) / len(q)
        first, last = sum(x['rev'] for x in q[:4]), sum(x['rev'] for x in q[-4:])
        growth = math.log(last / first) / max(0.25, q[-1]['t'] - q[3]['t'])
        out.setdefault(model, []).append((beat, growth))
    return {m: (sum(b for b, _ in v) / len(v), sum(g for _, g in v) / len(v)) for m, v in out.items()}


rows = {}
for s in seeds:
    a, b = per_model(arm_a, s), per_model(arm_b, s)
    for m in set(a) & set(b):
        rows.setdefault(m, []).append((a[m], b[m]))


def stat(pairs, i):
    d = [pb[i] - pa[i] for pa, pb in pairs]
    n = len(d)
    mean = sum(d) / n
    se = (sum((x - mean) ** 2 for x in d) / (n - 1) / n) ** 0.5 if n > 1 else float('nan')
    return sum(pa[i] for pa, _ in pairs) / n, sum(pb[i] for _, pb in pairs) / n, mean, se


print(f'| model | n | beat % {arm_a} | beat % {arm_b} | Δ (t) | growth %/y {arm_a} | {arm_b} | Δ (t) |')
print('|---|---|---|---|---|---|---|---|')
for m in sorted(rows):
    p = rows[m]
    ba, bb, bd, bse = stat(p, 0)
    ga, gb, gd, gse = stat(p, 1)
    t = lambda d, se: f'{d * 100:+.2f} ({d / se:+.1f})' if se and se > 0 else f'{d * 100:+.2f}'
    print(f'| {m} | {len(p)} | {ba * 100:+.2f} | {bb * 100:+.2f} | {t(bd, bse)} | {ga * 100:+.2f} | {gb * 100:+.2f} | {t(gd, gse)} |')
