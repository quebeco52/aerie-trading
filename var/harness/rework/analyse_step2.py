#!/usr/bin/env python3
"""analyse_step2.py <runs dir>: macro-review step 2 table, paired by seed: s2base vs s2new and s2basefund vs s2newfund."""
import json, sys, glob, math, statistics as st
from step2_extra import extra

BURN = 40  # quarters
ALARM = 0.75  # MacroEngine::SYSTEMIC_INVERSION_ALARM_YEARS
CAP = 0.85

def pct(v, p):
    v = sorted(v); i = (len(v) - 1) * p; lo = int(i); hi = min(lo + 1, len(v) - 1)
    return v[lo] + (v[hi] - v[lo]) * (i - lo)

def corr(a, b):
    ma, mb = st.mean(a), st.mean(b)
    num = sum((x - ma) * (y - mb) for x, y in zip(a, b))
    den = math.sqrt(sum((x - ma) ** 2 for x in a) * sum((y - mb) ** 2 for y in b))
    return num / den if den else float('nan')

def moments(r):
    q = lambda f: [x for x in r[f][BURN:] if x is not None] if r.get(f) else []
    m = {}
    gap, u, pi, pr = q('outputGap'), q('unemploymentRate'), q('inflation'), q('policyRate')
    yrs = len(gap) / 4
    m['gap mean %'] = 100 * st.mean(gap); m['gap sd %'] = 100 * st.pstdev(gap)
    m['unemp sd %'] = 100 * st.pstdev(u)
    m['infl mean %'] = 100 * st.mean(pi); m['infl sd %'] = 100 * st.pstdev(pi)
    m['rate dq sd bp'] = 1e4 * st.pstdev([b - a for a, b in zip(pr, pr[1:])])
    rec = q('recessionDeclared')
    m['recession share %'] = 100 * st.mean(rec)
    m['crises /100y'] = len({x for x in q('lastCreditCrisisAt') if x >= 0}) * 100 / yrs
    m['debt mean %'] = 100 * st.mean(q('sovereignDebtToGdp'))
    s = q('sloosTighteningIndex')
    m['SLOOS mean'] = st.mean(s); m['SLOOS p95'] = pct(s, 0.95); m['SLOOS max'] = max(s)
    m['SLOOS at cap %'] = 100 * st.mean([1 if x >= CAP - 1e-9 else 0 for x in s])
    mg, nb = q('moneySupplyGrowth'), q('householdNewBorrowing')
    m['M2 g mean %'] = 100 * st.mean(mg); m['M2 g sd %'] = 100 * st.pstdev(mg)
    m['corr(M2 g, HH borrow)'] = corr(mg, nb)
    rp = q('recessionProbability')
    m['rec prob mean %'] = 100 * st.mean(rp); m['rec prob sd %'] = 100 * st.pstdev(rp)
    inv = q('inversionDuration')
    m['inv alarms /100y'] = sum(1 for a, b in zip([0] + inv, inv) if b >= ALARM and a < ALARM) * 100 / yrs
    fx = q('exchangeRateIndex')
    m['FX dlog q sd %'] = 100 * st.pstdev([math.log(b / a) for a, b in zip(fx, fx[1:])])
    m['FX min'] = min(fx); m['FX max'] = max(fx)
    t = r.get('fxTicks')
    if t:
        m['|dFX| meet bp'] = 1e4 * t['meet'][0] / t['meet'][1]
        m['|dFX| other bp'] = 1e4 * t['other'][0] / t['other'][1]
        for kk, lab in (('meetQ', 'meet+qturn'), ('meetMid', 'meet mid-q'), ('qturn', 'qturn all')):
            if kk in t and t[kk][1]: m[f'|dFX| {lab} bp'] = 1e4 * t[kk][0] / t[kk][1]
    tb = q('tipsBreakeven')
    m['breakeven mean %'] = 100 * st.mean(tb); m['breakeven sd %'] = 100 * st.pstdev(tb)
    m['max |gap| %'] = 100 * max(abs(x) for x in gap)
    return {**m, **extra(r, BURN)}

def load(d, arm):
    out = {}
    for p in glob.glob(f'{d}/{arm}_*.jsonl'):
        r = json.loads(open(p).read().splitlines()[0])
        if r['seed'] != 99: out[r['seed']] = moments(r)
    return out

d = sys.argv[1]
for a0, a1 in (('s2base', 's2new'), ('s2basefund', 's2newfund')):
    x0, x1 = load(d, a0), load(d, a1)
    seeds = sorted(set(x0) & set(x1))
    print(f'\n{a0} vs {a1}, n={len(seeds)} seeds (paired), quarterly after {BURN // 4}y burn-in')
    print(f"{'moment':24}{a0:>10}{a1:>10}{'delta':>10}{'se':>9}{'t':>7}")
    for k in x0[seeds[0]]:
        v0 = [x0[s][k] for s in seeds]; v1 = [x1[s][k] for s in seeds]
        dd = [b - a for a, b in zip(v0, v1)]
        mu = st.mean(dd); se = st.stdev(dd) / math.sqrt(len(dd))
        t = mu / se if se > 0 else (0.0 if mu == 0 else float('inf'))
        flag = ' *' if abs(t) > 2 else ''
        print(f'{k:24}{st.mean(v0):>10.4f}{st.mean(v1):>10.4f}{mu:>10.4f}{se:>9.4f}{t:>7.1f}{flag}')
