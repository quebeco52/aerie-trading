"""What the talks produced, election by election (formation_run.php output), against the record."""
import json, sys, statistics as st, collections, math
every = [json.loads(l) for f in sys.argv[1:] for l in open(f)]
rows = [r for r in every if not r.get('fall')]
LOYAL = {'chartists'}
n = len(rows)
talks = [r for r in rows if r['log']]
days = [r['log'][-1]['day'] for r in talks]
att = [len(r['log']) for r in talks]
minority = [r for r in rows if r['support']]
largest = lambda r: max(r['seats'], key=lambda p: (r['seats'][p], r['shares'][p]))
second = lambda r: sorted(r['seats'], key=lambda p: (-r['seats'][p], -r['shares'][p]))[1]
print(f"elections {n}; talks {len(talks)}; single-party majorities {n - len(talks)}")
print(f"first attempt fails {sum(a > 1 for a in att) / len(att):.3f} (Golder: nearly a third)")
print(f"days mean {st.mean(days):.1f} sd {st.pstdev(days):.1f} median {st.median(days):.1f} >90 {sum(d > 90 for d in days) / len(days):.3f} >180 {sum(d > 180 for d in days) / len(days):.4f} max {max(days):.0f} (Baeck et al.: 33.7, 33.9; <5% over 90 of all governments)")
print(f"attempts: " + ", ".join(f"{k}:{v}" for k, v in sorted(collections.Counter(att).items())))
print(f"minority cabinets {len(minority) / n:.3f} (Scandinavia 0.84; Western Europe 0.365)")
print(f"single-party cabinets {sum(len(r['cabinet']) == 1 for r in rows) / n:.3f} (Scandinavia 0.47)")
single_min = sum(len(r['cabinet']) == 1 and bool(r['support']) for r in rows)
print(f"  single-party minority {single_min / n:.3f}; multi-party minority {(len(minority) - single_min) / n:.3f}")
def seats(r, ps): return sum(r['seats'][p] for p in ps)
mw = sum(not r['support'] and all(seats(r, r['cabinet']) - r['seats'][p] < 151 for p in r['cabinet']) for r in rows)
print(f"minimal winning {mw / n:.3f}; surplus {(n - len(minority) - mw) / n:.3f}")
print(f"grand coalition (Civic and Vanguard in cabinet) {sum('civic' in r['cabinet'] and 'vanguard' in r['cabinet'] for r in rows) / n:.3f}")
print(f"largest party in cabinet {sum(largest(r) in r['cabinet'] for r in rows) / n:.3f} (Scandinavia 0.63; Western Europe 0.82); led by the largest {sum(bool(r['log']) and r['log'][-1]['formateur'] == largest(r) for r in rows) / n:.3f} (PM from the largest 0.74); talks opened by another party {sum(bool(r['log']) and r['log'][0]['formateur'] != largest(r) for r in rows) / n:.3f}")
print(f"two largest in cabinet {sum(largest(r) in r['cabinet'] and second(r) in r['cabinet'] for r in rows) / n:.3f} (Scandinavia 0.015; Western Europe 0.30)")
LEADERS = ['civic', 'vanguard']
CORES = {'civic': ['civic'], 'vanguard': ['vanguard']}
def blocs(r):
    if 'blocs' in r: return r['blocs']
    X = r['positions']
    centre = {l: {x: sum(X[p][x] for p in c) / len(c) for x in ('state', 'openness')} for l, c in CORES.items()}
    out = {}
    for p in X:
        home = [l for l, c in CORES.items() if p in c]
        out[p] = home[0] if home else min(LEADERS, key=lambda l: (sum((X[p][x] - centre[l][x]) ** 2 for x in ('state', 'openness')), l != LEADERS[0]))
    return out
def leaders(r): return sorted(set(blocs(r).values())) if 'blocs' in r else LEADERS
print(f"a bloc leader props up the other {sum(any(l in r['cabinet'] for l in leaders(r)) and any(l in r['support'] for l in leaders(r)) for r in rows) / n:.3f}")
print(f"both bloc leaders in cabinet {sum(all(l in r['cabinet'] for l in leaders(r)) for r in rows) / n:.3f}")
print(f"bloc leaders: {', '.join(f'{k} {v / n:.3f}' for k, v in collections.Counter('+'.join(leaders(r)) for r in rows).most_common(5))}")
print(f"bloc shapes: {'; '.join(f'{v / n:.3f} ' + k for k, v in collections.Counter(' | '.join(sorted(','.join(p[:4] for p in blocs(r) if blocs(r)[p] == l) for l in set(blocs(r).values()))) for r in rows).most_common(4))}")
cross = sum(len({blocs(r)[p] for p in r['cabinet'] + r['support']}) > 1 for r in rows) / n
print(f"governments crossing the blocs {cross:.3f}")
switches = seen = 0
by_seed = collections.defaultdict(list)
for r in rows: by_seed[r['s']].append(r)
for rs in by_seed.values():
    rs.sort(key=lambda r: r['t'])
    for a, b in zip(rs, rs[1:]):
        ba, bb = blocs(a), blocs(b)
        for p in ba:
            if 'blocs' in a:
                if ba[p] != p and bb[p] != p: seen += 1; switches += (ba[p] == ba['civic']) != (bb[p] == bb['civic'])
            elif not any(p in c for c in CORES.values()): seen += 1; switches += ba[p] != bb[p]
print(f"a party switches bloc at {switches / seen:.3f} of votes")
for p in rows[0]['seats']:
    print(f"  {p:14s} bloc {collections.Counter(blocs(r)[p] for r in rows).most_common(1)[0][0]:8s} ({collections.Counter(blocs(r)[p] for r in rows).most_common(1)[0][1] / n:.2f})  cabinet {sum(p in r['cabinet'] for r in rows) / n:.2f} support {sum(p in r['support'] for r in rows) / n:.2f}")
for lo in range(0, 200, 40):
    b = [r for r in rows if lo < r['t'] <= lo + 40]
    if b: print(f"  years {lo:3d}-{lo + 40:3d}: grand coalition {sum('civic' in r['cabinet'] and 'vanguard' in r['cabinet'] for r in b) / len(b):.3f}, minority {sum(bool(r['support']) for r in b) / len(b):.3f}")
FIXED = {'civic': {'state'}, 'vanguard': {'state'}, 'iron_harbor': {'openness'}, 'exchange': {'openness'}, 'chartists': {'council'}, 'common_lot': {'council'},
         'tideline': {'environment'}, 'new_horizon': {'state', 'openness'}}
CHES_SD = {'state': 0.125, 'openness': 0.265, 'council': 0.188, 'environment': 0.170}
late = [r for r in rows if r['t'] > 40]
for axis in CHES_SD:
    devs = [r['positions'][p][axis] - st.mean(q['positions'][p][axis] for q in late) for r in late for p in r['positions'] if axis not in FIXED[p] and axis in r['positions'][p]]
    print(f"  {axis:8s} spread around home {st.pstdev(devs):.3f} (Chapel Hill {CHES_SD[axis]})")
print(f"cabinet re-formed unchanged {sum(sorted(r['cabinet']) == sorted(r['outgoing']) for r in rows) / n:.3f} (Scandinavia 0.46; Western Europe 0.38)")
print(f"Chartists and Common Lot both in cabinet {sum('chartists' in r['cabinet'] and 'common_lot' in r['cabinet'] for r in rows) / n:.4f}; in cabinet+support {sum({'chartists','common_lot'} <= set(r['cabinet'] + r['support']) for r in rows) / n:.4f}")
print(f"three quarters (less loyalists) {sum(seats(r, [p for p in r['cabinet'] + r['support'] if p not in LOYAL]) >= 225 for r in rows) / n:.4f}")
steady = [r for r in rows if r['t'] > 4]
print(f"Pedersen volatility {st.mean(0.5 * sum(abs(v) for v in r['swings'].values()) for r in steady) * 100:.2f} (compromise B 6.8)")
print(f"observed incumbent loss {-st.mean(r['incumbentSwing'] for r in steady) * 100:.2f}pp")
for p in rows[0]['seats']:
    print(f"  {p:11s} seats mean {st.mean(r['seats'][p] for r in steady):5.1f} sd {st.pstdev(r['seats'][p] for r in steady):4.1f}; in cabinet {sum(p in r['cabinet'] for r in rows) / n:.2f}, supporting {sum(p in r['support'] for r in rows) / n:.2f}")
types = collections.Counter('+'.join(r['cabinet']) + (' | ' + '+'.join(r['support']) if r['support'] else '') for r in rows)
print("top governments:")
for k, v in types.most_common(12):
    print(f"  {v / n:.3f} {k}")

# Cabinets falling between votes (DistrictPoliticsSubsystem::fallHazard) against ParlGov (termination_fit.py).
TERM, DAYS = 4.0, 365.0
by_seed = collections.defaultdict(list)
for r in every:
    by_seed[r['s']].append(r)
exposure = collections.Counter(); falls = collections.Counter(); excess = 0.0; terms = 0; terms_with_fall = set()
def kind(cabinet, seats):
    minority = sum(seats[p] for p in cabinet) < 151
    return ('single-party minority' if len(cabinet) == 1 else 'minority coalition') if minority else ('majority coalition' if len(cabinet) > 1 else 'single-party majority')
def ramp(t):
    return max(0.0, 1.0 - (TERM - math.fmod(t, TERM)))
for seed, rs in by_seed.items():
    rs.sort(key=lambda r: (r['t'], not r.get('fall')))
    terms += sum(1 for r in rs if not r.get('fall'))
    for a, b in zip(rs, rs[1:]):
        days = a['log'][-1]['day'] if a['log'] else 0.0
        start = a['t'] + days / DAYS
        k = kind(a['cabinet'], a['seats'])
        exposure[k] += max(0.0, b['t'] - start)
        if b.get('fall'):
            falls[k] += 1
            terms_with_fall.add((seed, int(b['t'] // TERM)))
            end = b['t'] + (b['log'][-1]['day'] if b['log'] else 0.0) / DAYS
            steps = 200
            excess += sum((1.0 - ramp(b['t'] + (end - b['t']) * (i + 0.5) / steps)) for i in range(steps)) * (end - b['t']) / steps * DAYS
REAL = {'single-party minority': 0.106, 'minority coalition': 0.216, 'majority coalition': 0.119, 'single-party majority': 0.0}
print(f"cabinet falls: {sum(falls.values())} in {terms} terms; terms with a fall {len(terms_with_fall) / terms:.3f}")
for k in REAL:
    if exposure[k]:
        print(f"  {k:22s} {falls[k]:4d} falls in {exposure[k]:7.0f} cabinet-years: hazard {falls[k] / exposure[k]:.3f} (ParlGov {REAL[k]:.3f}); share of time {exposure[k] / sum(exposure.values()):.2f}")
fall_rows = [r for r in every if r.get('fall')]
if fall_rows:
    print(f"talks after a fall: mean {st.mean(r['log'][-1]['day'] if r['log'] else 0.0 for r in fall_rows):.1f} days; pulse days above the ramp per term {excess / terms:.2f}")
    print(f"  the fallen cabinet's parties back in the next: {st.mean(len(set(r['fallen']) & set(r['cabinet'])) > 0 for r in fall_rows):.2f}")
