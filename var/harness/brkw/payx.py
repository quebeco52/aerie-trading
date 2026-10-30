#!/usr/bin/env python3
"""payx.py <seeds> A B: dividend path, payout stress and deal funding for BRKW, paired B - A. Final table only.

DPS is split-adjusted to the opening share count. A cut is a quarter whose DPS is below 0.98 of the previous one.
Crisis = output gap (from the board record's firm rows) below -2% in the deal quarter.
"""
import json, math, re, sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'lib'))
from stats import paired, fmt
from analyse import seeds, H

DEAL = re.compile(r'executed a \$([\d.]+)([BMT]) .*?Funded with \$([\d.]+)([BMT]) in cash(?: and \$([\d.]+)([BMT]) in new debt)?')
U = {'M': 1e-3, 'B': 1.0, 'T': 1e3}


def gap_path(arm, s):
    rec = json.loads(open(H / f'{arm}-{s}.jsonl').readline())
    best = max(rec['final'].values(), key=lambda f: len(f['q']))
    return [(r['t'], r['gap']) for r in best['q']]


def one(arm, s):
    rep, ev = [], []
    for line in open(H / f'{arm}-{s}.brkw.jsonl'):
        r = json.loads(line)
        if r['kind'] == 'rep':
            rep.append(r)
        elif r['kind'] == 'ev':
            ev.append(r)
    splits = []
    for e in ev:
        if e['ticker'] == 'BRKW' and e['type'] == 'SPLIT':
            m = re.search(r'(\d+)-for-(\d+)', e['desc'])
            if m:
                splits.append((e['t'], int(m.group(1)) / int(m.group(2))))
    adj = lambda t: math.prod(f for ts, f in splits if ts > rep[0]['t'] and ts <= t)
    dps = [r['div'] / r['sh'] * adj(r['t']) for r in rep]
    cuts = [b / a - 1 for a, b in zip(dps, dps[1:]) if a > 0 and b < 0.98 * a]
    yrs = (rep[-1]['t'] - rep[0]['t'])
    over2 = sum(1 for r in rep if r['div'] > 0 and (r['ni'] <= 0 or r['div'] > 2 * r['ni']))
    borrow = 0
    deals_t = []
    gaps = gap_path(arm, s)
    gap_at = lambda t: min(gaps, key=lambda g: abs(g[0] - t))[1]
    size, cash_f, crisis_n, crisis_cash = 0.0, 0.0, 0, 0.0
    big = 0.0
    for e in ev:
        if e['ticker'] != 'BRKW' or e['type'] == 'SPLIT':
            continue
        m = DEAL.search(e['desc'])
        if not m:
            continue
        v = float(m.group(1)) * U[m.group(2)]
        c = float(m.group(3)) * U[m.group(4)]
        deals_t.append(e['t'])
        size += v; cash_f += c; big = max(big, v)
        if gap_at(e['t']) < -0.02:
            crisis_n += 1; crisis_cash += c
    for a, b in zip(rep, rep[1:]):
        if b['debt'] > a['debt'] * 1.001 and b['div'] > b['ni'] and not any(a['t'] < t <= b['t'] for t in deals_t):
            borrow += 1
    cn = [r['cash'] / r['eq'] for r in rep if r['eq'] > 0]
    return {
        'cash_nav_mean': sum(cn) / len(cn), 'cash_nav_end': cn[-1], 'cash_nav_y5_25_max': max(cn[len(cn) // 5:]),
        'cash0_bn': rep[0]['cash'] / 1e9, 'cash_end_bn': rep[-1]['cash'] / 1e9,
        'dps0': dps[0], 'dps_end': dps[-1], 'dps_cagr': (dps[-1] / dps[0]) ** (1 / yrs) - 1 if dps[0] > 0 else float('nan'),
        'dps_cuts_n': len(cuts), 'dps_cut_worst': min(cuts) if cuts else 0.0,
        'div_gt_2ni_q': over2, 'div_gt_ni_borrow_q': borrow,
        'acq_n': len(deals_t), 'acq_total_bn': size, 'acq_max_bn': big, 'acq_cash_share': cash_f / size if size else float('nan'),
        'acq_crisis_n': crisis_n, 'acq_crisis_cash_bn': crisis_cash,
    }


def main():
    ss, A, B = seeds(sys.argv[1]), sys.argv[2], sys.argv[3]
    ra = {s: one(A, s) for s in ss}
    rb = {s: one(B, s) for s in ss}
    print(f'BRKW dividend/deal extras, {A} vs {B}, seeds {sys.argv[1]}; per seed A/B, paired B-A (se) t n')
    for k in ra[ss[0]]:
        per = '  '.join(f'{ra[s][k]:.3g}/{rb[s][k]:.3g}' for s in ss)
        print(f'{k:20s} {per:60s} {fmt(paired([ra[s][k] for s in ss], [rb[s][k] for s in ss]))}')


if __name__ == '__main__':
    main()
