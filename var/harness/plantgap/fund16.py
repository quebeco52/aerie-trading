"""fund16.py <armA> <armB> <seeds>: paired B - A for the fundPlannedPlant check, years 5+ (runs/<arm>-<s>.*).
Prints events (deaths, reorgs, defaults), REIT, board, CASC/IBHI rows and planned-plant equity issues by firm."""
import json, math, sys, statistics as st
from collections import defaultdict
A_, B_, seeds = sys.argv[1], sys.argv[2], [int(s) for s in sys.argv[3].split(',')]
T0 = 5.0


def split_adjusted(q):
    """Quarter rows -> list of (t, price, dps, shares, C) with C = new shares per original share (splits only)."""
    out, C, prev = [], 1.0, None
    for r in q:
        if prev is not None and prev['shares'] > 0 and r['shares'] > 0:
            x = r['shares'] / prev['shares']
            k = round(x) if x >= 1 else 1 / max(1, round(1 / x))
            if k != 1 and abs(x / k - 1) < 0.03:
                C *= k
        out.append((r['t'], r['price'], r['div'] / r['shares'] if r['shares'] > 0 else 0.0, r['shares'], C, r))
        prev = r
    return out


def load(arm, s):
    rec = json.loads(open(f'runs/{arm}-{s}.out.jsonl').readline())
    for k in ('dead', 'defaults', 'final'):
        rec[k] = rec[k] or {}
    rows = json.load(open(f'runs/{arm}-{s}.short.json'))['rows']
    pg = [json.loads(l) for l in open(f'runs/{arm}-{s}.pg.jsonl')]
    late = [r for r in rows if r[0] >= T0 and r[12] and r[11]]
    nonfin = {r['tk'] for r in pg}
    reits = sorted({r['tk'] for r in pg if r['ind'].startswith('REIT -')})
    o = {}
    ev = [('dead', t, y) for t, y in rec['dead'].items()] + [('reorg', x['t'], x['year']) for x in rec['reorgs']] \
        + [('default', t, n) for t, n in rec['defaults'].items()]
    o['n deaths'] = len(rec['dead']); o['n reorgs'] = len(rec['reorgs']); o['n default tk'] = len(rec['defaults'])
    # firm metrics
    F = {}
    for tk, f in rec['final'].items():
        q = [r for r in f['q'] if r['t'] >= T0]
        if len(q) < 12:
            continue
        a = split_adjusted(q)
        yrs = a[-1][0] - a[0][0]
        m = {}
        m['shares%'] = 100 * math.log(a[-1][3] / a[-1][4] / a[0][3]) / yrs
        d0 = sum(x[2] * x[4] for x in a[:4]); d1 = sum(x[2] * x[4] for x in a[-4:])
        if d0 > 0 and d1 > 0:
            m['dps%'] = 100 * math.log(d1 / d0) / yrs
        lr = 0.0
        for p, c in zip(a, a[1:]):
            k = c[4] / p[4]
            lr += math.log(k * (c[1] + c[2]) / p[1])
        m['tsr%'] = 100 * lr / yrs
        m['lev'] = st.mean(r['debt'] / (r['debt'] + r['eq']) for r in q if r['debt'] + r['eq'] > 0)
        F[tk] = m
    for tk in nonfin:
        rs = [r for r in pg if r['tk'] == tk and r['t'] >= T0 and r['ppe'] > 0]
        fr = [r for r in late if r[1] == tk]
        if len(rs) < 8 or not fr:
            continue
        F.setdefault(tk, {})
        F[tk]['G'] = st.mean(math.log(r[12] / r[11]) for r in fr)
        F[tk]['ppe%'] = 100 * math.log(rs[-1]['ppe'] / rs[0]['ppe']) / (rs[-1]['t'] - rs[0]['t'])
    for tk in ['ELDE', 'SHOR', 'PLZA', 'KITE', 'CASC', 'IBHI']:
        for k in ['G', 'ppe%', 'shares%', 'dps%', 'tsr%', 'lev']:
            if tk in F and k in F[tk] and (tk in reits or k in ('G', 'ppe%')):
                o[f'{tk} {k}'] = F[tk][k]
    for k in ['G', 'ppe%', 'shares%', 'dps%', 'tsr%', 'lev']:
        v = [F[t][k] for t in reits if t in F and k in F[t]]
        if v:
            o[f'REIT mean {k}'] = st.mean(v)
    # board
    o['board G (ind sw)'] = st.mean(r[6] for r in rows if r[0] >= T0 and r[6] is not None)
    lp = sorted(math.log(r[3]) for r in late)
    o['mean|logP|'] = st.mean(abs(x) for x in lp)
    o['logP p5'] = lp[int(0.05 * len(lp))]; o['logP p95'] = lp[int(0.95 * len(lp))]
    byq = defaultdict(list)
    for tk, f in rec['final'].items():
        if tk in nonfin:
            for r in f['q']:
                if r['t'] >= T0:
                    byq[round(r['t'] * 4)].append(r)
    ks = sorted(byq)
    o['board lev'] = st.mean(sum(r['debt'] for r in byq[k]) / sum(r['debt'] + r['eq'] for r in byq[k]) for k in ks)
    o['median P/E'] = st.mean(st.median([r['price'] / r['eps'] for r in byq[k] if r['eps'] > 0]) for k in ks)
    pp = {k: sum(r['ppe'] for r in byq[k]) for k in ks}
    o['board PPE%'] = 100 * math.log(pp[ks[-1]] / pp[ks[0]]) / ((ks[-1] - ks[0]) / 4)
    pl = [r for r in pg if r['t'] >= T0 and not r.get('seg') and r.get('plan') is not None]
    o['plan built %'] = 100 * sum((r['grow'] or 0) + (r['org'] or 0) for r in pl) / sum(r['plan'] or 0 for r in pl)
    o['plan internal %'] = 100 * sum(r['grow'] or 0 for r in pl) / sum(r['plan'] or 0 for r in pl)
    # planned-plant equity issues (eqr > 0 at organic capex) and other share jumps (> 2%, not a split)
    iss = defaultdict(int)
    for r in pg:
        if (r.get('eqr') or 0) > 0:
            iss[r['tk']] += 1
    jumps = defaultdict(int)
    for tk, f in rec['final'].items():
        for p, c in zip(f['q'], f['q'][1:]):
            if p['shares'] > 0:
                x = c['shares'] / p['shares']
                k = round(x) if x >= 1 else 0
                if x > 1.02 and not (k >= 2 and abs(x / k - 1) < 0.03):
                    jumps[tk] += 1
    o['n plant equity issues'] = sum(iss.values())
    o['n share jumps>2%'] = sum(jumps.values())
    return o, ev, iss, jumps, reits


D = {a: {s: load(a, s) for s in seeds} for a in (A_, B_)}
print(f'n = {len(seeds)} seeds, years {T0:.0f}+; diff = {B_} - {A_}')
print(f'{"quantity":22s} | {A_:>9s} | {B_:>9s} | diff (se) t')
keys = list(dict.fromkeys(k for s in seeds for a in (A_, B_) for k in D[a][s][0]))
for k in keys:
    pr = [(D[A_][s][0][k], D[B_][s][0][k]) for s in seeds if k in D[A_][s][0] and k in D[B_][s][0]]
    if len(pr) < 2:
        continue
    d = [b - a for a, b in pr]
    m = st.mean(d); se = st.stdev(d) / math.sqrt(len(d))
    print(f'{k:22s} | {st.mean(a for a, _ in pr):9.4f} | {st.mean(b for _, b in pr):9.4f} | {m:+.4f} ({se:.4f}) {m / se if se > 0 else float("nan"):+.1f}  n={len(d)}')
print('REITs:', ','.join(D[B_][seeds[0]][4]))
for a in (A_, B_):
    print(f'events {a}:', '; '.join(f'{e[0]} {e[1]} s{s} y{e[2]}' for s in seeds for e in D[a][s][1]) or 'none')
    tot = defaultdict(int); jt = defaultdict(int)
    for s in seeds:
        for t, n in D[a][s][2].items():
            tot[t] += n
        for t, n in D[a][s][3].items():
            jt[t] += n
    print(f'plant equity issues {a} (per run):', ', '.join(f'{t} {n / len(seeds):.2f}' for t, n in sorted(tot.items(), key=lambda x: -x[1])) or 'none')
    print(f'share jumps>2% {a} (per run):', ', '.join(f'{t} {n / len(seeds):.2f}' for t, n in sorted(jt.items(), key=lambda x: -x[1])[:12]) or 'none')
