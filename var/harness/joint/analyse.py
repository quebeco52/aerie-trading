#!/usr/bin/env python3
"""analyse.py <seeds> [arm] [tpy]: joint sanity table from runs/<arm>-<seed>.*; prints per-seed columns and mean (sd)."""
import csv, json, math, statistics as st, sys, os, collections

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
arm = sys.argv[2] if len(sys.argv) > 2 else 'live'
tpy = int(sys.argv[3]) if len(sys.argv) > 3 else 360


def acf(x, k):
    m = sum(x) / len(x)
    v = sum((a - m) ** 2 for a in x)
    return sum((x[i] - m) * (x[i + k] - m) for i in range(len(x) - k)) / v if v > 0 else float('nan')


def sd(x):
    return st.pstdev(x) if len(x) > 1 else float('nan')


def med(x):
    return st.median(x) if x else float('nan')


import re
CFG = {t: float(v) for t, _, v in re.findall(r"'ticker' => '([A-Z]+)'(.*?)'volatility' => ([0-9.]+)", open(f'{H}/../../../src/Data/Company/InitialMarket.php').read(), re.S)}
rows = collections.OrderedDict()
notes = []


def put(name, seed, val):
    rows.setdefault(name, {})[seed] = val


for s in seeds:
    p = f"{H}/runs/{arm}-{s}"
    mac = [json.loads(l) for l in open(p + '.macro.jsonl')]
    g = [r['gap'] * 100 for r in mac]
    put('gap mean %', s, sum(g) / len(g)); put('gap sd %', s, sd(g)); put('gap ACF4', s, acf(g, 4))
    inf = [r['infl'] * 100 for r in mac]
    put('infl mean %', s, sum(inf) / len(inf)); put('infl sd %', s, sd(inf))
    pr = [r['policy'] * 100 for r in mac]
    put('policy mean %', s, sum(pr) / len(pr)); put('policy min %', s, min(pr)); put('policy max %', s, max(pr))
    y = [r['y10'] * 100 for r in mac]
    put('10y mean %', s, sum(y) / len(y)); put('10y min %', s, min(y)); put('10y max %', s, max(y))
    u = [r['u'] * 100 for r in mac]
    put('unemp mean %', s, sum(u) / len(u)); put('unemp max %', s, max(u))
    gq = [r['gq'] for r in mac]
    rec = [False] * len(gq)
    for i in range(1, len(gq)):
        if gq[i] < 0 and gq[i - 1] < 0:
            rec[i] = rec[i - 1] = True
    put('recession qtr share %', s, 100 * sum(rec) / len(rec))
    put('GDP q/q sd %', s, sd([x * 100 for x in gq]))
    put('debt/GDP start', s, mac[0]['debt']); put('debt/GDP end', s, mac[-1]['debt'])
    put('hh debt/inc end', s, mac[-1]['hh'])
    fx = [r['fx'] for r in mac]
    put('FX min', s, min(fx)); put('FX max', s, max(fx))
    put('fund/GDP end', s, mac[-1]['fund'])

    # --- board from tick prices ---
    with open(p + '.ticks.csv') as f:
        rd = csv.reader(f)
        hdr = next(rd)
        tick = [list(map(float, r)) for r in rd]
    names = [h[:-2] for h in hdr[1::2]]
    n = len(names)
    P = [[r[1 + 2 * j] for j in range(n)] for r in tick]
    N = [[r[2 + 2 * j] for j in range(n)] for r in tick]
    ew, cw = [], []
    for t in range(1, len(P)):
        rs, ws, wr = [], 0.0, 0.0
        for j in range(n):
            a, b = P[t - 1][j], P[t][j]
            if a > 0 and math.isfinite(a):
                # a split or reverse split (shares move >20% in a tick) is priced through the cap, not the price
                na, nb = N[t - 1][j], N[t][j]
                k = nb / na if na > 0 and abs(nb / na - 1) > 0.2 else 1.0
                r = (b * k / a - 1) if (b > 0 and math.isfinite(b)) else -1.0
                rs.append(r)
                w = a * N[t - 1][j]
                ws += w; wr += w * r
        ew.append(math.log1p(sum(rs) / len(rs)))
        cw.append(math.log1p(wr / ws))
    yrs = len(ew) / tpy
    for nm, ser in (('EW', ew), ('CW', cw)):
        put(f'{nm} index return %/y', s, 100 * (math.exp(sum(ser) / yrs) - 1))
        blk = [sum(ser[i:i + 21]) for i in range(0, len(ser) - 20, 21)]
        put(f'{nm} index vol 21t %', s, 100 * sd(blk) * math.sqrt(tpy / 21))
    # per-name realised 21-tick vol vs model current vol, and extreme moves
    out = json.loads(open(p + '.jsonl').readline())
    fin = out['final']
    exc, big, exk = [], [], []
    for j, nm in enumerate(names):
        px, f = [], 1.0
        for t in range(len(P)):
            if t and N[t - 1][j] > 0 and abs(N[t][j] / N[t - 1][j] - 1) > 0.2:
                f *= N[t][j] / N[t - 1][j]
            if P[t][j] > 0:
                px.append(P[t][j] * f)
        if len(px) < 2 * tpy:
            continue
        lr = [math.log(px[i + 21] / px[i]) for i in range(0, len(px) - 21, 21)]
        rv = sd(lr) * math.sqrt(tpy / 21)
        cv = [q['cvol'] for q in fin[nm]['q'] if q.get('cvol', 0) > 0]
        if nm in CFG:
            exk.append(rv - CFG[nm])
        if cv:
            exc.append(rv - sum(cv) / len(cv))
        mx, mn = max(px) / px[0], min(px) / px[0]
        if mx > 20 or mn < 0.02:
            big.append(f"{nm} max x{mx:.1f} min x{mn:.3f} end x{px[-1] / px[0]:.2f}")
    put('vol excess vs cvol 21t median', s, med(exc))
    put('vol excess vs cvol 21t mean', s, sum(exc) / len(exc))
    put('vol excess vs configured 21t median', s, med(exk))
    put('vol excess vs configured 21t mean', s, sum(exk) / len(exk))
    for b in big:
        notes.append(f"seed {s}: {b}")

    # --- reports ---
    def pe(q):
        return q['price'] / q['eps'] if q['eps'] > 0 else None
    first = [pe(v['q'][0]) for v in fin.values() if v['q']]
    last = [pe(v['q'][-1]) for v in fin.values() if v['q'] and not v['dead']]
    put('median P/E start', s, med([x for x in first if x]))
    put('median P/E end', s, med([x for x in last if x]))
    put('loss-making names end', s, sum(1 for x in last if x is None))
    nd = [v['q'][-1].get('ndE') for v in fin.values() if v['q'] and not v['dead'] and not v['q'][-1]['fin']]
    put('median ND/EBITDA end (non-fin)', s, med([x for x in nd if x is not None and math.isfinite(x)]))
    rat = collections.Counter(v['q'][-1]['rating'] for v in fin.values() if v['q'] and not v['dead'])
    bucket = collections.Counter()
    for r, c in rat.items():
        r = str(r)
        b = 'AAA/AA' if r.startswith('AA') else 'A' if r.startswith('A') else 'BBB' if r.startswith('BBB') else 'HY' if r[:1] in 'BC' else 'D/other'
        bucket[b] += c
    put('rating AAA-AA/A/BBB/HY/D', s, '/'.join(str(bucket[k]) for k in ('AAA/AA', 'A', 'BBB', 'HY', 'D/other')))
    byq = collections.defaultdict(list)
    for v in fin.values():
        for q in v['q']:
            if q['exp'] > 0:
                byq[round(q['t'] * 4)].append(q['rev'] > q['exp'])
    put('beat share %/qtr', s, 100 * sum(sum(x) / len(x) for x in byq.values()) / len(byq))
    put('failures', s, len(out['dead'])); put('Ch11 reorgs', s, len(out['reorgs']))
    put('names in payment default', s, len(out['defaults']))
    ev = [(t, e) for t, es in out['events'].items() for e in es]
    put('rescue equity raises', s, sum(1 for t, e in ev if 'stave off bankruptcy' in e['d'] and 'Issued new shares' in e['d']))
    for t, y_ in (out['dead'] or {}).items():
        notes.append(f"seed {s}: {t} liquidated year {y_}")
    for r in out['reorgs']:
        notes.append(f"seed {s}: {r['t']} Ch11 year {r['year']}")
    for l in open(p + '.anom.jsonl'):
        notes.append(f"seed {s}: anomaly {l.strip()}")
    for r in mac:
        if not (-2 <= r['policy'] * 100 <= 20) or not (-2 <= r['y10'] * 100 <= 20):
            notes.append(f"seed {s}: rate out of range t={r['t']} policy {r['policy']:.4f} y10 {r['y10']:.4f}")
            break

print('| quantity | ' + ' | '.join(f's{s}' for s in seeds) + ' | mean (sd) |')
print('|---' * (len(seeds) + 2) + '|')
for k, v in rows.items():
    vals = [v[s] for s in seeds]
    if isinstance(vals[0], str):
        print(f"| {k} | " + ' | '.join(vals) + ' | |')
        continue
    print(f"| {k} | " + ' | '.join(f"{x:.3g}" for x in vals) + f" | {sum(vals) / len(vals):.3g} ({st.stdev(vals) if len(vals) > 1 else 0:.2g}) |")
print()
for nline in notes:
    print('- ' + nline)
