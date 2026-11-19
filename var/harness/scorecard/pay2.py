"""pay2: payout policy driven by measured reinvestment, and the dividend roll, from PayoutHarnessTest runs.
Usage (from this directory): python3 pay2.py <arm> [<compare_arm>]   (rows need ref/rinv/earn/yed/streak; HEALTH=1
adds icr/head for the crisis count)
"""
import os
import statistics as st
import sys
from collections import Counter, defaultdict

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from payout import load, step, at, STEPS, SPLIT, dps_adj  # noqa: E402

TIERS = [('challenger', 5), ('contender', 10), ('champion', 25), ('king', 50)]
RANK = {s: i for i, (s, _) in enumerate(STEPS)}


def tier(streak):
    out = 'none'
    for name, y in TIERS:
        if streak is not None and streak >= y:
            out = name
    return out


def q(xs, p):
    xs = sorted(xs)
    if not xs:
        return float('nan')
    return xs[min(len(xs) - 1, int(p * len(xs)))]


def ms(xs):
    m = st.mean(xs)
    se = st.stdev(xs) / len(xs) ** 0.5 if len(xs) > 1 else 0.0
    return f'{m:7.2f} ({se:.2f})'


def per_run(run):
    years = run['years']
    o = Counter()
    trans = Counter()
    timing = Counter()
    flippers = {}
    for tk, f in run['final'].items():
        ev = run.get('pol', {}).get(tk, [])
        for e in ev:
            o['ups' if e['d'].startswith('Raised') else 'downs'] += 1
            timing['y0-3' if e['t'] < 3 else ('y3-10' if e['t'] < 10 else 'y10-20')] += 1
        if ev:
            o['moved'] += 1
        if len(ev) >= 3:
            flippers[tk] = len(ev)
        if f['q']:
            p0, p1 = step(run['meta'][tk]['tpr0']), step(f['q'][-1]['tpr'])
            trans[(p0, p1)] += 1
            o['harvest20'] += p1 == 'harvest'
            o['retain20'] += p1 == 'retain'
    o['n'] = len(run['meta'])
    return o, trans, timing, flippers


def shares(run):
    """Reference share, final share and implied shift per firm (financials flagged)."""
    out = {}
    for tk, f in run['final'].items():
        rows = f['q']
        if not rows or rows[-1].get('ref') is None:
            continue
        last = rows[-1]
        share = last['rinv'] / last['earn'] if last.get('earn') and last['earn'] > 0 else None
        out[tk] = (run['meta'][tk]['fin'], last['ref'], share, 0.5 * (last['ref'] - share) if share is not None else None)
    return out


def roll(run):
    years = run['years']
    tiers = {}
    for y in (0, 1, 5, 10, 15, 20):
        c = Counter()
        for tk, f in run['final'].items():
            if y == 0:
                s, payer = run['meta'][tk].get('st0'), True
            else:
                r = at(f['q'], y)
                if r is None or f['dead'] and f['q'][-1]['t'] < y - 0.5:
                    continue
                s, payer = r.get('streak'), r['div'] > 0
            c['firms'] += 1
            if payer:
                c['payers'] += 1
                c[tier(s)] += 1
        tiers[y] = c
    breaks15 = joins15 = exits = entries = 0
    raised, paying = Counter(), Counter()
    for tk, f in run['final'].items():
        rows = f['q']
        adj, _ = dps_adj(rows)
        for y in range(2, years + 1):
            i0 = max((i for i, r in enumerate(rows) if r['t'] <= y - 1 + 1e-6), default=None)
            i1 = max((i for i, r in enumerate(rows) if r['t'] <= y + 1e-6), default=None)
            if i0 is None or i1 is None or i1 - i0 < 3 or adj[i0] <= 0 or adj[i1] <= 0:
                continue
            paying[y] += 1
            raised[y] += adj[i1] > adj[i0] * (1 + 1e-4)
    breaks = joins = tokens = raises_listed = over_eps = crisis = crisis_firms = 0
    over_firms, crisis_list = set(), set()
    ye_breaks = mid_breaks = 0
    for tk, f in run['final'].items():
        rows = f['q']
        # The fiscal year-end phase: the row index mod 4 at which the year-end dividend record is rewritten.
        phases = Counter(i % 4 for i in range(1, len(rows)) if rows[i].get('yed') is not None and rows[i]['yed'] != rows[i - 1].get('yed'))
        phase = phases.most_common(1)[0][0] if phases else None
        for i, (a, b) in enumerate(zip(rows, rows[1:]), start=1):
            sa, sb = a.get('streak') or 0, b.get('streak') or 0
            if sa > 0 and sb < sa:
                breaks += 1
                if phase is not None and i % 4 == phase:
                    ye_breaks += 1
                else:
                    mid_breaks += 1
                exits += sa >= 5
                breaks15 += b['t'] > 15 and sa >= 5

            ta, tb = tier(sa), tier(sb)
            if tb != 'none' and tb != ta and sb > sa:
                joins += 1
                entries += ta == 'none'
                joins15 += b['t'] > 15 and ta == 'none'

            ya, yb = a.get('yed'), b.get('yed')
            split = a['shares'] > 0 and b['shares'] > 0 and (b['shares'] / a['shares'] > SPLIT or a['shares'] / b['shares'] > SPLIT)
            if ya and yb and ya > 0 and yb != ya and not split and tier(sa) != 'none' and sb > sa:
                raises_listed += 1
                if abs(yb - round(ya * 1.01, 4)) <= 1.5e-4:
                    tokens += 1
        run_len = 0
        for i in range(3, len(rows)):
            w = rows[i - 3:i + 1]
            listed = tier(rows[i].get('streak') or 0) != 'none'
            if listed and sum(r['div'] for r in w) > max(0.0, sum(r['ni'] for r in w)) and sum(r['div'] for r in w) > 0:
                run_len += 1
                if run_len == 4:
                    over_eps += 1
                    over_firms.add(tk)
            else:
                run_len = 0
            r = rows[i]
            if listed and ((r.get('icr') is not None and r['icr'] < 1.0) or r.get('head') is False):
                crisis += 1
                crisis_list.add(tk)
    return tiers, {'year-end breaks/run': ye_breaks, 'mid-year breaks/run': mid_breaks, 'breaks/y': breaks / years, 'joins/y': joins / years, 'roll exits (streak>=5 broken)/y': exits / years,
                   'roll entries (none->challenger)/y': entries / years, 'roll exits/y y15-20': breaks15 / 5,
                   'roll entries (none->challenger)/y y15-20': joins15 / 5,
                   'payers raising Q4 DPS y/y': sum(raised.values()) / max(1, sum(paying.values())),
                   'payers raising Q4 DPS y/y, y15-20': sum(raised[y] for y in range(16, 21)) / max(1, sum(paying[y] for y in range(16, 21))), 'token raises/run': tokens,
                   'listed FY raises/run': raises_listed, 'div>TTM NI 4q episodes': over_eps,
                   'listed firm-quarters in crisis/covenant': crisis}, over_firms, crisis_list


def main():
    arm = sys.argv[1]
    runs = load(arm)
    seeds = sorted(runs)
    r0 = runs[seeds[0]]
    print(f'arm {arm}, seeds {seeds}, {r0["years"]}y, tpy {r0["tpy"]}')

    print('\nA. Policy movement (per run, mean (se))')
    pr = {s: per_run(runs[s]) for s in seeds}
    for k in ('moved', 'ups', 'downs', 'harvest20', 'retain20', 'n'):
        print(f'  {k:28} {ms([pr[s][0][k] for s in seeds])}')
    for k in ('y0-3', 'y3-10', 'y10-20'):
        print(f'  changes {k:20} {ms([pr[s][2][k] for s in seeds])}')
    tr = Counter()
    fl = Counter()
    for s in seeds:
        tr.update(pr[s][1])
        for tk, n in pr[s][3].items():
            fl[f'{tk}(s{s})'] = n
    print('  transition opening -> final (firms, mean per run)')
    print(f'  {"":10}' + ''.join(f'{s:>9}' for s, _ in STEPS))
    for a, _ in STEPS:
        print(f'  {a:10}' + ''.join(f'{tr[(a, b)] / len(seeds):9.2f}' for b, _ in STEPS))
    print('  firms with >=3 changes: ' + (', '.join(f'{k} {v}' for k, v in fl.most_common()) or 'none'))

    print('\nB. Reinvestment share (net reinvestment / net income, 3y EMAs), firm-runs, p10/p50/p90')
    sh = {s: shares(runs[s]) for s in seeds}
    for lab, fin in (('operating', False), ('financial', True)):
        rows = [v for s in seeds for v in sh[s].values() if v[0] == fin]
        ref = [v[1] for v in rows]
        fin_s = [v[2] for v in rows if v[2] is not None]
        sft = [v[3] for v in rows if v[3] is not None]
        print(f'  {lab:10} n {len(rows):3}  ref {q(ref, .1):+.2f}/{q(ref, .5):+.2f}/{q(ref, .9):+.2f}'
              f'  final {q(fin_s, .1):+.2f}/{q(fin_s, .5):+.2f}/{q(fin_s, .9):+.2f}'
              f'  shift {q(sft, .1):+.3f}/{q(sft, .5):+.3f}/{q(sft, .9):+.3f}  |shift|>0.075: {sum(abs(x) > 0.075 for x in sft)}')
    byfirm = defaultdict(list)
    for s in seeds:
        for tk, v in sh[s].items():
            if v[3] is not None:
                byfirm[tk].append(v)
    mean_shift = {tk: st.mean(x[3] for x in v) for tk, v in byfirm.items()}
    print('  largest mean shifts (firm: shift, ref -> final share, opening -> final policy in seed 1):')
    for tk in sorted(mean_shift, key=lambda t: -abs(mean_shift[t]))[:10]:
        v = byfirm[tk]
        r1 = runs[seeds[0]]
        pol = f"{step(r1['meta'][tk]['tpr0'])}->{step(r1['final'][tk]['q'][-1]['tpr'])}" if r1['final'][tk]['q'] else '?'
        print(f'    {tk:5} {r1["meta"][tk]["model"][:22]:22} {mean_shift[tk]:+.3f}  ref {st.mean(x[1] for x in v):+.2f} -> {st.mean(x[2] for x in v):+.2f}  {pol}')

    print('\nC. Dividend roll: tier counts among payers (mean per run), share of payers in the three upper tiers')
    rl = {s: roll(runs[s]) for s in seeds}
    print(f'  {"year":>5}{"payers":>8}' + ''.join(f'{n:>11}' for n, _ in TIERS) + f'{"listed%":>9}{"champ+%":>9}{"3tier%":>8}{"all:list%":>10}{"3tier%":>8}{"champ+%":>8}')
    for y in (0, 1, 5, 10, 15, 20):
        c = {k: st.mean(rl[s][0][y][k] for s in seeds) for k in ['payers', 'firms'] + [n for n, _ in TIERS]}
        listed = sum(c[n] for n, _ in TIERS)
        three = listed - c['challenger']
        champ = c['champion'] + c['king']
        print(f'  {y:5}{c["payers"]:8.1f}' + ''.join(f'{c[n]:11.1f}' for n, _ in TIERS)
              + f'{100 * listed / c["payers"]:8.1f}%{100 * champ / c["payers"]:8.1f}%{100 * three / c["payers"]:7.1f}%'
              + f'{100 * listed / c["firms"]:9.1f}%{100 * three / c["firms"]:7.1f}%{100 * champ / c["firms"]:7.1f}%')
    for k in rl[seeds[0]][1]:
        print(f'  {k:40} {ms([rl[s][1][k] for s in seeds])}')
    of, cf = Counter(), Counter()
    for s in seeds:
        of.update(rl[s][2])
        cf.update(rl[s][3])
    print('  div>NI 4q firms (seeds): ' + (', '.join(f'{k} {v}' for k, v in of.most_common(10)) or 'none'))
    print('  crisis/covenant while listed (seeds): ' + (', '.join(f'{k} {v}' for k, v in cf.most_common(10)) or 'none'))


if __name__ == '__main__':
    main()
