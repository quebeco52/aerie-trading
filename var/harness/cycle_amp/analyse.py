#!/usr/bin/env python3
"""Cycle amplitude on one arm: annual growth, crises, gap split, unemployment, floor share, gap channels.
Usage: python3 analyse.py [ARM] (default R1). Env SEEDS (e.g. 1-16, default every file), BURN (5 years), YEARS (45)."""
import glob, json, math, os, re, sys

H = os.path.dirname(os.path.abspath(__file__))
ARM = sys.argv[1] if len(sys.argv) > 1 else 'R1'
BURN = int(os.environ.get('BURN', 5))
YEARS = int(os.environ.get('YEARS', 45))
ELB = 0.001 + 1e-4          # MacroEngine::EFFECTIVE_LOWER_BOUND + 1e-4
BS_ON = 0.0005              # MacroEngine::BALANCE_SHEET_ACTIVE_THRESHOLD
SAHM = 0.005                # MacroEngine::SAHM_RECESSION_THRESHOLD


def seeds():
    s = os.environ.get('SEEDS')
    if not s:
        return sorted(int(re.search(r'-(\d+)\.macro', f).group(1)) for f in glob.glob(f'{H}/runs/{ARM}-*.macro.jsonl'))
    out = []
    for part in s.split(','):
        a, _, b = part.partition('-')
        out += list(range(int(a), int(b or a) + 1))
    return out


def mean(x): return sum(x) / len(x) if x else float('nan')
def sd(x):
    m = mean(x); return math.sqrt(sum((v - m) ** 2 for v in x) / (len(x) - 1)) if len(x) > 1 else float('nan')
def cov(x, y):
    mx, my = mean(x), mean(y); return sum((a - mx) * (b - my) for a, b in zip(x, y)) / (len(x) - 1)


per = {}       # quantity -> list of per-seed values
pooled = {'post_crisis_worst': [], 'quiet_worst': []}
chan = {}      # channel -> list of (sd, cov share)
per40 = 40.0 / (YEARS - BURN)
blown = []
for seed in seeds():
    rows = [json.loads(l) for l in open(f'{H}/runs/{ARM}-{seed}.macro.jsonl')]
    if not rows or rows[-1]['t'] < YEARS - 1e-6:
        blown.append(seed); continue
    # Annual level: mean of the 4 quarters of each sim year (t in (y-1, y]); year BURN is the growth base only.
    lvl = {}
    for r in rows:
        y = math.ceil(r['t'] - 1e-9)
        lvl.setdefault(y, []).append(r['pot'] * (1 + r['gap']))
    g = {y: 100 * (mean(lvl[y]) / mean(lvl[y - 1]) - 1) for y in range(BURN + 1, YEARS + 1)}
    gv = list(g.values())
    add = lambda k, v: per.setdefault(k, []).append(v)
    add('growth sd %', sd(gv)); add('growth mean %', mean(gv)); add('worst year %', min(gv)); add('best year %', max(gv))
    add('negative years /40', per40 * sum(v < 0 for v in gv))
    # Crises: the year a new lastCreditCrisisAt appears (whole run, so burn-in crises still mark the quiet test).
    cy, last = [], None
    for r in rows:
        c = r['crisis_at']
        if c is not None and c > 0 and c != last:
            cy.append(math.ceil(c - 1e-9))
        last = c
    win = [y for y in cy if y > BURN]
    add('crises /40', per40 * len(win))
    for y in win:   # crisis year and the two after it
        w = [g[k] for k in (y, y + 1, y + 2) if k in g]
        if w: pooled['post_crisis_worst'].append(min(w))
    quiet = [g[y] for y in g if not any(y - 3 <= c <= y for c in cy)]
    if quiet: pooled['quiet_worst'].append(min(quiet))
    # Quarterly, after burn-in.
    q = [r for r in rows if r['t'] > BURN + 1e-9]
    gap = [r['gap'] for r in q]; vg = sd(gap) ** 2
    add('gap sd %', 100 * sd(gap)); add('gap min %', 100 * min(gap)); add('gap max %', 100 * max(gap))
    for k, lab in (('supply', 'supply'), ('nx', 'net export'), ('fin', 'finance')):
        x = [r[k] for r in q]
        add(f'{lab} gap sd %', 100 * sd(x)); add(f'{lab} share of var(gap)', cov(x, gap) / vg)
    ua = [100 * mean([r['u'] for r in rows if math.ceil(r['t'] - 1e-9) == y]) for y in range(BURN + 1, YEARS + 1)]
    add('u annual sd pp', sd(ua)); add('u max %', 100 * max(r['u'] for r in q)); add('u mean %', 100 * mean([r['u'] for r in q]))
    starts, armed = 0, True
    for r in q:
        if armed and r['sahm'] >= SAHM: starts += 1; armed = False
        elif r['sahm'] < SAHM: armed = True
    add('Sahm starts /40', per40 * starts)
    add('policy at floor share', mean([r['policy'] <= ELB for r in q]))
    add('balance sheet on share', mean([r['bs_i'] > BS_ON for r in q]))
    # Gap channels: each quarter's drift contribution vs the quarter's gap change (closes with diffusion, clamp, unexplained).
    dg = [q[i]['gap'] - q[i - 1]['gap'] for i in range(1, len(q))]; vd = sd(dg) ** 2
    names = set().union(*(r['gc'].keys() for r in q))
    for n in list(names) + ['diffusion', 'clamp']:
        x = [(q[i]['gc'].get(n, 0.0) if n not in ('diffusion', 'clamp') else q[i]['gc_' + n[:5].replace('diffu', 'diff')]) for i in range(1, len(q))]
        chan.setdefault(n, []).append((100 * sd(x), cov(x, dg) / vd))
    add('d(gap) quarterly sd %', 100 * math.sqrt(vd))

n = len(per['growth sd %'])
L = [f'arm {ARM}, n={n} seeds, years {BURN + 1}-{YEARS} (growth base year {BURN}), blown/short: {blown or "none"}',
     f'{"quantity":32s} {"mean":>8s} {"se":>7s} {"min":>8s} {"max":>8s}']
for k, v in per.items():
    L.append(f'{k:32s} {mean(v):8.3f} {sd(v) / math.sqrt(len(v)):7.3f} {min(v):8.3f} {max(v):8.3f}')
for k, v in pooled.items():
    if v: L.append(f'{k + " % (pooled, n=" + str(len(v)) + ")":32s} {mean(v):8.3f} {sd(v) / math.sqrt(len(v)) if len(v) > 1 else float("nan"):7.3f} {min(v):8.3f} {max(v):8.3f}')
L.append('')
L.append('gap channels, quarterly: sd of contribution (% of potential) and cov(contribution, d gap)/var(d gap), seed means')
L.append(f'{"channel":28s} {"sd %":>8s} {"cov share":>10s} {"se":>7s}')
rank = sorted(chan.items(), key=lambda kv: -abs(mean([c for _, c in kv[1]])))
for k, v in rank:
    cs = [c for _, c in v]
    L.append(f'{k:28s} {mean([s for s, _ in v]):8.4f} {mean(cs):10.3f} {sd(cs) / math.sqrt(len(cs)):7.3f}')
L.append(f'{"sum of cov shares":28s} {"":8s} {sum(mean([c for _, c in v]) for v in chan.values()):10.3f}  (1 minus unexplained)')
print('\n'.join(L))
