#!/usr/bin/env python3
"""model_moments.py <seeds> [arm]: fundamentals moments per business model, pooled over its tickers and seeds, from
runs/<arm>-<seed>.jsonl report rows and runs/<arm>-<seed>.macro.jsonl. Prints one table plus flags against guide
ranges. Needs runs/ticker_models.json (RUN.md).

Columns:
  nF/nQ     firm-seeds / firm-quarters
  g%        median annualised nominal revenue growth (log, first 4q to last 4q) across firm-seeds; dG = g - nominal GDP
  beta(se)  seS = cross-seed SE of the per-seed slope; within-firm OLS slope of y/y revenue growth on y/y real GDP growth (compounded gq), SE clustered by firm-seed
  m% sd% L% EBIT/revenue mean, median within-firm sd of the quarterly margin (pp), share of quarters with ni < 0
  R50 R10 R90  E = financial by the strategy (runs/ticker_fin.json), C = not. ROIC (non-fin: trailing-4q EBIT x (1 - macro tax_rate) / (eq + debt - cash)) or ROE (fin: trailing-4q
            ni / eq) over firm-years, median and 10/90 percentiles; R0/R1 medians over the first / last 2 years
  beat%     EPS beat share (reps > ceps), year 1 skipped; su50/susd median and median within-firm sd of rev/exp - 1
  ndE       median end ND/EBITDA (non-fin, alive, trailing-4q EBITDA > 0); cx/dp median firm-year capex/dep;
  pay       median firm-year (div + bb) / ni over years with ni > 0
  dist      liquidations / Ch11 / rescue equity raises
  qq% sea%  mean |q/q revenue change|; seasonal amplitude = range across calendar quarters of the mean q/q log change
"""
import json, math, os, statistics as st, sys, collections

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
arm = sys.argv[2] if len(sys.argv) > 2 else 'live'
models = json.load(open(f"{H}/runs/ticker_models.json"))
finmap = json.load(open(f"{H}/runs/ticker_fin.json")) if os.path.exists(f"{H}/runs/ticker_fin.json") else {}

DEFENSIVE = {'ConsumerStaples', 'Utility', 'HealthInsurer', 'MedicalCareFacility'}
CYCLICAL = {'AutoManufacturer', 'SteelManufacturing', 'Chemical', 'Semiconductor', 'HeavyManufacturing',
            'SpecialtyIndustrialMachinery', 'Shipping'}
SMOOTH = {'ConsumerStaples', 'Utility'}
GROWTH = {'Biotech', 'Tech', 'InternetRetail', 'Semiconductor', 'PrivateEquity', 'HedgeFund', 'DistressedDebt',
          'InvestmentCompany'}


def pct(xs, p):
    xs = sorted(xs)
    if not xs:
        return float('nan')
    k = (len(xs) - 1) * p
    lo = int(k)
    return xs[lo] + (xs[min(lo + 1, len(xs) - 1)] - xs[lo]) * (k - lo)


def med(xs):
    return pct(xs, 0.5)


D = collections.defaultdict(lambda: collections.defaultdict(list))
reg = collections.defaultdict(list)          # model -> [(cluster, x, y)]
bad = collections.defaultdict(int)
ngdp_g, seed_beta = [], collections.defaultdict(list)
for s in seeds:
    rec = json.loads(open(f"{H}/runs/{arm}-{s}.jsonl").readline())
    mac = [json.loads(l) for l in open(f"{H}/runs/{arm}-{s}.macro.jsonl")]
    byk = {}
    for r in mac:
        byk[round(r['t'] * 4)] = r
    ks = sorted(byk)
    ngdp_g.append(math.log(byk[ks[-1]]['nominal_gdp'] / byk[ks[0]]['nominal_gdp']) / ((ks[-1] - ks[0]) / 4))
    lvl, idx = {}, 1.0
    for k in ks:
        idx *= 1.0 + (byk[k].get('gq') or 0.0)
        lvl[k] = idx
    tax = {k: byk[k].get('tax_rate', 0.21) for k in ks}
    reorg = collections.Counter(r['t'] for r in rec.get('reorgs', []))
    rescue = collections.Counter(t for t, es in rec.get('events', {}).items() for e in es
                                 if 'stave off bankruptcy' in e['d'] and 'Issued new shares' in e['d'])
    sx = collections.defaultdict(list)
    for t, v in rec['final'].items():
        m = models.get(t, '?').replace('BusinessModel', '')
        d = D[m]
        d['dead'].append(1 if v['dead'] else 0)
        d['ch11'].append(reorg[t]); d['resc'].append(rescue[t])
        q = sorted(v['q'], key=lambda r: r['t'])
        if len(q) < 12:
            continue
        cl = f"{s}:{t}"
        d['nF'].append(1); d['nQ'].append(len(q))
        for r in q:
            if not all(math.isfinite(float(r[f])) for f in ('rev', 'ni', 'ebit', 'eq')) or r['rev'] <= 0:
                bad[m] += 1
        fin = finmap.get(t, q[-1]["fin"])
        d['fin'].append(fin)
        r0, r1 = sum(r['rev'] for r in q[:4]), sum(r['rev'] for r in q[-4:])
        if r0 > 0 and r1 > 0:
            d['g'].append(math.log(r1 / r0) / ((q[-4]['t'] - q[0]['t'])))
        # cyclicality, within-firm
        pts = []
        for i in range(4, len(q)):
            k, k4 = round(q[i]['t'] * 4), round(q[i - 4]['t'] * 4)
            if q[i - 4]['rev'] > 0 and q[i]['rev'] > 0 and k in lvl and k4 in lvl:
                pts.append((lvl[k] / lvl[k4] - 1.0, q[i]['rev'] / q[i - 4]['rev'] - 1.0))
        if len(pts) > 4:
            mx, my = st.mean(p[0] for p in pts), st.mean(p[1] for p in pts)
            reg[m] += [(cl, x - mx, y - my) for x, y in pts]
            sx[m] += [(x - mx, y - my) for x, y in pts]
        mg = [r['ebit'] / r['rev'] for r in q if r['rev'] > 0]
        d['m'] += mg; d['msd'].append(st.pstdev(mg))
        d['loss'] += [1 if r['ni'] < 0 else 0 for r in q]
        t0, tN = q[0]['t'], q[-1]['t']
        for i in range(3, len(q), 4):
            w = q[i - 3:i + 1]
            k = round(q[i]['t'] * 4)
            if fin:
                ret = sum(r['ni'] for r in w) / q[i]['eq'] if q[i]['eq'] > 0 else None
            else:
                ic = q[i]['eq'] + q[i]['debt'] - q[i]['cash']
                ret = sum(r['ebit'] for r in w) * (1 - tax.get(k, 0.21)) / ic if ic > 0 else None
            if ret is None:
                d['ret_na'].append(1)
            else:
                d['ret'].append(ret)
                if q[i]['t'] <= t0 + 2.0:
                    d['ret0'].append(ret)
                if q[i]['t'] >= tN - 2.0:
                    d['ret1'].append(ret)
            dep = sum(r['dep'] for r in w)
            if dep > 0:
                d['cxdp'].append(sum(abs(r['capex']) for r in w) / dep)
            ni = sum(r['ni'] for r in w)
            if ni > 0:
                d['pay'].append(sum(abs(r['div']) + abs(r['bb']) for r in w) / ni)
        qb = [r for r in q if r['t'] > 1.0 and r['ceps'] != 0]
        d['beat'] += [1 if r['reps'] > r['ceps'] else 0 for r in qb]
        su = [r['rev'] / r['exp'] - 1 for r in q if r['t'] > 1.0 and r['exp'] > 0]
        d['su'] += su
        if len(su) > 4:
            d['susd'].append(st.pstdev(su))
        if not fin and not v['dead']:
            e4 = sum(r['ebitda'] for r in q[-4:])
            if e4 > 0:
                d['ndE'].append((q[-1]['debt'] - q[-1]['cash']) / e4)
            else:
                d['ndE_neg'].append(1)
        qq = collections.defaultdict(list)
        for i in range(1, len(q)):
            if q[i - 1]['rev'] > 0 and q[i]['rev'] > 0:
                d['qq'].append(abs(q[i]['rev'] / q[i - 1]['rev'] - 1))
                qq[round(q[i]['t'] * 4) % 4].append(math.log(q[i]['rev'] / q[i - 1]['rev']))
        if len(qq) == 4:
            mq = [st.mean(x) for x in qq.values()]
            d['sea'].append(max(mq) - min(mq))
    for m, p in sx.items():
        sxx = sum(x * x for x, _ in p)
        if sxx > 0:
            seed_beta[m].append(sum(x * y for x, y in p) / sxx)

g_n = st.mean(ngdp_g)


def beta(m):
    p = reg.get(m, [])
    sxx = sum(x * x for _, x, _ in p)
    if sxx <= 0:
        return float('nan'), float('nan')
    b = sum(x * y for _, x, y in p) / sxx
    sc = collections.defaultdict(float)
    for c, x, y in p:
        sc[c] += x * (y - b * x)
    return b, math.sqrt(sum(v * v for v in sc.values())) / sxx


print(f"arm {arm} seeds {sys.argv[1]}: nominal GDP growth {100 * g_n:.2f}%/y (seed sd {100 * st.pstdev(ngdp_g):.2f}); "
      f"real GDP y/y from compounded gq; tax = macro tax_rate")
hdr = (f"{'model':27s} {'nF':>3s} {'nQ':>4s} {'g%':>6s} {'dG':>6s} {'beta':>5s} {'(se)':>6s} {"seS":>5s} {'m%':>6s} "
       f"{'sd%':>5s} {'L%':>5s} {'R':>1s} {'R50':>6s} {'R10':>6s} {'R90':>6s} {'R0':>6s} {'R1':>6s} {'beat%':>5s} "
       f"{'su50':>6s} {'susd':>5s} {'ndE':>5s} {'cx/dp':>5s} {'pay':>5s} {'dist':>6s} {'qq%':>5s} {'sea%':>5s}")
print(hdr)
flags = []
for m in sorted(D, key=lambda k: (any(D[k]['fin']), k)):
    d = D[m]
    if not d['nF']:
        print(f"{m:27s} no firm with 12+ quarters")
        continue
    fin = any(d['fin'])
    b, se = beta(m)
    sb = seed_beta.get(m, [])
    sds = st.stdev(sb) / math.sqrt(len(sb)) if len(sb) > 1 else float('nan')
    g = med(d['g'])
    row = dict(g=100 * g, dG=100 * (g - g_n), b=b, se=se, m=100 * st.mean(d['m']), sd=100 * med(d['msd']),
               L=100 * st.mean(d['loss']), R50=100 * med(d['ret']), R10=100 * pct(d['ret'], .1),
               R90=100 * pct(d['ret'], .9), R0=100 * med(d['ret0']), R1=100 * med(d['ret1']),
               beat=100 * st.mean(d['beat']) if d['beat'] else float('nan'), su=100 * med(d['su']),
               susd=100 * med(d['susd']), ndE=med(d['ndE']), cx=med(d['cxdp']), pay=med(d['pay']),
               qq=100 * st.mean(d['qq']), sea=100 * med(d['sea']))
    dist = f"{sum(d['dead'])}/{sum(d['ch11'])}/{sum(d['resc'])}"
    print(f"{m:27s} {len(d['nF']):3d} {sum(d['nQ']):4d} {row['g']:6.2f} {row['dG']:+6.2f} {b:5.2f} ({se:4.2f}) "
          f"{sds:5.2f} {row['m']:6.1f} {row['sd']:5.2f} {row['L']:5.1f} {'E' if fin else 'C'} {row['R50']:6.1f} "
          f"{row['R10']:6.1f} {row['R90']:6.1f} {row['R0']:6.1f} {row['R1']:6.1f} {row['beat']:5.1f} {row['su']:+6.2f} "
          f"{row['susd']:5.2f} {row['ndE']:5.2f} {row['cx']:5.2f} {row['pay']:5.2f} {dist:>6s} {row['qq']:5.1f} "
          f"{row['sea']:5.1f}")
    why = []
    if m in DEFENSIVE and not (-0.2 <= b <= 0.8):
        why.append(f"defensive beta {b:.2f} (se {se:.2f}) outside ~0-0.6")
    if m in CYCLICAL and not (1.5 <= b <= 4.5):
        why.append(f"cyclical beta {b:.2f} (se {se:.2f}) outside ~2-4")
    if m in SMOOTH and row['sd'] > 2.0:
        why.append(f"margin sd {row['sd']:.2f}pp > 2pp")
    if row['R90'] > 50 or row['R50'] < 0:
        why.append(f"{'ROE' if fin else 'ROIC'} median {row['R50']:.1f}% p90 {row['R90']:.1f}%")
    if not (55 <= row['beat'] <= 80):
        why.append(f"EPS beat share {row['beat']:.1f}%")
    if not fin and row['ndE'] > 6:
        why.append(f"ND/EBITDA {row['ndE']:.2f}")
    if m not in GROWTH and abs(row['dG']) > 5:
        why.append(f"revenue growth {row['g']:.2f}%/y vs nominal GDP {100 * g_n:.2f}%")
    if bad[m]:
        why.append(f"{bad[m]} quarters with NaN or non-positive revenue")
    if d['ret_na']:
        why.append(f"{len(d['ret_na'])} firm-years with non-positive invested capital/equity")
    if d['ndE_neg']:
        why.append(f"{len(d['ndE_neg'])} end firm-seeds with trailing EBITDA <= 0")
    if why:
        flags.append(f"  {m} (nF {len(d['nF'])}): " + '; '.join(why))
print("\nflags (guide ranges; beta band widened by ~0.2 for se):")
print('\n'.join(flags) if flags else "  none")
