"""Divestiture classification and sales, from DivestitureHarnessTest runs. Usage: ARM=r3v4d python3 -I analyse.py 1 2 ... 8"""
import json, os, sys, math, statistics as st

H = os.path.dirname(os.path.abspath(__file__))
ARM = os.environ.get('ARM', 'r3v4d')
seeds = [int(a) for a in sys.argv[1:]] or list(range(1, 9))
UTIL = ('WADE', 'BIRD')


def mse(xs):
    xs = list(xs)
    if not xs:
        return float('nan'), float('nan')
    m = sum(xs) / len(xs)
    return m, (st.stdev(xs) / math.sqrt(len(xs)) if len(xs) > 1 else float('nan'))


def fmt(x, d=3):
    return 'nan' if x is None or (isinstance(x, float) and math.isnan(x)) else f'{x:.{d}f}'


runs = {}
for s in seeds:
    with open(f'{H}/runs/{ARM}-{s}.jsonl') as fh:
        runs[s] = json.loads(fh.readline())

# ---- 1. classification shares ----
print(f'== 1. Classification (firm-quarters, after hoarder/cash-buffer exemptions), arm {ARM}, seeds {seeds}')
tot = {'n': 0, 'dy': 0, 'dis': 0, 'rawdis': 0, 'buf': 0, 'hoard': 0, 'shed': 0}
per_seed_dy, per_seed_dis = [], []
firm = {}
for s, r in runs.items():
    n = dy = dis = 0
    for tk, f in r['final'].items():
        for q in f['q']:
            if 'dRet' not in q:
                continue
            d = firm.setdefault(tk, {'n': 0, 'dy': 0, 'dis': 0, 'buf': 0, 'shed': 0, 'ret': [], 'hur': [], 'broic': q['broic'], 'hur0': [], 'ret0': [], 'fin': q.get('fin')})
            if not d['ret0'] or d.get('_s') != s:
                d['_s'] = s
                d['hur0'].append(q['dHur'])
                d['ret0'].append(q['dRet'])
            n += 1
            d['n'] += 1
            d['ret'].append(q['dRet'])
            d['hur'].append(q['dHur'])
            for k, key in (('dy', 'dDy'), ('dis', 'dDis'), ('buf', 'dBuf'), ('shed', 'dShed')):
                d[k] += bool(q[key])
            dy += bool(q['dDy'])
            dis += bool(q['dDis']) and not q['dDy']
            tot['rawdis'] += bool(q['dDisRaw'])
            tot['buf'] += bool(q['dDisRaw']) and not q['dDis'] and not q['dHoard']
            tot['hoard'] += bool(q['dHoard'])
            tot['shed'] += bool(q['dShed'])
        tot['n'] += 0
    tot['n'] += n
    per_seed_dy.append(dy / n)
    per_seed_dis.append(dis / n)
m, se = mse(per_seed_dy)
m2, se2 = mse(per_seed_dis)
print(f'firm-quarters {tot["n"]}: dying {fmt(m)} ({fmt(se)}), distressed-not-dying {fmt(m2)} ({fmt(se2)}), '
      f'raw distressed {fmt(tot["rawdis"]/tot["n"])}, cleared by cash buffer {fmt(tot["buf"]/tot["n"])}, '
      f'hoarder {fmt(tot["hoard"]/tot["n"])}, shedding stage {fmt(tot["shed"]/tot["n"])}')
print('top 15 by share of quarters dying or distressed (mean over seeds):')
print(f'{"tk":6}{"dying":>7}{"dis":>7}{"buf":>6}{"shed":>6}{"ret p50":>8}{"hur p50":>8}{"broic":>7}{"hur0":>7}{"ret0":>7}')
rows = sorted(firm.items(), key=lambda kv: -kv[1]['dis'] / kv[1]['n'])
for tk, d in rows[:15]:
    print(f'{tk:6}{d["dy"]/d["n"]:7.3f}{d["dis"]/d["n"]:7.3f}{d["buf"]/d["n"]:6.2f}{d["shed"]/d["n"]:6.2f}'
          f'{st.median(d["ret"]):8.3f}{st.median(d["hur"]):8.3f}{d["broic"]:7.3f}{st.median(d["hur0"]):7.3f}{st.median(d["ret0"]):7.3f}')
struct = [(tk, d) for tk, d in firm.items() if d['broic'] - st.median(d['hur0']) < -0.02 or d['broic'] < 0.03]
struct_dy = [tk for tk, d in struct if d['broic'] < 0 or d['broic'] - st.median(d['hur0']) < -0.05]
first_dis = [tk for tk, d in firm.items() if st.median(d['ret0']) - st.median(d['hur0']) < -0.02 or st.median(d['ret0']) < 0.03]
print(f'structural (baselineRoic vs first-quarter hurdle): distressed {len(struct)} of {len(firm)} firms: '
      + ' '.join(f'{tk}({d["broic"]:.3f}-{st.median(d["hur0"]):.3f})' for tk, d in sorted(struct)))
print(f'  of which dying at seed ROIC: {sorted(struct_dy)}')
print(f'  distressed by the engine return at the first report: {len(first_dis)}: {sorted(first_dis)}')

# ---- helpers: quarter lookups ----
def qrows(r, tk):
    return [q for q in r['final'][tk]['q'] if 'dRet' in q]


def before_after(qs, t):
    b = a = None
    for q in qs:
        if q['t'] <= t:
            b = q
        elif a is None:
            a = q
    return b, a


# ---- 2. sales by branch ----
print('\n== 2. Sales by branch (all seeds pooled; se over sales)')
allsales = []
for s, r in runs.items():
    bytk = {}
    for x in r['sales']:
        bytk.setdefault(x['tk'], []).append(x)
    for tk, xs in bytk.items():
        qs = qrows(r, tk)
        for i, x in enumerate(xs):
            x['seed'] = s
            x['repeat3'] = any(0 < y['t'] - x['t'] <= 3.0 for y in xs[i + 1:])
            b, a = before_after(qs, x['t'])
            x['qb'], x['qa'] = b, a
            allsales.append(x)
print(f'{"branch":11}{"n":>4}{"f":>6}{"px/book":>8}{"px/fmc":>7}{"gain$bn":>8}{"loss%":>6}{"rep3y":>6}{"shut":>5}{"cov>":>5}'
      f'{"roic0":>7}{"roicQ+":>7}{"ndE0":>6}{"ndE1":>6}{"ndEQ-":>6}{"ndEQ+":>6}')
for br in ('dying', 'distressed', 'premium', 'all'):
    xs = [x for x in allsales if br == 'all' or x['br'] == br]
    if not xs:
        continue
    f = mse(x['f'] for x in xs)[0]
    pb = st.median(x['px'] / x['bookEq'] for x in xs if x['bookEq'] > 0)
    pf = st.median(x['px'] / (x['f'] * x['mcap']) for x in xs)
    g = mse(x['gain'] / 1e9 for x in xs)[0]
    loss = sum(x['gain'] < 0 for x in xs) / len(xs)
    rep = sum(x['repeat3'] for x in xs) / len(xs)
    shut = sum(not x['mkt0'] for x in xs) / len(xs)
    cov = sum((x['ndE0'] or 0) > (x['covL0'] or 999) for x in xs) / len(xs)
    r0 = st.median(x['ret'] for x in xs)
    ra = st.median(x['qa']['dRet'] for x in xs if x['qa'])
    n0 = st.median(x['ndE0'] for x in xs)
    n1 = st.median(x['ndE1'] for x in xs)
    nb = st.median(x['qb']['ndE'] for x in xs if x['qb'] and 'ndE' in x['qb'])
    na = st.median(x['qa']['ndE'] for x in xs if x['qa'] and 'ndE' in x['qa'])
    print(f'{br:11}{len(xs):4}{f:6.3f}{pb:8.2f}{pf:7.2f}{g:8.1f}{loss:6.2f}{rep:6.2f}{shut:5.2f}{cov:5.2f}{r0:7.3f}{ra:7.3f}{n0:6.2f}{n1:6.2f}{nb:6.2f}{na:6.2f}')
d_roic = [x['post']['roic'] - x['pre']['roic'] for x in allsales]
d_roicq = [x['qa']['dRet'] - x['ret'] for x in allsales if x['qa']]
print(f'roicTtm change at the sale: max |d| {max(abs(v) for v in d_roic):.2e}; engine return next report minus at sale: '
      f'mean {fmt(mse(d_roicq)[0])} (se {fmt(mse(d_roicq)[1])}), n {len(d_roicq)}')
dn = [x['ndE0'] - x['ndE1'] for x in allsales]
print(f'ND/EBITDA (engine health, same tick) before-after: median {fmt(st.median(dn),2)}; share of sales ending net-cash (ndE1<=0): '
      f'{sum(x["ndE1"] <= 0 for x in allsales)/len(allsales):.2f}; share starting <=0: {sum(x["ndE0"] <= 0 for x in allsales)/len(allsales):.2f}')
cash_after = [x['post']['cash'] for x in allsales]
print('cash buffer next report (dBuf): ' + ', '.join(f'{br} {sum(bool(x["qa"] and x["qa"]["dBuf"]) for x in allsales if x["br"]==br)}/{sum(x["br"]==br for x in allsales)}' for br in ('dying', 'distressed', 'premium')))

# ---- 3. cascades ----
print('\n== 3. Cascades (cumulative retained plant < 0.40 within 3y of a sale): time to engine return > 0 and > hurdle-0.05')
print(f'{"seed":>4} {"tk":6}{"t0":>6}{"nsale":>6}{"kept3y":>7}{"ret0":>7}{"hur0":>7}{"t>0":>6}{"t!dy":>6}{"soldBy!dy":>10}{"keptEnd":>8}{"lastSale":>9}')
casc = []
for s, r in runs.items():
    bytk = {}
    for x in r['sales']:
        bytk.setdefault(x['tk'], []).append(x)
    for tk, xs in bytk.items():
        i = 0
        while i < len(xs):
            kept = 1.0
            j = i
            while j < len(xs) and xs[j]['t'] - xs[i]['t'] <= 3.0:
                kept *= 1 - xs[j]['f']
                j += 1
            if kept < 0.40:
                t0 = xs[i]['t']
                qs = [q for q in qrows(r, tk) if q['t'] > t0]
                tpos = next((q['t'] - t0 for q in qs if q['dRet'] > 0), None)
                tok = next((q['t'] - t0 for q in qs if q['dRet'] >= 0 and q['dRet'] - q['dHur'] >= -0.05), None)
                kept_ok = 1.0
                for y in xs[i:]:
                    if tok is None or y['t'] <= t0 + tok:
                        kept_ok *= 1 - y['f']
                kept_end = 1.0
                for y in xs[i:]:
                    kept_end *= 1 - y['f']
                after = [y for y in xs if y['t'] >= t0]
                casc.append((s, tk, tok, 1 - kept_ok))
                print(f'{s:4} {tk:6}{t0:6.1f}{len(after):6}{kept:7.2f}{xs[i]["ret"]:7.3f}{xs[i]["hur"]:7.3f}'
                      f'{fmt(tpos,1) if tpos is not None else "never":>6}{fmt(tok,1) if tok is not None else "never":>6}{1-kept_ok:10.2f}{kept_end:8.2f}{after[-1]["t"]:9.1f}')
                # skip to after this window's sales
                i = len(xs) if tok is None else max(j, next((k for k, y in enumerate(xs) if y['t'] > t0 + tok), len(xs)))
            else:
                i += 1
nev = sum(c[2] is None for c in casc)
print(f'cascades {len(casc)}; dying state never ends by itself in {nev}; median plant sold before it ends: '
      f'{fmt(st.median(c[3] for c in casc),2) if casc else "nan"}')

# ---- 4. utilities ----
print('\n== 4. Regulated utilities: engine return vs hurdle (per seed)')
print(f'{"seed":>4} {"tk":5}{"broic":>6}{"hur p50":>8}{"ret p10":>8}{"ret p50":>8}{"dying":>6}{"dis":>6}{"buf":>5}{"shed":>5}{"sales":>6}{"kept":>6}')
for tk in UTIL:
    for s, r in runs.items():
        if tk not in r['final']:
            continue
        qs = qrows(r, tk)
        if not qs:
            continue
        ret = sorted(q['dRet'] for q in qs)
        xs = [x for x in r['sales'] if x['tk'] == tk]
        kept = 1.0
        for x in xs:
            kept *= 1 - x['f']
        n = len(qs)
        print(f'{s:4} {tk:5}{qs[0]["broic"]:6.3f}{st.median(q["dHur"] for q in qs):8.3f}{ret[n//10]:8.3f}{ret[n//2]:8.3f}'
              f'{sum(q["dDy"] for q in qs)/n:6.2f}{sum(q["dDis"] and not q["dDy"] for q in qs)/n:6.2f}{sum(q["dBuf"] for q in qs)/n:5.2f}'
              f'{sum(q["dShed"] for q in qs)/n:5.2f}{len(xs):6}{kept:6.2f}')
for tk in UTIL:
    worst = min(runs, key=lambda s: math.prod(1 - x['f'] for x in runs[s]['sales'] if x['tk'] == tk))
    qs = qrows(runs[worst], tk)
    print(f'{tk} seed {worst}, yearly (Q4 rows): year ret hur eva dying shed buf ndE om ppe/ppe0')
    p0 = qs[0]['ppe'] or 1
    for q in qs[3::4]:
        print(f'  {q["t"]:5.1f} {q["dRet"]:7.3f} {q["dHur"]:6.3f} {q["dEva"]:7.3f} {int(q["dDy"])} {int(q["dShed"])} {int(q["dBuf"])} '
              f'{q.get("ndE", 0):5.2f} {q["om"]:6.3f} {q["ppe"]/p0:5.2f}')
    for x in [x for x in runs[worst]['sales'] if x['tk'] == tk]:
        print(f'  sale t {x["t"]:.2f} {x["br"]} f {x["f"]:.2f} ret {x["ret"]:.3f} hur {x["hur"]:.3f} shed {int(x["shed"])} '
              f'ndE {x["ndE0"]:.2f}->{x["ndE1"]:.2f} mkt {int(x["mkt0"])} px/fmc {x["px"]/(x["f"]*x["mcap"]):.2f} gain {x["gain"]/1e9:.1f}bn')

# ---- 5. plant sold ----
print('\n== 5. Net plant sold over the run, share of opening board net plant (per seed mean, se)')
share = {b: [] for b in ('dying', 'distressed', 'premium', 'all')}
for s, r in runs.items():
    open_ppe = sum(f['q'][0]['ppe'] for f in r['final'].values() if f['q'])
    for b in share:
        share[b].append(sum(x['f'] * x['pre']['ppe'] for x in r['sales'] if b == 'all' or x['br'] == b) / open_ppe)
for b, v in share.items():
    m, se = mse(v)
    print(f'{b:11} {m:6.3f} ({se:.3f})  range {min(v):.3f}-{max(v):.3f}  sales/seed {sum(1 for r in runs.values() for x in r["sales"] if b=="all" or x["br"]==b)/len(runs):.1f}')
