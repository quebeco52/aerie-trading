#!/usr/bin/env python3
"""Round 3 sanity table: SKUA (challenger carrier), DIPR (independent power), KTWK (port terminal), LOON beside them.

Usage: ARM=<arm> python3 analyse_round3.py [seeds...]. Run without -I: it imports minnp.py beside it.
Needs HEALTH=1 runs (ndE, covL columns) of NewFirmsHarnessTest.php with the round-3 row (kpi, reg, ddg, fog).
"""
import json, math, os, sys
import minnp as np

H = os.path.dirname(os.path.abspath(__file__))
ARM = os.environ.get('ARM', 'live')
seeds = [int(s) for s in sys.argv[1:]] or [1, 2, 3]
T4 = ('SKUA', 'LOON', 'DIPR', 'KTWK')


def splits(q):
    f = [1.0]
    for a, b in zip(q, q[1:]):
        r = b['shares'] / a['shares'] if a['shares'] > 0 else 1.0
        f.append(round(r) if r >= 1.5 else (1.0 / round(1.0 / r) if r <= 0.67 else 1.0))
    return f


def onsets(xs):
    return sum(1 for i, x in enumerate(xs) if x > 0 and (i == 0 or xs[i - 1] == 0))


def qi(x):
    return int(math.floor(x['t'] * 4 + 1e-9))


def corr(a, b):
    p = [(x, y) for x, y in zip(a, b) if math.isfinite(x) and math.isfinite(y)]
    return np.corr([x for x, _ in p], [y for _, y in p]) if len(p) > 3 else float('nan')


def distress(ev):
    c = {'default': 0, 'missed': 0, 'shut': 0, 'emerg': 0}
    for e in ev:
        d = e['d'].lower()
        if 'failed to repay' in d:
            c['missed'] += 1
        elif 'cured' in d:
            c['cured'] = c.get('cured', 0) + 1
        elif 'default' in d or 'chapter' in d or 'liquidat' in d:
            c['default'] += 1
        if 'shut out' in d:
            c['shut'] += 1
        if 'emergency' in d:
            c['emerg'] += 1
    return c


rows = {}
for s in seeds:
    r = json.loads(open(os.path.join(H, 'runs', f'{ARM}-{s}.jsonl')).readline())
    op = json.load(open(os.path.join(H, 'runs', f'{ARM}-{s}.open.json')))
    fin = r['final']
    # Price from tick 1 (the seed command splits some titans at tick 1), split-adjusted over the quarters.
    s1 = lambda t, q: (round(q[0]['shares'] / op[t]['sh1']) if q[0]['shares'] / op[t]['sh1'] >= 1.5 else 1.0)
    pxr = {t: f['px'] * s1(t, f['q']) * math.prod(splits(f['q'])) / op[t]['px1'] for t, f in fin.items() if f['q'] and not f['dead'] and t in op}
    out = {'fail': len(r['dead']), 'reorg': len(r['reorgs']), 'board_px': float(np.median(list(pxr.values()))),
           'dead_list': ','.join(sorted(r['dead'])) if isinstance(r['dead'], (list, dict)) else str(r['dead'])}
    # Absurd-level screen over the whole board.
    bad, shx, omx = 0, [], []
    for t, f in fin.items():
        q = f['q']
        if not q:
            continue
        bad += sum(1 for x in q if not math.isfinite(x['rev']) or x['rev'] <= 0 or not math.isfinite(x['om']))
        if not f['dead'] and q[0]['shares'] > 0:
            shx.append(q[-1]['shares'] / q[0]['shares'] / math.prod(splits(q)))
        omx.append(max(x['om'] for x in q))
    out.update(board_bad=bad, shares_min=min(shx), shares_max=max(shx), om_max=max(omx), px_max=max(pxr.values()), px_min=min(pxr.values()))
    for t in T4:
        q = fin[t]['q']
        om = [x['om'] for x in q]
        rev = [x['rev'] for x in q]
        nde = [x['ndE'] for x in q if x.get('ndE') is not None and math.isfinite(x['ndE'])]
        cov = q[-1].get('covL')
        out[t] = {
            'p5': np.percentile(om, 5), 'p50': np.median(om), 'p95': np.percentile(om, 95),
            'loss': float(np.mean([x['ni'] < 0 for x in q])), 'nde_max': max(nde), 'cov': cov,
            'breach': sum(1 for x in q if x.get('ndE') is not None and cov and x['ndE'] > x['covL']),
            'dead': fin[t]['dead'], 'reorg': sum(1 for x in r['reorgs'] if x['t'] == t),
            'px': pxr.get(t, float('nan')), 'rev_x': sum(rev[-4:]) / sum(rev[:4]),
            'cov_ev': sum(1 for e in r['events'].get(t, []) if 'covenant' in e['d'].lower()),
            'icr_min': min((x['icr'] for x in q if x.get('icr') is not None and math.isfinite(x['icr'])), default=float('nan')),
            'distress': distress(r['events'].get(t, [])),
        }
    # Telecom: the war is the industry's, so both carriers' clocks should agree quarter by quarter.
    w = {t: {qi(x): x['reg'].get('price_war', 0) for x in fin[t]['q']} for t in ('SKUA', 'LOON')}
    ks = sorted(set(w['SKUA']) & set(w['LOON']))
    a = [1 if w['SKUA'][k] > 0 else 0 for k in ks]
    b = [1 if w['LOON'][k] > 0 else 0 for k in ks]
    rs = {qi(x): x['rev'] for x in fin['SKUA']['q']}
    rl = {qi(x): x['rev'] for x in fin['LOON']['q']}
    kr = sorted(set(rs) & set(rl))
    sh = lambda kk: sum(rs[k] for k in kk) / sum(rs[k] + rl[k] for k in kk)
    out['tel'] = {'war_s': float(np.mean(a)), 'war_l': float(np.mean(b)), 'mismatch': sum(1 for x, y in zip(a, b) if x != y),
                  'wars': onsets([max(x, y) for x, y in zip(a, b)]), 'onset_s': onsets(a), 'onset_l': onsets(b),
                  'share0': sh(kr[:4]), 'share20': sh(kr[-4:]),
                  'arpu_war': float(np.mean([x['kpi']['arpu_index'] for x in fin['SKUA']['q'] if x['reg'].get('price_war', 0) > 0] or [float('nan')])),
                  'arpu_peace': float(np.mean([x['kpi']['arpu_index'] for x in fin['SKUA']['q'] if x['reg'].get('price_war', 0) == 0]))}
    q = fin['DIPR']['q']
    hg = [x['kpi'].get('hedge_gain', 0.0) for x in q]
    out['ipp'] = {'c_gas': corr([x['om'] for x in q], [x['gas'] for x in q]), 'c_pow': corr([x['om'] for x in q], [x['power'] for x in q]),
                  'outages': onsets([x['reg'].get('unit_outage', 0) for x in q]), 'hg5': np.percentile(hg, 5), 'hg95': np.percentile(hg, 95),
                  'hg_q': [float(np.mean([x['kpi'].get('hedge_gain', 0.0) for x in q if int(math.floor((x['t'] % 1.0) * 4 + 1e-9)) == c])) for c in range(4)],
                  'spark_min': min(x['kpi']['clean_spark_spread'] for x in q), 'gas_min': min(x['gas'] for x in q), 'gas_max': max(x['gas'] for x in q)}
    q = fin['KTWK']['q']
    rev = [x['rev'] for x in q]
    yr = [math.log(rev[i] / rev[i - 4]) for i in range(4, len(rev))]
    d = lambda key: [q[i][key] - q[i - 4][key] for i in range(4, len(q))]
    th = [x['kpi']['throughput_index'] for x in q]
    dw = [x['kpi']['dwell_index'] for x in q]
    sk = [x['reg'].get('dock_strike', 0) for x in q]
    on = [i for i, v in enumerate(sk) if v > 0 and (i == 0 or sk[i - 1] == 0)]
    around = [[th[i + k] / th[i - 1] for k in (0, 1, 2) if 0 <= i + k < len(th)] for i in on if i > 0]
    out['port'] = {'around': around, 'strike_q': sum(1 for v in sk if v > 0),'th_min': min(th), 'th_max': max(th), 'dw_min': min(dw), 'dw_max': max(dw),
                   'strikes': onsets([x['reg'].get('dock_strike', 0) for x in q]),
                   'c_ddg': corr(yr, d('ddg')), 'c_fog': corr(yr, d('fog')), 'c_wag': corr(yr, d('wag')),
                   'c_th': corr(yr, [math.log(th[i] / th[i - 4]) for i in range(4, len(th))])}
    rows[s] = out


def fmt(v):
    if isinstance(v, bool):
        return str(v)
    return '%.3f' % v if isinstance(v, float) else str(v)


def line(label, f, pool=True):
    vals = [f(rows[s]) for s in seeds]
    num = [v for v in vals if isinstance(v, (int, float)) and not isinstance(v, bool) and v is not None and math.isfinite(v)]
    pooled = '%.3f' % float(np.mean(num)) if pool and len(num) == len(vals) else ''
    print(label.ljust(40) + ''.join(fmt(v).rjust(9) for v in vals) + pooled.rjust(9))


print('seed'.ljust(40) + ''.join(str(s).rjust(9) for s in seeds) + 'mean'.rjust(9))
for T in T4:
    print(T, 'covenant', rows[seeds[0]][T]['cov'])
    for k in ('p5', 'p50', 'p95'):
        line(' op margin ' + k, lambda r, k=k: float(r[T][k]))
    line(' loss-quarter share', lambda r: r[T]['loss'])
    line(' max net debt / EBITDA', lambda r: float(r[T]['nde_max']))
    line(' quarters over covenant', lambda r: r[T]['breach'])
    line(' covenant event lines', lambda r: r[T]['cov_ev'])
    line(' min interest cover (EBIT/int)', lambda r: float(r[T]['icr_min']))
    line(' default/missed/shut-out/emergency', lambda r: '%d/%d/%d/%d' % tuple(r[T]['distress'][k] for k in ('default', 'missed', 'shut', 'emerg')), pool=False)
    line(' dead / reorgs', lambda r: f"{int(r[T]['dead'])}/{r[T]['reorg']}", pool=False)
    line(' price y20 / tick 1', lambda r: float(r[T]['px']))
    line(' revenue y20 / y0 (4q sums)', lambda r: float(r[T]['rev_x']))
line('board median price y20 / tick 1', lambda r: r['board_px'])
print('Telecom')
line(' SKUA war-quarter share', lambda r: r['tel']['war_s'])
line(' LOON war-quarter share', lambda r: r['tel']['war_l'])
line(' quarters the clocks disagree', lambda r: r['tel']['mismatch'])
line(' wars (union onsets)', lambda r: r['tel']['wars'])
line(' onsets SKUA / LOON', lambda r: f"{r['tel']['onset_s']}/{r['tel']['onset_l']}", pool=False)
line(' SKUA ARPU index war / peace', lambda r: f"{r['tel']['arpu_war']:.3f}/{r['tel']['arpu_peace']:.3f}", pool=False)
line(' SKUA revenue share y0', lambda r: r['tel']['share0'])
line(' SKUA revenue share y20', lambda r: r['tel']['share20'])
print('DIPR')
line(' corr(op margin, gas EMA)', lambda r: r['ipp']['c_gas'])
line(' corr(op margin, power EMA)', lambda r: r['ipp']['c_pow'])
line(' outages', lambda r: r['ipp']['outages'])
line(' hedge gain / revenue p5', lambda r: float(r['ipp']['hg5']))
line(' hedge gain / revenue p95', lambda r: float(r['ipp']['hg95']))
for c in range(4):
    line(' hedge gain / revenue mean, cal Q%d' % (c + 1), lambda r, c=c: r['ipp']['hg_q'][c])
line(' clean spark spread min', lambda r: r['ipp']['spark_min'])
line(' gas EMA min', lambda r: r['ipp']['gas_min'])
line(' gas EMA max', lambda r: r['ipp']['gas_max'])
print('KTWK')
for k in ('th_min', 'th_max', 'dw_min', 'dw_max'):
    line(' ' + k, lambda r, k=k: r['port'][k])
line(' strikes', lambda r: r['port']['strikes'])
line(' strike quarters', lambda r: r['port']['strike_q'])
for s_ in seeds:
    print('  seed %d throughput at onset / +1q / +2q over the quarter before: %s' % (s_, '; '.join('/'.join('%.3f' % v for v in a) for a in rows[s_]['port']['around']) or '-'))
line(' corr(YoY log rev, YoY d domestic gap)', lambda r: r['port']['c_ddg'])
line(' corr(YoY log rev, YoY d foreign gap)', lambda r: r['port']['c_fog'])
line(' corr(YoY log rev, YoY d world gap)', lambda r: r['port']['c_wag'])
line(' corr(YoY log rev, YoY log throughput)', lambda r: r['port']['c_th'])
print('Board')
line(' failures', lambda r: r['fail'])
line(' reorgs', lambda r: r['reorg'])
line(' dead tickers', lambda r: r['dead_list'] or '-', pool=False)
line(' zero/neg/NaN revenue or margin quarters', lambda r: r['board_bad'])
line(' shares y20/y0 split-adj min', lambda r: r['shares_min'])
line(' shares y20/y0 split-adj max', lambda r: r['shares_max'])
line(' max op margin any firm', lambda r: r['om_max'])
line(' price y20/tick1 min', lambda r: r['px_min'])
line(' price y20/tick1 max', lambda r: r['px_max'])
