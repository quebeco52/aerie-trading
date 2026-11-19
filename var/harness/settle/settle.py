"""Per-firm drift from the seed: settle.py <run.jsonl> [more runs, averaged] [--csv out.csv]

Columns: the seed (q0), year 1 (q1-4), year 5 (q17-20) and the last year, for the return state, the filed return,
margin, size against nominal GDP (revenue and equity, seed = 1.00), leverage, cash/assets and P/B. A firm is flagged
when, by year 5, its return moved over 3pp, its revenue/GDP or equity/GDP over 25%, its margin over 5pp, or its D/E
over 50%.
"""
import json
import sys
from collections import defaultdict
from statistics import mean


def load(paths):
    """Rows by ticker; several runs (seeds) are averaged quarter by quarter, numeric fields only."""
    cells = defaultdict(list)
    for path in paths:
        with open(path) as f:
            for line in f:
                r = json.loads(line)
                cells[(r['ticker'], r['q'])].append(r)
    rows = defaultdict(list)
    for (ticker, q), rs in cells.items():
        r = dict(rs[0])
        for k, v in r.items():
            if isinstance(v, (int, float)) and not isinstance(v, bool):
                xs = [x[k] for x in rs if x.get(k) is not None]
                r[k] = mean(xs) if xs else None
        r['bankrupt'] = any(x['bankrupt'] for x in rs)
        r['q'] = q
        rows[ticker].append(r)
    return rows


def window(rs, lo, hi, key):
    xs = [r[key] for r in rs if lo <= r['q'] <= hi and r.get(key) is not None]
    return mean(xs) if xs else None


def summarise(ticker, rs):
    rs = sorted(rs, key=lambda r: r['q'])
    s = rs[0]
    last = max(r['q'] for r in rs)
    y = {'y1': (1, 4), 'y5': (17, 20), 'yN': (last - 3, last)}
    out = {'ticker': ticker, 'model': s['model'], 'fin': s['fin'], 'sphere': s['sphere'], 'last_q': last,
           'bankrupt': rs[-1]['bankrupt'], 'equilibrium': (s['required'] or 0) + (s['moat'] or 0)}
    for k in ('ret_ttm', 'ret_filed', 'margin', 'de', 'cash_assets', 'pb', 'payout', 'required'):
        out[k + '_0'] = s.get(k)
        for name, (lo, hi) in y.items():
            out[f'{k}_{name}'] = window(rs, lo, hi, k)
    for k in ('rev_gdp', 'eq_gdp', 'assets_gdp'):
        base = s[k] or None
        for name, (lo, hi) in y.items():
            v = window(rs, lo, hi, k)
            out[f'{k}_{name}'] = (v / base) if (v is not None and base) else None
    return out


def flags(o):
    f = []
    d = lambda a, b: (a - b) if a is not None and b is not None else 0.0
    if abs(d(o['ret_ttm_y5'], o['ret_ttm_0'])) > 0.03:
        f.append('ret')
    if o['rev_gdp_y5'] is not None and abs(o['rev_gdp_y5'] - 1) > 0.25:
        f.append('rev')
    if o['eq_gdp_y5'] is not None and abs(o['eq_gdp_y5'] - 1) > 0.25:
        f.append('eq')
    if abs(d(o['margin_y5'], o['margin_0'])) > 0.05:
        f.append('margin')
    if o['de_0'] and o['de_y5'] is not None and abs(o['de_y5'] / o['de_0'] - 1) > 0.5 and abs(o['de_y5'] - o['de_0']) > 0.1:
        f.append('lev')
    if o['bankrupt']:
        f.append('BANKRUPT')
    return f


def fmt(v, pct=False, w=6):
    if v is None:
        return ' ' * (w - 1) + '-'
    return f'{v * 100:{w}.1f}' if pct else f'{v:{w}.2f}'


def main():
    args = sys.argv[1:]
    csv_out = None
    if '--csv' in args:
        i = args.index('--csv')
        csv_out = args[i + 1]
        args = args[:i] + args[i + 2:]
    summ = [summarise(t, rs) for t, rs in load(args).items()]
    for o in summ:
        o['flags'] = flags(o)
    summ.sort(key=lambda o: (-len(o['flags']), -abs((o['ret_ttm_y5'] or 0) - (o['ret_ttm_0'] or 0))))
    print('return = ROE for financials, ROIC otherwise; eq = required + moat. rev/eq = size vs nominal GDP, seed 1.00')
    print(f"{'tick':5} {'model':26} {'ret0':>6} {'ret1':>6} {'ret5':>6} {'retN':>6} {'eq':>6} | {'mgn0':>5} {'mgn5':>5} |"
          f" {'rev1':>5} {'rev5':>5} {'revN':>5} {'eq5':>5} {'eqN':>5} | {'de0':>5} {'de5':>5} | {'cash0':>5} {'cash5':>5} |"
          f" {'pb0':>5} {'pb5':>5} | flags")
    for o in summ:
        print(f"{o['ticker']:5} {o['model'][:26]:26} {fmt(o['ret_ttm_0'], 1)} {fmt(o['ret_ttm_y1'], 1)} {fmt(o['ret_ttm_y5'], 1)}"
              f" {fmt(o['ret_ttm_yN'], 1)} {fmt(o['equilibrium'], 1)} | {fmt(o['margin_0'], 1, 5)} {fmt(o['margin_y5'], 1, 5)} |"
              f" {fmt(o['rev_gdp_y1'], w=5)} {fmt(o['rev_gdp_y5'], w=5)} {fmt(o['rev_gdp_yN'], w=5)} {fmt(o['eq_gdp_y5'], w=5)}"
              f" {fmt(o['eq_gdp_yN'], w=5)} | {fmt(o['de_0'], w=5)} {fmt(o['de_y5'], w=5)} | {fmt(o['cash_assets_0'], 1, 5)}"
              f" {fmt(o['cash_assets_y5'], 1, 5)} | {fmt(o['pb_0'], w=5)} {fmt(o['pb_y5'], w=5)} |"
              f" {','.join(o['flags'])}{' (sphere)' if o['sphere'] else ''}")
    n = len(summ)
    flagged = sum(1 for o in summ if o['flags'])
    print(f'\n{flagged}/{n} firms flagged by year 5')
    for k in ('ret', 'rev', 'eq', 'margin', 'lev', 'BANKRUPT'):
        print(f"  {k:8} {sum(1 for o in summ if k in o['flags'])}")
    if csv_out:
        import csv
        with open(csv_out, 'w', newline='') as f:
            w = csv.DictWriter(f, fieldnames=list(summ[0].keys()))
            w.writeheader()
            for o in summ:
                w.writerow({**o, 'flags': ','.join(o['flags'])})


if __name__ == '__main__':
    main()
