#!/usr/bin/env python3
"""ins_bridge.py <seeds> <arm> [<arm> ...]: DOVE/SAFE capital against premium, payout and the equity bridge.
Unpaired, mean (se) over seeds. Premium = rev - ii (revenue less investment income, ILS revenue included).
- equity vs premium growth: 4q-window means (equity) and sums (premium), years 0-1 against 2-3 and against 19-20;
- payout (div + bb) / NI by 5-year block (block sums); erl and sleeve/equity at y0/5/10/20;
- trailing realised sleeve gains (tgain, fixD2c on) over equity at y5/10/20;
- bridge by 5-year block, over equity at block start: dEq, NI - payouts, residual, the sleeve mark
  sl_v[i-1] x (sl_pi[i]/sl_pi[i-1] - 1) x (1 - tax), issuance (dShares x price on non-split quarters, ratio < 1.2),
  and the rest (residual - mark - issuance); corr(rest_q / eq, d y10) per run as an AOCI check."""
import collections, json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
arms = sys.argv[2:]
NAMES = ['DOVE', 'SAFE']


def se(x):
    return st.stdev(x) / math.sqrt(len(x)) if len(x) > 1 else float('nan')


def ms(x, f):
    x = [v for v in x if v is not None and not (isinstance(v, float) and math.isnan(v))]
    return (f.format(st.mean(x)) + ' (' + f.format(se(x)) + ')') if x else 'n/a'


def at(q, y):
    return min(q, key=lambda r: abs(r['t'] - y))


def corr(a, b):
    if len(a) < 3 or st.pstdev(a) == 0 or st.pstdev(b) == 0:
        return float('nan')
    ma, mb = st.mean(a), st.mean(b)
    return sum((x - ma) * (y - mb) for x, y in zip(a, b)) / len(a) / st.pstdev(a) / st.pstdev(b)


R = {a: collections.defaultdict(lambda: collections.defaultdict(list)) for a in arms}
for arm in arms:
    for s in seeds:
        d = json.loads(open(f"{H}/runs/{arm}-{s}.jsonl").readline())
        mac = [json.loads(l) for l in open(f"{H}/runs/{arm}-{s}.macro.jsonl")]
        mt = [m['t'] for m in mac]

        def y10(t):
            i = min(range(len(mt)), key=lambda k: abs(mt[k] - t))
            return mac[i].get('y10')

        for t in NAMES:
            q = d['final'].get(t, {}).get('q', [])
            if len(q) < 60:
                continue
            P = R[arm][t]
            prem = [r['rev'] - r['ii'] for r in q]

            def win(lo, hi):
                ix = [i for i, r in enumerate(q) if lo <= r['t'] < hi]
                return st.mean(q[i]['eq'] for i in ix), sum(prem[i] for i in ix)
            e0, p0 = win(0, 1)
            e2, p2 = win(2, 3)
            e20, p20 = win(19, 20)
            P['eg2'].append(e2 / e0 - 1); P['pg2'].append(p2 / p0 - 1)
            P['eg20'].append((e20 / e0) ** (1 / 19) - 1); P['pg20'].append((p20 / p0) ** (1 / 19) - 1)
            for y in (0, 5, 10, 20):
                r = at(q, y)
                if r.get('erl') is not None:
                    P[f'erl{y}'].append(r['erl'])
                P[f'sl{y}'].append(r['sl_v'] / r['eq'])
                if r.get('tgain') is not None and y > 0:
                    P[f'tg{y}'].append(r['tgain'] / r['eq'])
            P['slm'].append(st.mean(r['sl_v'] / r['eq'] for r in q))
            rest_q, dy = [], []
            for b in range(4):
                ix = [i for i in range(1, len(q)) if 5 * b <= q[i]['t'] < 5 * b + 5]
                if not ix:
                    continue
                ni = sum(q[i]['ni'] for i in ix)
                pay = sum(q[i]['div'] + q[i]['bb'] for i in ix)
                P[f'po{b}'].append(pay / ni if ni > 0 else float('nan'))
                eb = q[ix[0] - 1]['eq']
                deq = q[ix[-1]]['eq'] - eb
                mark = iss = 0.0
                for i in ix:
                    a0, a1 = q[i - 1], q[i]
                    mk = a0['sl_v'] * (a1['sl_pi'] / a0['sl_pi'] - 1) * (1 - a0['tax']) if a0.get('sl_pi') and a1.get('sl_pi') else 0.0
                    ratio = a1['shares'] / a0['shares'] if a0['shares'] > 0 else 1.0
                    isq = (a1['shares'] - a0['shares']) * a1['price'] if 1.0 < ratio < 1.2 else 0.0
                    mark += mk; iss += isq
                    res_q = (a1['eq'] - a0['eq']) - (a1['ni'] - a1['div'] - a1['bb'])
                    v0, v1 = y10(a0['t']), y10(a1['t'])
                    if v0 is not None and v1 is not None and a0['eq'] > 0:
                        rest_q.append((res_q - mk - isq) / a0['eq']); dy.append(v1 - v0)
                res = deq - (ni - pay)
                P[f'de{b}'].append(deq / eb); P[f'fl{b}'].append((ni - pay) / eb); P[f'rs{b}'].append(res / eb)
                P[f'mk{b}'].append(mark / eb); P[f'is{b}'].append(iss / eb); P[f'rt{b}'].append((res - mark - iss) / eb)
            P['cy'].append(corr(rest_q, dy))
            ev = d['events'].get(t, [])
            P['nev_eq'].append(sum(1 for e in ev if 'share' in e['d'].lower() or 'equity' in e['d'].lower()))

print(f"seeds {sys.argv[1]}, arms {' | '.join(arms)} (unpaired, mean (se) over seeds)")
rows = [('eg2', 'equity growth y0-1->y2-3', '{:.3f}'), ('pg2', 'premium growth y0-1->y2-3', '{:.3f}'),
        ('eg20', 'equity CAGR y0-1->y19-20', '{:.4f}'), ('pg20', 'premium CAGR y0-1->y19-20', '{:.4f}')]
rows += [(f'po{b}', f'payout/NI y{5*b}-{5*b+5}', '{:.2f}') for b in range(4)]
rows += [(f'erl{y}', f'erl y{y}', '{:.3f}') for y in (0, 5, 10, 20)]
rows += [(f'sl{y}', f'sleeve/eq y{y}', '{:.3f}') for y in (0, 5, 10, 20)] + [('slm', 'sleeve/eq mean', '{:.3f}')]
rows += [(f'tg{y}', f'trail gains/eq y{y}', '{:.4f}') for y in (5, 10, 20)]
for b in range(4):
    lab = f'y{5*b}-{5*b+5}'
    rows += [(f'de{b}', f'{lab} dEq/eq0', '{:.3f}'), (f'fl{b}', f'{lab} NI-pay/eq0', '{:.3f}'),
             (f'rs{b}', f'{lab} residual', '{:.3f}'), (f'mk{b}', f'{lab}  sleeve mark', '{:.3f}'),
             (f'is{b}', f'{lab}  issuance', '{:.3f}'), (f'rt{b}', f'{lab}  rest', '{:.3f}')]
rows += [('cy', 'corr(rest_q, d y10)', '{:.2f}'), ('nev_eq', 'share/equity events', '{:.1f}')]
for t in NAMES:
    print(f"\n{t}")
    for k, lab, f in rows:
        v = [R[a][t][k] for a in arms]
        if not any(v):
            continue
        print(f"  {lab:27s} " + ' | '.join(f"{ms(x, f):>18s}" for x in v))
