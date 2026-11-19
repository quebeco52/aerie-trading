"""Dividend policy and board dividend measures from PayoutHarnessTest runs.
Usage (from this directory): python3 payout.py <arm_a> <arm_b>   (policy-change counts read arm_b's events)
"""
import glob
import json
import os
import statistics as st
import sys
from collections import Counter

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import paired, fmt  # noqa: E402

STEPS = [('retain', 0.0), ('low', 0.20), ('balanced', 0.35), ('income', 0.50), ('harvest', 0.75)]
SPLIT = 1.4
FLIP_YEARS = 3.0


def load(arm):
    runs = {}
    for path in sorted(glob.glob(f'runs/{arm}-s*.jsonl')):
        with open(path) as f:
            line = f.readline()
        if line.strip():
            r = json.loads(line)
            runs[r['seed']] = r
    return runs


def step(ratio):
    return min(STEPS, key=lambda s: abs(s[1] - ratio))[0]


def at(q, t):
    """The last row at or before time t."""
    row = None
    for r in q:
        if r['t'] <= t + 1e-6:
            row = r
    return row


def dps_adj(rows):
    """Split-adjusted dividend per share per row: the engine's declared `dps` when the run recorded it, else div / shares
    (which mixes in the quarter's buybacks and issuance). Returns (series, measure)."""
    declared = bool(rows) and rows[0].get('dps') is not None
    fac, out = 1.0, []
    for i, r in enumerate(rows):
        if i and rows[i - 1]['shares'] > 0 and r['shares'] > 0:
            ratio = r['shares'] / rows[i - 1]['shares']
            if ratio > SPLIT or 1 / ratio > SPLIT:
                fac *= ratio
        if declared:
            out.append(r['dps'] * fac)
        else:
            out.append(r['div'] / r['shares'] * fac if r['shares'] > 0 else 0.0)
    return out, 'declared' if declared else 'div/shares'


def dividends(run):
    """Per run: median payout of payers, median yield of payers (firm-years), DPS cuts, non-payers in the last year."""
    payouts, yields, cuts, cuts10, nonpay, alive = [], [], 0, 0, 0, 0
    years = run['years']
    for t, f in run['final'].items():
        q = f['q']
        for y in range(1, years + 1):
            rows = [r for r in q if y - 1 < r['t'] <= y + 1e-6]
            if len(rows) < 4:
                continue
            div, ni = sum(r['div'] for r in rows), sum(r['ni'] for r in rows)
            cap = rows[-1]['price'] * rows[-1]['shares']
            if div > 0 and ni > 0:
                payouts.append(div / ni)
            if div > 0 and cap > 0:
                yields.append(div / cap)
        adj, _ = dps_adj(q)
        for a, b in zip(adj, adj[1:]):
            if a <= 0:
                continue
            fall = b / a - 1.0
            if fall < -0.005:
                cuts += 1
            if fall < -0.10:
                cuts10 += 1
        last = [r for r in q if r['t'] > years - 1 + 1e-6]
        if len(last) >= 4 and not f['dead']:
            alive += 1
            if sum(r['div'] for r in last) <= 0:
                nonpay += 1
    return {
        'payout': st.median(payouts) if payouts else 0.0,
        'yield': st.median(yields) if yields else 0.0,
        'cuts': cuts, 'cuts10': cuts10, 'nonpay': nonpay, 'alive': alive,
    }


def distribution(run):
    out = {}
    for label, t in (('y0', None), ('y10', 10.0), ('y20', float(run['years']))):
        c = Counter()
        for tk, f in run['final'].items():
            if t is None:
                c[step(run['meta'][tk]['tpr0'])] += 1
            else:
                r = at(f['q'], t)
                if r is not None:
                    c[step(r['tpr'])] += 1
        out[label] = c
    return out


def policy_events(run):
    years, n = run['years'], len(run['meta'])
    ups = downs = flips = 0
    moved, flippers = set(), Counter()
    per_firm = Counter()
    for tk, ev in run.get('pol', {}).items():
        seq = []
        for e in ev:
            d = +1 if e['d'].startswith('Raised') else -1
            seq.append((e['t'], d))
            ups += d > 0
            downs += d < 0
        moved.add(tk)
        per_firm[tk] = len(seq)
        for (t1, d1), (t2, d2) in zip(seq, seq[1:]):
            if d1 != d2 and t2 - t1 <= FLIP_YEARS:
                flips += 1
                flippers[tk] += 1
    harvest20 = sum(1 for tk, f in run['final'].items() if f['q'] and step(f['q'][-1]['tpr']) == 'harvest')
    harvest0 = sum(1 for tk in run['meta'] if step(run['meta'][tk]['tpr0']) == 'harvest')
    return {
        'ups': ups, 'downs': downs, 'per_firm_decade': (ups + downs) / n / (years / 10.0),
        'never': n - len(moved), 'n': n, 'harvest0': harvest0, 'harvest20': harvest20,
        'flips': flips, 'flippers': flippers, 'per_firm': per_firm,
    }


def ms(xs):
    m = st.mean(xs)
    se = st.stdev(xs) / len(xs) ** 0.5 if len(xs) > 1 else 0.0
    return f'{m:8.3f} ({se:.3f})'


def main():
    a, b = sys.argv[1], sys.argv[2]
    ra, rb = load(a), load(b)
    seeds = sorted(set(ra) & set(rb))
    print(f'arms {a} {b}, seeds {seeds}, {rb[seeds[0]]["years"]}y, tpy {rb[seeds[0]]["tpy"]}')

    print(f'\n1. Policy changes in {b} (per run, mean (se) over seeds)')
    pe = {s: policy_events(rb[s]) for s in seeds}
    for k, label in (('ups', 'raises'), ('downs', 'cuts'), ('per_firm_decade', 'changes per firm per decade'),
                     ('never', 'firms never moving'), ('n', 'firms'), ('harvest0', 'firms on harvest y0'),
                     ('harvest20', 'firms on harvest y20'), ('flips', 'flip-flops within 3y')):
        print(f'  {label:30} {ms([pe[s][k] for s in seeds])}   range {min(pe[s][k] for s in seeds)}..{max(pe[s][k] for s in seeds)}')
    fl = Counter()
    pf = Counter()
    for s in seeds:
        fl.update(pe[s]['flippers'])
        pf.update(pe[s]['per_firm'])
    print('  flip-flops by firm (total over seeds): ' + (', '.join(f'{t} {n}' for t, n in fl.most_common(12)) or 'none'))
    print('  most changes by firm (total over seeds): ' + ', '.join(f'{t} {n}' for t, n in pf.most_common(10)))

    print('\n2. Policy distribution (firms, mean over seeds; base mapped to nearest step)')
    print(f'  {"":10}' + ''.join(f'{s:>10}' for s, _ in STEPS))
    for arm, runs in ((a, ra), (b, rb)):
        ds = {s: distribution(runs[s]) for s in seeds}
        for lab in ('y0', 'y10', 'y20'):
            print(f'  {arm + " " + lab:10}' + ''.join(f'{st.mean(ds[s][lab][k] for s in seeds):10.1f}' for k, _ in STEPS))

    print(f'\n3. Board dividends: {a} | {b} | paired {b}-{a} (se) t n')
    da = {s: dividends(ra[s]) for s in seeds}
    db = {s: dividends(rb[s]) for s in seeds}
    for k, label in (('payout', 'median payout of payers'), ('yield', 'median yield of payers'),
                     ('cuts', 'DPS falls >0.5% per run'), ('cuts10', 'DPS falls >10% per run'),
                     ('nonpay', 'non-payers, last year'), ('alive', 'firms alive')):
        x = {s: da[s][k] for s in seeds}
        y = {s: db[s][k] for s in seeds}
        print(f'  {label:26} {st.mean(x.values()):9.3f} | {st.mean(y.values()):9.3f} | {fmt(paired(x, y))}')


if __name__ == '__main__':
    main()
