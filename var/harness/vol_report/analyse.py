#!/usr/bin/env python3
"""analyse.py <seeds> [warmup_ticks] [tpy]: paired base vs treat table from runs/<arm>-<seed>.json (VR_OUT)."""
import json, math, sys, os
import statistics as st

H = os.path.dirname(os.path.abspath(__file__))
BOOST = {'SWAN', 'CROW', 'VULT', 'TIER', 'SILC', 'IBIS', 'CBIL', 'EIDR', 'ERNE', 'SNDR', 'ALCA'}


def seeds(spec):
    out = []
    for p in spec.split(','):
        if '-' in p:
            a, b = p.split('-'); out += list(range(int(a), int(b) + 1))
        else:
            out.append(int(p))
    return out


def pct(xs, q):
    xs = sorted(xs); k = (len(xs) - 1) * q; f = math.floor(k); c = min(f + 1, len(xs) - 1)
    return xs[f] + (xs[c] - xs[f]) * (k - f)


def metrics(path, w0, tpy, active=None, lag=0):
    d = json.load(open(path))
    tk = {t: {int(k): v for k, v in s.items()} for t, s in d['tk'].items()}
    last = max(max(s) for s in tk.values())
    bad = 0
    ratio, trade, spread, adv, rvol, repvol = {}, {}, [], [], {}, {}
    for t, s in tk.items():
        for v in s.values():
            if any(not math.isfinite(x) for x in v):
                bad += 1
        ticks = [k for k in range(w0, last + 1) if k in s and k - 1 in s]
        if t in d['dead'] or len(ticks) < (last - w0) * 0.98:
            continue
        r = [math.log(s[k][0] / s[k - 1][0]) for k in ticks if s[k][0] > 0 and s[k - 1][0] > 0]
        real = st.pstdev(r) * math.sqrt(tpy)
        rep = math.sqrt(st.fmean([s[k][1] ** 2 for k in ticks]))
        ratio[t] = rep / real
        rvol[t], repvol[t] = real, rep
        trade[t] = st.fmean([s[k][4] * s[k][0] for k in ticks])
        spread.append(st.fmean([s[k][2] for k in ticks]))
        adv.append(st.fmean([s[k][3] * s[k][0] for k in ticks]) / 1e6)
    if active is None:
        n = max(1, round(len(trade) / 10))
        active = set(sorted(trade, key=trade.get, reverse=True)[:n])
    rs = list(ratio.values()); ra = [ratio[t] for t in active if t in ratio]
    # board: cap-weighted simple return over names alive both ticks
    idx, lvl, peak, mdd, ir = [], 1.0, 1.0, 0.0, []
    for k in range(w0, last + 1):
        num = den = 0.0
        for s in tk.values():
            if k in s and k - 1 in s and s[k - 1][0] > 0:
                num += s[k - 1][5] * (s[k][0] / s[k - 1][0] - 1); den += s[k - 1][5]
        x = num / den if den > 0 else 0.0
        ir.append(math.log1p(x)); lvl *= 1 + x; peak = max(peak, lvl); mdd = max(mdd, 1 - lvl / peak)
    # earnings
    sb, so, eb, eo = [], [], [], []
    for t, k, rep, cons in d.get('er', []):
        s = tk.get(t, {})
        k = k + lag
        if rep is None or cons is None or k not in s or k - 1 not in s or s[k - 1][0] <= 0 or s[k][0] <= 0:
            continue
        sur = min(abs(rep - cons) / abs(cons), 1.0) if abs(cons) > 1e-9 else 1.0
        er = abs(math.log(s[k][0] / s[k - 1][0]))
        (sb if t in BOOST else so).append(sur); (eb if t in BOOST else eo).append(er)
    sk = [st.fmean([v[0] for v in q.values()]) for t, q in d.get('skew', {}).items() if q]
    return {
        'ratio_med': st.median(rs), 'ratio_p10': pct(rs, .1), 'ratio_p90': pct(rs, .9),
        'act_med': st.median(ra), 'act_p10': pct(ra, .1), 'act_p90': pct(ra, .9),
        'rep_vol_med': st.median(repvol.values()), 'real_vol_med': st.median(rvol.values()),
        'idx_vol': st.pstdev(ir) * math.sqrt(tpy), 'idx_ret': st.fmean(ir) * tpy, 'idx_mdd': mdd,
        'bankrupt': len(d['dead']), 'nonfinite': bad,
        'spread_bps': st.fmean(spread), 'adv_$m': st.fmean(adv),
        'boost_|surp|': st.fmean(sb) if sb else float('nan'), 'boost_|eday_r|': st.fmean(eb) if eb else float('nan'),
        'other_|surp|': st.fmean(so), 'other_|eday_r|': st.fmean(eo), 'n_boost_reports': len(sb),
        'skew30_med': st.median(sk) if sk else float('nan'),
    }, active


def main():
    ss = seeds(sys.argv[1]); w0 = int(sys.argv[2]) if len(sys.argv) > 2 else 90; tpy = int(sys.argv[3]) if len(sys.argv) > 3 else 360
    lag = int(os.environ.get('ELAG', '0'))
    rows = {}
    for s in ss:
        b, act = metrics(f'{H}/runs/base-{s}.json', w0, tpy, lag=lag)
        t, _ = metrics(f'{H}/runs/treat-{s}.json', w0, tpy, act, lag=lag)  # same active set as base
        for k in b:
            rows.setdefault(k, []).append((b[k], t[k]))
    n = len(ss)
    print(f'seeds {sys.argv[1]} (n={n}), warm-up {w0} ticks, tpy {tpy}; active decile = top 10% by traded value in base')
    print(f"{'quantity':18s} {'base':>10s} {'treat':>10s} {'diff':>10s} {'se':>9s} {'t':>6s}  range(diff)")
    for k, v in rows.items():
        b = [x[0] for x in v]; t = [x[1] for x in v]; d = [y - x for x, y in v]
        se = st.stdev(d) / math.sqrt(n) if n > 1 else float('nan')
        tt = st.fmean(d) / se if se and se > 0 else float('nan')
        print(f'{k:18s} {st.fmean(b):10.4f} {st.fmean(t):10.4f} {st.fmean(d):+10.4f} {se:9.4f} {tt:6.1f}  [{min(d):+.4f}, {max(d):+.4f}]')


main()
