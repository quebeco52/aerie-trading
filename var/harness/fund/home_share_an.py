#!/usr/bin/env python3
"""Paired closed/open - base on HomeShareImpactTest runs. Usage: home_share_an.py [runs dir] (default runs_home)."""
import json, math, os, sys, statistics as st

D = sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(os.path.abspath(__file__)), 'runs_home')
TRANS_MONTHS = 20


def load(arm):
    out = {}
    for f in os.listdir(D):
        if f.startswith(arm + '-') and f.endswith('.jsonl'):
            r = json.loads(open(os.path.join(D, f)).readline())
            out[r['seed']] = r
    return out


def stats(xs):
    n = len(xs)
    m = st.mean(xs)
    se = st.stdev(xs) / math.sqrt(n) if n > 1 else float('nan')
    return m, se, (m / se if se and se > 0 else float('nan')), min(xs), max(xs), n


def logidx(r):
    acc, out = 0.0, []
    for x in r['ret']:
        acc += math.log1p(x)
        out.append(acc)
    return out


def summarise(r):
    tpy = r['tpy']
    s = r['switch_tick']
    e = s + int(round(TRANS_MONTHS / 12 * tpy))
    li = logidx(r)
    month = {round(m['t'] * 12): m for m in r['months']}
    ms, me = round(s / tpy * 12), round(e / tpy * 12)
    win = r['ret'][s:e]
    return {
        'li_end': li[e - 1], 'li_fin': li[-1], 'li_pre': li[s - 2],
        'path': [li[min(len(li) - 1, s - 1 + int(k / 12 * tpy))] for k in PATH],
        'own_end': month[me]['own'], 'own_sw': month[ms]['own'],
        'bought': sum(r['trade'][s - 1:e]),
        'push': sum(r.get('push', [0.0])[s - 1:e]),
        'vol': st.pstdev(win) * math.sqrt(tpy),
        'tw': month[ms + 1]['tw'], 'backlog0': month[ms]['backlog'],
        'fund_end': month[me]['fund_gdp'],
    }


PATH = [0, 3, 6, 10, 15, 20, 26, 32, 40, 50, 60]
base = load('base')
rows = []
for arm in ('closed', 'open'):
    a = load(arm)
    seeds = sorted(set(a) & set(base))
    if not seeds:
        continue
    A = {k: summarise(a[k]) for k in seeds}
    B = {k: summarise(base[k]) for k in seeds}
    pre = max(abs(A[k]['li_pre'] - B[k]['li_pre']) for k in seeds)
    print(f"== {arm} - base, n={len(seeds)} seeds {seeds[0]}-{seeds[-1]}; max |dlog| before switch {pre:.2e}")
    print(f"{'quantity':34s} {'base':>9s} {arm:>9s} {'diff':>9s} {'se':>8s} {'t':>6s}  range")
    def line(name, key, scale=1.0, fmt='.4f'):
        d = [(A[k][key] - B[k][key]) * scale for k in seeds]
        m, se, t, lo, hi, n = stats(d)
        print(f"{name:34s} {st.mean(B[k][key] for k in seeds) * scale:9{fmt}} {st.mean(A[k][key] for k in seeds) * scale:9{fmt}} {m:9{fmt}} {se:8{fmt}} {t:6.1f}  [{lo:{fmt}}, {hi:{fmt}}]")
        return d
    d_end = line('T1 dlog board idx, end transition', 'li_end')
    d_fin = line('T1 dlog board idx, year 8', 'li_fin')
    line('T2 ownership of float, switch', 'own_sw')
    line('T2 ownership of float, end trans', 'own_end')
    d_b = line('T2 bought / float, transition', 'bought')
    d_p = line('   gross permanent push (log), trans', 'push')
    line('T3 daily-ret vol (ann.), trans', 'vol')
    line('   fund / GDP, end transition', 'fund_end')
    line('   target weight after switch', 'tw')
    mult = [x / y for x, y in zip(d_end, d_b) if abs(y) > 1e-6]
    agg = st.mean(d_end) / st.mean(d_b) if abs(st.mean(d_b)) > 1e-9 else float('nan')
    print(f"multiplier dlog/dbought: ratio of means {agg:.2f}; per-seed mean {st.mean(mult):.2f} (sd {st.stdev(mult) if len(mult) > 1 else 0:.2f})")
    print(f"gross-push multiplier: {st.mean(d_p) / st.mean(d_b):.2f}")
    print('mean dlog path (months after switch): ' + ' '.join(f"{k}:{st.mean(A[s]['path'][i] - B[s]['path'][i] for s in seeds):+.4f}" for i, k in enumerate(PATH)))
    print()
