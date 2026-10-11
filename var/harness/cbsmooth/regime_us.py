#!/usr/bin/env python3
"""US regime moments: python3 -I regime_us.py <ebp.csv> <sahmrealtime.csv> <usrec.csv>.
GZ EBP (FEDS note csv, pp), FRED SAHMREALTIME (pp), FRED USREC; monthly. Prints the shares the sim's recession regime
(Sahm >= 0.5 OR EBP latch: in at >= entry, out at <= exit) is on over 1985-2019 and 1985-2021."""
import csv, math, sys

def load(path, col):
    out = {}
    for r in csv.DictReader(open(path)):
        k = list(r.keys())[0]
        v = r[col]
        if v not in ('', '.'):
            out[r[k][:7]] = float(v)
    return out

ebp = load(sys.argv[1], 'ebp'); sahm = load(sys.argv[2], 'SAHMREALTIME'); rec = load(sys.argv[3], 'USREC')

def latch(series, entry, exit_):
    on, out = False, []
    for v in series:
        on = v >= entry or (on and v > exit_)
        out.append(on)
    return out

def stats(lo, hi):
    ms = sorted(m for m in ebp if lo <= m[:4] <= hi and m in sahm and m in rec)
    e = [ebp[m] for m in ms]; s = [sahm[m] >= 0.5 for m in ms]; r = [rec[m] > 0 for m in ms]
    n = len(ms); mu = sum(e) / n; sd = math.sqrt(sum((x - mu) ** 2 for x in e) / (n - 1))
    print(f'{lo}-{hi}: n={n} months ({ms[0]}..{ms[-1]})')
    print(f'  EBP mean {mu:.3f}pp sd {sd:.3f}pp  share>=1pp {sum(x >= 1.0 for x in e) / n:.3f}  share>=0.5pp {sum(x >= 0.5 for x in e) / n:.3f}')
    print(f'  Sahm>=0.5 share {sum(s) / n:.3f}   NBER (USREC) share {sum(r) / n:.3f}')
    for entry in (1.0, 1.25, 1.5, 2.0):
        for exit_ in (0.0, 0.25, 0.5):
            L = latch(e, entry, exit_)
            print(f'  latch in {entry:.2f} out {exit_:.2f}: latch {sum(L) / n:.3f}  latch-only {sum(a and not b for a, b in zip(L, s)) / n:.3f}'
                  f'  sahm-only {sum(b and not a for a, b in zip(L, s)) / n:.3f}  both {sum(a and b for a, b in zip(L, s)) / n:.3f}'
                  f'  union {sum(a or b for a, b in zip(L, s)) / n:.3f}')

stats('1985', '2019')
stats('1985', '2021')
