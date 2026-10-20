#!/usr/bin/env python3
"""ins_an.py <seeds> <arm> [<arm> ...]: insurers (DOVE, SAFE) and equity-sleeve funds (CROW, SWAN, TIER, VULT), two
unpaired arms, mean (se) over seeds. Needs the batch D2 report fields (hm, cdep, catb, ilsp, sl_v, sl_pi, bpi, tax) and
the record's event_types (JointHarnessTest 10-20 D2).
Insurers: quarterly operating margin mean/sd and loss-quarter share; hard-market onsets per decade (hm goes 0 -> >0),
margin in the 1st-4th onset quarters; cover attachments per decade (reinsurance_attachment_breach events); trailing-4q
ROE (year 1 skipped); float (customer deposits) at y0/5/10/20; equity CAGR; EPS beat share; SAFE's catastrophe_bonds
revenue in the 0-6 quarters after a breach (evt field) over its mean of the 4 quarters before.
Funds: ROE mean / within-run sd, equity CAGR, sd of quarterly interest income / equity, sleeve mark / equity (sd, mean
|.|; mark = sleeve value x board price return since the last mark x (1 - tax)) and corr(mark/eq, board price return)."""
import collections, json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
arms = sys.argv[2:]
INS = ['DOVE', 'SAFE']
FUNDS = ['CROW', 'SWAN', 'TIER', 'VULT']


def se(x):
    return st.stdev(x) / math.sqrt(len(x)) if len(x) > 1 else float('nan')


def ms(x, f='{:.3f}'):
    x = [v for v in x if v is not None and not (isinstance(v, float) and math.isnan(v))]
    return (f.format(st.mean(x)) + ' (' + f.format(se(x)) + ')') if x else 'n/a'


def at(q, y):
    return min(q, key=lambda r: abs(r['t'] - y))


def corr(a, b):
    if len(a) < 3 or st.pstdev(a) == 0 or st.pstdev(b) == 0:
        return float('nan')
    ma, mb = st.mean(a), st.mean(b)
    return sum((x - ma) * (y - mb) for x, y in zip(a, b)) / len(a) / st.pstdev(a) / st.pstdev(b)


R = {arm: collections.defaultdict(lambda: collections.defaultdict(list)) for arm in arms}
for arm in arms:
    for s in seeds:
        d = json.loads(open(f"{H}/runs/{arm}-{s}.jsonl").readline())
        for t in INS + FUNDS:
            q = d['final'].get(t, {}).get('q', [])
            P = R[arm][t]
            if not q:
                P['fail'].append(1.0)
                continue
            P['fail'].append(1.0 if q[-1]['t'] < 19.0 else 0.0)
            yrs = q[-1]['t'] - q[0]['t']
            dec = yrs / 10.0
            roe = []
            for i in range(4, len(q)):
                if q[i]['t'] <= 1.0:
                    continue
                eq = st.mean(r['eq'] for r in q[i - 3:i + 1])
                if eq > 0:
                    roe.append(sum(r['ni'] for r in q[i - 3:i + 1]) / eq)
            if roe:
                P['roe'].append(st.mean(roe)); P['roesd'].append(st.pstdev(roe))
            if q[0]['eq'] > 0 and q[-1]['eq'] > 0 and yrs > 1:
                P['eqg'].append((q[-1]['eq'] / q[0]['eq']) ** (1 / yrs) - 1)
            b = [1.0 if r['reps'] > r['ceps'] else 0.0 for r in q if r['t'] > 1.0 and r.get('ceps', 0) != 0]
            if b:
                P['beat'].append(st.mean(b))
            om = [r['om'] for r in q if r['t'] > 0.3]
            P['om'].append(st.mean(om)); P['omsd'].append(st.pstdev(om))
            P['loss'].append(st.mean(1.0 if r['ni'] < 0 else 0.0 for r in q))
            if t in INS:
                for y in (0, 5, 10, 20):
                    P[f'f{y}'].append(at(q, y)['cdep'] / 1e9)
                ons = [i for i in range(1, len(q)) if q[i]['hm'] > 0 and q[i - 1]['hm'] <= 0]
                if q[0]['hm'] > 0:
                    ons = [0] + ons
                P['ons'].append(len(ons) / dec)
                P['hmsh'].append(st.mean(1.0 if r['hm'] > 0 else 0.0 for r in q))
                for k in range(4):
                    v = [q[i + k]['om'] for i in ons if i + k < len(q)]
                    if v:
                        P[f'on{k}'].append(st.mean(v))
                br = [r['t'] for r in q if r.get('evt') == 'Reinsurance Attachment Breach']
                cr = [r['lr'] + r['xr'] for r in q if r.get('lr') is not None and r['t'] > 0.3]
                if cr:
                    P['cr'].append(st.mean(cr)); P['crsd'].append(st.pstdev(cr))
                for k in range(4):
                    v = [q[i + k]['lr'] + q[i + k]['xr'] for i in ons if i + k < len(q) and q[i + k].get('lr') is not None]
                    if v:
                        P[f'cr{k}'].append(st.mean(v))
                P['att'].append(len(br) / dec)
                if t == 'SAFE':
                    ts = [r['t'] for r in q]
                    for bt in br:
                        i0 = min(range(len(q)), key=lambda i: abs(ts[i] - bt))
                        pre = [r['catb'] for r in q[max(0, i0 - 4):i0]]
                        if len(pre) < 4 or st.mean(pre) <= 0:
                            continue
                        for k in range(7):
                            if i0 + k < len(q):
                                P[f'ils{k}'].append(q[i0 + k]['catb'] / st.mean(pre))
                    P['catsh'].append(st.mean(r['catb'] / r['rev'] for r in q if r['rev'] > 0))
            else:
                ii = [r['ii'] * 4 / r['eq'] for r in q if r['eq'] > 0 and r['t'] > 0.3]
                if ii:
                    P['iisd'].append(st.pstdev(ii)); P['iim'].append(st.mean(ii))
                mk, br = [], []
                for i in range(1, len(q)):
                    a0, a1 = q[i - 1], q[i]
                    if a0.get('sl_pi') and a1.get('sl_pi') and a0['eq'] > 0 and a0['sl_v'] > 0:
                        r_ = a1['sl_pi'] / a0['sl_pi'] - 1
                        mk.append(a0['sl_v'] * r_ * (1 - a0['tax']) / a0['eq']); br.append(r_)
                    elif a0.get('bpi') and a1.get('bpi'):
                        br.append(a1['bpi'] / a0['bpi'] - 1)
                P['slv'].append(st.mean(r['sl_v'] / r['eq'] for r in q if r['eq'] > 0))
                if len(mk) > 4:
                    P['mksd'].append(st.pstdev(mk)); P['mkabs'].append(st.mean(abs(x) for x in mk))
                    P['mkc'].append(corr(mk, br))
print(f"seeds {sys.argv[1]}, arms {' | '.join(arms)} (unpaired, mean (se) over seeds)")
irows = [('om', 'op margin q mean', '{:.3f}'), ('omsd', 'op margin q sd', '{:.3f}'), ('loss', 'loss-quarter share', '{:.3f}'),
         ('ons', 'hard onsets /decade', '{:.2f}'), ('hmsh', 'hard-regime share', '{:.2f}'),
         ('on0', 'margin onset q1', '{:.3f}'), ('on1', 'margin onset q2', '{:.3f}'), ('on2', 'margin onset q3', '{:.3f}'),
         ('on3', 'margin onset q4', '{:.3f}'), ('cr', 'combined ratio mean', '{:.3f}'), ('crsd', 'combined ratio sd', '{:.3f}'),
         ('cr0', 'CR onset q1', '{:.3f}'), ('cr1', 'CR onset q2', '{:.3f}'), ('cr2', 'CR onset q3', '{:.3f}'),
         ('cr3', 'CR onset q4', '{:.3f}'), ('att', 'attachments /decade', '{:.2f}'),
         ('roe', 'ROE ttm mean', '{:.3f}'), ('roesd', 'ROE within-run sd', '{:.3f}'),
         ('f0', 'float y0 bn', '{:.0f}'), ('f5', 'float y5 bn', '{:.0f}'), ('f10', 'float y10 bn', '{:.0f}'),
         ('f20', 'float y20 bn', '{:.0f}'), ('eqg', 'equity CAGR', '{:.3f}'), ('beat', 'EPS beat share', '{:.2f}'),
         ('catsh', 'ILS rev / rev', '{:.3f}')] + [(f'ils{k}', f'ILS rev q+{k}/pre', '{:.2f}') for k in range(7)] + [
         ('fail', 'failed share', '{:.2f}')]
frows = [('roe', 'ROE ttm mean', '{:.3f}'), ('roesd', 'ROE within-run sd', '{:.3f}'), ('eqg', 'equity CAGR', '{:.3f}'),
         ('om', 'op margin q mean', '{:.3f}'), ('loss', 'loss-quarter share', '{:.3f}'), ('beat', 'EPS beat share', '{:.2f}'),
         ('iim', 'int inc ann / eq mean', '{:.4f}'), ('iisd', 'int inc ann / eq sd', '{:.4f}'),
         ('slv', 'sleeve / equity', '{:.3f}'), ('mksd', 'mark / eq q sd', '{:.4f}'), ('mkabs', 'mark / eq q mean|.|', '{:.4f}'),
         ('mkc', 'corr(mark, board ret)', '{:.2f}'), ('fail', 'failed share', '{:.2f}')]
for t in INS + FUNDS:
    print(f"\n{t}")
    for k, lab, f in (irows if t in INS else frows):
        v = [R[a][t][k] for a in arms]
        if not any(v):
            continue
        print(f"  {lab:22s} " + ' | '.join(f"{ms(x, f):>20s}" for x in v))
