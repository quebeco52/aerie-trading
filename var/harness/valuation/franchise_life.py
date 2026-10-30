#!/usr/bin/env python3
"""franchise_life.py <seeds> <arm> [<arm> ...]: how far firms' margins and returns wander from their structure, and
how much idle cash low-return firms keep, from runs/<arm>-<seed>.jsonl, with paired differences against the first arm.

Per seed, over the firms alive at the end (years 5 onward):
- om drift med / p90: median and 90th percentile over firms of |mean operating margin - seeded margin|.
- roe drift med: median over firms of |mean ROE - year-1 ROE| (ROE = 4 NI / equity a quarter).
- roe<5%: firms whose mean ROE is under 5%.
- SWAN roe / SWAN om: the hedge fund's mean ROE and operating margin.
- cash/eq med / p90: treasury over equity at the end; hoarders: firms ending with cash over 25% of equity while their
  last year's ROE is under 7%.
"""
import json, math, os, statistics as st, sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import mean_se, paired, fmt  # noqa: E402

H = os.path.dirname(os.path.abspath(__file__))


def seeds_of(arg):
    if '-' in arg:
        a, b = arg.split('-')
        return list(range(int(a), int(b) + 1))
    return [int(x) for x in arg.split(',')]


def roe(q):
    return 4.0 * q['ni'] / q['eq'] if q['eq'] > 0 else math.nan


def pct(xs, p):
    xs = sorted(xs)
    return xs[int(p * (len(xs) - 1))]


def measure(arm, seed):
    final = json.loads(open(f'{H}/runs/{arm}-{seed}.jsonl').readline())['final']
    om_drift, roe_drift, low, cash_eq, hoard = [], [], 0, [], 0
    out = {}
    for name, f in final.items():
        if f['dead'] or len(f['q']) < 24:
            continue
        later = [q for q in f['q'] if q['t'] >= 5.0]
        first = [q for q in f['q'] if q['t'] < 1.0]
        oms = [q['om'] for q in later if q['om'] is not None]
        roes = [r for r in (roe(q) for q in later) if not math.isnan(r)]
        roe1 = [r for r in (roe(q) for q in first) if not math.isnan(r)]
        if not oms or not roes or not roe1:
            continue
        om_drift.append(abs(st.mean(oms) - f['om_seed']))
        roe_drift.append(abs(st.mean(roes) - st.mean(roe1)))
        low += st.mean(roes) < 0.05
        end = f['q'][-1]
        if end['eq'] > 0:
            cash_eq.append(end['cash'] / end['eq'])
            last = [r for r in (roe(q) for q in f['q'][-4:]) if not math.isnan(r)]
            hoard += end['cash'] / end['eq'] > 0.25 and last and st.mean(last) < 0.07
        if name == 'SWAN':
            out['SWAN roe'] = st.mean(roes)
            out['SWAN om'] = st.mean(oms)
    out.update({'om drift med': st.median(om_drift), 'om drift p90': pct(om_drift, 0.9),
                'roe drift med': st.median(roe_drift), 'roe<5%': low,
                'cash/eq med': st.median(cash_eq), 'cash/eq p90': pct(cash_eq, 0.9), 'hoarders': hoard})
    return out


def main():
    seeds = seeds_of(sys.argv[1])
    arms = sys.argv[2:]
    per = {a: {s: measure(a, s) for s in seeds if os.path.exists(f'{H}/runs/{a}-{s}.jsonl')} for a in arms}
    keys = ['om drift med', 'om drift p90', 'roe drift med', 'roe<5%', 'SWAN roe', 'SWAN om', 'cash/eq med',
            'cash/eq p90', 'hoarders']
    print(f"{'measure':14s}" + ''.join(f'{a + " mean (se)":>22s}' for a in arms)
          + ''.join(f'{a + " - " + arms[0]:>34s}' for a in arms[1:]))
    for k in keys:
        line = f'{k:14s}'
        for a in arms:
            ms = mean_se([m[k] for m in per[a].values() if k in m])
            line += f'{ms.mean:+.4f} ({ms.se:.4f})'.rjust(22)
        for a in arms[1:]:
            line += f"{fmt(paired({s: m[k] for s, m in per[arms[0]].items() if k in m}, {s: m[k] for s, m in per[a].items() if k in m})):>34s}"
        print(line)
    print('SWAN roe by seed: ' + ' || '.join(
        a + ' ' + ' '.join(f"{per[a][s].get('SWAN roe', math.nan):+.3f}" for s in sorted(per[a])) for a in arms))


if __name__ == '__main__':
    main()
