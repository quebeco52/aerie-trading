"""DTI drift question: dti.py ARM [ARM2]. Env SEEDS (1-16), H (15, decomposition horizon in years).

Prints DTI at years 5/10/15/20 (mean, sd, min, n), the log-DTI decomposition over years 0..H (seed means with se),
context (LTV cap, capital requirement, CCyB, house-price lift) and, given two arms, the paired ARM-ARM2 DTI at year H."""
import json, math, os, sys

D = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'runs')


def seeds():
    out = []
    for part in os.environ.get('SEEDS', '1-16').split(','):
        a, _, b = part.partition('-')
        out += list(range(int(a), int(b or a) + 1))
    return out


def load(arm, s):
    p = os.path.join(D, f'{arm}-{s}.macro.jsonl')
    if not os.path.exists(p):
        return None
    rows = [json.loads(l) for l in open(p) if l.strip()]
    return rows or None


def at(rows, y):
    r = [x for x in rows if abs(x['t'] - y) < 1e-6]
    return r[0] if r else None


def ms(v):
    n = len(v)
    if n == 0:
        return (float('nan'),) * 3
    m = sum(v) / n
    sd = math.sqrt(sum((x - m) ** 2 for x in v) / (n - 1)) if n > 1 else float('nan')
    return m, sd, sd / math.sqrt(n) if n > 1 else float('nan')


TERMS = ['d_hp', 'd_rev', 'd_dsr', 'd_co', 'd_cap', 'd_ltv']
NAMES = {'d_hp': 'house price', 'd_rev': 'reversion', 'd_dsr': 'DSR', 'd_co': 'charge-off', 'd_cap': 'capital squeeze',
         'd_ltv': 'LTV squeeze', 'resid': 'residual (noise+in-tick)', 'd_tot': 'total log change'}


def arm_stats(arm, H):
    lv, dec, ctx = {}, {}, {}
    for s in seeds():
        rows = load(arm, s)
        if rows is None:
            continue
        for y in (5, 10, 15, 20):
            r = at(rows, y)
            if r is not None:
                lv.setdefault(y, {})[s] = r['dti']
        win = [r for r in rows if r['t'] <= H + 1e-6]
        if at(rows, H) is None:
            continue
        sums = {k: sum(r.get(k, 0.0) for r in win) for k in TERMS + ['d_tot', 'cap_up', 'cap_dn']}
        sums['resid'] = sums['d_tot'] - sum(sums[k] for k in TERMS)
        for k, v in sums.items():
            dec.setdefault(k, []).append(v)
        rH = at(rows, H)
        ctx.setdefault('ltv_cap@H', []).append(rH['ltv_cap'] if rH['ltv_cap'] is not None else 1.0)
        ctx.setdefault('ltv_cut@H', []).append(rH['ltv_cut'])
        ctx.setdefault('cap_req@H', []).append(rH['cap_req'])
        ctx.setdefault('ccyb@H', []).append(rH['ccyb'])
        ctx.setdefault('mean lift 0..H', []).append(sum(r['resi_ema'] / r['resi_trend'] - 1 for r in win) / len(win))
        ctx.setdefault('mean retail_def', []).append(sum(r['retail_def'] for r in win) / len(win))
        ctx.setdefault('mean dsr_gap', []).append(sum(r['dsr_gap'] for r in win) / len(win))
    return lv, dec, ctx


def main():
    arms = sys.argv[1:]
    H = int(os.environ.get('H', '15'))
    stats = {a: arm_stats(a, H) for a in arms}
    print(f'DTI level (mean / sd / min, n)')
    print('year | ' + ' | '.join(arms))
    for y in (5, 10, 15, 20):
        cells = []
        for a in arms:
            v = list(stats[a][0].get(y, {}).values())
            m, sd, _ = ms(v)
            cells.append(f'{m:.3f} / {sd:.3f} / {min(v) if v else float("nan"):.3f} (n={len(v)})')
        print(f'{y:>4} | ' + ' | '.join(cells))
    print(f'\nlog DTI decomposition, years 0-{H}: seed mean (se)')
    print('term | ' + ' | '.join(arms))
    for k in TERMS + ['resid', 'd_tot', 'cap_up', 'cap_dn']:
        cells = []
        for a in arms:
            m, _, se = ms(stats[a][1].get(k, []))
            cells.append(f'{m:+.4f} ({se:.4f})')
        print(f'{NAMES.get(k, k)} | ' + ' | '.join(cells))
    print('\ncontext: seed mean (sd)')
    for k in stats[arms[0]][2]:
        cells = []
        for a in arms:
            m, sd, _ = ms(stats[a][2].get(k, []))
            cells.append(f'{m:.4f} ({sd:.4f})')
        print(f'{k} | ' + ' | '.join(cells))
    if len(arms) == 2:
        a, b = arms
        for y in (H,):
            A, B = stats[a][0].get(y, {}), stats[b][0].get(y, {})
            common = sorted(set(A) & set(B))
            d = [A[s] - B[s] for s in common]
            m, sd, se = ms(d)
            print(f'\npaired {a}-{b} DTI at year {y}: {m:+.4f} (se {se:.4f}, t {m / se if se else float("nan"):.2f}, n {len(d)})')


main()
