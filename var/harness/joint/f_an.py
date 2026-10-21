#!/usr/bin/env python3
"""f_an.py <seeds> <armA> <armB>: batch F (payout odds, dividend hold only when covered, HF bonus, IB gamma friction).
A "Borrowed $xB to fund its dividend" events and $B by firm (top 15) per arm.
B payout firms: div/NI year 1 and over 20y, equity $B y0/5/10/20, ND/EBITDA y1/y20, end ratings, defaults, quarters
  with a dividend cut >10% q/q, dividend $B/q mean in year 1 vs year 20.
C board: dividend payout (sum div / sum positive NI) and cap-weighted dividend yield (4 x div / cap, mean of quarters).
D SWAN/PERE EBIT margin, TTM ROE from y2 (as e_an.py), EPS beat share (as beat_an.py), lore baseline_roe.
Failures, Ch11, payment defaults, rescues, index return come from analyse.py; board beat by model from beat_an.py.
Values are mean [min..max] over seeds."""
import collections, json, math, os, re, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
arms = sys.argv[2:]
FIRMS = ['WADE', 'PLZA', 'KITE', 'SHOR', 'ELDE', 'CLAW', 'PHIL', 'SHRK', 'LARK', 'SWFT', 'BIRD']
SRC = open(f'{H}/../../../src/Data/Company/InitialMarket.php').read()


def lore_roe(tk):
    i = SRC.index(f"'ticker' => '{tk}'"); j = SRC.find("'ticker' =>", i + 10)
    m = re.search(r"'baseline_roe' => ([0-9.]+)", SRC[i:j])
    return float(m.group(1)) if m else float('nan')


def rng(xs, f='{:.2f}'):
    xs = [x for x in xs if x is not None and not (isinstance(x, float) and math.isnan(x))]
    if not xs:
        return 'n/a'
    return (f + ' [' + f + '..' + f + ']').format(st.mean(xs), min(xs), max(xs))


def ttm_roe(q):
    out = []
    for i in range(7, len(q)):
        w = q[i - 3:i + 1]; me = st.mean(x['eq'] for x in w)
        if me > 0:
            out.append(sum(x['ni'] for x in w) / me)
    return st.mean(out) if out else float('nan')


def at(q, y):
    """Row nearest to year y (y0 = first row)."""
    return min(q, key=lambda x: abs(x['t'] - y)) if y else q[0]


def ratio(n, d):
    return n / d if d > 0 else float('nan')


R = {}
for arm in arms:
    borrow = collections.defaultdict(lambda: [0, 0.0])
    F = collections.defaultdict(lambda: collections.defaultdict(list))
    board = collections.defaultdict(list); sw = collections.defaultdict(list)
    for s in seeds:
        r = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').readline())
        fin = r['final']; dft = r.get('defaults') or {}
        for tk, es in r['events'].items():
            for e in es:
                m = re.match(r'Borrowed \$([\d.]+)B to fund its dividend', e['d'])
                if m:
                    borrow[tk][0] += 1; borrow[tk][1] += float(m.group(1))
        for tk in FIRMS:
            q = fin[tk]['q']; y1 = [x for x in q if x['t'] < 1]; y20 = [x for x in q if x['t'] >= 19]
            d = F[tk]
            d['p1'].append(ratio(sum(x['div'] for x in y1), sum(x['ni'] for x in y1)))
            d['p20'].append(ratio(sum(x['div'] for x in q), sum(x['ni'] for x in q)))
            for y in (0, 5, 10, 20):
                d[f'eq{y}'].append(at(q, y)['eq'] / 1e9)
            d['nd1'].append(at(q, 1)['ndE']); d['nd20'].append(q[-1]['ndE'])
            d['end'].append(q[-1]['rating']); d['dft'].append(tk in dft or tk in (r['dead'] or {}))
            d['cuts'].append(sum(1 for a, b in zip(q, q[1:]) if a['div'] > 0 and b['div'] < 0.9 * a['div']))
            d['dv1'].append(st.mean(x['div'] for x in y1) / 1e9); d['dv20'].append(st.mean(x['div'] for x in y20) / 1e9)
            d['eqchg'].append(q[-1]['eq'] / q[0]['eq'] - 1 if q[0]['eq'] > 0 else float('nan'))
        dv = sum(x['div'] for v in fin.values() for x in v['q'])
        pni = sum(max(0, x['ni']) for v in fin.values() for x in v['q'])
        board['payout'].append(dv / pni)
        byq = collections.defaultdict(lambda: [0.0, 0.0])
        for v in fin.values():
            for x in v['q']:
                cap = x['price'] * x['shares']
                if cap > 0:
                    k = round(x['t'] * 4); byq[k][0] += 4 * x['div']; byq[k][1] += cap
        board['yield'].append(st.mean(a / b for a, b in byq.values() if b > 0))
        board['eqfall'].append([tk for tk, v in fin.items() if v['q'] and v['q'][0]['eq'] > 0
                                and v['q'][-1]['eq'] < 0.6 * v['q'][0]['eq']])
        for tk in ('SWAN', 'PERE'):
            q = fin[tk]['q']
            sw[tk + ' om'].append(st.mean(x['ebit'] / x['rev'] for x in q if x['rev'] > 0))
            sw[tk + ' roe'].append(ttm_roe(q))
            qb = [x for x in q if x['t'] > 1.0 and x.get('rev', 0) > 0 and 'reps' in x and x['ceps'] != 0]
            sw[tk + ' beat'].append(sum(x['reps'] > x['ceps'] for x in qb) / len(qb) if qb else float('nan'))
    R[arm] = (borrow, F, board, sw)

print(f'seeds {seeds[0]}-{seeds[-1]}, arms {" vs ".join(arms)}; mean [min..max] over seeds')
print('\nA. "Borrowed $xB to fund its dividend": events / $B, all seeds summed')
tot = collections.Counter()
for arm in arms:
    for tk, (n, b) in R[arm][0].items():
        tot[tk] += b
print(f'{"firm":6s}' + ''.join(f'{a:>18s}' for a in arms))
print(f'{"ALL":6s}' + ''.join(f'{sum(v[0] for v in R[a][0].values()):>8d} {sum(v[1] for v in R[a][0].values()):8.1f}B' for a in arms))
for tk, _ in tot.most_common(15):
    print(f'{tk:6s}' + ''.join(f'{R[a][0][tk][0]:>8d} {R[a][0][tk][1]:8.1f}B' if tk in R[a][0] else f'{"0":>8s} {"0.0":>8s}B' for a in arms))

print('\nB. payout firms')
for tk in FIRMS:
    for arm in arms:
        d = R[arm][1][tk]
        print(f'{tk:5s} {arm:6s} div/NI y1 {rng(d["p1"])} 20y {rng(d["p20"])} | eq$B y0 {st.mean(d["eq0"]):.1f} y5 {st.mean(d["eq5"]):.1f}'
              f' y10 {st.mean(d["eq10"]):.1f} y20 {rng(d["eq20"], "{:.1f}")} | ndE y1 {rng(d["nd1"])} y20 {rng(d["nd20"])}')
        print(f'{"":12s} end {dict(collections.Counter(d["end"]).most_common())} defaults {sum(d["dft"])} '
              f'cuts>10%/run {rng(d["cuts"], "{:.1f}")} div$B/q y1 {rng(d["dv1"], "{:.3f}")} y20 {rng(d["dv20"], "{:.3f}")}'
              f' eq chg {rng([100 * x for x in d["eqchg"]], "{:+.0f}")}%')

print('\nC. board dividends')
for arm in arms:
    b = R[arm][2]
    print(f'{arm:6s} payout sum div/sum positive NI {rng([100 * x for x in b["payout"]], "{:.1f}")}%  '
          f'CW dividend yield {rng([100 * x for x in b["yield"]], "{:.2f}")}%')
    fall = collections.Counter(t for l in b['eqfall'] for t in l)
    print(f'{"":6s} firms with equity down >40% over 20y (seeds): {fall.most_common(12)}')

print('\nD. SWAN (lore ROE {:.0%}) and PERE (lore ROE {:.0%})'.format(lore_roe('SWAN'), lore_roe('PERE')))
for tk in ('SWAN', 'PERE'):
    for arm in arms:
        sw = R[arm][3]
        print(f'{tk:5s} {arm:6s} EBIT margin {rng([100 * x for x in sw[tk + " om"]], "{:.1f}")}%  TTM ROE y2+ '
              f'{rng([100 * x for x in sw[tk + " roe"]], "{:.1f}")}%  EPS beat {rng([100 * x for x in sw[tk + " beat"]], "{:.0f}")}%')
