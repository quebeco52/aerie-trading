#!/usr/bin/env python3
"""m_an.py <arm:seeds> ...: batch M (fixL with SWAN fund leverage 1.80). Per arm: board market-cap CAGR ex SWAN/POOL,
macro vol and erp means, SWAN blow-up / margin-call quarters; diffs of each arm against the first (unpaired, own seed
sets). Then pooled per-seed correlations (SWAN blow-ups vs mean vol, mean vol vs board CAGR) and the event-time
macro vol change k=1..4 quarters after a SWAN blow-up quarter against all other quarters (same runs)."""
import collections, json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))


def seedlist(s):
    out = []
    for part in s.split(','):
        a, _, b = part.partition('-')
        out += list(range(int(a), int(b or a) + 1))
    return out


def board_cagr(fin):
    c0 = c1 = 0.0; t0 = []; t1 = []
    for tk, v in fin.items():
        q = v['q']
        if tk in ('SWAN', 'POOL') or len(q) < 70:
            continue
        c0 += q[0]['price'] * q[0]['shares']; c1 += q[-1]['price'] * q[-1]['shares']
        t0.append(q[0]['t']); t1.append(q[-1]['t'])
    return (c1 / c0) ** (1 / (st.mean(t1) - st.mean(t0))) - 1


def corr(x, y):
    mx, my = st.mean(x), st.mean(y)
    sxy = sum((a - mx) * (b - my) for a, b in zip(x, y))
    return sxy / math.sqrt(sum((a - mx) ** 2 for a in x) * sum((b - my) ** 2 for b in y))


arms = []
for spec in sys.argv[1:]:
    arm, s = spec.split(':'); rows = []
    for seed in seedlist(s):
        r = json.loads(open(f'{H}/runs/{arm}-{seed}.jsonl').readline())
        macro = sorted((json.loads(l) for l in open(f'{H}/runs/{arm}-{seed}.macro.jsonl') if l.strip()),
                       key=lambda m: m['t'])
        q = r['final']['SWAN']['q']; ev = collections.Counter(x['evt'] for x in q)
        bt = [x['t'] for x in q if x['evt'] == 'Hf Directional Blowup']
        vols = [m['vol'] for m in macro]
        # event time: macro row index nearest each blow-up quarter's t
        idx = [min(range(len(macro)), key=lambda i: abs(macro[i]['t'] - t)) for t in bt]
        rows.append(dict(seed=seed, mcap=board_cagr(r['final']), vol=st.mean(vols),
                         erp=st.mean(m['erp'] for m in macro), blow=ev.get('Hf Directional Blowup', 0),
                         mc=ev.get('Hf Margin Call', 0), vols=vols, idx=set(idx)))
    arms.append((arm, s, rows))


def stat(v):
    return st.mean(v), (st.stdev(v) if len(v) > 1 else float('nan'))


print('| quantity | ' + ' | '.join(f'{a} {s} mean (sd)' for a, s, _ in arms) + ' | '
      + ' | '.join(f'{a} - {arms[0][0]} (se) t' for a, _, _ in arms[1:]) + ' |')
print('|---' * (1 + 2 * len(arms) - 1) + '|')
for lab, k, m in (('board mkt-cap CAGR ex SWAN/POOL %', 'mcap', 100), ('macro vol mean %', 'vol', 100),
                  ('macro erp mean %', 'erp', 100), ('SWAN blow-up quarters/run', 'blow', 1),
                  ('SWAN margin-call quarters/run', 'mc', 1)):
    cells, diffs = [], []
    a0 = [m * x[k] for x in arms[0][2]]
    for _, _, rows in arms:
        v = [m * x[k] for x in rows]; mu, sd = stat(v); cells.append(f'{mu:.3g} ({sd:.2g})')
    for _, _, rows in arms[1:]:
        v = [m * x[k] for x in rows]
        d = st.mean(v) - st.mean(a0); se = math.sqrt(st.variance(v) / len(v) + st.variance(a0) / len(a0))
        diffs.append(f'{d:+.3g} ({se:.2g}) {d / se if se else float("nan"):+.1f}')
    print(f'| {lab} | ' + ' | '.join(cells) + ' | ' + ' | '.join(diffs) + ' |')

pool = [x for _, _, rows in arms for x in rows]
print(f'\nPooled per-seed (n={len(pool)}): corr(SWAN blow-ups, mean vol) {corr([x["blow"] for x in pool], [x["vol"] for x in pool]):+.2f}; '
      f'corr(mean vol, board CAGR) {corr([x["vol"] for x in pool], [x["mcap"] for x in pool]):+.2f}; '
      f'corr(blow-ups, board CAGR) {corr([x["blow"] for x in pool], [x["mcap"] for x in pool]):+.2f}')
print('\nEvent time, pooled: mean macro vol change vol[i+k]-vol[i] (pp), blow-up quarter i vs all other quarters')
print('| k | after blow-up (se) | other quarters (se) | diff | t | n events |\n|---|---|---|---|---|---|')
for k in (0, 1, 2, 3, 4):
    ev, ot = [], []
    for x in pool:
        v = x['vols']
        for i in range(1, len(v) - k):
            d = 100 * (v[i + k] - v[i - 1])
            (ev if i in x['idx'] else ot).append(d)
    if len(ev) > 1:
        se = math.sqrt(st.variance(ev) / len(ev) + st.variance(ot) / len(ot)); dd = st.mean(ev) - st.mean(ot)
        print(f'| {k} | {st.mean(ev):+.2f} ({st.stdev(ev) / math.sqrt(len(ev)):.2f}) | {st.mean(ot):+.2f} '
              f'({st.stdev(ot) / math.sqrt(len(ot)):.2f}) | {dd:+.2f} | {dd / se:+.1f} | {len(ev)} |')
print('(change measured from the quarter before the blow-up, i-1, to i+k)')
