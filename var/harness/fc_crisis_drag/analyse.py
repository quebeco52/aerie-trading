"""Long-run mean of creditCrisisDrag vs KALDOR_CRISIS_DRAG_COMPENSATOR: python3 analyse.py [arm] [burn years]"""
import glob, json, math, os, re, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
ARM = sys.argv[1] if len(sys.argv) > 1 else 'base'
BURN = float(sys.argv[2]) if len(sys.argv) > 2 else 5.0
SRC = os.path.join(H, '../../../src/Service/Macro/Subsystem/MacroAggregateSubsystem.php')
COMP = float(re.search(r'KALDOR_CRISIS_DRAG_COMPENSATOR = ([0-9.]+)', open(SRC).read()).group(1))

seeds = []
for p in sorted(glob.glob(f'{H}/runs/{ARM}-*.json')):
    q = [r for r in json.load(open(p)) if r['t'] > BURN]
    ev = [e for e in json.load(open(p + '.crises')) if e['t'] > BURN]
    years = len(q) / 4.0
    seeds.append({
        'drag': st.mean(r['drag_avg'] for r in q),
        'drag_q': st.mean(r['drag'] for r in q),
        'crises': 100.0 * len(ev) / years,
        'ev': ev,
        'years': years,
    })

n = len(seeds)


def row(name, xs, fmt='{:.5f}'):
    m = st.mean(xs)
    se = st.stdev(xs) / math.sqrt(len(xs))
    return m, se, f'{name:<42} {fmt.format(m):>10} {fmt.format(se):>10} {fmt.format(min(xs)):>10} {fmt.format(max(xs)):>10}'


print(f'arm={ARM} seeds={n} years/seed={seeds[0]["years"]:.1f} (burn-in {BURN}y dropped) compensator={COMP}')
print(f'{"quantity":<42} {"mean":>10} {"se":>10} {"min":>10} {"max":>10}')
m, se, line = row('creditCrisisDrag, tick average (/yr)', [s['drag'] for s in seeds]); print(line)
print(row('creditCrisisDrag, quarter-end sample (/yr)', [s['drag_q'] for s in seeds])[2])
print(row('net push: drag - compensator (/yr)', [s['drag'] - COMP for s in seeds])[2])
print(f'{"t of (compensator - mean)/se":<42} {(COMP - m) / se:>10.2f}')
print(row('crises per 100 years', [s['crises'] for s in seeds], '{:.3f}')[2])
allev = [e for s in seeds for e in s['ev']]
seed_imp = [st.mean(e['impulse'] for e in s['ev']) for s in seeds if s['ev']]
seed_gap = [st.mean(e['credit_gap_before'] for e in s['ev']) for s in seeds if s['ev']]
print(row('impulse per crisis (/yr), seed means', seed_imp)[2])
print(row('creditToGdpGapEma at onset, seed means', seed_gap)[2])
print(f'{"crises pooled / impulse pooled mean":<42} {len(allev):>10d} {st.mean(e["impulse"] for e in allev):>10.5f}')
rate = st.mean(s['crises'] for s in seeds) / 100.0
print(f'{"rate x mean impulse / decay 0.12 (/yr)":<42} {rate * st.mean(e["impulse"] for e in allev) / 0.12:>10.5f}')
