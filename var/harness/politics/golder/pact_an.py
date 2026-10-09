"""Golder pacts on the recorded Diets: how many pacts form, what blocs they make, and what governments come out, by arm.

python3 pact_an.py out/p_*.jsonl
"""
import json, sys, glob, collections, statistics as st

ARMS = ['fixed', 'none', 'g0', 'g2', 'g4']
MAJ = 151
rows = collections.defaultdict(list)
for f in sys.argv[1:] or glob.glob('out/p_*.jsonl'):
    for line in open(f):
        r = json.loads(line)
        rows[r['arm']].append(r)

print('Pacts (Golder 1946-98: 5.4% of dyads; elections with a pact: all 44%, Norway 54, Netherlands 40, Sweden 31, Finland 7, Denmark 5)')
print(f"{'arm':6s} {'dyad%':>6s} {'elec w/ pact':>12s} {'pacts/elec':>10s} {'2 rival blocs':>13s} {'C-V pact':>8s} {'polar':>6s}")
for arm in ('g0', 'g2', 'g4'):
    rs = rows[arm]
    n = len(rs)
    npacts = [len(r['pacts']) for r in rs]
    cv = sum(any(set(p) == {'civic', 'vanguard'} for p in r['pacts']) for r in rs)
    two = 0
    for r in rs:
        comps = collections.defaultdict(set)
        for p, b in r['blocs'].items():
            comps[b].add(p)
        led = [c for c in comps.values() if len(c) > 1 and ({'civic', 'vanguard'} & c) and not {'civic', 'vanguard'} <= c]
        two += len(led) >= 2
    print(f"{arm:6s} {100 * sum(npacts) / (28 * n):6.1f} {100 * sum(x > 0 for x in npacts) / n:11.0f}% {st.mean(npacts):10.2f} {100 * two / n:12.1f}% {100 * cv / n:7.1f}% {st.mean(r['polar'] for r in rs):6.1f}")
pairs = collections.Counter(tuple(sorted(p)) for r in rows['g0'] for p in r['pacts'])
print('  commonest g0 pacts, % of elections:', ', '.join(f"{a[:4]}-{b[:4]} {100 * c / len(rows['g0']):.1f}" for (a, b), c in pairs.most_common(8)))

print('\nGovernments after a hung vote (Scandinavia: minority 84%, single-party 47%, grand 1.5%; West Europe: minority 36.5%, largest party in 82%)')
hdr = ['minority', 'single', '1p minority', 'grand C+V', 'rival props', 'largest in', 'one-bloc', 'days', '>1 attempt', 'parties']
print(f"{'arm':6s}" + ''.join(f'{h:>12s}' for h in hdr))
for arm in ARMS:
    rs = [r for r in rows[arm] if max(r['seats'].values()) < MAJ]
    n = len(rs)
    c = collections.Counter()
    for r in rs:
        cab, sup, seats = set(r['cabinet']), set(r['support']), r['seats']
        minority = sum(seats[p] for p in cab) < MAJ
        largest = max(seats, key=lambda p: seats[p])
        gov = cab | sup
        c['minority'] += minority
        c['single'] += len(cab) == 1
        c['1p minority'] += len(cab) == 1 and minority
        c['grand C+V'] += {'civic', 'vanguard'} <= cab
        c['rival props'] += ('civic' in cab and 'vanguard' in sup) or ('vanguard' in cab and 'civic' in sup)
        c['largest in'] += largest in cab
        c['one-bloc'] += len(gov) > 1 and len({r['blocs'][p] for p in gov}) == 1
        c['>1 attempt'] += r['attempts'] > 1
    days = st.mean(r['days'] for r in rs)
    parties = st.mean(len(r['cabinet']) for r in rs)
    print(f'{arm:6s}' + ''.join(f"{100 * c[h] / n:11.1f}%" for h in hdr[:7]) + f'{days:12.1f}' + f"{100 * c['>1 attempt'] / n:11.1f}%" + f'{parties:12.2f}')
