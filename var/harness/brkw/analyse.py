#!/usr/bin/env python3
"""analyse.py <seeds> A B: BRKW per seed and paired B - A, plus board sanity. Prints the final table only."""
import json, math, re, sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'lib'))
from stats import paired, fmt

H = Path(__file__).resolve().parent / 'runs'


def seeds(spec):
    out = []
    for part in spec.split(','):
        if '-' in part:
            a, b = part.split('-'); out += range(int(a), int(b) + 1)
        else:
            out.append(int(part))
    return out


def brkw(arm, s):
    q, rep, ev = [], [], []
    for line in open(H / f'{arm}-{s}.brkw.jsonl'):
        r = json.loads(line)
        {'q': q, 'rep': rep, 'ev': ev}.get(r['kind'], []).append(r)
    splits = []
    for e in ev:
        if e['ticker'] == 'BRKW' and e['type'] == 'SPLIT':
            m = re.search(r'(\d+)-for-(\d+)', e['desc'])
            if m:
                splits.append((e['t'], int(m.group(1)) / int(m.group(2))))
    t0 = rep[0]['t']
    fac = math.prod(f for t, f in splits if t > t0)
    pb = [r['px'] * r['sh'] / r['eq'] for r in q if r['eq'] > 0]
    lb = [r['lbeta'] for r in q]
    pa = [r['px'] / r['pfv'] for r in q if r['pfv'] > 0]
    eva = [r['eva'] for r in rep]
    ni = sum(r['ni'] for r in rep)
    pay = sum(r['div'] + r['bb'] for r in rep)
    bbhi = sum(r['bb'] for r in rep if r['eq'] > 0 and r['px'] * r['sh'] / r['eq'] > 1.0)
    ms = [r['marked_share'] for r in q if r.get('marked_share') is not None]
    return {
        'beta_mean': sum(lb) / len(lb), 'beta_max': max(lb), 'beta_end': lb[-1],
        'pb_mean': sum(pb) / len(pb), 'pb_min': min(pb), 'pb_max': max(pb), 'pb_end': pb[-1],
        'p_anchor_mean': sum(pa) / len(pa), 'p_anchor_end': pa[-1],
        'roic_filed': sum(r['roic_rep'] for r in rep) / len(rep),
        'roicTtm': sum(r['roicTtm'] for r in q) / len(q),
        'coe_mean': sum(r['coe'] for r in q) / len(q),
        'eva_neg_share': sum(1 for e in eva if e < 0) / len(eva), 'eva_mean_bn': sum(eva) / len(eva) / 1e9,
        'payout': pay / ni if ni else float('nan'),
        'debt0_bn': rep[0]['debt'] / 1e9, 'debt_end_bn': rep[-1]['debt'] / 1e9,
        'cash0_bn': rep[0]['cash'] / 1e9, 'cash_end_bn': rep[-1]['cash'] / 1e9,
        'nav_end_bn': rep[-1]['eq'] / 1e9,
        'shares_x': rep[-1]['sh'] / rep[0]['sh'] / fac,
        'divest': sum(1 for e in ev if e['ticker'] == 'BRKW' and e['type'] == 'DIVESTITURE'),
        'acq': sum(1 for e in ev if e['ticker'] == 'BRKW' and ' executed a $' in e['desc'] and e['type'] != 'SPLIT'),
        'bb_pb_gt1_bn': bbhi / 1e9,
        'marked_share': sum(ms) / len(ms) if ms else float('nan'),
        'board_divest': sum(1 for e in ev if e['type'] == 'DIVESTITURE'),
        **extras(q, rep, ev, ni),
    }


def extras(q, rep, ev, ni):
    """Payout split, buyback firing, cash pile-up and debt rises with no BRKW deal in the quarter."""
    pbr = lambda r: r['px'] * r['sh'] / r['eq'] if r['eq'] > 0 else float('nan')
    fired = [pbr(r) for r in rep if r['bb'] > 0]
    fired.sort()
    deals = [e['t'] for e in ev if e['ticker'] == 'BRKW' and ' executed a $' in e['desc'] and e['type'] != 'SPLIT']
    up_n, up_bn = 0, 0.0
    for a, b in zip(rep, rep[1:]):
        if b['debt'] > a['debt'] * 1.001 and not any(a['t'] < t <= b['t'] for t in deals):
            up_n += 1; up_bn += (b['debt'] - a['debt']) / 1e9
    cash_nav = [r['cash'] / r['eq'] for r in rep if r['eq'] > 0]
    yr = lambda y: min(rep, key=lambda r: abs(r['t'] - y))['cash'] / 1e9
    return {
        'div_ni': sum(r['div'] for r in rep) / ni if ni else float('nan'),
        'bb_ni': sum(r['bb'] for r in rep) / ni if ni else float('nan'),
        'bb_total_bn': sum(r['bb'] for r in rep) / 1e9,
        'bb_fire_share': len(fired) / len(rep),
        'bb_fire_pb_med': fired[len(fired) // 2] if fired else float('nan'),
        'cash_max_bn': max(r['cash'] for r in rep) / 1e9,
        'cash_y10_bn': yr(10), 'cash_y20_bn': yr(20),
        'cash_nav_mean': sum(cash_nav) / len(cash_nav), 'cash_nav_end': cash_nav[-1],
        'debt_up_nodeal_n': up_n, 'debt_up_nodeal_bn': up_bn,
    }


def board(arm, s):
    rec = json.loads(open(H / f'{arm}-{s}.jsonl').readline())
    caps = {}
    for t, f in rec['final'].items():
        if f['dead'] or not f['q']:
            continue
        caps[t] = f['q'][-1]['price'] * f['q'][-1]['shares']
    return {'cap_T': sum(caps.values()) / 1e12, 'deaths': len(rec['dead']), 'reorgs': len(rec['reorgs']),
            'defaults': len(rec['defaults'])}, caps


def main():
    ss, A, B = seeds(sys.argv[1]), sys.argv[2], sys.argv[3]
    ra = {s: brkw(A, s) for s in ss}
    rb = {s: brkw(B, s) for s in ss}
    keys = list(ra[ss[0]].keys())
    print(f'BRKW, {A} vs {B}, seeds {sys.argv[1]}; per seed A/B, paired B-A (se) t n')
    for k in keys:
        per = '  '.join(f'{ra[s][k]:.3g}/{rb[s][k]:.3g}' for s in ss)
        print(f'{k:15s} {per:60s} {fmt(paired([ra[s][k] for s in ss], [rb[s][k] for s in ss]))}')
    ba, bb, ca, cb = {}, {}, {}, {}
    for s in ss:
        ba[s], ca[s] = board(A, s)
        bb[s], cb[s] = board(B, s)
    print('\nBoard')
    for k in ba[ss[0]]:
        per = '  '.join(f'{ba[s][k]:.3g}/{bb[s][k]:.3g}' for s in ss)
        print(f'{k:15s} {per:60s} {fmt(paired([ba[s][k] for s in ss], [bb[s][k] for s in ss]))}')
    # Firms whose end cap moved: mean |log ratio| over seeds where alive in both.
    moves = {}
    for s in ss:
        for t in set(ca[s]) & set(cb[s]):
            if ca[s][t] > 0 and cb[s][t] > 0:
                moves.setdefault(t, []).append(math.log(cb[s][t] / ca[s][t]))
    print('\nEnd-cap log ratio B/A, firms with mean |d| > 0.10 (per seed)')
    for t, d in sorted(moves.items(), key=lambda kv: -sum(abs(x) for x in kv[1]) / len(kv[1])):
        m = sum(abs(x) for x in d) / len(d)
        if m > 0.10:
            print(f'{t:6s} mean|d| {m:.2f}  ' + ' '.join(f'{x:+.2f}' for x in d))
    allm = [sum(abs(x) for x in d) / len(d) for d in moves.values()]
    allm.sort()
    print(f'median firm mean|d| {allm[len(allm) // 2]:.3f} over {len(allm)} firms')
    alive = {s: set(ca[s]) ^ set(cb[s]) for s in ss}
    print('alive in one arm only: ' + '; '.join(f's{s}: {sorted(v)}' for s, v in alive.items()))


if __name__ == '__main__':
    main()
