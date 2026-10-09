#!/usr/bin/env python3
"""Compares the fund harness arms (fund_runs/<arm>-<seed>.jsonl) on the macro gate, the market's shape and the fund.

Every statistic is computed per run and averaged across seeds with its standard error. fund vs nofund are independent
samples (the fund draws its own random numbers); fund vs norebal share the fiscal path and most of the random path,
so their difference is taken per seed.
"""
import glob
import json
import math
import os
import statistics as st
import sys

H = os.environ.get('RUNS', os.path.dirname(os.path.abspath(__file__)) + '/fund_runs') + '/'


def load(arm):
    runs = {}
    for path in glob.glob(H + f'{arm}-*.jsonl'):
        with open(path) as fh:
            for line in fh:
                r = json.loads(line)
                runs[r['seed']] = r
    return runs


def acf(x, lag):
    m = st.mean(x)
    den = sum((v - m) ** 2 for v in x)
    return sum((x[i] - m) * (x[i + lag] - m) for i in range(len(x) - lag)) / den if den > 0 else 0.0


def skew(x):
    m, s = st.mean(x), st.pstdev(x)
    return sum(((v - m) / s) ** 3 for v in x) / len(x) if s > 0 else 0.0


def drawdowns(index):
    """Episodes peak -> trough -> recovery: depth and ticks to regain the peak (None if never)."""
    out, peak, peak_i, trough, in_dd = [], index[0], 0, index[0], False
    for i, v in enumerate(index):
        if v >= peak:
            if in_dd:
                out.append((1.0 - trough / peak, i - peak_i))
                in_dd = False
            peak, peak_i, trough = v, i, v
        else:
            in_dd = True
            trough = min(trough, v)
    if in_dd:
        out.append((1.0 - trough / peak, None))
    return out


def run_stats(r):
    q = r['quarters']
    gap = [row['gap'] for row in q]
    spread = [row['y10'] - row['y2'] for row in q]
    late = [row for row in q if row['t'] > r['years'] - 20]
    board = r['board']
    TPY = r['tpy']
    MONTH = TPY // 12
    logs = [math.log(board[i] / board[i - 1]) for i in range(1, len(board)) if board[i - 1] > 0 and board[i] > 0]
    monthly = [math.log(board[i] / board[i - MONTH]) for i in range(MONTH, len(board), MONTH)]
    quarterly = [math.log(board[i] / board[i - 3 * MONTH]) for i in range(3 * MONTH, len(board), 3 * MONTH)]
    dds = drawdowns(board)
    deep = [d for d in dds if d[0] > 0.20]
    recov = [d[1] / TPY for d in deep if d[1] is not None]
    s = {
        'gap_sd': st.pstdev(gap) * 100,
        'acf4': acf(gap, 4), 'acf8': acf(gap, 8), 'acf12': acf(gap, 12), 'acf16': acf(gap, 16),
        'gap_skew': skew(gap),
        'busts_pct_q': 100 * sum(1 for g in gap if g < -0.03) / len(gap),
        'booms_pct_q': 100 * sum(1 for g in gap if g > 0.03) / len(gap),
        'inverted_pct': 100 * sum(1 for x in spread if x < 0) / len(spread),
        'y10_mean': 100 * st.mean(row['y10'] for row in q),
        'debt_late': st.mean(row['debt'] for row in late),
        'board_vol_tick': st.pstdev(logs) * math.sqrt(TPY) * 100,
        'board_vol_month': st.pstdev(monthly) * math.sqrt(12) * 100,
        'max_dd': 100 * max((d[0] for d in dds), default=0.0),
        'dd20_per_decade': 10 * len(deep) / r['years'],
        'dd20_depth': 100 * st.mean(d[0] for d in deep) if deep else float('nan'),
        'dd20_recovery_y': st.mean(recov) if recov else float('nan'),
        'q_reversal': acf(quarterly, 1) if len(quarterly) > 3 else float('nan'),
        'deaths': r['deaths'],
    }
    if 'spread' in q[0]:
        s['spread_pos_pct'] = 100 * sum(1 for row in q if row['spread'] > 1e-5) / len(q)
        s['net_debt_late'] = st.mean(row['net_debt'] for row in late)
    fund = [row for row in q if row['fund_gdp'] > 0]
    if fund:
        s['fund_gdp_first'] = fund[0]['fund_gdp']
        s['fund_gdp_last'] = st.mean(row['fund_gdp'] for row in fund[-8:])
        s['own_min'] = 100 * min(row['own'] for row in fund)
        s['own_max'] = 100 * max(row['own'] for row in fund)
        s['programmes_per_decade'] = 10 * len(r['programmes']) / r['years']
        buys = [p for p in r['programmes'] if p['share'] > 0]
        s['buys_per_decade'] = 10 * len(buys) / r['years']
        s['buy_size_pct_float'] = 100 * st.mean(p['share'] for p in buys) if buys else float('nan')
        idx = [row for row in fund if row.get('ret', 0) > 0]
        if len(idx) > 8:
            span = idx[-1]['t'] - idx[0]['t']
            s['ret_nominal_pa'] = 100 * ((idx[-1]['ret'] / idx[0]['ret']) ** (1 / span) - 1)
            s['ret_real_pa'] = 100 * ((idx[-1]['ret_real'] / idx[0]['ret_real']) ** (1 / span) - 1)
            s['ret_assumed_pa'] = 100 * st.mean(row['exp_real'] for row in idx)
            s['ret_real_minus_assumed'] = s['ret_real_pa'] - s['ret_assumed_pa']
            bonds, equities = bond_quarterly_returns(idx), []
            for a, b in zip(idx, idx[1:]):
                equities.append(math.log(b['fx_eq'] / a['fx_eq']))
            s['bond_vol_pa'] = 100 * st.pstdev(bonds) * 2
            s['bond_ret_pa'] = 100 * 4 * st.mean(bonds)
            s['bond_equity_corr'] = st.correlation(bonds, equities)
    return s


# The fund's foreign curve (SovereignFundSubsystem::foreignZeroYield), to reprice its paper between recorded quarters.
BOND_DURATION, NEUTRAL, SLOPE_LAMBDA, TERM_PREMIUM, TP_HORIZON = 6.17, 0.025, 0.30, 0.0115, 10.0


def foreign_zero_yield(rate, tau):
    loading = (1 - math.exp(-SLOPE_LAMBDA * tau)) / (SLOPE_LAMBDA * tau)
    scale = (1 - math.exp(-tau / TP_HORIZON)) / (1 - math.exp(-10 / TP_HORIZON))
    return NEUTRAL + (rate - NEUTRAL) * loading + TERM_PREMIUM * scale


def bond_quarterly_returns(rows):
    """Log return of the paper over each recorded quarter: a zero bought at the duration, sold a quarter shorter."""
    out = []
    for a, b in zip(rows, rows[1:]):
        held = BOND_DURATION - (b['t'] - a['t'])
        out.append(a['bond_y'] * BOND_DURATION - foreign_zero_yield(b['fpol'], held) * held)
    return out


def summarize(runs):
    stats = {seed: run_stats(r) for seed, r in runs.items()}
    keys = sorted({k for s in stats.values() for k in s})
    out = {}
    for k in keys:
        vals = [s[k] for s in stats.values() if k in s and not math.isnan(s[k])]
        if vals:
            out[k] = (st.mean(vals), st.stdev(vals) / math.sqrt(len(vals)) if len(vals) > 1 else float('nan'), len(vals))
    return stats, out


def main():
    arms = sys.argv[1:] or ['fund', 'nofund', 'norebal', 'exec1', 'spend']
    data = {a: load(a) for a in arms}
    summaries = {a: summarize(data[a]) for a in arms if data[a]}
    keys = sorted({k for a in summaries for k in summaries[a][1]})
    print(f"{'metric':24s}" + ''.join(f"{a:>22s}" for a in summaries))
    for k in keys:
        row = f'{k:24s}'
        for a in summaries:
            v = summaries[a][1].get(k)
            row += f'{v[0]:12.3f} ±{v[1]:6.3f} n{v[2]:<2d}' if v else ' ' * 22
        print(row)

    def diff(a, b, paired):
        if a not in summaries or b not in summaries:
            return
        print(f'\n{a} - {b} ({"paired by seed" if paired else "independent"}), z = diff / se:')
        sa, sb = summaries[a][0], summaries[b][0]
        for k in keys:
            if paired:
                d = [sa[s][k] - sb[s][k] for s in sa if s in sb and k in sa[s] and k in sb[s] and not math.isnan(sa[s][k]) and not math.isnan(sb[s][k])]
                if len(d) < 3:
                    continue
                m, se = st.mean(d), st.stdev(d) / math.sqrt(len(d))
            else:
                if k not in summaries[a][1] or k not in summaries[b][1]:
                    continue
                (ma, sea, _), (mb, seb, _) = summaries[a][1][k], summaries[b][1][k]
                m, se = ma - mb, math.sqrt(sea ** 2 + seb ** 2)
            z = m / se if se > 0 else float('nan')
            flag = '  <-- |z|>2' if abs(z) > 2 else ''
            print(f'  {k:24s} {m:10.3f} ± {se:7.3f}  z {z:6.2f}{flag}')

    diff('fund', 'nofund', False)
    diff('fund', 'norebal', True)
    diff('exec1', 'fund', True)
    diff('spend', 'fund', True)
    diff('spend', 'nofund', False)


main()
