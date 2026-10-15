#!/usr/bin/env python3
"""Per-arm table for the disclosure runs: mean over seeds [min..max], and strict/light minus founding (paired, se)."""
import glob, json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
ARMS = (os.environ.get('ARMS') or 'light,founding,strict').split(',')


def sd(x):
    return st.pstdev(x) if len(x) > 1 else float('nan')


def metrics(d):
    fy = d['n_firms'] * d['years']
    w = d['w']
    wl = [x[2] for x in w]
    rest = [abs(x[2]) for x in w if x[3] > 0]
    rj = d.get('rj', [])
    r = d['r']
    gaps = [x[3] for x in r if x[3] is not None]
    ret = [x for x in d['ret'] if x is not None]
    caps = [c for c in d.get('cap', []) if c]
    pr = [d['px_final'][t] / d['px_seed'][t] for t in d['px_final'] if d['px_seed'][t] > 0]
    return {
        "warnings (count)": len(w), "warn/firm-yr": len(w) / fy,
        'zero-react share': (sum(1 for x in w if x[3] <= 0) / len(w)) if w else float('nan'),
        'mean |warn| rest %': 100 * st.mean(rest) if rest else float('nan'),
        'sd report-day %': 100 * sd(rj),
        'sd warn-day %': 100 * sd(wl) if len(wl) > 1 else float('nan'),
        'ann var/firm-yr x1e4': 1e4 * (sum(x * x for x in rj) + sum(x * x for x in wl)) / fy,
        'bank/TA bp': 1e4 * st.mean(x[2] for x in r),
        'beat [0,1c] %': 100 * sum(1 for g in gaps if -1e-6 <= g <= 0.01 + 1e-6) / len(gaps),
        'miss [-1c,0) %': 100 * sum(1 for g in gaps if -0.01 - 1e-6 <= g < -1e-6) / len(gaps),
        'index vol %': 100 * sd(ret) * math.sqrt(d['tpy']),
        'cap end/start': caps[-1] / caps[0] if len(caps) > 1 else float('nan'),
        'max px x seed': max(pr), 'deaths': len(d['dead']),
    }


data = {}
for a in ARMS:
    for f in sorted(glob.glob(f'{H}/runs/{a}-*.json')):
        s = int(f.rsplit('-', 1)[1].split('.')[0])
        data.setdefault(a, {})[s] = metrics(json.load(open(f)))
seeds = sorted(set.intersection(*[set(v) for v in data.values()]))
keys = list(next(iter(data[ARMS[0]].values())).keys())
print(f'n seeds per arm = {len(seeds)} {seeds}')
hdr = f"{'quantity':22s}" + ''.join(f'{a:>26s}' for a in ARMS) + ''.join(f'{a + "-fnd (se)":>22s}' for a in ARMS if a != 'founding')
print(hdr)
for k in keys:
    row = f'{k:22s}'
    for a in ARMS:
        v = [data[a][s][k] for s in seeds]
        row += f'{st.mean(v):10.3f} [{min(v):6.3f}..{max(v):6.3f}]'.rjust(26)
    for a in ARMS:
        if a == 'founding' or 'founding' not in data:
            continue
        dd = [data[a][s][k] - data['founding'][s][k] for s in seeds]
        se = st.stdev(dd) / math.sqrt(len(dd)) if len(dd) > 1 and all(x == x for x in dd) else float('nan')
        row += f'{st.mean(dd):10.3f} ({se:.3f})'.rjust(22)
    print(row)
