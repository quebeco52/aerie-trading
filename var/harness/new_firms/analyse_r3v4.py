#!/usr/bin/env python3
"""Round 3 v4: SKUA seed volatility 0.24 -> 0.18 (Q1) and plant-sale cascades across all firms (Q2).

Usage: python3 -I analyse_r3v4.py   (reads runs/{head-1..4, r3v3-1..8, r3v4-1..8}.jsonl; missing files are skipped)
Q1 arms: r3v3 (0.24) and r3v4 (0.18); head shown for reference. Q2 pools every arm.
Plant sale = quarter with ppe down >15% on the quarter and shares < 1.3x prior (no split). The harness's event filter
drops DIVESTITURE lines, so sales are inferred from the plant path; 'debt also down' marks a sale (buyer assumes debt)
against a write-down.
Cascade = plant below 40% of its level before the first such drop, within 12 quarters of that drop.
"""
import json, math, os, statistics as st

H = os.path.dirname(os.path.abspath(__file__))
ARMS = {'head': range(1, 5), 'r3v3': range(1, 9), 'r3v4': range(1, 9)}
BASE_CHURN = {'SKUA': 0.048, 'LOON': 0.03}
COMM = ('crude', 'crack', 'gas', 'metals', 'gold', 'power', 'agri', 'freight')


def load(arm, s):
    p = os.path.join(H, 'runs', f'{arm}-{s}.jsonl')
    if not os.path.exists(p):
        return None
    r = json.loads(open(p).readline())
    r['open'] = json.load(open(os.path.join(H, 'runs', f'{arm}-{s}.open.json')))
    return r


def splitf(a, b):
    r = b['shares'] / a['shares'] if a['shares'] > 0 else 1.0
    return round(r) if r >= 1.5 else (1.0 / round(1.0 / r) if r <= 0.67 else 1.0)


def sale_q(q):
    return [i for i in range(1, len(q)) if q[i - 1]['ppe'] > 0 and q[i]['ppe'] < 0.85 * q[i - 1]['ppe']
            and q[i]['shares'] < 1.3 * q[i - 1]['shares']]


def corr(a, b):
    try:
        return st.correlation(a, b)
    except Exception:
        return float('nan')


runs = {(a, s): load(a, s) for a, ss in ARMS.items() for s in ss}
runs = {k: v for k, v in runs.items() if v}

# ---------------- Q1 ----------------
def q1(r, T):
    q = r['final'][T]['q']
    si = [x['kpi']['subscriber_index'] for x in q]
    e = []
    for i in range(1, len(q)):
        if q[i]['reg'].get('price_war', 0) == 0 and q[i - 1]['reg'].get('price_war', 0) == 0:
            ch = q[i]['kpi']['quarterly_churn']
            e.append((si[i] - si[i - 1] * (1 - ch)) / (si[i - 1] * BASE_CHURN[T]) - 1)
    ev = r['events'].get(T, [])
    lr = []
    for i in range(1, len(q)):
        lr.append(math.log(q[i]['price'] * splitf(q[i - 1], q[i]) / q[i - 1]['price']))
    nde = [x['ndE'] for x in q if x.get('ndE') is not None and math.isfinite(x['ndE'])]
    return {
        'si_min': min(si), 'si_max': max(si), 'si_sd': st.pstdev(si), 'e_sd': st.pstdev(e) if len(e) > 2 else float('nan'),
        'om50': st.median([x['om'] for x in q]), 'loss': sum(x['ni'] < 0 for x in q) / len(q),
        'nde_max': max(nde), 'q45': sum(1 for v in nde if v > 4.5),
        'dead': int(bool(r['final'][T]['dead'])), 'reorg': sum(1 for x in r['reorgs'] if x['t'] == T),
        'shut': sum('shut out' in x['d'].lower() for x in ev), 'emerg': sum('emergency' in x['d'].lower() for x in ev),
        'plant': len(sale_q(q)), 'vol': st.pstdev(lr) * 2.0, 'cvol': st.mean(x['cvol'] for x in q),
    }


KEYS = [('si_min', 'subscriber index min'), ('si_max', 'subscriber index max'), ('si_sd', 'subscriber index sd'),
        ('e_sd', 'gross-adds noise sd (peace q)'), ('om50', 'op margin p50'), ('loss', 'loss-quarter share'),
        ('nde_max', 'peak ND/EBITDA'), ('q45', 'quarters ND/EBITDA > 4.5'), ('dead', 'defaults (dead)'),
        ('reorg', 'reorgs'), ('shut', 'bond shut-out lines'), ('emerg', 'emergency equity lines'),
        ('plant', 'plant-sale quarters'), ('vol', 'realized vol, ann. (qtr px)'), ('cvol', 'engine vol (cvol) mean')]
print('Q1  SKUA / LOON per arm (8 seeds unless noted). Cells: mean [min-max] over seeds; counts are totals.')
for T in ('SKUA', 'LOON'):
    print(T)
    print('  ' + 'quantity'.ljust(32) + ''.join(a.rjust(26) for a in ARMS))
    res = {a: [q1(runs[(a, s)], T) for s in ARMS[a] if (a, s) in runs] for a in ARMS}
    for k, lab in KEYS:
        cells = []
        for a in ARMS:
            v = [x[k] for x in res[a]]
            if not v:
                cells.append('-')
            elif k in ('dead', 'reorg', 'shut', 'emerg', 'plant', 'q45'):
                cells.append('%d (%d seeds>0) n=%d' % (sum(v), sum(1 for x in v if x > 0), len(v)))
            else:
                cells.append('%.3f [%.2f-%.2f]' % (st.mean(v), min(v), max(v)))
        print('  ' + lab.ljust(32) + ''.join(c.rjust(26) for c in cells))
    for a in ('r3v3', 'r3v4'):
        print('  %s per seed si_min/si_max/e_sd/vol: %s' % (a, '  '.join('%.2f/%.2f/%.2f/%.2f' % (x['si_min'], x['si_max'], x['e_sd'], x['vol']) for x in res[a])))
    # Arms diverge after the first draw that touches SKUA, so the difference is unpaired: se from both arms' variances.
    for k in ('si_min', 'si_sd', 'e_sd', 'om50', 'loss', 'nde_max', 'vol'):
        a, b = [x[k] for x in res['r3v3']], [x[k] for x in res['r3v4']]
        if len(a) > 1 and len(b) > 1:
            d, se = st.mean(b) - st.mean(a), (st.variance(a) / len(a) + st.variance(b) / len(b)) ** 0.5
            print('  r3v4 - r3v3 %-8s %+.3f (se %.3f, t %+.1f); medians %.3f -> %.3f' % (k, d, se, d / se if se else float('nan'), st.median(a), st.median(b)))

# ---------------- Q2 ----------------
print()
print('Q2  plant-sale quarters (ppe -15%+, no split) and cascades (<40% of pre-drop plant within 3y), all firms')
cnt, nruns = {}, {}
casc = []
for (a, s), r in sorted(runs.items()):
    for T, f in r['final'].items():
        q = f['q']
        if len(q) < 8:
            continue
        sq = sale_q(q)
        c = cnt.setdefault(T, {'sale': 0, 'debt': 0, 'runs': 0, 'casc': 0})
        c['sale'] += len(sq)
        c['debt'] += sum(1 for i in sq if q[i]['debt'] < 0.9 * q[i - 1]['debt'])
        c['runs'] += 1 if sq else 0
        nruns[T] = nruns.get(T, 0) + 1
        done = -1
        for i in sq:
            if i <= done:
                continue
            p0 = q[i - 1]['ppe']
            win = range(i, min(len(q), i + 12))
            j = min(win, key=lambda k: q[k]['ppe'])
            if q[j]['ppe'] < 0.4 * p0:
                done = i + 12
                c['casc'] += 1
                pre = q[max(0, i - 4):i]
                om = [x['om'] for x in q]
                best = max(COMM, key=lambda k: abs(corr(om, [x[k] for x in q])) if len(set(x[k] for x in q)) > 1 else 0)
                med = st.median(x[best] for x in q)
                casc.append({
                    'arm': a, 'seed': s, 'T': T, 'ind': r['open'].get(T, {}).get('industry', '?')[:22], 'yr': q[i]['t'],
                    'n': sum(1 for k in sq if i <= k <= j), 'min': q[j]['ppe'] / p0, 'end': q[-1]['ppe'] / p0,
                    'om_in': st.mean(x['om'] for x in pre), 'om_out': st.median(om[j:j + 8]), 'om_end': st.median(om[-12:]),
                    'om_run': st.median(om[:max(1, i - 4)]),
                    'nde_in': max((x['ndE'] for x in pre if x.get('ndE') is not None), default=float('nan')),
                    'ni_neg': sum(x['ni'] < 0 for x in pre), 'com': best, 'rho': corr(om, [x[best] for x in q]),
                    'c_in': st.mean(x[best] for x in pre) / med, 'c_end': st.mean(x[best] for x in q[-8:]) / med,
                    'rev_x': sum(x['rev'] for x in q[-4:]) / sum(x['rev'] for x in pre),
                    'dead': bool(f['dead']),
                })
print('  firm  firm-runs  runs-with-sale  sale-quarters  of-which-debt-down  cascades')
for T, c in sorted(cnt.items(), key=lambda kv: -kv[1]['sale']):
    if c['sale']:
        print('  %-5s %9d %15d %14d %19d %9d' % (T, nruns[T], c['runs'], c['sale'], c['debt'], c['casc']))
print('  firms with no plant-sale quarter in any run: %d of %d' % (sum(1 for c in cnt.values() if not c['sale']), len(cnt)))
print()
print('  Cascades. om_pre = op margin 4q before (first-drop) | om_hist = median before | om+2y after trough | om last 3y;')
print('  nde_pre = max ND/EBITDA 4q before; lossq = loss quarters of 4 before; com = index most correlated with the')
print('  firm\'s op margin (rho), level / run median 4q before and over the last 2y; plant min and y20 vs pre-drop.')
hdr = ('arm', 'sd', 'firm', 'industry', 'year', 'nsale', 'pl_min', 'pl_y20', 'om_hist', 'om_pre', 'om+2y', 'om_y18-20',
       'nde_pre', 'lossq', 'com(rho)', 'com_pre', 'com_y19-20', 'rev y20/pre', 'dead')
print('  ' + ' '.join(h.rjust(w) for h, w in zip(hdr, (4, 2, 5, 22, 5, 5, 6, 6, 7, 6, 6, 9, 7, 5, 13, 7, 10, 11, 4))))
for c in casc:
    print('  %4s %2d %5s %22s %5.1f %5d %6.2f %6.2f %7.3f %6.3f %6.3f %9.3f %7.2f %5d %13s %7.2f %10.2f %11.2f %4s' % (
        c['arm'], c['seed'], c['T'], c['ind'], c['yr'], c['n'], c['min'], c['end'], c['om_run'], c['om_in'], c['om_out'],
        c['om_end'], c['nde_in'], c['ni_neg'], '%s(%+.2f)' % (c['com'], c['rho']), c['c_in'], c['c_end'], c['rev_x'],
        'Y' if c['dead'] else ''))
print('  runs read: ' + ', '.join('%s %d' % (a, sum(1 for k in runs if k[0] == a)) for a in ARMS))
