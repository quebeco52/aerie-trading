"""event.py: crash event study, paired. Events: quarters where B's fgap falls > 1.5pp and A's (same seed, same quarter)
falls < 1pp. Prints the mean B-A path of fgap, gap, rgq, dmain, dshock, policy (pp) from k = -1 to +8 quarters.
Env SEEDS (1-8), BURN (5)."""
import json, os
H = os.path.dirname(os.path.abspath(__file__))
BURN = float(os.environ.get('BURN', 5))


def seeds():
    out = []
    for part in os.environ.get('SEEDS', '1-8').split(','):
        a, _, b = part.partition('-')
        out += list(range(int(a), int(b or a) + 1))
    return out


def load(arm, s):
    return [r for r in (json.loads(l) for l in open(f'{H}/runs/{arm}-{s}.macro.jsonl')) if r['t'] > 0]


F = ['fgap', 'gap', 'rgq', 'dmain', 'dshock', 'policy']
K = range(-1, 9)
acc = {(f, k): [] for f in F for k in K}
n = 0
for s in seeds():
    A, B = load('A', s), load('B', s)
    m = min(len(A), len(B))
    for i in range(1, m - 8):
        if B[i]['t'] <= BURN:
            continue
        if B[i]['fgap'] - B[i - 1]['fgap'] < -0.015 and A[i]['fgap'] - A[i - 1]['fgap'] > -0.01:
            n += 1
            for f in F:
                base = B[i - 1].get(f, 0.0) - A[i - 1].get(f, 0.0)
                for k in K:
                    acc[(f, k)].append((B[i + k].get(f, 0.0) - A[i + k].get(f, 0.0) - base) * 100)
print(f'{n} crash events (B fgap fall > 1.5pp, A < 1pp); mean B-A in pp less its k = -1 value, k quarters after the crash quarter')
print('k'.ljust(8) + ''.join(f'{k:+d}'.rjust(8) for k in K))
for f in F:
    print(f.ljust(8) + ''.join(f'{sum(acc[(f, k)]) / max(1, len(acc[(f, k)])):8.2f}' for k in K))
