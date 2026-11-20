"""Compare HEAD vs new risk-ladder arm across 4 seeds x 20y on all scorecard standards and plan gates."""
import glob
import json
import math
import os
import statistics as st
import sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
SCORECARD_DIR = ROOT / 'var/harness/scorecard'
LIB_DIR = ROOT / 'var/harness/lib'
sys.path.insert(0, str(SCORECARD_DIR))
sys.path.insert(0, str(LIB_DIR))

import scorecard as sc
from stats import paired, mean_se, fmt


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

    for t, f in run['final'].items():
        for r in f['q']:
            if r.get('rev', 0) <= 0:
                continue
            ratings[r.get('rating', '?')] += 1
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
    }


def main():
    arms = sys.argv[1:] or ['head', 'new']
    # Load step ranks from step0_after.json (or via PHP)
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
    print('\n=== RISK-LADDER GATES ===')
    gate_specs = [
        ('realised vol median', 'rv_med', '{:.4f}'),
        ('realised vol p10', 'rv_p10', '{:.4f}'),
        ('realised vol p90', 'rv_p90', '{:.4f}'),
        ('Spearman(rvol, BusinessRisk step)', 'rv_step_rho', '{:+.3f}'),
        ('mispricing |log(px/fv)| > 0.25 share', 'misp_gt25', '{:.4f}'),
        ('icr < 1 firm-quarters / run', 'icr_lt1_qtrs', '{:.1f}'),
        ('EPS beat rate', 'eps_beat_rate', '{:.4f}'),
        ('quarterly rev surprise SD', 'rev_surp_sd', '{:.4f}'),
        ('quarters |rev surp| > 10% / run', 'rev_gt10_qtrs', '{:.1f}'),
        ('quarters |rev surp| > 10% share', 'rev_gt10_share', '{:.4f}'),
    ]
    for label, key, fstr in gate_specs:
        vals = [[ex[s][key] for s in seeds] for ex in extras]
        cells = []
        for v in vals:
            m = st.mean(v)
            se = st.stdev(v) / math.sqrt(len(v)) if len(v) > 1 else 0.0
            cells.append(f"{fstr.format(m):>9} ±{se:6.4f}")
        line = f"{label:38} " + ' '.join(cells)
        if len(arms) == 2:
            p = paired(vals[0], vals[1])
            line += f"   {fmt(p)}"
        print(line)

    # 3. Ratings distribution
    print('\n=== RATINGS DISTRIBUTION (% of firm-quarters) ===')
    grades = ['AAA', 'AA', 'A', 'BBB', 'BB', 'B', 'CCC', 'CC', 'C', 'D', 'SD']
    for arm, ex in zip(arms, extras):
        tot = Counter()
        for s in seeds:
            tot.update(ex[s]['ratings'])
        n = sum(tot.values())
        parts = [f"{g}:{100*tot[g]/n:4.1f}%" for g in grades if tot[g] > 0]
        print(f"  {arm:6s}: " + '  '.join(parts))


if __name__ == '__main__':
    main()
