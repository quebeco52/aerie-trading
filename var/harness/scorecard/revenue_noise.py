#!/usr/bin/env python3
"""revenue_noise.py <arm> [<arm> ...]: how firms' quarterly revenue moves, from runs/<arm>-s<seed>.jsonl, with paired
differences against the first arm over the seeds they share. Run from this directory. Year 1 skipped; firms with fewer
than 40 quarters left out.

Each firm's quarter-on-quarter log revenue growth has its own mean for each position in the year removed (seasonality).
Per seed, medians over firms:
- sd q/q: sd of that deseasonalised growth.
- AC1: its lag-1 autocorrelation; near zero when revenue keeps its news, -0.5 when each quarter is fresh noise.
- VR: variance of year-on-year log growth over four times the quarterly variance; 1 or more when news persists.
- sd y/y: sd of year-on-year log growth.
- growth: annual log revenue growth over the run.
Per seed, counts:
- bounces / 100 fy: quarters down more than 15% (deseasonalised) followed by one up more than 10%, per 100
  firm-years, outside the firms whose revenue is lumpy by nature (LUMPY).
- gap sd, spare > 10%: median over firms of the sd of the filed capacity gap, and the share of firm-quarters with more
  than 10% of plant spare (rows carry `cgap` and `spare` from 10-30).
"""
import json, math, os, statistics as st, sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import mean_se, paired, fmt  # noqa: E402

H = os.path.dirname(os.path.abspath(__file__))
LUMPY = {'Private Equity', 'Investment Banking', 'Hedge Fund', 'Space Systems', 'Legal Services'}
MEASURES = ['sd q/q', 'AC1', 'VR', 'sd y/y', 'growth', 'bounces / 100 fy', 'gap sd', 'spare > 10%']


def corr(a, b):
    ma, mb, sa, sb = st.fmean(a), st.fmean(b), st.pstdev(a), st.pstdev(b)
    return sum((x - ma) * (y - mb) for x, y in zip(a, b)) / len(a) / (sa * sb) if sa > 0 and sb > 0 else math.nan


def seed_measures(run):
    per, bounces, firm_years, gap_sd, spare_rows, rows = [], 0, 0.0, [], 0, 0
    for ticker, firm in run['final'].items():
        q = [x for x in firm['q'] if x['t'] >= 1.0]
        if len(q) < 40 or min(x['rev'] for x in q) <= 0:
            continue
        rev = [x['rev'] for x in q]
        g = [math.log(rev[i + 1] / rev[i]) for i in range(len(rev) - 1)]
        season = [st.fmean(g[k::4]) for k in range(4)]
        d = [x - season[i % 4] for i, x in enumerate(g)]
        yy = [math.log(rev[i + 4] / rev[i]) for i in range(len(rev) - 4)]
        per.append({
            'sd q/q': st.pstdev(d),
            'AC1': corr(d[1:], d[:-1]),
            'VR': st.pvariance(yy) / (4.0 * st.pvariance(d)) if st.pvariance(d) > 0 else math.nan,
            'sd y/y': st.pstdev(yy),
            'growth': math.log(rev[-1] / rev[0]) / (q[-1]['t'] - q[0]['t']),
        })
        if run['meta'].get(ticker, {}).get('industry') not in LUMPY:
            bounces += sum(1 for i in range(len(d) - 1) if d[i] < -0.15 and d[i + 1] > 0.10)
            firm_years += len(q) / 4.0
        if 'cgap' in q[0]:
            gap_sd.append(st.pstdev(x['cgap'] for x in q))
            spare_rows += sum(1 for x in q if x['spare'] > 0.10)
            rows += len(q)
    out = {k: st.median(x[k] for x in per if not math.isnan(x[k])) for k in ('sd q/q', 'AC1', 'VR', 'sd y/y', 'growth')}
    out['bounces / 100 fy'] = 100.0 * bounces / firm_years
    out['gap sd'] = st.median(gap_sd) if gap_sd else math.nan
    out['spare > 10%'] = spare_rows / rows if rows else math.nan
    return out


def load(arm):
    runs = {}
    for name in sorted(os.listdir(f'{H}/runs')):
        if name.startswith(f'{arm}-s') and name.endswith('.jsonl'):
            line = open(f'{H}/runs/{name}').readline()
            if line.strip():
                r = json.loads(line)
                runs[r['seed']] = seed_measures(r)
    return runs


def main():
    arms = sys.argv[1:]
    per = {a: load(a) for a in arms}
    print(f"{'measure':18s}" + ''.join(f'{a + " mean (se)":>22s}' for a in arms)
          + ''.join(f'{a + " - " + arms[0]:>34s}' for a in arms[1:]))
    for k in MEASURES:
        line = f'{k:18s}'
        for a in arms:
            m = mean_se([x[k] for x in per[a].values()])
            line += f'{m.mean:>13.3f} ({m.se:.3f})'
        for a in arms[1:]:
            line += f"{fmt(paired({s: x[k] for s, x in per[arms[0]].items()}, {s: x[k] for s, x in per[a].items()})):>34s}"
        print(line)


if __name__ == '__main__':
    main()
