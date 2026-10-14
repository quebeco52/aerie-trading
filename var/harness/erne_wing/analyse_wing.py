"""WING rating attribution across arms, paired by seed. Usage: python3 -I analyse_wing.py <lo-hi> a s b.
Needs runs made with RATING_PROBE=WING (r_* fields). Quarters after year 2 for the moments; whole run for ratings."""
import json, math, os, sys

H = os.path.dirname(os.path.abspath(__file__))
T0 = 2.0
lo, hi = (int(x) for x in sys.argv[1].split('-'))
ARMS = sys.argv[2:] or ['a', 's', 'b']
seeds = list(range(lo, hi + 1))
RANK = {'AAA': 7, 'AA': 6, 'A': 5, 'BBB': 4, 'BB': 3, 'B': 2, 'CCC': 1, 'CC': 1, 'C': 1, 'D': 0}


def mean(xs):
    xs = [x for x in xs if x is not None and not (isinstance(x, float) and math.isnan(x))]
    return sum(xs) / len(xs) if xs else float('nan')


def sd(xs):
    m = mean(xs)
    return math.sqrt(sum((x - m) ** 2 for x in xs) / (len(xs) - 1))


def stats(arm, s):
    r = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').readline())
    allq = r['final']['WING']['q']
    q = [x for x in allq if x['t'] > T0 and x['rev'] > 0]
    spells, run = [], 0
    for x in allq:
        if RANK.get(x.get('rating'), 9) <= 2:
            run += 1
        elif run:
            spells.append(run); run = 0
    if run:
        spells.append(run)
    qicr = [x['ebit'] / x['int'] if x['int'] > 0 else None for x in q]
    qnde = [(x['debt'] - x['cash']) / (4 * x['ebitda']) if x['ebitda'] > 0 else None for x in q]
    return {
        'EBIT margin %': 100 * mean([x['ebit'] / x['rev'] for x in q]),
        'ICR quarterly, mean': mean(qicr), 'ICR quarterly, min': min(v for v in qicr if v is not None),
        'ICR LTM, mean': mean([x.get('r_icr') for x in q]), 'ICR LTM, min': min(x['r_icr'] for x in q),
        'ND/EBITDA quarterly x4, mean': mean(qnde), 'ND/EBITDA quarterly x4, max': max(v for v in qnde if v is not None),
        'ND/EBITDA LTM, mean': mean([x.get('r_ndE') for x in q]), 'ND/EBITDA LTM, max': max(x['r_ndE'] for x in q),
        'rated ND/EBITDA (time-weighted), mean': mean([x.get('r_twl') for x in q]),
        'Merton d2, mean': mean([x['r_dd'] for x in q]), 'Merton d2, min': min(x['r_dd'] for x in q),
        'Altman Z, mean': mean([x['r_z'] for x in q]), 'Altman Z, min': min(x['r_z'] for x in q),
        'share q Altman Grey/Distress %': 100 * mean([1.0 if x['r_zone'] in ('Grey', 'Distress') else 0.0 for x in q]),
        'share q leverage anchor <= BB %': 100 * mean([1.0 if RANK.get(x.get('r_anchor'), 9) <= 3 else 0.0 for x in q]),
        'share q rated <= B %': 100 * mean([1.0 if RANK.get(x.get('rating'), 9) <= 2 else 0.0 for x in q]),
        'sd equity vol (cvol)': sd([x['cvol'] for x in q]),
        '_spells': spells,
        '_bad_q': [(x['r_anchor'], x['r_zone'], round(x['r_dd'], 2)) for x in allq if RANK.get(x.get('rating'), 9) <= 2],
    }


D = {a: {s: stats(a, s) for s in seeds} for a in ARMS}
keys = [k for k in D[ARMS[0]][seeds[0]] if not k.startswith('_')]
n = len(seeds)
pairs = [(ARMS[i], ARMS[j]) for i in range(len(ARMS)) for j in range(i + 1, len(ARMS))]
print(f'WING, seeds {lo}-{hi} (n={n}); moments over quarters after year {T0:g}; paired differences by seed')
print(f"{'quantity':38s}" + ''.join(f'{a:>9s}' for a in ARMS) + ''.join(f"{b + '-' + a:>9s} {'se':>5s} {'t':>5s}" for a, b in pairs))
for k in keys:
    v = {a: [D[a][s][k] for s in seeds] for a in ARMS}
    line = f'{k:38s}' + ''.join(f'{mean(v[a]):9.2f}' for a in ARMS)
    for a, b in pairs:
        d = [y - x for x, y in zip(v[a], v[b])]
        se = sd(d) / math.sqrt(n)
        line += f'{mean(d):9.2f} {se:5.2f} {(mean(d) / se if se > 0 else float("nan")):5.1f}'
    print(line)
for a in ARMS:
    hit = [s for s in seeds if D[a][s]['_spells']]
    print(f"{a}: seeds reaching B or worse {len(hit)}/{n} {hit}; spell lengths (quarters) "
          + '; '.join(f"s{s}: {D[a][s]['_spells']}" for s in hit))
    bad = [b for s in seeds for b in D[a][s]['_bad_q']]
    if bad:
        print(f"   in B-or-worse quarters: anchor <=BB {sum(1 for x in bad if RANK.get(x[0], 9) <= 3)}/{len(bad)}, "
              f"Altman Grey/Distress {sum(1 for x in bad if x[1] in ('Grey', 'Distress'))}/{len(bad)}, "
              f"Altman Distress {sum(1 for x in bad if x[1] == 'Distress')}/{len(bad)}, median d2 {sorted(x[2] for x in bad)[len(bad) // 2]}")
