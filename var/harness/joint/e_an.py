#!/usr/bin/env python3
"""e_an.py <arm> [seeds, default 1-8]: batch E quantities from runs/<arm>-<seed>.{jsonl,macro.jsonl}.
B capital: share raises 2-20% q/q split rescue/non-rescue and by firm, the loss/collapse share; "Borrowed ... to fund
  its dividend" events and $ for SINK/FNCH; SINK dividend peak/median; SINK buyback $ share at crude >130 vs <75;
  TTM ROE (NI / mean 4q equity, from year 2) for the IBs and SAFE/DOVE.
C industrials: per-firm revenue beta to y/y real GDP; Heavy inventory write-down share of quarters.
D utilities: BIRD/DIPR EBIT margin sd, BIRD R2 on the wholesale power index; WADE end rating, ND/EBITDA, defaults.
E housing (CREDIT=1 rows): corr(starts_t, gap_t+k) k=-4..4, sd(log starts)/sd(gap), AR1, min/p5; BOWR defaults.
Per-seed numbers are printed as mean [min..max] across seeds."""
import collections, json, math, os, re, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__)) + '/runs'
arm = sys.argv[1]
seeds = []
for part in (sys.argv[2] if len(sys.argv) > 2 else '1-8').split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
models = json.load(open(H + '/ticker_models.json'))


def rng(xs, f='{:.2f}'):
    xs = [x for x in xs if x is not None and not (isinstance(x, float) and math.isnan(x))]
    if not xs:
        return 'n/a'
    return (f + ' [' + f + '..' + f + ']').format(st.mean(xs), min(xs), max(xs))


def corr(a, b):
    if len(a) < 3:
        return float('nan')
    ma, mb = st.mean(a), st.mean(b)
    sa = math.sqrt(sum((x - ma) ** 2 for x in a)); sb = math.sqrt(sum((y - mb) ** 2 for y in b))
    return sum((x - ma) * (y - mb) for x, y in zip(a, b)) / (sa * sb) if sa * sb > 0 else float('nan')


def lagcorr(s, g, k):
    if k >= 0:
        return corr(s[:len(s) - k], g[k:])
    return corr(s[-k:], g[:len(g) + k])


def ols(x, y):
    mx, my = st.mean(x), st.mean(y)
    sxx = sum((a - mx) ** 2 for a in x); sxy = sum((a - mx) * (b - my) for a, b in zip(x, y))
    b = sxy / sxx if sxx else 0
    vy = sum((yy - my) ** 2 for yy in y)
    res = sum((yy - (my + b * (xx - mx))) ** 2 for xx, yy in zip(x, y))
    return b, (1 - res / vy if vy else 0)


def ttm_roe(q):
    out = []
    for i in range(7, len(q)):
        w = q[i - 3:i + 1]; me = st.mean(x['eq'] for x in w)
        if me > 0:
            out.append(sum(x['ni'] for x in w) / me)
    return st.mean(out) if out else float('nan')


raises = []; borrow = collections.defaultdict(lambda: [0, 0.0]); sinkdiv = []; sinkbb = []
roe = collections.defaultdict(list); beta = collections.defaultdict(list); invwd = [0, 0]
util = collections.defaultdict(list); wade = []; defaults = collections.Counter(); bowr = []
hous = collections.defaultdict(list); pooled_s = []; pooled_g = []
BETA = ['BUZT', 'NUTH', 'BOBY', 'WREN', 'CHUF', 'RIVE', 'SNDR', 'ALCA']
HEAVY = ['BUZT', 'NUTH', 'BOBY', 'WREN', 'CHUF']
for s in seeds:
    r = json.loads(open(f'{H}/{arm}-{s}.jsonl').readline())
    mac = [json.loads(l) for l in open(f'{H}/{arm}-{s}.macro.jsonl')]
    F = r['final']; ev = r['events']
    for tk, n in (r.get('defaults') or {}).items():
        defaults[tk] += 1
    # --- B raises ---
    for tk, d in F.items():
        q = d['q']; resc = [e['t'] for e in ev.get(tk, []) if 'stave off' in e['d']]
        for i in range(1, len(q)):
            a, b = q[i - 1], q[i]
            if a['shares'] <= 0 or not (1.02 <= b['shares'] / a['shares'] < 1.2):
                continue
            pm = st.mean(x['ni'] for x in q[max(0, i - 4):i])
            cls = 'loss' if b['ni'] < 0 else ('collapse' if pm > 0 and b['ni'] < 0.3 * pm else 'normal')
            raises.append((tk, any(a['t'] - 0.01 < t <= b['t'] + 0.01 for t in resc), cls))
    for tk in ('SINK', 'FNCH'):
        for e in ev.get(tk, []):
            m = re.match(r'Borrowed \$([\d.]+)B to fund its dividend', e['d'])
            if m:
                borrow[tk][0] += 1; borrow[tk][1] += float(m.group(1))
    q = F['SINK']['q']; dv = [x['div'] for x in q]
    sinkdiv.append((max(dv) / 1e9, st.median(dv) / 1e9))
    tot = sum(x['bb'] for x in q) or float('nan')
    sinkbb.append((sum(x['bb'] for x in q if x['crude'] > 130) / tot, sum(x['bb'] for x in q if x['crude'] < 75) / tot,
                   sum(1 for x in q if x['crude'] > 130) / len(q), sum(1 for x in q if x['crude'] < 75) / len(q)))
    for tk in ('KING', 'PERE', 'CORV', 'PTRL', 'SAFE', 'DOVE'):
        roe[tk].append(ttm_roe(F[tk]['q']))
    # --- C betas ---
    byk = {round(m['t'] * 4): m for m in mac}; lvl = {}; idx = 1.0
    for k in sorted(byk):
        idx *= 1 + (byk[k].get('gq') or 0); lvl[k] = idx
    for tk in BETA:
        if tk not in F:
            continue
        qq = sorted(F[tk]['q'], key=lambda x: x['t']); pts = []
        for i in range(4, len(qq)):
            k = round(qq[i]['t'] * 4)
            if qq[i - 4]['rev'] > 0 and k in lvl and k - 4 in lvl:
                pts.append((lvl[k] / lvl[k - 4] - 1, qq[i]['rev'] / qq[i - 4]['rev'] - 1))
        if len(pts) > 8:
            beta[tk].append(ols([p[0] for p in pts], [p[1] for p in pts])[0])
    for tk in HEAVY:
        for x in F[tk]['q']:
            invwd[1] += 1; invwd[0] += (x.get('invwd') or 0) > 0
    # --- D utilities ---
    for tk in ('BIRD', 'DIPR'):
        qq = F[tk]['q']; om = [x['om'] for x in qq]
        util[tk + ' om sd pp'].append(100 * st.pstdev(om))
        if tk == 'BIRD':
            util['BIRD R2 power'].append(ols([x.get('power') or 0 for x in qq], om)[1])
    wq = F['WADE']['q']; wade.append((wq[-1]['rating'], wq[-1]['ndE'], 'WADE' in (r.get('defaults') or {})))
    bowr.append(('BOWR' in (r.get('defaults') or {}), 'BOWR' in r['dead'], any(x.get('t') == 'BOWR' for x in r['reorgs'])))
    # --- E housing ---
    rows = mac[8:]
    if rows and 'starts' in rows[0]:
        st_ = [m['starts'] for m in rows]; gp = [m['gap'] for m in rows]
        for k in range(-4, 5):
            hous[k].append(lagcorr(st_, gp, k))
        ls = [math.log(x) for x in st_]
        hous['rel_sd'].append(st.pstdev(ls) / st.pstdev(gp))
        hous['ar1'].append(corr(ls[:-1], ls[1:]))
        hous['min'].append(min(st_)); hous['p5'].append(sorted(st_)[len(st_) // 20]); hous['mean'].append(st.mean(st_))
        pooled_s += [x - st.mean(ls) for x in ls]; pooled_g += gp

print(f'== {arm} seeds {seeds[0]}-{seeds[-1]}')
print('B raises 2-20%:', len(raises))
nr = [x for x in raises if not x[1]]; rs = [x for x in raises if x[1]]
print(f'  non-rescue {len(nr)} (after loss/collapse {sum(1 for x in nr if x[2] != "normal")}):',
      collections.Counter(x[0] for x in nr).most_common(8))
print(f'  rescue {len(rs)}:', collections.Counter(x[0] for x in rs).most_common(8))
print('  SAFE raises (all):', sum(1 for x in raises if x[0] == 'SAFE'))
for tk in ('SINK', 'FNCH'):
    print(f'  {tk} borrowed-for-dividend: {borrow[tk][0]} events ${borrow[tk][1]:.1f}B')
print('  SINK div $B/q peak', rng([x[0] for x in sinkdiv]), ' median', rng([x[1] for x in sinkdiv]))
print('  SINK bb$ share crude>130', rng([x[0] for x in sinkbb]), ' <75', rng([x[1] for x in sinkbb]),
      ' (quarter share >130', rng([x[2] for x in sinkbb]), ' <75', rng([x[3] for x in sinkbb]) + ')')
print('  ROE % (TTM, from y2):', '  '.join(f'{tk} {rng([100 * v for v in vs], "{:.1f}")}' for tk, vs in roe.items()))
print('C revenue beta to y/y real GDP:', '  '.join(f'{tk} {rng(v)}' for tk, v in beta.items()))
print(f'  Heavy invwd>0 share of quarters {invwd[0] / max(1, invwd[1]):.3f}')
print('D', '  '.join(f'{k} {rng(v)}' for k, v in util.items()))
print('  WADE end rating', collections.Counter(w[0] for w in wade).most_common(), ' defaulted in',
      sum(w[2] for w in wade), 'seeds  ndE_end', rng([w[1] for w in wade]))
print('  defaults by firm (seeds):', defaults.most_common(10))
print('E BOWR defaulted/dead/reorg seeds:', [sum(b[i] for b in bowr) for i in range(3)])
if hous:
    print('  corr(starts_t, gap_t+k):', ' '.join(f'{k:+d}:{st.mean(hous[k]):+.2f}' for k in range(-4, 5)))
    print('    k=0 per seed', rng(hous[0]), ' pooled k=0', f'{corr(pooled_s, pooled_g):+.2f}')
    print('  sd(log starts)/sd(gap)', rng(hous['rel_sd']), ' AR1', rng(hous['ar1']), ' mean', rng(hous['mean']),
          ' min', rng(hous['min']), ' p5', rng(hous['p5']))
else:
    print('  no starts in macro rows')
