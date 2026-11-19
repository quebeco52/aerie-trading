"""Long-run dividend-streak dynamics from a long PayoutHarnessTest arm (e.g. 60 years).
Usage (from this directory): python3 longrun.py <arm> [from_year]
1. Yearly survival by streak band at each firm's fiscal year end (years from_year..end): P(streak goes s -> s+1).
2. The stationary distribution those survival rates imply under constant entry, scaled by P(streak >= 1 | payer).
3. Actual tier shares of payers at years 20, 40, 60.
4. Breaks of 10+ year streaks against the year's output gap and nominal GDP growth.
"""
import os
import statistics as st
import sys
from collections import Counter, defaultdict

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from payout import load, at  # noqa: E402

BANDS = [(1, 1), (2, 2), (3, 3), (4, 4), (5, 9), (10, 24), (25, 49), (50, 10 ** 6)]


def band(s):
    for lo, hi in BANDS:
        if lo <= s <= hi:
            return (lo, hi)
    return None


def fy_rows(rows):
    """Indices of the firm's fiscal-year-end rows: the phase (index mod 4) where the year-end record is rewritten."""
    ph = Counter(i % 4 for i in range(1, len(rows)) if rows[i].get('yed') is not None and rows[i]['yed'] != rows[i - 1].get('yed'))
    if not ph:
        return []
    p = ph.most_common(1)[0][0]
    return [i for i in range(len(rows)) if i % 4 == p]


def main():
    arm = sys.argv[1]
    y0 = float(sys.argv[2]) if len(sys.argv) > 2 else 10.0
    runs = load(arm)
    seeds = sorted(runs)
    years = runs[seeds[0]]['years']
    cont, tot = Counter(), Counter()
    pay_n = pay_streak = 0
    breaks10 = defaultdict(int)  # (seed, calendar year) -> breaks of 10+ streaks
    for s in seeds:
        for tk, f in runs[s]['final'].items():
            rows = f['q']
            fy = fy_rows(rows)
            for a, b in zip(fy, fy[1:]):
                if rows[b]['t'] < y0 or b - a != 4:
                    continue
                sa, sb = rows[a].get('streak') or 0, rows[b].get('streak') or 0
                if (rows[a].get('dps') or 0) > 0:
                    pay_n += 1
                    pay_streak += sa >= 1
                if sa >= 1:
                    bd = band(sa)
                    tot[bd] += 1
                    cont[bd] += sb == sa + 1
            for i in range(1, len(rows)):
                sa, sb = rows[i - 1].get('streak') or 0, rows[i].get('streak') or 0
                if sa >= 10 and sb < sa and rows[i]['t'] >= y0:
                    breaks10[(s, int(rows[i]['t'] - 1e-6) + 1)] += 1
    print(f'arm {arm}, seeds {seeds}, {years}y, tpy {runs[seeds[0]]["tpy"]}; survival measured at fiscal year ends, years {y0:g}-{years}')
    print('\n1. P(streak continues one more year) by band')
    c = {}
    for bd in BANDS:
        p = cont[bd] / tot[bd] if tot[bd] else float('nan')
        c[bd] = p
        lab = f'{bd[0]}' if bd[0] == bd[1] else (f'{bd[0]}+' if bd[1] > 10 ** 5 else f'{bd[0]}-{bd[1]}')
        print(f'  {lab:>6}  {p:.3f}  n {tot[bd]:5}  (se {((p * (1 - p) / tot[bd]) ** 0.5 if tot[bd] else float("nan")):.3f})')
    p1 = pay_streak / pay_n
    print(f'  P(streak >= 1 | payer) {p1:.3f}  n {pay_n}')

    print('\n2. Stationary shares of payers implied (constant entry, N_m ~ prod_{k<m} c_k, m to 2000)')
    n, w = [], 1.0
    for m in range(1, 2001):
        n.append(w)
        w *= c[band(m)]
    total = sum(n)
    for lo in (5, 10, 25, 50):
        print(f'  >= {lo:2}: {100 * p1 * sum(n[lo - 1:]) / total:5.1f}%')
    print(f'  mean streak among streak>=1: {sum((i + 1) * x for i, x in enumerate(n)) / total:.1f} y')

    print('\n3. Actual shares of payers by streak (mean over seeds)')
    for y in (10, 20, 40, 60):
        if y > years:
            continue
        sh = defaultdict(list)
        for s in seeds:
            pay = [r for f in runs[s]['final'].values() for r in [at(f['q'], y)] if r is not None and (r.get('dps') or 0) > 0]
            for lo in (5, 10, 25, 50):
                sh[lo].append(sum((r.get('streak') or 0) >= lo for r in pay) / max(1, len(pay)))
        print(f'  y{y:2}: ' + '  '.join(f'>={lo} {100 * st.mean(v):5.1f}%' for lo, v in sh.items()))

    print('\n4. Breaks of 10+ year streaks against the year (years after the first, per seed-year)')
    rows_y = []
    for s in seeds:
        r = runs[s]
        mac = r['macro']
        anyq = next(iter(r['final'].values()))['q']
        for y in range(int(y0) + 1, years + 1):
            g0 = [m[1] for m in mac if y - 1 - 0.13 < m[0] <= y - 1 + 0.13]
            g1 = [m[1] for m in mac if y - 0.13 < m[0] <= y + 0.13]
            gaps = [q['gap'] for q in anyq if y - 1 < q['t'] <= y]
            if not g0 or not g1 or not gaps:
                continue
            rows_y.append((g1[-1] / g0[-1] - 1.0, st.mean(gaps), breaks10[(s, y)]))
    nb = sum(x[2] for x in rows_y)
    for k, lab in ((0, 'nominal GDP growth'), (1, 'output gap')):
        srt = sorted(rows_y, key=lambda x: x[k])
        cut = len(srt) // 5
        worst = sum(x[2] for x in srt[:cut])
        best = sum(x[2] for x in srt[-cut:])
        xs, ys = [x[k] for x in rows_y], [x[2] for x in rows_y]
        mx, my = st.mean(xs), st.mean(ys)
        cov = sum((a - mx) * (b - my) for a, b in zip(xs, ys))
        corr = cov / ((sum((a - mx) ** 2 for a in xs) * sum((b - my) ** 2 for b in ys)) ** 0.5 or 1)
        print(f'  by {lab:20}: worst fifth {100 * worst / max(1, nb):4.1f}% of breaks, best fifth {100 * best / max(1, nb):4.1f}%, corr {corr:+.2f}')
    print(f'  breaks of 10+ streaks: {nb} over {len(rows_y)} seed-years ({nb / max(1, len(rows_y)):.2f} a year)')


if __name__ == '__main__':
    main()
