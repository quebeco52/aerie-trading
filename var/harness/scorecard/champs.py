"""Seeded champions (opening streak >= 25): survival on the roll and every streak break with its likely cause.
Usage (from this directory): python3 champs.py <arm> [<arm>...]
Cause, read from the quarter row where the streak fell: `cut` (DPS per share fell that quarter; sub-tag crisis icr<1,
covenant head false, or none seen) else `flat`: year-end dividend not above last year's, `uncovered` when last
year-end x 1.01 exceeds trailing EPS / 4 (the token's coverage test), `covered` otherwise; `split-yr` when a split
fell in the last year. Only breaks on the roll (streak >= 5). Survival = the opening record unbroken.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from payout import load, at, SPLIT  # noqa: E402


def main():
    for arm in sys.argv[1:]:
        runs = load(arm)
        seeds = sorted(runs)
        surv = {5: 0, 10: 0, 20: 0}
        total = 0
        breaks = []
        for s in seeds:
            r = runs[s]
            for tk, m in r['meta'].items():
                if (m.get('st0') or 0) < 25:
                    continue
                total += 1
                q = r['final'][tk]['q']
                for y in surv:
                    row = at(q, y)
                    surv[y] += row is not None and (row.get('streak') or 0) >= m['st0'] + y - 1
                for i in range(1, len(q)):
                    a, b = q[i - 1], q[i]
                    sa, sb = a.get('streak') or 0, b.get('streak') or 0
                    if not (sa >= 5 and sb < sa):
                        continue
                    win = q[max(0, i - 4):i + 1]
                    split = any(x['shares'] > 0 and y['shares'] > 0 and (y['shares'] / x['shares'] > SPLIT or x['shares'] / y['shares'] > SPLIT) for x, y in zip(win, win[1:]))
                    da, db = a['div'] / max(a['shares'], 1), b['div'] / max(b['shares'], 1)
                    eps = (b.get('eps') or 0.0) / 4.0
                    if da > 0 and db < da * (1 - 1e-4) and not (a['shares'] > 0 and b['shares'] / a['shares'] > SPLIT):
                        cause = 'cut'
                        tags = [t for t, ok in (('crisis', b.get('icr') is not None and b['icr'] < 1.0), ('covenant', b.get('head') is False)) if ok]
                        cause += '/' + ('+'.join(tags) if tags else 'cap?')
                    else:
                        yed = a.get('yed') or 0.0
                        cause = 'flat/uncovered' if yed * 1.01 > eps else 'flat/covered'
                        cause += '/split-yr' if split else ''
                    breaks.append((s, tk, b['t'], sa, cause, db, eps, a.get('yed') or 0.0, b['cash'] / 1e9, b['debt'] / 1e9, r['meta'][tk]['model'][:16]))
        print(f'{arm}: {total} champion firm-runs; still on the roll ' + ', '.join(f'y{y} {100 * n / total:.0f}%' for y, n in surv.items())
              + f'; implied attrition {100 * (1 - (surv[20] / total) ** (1 / 20)) if surv[20] else float("nan"):.1f}%/y; breaks {len(breaks)}')
        from collections import Counter
        print('  causes: ' + ', '.join(f'{k} {v}' for k, v in Counter(b[4] for b in breaks).most_common()))
        for b in sorted(breaks, key=lambda x: (x[1], x[0], x[2])):
            print(f'  s{b[0]} {b[1]:5} {b[10]:16} y{b[2]:5.2f} streak {b[3]:2} {b[4]:20} dps {b[5]:.3f} yed {b[7]:.3f} ttmEPS/4 {b[6]:+.3f} cash {b[8]:6.1f}bn debt {b[9]:6.1f}bn')


if __name__ == '__main__':
    main()
