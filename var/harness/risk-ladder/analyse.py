"""Compare two arms across seeds x 20y on board scorecard standards, risk-ladder and relever-volatility gates."""
import glob
import json
import math
import os
import statistics as st
import sys
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
SCORECARD_DIR = ROOT / 'var/harness/scorecard'
LIB_DIR = ROOT / 'var/harness/lib'
sys.path.insert(0, str(SCORECARD_DIR))
sys.path.insert(0, str(LIB_DIR))

import scorecard as sc
from stats import paired, mean_se, fmt

SPECULATIVE_GRADES = {'BB', 'B', 'CCC', 'CC', 'C', 'D', 'SD'}
MAX_VOLATILITY_CEILING = 1.50


def load(arm):
    runs = {}
    for path in sorted(glob.glob(str(Path(__file__).parent / f'runs/{arm}-s*.jsonl'))):
        with open(path) as f:
            line = f.readline()
        if line.strip():
            r = json.loads(line)
            runs[r['seed']] = r
    return runs


def avg_ranks(vals):
    order = sorted(range(len(vals)), key=lambda i: vals[i])
    ranks = [0.0] * len(vals)
    i = 0
    while i < len(vals):
        j = i
        while j + 1 < len(vals) and vals[order[j + 1]] == vals[order[i]]:
            j += 1
        r = 0.5 * (i + j) + 1.0
        for k in range(i, j + 1):
            ranks[order[k]] = r
        i = j + 1
    return ranks


def spearman(x, y):
    rx, ry = avg_ranks(x), avg_ranks(y)
    mx, my = sum(rx) / len(rx), sum(ry) / len(ry)
    num = sum((a - mx) * (b - my) for a, b in zip(rx, ry))
    den = math.sqrt(sum((a - mx) ** 2 for a in rx) * sum((b - my) ** 2 for b in ry))
    return num / den if den > 0 else float('nan')


def extra_metrics(run, biz_step_rank):
    rvols = {t: v for t, v in run['rvol'].items() if v is not None and not run['final'][t]['dead']}
    sv = sorted(rvols.values())
    med_rv = st.median(sv)
    p10_rv = sv[int(0.10 * len(sv))]
    p90_rv = sv[min(len(sv) - 1, int(0.90 * len(sv)))]

    common = [t for t in rvols if t in biz_step_rank]
    rho_step = spearman([rvols[t] for t in common], [biz_step_rank[t] for t in common])

    misp_gt = sum(m['gt25'] for m in run['misp'].values())
    misp_n = sum(m['n'] for m in run['misp'].values())
    misp_share = misp_gt / max(1, misp_n)

    icr_lt1 = 0
    icr_n = 0
    ratings = Counter()
    beats = 0
    eps_n = 0
    rev_surps = []
    rev_jumps_gt10 = 0
    rev_q_n = 0
    bb_years_by_ticker = defaultdict(set)

    for t, f in run['final'].items():
        for r in f['q']:
            if r.get('rev', 0) <= 0:
                continue
            rating = r.get('rating', '?')
            ratings[rating] += 1
            if rating in SPECULATIVE_GRADES and 't' in r:
                bb_years_by_ticker[t].add(int(math.floor(max(0.0, r['t'] - 1e-9))))
            if 'icr' in r and r['icr'] is not None:
                icr_n += 1
                if r['icr'] < 1.0:
                    icr_lt1 += 1
            if r.get('reps') is not None and r.get('ceps') is not None:
                eps_n += 1
                if r['reps'] >= r['ceps'] - 1e-9:
                    beats += 1
            exp_rev = r.get('crev') or r.get('exp') or 0.0
            if exp_rev > 0.0:
                rev_q_n += 1
                surp = (r['rev'] / exp_rev) - 1.0
                rev_surps.append(surp)
                if abs(surp) > 0.10:
                    rev_jumps_gt10 += 1

    # Re-levering metrics (when 'yearly' is present)
    tercile_ratios = []
    slope_num = 0.0
    slope_den = 0.0
    mover_firms = {}
    bb_vol_ratios = []
    bb_vol_ratios_up = []
    bb_vol_ratios_dn = []
    fy_sig_gt06_mean = 0
    fy_sig_gt06_max = 0
    max_sig_lr = 0.0
    max_cvol = 0.0
    hit_max_vol_firms = []

    yearly = run.get('yearly') or {}
    tpy = run.get('tpy', 360)
    min_year_ticks = tpy // 2

    for t, ymap in yearly.items():
        meta = run['meta'].get(t, {})
        open_vol = meta.get('open_vol') or run['final'][t].get('vol') or 0.0
        is_fin = bool(meta.get('fin', False))

        full_years = []
        firm_hit_max = False
        items = list(enumerate(ymap)) if isinstance(ymap, list) else sorted(((int(k), v) for k, v in ymap.items()), key=lambda kv: kv[0])
        l_open = items[0][1]['l'] if items else 1.0
        for y, yd in items:
            if yd['sig'] > 0.6:
                fy_sig_gt06_mean += 1
            if yd['sig_max'] > 0.6:
                fy_sig_gt06_max += 1
            if yd['sig_max'] > max_sig_lr:
                max_sig_lr = yd['sig_max']
            if yd['cvol_max'] > max_cvol:
                max_cvol = yd['cvol_max']
            if yd['cvol_max'] >= MAX_VOLATILITY_CEILING - 1e-4:
                firm_hit_max = True
            if yd['n'] < min_year_ticks or yd['rv'] <= 0.0 or yd['l'] <= 0.0:
                continue
            full_years.append((y, yd))
            if y in bb_years_by_ticker[t] and open_vol > 0.0:
                ratio = yd['rv'] / open_vol
                bb_vol_ratios.append(ratio)
                if yd['l'] > l_open * 1.05:
                    bb_vol_ratios_up.append(ratio)
                elif yd['l'] < l_open * 0.95:
                    bb_vol_ratios_dn.append(ratio)

        if firm_hit_max:
            hit_max_vol_firms.append(t)

        if is_fin or len(full_years) < 3:
            continue

        by_l = sorted(full_years, key=lambda item: item[1]['l'])
        k = max(1, len(by_l) // 3)
        bot_rv = st.mean(item[1]['rv'] for item in by_l[:k])
        top_rv = st.mean(item[1]['rv'] for item in by_l[-k:])
        if bot_rv > 0.0:
            tercile_ratios.append(top_rv / bot_rv)

        l_min_run = min(item[1]['l_min'] for item in full_years)
        l_max_run = max(item[1]['l_max'] for item in full_years)
        if l_min_run > 0.0 and (l_max_run / l_min_run) > 1.25:
            xs = [math.log(item[1]['l']) for item in full_years]
            ys = [math.log(item[1]['rv']) for item in full_years]
            mx, my = st.mean(xs), st.mean(ys)
            for x, y_val in zip(xs, ys):
                slope_num += (x - mx) * (y_val - my)
                slope_den += (x - mx) ** 2
            lo_y, lo_d = by_l[0]
            hi_y, hi_d = by_l[-1]
            mover_firms[t] = {
                'l_span': l_max_run / l_min_run,
                'lo_l': lo_d['l'],
                'lo_rv': lo_d['rv'],
                'hi_l': hi_d['l'],
                'hi_rv': hi_d['rv'],
                'open_vol': open_vol,
            }

    pooled_slope = (slope_num / slope_den) if slope_den > 0.0 else float('nan')

    defaults_total = sum(run.get('defaults', {}).values()) if isinstance(run.get('defaults'), dict) else len(run.get('defaults', []))
    reorgs_total = len(run.get('reorgs', []))

    return {
        'rv_med': med_rv,
        'rv_p10': p10_rv,
        'rv_p90': p90_rv,
        'rv_step_rho': rho_step,
        'misp_gt25': misp_share,
        'icr_lt1_qtrs': icr_lt1,
        'icr_lt1_share': icr_lt1 / max(1, icr_n),
        'eps_beat_rate': beats / max(1, eps_n),
        'rev_surp_sd': st.pstdev(rev_surps) if rev_surps else float('nan'),
        'rev_gt10_qtrs': rev_jumps_gt10,
        'rev_gt10_share': rev_jumps_gt10 / max(1, rev_q_n),
        'ratings': ratings,
        'defaults_qtrs': defaults_total,
        'reorgs': reorgs_total,
        'tercile_ratio_med': st.median(tercile_ratios) if tercile_ratios else float('nan'),
        'pooled_log_slope': pooled_slope,
        'mover_count': len(mover_firms),
        'mover_firms': mover_firms,
        'bb_rv_over_open_med': st.median(bb_vol_ratios) if bb_vol_ratios else float('nan'),
        'bb_rv_over_open_mean': st.mean(bb_vol_ratios) if bb_vol_ratios else float('nan'),
        'bb_rv_over_open_up': st.median(bb_vol_ratios_up) if bb_vol_ratios_up else float('nan'),
        'bb_rv_over_open_dn': st.median(bb_vol_ratios_dn) if bb_vol_ratios_dn else float('nan'),
        'bb_fy_n': len(bb_vol_ratios),
        'fy_sig_gt06_mean': fy_sig_gt06_mean,
        'fy_sig_gt06_max': fy_sig_gt06_max,
        'max_sig_lr': max_sig_lr,
        'max_cvol': max_cvol,
        'hit_max_vol_firms': hit_max_vol_firms,
    }


def main():
    arms = sys.argv[1:] or ['head', 'new']
    step_ranks = json.loads(os.popen(
        'bin/php-slot php -r \'require "tests/bootstrap.php"; $out=[]; foreach (App\\Data\\Company\\InitialMarket::STOCKS as $r) $out[$r["ticker"]] = App\\Data\\Company\\IndustryRisks::businessForRow($r)->rank(); echo json_encode($out);\''
    ).read())

    loaded = [load(a) for a in arms]
    seeds = sorted(set.intersection(*[set(d.keys()) for d in loaded]))
    print(f'Arms: {arms}, seeds: {seeds}')

    # 1. Standard board scorecard
    scores = []
    offenders_all = []
    for runs in loaded:
        s_map = {}
        off = Counter()
        for s in seeds:
            sc_s, o = sc.score(runs[s])
            s_map[s] = sc_s
            off.update(o)
        scores.append(s_map)
        offenders_all.append(off)

    print('\n=== BOARD SCORECARD ===')
    header = f"{'standard':38} {'range':>13} " + ' '.join(f"{a:>17}" for a in arms) + ('   paired diff (t)' if len(arms) == 2 else '')
    print(header)
    for label, key, fstr, (lo, hi) in sc.STANDARDS:
        cells, vals = [], []
        for s_map in scores:
            v = [s_map[s][key] for s in seeds]
            vals.append(v)
            m = st.mean(v)
            se = st.stdev(v) / math.sqrt(len(v)) if len(v) > 1 else 0.0
            flag = '' if lo <= m <= hi else ' !'
            cells.append(f"{fstr.format(m):>9} ±{se:5.2f}{flag:2}")
        line = f"{label:38} {fstr.format(lo) + '..' + fstr.format(hi):>13} " + ' '.join(cells)
        if len(arms) == 2:
            d = [b - a for a, b in zip(*vals)]
            se = st.stdev(d) / math.sqrt(len(d)) if len(d) > 1 else 0.0
            line += f"   {st.mean(d):+.3f} ({st.mean(d) / se:+.1f})" if se > 0 else f"   {st.mean(d):+.3f}"
        print(line)

    # 2. Extra gates
    extras = [{s: extra_metrics(runs[s], step_ranks) for s in seeds} for runs in loaded]
    print('\n=== RISK-LADDER & RELEVER-VOLATILITY GATES ===')
    gate_specs = [
        ('realised vol median', 'rv_med', '{:.4f}'),
        ('realised vol p10', 'rv_p10', '{:.4f}'),
        ('realised vol p90', 'rv_p90', '{:.4f}'),
        ('Spearman(rvol, BusinessRisk step)', 'rv_step_rho', '{:+.3f}'),
        ('within-firm top/bot tercile L rv med', 'tercile_ratio_med', '{:.4f}'),
        ('pooled slope log(rv) on log(L) (>1.25)', 'pooled_log_slope', '{:+.4f}'),
        ('qualifying L_max/L_min > 1.25 firms', 'mover_count', '{:.1f}'),
        ('BB-or-below fy rv / open_vol (med)', 'bb_rv_over_open_med', '{:.4f}'),
        ('BB-or-below fy rv / open_vol (mean)', 'bb_rv_over_open_mean', '{:.4f}'),
        ('  BB-or-below L > 1.05*L0 (med)', 'bb_rv_over_open_up', '{:.4f}'),
        ('  BB-or-below L < 0.95*L0 (med)', 'bb_rv_over_open_dn', '{:.4f}'),
        ('BB-or-below firm-years / run', 'bb_fy_n', '{:.1f}'),
        ('firm-years mean sigma_LR > 0.6', 'fy_sig_gt06_mean', '{:.1f}'),
        ('firm-years peak sigma_LR > 0.6', 'fy_sig_gt06_max', '{:.1f}'),
        ('peak sigma_LR across run', 'max_sig_lr', '{:.4f}'),
        ('peak spot cvol across run', 'max_cvol', '{:.4f}'),
        ('payment default firm-quarters / run', 'defaults_qtrs', '{:.2f}'),
        ('reorganisations / run', 'reorgs', '{:.2f}'),
        ('icr < 1 firm-quarters / run', 'icr_lt1_qtrs', '{:.1f}'),
        ('mispricing |log(px/fv)| > 0.25 share', 'misp_gt25', '{:.4f}'),
        ('EPS beat rate', 'eps_beat_rate', '{:.4f}'),
        ('quarterly rev surprise SD', 'rev_surp_sd', '{:.4f}'),
        ('quarters |rev surp| > 10% share', 'rev_gt10_share', '{:.4f}'),
    ]
    for label, key, fstr in gate_specs:
        vals = [[ex[s][key] for s in seeds] for ex in extras]
        cells = []
        for v in vals:
            clean = [x for x in v if not math.isnan(x)]
            if not clean:
                cells.append(f"{'n/a':>17}")
                continue
            m = st.mean(clean)
            se = st.stdev(clean) / math.sqrt(len(clean)) if len(clean) > 1 else 0.0
            cells.append(f"{fstr.format(m):>9} ±{se:6.4f}")
        line = f"{label:40} " + ' '.join(cells)
        if len(arms) == 2 and all(not math.isnan(x) for x in vals[0] + vals[1]):
            p = paired(vals[0], vals[1])
            line += f"   {fmt(p)}"
        print(line)

    # Check MAX_VOLATILITY hits
    for arm, ex in zip(arms, extras):
        hits = {s: ex[s]['hit_max_vol_firms'] for s in seeds if ex[s]['hit_max_vol_firms']}
        print(f"  {arm:10s} firms reaching MAX_VOLATILITY (1.50): {hits if hits else 'none'}")

    # 3. Per-firm table for L_max/L_min > 1.25 movers
    if len(arms) == 2:
        movers_union = sorted({t for idx in (0, 1) for s in seeds for t in extras[idx][s]['mover_firms']})
        if movers_union:
            print(f'\n=== FIRMS WITH L_max/L_min > 1.25 ({len(movers_union)} firms across {len(seeds)} seeds) ===')
            print(f"{'Ticker':6s} {'seeds(H/N)':>10s} {'openV':>6s} | {'H loL':>5s} {'H loRV':>6s} {'H hiL':>5s} {'H hiRV':>6s} {'H hi/lo':>7s} | {'N loL':>5s} {'N loRV':>6s} {'N hiL':>5s} {'N hiRV':>6s} {'N hi/lo':>7s}")
            for t in movers_union:
                h_rows = [extras[0][s]['mover_firms'][t] for s in seeds if t in extras[0][s]['mover_firms']]
                n_rows = [extras[1][s]['mover_firms'][t] for s in seeds if t in extras[1][s]['mover_firms']]
                open_v = (h_rows or n_rows)[0]['open_vol']

                def fmt_side(rows):
                    if not rows:
                        return f"{'—':>5s} {'—':>6s} {'—':>5s} {'—':>6s} {'—':>7s}"
                    lo_l = st.mean(r['lo_l'] for r in rows)
                    lo_rv = st.mean(r['lo_rv'] for r in rows)
                    hi_l = st.mean(r['hi_l'] for r in rows)
                    hi_rv = st.mean(r['hi_rv'] for r in rows)
                    return f"{lo_l:5.2f} {lo_rv:6.3f} {hi_l:5.2f} {hi_rv:6.3f} {hi_rv/lo_rv:7.2f}"

                print(f"{t:6s} {f'{len(h_rows)}/{len(n_rows)}':>10s} {open_v:6.3f} | {fmt_side(h_rows)} | {fmt_side(n_rows)}")

    # 4. Ratings distribution
    print('\n=== RATINGS DISTRIBUTION (% of firm-quarters) ===')
    grades = ['AAA', 'AA', 'A', 'BBB', 'BB', 'B', 'CCC', 'CC', 'C', 'D', 'SD']
    for arm, ex in zip(arms, extras):
        tot = Counter()
        for s in seeds:
            tot.update(ex[s]['ratings'])
        n = sum(tot.values())
        parts = [f"{g}:{100*tot[g]/n:4.1f}%" for g in grades if tot[g] > 0]
        print(f"  {arm:10s}: " + '  '.join(parts))


if __name__ == '__main__':
    main()
