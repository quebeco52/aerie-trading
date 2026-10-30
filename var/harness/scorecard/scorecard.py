"""Board scorecard: one line per health standard, each scored against a range a real economy spans, with the firms that
break it most often across seeds. Usage (from this directory): python3 scorecard.py <arm> [<arm_b>]

A second arm prints both side by side with the paired difference over the seeds they share.
"""
import glob
import json
import math
import statistics as st
import sys
from collections import Counter

LOW_GRADES = {'CCC', 'CC', 'C', 'D', 'SD'}


def load(arm):
    runs = {}
    for path in sorted(glob.glob(f'runs/{arm}-s*.jsonl')):
        with open(path) as f:
            line = f.readline()
        if line.strip():
            r = json.loads(line)
            runs[r['seed']] = r
    return runs


def gdp_at(run, t):
    """Nominal GDP in dollars a year at time t: the opening level times the index's growth."""
    macro = run['macro']
    base = macro[0][1] if macro else 1.0
    idx = base
    for row in macro:
        if row[0] <= t + 1e-9:
            idx = row[1]
    return run['gdp_usd'] * idx / base


SPLIT_RATIO = 1.4


def split_adjusted(q):
    """Prices restated for share splits: a quarter whose share count jumps by a split's ratio scales every later price
    by it, so a 2-for-1 split is not read as a halving. Buybacks and issues move shares by far less."""
    factor, out = 1.0, []
    for i, r in enumerate(q):
        if i > 0 and q[i - 1].get('shares', 0) > 0:
            ratio = r.get('shares', 0) / q[i - 1]['shares']
            if ratio > SPLIT_RATIO or (ratio > 0 and 1 / ratio > SPLIT_RATIO):
                factor *= ratio
        out.append(r['price'] * factor)
    return out


def max_drawdown(prices):
    peak, worst = 0.0, 0.0
    for p in prices:
        if p > 0:
            peak = max(peak, p)
            worst = min(worst, p / peak - 1.0)
    return worst


def score(run):
    """Per-run standards, and per firm the standards it breaks."""
    meta, final = run['meta'], run['final']
    years = run['years']
    offenders = Counter()
    out = {}

    out['deaths'] = len(run['dead'])
    out['reorgs'] = len(run['reorgs'])
    out['defaulters'] = len(run['defaults'])
    for t in run['dead']:
        offenders[(t, 'failed')] += 1

    share_peak, share_change, margin_sd, gw_eq, dd, pb_extreme, low_grade, neg_eq = [], [], [], [], [], 0, 0, 0
    firm_quarters = 0
    for t, f in final.items():
        q = [r for r in f['q'] if r.get('rev', 0) > 0]
        if len(q) < 8:
            continue
        fin = meta.get(t, {}).get('fin', False)
        # Size: trailing-year revenue over GDP.
        shares = []
        for i in range(3, len(q)):
            ttm = sum(r['rev'] for r in q[i - 3:i + 1])
            shares.append((q[i]['t'], ttm / gdp_at(run, q[i]['t'])))
        if shares:
            peak = max(s for _, s in shares)
            share_peak.append(peak)
            if peak > 0.15:
                offenders[(t, 'revenue >15% of GDP')] += 1
            change = math.log(shares[-1][1] / shares[0][1]) if shares[0][1] > 0 and shares[-1][1] > 0 else 0.0
            share_change.append(change)
            if abs(change) > math.log(3):
                offenders[(t, 'share of GDP x3 or /3')] += 1
        # Margin stability (operating firms; a lender's margin is not its risk).
        if not fin:
            sd = st.pstdev([r['om'] for r in q])
            margin_sd.append(sd)
            if sd > 0.10:
                offenders[(t, 'margin sd >10pp')] += 1
        # Goodwill against equity at its peak over the run, so a spree written off before the end still counts.
        peak_gw = max((r['gw'] / r['eq'] for r in q if r['eq'] > 0), default=0.0)
        gw_eq.append(peak_gw)
        if peak_gw > 1.0:
            offenders[(t, 'goodwill > equity')] += 1
        for r in q:
            firm_quarters += 1
            if r['eq'] <= 0:
                neg_eq += 1
            if r.get('rating') in LOW_GRADES:
                low_grade += 1
            bvps = r['eq'] / r['shares'] if r.get('shares') else 0.0
            if bvps > 0 and r['price'] > 0 and (r['price'] / bvps > 10 or r['price'] / bvps < 0.3):
                pb_extreme += 1
        # Price: worst drawdown over the run.
        d = max_drawdown(split_adjusted(q))
        dd.append(d)
        if d < -0.80:
            offenders[(t, 'drawdown >80%')] += 1

    def pct(v, p):
        v = sorted(v)
        return v[min(len(v) - 1, int(p * len(v)))] if v else float('nan')

    macro = run['macro']
    out['cap_gdp_open'] = macro[0][2] / gdp_at(run, macro[0][0]) if macro else float('nan')
    out['cap_gdp_end'] = macro[-1][2] / gdp_at(run, macro[-1][0]) if macro else float('nan')
    out['largest_rev_gdp'] = max(share_peak) if share_peak else float('nan')
    out['firms_over_15pct_gdp'] = sum(1 for s in share_peak if s > 0.15)
    out['share_change_p5'] = pct(share_change, 0.05)
    out['share_change_p95'] = pct(share_change, 0.95)
    out['firms_share_x3'] = sum(1 for c in share_change if abs(c) > math.log(3))
    out['margin_sd_median'] = st.median(margin_sd) if margin_sd else float('nan')
    out['firms_margin_sd_10pp'] = sum(1 for s in margin_sd if s > 0.10)
    out['gw_eq_p90'] = pct(gw_eq, 0.90)
    out['firms_gw_over_eq'] = sum(1 for g in gw_eq if g > 1.0)
    out['dd_median'] = st.median(dd) if dd else float('nan')
    out['firms_dd_80'] = sum(1 for d in dd if d < -0.80)
    out['pb_extreme_share'] = pb_extreme / max(1, firm_quarters)
    out['low_grade_share'] = low_grade / max(1, firm_quarters)
    out['neg_equity_share'] = neg_eq / max(1, firm_quarters)
    out['years'] = years
    return out, offenders


# Each standard: label, key, format, and the range a real economy spans (None where there is no one-sided bound).
STANDARDS = [
    ('firm failures (deaths)', 'deaths', '{:.1f}', (0, 2)),
    ('reorganisations', 'reorgs', '{:.1f}', (0, 3)),
    ('firms ever in payment default', 'defaulters', '{:.1f}', (0, 4)),
    ('board cap / GDP at open', 'cap_gdp_open', '{:.2f}', (1.0, 5.0)),
    ('board cap / GDP at end', 'cap_gdp_end', '{:.2f}', (1.0, 5.0)),
    ('largest firm revenue / GDP', 'largest_rev_gdp', '{:.2f}', (0.0, 0.15)),
    ('firms above 15% of GDP', 'firms_over_15pct_gdp', '{:.1f}', (0, 0)),
    ('share of GDP change p5 (log)', 'share_change_p5', '{:+.2f}', (-1.1, 0.0)),
    ('share of GDP change p95 (log)', 'share_change_p95', '{:+.2f}', (0.0, 1.1)),
    ('firms whose share moved x3', 'firms_share_x3', '{:.1f}', (0, 5)),
    ('median margin sd (operating)', 'margin_sd_median', '{:.3f}', (0.0, 0.05)),
    ('firms with margin sd >10pp', 'firms_margin_sd_10pp', '{:.1f}', (0, 3)),
    ('peak goodwill / equity p90', 'gw_eq_p90', '{:.2f}', (0.0, 1.0)),
    ('firms with goodwill > equity', 'firms_gw_over_eq', '{:.1f}', (0, 3)),
    ('median worst drawdown', 'dd_median', '{:+.2f}', (-0.70, -0.30)),
    ('firms with drawdown >80%', 'firms_dd_80', '{:.1f}', (0, 10)),
    ('firm-quarters P/B >10 or <0.3', 'pb_extreme_share', '{:.3f}', (0.0, 0.03)),
    ('firm-quarters rated CCC or below', 'low_grade_share', '{:.3f}', (0.0, 0.03)),
    ('firm-quarters with negative equity', 'neg_equity_share', '{:.3f}', (0.0, 0.005)),
]


def summarise(runs):
    scores, offenders = {}, Counter()
    for seed, run in runs.items():
        s, o = score(run)
        scores[seed] = s
        offenders.update(o)
    return scores, offenders


def main():
    arms = sys.argv[1:] or ['base']
    data = [summarise(load(a)) for a in arms]
    seeds = sorted(set.intersection(*[set(d[0]) for d in data]))
    print(f"arms {arms}, seeds {seeds}, {data[0][0][seeds[0]]['years']}y each")
    header = f"{'standard':38} {'range':>13} " + ' '.join(f"{a:>17}" for a in arms) + ('   paired diff (t)' if len(arms) == 2 else '')
    print(header)
    for label, key, fmt, (lo, hi) in STANDARDS:
        cells = []
        means = []
        for scores, _ in data:
            v = [scores[s][key] for s in seeds]
            m = st.mean(v)
            means.append(v)
            flag = '' if lo <= m <= hi else ' !'
            cells.append(f"{fmt.format(m):>9} ±{st.stdev(v) / math.sqrt(len(v)) if len(v) > 1 else 0:5.2f}{flag:2}")
        line = f"{label:38} {fmt.format(lo) + '..' + fmt.format(hi):>13} " + ' '.join(cells)
        if len(arms) == 2:
            d = [b - a for a, b in zip(*means)]
            se = st.stdev(d) / math.sqrt(len(d)) if len(d) > 1 else 0.0
            line += f"   {st.mean(d):+.3f} ({st.mean(d) / se:+.1f})" if se > 0 else f"   {st.mean(d):+.3f}"
        print(line)
    for arm, (_, offenders) in zip(arms, data):
        print(f"\n{arm}: firms breaking a standard, in how many of {len(seeds)} seeds")
        by_rule = {}
        for (t, rule), n in offenders.most_common():
            by_rule.setdefault(rule, []).append(f"{t} {n}")
        for rule, firms in by_rule.items():
            print(f"  {rule:24} {', '.join(firms[:14])}")


if __name__ == '__main__':
    main()
