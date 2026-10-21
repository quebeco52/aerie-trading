#!/usr/bin/env python3
"""h_an.py <seeds> <armA> <armB>: batch H (utility own-failure threshold and per-firm exposure, utilities off the
Altman zones, REIT solvency ratio on undepreciated assets).
A utilities BIRD, WADE, DIPR (control): 'Grid Failure Wildfire' quarters per firm-year, rating share of quarters, end
  ratings, ND/EBITDA mean, penalty-rate borrowing count/$B, defaults, EBIT margin mean and sd, revenue CAGR.
B REITs PLZA, KITE, SHOR, ELDE: rating share, end ratings, dead/reorg/default seeds, equity $B y0/y20, ND/EBITDA y20,
  div/NI over 20y, borrowed-for-dividend events.
Values are mean [min..max] over seeds."""
import collections, json, math, os, re, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
arms = sys.argv[2:]
ORDER = ['AAA', 'AA', 'A', 'BBB', 'BB', 'B', 'CCC', 'CC', 'C', 'D']
UT = ['BIRD', 'WADE', 'DIPR']
RE = ['PLZA', 'KITE', 'SHOR', 'ELDE']


def rng(xs, f='{:.2f}'):
    xs = [x for x in xs if x is not None and not (isinstance(x, float) and math.isnan(x))]
    if not xs:
        return 'n/a'
    return (f + ' [' + f + '..' + f + ']').format(st.mean(xs), min(xs), max(xs))


def cagr(q):
    if len(q) < 16:
        return None
    a = sum(x['rev'] for x in q[:4]); b = sum(x['rev'] for x in q[-4:]); yrs = q[-2]['t'] - q[1]['t']
    return (b / a) ** (1 / yrs) - 1 if a > 0 and b > 0 and yrs > 0 else None


def bil(d):
    m = re.search(r'\$([0-9.]+)B', d)
    return float(m.group(1)) if m else 0.0


def mentions(x, tk):
    return tk in json.dumps(x)


D = {arm: collections.defaultdict(lambda: collections.defaultdict(list)) for arm in arms}
for arm in arms:
    for s in seeds:
        r = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').readline())
        fin, evs = r['final'], r.get('events') or {}
        for tk in UT + RE:
            v = fin[tk]; q = v['q']; d = D[arm][tk]
            rs = [x.get('rating') for x in q if x.get('rating') in ORDER]
            d['ratings'].extend(rs)
            d['end'].append(q[-1].get('rating') if not v['dead'] else 'dead')
            nd = [x['ndE'] for x in q if x.get('ndE') is not None]
            d['ndE'].append(st.mean(nd) if nd else None)
            d['ndE20'].append(q[-1].get('ndE'))
            flags = []
            if v['dead'] or mentions(r['dead'], tk):
                flags.append('dead')
            if mentions(r['reorgs'], tk):
                flags.append('reorg')
            if mentions(r['defaults'], tk):
                flags.append('default')
            if flags:
                d['flags'].append(f's{s}:' + '+'.join(flags))
            el = evs.get(tk, [])
            pen = [e for e in el if 'Forced to borrow' in e['d']]
            d['pen_n'].append(len(pen)); d['pen_b'].append(sum(bil(e['d']) for e in pen))
            dv = [e for e in el if re.match(r'Borrowed \$[\d.]+B to fund its dividend', e['d'])]
            d['divb_n'].append(len(dv)); d['divb_b'].append(sum(bil(e['d']) for e in dv))
            yrs = max(q[-1]['t'] - q[0]['t'], 1e-9)
            d['gf'].append(sum(1 for x in q if x.get('evt') and 'Grid Failure Wildfire' in str(x['evt'])) / yrs)
            om = [x['ebit'] / x['rev'] for x in q if x['rev'] > 0]
            d['om'].append(st.mean(om)); d['omsd'].append(st.pstdev(om))
            d['cagr'].append(cagr(q))
            d['eq0'].append(q[0]['eq'] / 1e9); d['eq20'].append(q[-1]['eq'] / 1e9)
            pos = sum(x['ni'] for x in q)
            d['divni'].append(sum(x['div'] for x in q) / pos if pos > 0 else None)


def ratshare(rs):
    c = collections.Counter(rs); n = max(len(rs), 1)
    return ' '.join(f'{k} {100 * c[k] / n:.0f}' for k in ORDER if c[k])


def ends(xs):
    c = collections.Counter(xs)
    return ' '.join(f'{k}x{c[k]}' for k in ORDER + ['dead', None] if c[k])


print(f'## A utilities ({len(seeds)} seeds)')
for tk in UT:
    for arm in arms:
        d = D[arm][tk]
        print(f'{tk} {arm:5s} gridfail/yr {rng(d["gf"], "{:.3f}")} | ratings % {ratshare(d["ratings"])} | end {ends(d["end"])}')
        print(f'           ND/EBITDA mean {rng(d["ndE"])} | penalty borrow n {rng(d["pen_n"], "{:.1f}")} $B {rng(d["pen_b"], "{:.1f}")}'
              f' | EBIT m % {100 * st.mean(d["om"]):.1f} sd {100 * st.mean(d["omsd"]):.1f} | rev CAGR % '
              f'{rng([100 * x for x in d["cagr"] if x is not None])} | flags {d["flags"] or "-"}')
print(f'\n## B REITs ({len(seeds)} seeds)')
for tk in RE:
    for arm in arms:
        d = D[arm][tk]
        print(f'{tk} {arm:5s} ratings % {ratshare(d["ratings"])} | end {ends(d["end"])} | flags {d["flags"] or "-"}')
        print(f'           eq$B y0 {st.mean(d["eq0"]):.1f} y20 {rng(d["eq20"], "{:.1f}")} | ND/EBITDA y20 {rng(d["ndE20"])}'
              f' | div/NI 20y {rng(d["divni"])} | div-borrow n {rng(d["divb_n"], "{:.1f}")} $B {rng(d["divb_b"], "{:.1f}")}')
